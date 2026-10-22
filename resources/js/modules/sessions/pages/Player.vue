<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppShell from '@/shared/AppShell.vue';
import { api } from '@/shared/api';
import { uid } from '@/shared/uuid';
import BodyCoverage from '../BodyCoverage.vue';
import type { ExecutionPlan, ExecutionProgress, ExecutionSession } from '../types';
const props = defineProps<{ documentId?: string; publicToken?: string }>();
const page = usePage();
const plan = ref<ExecutionPlan | null>(null),
  session = ref<ExecutionSession | null>(null),
  loading = ref(true),
  error = ref(''),
  notice = ref(''),
  saving = ref(false),
  starting = ref(false);
const mode = ref<'teach' | 'practice'>(
  new URLSearchParams(location.search).get('mode') === 'teach' ? 'teach' : 'practice',
);
const progress = ref<ExecutionProgress>({ step: 0, seconds_by_step: [], completed: [] });
const active = ref(0),
  running = ref(false),
  started = ref(false),
  finished = ref(false),
  sound = ref(false),
  auto = ref(mode.value === 'practice'),
  breathSeconds = ref(0),
  showNames = ref(true),
  needsReconcile = ref(false);
const preparationSeconds = ref(0),
  preparing = ref(false),
  prepPaused = ref(false),
  prepLeft = ref(0);
let prepDeadline = 0,
  prepSatisfied = false;
const teacherTargets = ref<Record<number, number>>({});
const current = computed(() => plan.value?.steps[progress.value.step]);
const next = computed(() => plan.value?.steps[progress.value.step + 1]);
const stepSeconds = computed(() => progress.value.seconds_by_step[progress.value.step] || 0);
const baseTarget = computed(() =>
  current.value?.advance_mode === 'time'
    ? current.value.duration_seconds
    : current.value?.advance_mode === 'breaths' && breathSeconds.value > 0
      ? (current.value.breath_count || 0) * breathSeconds.value
      : null,
);
const target = computed(() => teacherTargets.value[progress.value.step] ?? baseTarget.value);
const remaining = computed(() =>
  target.value ? Math.max(0, Math.ceil(target.value - stepSeconds.value)) : null,
);
const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
const auth = page.props.auth as { user?: { id: number }; workspace?: { id: string } } | undefined;
const userId = auth?.user?.id;
const legacyKey = `ribka-execution:${userId || 'guest'}:${props.documentId || props.publicToken}`;
const key = `ribka-execution:${userId || 'guest'}:${auth?.workspace?.id || 'public'}:${props.documentId || props.publicToken}`;
interface StoredDraft {
  sessionId?: string;
  pendingStartId?: string;
  sessionRevision?: number;
  plan?: ExecutionPlan;
  progress?: ExecutionProgress;
  active?: number;
  mode?: 'teach' | 'practice';
  started?: boolean;
  finished?: boolean;
  preparationSeconds?: number;
  breathSeconds?: number;
  teacherTargets?: Record<number, number>;
}
let pendingStartId: string | null = null,
  ticker: ReturnType<typeof setInterval> | undefined,
  last = 0,
  syncCounter = 0,
  lastDraftAt = 0;
let audio: AudioContext | null = null,
  wake: WakeLockSentinel | null = null;
let pendingStatus: string | null = null,
  persistJob: Promise<void> | null = null,
  closing = false,
  disposed = false;
const clock = (seconds: number) =>
  `${Math.floor(seconds / 60)}:${Math.floor(seconds % 60)
    .toString()
    .padStart(2, '0')}`;
