<script setup lang="ts">
import ResponsivePanel from '@/shared/ResponsivePanel.vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import {
  AudioLines,
  CalendarDays,
  Download,
  Upload,
  Wind,
  Bell,
  BellRing,
  Expand,
  Leaf,
  Play,
  SlidersHorizontal,
  Timer,
  Volume2,
  VolumeX,
  X,
} from '@lucide/vue';
import { notifyMeditationCompleted, uiPreferencesKey } from '@/shared/uiPreferences';
import AppShell from '@/shared/AppShell.vue';
import PageHeading from '@/shared/PageHeading.vue';
import AppSelect from '@/shared/AppSelect.vue';
import PlayerWindow from '@/shared/PlayerWindow.vue';
import CountdownRing from '@/shared/CountdownRing.vue';
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

const uiKey = uiPreferencesKey(usePage().props.auth);
const config = reactive<MeditationConfig>({ ...defaults });
const immersive = ref(false);
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
let playbackRequested = false;
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
const durationOptions = [1, 3, 5, 10, 15, 20, 30, 60, 180];
const lastBackground = reactive<{
  ambience: MeditationConfig['ambience'];
  audio_asset_id: string | null;
}>({ ambience: 'rain', audio_asset_id: null });
const backgroundEnabled = computed({
  get: () => config.ambience !== 'none' || config.audio_asset_id !== null,
  set: (enabled: boolean) => {
    if (enabled) {
      const rememberedAudio = audios.value.find((item) => item.id === lastBackground.audio_asset_id);
      config.audio_asset_id = rememberedAudio?.archived_at ? null : lastBackground.audio_asset_id;
      config.ambience =
        lastBackground.ambience === 'none' && !config.audio_asset_id ? 'rain' : lastBackground.ambience;
    } else {
      lastBackground.ambience = config.ambience;
      lastBackground.audio_asset_id = config.audio_asset_id;
      config.ambience = 'none';
      config.audio_asset_id = null;
    }
  },
});
function toggleGong(key: 'start_signal' | 'end_signal'): void {
  config[key] = config[key] === 'none' ? 'gong' : 'none';
}

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
  if (requestBusy.value || disposed || !playbackRequested) return;
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
    if (!playbackRequested || disposed) {
      void command('pause');
      return;
    }
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
  playbackRequested = true;
  immersive.value = true;
  error.value = '';
  notice.value = '';
  frozen.value = 0;
  document.querySelectorAll<HTMLAudioElement>('.audio-list audio').forEach((audio) => audio.pause());
  try {
    await engine.unlock();
  } catch {
    notice.value = 'Звук недоступен; можно продолжить в тишине.';
  }
  if (!playbackRequested || disposed) return;
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
  playbackRequested = !preparationPaused.value;
  if (!preparationPaused.value) preparationDeadline = performance.now() + preparationLeft.value * 1000;
}
async function command(action: 'heartbeat' | 'pause' | 'resume' | 'complete' | 'interrupt'): Promise<void> {
  if (!session.value) return;
  if (action !== 'heartbeat') playbackRequested = action === 'resume';
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
          if (!playbackRequested || disposed) {
            localPause.value = true;
            engine.pause();
            void command('pause');
            return;
          }
          localPause.value = false;
          await sound();
          await screenAwake();
        } else if (result.data.status !== 'running') {
          localPause.value = true;
          engine.pause();
        }
        if (terminal.value) {
          engine.stop();
          if (result.data.status === 'completed') {
            engine.signal(result.data.settings.end_signal, result.data.settings.signal_volume);
            notifyMeditationCompleted(uiKey);
          }
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
    if (archived) {
      if (config.audio_asset_id === item.id) config.audio_asset_id = null;
      if (lastBackground.audio_asset_id === item.id) lastBackground.audio_asset_id = null;
    }
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
  immersive.value = true;
}
function closeWindow(): void {
  playbackRequested = false;
  if (preparing.value && !preparationPaused.value) togglePreparation();
  if (running.value) void command('pause');
  immersive.value = false;
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
    <PageHeading
      eyebrow="Пауза для себя"
      title="Медитация"
      description="Выберите время, настройте звук — и просто побудьте в моменте."
    />
    <div class="meditation-page">
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
        <div class="meditation-workspace">
          <div class="meditation-setup">
            <section class="card meditation-duration">
              <h2><Timer aria-hidden="true" />Длительность</h2>
              <fieldset
                :disabled="locked || requestBusy"
                class="duration-options"
                aria-label="Длительность медитации"
              >
                <button
                  v-for="minutes in durationOptions"
                  :key="minutes"
                  type="button"
                  class="duration-option"
                  :class="{ selected: config.duration_seconds === minutes * 60 }"
                  :aria-pressed="config.duration_seconds === minutes * 60"
                  @click="config.duration_seconds = minutes * 60"
                >
                  {{ minutes }} <span>мин</span>
                </button>
              </fieldset>
              <p
                v-if="
                  config.duration_seconds === null || !durationOptions.includes(config.duration_seconds / 60)
                "
                class="muted small"
              >
                {{
                  config.duration_seconds === null
                    ? 'Выбран свободный отсчёт.'
                    : `Выбрано своё время: ${time(config.duration_seconds)}.`
                }}
                Его можно изменить в дополнительных настройках.
              </p>
            </section>
            <ResponsivePanel title="Звуки медитации" label="Звуки и настройки">
              <section class="card meditation-sounds">
                <h2><Volume2 aria-hidden="true" />Звуки</h2>
                <fieldset :disabled="locked || requestBusy" class="sound-switches">
                  <div class="sound-row">
                    <span class="sound-icon"><BellRing aria-hidden="true" /></span>
                    <div class="sound-label">
                      <span id="start-gong-label">Гонг в начале</span>
                      <small v-if="!['none', 'gong'].includes(config.start_signal)"
                        >Сохранённый сигнал: {{ signalNames[config.start_signal] }}</small
                      >
                    </div>
                    <button
                      type="button"
                      class="meditation-switch"
                      role="switch"
                      :aria-checked="config.start_signal !== 'none'"
                      aria-labelledby="start-gong-label"
                      @click="toggleGong('start_signal')"
                    >
                      <span />
                    </button>
                  </div>
                  <div class="sound-row">
                    <span class="sound-icon"><Bell aria-hidden="true" /></span>
                    <div class="sound-label">
                      <span id="end-gong-label">Гонг в конце</span>
                      <small v-if="!['none', 'gong'].includes(config.end_signal)"
                        >Сохранённый сигнал: {{ signalNames[config.end_signal] }}</small
                      >
                    </div>
                    <button
                      type="button"
                      class="meditation-switch"
                      role="switch"
                      :aria-checked="config.end_signal !== 'none'"
                      aria-labelledby="end-gong-label"
                      @click="toggleGong('end_signal')"
                    >
                      <span />
                    </button>
                  </div>
                </fieldset>
              </section>
              <section class="card meditation-background">
                <fieldset :disabled="locked || requestBusy" class="sound-switches">
                  <div class="sound-row">
                    <span class="sound-icon"><AudioLines aria-hidden="true" /></span>
                    <h2 id="background-label">Фоновый звук</h2>
                    <button
                      type="button"
                      class="meditation-switch"
                      role="switch"
                      :aria-checked="backgroundEnabled"
                      aria-labelledby="background-label"
                      @click="backgroundEnabled = !backgroundEnabled"
                    >
                      <span />
                    </button>
                  </div>
                  <template v-if="backgroundEnabled">
                    <div class="ambience-options" aria-label="Выбор фонового звука">
                      <button
                        v-for="(item, key) in soundManifest.ambience"
                        :key="key"
                        type="button"
                        class="btn"
                        :class="{ 'ambience-selected': config.ambience === key }"
                        :aria-pressed="config.ambience === key"
                        @click="config.ambience = key"
                      >
                        {{ item.name }}
                      </button>
                    </div>
                    <label class="background-volume"
                      >Громкость
                      <input
                        v-model.number="config.ambience_volume"
                        type="range"
                        min="0"
                        max="1"
                        step="0.01"
                      />
                    </label>
                    <p v-if="config.audio_asset_id" class="muted small">
                      Также выбрана личная запись. Управление — в разделе «Моё аудио».
                    </p>
                    <p class="muted small">Лес, дождь и волны — синтез звуков природы.</p>
                  </template>
                </fieldset>
              </section>
              <button class="btn save-preferences" :disabled="locked || requestBusy" @click="saveSettings">
                Сохранить мои настройки
              </button>
            </ResponsivePanel>
          </div>
          <PlayerWindow :open="immersive" title="Медитация" @close="closeWindow"
            ><section
              class="card meditation-timer"
              :class="{ 'is-active': locked, 'meditation-immersive': immersive }"
              aria-label="Таймер медитации"
            >
              <header v-if="immersive" class="meditation-window-header">
                <strong>Медитация</strong
                ><button
                  class="icon-btn"
                  aria-label="Свернуть медитацию и поставить на паузу"
                  @click="closeWindow"
                >
                  <X />
                </button>
              </header>
              <p v-if="immersive && error" class="alert" role="alert">{{ error }}</p>
              <p v-if="immersive && notice" class="success-banner" role="status">{{ notice }}</p>
              <img
                v-if="immersive"
                class="meditation-window-figure"
                src="/images/brand/poses/seated.svg"
                alt="Поза для медитации"
              />
              <div v-else class="meditation-emblem" aria-hidden="true"><Leaf /></div>
              <p class="meditation-kicker">
                {{ preparing ? 'Подготовка' : kindNames[session && !terminal ? session.kind : config.kind] }}
              </p>
              <CountdownRing
                v-if="immersive"
                :seconds="
                  preparing
                    ? preparationLeft
                    : session && !terminal
                      ? shownSeconds
                      : (config.duration_seconds ?? 0)
                "
                :total="
                  preparing
                    ? config.preparation_seconds
                    : session && !terminal
                      ? session.settings.duration_seconds
                      : config.duration_seconds
                "
                :label="
                  preparing
                    ? 'Подготовка'
                    : !terminal && !running
                      ? 'На паузе'
                      : (session && !terminal
                            ? session.settings.duration_seconds
                            : config.duration_seconds) === null
                        ? 'Прошло'
                        : 'Осталось'
                "
                :paused="(!running && !preparing) || preparationPaused"
              />
              <div v-else class="meditation-clock" role="timer" aria-atomic="true">
                {{
                  preparing
                    ? time(preparationLeft)
                    : session && !terminal
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
                              : 'Устройтесь удобно и начните'
                }}
              </p>
              <p v-if="session && !terminal" class="muted small">
                Активное время: {{ time(activeMs / 1000) }}
              </p>
              <div
                v-if="breath && !terminal"
                class="breathing-guide"
                :style="{ '--breath-scale': breath.scale }"
              >
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
                  class="btn btn-primary meditation-start"
                  :disabled="requestBusy || uploading"
                  @click="start"
                >
                  <Play :size="18" fill="currentColor" aria-hidden="true" />
                  {{ session ? 'Начать снова' : 'Начать' }}
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
              </div>
              <div class="meditation-utilities">
                <button class="btn" @click="muted = !muted" :aria-pressed="muted">
                  <VolumeX v-if="muted" :size="17" aria-hidden="true" /><Volume2
                    v-else
                    :size="17"
                    aria-hidden="true"
                  />
                  {{ muted ? 'Звук выключен' : 'Звук включён' }}
                </button>
                <button v-if="!immersive" class="btn" @click="fullscreen">
                  <Expand :size="17" aria-hidden="true" />На весь экран
                </button>
                <button v-if="running" class="btn" @click="sound">Возобновить звук</button>
              </div>
              <p class="muted small">
                При сворачивании страницы, блокировке или потере связи таймер ставится на паузу. Вернувшись,
                продолжите явно.
              </p>
            </section></PlayerWindow
          >
        </div>
        <div class="meditation-extras">
          <details class="disclosure meditation-extra">
            <summary>
              <span class="disclosure-icon"><SlidersHorizontal aria-hidden="true" /></span>
              <span class="disclosure-copy"
                ><strong>Дополнительные настройки</strong
                ><small>Время, звуки и сопровождение вашей практики</small></span
              >
            </summary>
            <div class="disclosure-body extra-content">
              <p v-if="locked" class="settings-notice">
                Настройки действуют для следующей сессии. Завершите текущую, чтобы их изменить.
              </p>
              <div class="meditation-settings-grid">
                <section class="settings-panel">
                  <header class="settings-panel-heading">
                    <span class="sound-icon"><Timer aria-hidden="true" /></span>
                    <div>
                      <h3>Время и режим</h3>
                      <p>Выберите удобный формат практики</p>
                    </div>
                  </header>
                  <fieldset :disabled="locked || requestBusy" class="meditation-form">
                    <label
                      >Режим<AppSelect v-model="config.kind">
                        <option value="meditation">Медитация</option>
                        <option value="breathing">Дыхание</option>
                        <option value="tones">Звуковые частоты</option>
                      </AppSelect></label
                    >
                    <label
                      >Длительность<AppSelect
                        :value="config.duration_seconds === null ? 'free' : 'timed'"
                        @change="
                          config.duration_seconds =
                            ($event.target as HTMLSelectElement).value === 'free' ? null : 600
                        "
                      >
                        <option value="timed">Заданное время</option>
                        <option value="free">Свободный отсчёт</option>
                      </AppSelect></label
                    >
                    <label v-if="config.duration_seconds !== null"
                      >Минуты<input
                        class="input"
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
                        class="input"
                        v-model.number="config.preparation_seconds"
                        type="number"
                        min="0"
                        max="300"
                    /></label>
                    <label class="field-wide"
                      >Часовой пояс<AppSelect v-model="config.timezone">
                        <option v-for="zone in timezoneOptions" :key="zone" :value="zone">{{ zone }}</option>
                      </AppSelect></label
                    >
                  </fieldset>
                </section>
                <section class="settings-panel">
                  <header class="settings-panel-heading">
                    <span class="sound-icon"><Bell aria-hidden="true" /></span>
                    <div>
                      <h3>Сигналы</h3>
                      <p>Мягкое начало, завершение и интервалы</p>
                    </div>
                  </header>
                  <fieldset :disabled="locked" class="meditation-form">
                    <label
                      >В начале<AppSelect v-model="config.start_signal">
                        <option v-for="(name, key) in signalNames" :key="key" :value="key">{{ name }}</option>
                      </AppSelect></label
                    >
                    <label
                      >В конце<AppSelect v-model="config.end_signal">
                        <option v-for="(name, key) in signalNames" :key="key" :value="key">{{ name }}</option>
                      </AppSelect></label
                    >
                    <label
                      >Интервальный сигнал<AppSelect v-model="config.interval_signal">
                        <option v-for="(name, key) in signalNames" :key="key" :value="key">{{ name }}</option>
                      </AppSelect></label
                    >
                    <label v-if="config.interval_signal !== 'none'"
                      >Интервал, секунд<input
                        class="input"
                        v-model.number="config.interval_seconds"
                        type="number"
                        min="1"
                        max="43200"
                    /></label>
                    <label class="field-wide"
                      >Громкость сигналов<input
                        v-model.number="config.signal_volume"
                        type="range"
                        min="0"
                        max="1"
                        step="0.01"
                    /></label>
                  </fieldset>
                  <div class="signal-previews">
                    <span class="field-caption">Послушать сигнал</span>
                    <div class="meditation-actions">
                      <button
                        v-for="signal in ['gong', 'bowl', 'bell'] as Signal[]"
                        :key="signal"
                        type="button"
                        class="btn"
                        :disabled="locked"
                        @click="preview(signal)"
                      >
                        <Play :size="15" aria-hidden="true" />{{ signalNames[signal] }}</button
                      ><button type="button" class="btn" :disabled="locked" @click="engine.stop">
                        Остановить
                      </button>
                    </div>
                  </div>
                </section>
                <section class="settings-panel settings-atmosphere">
                  <header class="settings-panel-heading">
                    <span class="sound-icon"><AudioLines aria-hidden="true" /></span>
                    <div>
                      <h3>Атмосфера</h3>
                      <p>Звуковой фон и его громкость</p>
                    </div>
                  </header>
                  <fieldset :disabled="locked" class="meditation-form">
                    <label
                      >Звуковой фон<AppSelect v-model="config.ambience">
                        <option value="none">Без фона</option>
                        <option v-for="(item, key) in soundManifest.ambience" :key="key" :value="key">
                          {{ item.name }} — синтез
                        </option>
                      </AppSelect></label
                    ><label
                      >Громкость фона<input
                        v-model.number="config.ambience_volume"
                        type="range"
                        min="0"
                        max="1"
                        step="0.01"
                    /></label>
                  </fieldset>
                  <p class="muted small">Синтезированные звуковые образы природы и инструментов.</p>
                </section>
              </div>
              <div class="meditation-mode-grid">
                <details class="disclosure mode-disclosure">
                  <summary>
                    <span class="disclosure-icon"><Wind aria-hidden="true" /></span
                    ><span class="disclosure-copy"
                      ><strong>Дыхательное сопровождение</strong
                      ><small>Вдох, выдох и паузы в вашем ритме</small></span
                    >
                  </summary>
                  <div class="disclosure-body">
                    <div class="settings-toggle">
                      <div>
                        <strong>Показывать ритм</strong>
                        <p>Визуальная подсказка для дыхания</p>
                      </div>
                      <button
                        type="button"
                        class="meditation-switch"
                        role="switch"
                        aria-label="Показывать ритм дыхания"
                        :aria-checked="config.breathing_enabled"
                        :disabled="locked"
                        @click="config.breathing_enabled = !config.breathing_enabled"
                      >
                        <span aria-hidden="true"></span>
                      </button>
                    </div>
                    <fieldset :disabled="locked" class="meditation-form">
                      <label
                        >Сигнал смены фазы<AppSelect v-model="config.breathing_signal">
                          <option v-for="(name, key) in signalNames" :key="key" :value="key">
                            {{ name }}
                          </option>
                        </AppSelect></label
                      ><label
                        >Вдох, секунд<input
                          class="input"
                          v-model.number="config.inhale_seconds"
                          type="number"
                          min="1"
                          max="30" /></label
                      ><label
                        >Выдох, секунд<input
                          class="input"
                          v-model.number="config.exhale_seconds"
                          type="number"
                          min="1"
                          max="30" /></label
                      ><label
                        >Пауза после вдоха<input
                          class="input"
                          v-model.number="config.hold_in_seconds"
                          type="number"
                          min="0"
                          max="30" /></label
                      ><label
                        >Пауза после выдоха<input
                          class="input"
                          v-model.number="config.hold_out_seconds"
                          type="number"
                          min="0"
                          max="30" /></label
                      ><label
                        >Количество циклов<input
                          class="input"
                          v-model.number="config.breathing_cycles"
                          type="number"
                          min="1"
                          max="1000"
                      /></label>
                    </fieldset>
                    <p class="muted small">
                      Следуйте комфортному ритму. Паузы отключены по умолчанию; подсказка не измеряет ваше
                      дыхание.
                    </p>
                  </div>
                </details>
                <details class="disclosure mode-disclosure">
                  <summary>
                    <span class="disclosure-icon"><AudioLines aria-hidden="true" /></span
                    ><span class="disclosure-copy"
                      ><strong>Звуковые частоты</strong><small>Ровный тон на выбранной частоте</small></span
                    >
                  </summary>
                  <div class="disclosure-body">
                    <div class="settings-toggle">
                      <div>
                        <strong>Включить тон</strong>
                        <p>Одна частота с отдельной громкостью</p>
                      </div>
                      <button
                        type="button"
                        class="meditation-switch"
                        role="switch"
                        aria-label="Включить звуковой тон"
                        :aria-checked="config.tone_enabled"
                        :disabled="locked"
                        @click="config.tone_enabled = !config.tone_enabled"
                      >
                        <span aria-hidden="true"></span>
                      </button>
                    </div>
                    <fieldset :disabled="locked" class="meditation-form">
                      <label
                        >Частота, Гц<input
                          class="input"
                          v-model.number="config.tone_frequency"
                          type="number"
                          min="20"
                          max="2000" /></label
                      ><label
                        >Длительность тона, секунд<input
                          class="input"
                          v-model.number="config.tone_seconds"
                          type="number"
                          min="1"
                          max="43200" /></label
                      ><label class="field-wide"
                        >Громкость тона<input
                          v-model.number="config.tone_volume"
                          type="range"
                          min="0"
                          max="0.2"
                          step="0.005"
                      /></label>
                    </fieldset>
                    <p class="muted small">
                      Одиночный тон, без бинаурального эффекта и медицинских обещаний.
                    </p>
                  </div>
                </details>
              </div>
              <div class="settings-save">
                <p class="muted small">Настройки сохранятся для ваших следующих сессий.</p>
                <button class="btn btn-primary" :disabled="locked || requestBusy" @click="saveSettings">
                  Сохранить мои настройки
                </button>
              </div>
            </div>
          </details>
          <details class="disclosure meditation-extra">
            <summary>
              <span class="disclosure-icon"><AudioLines aria-hidden="true" /></span
              ><span class="disclosure-copy"
                ><strong>Моё аудио</strong><small>Личные записи и музыка для практики</small></span
              >
            </summary>
            <div class="disclosure-body extra-content">
              <div class="meditation-audio-grid">
                <section class="settings-panel">
                  <header class="settings-panel-heading">
                    <span class="sound-icon"><Play aria-hidden="true" /></span>
                    <div>
                      <h3>Для этой сессии</h3>
                      <p>Запись, громкость и повтор</p>
                    </div>
                  </header>
                  <fieldset :disabled="locked || uploading" class="meditation-form">
                    <label
                      >Личная запись<AppSelect v-model="config.audio_asset_id">
                        <option :value="null">Без личного аудио</option>
                        <option
                          v-for="item in audios.filter((item) => !item.archived_at)"
                          :key="item.id"
                          :value="item.id"
                        >
                          {{ item.title }} · {{ time(item.duration_ms / 1000) }}
                        </option>
                      </AppSelect></label
                    ><label
                      >Громкость записи<input
                        v-model.number="config.audio_volume"
                        type="range"
                        min="0"
                        max="1"
                        step="0.01" /></label
                    ><label v-if="!config.audio_repeat"
                      >После окончания<AppSelect v-model="config.audio_end">
                        <option value="continue">Продолжить таймер</option>
                        <option value="finish">Завершить сессию</option>
                      </AppSelect></label
                    >
                  </fieldset>
                  <div class="settings-toggle">
                    <div>
                      <strong>Повторять запись</strong>
                      <p>Начинать снова после завершения</p>
                    </div>
                    <button
                      type="button"
                      class="meditation-switch"
                      role="switch"
                      aria-label="Повторять личную запись"
                      :aria-checked="config.audio_repeat"
                      :disabled="locked || uploading"
                      @click="config.audio_repeat = !config.audio_repeat"
                    >
                      <span aria-hidden="true"></span>
                    </button>
                  </div>
                </section>
                <section class="settings-panel">
                  <header class="settings-panel-heading">
                    <span class="sound-icon"><Upload aria-hidden="true" /></span>
                    <div>
                      <h3>Добавить запись</h3>
                      <p>Аудио доступно только вашему аккаунту</p>
                    </div>
                  </header>
                  <form class="audio-upload" @submit.prevent="upload">
                    <label
                      >Название<input
                        class="input"
                        v-model="audioTitle"
                        maxlength="160"
                        required
                        :disabled="uploading" /></label
                    ><label
                      >MP3, M4A/AAC, WAV<input
                        type="file"
                        accept=".mp3,.m4a,.wav,audio/mpeg,audio/mp4,audio/wav"
                        :disabled="uploading"
                        @change="selectFile" /></label
                    ><button
                      class="btn btn-primary"
                      :disabled="uploading || !audioFile || !audioTitle.trim()"
                      type="submit"
                    >
                      Загрузить личную запись</button
                    ><template v-if="uploading"
                      ><progress :value="uploadPercent" max="100"></progress
                      ><span
                        >{{ uploadPercent }}%{{
                          uploadPercent === 100 ? ' — проверяем декодирование…' : ''
                        }}</span
                      ><button type="button" class="btn" @click="uploadCancel?.()">
                        Отменить загрузку
                      </button></template
                    >
                  </form>

                  <p class="muted small">
                    До {{ Math.round(limits.max_bytes / 1048576) }} МБ и
                    {{ Math.round(limits.max_seconds / 60) }} минут на файл. Вся библиотека с архивом — до
                    {{ Math.round(limits.max_library_bytes / 1048576) }} МБ.
                  </p>
                </section>
              </div>
              <div class="audio-library">
                <label class="check"
                  ><input v-model="audioArchive" type="checkbox" /> Показать архив аудио</label
                >
                <p v-if="!audios.length" class="muted">
                  {{ audioArchive ? 'Архив пуст.' : 'Личных записей пока нет.' }}
                </p>
                <ul class="audio-list">
                  <li v-for="item in audios" :key="item.id">
                    <div>
                      <strong>{{ item.title }}</strong
                      ><small
                        >{{ time(item.duration_ms / 1000) }} ·
                        {{ (item.bytes / 1048576).toFixed(1) }} МБ</small
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
              </div>
            </div>
          </details>
          <details class="disclosure meditation-extra">
            <summary>
              <span class="disclosure-icon"><CalendarDays aria-hidden="true" /></span
              ><span class="disclosure-copy"
                ><strong>Моя статистика</strong><small>Время, регулярность и история сессий</small></span
              >
            </summary>
            <div class="disclosure-body extra-content">
              <div class="statistics-toolbar">
                <label
                  >Период<AppSelect v-model="period"
                    ><option value="1">Сегодня</option>
                    <option value="7">7 дней</option>
                    <option value="30">30 дней</option>
                    <option value="180">6 месяцев</option>
                    <option value="all">Всё время</option></AppSelect
                  ></label
                ><a class="btn" :href="exportUrl"><Download :size="17" aria-hidden="true" />Экспорт CSV</a>
              </div>
              <p class="muted small">
                Часовой пояс: {{ config.timezone }}. Завершённые и прерванные сессии учитываются отдельно.
              </p>
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
            </div>
          </details>
        </div>
      </template>
    </div>
  </AppShell>
</template>

<style scoped>
.meditation-page {
  display: grid;
  gap: var(--section-gap);
  width: 100%;
  min-width: 0;
  color: var(--text, #151916);
}
.meditation-page h2 {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0 0 20px;
  font-size: var(--panel-title-size);
  font-weight: 650;
  letter-spacing: -0.025em;
}
.meditation-page h2 > svg {
  width: 21px;
  height: 21px;
  color: var(--accent-dark, #16783a);
  stroke-width: 1.8;
}
.meditation-page h3 {
  font-size: 16px;
  font-weight: 600;
  margin: 24px 0 10px;
}
.muted {
  color: var(--muted, #6b726d);
}
.small {
  font-size: 12px;
  line-height: 1.65;
  margin-top: 12px;
}
.meditation-error {
  background: #fff3f0;
  border: 1px solid #f0c3b9;
  color: #8a3320;
  border-radius: 18px;
  padding: 16px;
}
.meditation-notice {
  background: var(--accent-soft, #eaf9ef);
  padding: 16px;
  border-radius: 18px;
  font-size: 14px;
}
.meditation-workspace {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);
  align-items: start;
  gap: 24px;
}
.meditation-setup {
  display: grid;
  gap: 18px;
}
.duration-options {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
  padding: 0;
  margin: 0;
  border: 0;
}
.duration-option {
  min-height: 54px;
  padding: 12px 8px;
  border: 1px solid var(--line, #e3e8e4);
  border-radius: 100px;
  background: linear-gradient(180deg, #ffffffed, #f3f6f3c9);
  color: var(--text, #151916);
  box-shadow:
    inset 0 1px 0 #fff,
    0 2px 4px #15191603;
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  transition:
    background 0.18s,
    border-color 0.18s,
    box-shadow 0.18s;
}
.duration-option span {
  font-size: 14px;
  font-weight: 500;
}
.duration-option:hover:not(:disabled) {
  border-color: var(--accent-dark, #16783a);
  background: var(--accent-soft, #eaf9ef);
}
.duration-option.selected {
  border-color: #2c6041;
  background: #2c6041;
  box-shadow:
    inset 0 1px 0 #ffffff29,
    0 4px 12px #2c604118;
  color: #fff;
}
.duration-option.selected:hover:not(:disabled) {
  border-color: #234e34;
  background: #234e34;
}
.duration-option:focus-visible,
.meditation-switch:focus-visible {
  outline: 3px solid var(--accent-dark, #16783a);
  outline-offset: 4px;
}
.sound-switches {
  padding: 0;
  margin: 0;
  border: 0;
  min-width: 0;
}
.sound-row {
  display: flex;
  align-items: center;
  gap: 14px;
  min-height: 62px;
}
.sound-row + .sound-row {
  border-top: 1px solid var(--line, #e3e8e4);
  margin-top: 10px;
  padding-top: 10px;
}
.sound-icon {
  display: grid;
  place-items: center;
  flex: 0 0 40px;
  height: 40px;
  border-radius: 13px;
  background: #edf3e7;
  color: #45653b;
}
.sound-icon svg {
  width: 20px;
  height: 20px;
  stroke-width: 1.6;
}
.sound-label {
  display: grid;
  gap: 4px;
  flex: 1;
  min-width: 0;
  font-size: 16px;
}
.sound-label small {
  color: var(--muted, #6b726d);
  font-size: 11px;
  line-height: 1.5;
}
.meditation-switch {
  position: relative;
  flex: 0 0 50px;
  width: 50px;
  height: 44px;
  padding: 0;
  border: 0;
  border-radius: 100px;
  background: transparent;
  cursor: pointer;
}
.meditation-switch::before {
  content: '';
  position: absolute;
  left: 0;
  top: 7px;
  width: 50px;
  height: 30px;
  box-sizing: border-box;
  border: 1px solid #00000005;
  border-radius: 100px;
  background: #dce1dd;
  box-shadow: inset 0 1px 3px #1519160a;
  transition: background 0.18s;
}
.meditation-switch > span {
  position: absolute;
  left: 4px;
  top: 11px;
  display: block;
  width: 22px;
  height: 22px;
  background: white;
  border-radius: 50%;
  box-shadow: 0 1px 4px #15191626;
  transition: transform 0.18s;
}
.meditation-switch[aria-checked='true']::before {
  background: var(--accent, #2ac45a);
}
.meditation-switch[aria-checked='true'] > span {
  transform: translateX(20px);
}
.meditation-background .sound-row {
  min-height: 44px;
}
.meditation-background h2 {
  margin: 0;
  flex: 1;
  font-size: 17px;
}
.ambience-options {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  margin-top: 22px;
}
.ambience-options .btn {
  justify-content: center;
  font-size: 13px;
}
.ambience-selected {
  color: var(--accent-dark, #16783a);
  border-color: #a8dfb8;
  background: var(--accent-soft, #eaf9ef);
  box-shadow: inset 0 1px 0 #fff;
}
.meditation-page .background-volume {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 16px;
  margin-top: 12px;
  font-size: 12px;
  color: var(--muted, #6b726d);
}
.background-volume input {
  flex: 1;
  min-width: 0;
}
.save-preferences {
  justify-self: start;
  color: var(--muted, #6b726d);
  font-size: 12px;
}
.meditation-timer {
  position: sticky;
  top: 110px;
  text-align: center;
  padding: 36px 24px 24px;
  background: radial-gradient(ellipse 180px 130px at 50% 14%, #edf2e4b3, transparent), #fff;
}
.meditation-emblem {
  display: grid;
  place-items: center;
  width: 78px;
  height: 78px;
  margin: 0 auto 26px;
  border-radius: 50%;
  border: 1px solid #d1dfc4;
  background: linear-gradient(145deg, #ffffffed, #e6efdbb3);
  box-shadow:
    inset 0 1px 2px white,
    0 8px 24px #47613712;
  color: #42653a;
}
.meditation-emblem svg {
  width: 32px;
  height: 32px;
  stroke-width: 1.3;
}
.meditation-kicker {
  font-size: 13px;
  font-weight: 500;
  color: var(--muted, #6b726d);
}
.meditation-clock {
  margin: 14px 0 6px;
  font-size: clamp(54px, 6.4vw, 78px);
  line-height: 1.1;
  font-weight: 400;
  letter-spacing: -0.055em;
  font-variant-numeric: tabular-nums;
  color: var(--text, #151916);
}
.meditation-clock + p {
  margin-top: 14px;
  font-size: 13px;
  color: var(--muted, #6b726d);
}
.meditation-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-top: 16px;
}
.meditation-timer > .meditation-actions {
  justify-content: center;
  margin-top: 30px;
}
.meditation-timer .meditation-actions .btn-primary {
  width: 100%;
  min-height: 54px;
  justify-content: center;
  gap: 10px;
  font-size: 16px;
}
.meditation-utilities {
  display: flex;
  justify-content: center;
  gap: 4px;
  flex-wrap: wrap;
  margin: 14px 0 18px;
}
.meditation-utilities .btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  padding: 8px 10px;
  min-height: 38px;
  background: transparent;
  box-shadow: none;
  border-color: transparent;
  color: var(--muted, #6b726d);
}
.meditation-timer > .small:last-child {
  margin: 20px auto 0;
  max-width: 280px;
  font-size: 11px;
}
.meditation-timer.meditation-immersive {
  position: static;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: min(100%, 620px);
  min-height: 100%;
  margin: auto;
  padding: 0;
  border: 0;
  box-shadow: none;
  background: transparent;
  border-radius: 0;
  gap: 12px;
}
.meditation-immersive > .meditation-actions {
  width: min(100%, 340px);
  margin-top: 8px;
  justify-content: center;
}
.meditation-window-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  text-align: left;
}
.meditation-window-header strong {
  font-size: 20px;
  font-weight: 550;
}
.meditation-window-header .icon-btn {
  background: white;
  border-radius: 50%;
  color: var(--accent-dark);
  width: 44px;
  height: 44px;
}
.meditation-window-figure {
  width: min(100%, 340px);
  height: clamp(120px, 25dvh, 280px);
  object-fit: contain;
}
.meditation-immersive .meditation-utilities {
  margin-block: 0;
}
.meditation-immersive > .small:last-child {
  max-width: 360px;
  margin-top: 0;
}
.meditation-immersive .breathing-guide {
  margin: 0 auto;
}
@media (max-width: 500px) {
  .meditation-immersive {
    --countdown-size: 156px;
  }
  .meditation-timer.meditation-immersive {
    gap: 10px;
  }
}
@media (max-height: 700px) {
  .meditation-immersive {
    --countdown-size: 130px;
  }
  .meditation-window-figure {
    height: 110px;
  }
}
.meditation-extras {
  display: grid;
  gap: 16px;
  margin-top: 8px;
}
.meditation-extra {
  min-width: 0;
}
.meditation-settings-grid,
.meditation-audio-grid,
.meditation-mode-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  align-items: start;
  gap: 18px;
}
.meditation-mode-grid {
  margin-top: 18px;
}
.settings-panel {
  min-width: 0;
  border: 1px solid var(--line);
  border-radius: 22px;
  padding: 22px;
  background: linear-gradient(155deg, #fff, #f9fbf9);
  box-shadow: inset 0 1px 0 #fff;
}
.settings-atmosphere {
  grid-column: 1 / -1;
}
.settings-panel-heading {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 22px;
}
.settings-panel-heading > div {
  min-width: 0;
}
.settings-panel-heading h3 {
  margin: 0;
  font-size: 17px;
  line-height: 1.3;
  font-weight: 650;
  letter-spacing: -0.025em;
}
.settings-panel-heading p,
.settings-toggle p {
  color: var(--muted);
  margin-top: 4px;
  font-size: 12px;
  line-height: 1.5;
}
.meditation-form {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px 14px;
  border: 0;
  padding: 0;
  margin: 0;
  min-width: 0;
}
.meditation-form .field-wide {
  grid-column: 1 / -1;
}
.meditation-page label {
  display: flex;
  flex-direction: column;
  gap: 9px;
  min-width: 0;
  font-size: 13px;
  font-weight: 500;
  line-height: 1.5;
}
.meditation-page label.check {
  flex-direction: row;
  align-items: center;
  gap: 10px;
  margin: 0;
}
.meditation-page input[type='range'] {
  width: 100%;
  min-width: 0;
}
.meditation-page fieldset:disabled {
  opacity: 0.6;
}
.meditation-page fieldset:disabled button {
  cursor: default;
}
.settings-toggle {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  min-height: 54px;
  margin: 0 0 20px;
}
.settings-toggle strong {
  font-size: 14px;
  font-weight: 600;
}
.settings-panel > .settings-toggle {
  border-top: 1px solid var(--line);
  padding-top: 18px;
  margin: 18px 0 0;
}
.settings-notice {
  color: var(--accent-dark);
  background: var(--accent-soft);
  border-radius: 18px;
  padding: 14px 18px;
  margin-bottom: 18px;
  font-size: 13px;
  line-height: 1.6;
}
.signal-previews {
  border-top: 1px solid var(--line);
  margin-top: 20px;
  padding-top: 16px;
}
.field-caption {
  color: var(--muted);
  font-size: 12px;
}
.signal-previews .meditation-actions {
  gap: 6px;
  margin-top: 10px;
}
.signal-previews .btn {
  font-size: 12px;
  min-height: 40px;
  padding-inline: 12px;
  gap: 6px;
}
.settings-save {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
  border-top: 1px solid var(--line);
  padding-top: 20px;
  margin-top: 22px;
}
.settings-save .small {
  margin: 0;
}
.audio-upload {
  display: grid;
  gap: 16px;
  margin: 0;
}
.audio-upload progress {
  width: 100%;
  accent-color: var(--accent);
}
.audio-upload .btn {
  justify-content: center;
}
.audio-library {
  margin-top: 24px;
}
.audio-library > p {
  margin-top: 16px;
  padding: 28px 20px;
  border-radius: 20px;
  background: var(--surface-soft);
  text-align: center;
  font-size: 14px;
}
.audio-list,
.meditation-history {
  list-style: none;
  padding: 0;
  display: grid;
  gap: 10px;
  margin-top: 16px;
}
.audio-list li {
  display: grid;
  gap: 12px;
  padding: 18px;
  border: 1px solid var(--line);
  border-radius: 20px;
  background: var(--glass);
}
.audio-list small {
  display: block;
  color: var(--muted);
  font-size: 12px;
  margin-top: 4px;
}
.audio-list audio {
  width: min(100%, 440px);
}
.audio-list .meditation-actions {
  margin: 0;
}
.statistics-toolbar {
  display: flex;
  align-items: end;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 16px;
}
.statistics-toolbar label {
  width: 240px;
  max-width: 100%;
}
.meditation-history li {
  display: grid;
  grid-template-columns: minmax(170px, 1.2fr) 1fr auto auto;
  align-items: center;
  gap: 12px;
  padding: 16px 18px;
  border: 1px solid var(--line);
  border-radius: 18px;
  font-size: 13px;
}
.meditation-history li span:last-child {
  color: var(--muted);
}
.meditation-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
  margin: 24px 0;
}
.meditation-stats > div {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 22px;
  border-radius: 22px;
  border: 1px solid var(--line);
  background: var(--glass);
}
.meditation-stats strong {
  font-size: 38px;
  line-height: 1.1;
  font-weight: 500;
  letter-spacing: -0.05em;
  color: var(--text);
  font-variant-numeric: tabular-nums;
}
.meditation-stats > div:first-child {
  border-color: #ccebd5;
  background: linear-gradient(155deg, #fff, var(--accent-soft));
}
.meditation-stats span {
  font-size: 13px;
  color: var(--muted);
}
.meditation-calendar {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 7px;
  max-width: 480px;
}
.meditation-calendar > span {
  display: flex;
  min-height: 49px;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  border: 1px solid var(--line);
  border-radius: 13px;
  background: var(--glass);
  font-size: 13px;
  font-variant-numeric: tabular-nums;
}
.meditation-calendar > span.practiced {
  background: var(--accent-soft);
  border-color: #ccebd5;
  color: var(--accent-dark);
}
.meditation-calendar small {
  font-size: 10px;
}
@media (max-width: 960px) {
  .meditation-settings-grid,
  .meditation-audio-grid,
  .meditation-mode-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
@media (max-width: 600px) {
  .settings-panel {
    padding: 18px;
    border-radius: 20px;
  }
  .meditation-form {
    grid-template-columns: minmax(0, 1fr);
    gap: 16px;
  }
  .settings-panel-heading {
    margin-bottom: 20px;
  }
  .settings-save > .btn {
    width: 100%;
    justify-content: center;
  }
  .meditation-stats {
    gap: 8px;
  }
  .meditation-stats > div {
    padding: 16px 10px;
    border-radius: 18px;
  }
  .meditation-stats strong {
    font-size: 30px;
  }
  .meditation-stats span {
    font-size: 11px;
    line-height: 1.4;
  }
  .meditation-history li {
    grid-template-columns: 1fr auto;
  }
  .meditation-history li span:first-child {
    grid-column: 1 / -1;
  }
  .meditation-history li span:last-child {
    grid-column: 1 / -1;
  }
  .statistics-toolbar label {
    width: 100%;
  }
}
.breathing-guide {
  position: relative;
  height: 170px;
  width: 190px;
  display: grid;
  place-items: center;
  margin: 20px auto;
}
.breathing-orb {
  position: absolute;
  width: 145px;
  height: 145px;
  border-radius: 50%;
  background: var(--accent-soft, #eaf9ef);
  border: 1px solid #b5edc5;
  transform: scale(var(--breath-scale));
  transition: transform 1.5s ease;
}
.breathing-label {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 14px;
}
.breathing-label span {
  font-size: 28px;
}
@media (prefers-reduced-motion: reduce) {
  .breathing-orb {
    transition: none;
    transform: none;
  }
  .duration-option,
  .meditation-switch::before,
  .meditation-switch > span {
    transition: none;
  }
}
@media (max-width: 760px) {
  .meditation-page {
    gap: 20px;
  }
  .meditation-workspace {
    grid-template-columns: minmax(0, 1fr);
    gap: 20px;
  }
  .meditation-setup {
    gap: 14px;
  }
  .meditation-timer {
    position: static;
    padding-top: 28px;
  }
  .meditation-emblem {
    width: 64px;
    height: 64px;
    margin-bottom: 20px;
  }
  .meditation-clock {
    font-size: 64px;
  }
}
@media (max-width: 420px) {
  .meditation-page h2 {
    font-size: 18px;
    margin-bottom: 18px;
  }
  .meditation-background h2 {
    margin-bottom: 0;
    font-size: 16px;
  }
  .sound-row {
    gap: 10px;
  }
  .sound-icon {
    flex-basis: 36px;
    height: 36px;
    border-radius: 11px;
  }
  .sound-label {
    font-size: 15px;
  }
  .duration-option {
    min-height: 50px;
    font-size: 15px;
  }
  .duration-option span {
    font-size: 13px;
  }
}
</style>
