<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppShell from '@/shared/AppShell.vue';
import { api } from '@/shared/api';
import { uid } from '@/shared/uuid';
import { MeditationAudio } from '../audioEngine';
import { soundManifest } from '../soundManifest';
import { uploadAudio } from '../uploadAudio';
import {
  defaults,
  type AudioLimits,
  type MeditationConfig,
  type MeditationHistory,
  type MeditationSession,
  type PersonalAudio,
  type Signal,
} from '../types';

const config = reactive<MeditationConfig>({ ...defaults });
const revision = ref(1),
  ready = ref(false),
  error = ref(''),
  notice = ref('');
const session = ref<MeditationSession | null>(null),
  requestBusy = ref(false),
  localPause = ref(true),
  muted = ref(false);
const anchor = ref(0),
  clock = ref(0),
  activeBase = ref(0),
  lease = ref(0),
  frozen = ref(0);
const preparing = ref(false),
  preparationLeft = ref(0),
  preparationPaused = ref(false);
let preparationDeadline = 0;
const engine = new MeditationAudio();
const audios = ref<PersonalAudio[]>([]),
  audioArchive = ref(false),
  audioTitle = ref(''),
  audioFile = ref<File | null>(null);
const limits = ref<AudioLimits>({ max_bytes: 104857600, max_seconds: 7200, max_library_bytes: 1073741824 });
const uploading = ref(false),
  uploadPercent = ref(0);
let uploadCancel: (() => void) | null = null;
const history = ref<MeditationHistory | null>(null),
  period = ref('30'),
  historyPage = ref(1),
  historyBusy = ref(false);
let tickId: ReturnType<typeof setInterval> | null = null;
let heartbeatId: ReturnType<typeof setInterval> | null = null;
let operation: Promise<void> = Promise.resolve();
let intervalIndex = 0;
let breathPhase = '';
let disposed = false;
let startId: string | null = null;
let wakeLock: { release(): Promise<void> } | null = null;
const timeZones = Intl as typeof Intl & { supportedValuesOf?: (key: 'timeZone') => string[] };
const timezoneOptions = Array.from(
  new Set([
    defaults.timezone,
    'UTC',
    'Europe/Moscow',
    'Asia/Novokuznetsk',
    'Asia/Yekaterinburg',
    'Asia/Novosibirsk',
    'Asia/Irkutsk',
    'Asia/Vladivostok',
    ...(timeZones.supportedValuesOf?.('timeZone') ?? []),
  ]),
);