const planned = computed(() => {
  const zones = new Map<string, { name: string; seconds: number | null; count: number }>();
  let unknownCount = 0,
    unknownTimeCount = 0,
    total = 0,
    unclassified = 0;
  for (const step of plan.value?.steps ?? []) {
    const seconds =
      step.advance_mode === 'time'
        ? step.duration_seconds
        : step.advance_mode === 'breaths' && breathSeconds.value > 0 && step.breath_count
          ? step.breath_count * breathSeconds.value
          : null;
    if (seconds === null) unknownTimeCount++;
    else total += seconds;
    if (!step.anatomy.length) {
      unknownCount++;
      unclassified += seconds ?? 0;
    }
    for (const zone of step.anatomy) {
      const entry = zones.get(zone.key || zone.name) ?? { name: zone.name, seconds: 0, count: 0 };
      entry.count++;
      entry.seconds = entry.seconds === null || seconds === null ? null : entry.seconds + seconds;
      zones.set(zone.key || zone.name, entry);
    }
  }
  return {
    zones: [...zones.values()],
    unknownCount,
    unknownTimeCount,
    total: unknownTimeCount ? null : total,
    unclassified,
  };
});
async function unlockSound() {
  if (!sound.value) return;
  try {
    audio ??= new AudioContext();
    await audio.resume();
  } catch {
    notice.value = 'Звук недоступен; остальные элементы управления работают.';
  }
}
function bell() {
  if (!sound.value || !audio || audio.state !== 'running') return;
  const osc = audio.createOscillator(),
    gain = audio.createGain();
  osc.frequency.value = 660;
  gain.gain.setValueAtTime(0.08, audio.currentTime);
  gain.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + 0.7);
  osc.connect(gain).connect(audio.destination);
  osc.onended = () => {
    osc.disconnect();
    gain.disconnect();
  };
  osc.start();
  osc.stop(audio.currentTime + 0.8);
}
function draft(force = false) {
  if (!plan.value || (!force && performance.now() - lastDraftAt < 1000)) return;
  lastDraftAt = performance.now();
  try {
    localStorage.setItem(
      key,
      JSON.stringify({
        sessionId: session.value?.id,
        pendingStartId,
        sessionRevision: session.value?.revision,
        plan: plan.value,
        progress: progress.value,
        active: active.value,
        mode: mode.value,
        started: started.value,
        finished: finished.value,
        preparationSeconds: preparationSeconds.value,
        breathSeconds: breathSeconds.value,
        teacherTargets: teacherTargets.value,
      }),
    );
  } catch {
    /* Storage may be disabled. */
  }
}
function localState(): StoredDraft {
  return {
    sessionId: session.value?.id,
    sessionRevision: session.value?.revision,
    plan: plan.value ?? undefined,
    progress: JSON.parse(JSON.stringify(progress.value)),
    active: active.value,
    mode: mode.value,
  };
}
function adopt(remote: ExecutionSession, local?: StoredDraft) {
  session.value = remote;
  plan.value = remote.plan;
  mode.value = remote.mode;
  pendingStartId = remote.id;
  active.value = remote.active_seconds;
  progress.value = JSON.parse(JSON.stringify(remote.progress));
  const ended = ['completed', 'interrupted'].includes(remote.status);
  const samePlan =
    local?.plan?.steps.length === remote.plan.steps.length &&
    local.plan.steps.every((step, index) => step.id === remote.plan.steps[index]?.id);
  const localTime = Number(local?.active);
  const candidate = local?.progress;
  const dominates =
    candidate &&
    samePlan &&
    Number.isFinite(localTime) &&
    localTime >= remote.active_seconds &&
    localTime - remote.active_seconds <= 60 &&
    remote.progress.seconds_by_step.every(
      (seconds, index) => Number(candidate.seconds_by_step[index] ?? 0) >= seconds,
    );
  if (!ended && dominates && candidate) {
    const count = remote.plan.steps.length;
    const merged = Array.from(
      { length: Math.max(candidate.seconds_by_step.length, remote.progress.seconds_by_step.length) },
      (_, index) =>
        Math.max(Number(candidate.seconds_by_step[index] ?? 0), remote.progress.seconds_by_step[index] ?? 0),
    );
    if (merged.length <= count && merged.every((value) => Number.isFinite(value) && value >= 0)) {
      progress.value = {
        step: Math.max(0, Math.min(count - 1, Number(candidate.step) || 0)),
        seconds_by_step: merged,
        completed: [...new Set([...remote.progress.completed, ...candidate.completed])].filter(
          (index) => Number.isInteger(index) && index >= 0 && index < count,
        ),
      };
      active.value = Math.max(
        localTime,
        merged.reduce((sum, value) => sum + value, 0),
      );
    }
  }
  started.value = !ended;
  finished.value = ended;
  running.value = false;
  needsReconcile.value = false;
  last = performance.now();
  draft(true);
}
async function persist(status = 'paused') {
  draft(true);
  if (props.publicToken || !session.value) return;
  if (closing && !['completed', 'interrupted'].includes(status)) return persistJob;
  if (!pendingStatus || !['completed', 'interrupted'].includes(pendingStatus)) pendingStatus = status;
  if (persistJob) return persistJob;
  saving.value = true;
  persistJob = (async () => {
    while (pendingStatus && session.value) {
      const nextStatus = pendingStatus;
      pendingStatus = null;
      try {
        session.value = (
          await api<{ data: ExecutionSession }>(`/api/executions/${session.value.id}`, {
            method: 'PUT',
            body: JSON.stringify({
              expected_revision: session.value.revision,
              status: nextStatus,
              active_seconds: Math.floor(active.value),
              progress: {
                ...progress.value,
                seconds_by_step: Array.from({ length: progress.value.seconds_by_step.length }, (_, i) =>
                  Math.floor(progress.value.seconds_by_step[i] || 0),
                ),
              },
            }),
            keepalive: nextStatus === 'paused',
          })
        ).data;
        if (['completed', 'interrupted'].includes(session.value.status)) {
          running.value = false;
          finished.value = true;
        }
        draft(true);
      } catch (failure) {
        pendingStatus = null;
        running.value = false;
        needsReconcile.value = true;
        error.value =
          failure instanceof Error
            ? failure.message
            : 'Не удалось сохранить сессию. Прогресс сохранён на устройстве.';
        break;
      }
    }
  })();
  try {
    await persistJob;
  } finally {
    persistJob = null;
    saving.value = false;
  }
}
async function begin() {
  if (!plan.value?.steps.length || starting.value || running.value || closing) return;
  starting.value = true;
  error.value = '';
  try {
    if (needsReconcile.value && session.value && !props.publicToken) {
      const local = localState();
      const remote = (await api<{ data: ExecutionSession }>(`/api/executions/${session.value.id}`)).data;
      adopt(remote, local);
      if (finished.value) return;
    }
    if (!started.value && !props.publicToken) {
      pendingStartId ??= uid();
      draft(true);
      const remote = (
        await api<{ data: ExecutionSession }>('/api/executions', {
          method: 'POST',
          body: JSON.stringify({
            id: pendingStartId,
            document_id: props.documentId,
            expected_revision: plan.value.revision,
            mode: mode.value,
            timezone,
          }),
        })
      ).data;
      adopt(remote);
      if (finished.value) return;
    }
    if (disposed) return;
    preparing.value = false;
    started.value = true;
    finished.value = false;
    running.value = true;
    last = performance.now();
    bell();
    try {
      if ('wakeLock' in navigator) wake = await navigator.wakeLock.request('screen');
    } catch {
      /* Optional capability. */
    }
    draft(true);
  } catch (failure) {
    error.value =
      failure instanceof Error
        ? failure.message
        : 'Не удалось начать. Повторная попытка использует тот же идентификатор запуска.';
  } finally {
    starting.value = false;
  }
}
async function start() {
  if (starting.value || closing || running.value || !plan.value?.steps.length) return;
  await unlockSound();
  if (preparing.value && prepPaused.value) {
    prepDeadline = performance.now() + prepLeft.value * 1000;
    prepPaused.value = false;
    return;
  }
  if (preparing.value) return;
  if (!started.value && !prepSatisfied && preparationSeconds.value > 0) {
    preparing.value = true;
    prepPaused.value = false;
    prepLeft.value = Math.max(0, Math.min(120, preparationSeconds.value));
    prepDeadline = performance.now() + prepLeft.value * 1000;
    return;
  }
  await begin();
}
function accrue(now = performance.now()): boolean {
  if (!running.value) {
    last = now;
    return true;
  }
  const delta = Math.max(0, (now - last) / 1000);
  last = now;
  if (delta > 3) return false;
  active.value += delta;
  progress.value.seconds_by_step[progress.value.step] =
    (progress.value.seconds_by_step[progress.value.step] || 0) + delta;
  return true;
}
function pause() {
  if (preparing.value) {
    if (!prepPaused.value) prepLeft.value = Math.max(0, (prepDeadline - performance.now()) / 1000);
    prepPaused.value = true;
    return;
  }
  accrue();
  running.value = false;
  void wake?.release();
  wake = null;
  void audio?.suspend();
  void persist('paused');
}
function extend() {
  if (mode.value !== 'teach' || !started.value) return;
  teacherTargets.value[progress.value.step] = Math.max(target.value ?? 0, stepSeconds.value) + 30;
  draft(true);
}
function move(delta: number, complete = false) {
  if (!plan.value || !started.value || starting.value || preparing.value || finished.value) return;
  accrue();
  if (complete && !progress.value.completed.includes(progress.value.step))
    progress.value.completed.push(progress.value.step);
  const index = progress.value.step + delta;
  if (index >= plan.value.steps.length) {
    void finish(true);
    return;
  }
  progress.value.step = Math.max(0, index);
  last = performance.now();
  bell();
  draft(true);
  void persist(running.value ? 'running' : 'paused');
}
async function finish(complete: boolean) {
  if (closing || !started.value) return;
  closing = true;
  accrue();
  running.value = false;
  void wake?.release();
  wake = null;
  bell();
  await persist(complete ? 'completed' : 'interrupted');
  finished.value =
    Boolean(props.publicToken) || ['completed', 'interrupted'].includes(session.value?.status ?? '');
  closing = false;
  draft(true);
}
function tick() {
  const now = performance.now();
  if (preparing.value && !prepPaused.value) {
    prepLeft.value = Math.max(0, (prepDeadline - now) / 1000);
    if (prepLeft.value <= 0 && !starting.value) {
      preparing.value = false;
      prepSatisfied = true;
      void begin();
    }
    return;
  }
  if (!accrue(now)) {
    pause();
    notice.value =
      'Таймер приостановлен после задержки приложения. Продолжите явно; пропущенное время не добавлялось.';
  }
  if (
    running.value &&
    mode.value === 'practice' &&
    auto.value &&
    target.value &&
    stepSeconds.value >= target.value
  )
    move(1, true);
  if (++syncCounter >= 20) {
    syncCounter = 0;
    if (started.value && !finished.value) void persist(running.value ? 'running' : 'paused');
  }
  draft();
}
function visibility() {
  if (document.hidden && (running.value || preparing.value)) pause();
}
function offline() {
  if (running.value || preparing.value) pause();
  error.value = 'Соединение потеряно. Таймер на паузе, прогресс сохранён на устройстве.';
}
function leaving() {
  if (running.value || preparing.value) pause();
}
function keydown(event: KeyboardEvent) {
  if (
    (event.target as HTMLElement).matches('input,select,textarea,button') ||
    event.ctrlKey ||
    event.metaKey ||
    event.altKey
  )
    return;
  if (event.key === ' ') {
    event.preventDefault();
    running.value || (preparing.value && !prepPaused.value) ? pause() : void start();
  }
  if (started.value && event.key === 'ArrowRight') move(1, true);
  if (started.value && event.key === 'ArrowLeft') move(-1);
}
async function fullscreen() {
  try {
    await document.documentElement.requestFullscreen();
  } catch {
    notice.value = 'Полный экран недоступен; можно продолжить в обычном режиме.';
  }
}
async function restart() {
  if (starting.value) return;
  started.value = false;
  finished.value = false;
  session.value = null;
  pendingStartId = null;
  prepSatisfied = false;
  active.value = 0;
  progress.value = { step: 0, seconds_by_step: [], completed: [] };
  teacherTargets.value = {};
  try {
    plan.value = (
      await api<{ data: ExecutionPlan }>(
        props.publicToken
          ? `/api/published/${props.publicToken}/plan`
          : `/api/executions/plan/${props.documentId}`,
      )
    ).data;
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось открыть актуальный план.';
  }
  draft(true);
}
watch(mode, (value) => {
  auto.value = value === 'practice';
});
onMounted(async () => {
  let stored: StoredDraft | null = null;
  try {
    const raw = localStorage.getItem(key) ?? localStorage.getItem(legacyKey);
    if (raw) stored = JSON.parse(raw);
  } catch {
    /* No local recovery. */
  }
  try {
    plan.value = (
      await api<{ data: ExecutionPlan }>(
        props.publicToken
          ? `/api/published/${props.publicToken}/plan`
          : `/api/executions/plan/${props.documentId}`,
      )
    ).data;
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось открыть занятие.';
  }
  try {
    if (stored?.sessionId && !props.publicToken) {
      const remote = (await api<{ data: ExecutionSession }>(`/api/executions/${stored.sessionId}`)).data;
      adopt(remote, stored);
      notice.value =
        'Восстановлена сохранённая сессия и её неизменяемый план. Для продолжения нажмите кнопку.';
    } else if (
      props.publicToken &&
      plan.value &&
      stored?.started &&
      !stored.finished &&
      stored.plan?.revision === plan.value.revision &&
      stored.plan.steps.length === plan.value.steps.length
    ) {
      if (stored.progress && stored.progress.step >= 0 && stored.progress.step < plan.value.steps.length) {
        progress.value = stored.progress;
        active.value = Math.max(0, Number(stored.active) || 0);
        started.value = true;
      }
    } else if (stored?.pendingStartId && !stored.sessionId) pendingStartId = stored.pendingStartId;
    preparationSeconds.value = Math.max(0, Math.min(120, stored?.preparationSeconds ?? 0));
    breathSeconds.value = Math.max(0, Math.min(60, stored?.breathSeconds ?? 0));
    teacherTargets.value = stored?.teacherTargets ?? {};
  } catch (failure) {
    error.value =
      failure instanceof Error
        ? failure.message
        : 'Не удалось сверить прежнюю сессию. Прогресс на устройстве сохранён.';
  } finally {
    loading.value = false;
    last = performance.now();
    ticker = setInterval(tick, 500);
    document.addEventListener('visibilitychange', visibility);
    window.addEventListener('keydown', keydown);
    window.addEventListener('offline', offline);
    window.addEventListener('pagehide', leaving);
  }
});
onBeforeUnmount(() => {
  disposed = true;
  if (ticker) clearInterval(ticker);
  document.removeEventListener('visibilitychange', visibility);
  window.removeEventListener('keydown', keydown);
  window.removeEventListener('offline', offline);
  window.removeEventListener('pagehide', leaving);
  if (running.value) pause();
  draft(true);
  void wake?.release();
  void audio?.close();
});
</script>
<template>
  <AppShell section="practices"
    ><Head :title="plan?.title || 'Выполнение практики'" />
    <div class="player-heading">
      <Link :href="publicToken ? `/p/${publicToken}` : `/shared-practices/${documentId}`">← К листу</Link>
      <h1>{{ plan?.title || 'Практика' }}</h1>
      <Link v-if="!publicToken" href="/practice-history">История</Link>
    </div>
    <p v-if="loading">Загружаем план…</p>
    <p v-if="error" class="alert" role="alert">{{ error }}</p>
    <p v-if="notice" class="player-notice" role="status">{{ notice }}</p>
    <template v-if="plan && !loading">
      <div class="player-settings">
        <label
          >Режим
          <select v-model="mode" :disabled="started || starting || preparing">
            <option value="teach">Вести занятие</option>
            <option value="practice">Практиковать</option>
          </select></label
        ><label><input v-model="sound" type="checkbox" /> Сигнал</label
        ><label><input v-model="showNames" type="checkbox" /> Названия</label
        ><label v-if="mode === 'practice'"
          ><input v-model="auto" type="checkbox" /> Переход по параметрам</label
        ><button class="btn" @click="fullscreen">Полный экран</button>
        <label
          >Подготовка перед стартом, секунд<input
            v-model.number="preparationSeconds"
            type="number"
            min="0"
            max="120"
            :disabled="started || starting || preparing"
        /></label>
      </div>
      <div v-if="userId" class="player-settings">
        <Link href="/practices" class="btn">Создать мою практику</Link
        ><Link :href="publicToken ? `/p/${publicToken}` : `/shared-practices/${documentId}`" class="btn"
          >Лист и разрешённое копирование</Link
        ><span class="muted">Копия создаётся в вашем пространстве только при разрешении автора.</span>
      </div>
      <details v-if="!started" class="planned-coverage">
        <summary>Плановые зоны и длительность</summary>
        <p>
          Шагов: {{ plan.steps.length }}.
          {{
            planned.total === null
              ? 'Общая длительность не задана для всех шагов.'
              : `По заданным параметрам: ${clock(planned.total)}.`
          }}
        </p>
        <BodyCoverage
          :zones="planned.zones"
          :total="planned.total"
          :unclassified="planned.unclassified"
          planned
          :total-steps="plan.steps.length"
          :unknown-count="planned.unknownCount"
          :unknown-time-count="planned.unknownTimeCount"
        />
      </details>
      <p v-if="publicToken" class="muted">Прогресс этой публикации хранится только на устройстве.</p>
      <div v-if="current && !finished" class="pose-stage">
        <p>
          Шаг {{ progress.step + 1 }} из {{ plan.steps.length }}
          <span v-if="current.side">· {{ current.side === 'left' ? 'Левая сторона' : 'Правая сторона' }}</span
          ><span v-if="current.repeat > 1"> · Повтор {{ current.repeat }}</span>
        </p>
        <img v-if="current.image_url" :src="current.image_url" :alt="current.name" />
        <div v-else class="text-pose">{{ current.name }}</div>
        <h2 v-if="showNames">{{ current.name }}</h2>
        <p v-if="mode === 'teach' && current.note">{{ current.note }}</p>
        <div v-if="preparing" class="timer" role="timer">{{ clock(Math.ceil(prepLeft)) }}</div>
        <p v-if="preparing">
          {{
            prepPaused ? 'Подготовка на паузе' : 'Устройтесь удобно — подготовка не входит в активное время'
          }}
        </p>
        <div v-else class="timer" aria-live="off">
          {{ remaining === null ? clock(stepSeconds) : clock(remaining) }}
        </div>
        <p v-if="current.advance_mode === 'manual' || !target">Ручной переход</p>
        <label v-if="current.advance_mode === 'breaths'"
          >Секунд на дыхательный цикл
          <input v-model.number="breathSeconds" type="number" min="0" max="60" /> ·
          {{ current.breath_count }} циклов
          <span v-if="running && breathSeconds > 0">{{
            stepSeconds % breathSeconds < breathSeconds / 2 ? 'Вдох' : 'Выдох'
          }}</span></label
        >
        <p v-if="current.advance_mode === 'breaths' && breathSeconds <= 0" class="muted">
          Темп дыхания не выбран. Переход ручной; секунды не угадываются.
        </p>
        <div class="player-controls">
          <button class="btn" :disabled="progress.step === 0" @click="move(-1)">← Назад</button
          ><button
            v-if="!running && (!preparing || prepPaused)"
            class="btn primary"
            :disabled="starting || closing"
            @click="start"
          >
            {{ starting ? 'Запускаем…' : started || preparing ? 'Продолжить' : 'Начать' }}</button
          ><button v-else class="btn primary" @click="pause">Пауза</button
          ><button
            class="btn"
            :disabled="!started || starting || closing || preparing"
            @click="move(1, true)"
          >
            {{ progress.step === plan.steps.length - 1 ? 'Завершить' : 'Далее →' }}
          </button>
          <button v-if="mode === 'teach' && started" class="btn" @click="extend">+30 секунд к таймеру</button>
          <button
            v-if="preparing"
            class="btn"
            @click="
              preparing = false;
              prepPaused = false;
            "
          >
            Отменить подготовку
          </button>
        </div>
        <div v-if="next" class="next-pose">
          <img
            v-if="next.image_url"
            :src="next.image_url"
            :alt="showNames ? next.name : 'Следующая поза'"
          /><span>{{ showNames ? `Далее: ${next.name}` : 'Следующая поза' }}</span>
        </div>
        <p>
          Активное время: {{ clock(active) }} <span class="muted">{{ saving ? '· сохраняется…' : '' }}</span>
        </p>
        <button v-if="started" class="btn" @click="finish(false)">Закончить досрочно</button>
      </div>
      <section v-else-if="finished" class="pose-stage">
        <h2>Сессия сохранена</h2>
        <p>Активное время {{ clock(active) }}. Выполнено {{ progress.completed.length }} шагов.</p>
        <Link v-if="!publicToken" class="btn primary" href="/practice-history">Посмотреть сводку зон</Link>
        <button class="btn" :disabled="starting" @click="restart">Подготовить новый запуск</button>
      </section>
      <p v-else>В этой практике нет заполненных шагов. Добавьте асаны или текст в редакторе.</p>
      <p class="muted">
        При уходе приложения в фон включается пауза. Таймер учитывает работу с приложением, а не измеряет
        движения или дыхание.
      </p>
    </template>
  </AppShell>