const terminal = computed(
  () => !session.value || ['completed', 'interrupted'].includes(session.value.status),
);
const running = computed(() => !!session.value && session.value.status === 'running' && !localPause.value);
const locked = computed(() => preparing.value || !terminal.value);
const activeMs = computed(() =>
  running.value
    ? activeBase.value + Math.min(Math.max(0, clock.value - anchor.value), lease.value)
    : frozen.value,
);
const shownSeconds = computed(() => {
  const target = session.value?.settings.duration_seconds;
  return target == null || !session.value
    ? activeMs.value / 1000
    : Math.max(0, target - activeMs.value / 1000);
});
const breath = computed(() => {
  const settings = session.value?.settings ?? config;
  if (!settings.breathing_enabled) return null;
  const phases = [
    { name: 'Вдох', seconds: settings.inhale_seconds, scale: 1.15 },
    { name: 'Пауза после вдоха', seconds: settings.hold_in_seconds, scale: 1.15 },
    { name: 'Выдох', seconds: settings.exhale_seconds, scale: 0.8 },
    { name: 'Пауза после выдоха', seconds: settings.hold_out_seconds, scale: 0.8 },
  ].filter((phase) => phase.seconds > 0);
  const cycleSeconds = phases.reduce((sum, phase) => sum + phase.seconds, 0);
  const seconds = activeMs.value / 1000;
  const cycle = Math.floor(seconds / cycleSeconds);
  if (cycle >= settings.breathing_cycles)
    return { name: 'Дыхательные циклы завершены', remaining: 0, cycle: settings.breathing_cycles, scale: 1 };
  let position = seconds % cycleSeconds;
  for (const phase of phases) {
    if (position < phase.seconds)
      return { ...phase, remaining: Math.ceil(phase.seconds - position), cycle: cycle + 1 };
    position -= phase.seconds;
  }
  return null;
});
const calendar = computed(() => {
  const days = history.value?.summary.days ?? {};
  const today = new Intl.DateTimeFormat('en-CA', {
    timeZone: config.timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date());
  const cursor = new Date(`${today}T12:00:00Z`);
  return Array.from({ length: 35 }, (_, index) => {
    const date = new Date(cursor);
    date.setUTCDate(cursor.getUTCDate() - (34 - index));
    const key = date.toISOString().slice(0, 10);
    return { date: key, day: date.getUTCDate(), ms: days[key] ?? 0 };
  });
});
const exportUrl = computed(
  () => `/api/meditation/export?${new URLSearchParams({ period: period.value, timezone: config.timezone })}`,
);
const signalNames: Record<Signal, string> = {
  none: 'Тишина',
  gong: 'Гонг',
  bowl: 'Чаша',
  bell: 'Мягкий колокольчик',
};
const kindNames = { meditation: 'Медитация', breathing: 'Дыхание', tones: 'Звуковые частоты' };

function time(seconds: number): string {
  const whole = Math.max(0, Math.ceil(seconds));
  const h = Math.floor(whole / 3600),
    m = Math.floor((whole % 3600) / 60),
    s = whole % 60;
  return h
    ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
    : `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}
function message(cause: unknown): string {
  return cause instanceof Error ? cause.message : 'Не удалось выполнить действие.';
}
function adopt(value: MeditationSession): void {
  session.value = value;
  activeBase.value = value.active_ms;
  frozen.value = value.active_ms;
  lease.value = value.lease_remaining_ms;
  anchor.value = performance.now();
  clock.value = anchor.value;
}
async function loadHistory(): Promise<void> {
  historyBusy.value = true;
  try {
    history.value = await api<MeditationHistory>(
      `/api/meditation/history?${new URLSearchParams({ period: period.value, timezone: config.timezone, page: String(historyPage.value) })}`,
    );
  } catch (cause) {
    error.value = message(cause);
  } finally {
    historyBusy.value = false;
  }
}
async function loadAudio(): Promise<void> {
  const result = await api<{ data: PersonalAudio[]; limits: AudioLimits }>(
    `/api/audio?archived=${audioArchive.value ? 1 : 0}`,
  );
  audios.value = result.data;
  limits.value = result.limits;
}
async function initialize(): Promise<void> {
  error.value = '';
  try {
    const result = await api<{
      data: { revision: number; settings: MeditationConfig };
      audio_limits: AudioLimits;
    }>(`/api/meditation/settings?timezone=${encodeURIComponent(defaults.timezone)}`);
    Object.assign(config, result.data.settings);
    if (!timezoneOptions.includes(config.timezone)) timezoneOptions.push(config.timezone);
    revision.value = result.data.revision;
    limits.value = result.audio_limits;
    const current = await api<{ data: MeditationSession | null }>('/api/meditation/active');
    if (current.data) {
      adopt(current.data);
      localPause.value = true;
      notice.value =
        'Есть незавершённая сессия. Продолжите её явно; звук после открытия страницы не запускается.';
    }
    await Promise.all([loadAudio(), loadHistory()]);
    ready.value = true;
  } catch (cause) {
    error.value = message(cause);
  }
}
async function saveSettings(): Promise<void> {
  if (requestBusy.value) return;
  requestBusy.value = true;
  error.value = '';
  notice.value = '';
  try {
    const result = await api<{ data: { revision: number; settings: MeditationConfig } }>(
      '/api/meditation/settings',
      { method: 'PUT', body: JSON.stringify({ expected_revision: revision.value, settings: config }) },
    );
    revision.value = result.data.revision;
    notice.value = 'Личные настройки сохранены.';
  } catch (cause) {
    error.value = message(cause);
  } finally {
    requestBusy.value = false;
  }
}
function freeze(): void {
  frozen.value = activeMs.value;
  localPause.value = true;
  engine.pause();
  void wakeLock?.release().catch(() => {});
  wakeLock = null;
}
async function sound(): Promise<void> {
  if (!session.value || !running.value) return;
  const selected = session.value.settings;
  try {
    await engine.start(
      selected,
      activeMs.value / 1000,
      selected.audio_asset_id ? `/media/audio/${selected.audio_asset_id}` : null,
      () => {
        void command('complete');
      },
      (detail) => {
        error.value = detail;
      },
    );
    engine.setMuted(muted.value);
  } catch (cause) {
    error.value = message(cause);
  }
}
async function screenAwake(): Promise<void> {
  try {
    if ('wakeLock' in navigator)
      wakeLock = await (
        navigator as Navigator & {
          wakeLock: { request(type: 'screen'): Promise<{ release(): Promise<void> }> };
        }
      ).wakeLock.request('screen');
  } catch {
    /* Normal browser mode remains available. */
  }
}
async function beginSession(): Promise<void> {
  if (requestBusy.value || disposed) return;
  requestBusy.value = true;
  error.value = '';
  startId ??= uid();
  try {
    const result = await api<{ data: MeditationSession }>('/api/meditation/sessions', {
      method: 'POST',
      body: JSON.stringify({ id: startId, settings: config }),
    });
    adopt(result.data);
    localPause.value = false;
    preparing.value = false;
    intervalIndex = Math.floor(activeMs.value / 1000 / (result.data.settings.interval_seconds || Infinity));
    await sound();
    engine.signal(result.data.settings.start_signal, result.data.settings.signal_volume);
    await screenAwake();
  } catch (cause) {
    preparing.value = false;
    error.value = message(cause);
    try {
      const current = await api<{ data: MeditationSession | null }>('/api/meditation/active');
      if (current.data) {
        adopt(current.data);
        localPause.value = true;
        notice.value =
          'Найдена незавершённая сессия. Она не будет создана повторно: продолжите или завершите её.';
      }
    } catch {
      /* Original error remains visible; retry the status explicitly. */
    }
  } finally {
    requestBusy.value = false;
  }
}
async function start(): Promise<void> {
  if (!terminal.value || preparing.value || requestBusy.value) return;
  error.value = '';
  notice.value = '';
  frozen.value = 0;
  document.querySelectorAll<HTMLAudioElement>('.audio-list audio').forEach((audio) => audio.pause());
  try {
    await engine.unlock();
  } catch {
    notice.value = 'Звук недоступен; можно продолжить в тишине.';
  }
  startId = null;
  if (config.preparation_seconds > 0) {
    preparing.value = true;
    preparationPaused.value = false;
    preparationLeft.value = config.preparation_seconds;
    preparationDeadline = performance.now() + config.preparation_seconds * 1000;
  } else await beginSession();
}
function togglePreparation(): void {
  if (!preparing.value) return;
  preparationPaused.value = !preparationPaused.value;
  if (!preparationPaused.value) preparationDeadline = performance.now() + preparationLeft.value * 1000;
}
async function command(action: 'heartbeat' | 'pause' | 'resume' | 'complete' | 'interrupt'): Promise<void> {
  if (!session.value) return;
  if (action !== 'heartbeat' && action !== 'resume') freeze();
  operation = operation
    .catch(() => {})
    .then(async () => {
      if (!session.value || (terminal.value && action !== 'heartbeat')) return;
      requestBusy.value = true;
      try {
        const result = await api<{ data: MeditationSession }>(
          `/api/meditation/sessions/${session.value.id}`,
          {
            method: 'POST',
            body: JSON.stringify({ action, expected_revision: session.value.revision }),
            keepalive: action === 'pause',
          },
        );
        adopt(result.data);
        if (action === 'resume' && result.data.status === 'running') {
          localPause.value = false;
          await sound();
          await screenAwake();
        } else if (result.data.status !== 'running') {
          localPause.value = true;
          engine.pause();
        }
        if (terminal.value) {
          engine.stop();
          if (result.data.status === 'completed')
            engine.signal(result.data.settings.end_signal, result.data.settings.signal_volume);
          notice.value =
            result.data.status === 'completed'
              ? 'Сессия завершена. Активное время сохранено.'
              : 'Сессия прервана. Активное время сохранено отдельно от завершённых занятий.';
          await loadHistory();
        }
      } catch (cause) {
        freeze();
        error.value = `${message(cause)} Таймер приостановлен; нажмите «Сверить с сервером» перед продолжением.`;
      } finally {
        requestBusy.value = false;
      }
    });
  await operation;
}
async function recover(): Promise<void> {
  if (!session.value || requestBusy.value) return;
  requestBusy.value = true;
  try {
    const result = await api<{ data: MeditationSession }>(`/api/meditation/sessions/${session.value.id}`);
    adopt(result.data);
    localPause.value = true;
    error.value = '';
    notice.value = 'Состояние сверено. Для продолжения нажмите «Продолжить».';
  } catch (cause) {
    error.value = message(cause);
  } finally {
    requestBusy.value = false;
  }
}
function tick(): void {
  clock.value = performance.now();
  if (preparing.value && !preparationPaused.value && !requestBusy.value) {
    preparationLeft.value = Math.max(0, (preparationDeadline - clock.value) / 1000);
    if (preparationLeft.value <= 0) void beginSession();
  }
  if (!running.value || !session.value) return;
  const selected = session.value.settings;
  if (clock.value - anchor.value >= lease.value) {
    freeze();
    error.value =
      'Связь с сервером не подтверждена. Таймер приостановлен, звук выключен. Сверьте состояние перед продолжением.';
    return;
  }
  if (selected.duration_seconds !== null && activeMs.value >= selected.duration_seconds * 1000) {
    void command('complete');
    return;
  }
  if (selected.interval_seconds > 0 && selected.interval_signal !== 'none') {
    const interval = Math.floor(activeMs.value / 1000 / selected.interval_seconds);
    if (interval > intervalIndex) {
      intervalIndex = interval;
      engine.signal(selected.interval_signal, selected.signal_volume);
    }
  }
  if (selected.breathing_enabled && selected.breathing_signal !== 'none' && breath.value?.remaining) {
    const phase = `${breath.value.cycle}:${breath.value.name}`;
    if (phase !== breathPhase) {
      breathPhase = phase;
      engine.signal(selected.breathing_signal, selected.signal_volume * 0.35);
    }
  }
}
function hidden(): void {
  if (document.hidden) {
    document.querySelectorAll<HTMLAudioElement>('.audio-list audio').forEach((audio) => audio.pause());
    if (preparing.value && !preparationPaused.value) togglePreparation();
    if (running.value) {
      freeze();
      void command('pause');
      notice.value = 'При уходе с экрана таймер поставлен на паузу. Продолжите, когда вернётесь.';
    }
  }
}
function disconnected(): void {
  if (running.value) {
    freeze();
    void command('pause');
    error.value = 'Нет сети. Таймер и звук приостановлены; после подключения сверьте состояние.';
  }
}
function leaving(): void {
  if (running.value) {
    freeze();
    void command('pause');
  }
}
async function preview(signal: Signal): Promise<void> {
  try {
    await engine.preview(signal, config.signal_volume);
  } catch (cause) {
    error.value = message(cause);
  }
}
function selectFile(event: Event): void {
  const file = (event.target as HTMLInputElement).files?.[0] ?? null;
  audioFile.value = file;
  if (file && !audioTitle.value) audioTitle.value = file.name.replace(/\.[^.]+$/, '').slice(0, 160);
}
async function upload(): Promise<void> {
  if (!audioFile.value || !audioTitle.value.trim() || uploading.value) return;
  if (audioFile.value.size > limits.value.max_bytes) {
    error.value = 'Выбранный файл превышает допустимый размер.';
    return;
  }
  uploading.value = true;
  uploadPercent.value = 0;
  error.value = '';
  const transfer = uploadAudio(audioFile.value, audioTitle.value, (percent) => {
    uploadPercent.value = percent;
  });
  uploadCancel = transfer.cancel;
  try {
    const result = await transfer.result;
    config.audio_asset_id = result.id;
    audioFile.value = null;
    audioTitle.value = '';
    audioArchive.value = false;
    await loadAudio();
    notice.value = 'Личное аудио проверено и сохранено. Оно доступно только вам.';
  } catch (cause) {
    error.value = message(cause);
  } finally {
    uploading.value = false;
    uploadCancel = null;
  }
}
async function changeAudio(item: PersonalAudio, archived: boolean): Promise<void> {
  if (
    !window.confirm(
      archived
        ? `Убрать «${item.title}» в архив? Файл сохранится для истории.`
        : `Восстановить «${item.title}»?`,
    )
  )
    return;
  try {
    await api(`/api/audio/${item.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ expected_revision: item.revision, archived }),
    });
    if (archived && config.audio_asset_id === item.id) config.audio_asset_id = null;
    await loadAudio();
  } catch (cause) {
    error.value = message(cause);
  }
}
async function renameAudio(item: PersonalAudio): Promise<void> {
  const title = window.prompt('Новое название аудиозаписи', item.title)?.trim();
  if (!title || title === item.title) return;
  try {
    await api(`/api/audio/${item.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ expected_revision: item.revision, title }),
    });
    await loadAudio();
  } catch (cause) {
    error.value = message(cause);
  }
}
function fullscreen(): void {
  if (!document.fullscreenElement)
    void document
      .querySelector('.meditation-timer')
      ?.requestFullscreen?.()
      .catch(() => {
        notice.value = 'Полный экран недоступен; таймер работает в обычном режиме.';
      });
  else void document.exitFullscreen();
}
watch(muted, (value) => engine.setMuted(value));
watch(period, () => {
  historyPage.value = 1;
  if (ready.value) void loadHistory();
});
watch(
  () => config.timezone,
  () => {
    if (ready.value) void loadHistory();
  },
);
watch(audioArchive, () => {
  if (ready.value)
    void loadAudio().catch((cause) => {
      error.value = message(cause);
    });
});
watch(
  () => config.kind,
  (value) => {
    if (value === 'breathing') config.breathing_enabled = true;
    if (value === 'tones') config.tone_enabled = true;
  },
);
onMounted(() => {
  void initialize();
  tickId = setInterval(tick, 100);
  heartbeatId = setInterval(() => {
    if (running.value && !requestBusy.value) void command('heartbeat');
  }, 10000);
  document.addEventListener('visibilitychange', hidden);
  window.addEventListener('offline', disconnected);
  window.addEventListener('pagehide', leaving);
});
onBeforeUnmount(() => {
  disposed = true;
  if (tickId) clearInterval(tickId);
  if (heartbeatId) clearInterval(heartbeatId);
  document.removeEventListener('visibilitychange', hidden);
  window.removeEventListener('offline', disconnected);
  window.removeEventListener('pagehide', leaving);
  uploadCancel?.();
  if (running.value) void command('pause');
  engine.stop();
  void engine.dispose();
  void wakeLock?.release().catch(() => {});
});
</script>

<template>
  <Head title="Медитация" />
  <AppShell section="meditation">
    <div class="meditation-page">
      <header class="meditation-heading">
        <div>
          <h1>Медитация</h1>
          <p>Личный таймер, дыхание и звук. Время пауз не входит в статистику.</p>
        </div>
      </header>
      <div v-if="error" class="meditation-error" role="alert">
        {{ error }}
        <button
          v-if="session && !terminal"
          type="button"
          class="btn"
          :disabled="requestBusy"
          @click="recover"
        >
          Сверить с сервером
        </button>
      </div>
      <p v-if="notice" class="meditation-notice" role="status">{{ notice }}</p>
      <div v-if="!ready" class="card">
        <p>Загрузка личных настроек…</p>
        <button type="button" class="btn" @click="initialize">Повторить</button>
      </div>
      <template v-else>
        <section class="card meditation-timer" aria-label="Таймер медитации">
          <p class="meditation-kicker">
            {{
              preparing
                ? 'Подготовка — не считается временем медитации'
                : session
                  ? kindNames[session.kind]
                  : 'Готовы начать?'
            }}
          </p>
          <div class="meditation-clock" role="timer" aria-atomic="true">
            {{
              preparing
                ? time(preparationLeft)
                : session
                  ? time(shownSeconds)
                  : time(config.duration_seconds ?? 0)
            }}
          </div>
          <p>
            {{
              preparing
                ? preparationPaused
                  ? 'Подготовка на паузе'
                  : 'Устройтесь удобно'
                : running
                  ? 'Идёт сессия'
                  : session && !terminal
                    ? 'Пауза — нажмите «Продолжить»'
                    : session?.status === 'completed'
                      ? 'Завершено'
                      : session?.status === 'interrupted'
                        ? 'Прервано'
                        : config.duration_seconds === null
                          ? 'Свободный отсчёт'
                          : 'До начала'
            }}
          </p>
          <p v-if="session" class="muted">Активное время: {{ time(activeMs / 1000) }}</p>
          <div v-if="breath && !terminal" class="breathing-guide" :style="{ '--breath-scale': breath.scale }">
            <div class="breathing-orb" aria-hidden="true"></div>
            <div class="breathing-label">
              <strong>{{ breath.name }}</strong
              ><span v-if="breath.remaining">{{ breath.remaining }}</span
              ><small>Цикл {{ breath.cycle }} из {{ session?.settings.breathing_cycles }}</small>
            </div>
          </div>
          <div class="meditation-actions">
            <template v-if="preparing"
              ><button class="btn" @click="togglePreparation">
                {{ preparationPaused ? 'Продолжить подготовку' : 'Пауза' }}</button
              ><button class="btn" @click="preparing = false">Отменить подготовку</button></template
            >
            <button
              v-else-if="terminal"
              class="btn btn-primary"
              :disabled="requestBusy || uploading"
              @click="start"
            >
              {{ session ? 'Начать новую сессию' : 'Начать' }}
            </button>
            <template v-else
              ><button
                class="btn btn-primary"
                :disabled="requestBusy"
                @click="command(running ? 'pause' : 'resume')"
              >
                {{ running ? 'Пауза' : 'Продолжить' }}</button
              ><button class="btn" :disabled="requestBusy" @click="command('complete')">Завершить</button
              ><button class="btn" :disabled="requestBusy" @click="command('interrupt')">
                Прервать
              </button></template
            >
            <button class="btn" @click="muted = !muted">{{ muted ? 'Включить громкость' : 'Тишина' }}</button>
            <button v-if="running" class="btn" @click="sound">Включить звук</button
            ><button class="btn" @click="fullscreen">Полный экран</button>
          </div>
          <p class="muted small">
            При сворачивании страницы, блокировке или потере связи таймер ставится на паузу. Вернувшись,
            продолжите явно.
          </p>
        </section>

        <section class="card">
          <h2>Настройки сессии</h2>
          <p v-if="locked" class="muted">
            Настройки действуют для следующей сессии. Завершите текущую, чтобы их изменить.
          </p>
          <fieldset :disabled="locked || requestBusy" class="meditation-form">
            <label
              >Режим<select v-model="config.kind">
                <option value="meditation">Медитация</option>
                <option value="breathing">Дыхание</option>
                <option value="tones">Звуковые частоты</option>
              </select></label
            >
            <label
              >Длительность<select
                :value="config.duration_seconds === null ? 'free' : 'timed'"
                @change="
                  config.duration_seconds = ($event.target as HTMLSelectElement).value === 'free' ? null : 600
                "
              >
                <option value="timed">Заданное время</option>
                <option value="free">Свободный отсчёт</option>
              </select></label
            >
            <label v-if="config.duration_seconds !== null"
              >Минуты<input
                :value="config.duration_seconds / 60"
                type="number"
                min="0.02"
                max="720"
                step="0.1"
                @change="
                  config.duration_seconds = Math.max(
                    1,
                    Math.round(Number(($event.target as HTMLInputElement).value) * 60),
                  )
                "
            /></label>
            <label
              >Подготовка, секунд<input
                v-model.number="config.preparation_seconds"
                type="number"
                min="0"
                max="300"
            /></label>
            <label
              >Часовой пояс<select v-model="config.timezone">
                <option v-for="zone in timezoneOptions" :key="zone" :value="zone">{{ zone }}</option>
              </select></label
            >
          </fieldset>
          <h3>Сигналы</h3>
          <fieldset :disabled="locked" class="meditation-form">
            <label
              >В начале<select v-model="config.start_signal">
                <option v-for="(name, key) in signalNames" :key="key" :value="key">{{ name }}</option>
              </select></label
            >
            <label
              >В конце<select v-model="config.end_signal">
                <option v-for="(name, key) in signalNames" :key="key" :value="key">{{ name }}</option>
              </select></label
            >
            <label
              >Интервальный сигнал<select v-model="config.interval_signal">
                <option v-for="(name, key) in signalNames" :key="key" :value="key">{{ name }}</option>
              </select></label
            >
            <label v-if="config.interval_signal !== 'none'"
              >Интервал, секунд<input
                v-model.number="config.interval_seconds"
                type="number"
                min="1"
                max="43200"
            /></label>
            <label
              >Громкость сигналов<input
                v-model.number="config.signal_volume"
                type="range"
                min="0"
                max="1"
                step="0.01"
            /></label>
          </fieldset>
          <div class="meditation-actions">
            <button
              v-for="signal in ['gong', 'bowl', 'bell'] as Signal[]"
              :key="signal"
              type="button"
              class="btn"
              :disabled="locked"
              @click="preview(signal)"
            >
              Послушать: {{ signalNames[signal] }}</button
            ><button type="button" class="btn" :disabled="locked" @click="engine.stop">
              Остановить предпрослушивание
            </button>
          </div>
          <h3>Фон</h3>
          <fieldset :disabled="locked" class="meditation-form">
            <label
              >Звуковой фон<select v-model="config.ambience">
                <option value="none">Без фона</option>
                <option v-for="(item, key) in soundManifest.ambience" :key="key" :value="key">
                  {{ item.name }} — синтез
                </option>
              </select></label
            ><label
              >Громкость фона<input
                v-model.number="config.ambience_volume"
                type="range"
                min="0"
                max="1"
                step="0.01"
            /></label>
          </fieldset>
          <p class="muted small">
            Лес, дождь, волны и инструменты созданы процедурным синтезом RibkaYoga. Это звуковые образы, а не
            записи природы. Чужие аудиозаписи не используются.
          </p>
          <details>
            <summary>Дыхательное сопровождение</summary>
            <fieldset :disabled="locked" class="meditation-form">
              <label class="check"
                ><input v-model="config.breathing_enabled" type="checkbox" /> Показывать ритм</label
              ><label
                >Сигнал смены фазы<select v-model="config.breathing_signal">
                  <option v-for="(name, key) in signalNames" :key="key" :value="key">{{ name }}</option>
                </select></label
              ><label
                >Вдох, секунд<input
                  v-model.number="config.inhale_seconds"
                  type="number"
                  min="1"
                  max="30" /></label
              ><label
                >Выдох, секунд<input
                  v-model.number="config.exhale_seconds"
                  type="number"
                  min="1"
                  max="30" /></label
              ><label
                >Пауза после вдоха<input
                  v-model.number="config.hold_in_seconds"
                  type="number"
                  min="0"
                  max="30" /></label
              ><label
                >Пауза после выдоха<input
                  v-model.number="config.hold_out_seconds"
                  type="number"
                  min="0"
                  max="30" /></label
              ><label
                >Количество циклов<input
                  v-model.number="config.breathing_cycles"
                  type="number"
                  min="1"
                  max="1000"
              /></label>
            </fieldset>
            <p class="muted small">
              Следуйте комфортному ритму. Задержки отключены по умолчанию. Показанный ритм не измеряет ваше
              дыхание.
            </p>
          </details>
          <details>
            <summary>Звуковые частоты</summary>
            <fieldset :disabled="locked" class="meditation-form">
              <label class="check"
                ><input v-model="config.tone_enabled" type="checkbox" /> Одиночный синусоидальный тон</label
              ><label
                >Частота, Гц<input
                  v-model.number="config.tone_frequency"
                  type="number"
                  min="20"
                  max="2000" /></label
              ><label
                >Длительность тона, секунд<input
                  v-model.number="config.tone_seconds"
                  type="number"
                  min="1"
                  max="43200" /></label
              ><label
                >Тихая начальная громкость<input
                  v-model.number="config.tone_volume"
                  type="range"
                  min="0"
                  max="0.2"
                  step="0.005"
              /></label>
            </fieldset>
            <p class="muted small">
              Управляемый звук без медицинских обещаний. Этот режим не является бинауральным и не описывает
              влияние частоты на органы.
            </p>
          </details>
          <div class="meditation-actions">
            <button class="btn" :disabled="locked || requestBusy" @click="saveSettings">
              Сохранить личные настройки
            </button>
          </div>
        </section>

        <section class="card">
          <h2>Моё аудио</h2>
          <p class="muted">
            Только для вашего аккаунта. До {{ Math.round(limits.max_bytes / 1048576) }} МБ и
            {{ Math.round(limits.max_seconds / 60) }} минут на файл; библиотека с архивом — до
            {{ Math.round(limits.max_library_bytes / 1048576) }} МБ.
          </p>
          <fieldset :disabled="locked || uploading" class="meditation-form">
            <label
              >Личная запись<select v-model="config.audio_asset_id">
                <option :value="null">Без личного аудио</option>
                <option
                  v-for="item in audios.filter((item) => !item.archived_at)"
                  :key="item.id"
                  :value="item.id"
                >
                  {{ item.title }} · {{ time(item.duration_ms / 1000) }}
                </option>
              </select></label
            ><label
              >Громкость записи<input
                v-model.number="config.audio_volume"
                type="range"
                min="0"
                max="1"
                step="0.01" /></label
            ><label class="check"
              ><input v-model="config.audio_repeat" type="checkbox" /> Повторять запись</label
            ><label v-if="!config.audio_repeat"
              >После окончания<select v-model="config.audio_end">
                <option value="continue">Продолжить таймер</option>
                <option value="finish">Завершить сессию</option>
              </select></label
            >
          </fieldset>
          <form class="audio-upload" @submit.prevent="upload">
            <label
              >Название<input v-model="audioTitle" maxlength="160" required :disabled="uploading" /></label
            ><label
              >MP3, M4A/AAC, WAV<input
                type="file"
                accept=".mp3,.m4a,.wav,audio/mpeg,audio/mp4,audio/wav"
                :disabled="uploading"
                @change="selectFile" /></label
            ><button class="btn" :disabled="uploading || !audioFile || !audioTitle.trim()" type="submit">
              Загрузить личную запись</button
            ><template v-if="uploading"
              ><progress :value="uploadPercent" max="100"></progress
              ><span
                >{{ uploadPercent }}%{{ uploadPercent === 100 ? ' — проверяем декодирование…' : '' }}</span
              ><button type="button" class="btn" @click="uploadCancel?.()">
                Отменить загрузку
              </button></template
            >
          </form>
          <label class="check"><input v-model="audioArchive" type="checkbox" /> Показать архив аудио</label>
          <p v-if="!audios.length" class="muted">
            {{ audioArchive ? 'Архив пуст.' : 'Личных записей пока нет.' }}
          </p>
          <ul class="audio-list">
            <li v-for="item in audios" :key="item.id">
              <div>
                <strong>{{ item.title }}</strong
                ><small
                  >{{ time(item.duration_ms / 1000) }} · {{ (item.bytes / 1048576).toFixed(1) }} МБ</small
                >
              </div>
              <audio
                :controls="!locked"
                preload="none"
                :src="item.stream_url"
                :aria-label="`Прослушать ${item.title}`"
              ></audio>
              <div class="meditation-actions">
                <button class="btn" :disabled="locked" @click="renameAudio(item)">Название</button
                ><button class="btn" :disabled="locked" @click="changeAudio(item, !item.archived_at)">
                  {{ item.archived_at ? 'Восстановить' : 'Убрать в архив' }}
                </button>
              </div>
            </li>
          </ul>
        </section>

        <section class="card">
          <div class="meditation-heading">
            <div>
              <h2>Моя статистика медитации</h2>
              <p class="muted">
                Часовой пояс: {{ config.timezone }}. Завершённые и прерванные сессии различаются.
              </p>
            </div>
            <a class="btn" :href="exportUrl">Экспорт CSV</a>
          </div>
          <label
            >Период<select v-model="period">
              <option value="1">Сегодня</option>
              <option value="7">7 дней</option>
              <option value="30">30 дней</option>
              <option value="180">6 месяцев</option>
              <option value="all">Всё время</option>
            </select></label
          >
          <template v-if="history"
            ><div class="meditation-stats">
              <div>
                <strong>{{ Math.round(history.summary.active_ms / 60000) }}</strong
                ><span>активных минут</span>
              </div>
              <div>
                <strong>{{ history.summary.completed }}</strong
                ><span>завершено</span>
              </div>
              <div>
                <strong>{{ history.summary.interrupted }}</strong
                ><span>прервано</span>
              </div>
            </div>
            <h3>Регулярность за последние 35 дней</h3>
            <div class="meditation-calendar" aria-label="Календарь регулярности">
              <span
                v-for="day in calendar"
                :key="day.date"
                :class="{ practiced: day.ms > 0 }"
                :title="`${day.date}: ${time(day.ms / 1000)}`"
                tabindex="0"
                >{{ day.day }}<small v-if="day.ms > 0">{{ Math.ceil(day.ms / 60000) }}м</small></span
              >
            </div>
            <p class="muted small">
              Зелёный день — есть активное время в выбранном периоде. Дни вне периода не подсвечиваются.
            </p>
            <p v-if="!history.total">В этом периоде завершённых или прерванных сессий нет.</p>
            <ul class="meditation-history">
              <li v-for="item in history.data" :key="item.id">
                <span>{{
                  new Date(item.started_at).toLocaleString('ru-RU', { timeZone: config.timezone })
                }}</span
                ><span>{{ kindNames[item.kind] }}</span
                ><strong>{{ time(item.active_ms / 1000) }}</strong
                ><span>{{ item.status === 'completed' ? 'Завершено' : 'Прервано' }}</span>
              </li>
            </ul>
            <div v-if="history.last_page > 1" class="meditation-actions">
              <button
                class="btn"
                :disabled="historyBusy || history.current_page <= 1"
                @click="
                  historyPage--;
                  loadHistory();
                "
              >
                Назад</button
              ><span>{{ history.current_page }} / {{ history.last_page }}</span
              ><button
                class="btn"
                :disabled="historyBusy || history.current_page >= history.last_page"
                @click="
                  historyPage++;
                  loadHistory();
                "
              >
                Дальше
              </button>
            </div></template
          >
        </section>
      </template>
    </div>
  </AppShell>
</template>

<style scoped>
.meditation-page {
  display: grid;
  gap: 1rem;
  max-width: 1100px;
  margin: auto;
}
.meditation-heading {
  display: flex;
  justify-content: space-between;
  align-items: start;
  gap: 1rem;
  flex-wrap: wrap;
}
.meditation-page h1 {
  font-size: 1.65rem;
  font-weight: 650;
}
.meditation-page h2 {
  font-size: 1.2rem;
  font-weight: 650;
  margin-bottom: 0.65rem;
}
.meditation-page h3 {
  font-size: 1rem;
  font-weight: 600;
  margin: 1rem 0 0.5rem;
}
.card {
  border: 1px solid #dae3d7;
  border-radius: 1rem;
  padding: 1.2rem;
  background: white;
}
.muted {
  color: #61705c;
}
.small {
  font-size: 0.85rem;
  margin-top: 0.6rem;
}
.meditation-error {
  background: #fff0ec;
  border: 1px solid #eeab9c;
  color: #842d1b;
  border-radius: 0.8rem;
  padding: 1rem;
}
.meditation-notice {
  background: #eef7e9;
  padding: 0.9rem;
  border-radius: 0.8rem;
}
.meditation-timer {
  text-align: center;
  padding: 1.5rem;
}
.meditation-timer:fullscreen {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  overflow-y: auto;
  background: white;
}
.meditation-kicker {
  font-weight: 600;
}
.meditation-clock {
  font-size: clamp(3.5rem, 12vw, 6.5rem);
  line-height: 1.35;
  font-variant-numeric: tabular-nums;
  color: #2f622a;
}
.meditation-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: inherit;
  gap: 0.55rem;
  margin-top: 0.8rem;
}
.meditation-timer .meditation-actions {
  justify-content: center;
}
.meditation-form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 210px), 1fr));
  gap: 0.85rem;
  border: 0;
  padding: 0;
  margin-top: 0.75rem;
}
.meditation-page label {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  font-size: 0.9rem;
}
.meditation-page label.check {
  flex-direction: row;
  align-items: center;
  gap: 0.5rem;
  margin: 0.7rem 0;
}
.meditation-page input:not([type='checkbox']):not([type='range']),
.meditation-page select {
  width: 100%;
  min-height: 44px;
  border: 1px solid #c5d0c0;
  border-radius: 0.55rem;
  padding: 0.5rem;
  background: white;
}
.meditation-page input[type='range'] {
  width: 100%;
  min-height: 44px;
  accent-color: #397a28;
}
.meditation-page input[type='checkbox'] {
  accent-color: #397a28;
}
.meditation-page fieldset:disabled {
  opacity: 0.7;
}
.meditation-page details {
  padding: 0.8rem 0;
  border-bottom: 1px solid #e6ece3;
}
.meditation-page summary {
  cursor: pointer;
  font-weight: 600;
  min-height: 44px;
  padding-top: 0.5rem;
}
.audio-upload {
  display: grid;
  gap: 0.7rem;
  margin: 1rem 0;
  border: 1px dashed #a9b8a1;
  border-radius: 0.8rem;
  padding: 1rem;
}
.audio-upload progress {
  width: 100%;
}
.audio-list,
.meditation-history {
  list-style: none;
  padding: 0;
}
.audio-list li {
  display: grid;
  gap: 0.6rem;
  padding: 1rem 0;
  border-top: 1px solid #e2e9df;
}
.audio-list small {
  display: block;
  color: #61705c;
}
.audio-list audio {
  width: min(100%, 440px);
}
.meditation-history li {
  display: flex;
  gap: 0.7rem;
  flex-wrap: wrap;
  padding: 0.75rem 0;
  border-top: 1px solid #e2e9df;
}
.meditation-history li span:first-child {
  min-width: 170px;
}
.meditation-stats {
  display: flex;
  flex-wrap: wrap;
  gap: 2rem;
  margin: 1rem 0;
}
.meditation-stats div {
  display: flex;
  flex-direction: column;
}
.meditation-stats strong {
  font-size: 1.8rem;
  color: #2f622a;
}
.meditation-calendar {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 0.35rem;
  max-width: 480px;
}
.meditation-calendar > span {
  display: flex;
  min-height: 47px;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  border-radius: 0.4rem;
  background: #f1f4ef;
  font-variant-numeric: tabular-nums;
}
.meditation-calendar > span.practiced {
  background: #d2e9c7;
  color: #214c1d;
}
.meditation-calendar small {
  font-size: 0.65rem;
}
.breathing-guide {
  position: relative;
  height: 170px;
  width: 190px;
  display: grid;
  place-items: center;
  margin: 1rem auto;
}
.breathing-orb {
  position: absolute;
  width: 145px;
  height: 145px;
  border-radius: 50%;
  background: #e4f1dc;
  transform: scale(var(--breath-scale));
  transition: transform 1.5s ease;
}
.breathing-label {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
.breathing-label span {
  font-size: 1.7rem;
}
@media (prefers-reduced-motion: reduce) {
  .breathing-orb {
    transition: none;
    transform: none;
  }
}
@media (max-width: 500px) {
  .card {
    padding: 0.9rem;
  }
  .meditation-stats {
    gap: 1rem;
  }
  .meditation-clock {
    font-size: 4.5rem;
  }
  .meditation-history li {
    font-size: 0.85rem;
  }
  .meditation-timer .meditation-actions .btn {
    flex: 1 1 43%;
    min-height: 44px;
  }
}
</style>