</template>
<style scoped>
.player-heading,
.player-settings,
.player-controls {
  display: flex;
  gap: 14px;
  align-items: center;
  flex-wrap: wrap;
}
.player-heading {
  justify-content: space-between;
}
.player-heading h1 {
  font-size: 1.4rem;
}
.player-settings {
  background: white;
  padding: 14px;
  border-radius: 16px;
  margin: 18px 0;
}
.player-settings label {
  display: flex;
  align-items: center;
  gap: 7px;
}
.pose-stage {
  text-align: center;
  background: white;
  border: 1px solid #e2e8dd;
  border-radius: 22px;
  padding: 24px;
  max-width: 820px;
  margin: auto;
}
.pose-stage img {
  display: block;
  width: min(100%, 420px);
  height: 350px;
  object-fit: contain;
  margin: auto;
}
.text-pose {
  font-size: 2rem;
  padding: 80px 10px;
}
.timer {
  font-size: 3rem;
  font-variant-numeric: tabular-nums;
  color: #356927;
}
.player-controls {
  justify-content: center;
  margin: 20px 0;
}
.muted {
  font-size: 0.85rem;
  color: #687160;
}
.alert {
  color: #992c28;
  background: #fff0ee;
  padding: 12px;
  border-radius: 10px;
}
.player-notice {
  background: #eef7e9;
  padding: 12px;
  border-radius: 10px;
  margin: 10px 0;
}
.planned-coverage {
  padding: 14px;
  border: 1px solid #dce5d7;
  background: white;
  border-radius: 12px;
  margin: 15px 0;
}
.planned-coverage summary {
  cursor: pointer;
  font-weight: 600;
  padding: 0.5rem 0;
}
.next-pose {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  margin: 12px 0;
  color: #687160;
}
.pose-stage .next-pose img {
  width: 76px;
  height: 76px;
  object-fit: contain;
  margin: 0;
}
.pose-stage input[type='number'] {
  width: 65px;
}
@media (max-width: 500px) {
  .pose-stage {
    padding: 14px;
  }
  .pose-stage img {
    height: 260px;
  }
  .player-heading h1 {
    width: 100%;
    order: 3;
  }
  .player-controls {
    gap: 7px;
  }
  .player-controls .btn {
    flex: 1;
  }
}
</style>
