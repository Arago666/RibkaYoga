<script setup lang="ts">
import AppSelect from '@/shared/AppSelect.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Activity } from '@lucide/vue';
import AppShell from '@/shared/AppShell.vue';
import { api } from '@/shared/api';
import { uid } from '@/shared/uuid';
import BodyCoverage from '../BodyCoverage.vue';
import MetronomeDialog from '../components/MetronomeSettings.vue';
import {
  defaultMetronome,
  normalizeMetronome,
  metronomeBeatLabels,
  metronomeClock,
  type MetronomeSettings,
} from '../metronome';
import { MetronomeEngine, type MetronomeState } from '../metronomeEngine';
import type { ExecutionPlan, ExecutionProgress, ExecutionSession } from '../types';
const props = defineProps<{ documentId?: string; publicToken?: string }>();
const page = usePage();
const metronome = ref<MetronomeSettings>(defaultMetronome());
const metronomeOpen = ref(false);
const metronomeState = ref<MetronomeState | null>(null);
const metronomeEngine = new MetronomeEngine((state) => (metronomeState.value = state));
const metronomeStarted = ref(false);
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
const progress = ref<ExecutionProgress>({
  step: 0,
  seconds_by_step: [],
  rest_seconds_by_step: [],
  completed: [],
  phase: 'pose',
  phase_elapsed: 0,
});
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
const restTargets = ref<Record<number, number>>({});
const current = computed(() => plan.value?.steps[progress.value.step]);
const next = computed(() => plan.value?.steps[progress.value.step + 1]);
const stepSeconds = computed(() => progress.value.seconds_by_step[progress.value.step] || 0);
const resting = computed(() => progress.value.phase === 'rest');
const phaseSeconds = computed(
  () =>
    progress.value.phase_elapsed ??
    (resting.value ? progress.value.rest_seconds_by_step?.[progress.value.step] || 0 : stepSeconds.value),
);
const restDuration = computed(() => (next.value ? current.value?.rest_seconds || 0 : 0));
const stagePose = computed(() => (resting.value ? next.value : current.value));
const metronomeTrackerValue = computed(() => {
  const tracker = metronome.value.tracker;
  const state = metronomeStarted.value ? metronomeState.value : null;
  if (tracker.mode === 'bars') {
    const bars = state?.bar ?? (tracker.start_from_one && !tracker.countdown ? 1 : 0);
    return `${tracker.countdown ? Math.max(0, tracker.bars - bars) : bars} тактов`;
  }
  return metronomeClock(state ? (state.remaining ?? state.elapsed) : tracker.countdown ? tracker.seconds : 0);
});
const baseTarget = computed(() =>
  current.value?.advance_mode === 'time'
    ? current.value.duration_seconds
    : current.value?.advance_mode === 'breaths' && breathSeconds.value > 0
      ? (current.value.breath_count || 0) * breathSeconds.value
      : null,
);
const target = computed(() =>
  resting.value
    ? (restTargets.value[progress.value.step] ?? restDuration.value)
    : (teacherTargets.value[progress.value.step] ?? baseTarget.value),
);
const remaining = computed(() =>
  target.value !== null ? Math.max(0, Math.ceil(target.value - phaseSeconds.value)) : null,
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
  restTargets?: Record<number, number>;
  metronome?: MetronomeSettings;
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
  let rest = 0;
  const steps = plan.value?.steps ?? [];
  for (const [index, step] of steps.entries()) {
    if (index < steps.length - 1) rest += step.rest_seconds || 0;
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
    total: unknownTimeCount ? null : total + rest,
    rest,
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
        restTargets: restTargets.value,
        metronome: metronome.value,
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
  metronome.value = normalizeMetronome(remote.plan.metronome);
  mode.value = remote.mode;
  pendingStartId = remote.id;
  active.value = remote.active_seconds;
  progress.value = JSON.parse(JSON.stringify(remote.progress));
  progress.value.phase ??= 'pose';
  progress.value.rest_seconds_by_step ??= [];
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
    ) &&
    (remote.progress.rest_seconds_by_step ?? []).every(
      (seconds, index) => Number(candidate.rest_seconds_by_step?.[index] ?? 0) >= seconds,
    );
  if (!ended && dominates && candidate) {
    const count = remote.plan.steps.length;
    const merged = Array.from(
      { length: Math.max(candidate.seconds_by_step.length, remote.progress.seconds_by_step.length) },
      (_, index) =>
        Math.max(Number(candidate.seconds_by_step[index] ?? 0), remote.progress.seconds_by_step[index] ?? 0),
    );
    const mergedRest = Array.from(
      {
        length: Math.max(
          candidate.rest_seconds_by_step?.length ?? 0,
          remote.progress.rest_seconds_by_step?.length ?? 0,
        ),
      },
      (_, index) =>
        Math.max(
          Number(candidate.rest_seconds_by_step?.[index] ?? 0),
          remote.progress.rest_seconds_by_step?.[index] ?? 0,
        ),
    );
    if (
      merged.length <= count &&
      mergedRest.length <= count &&
      [...merged, ...mergedRest].every((value) => Number.isFinite(value) && value >= 0)
    ) {
      progress.value = {
        step: Math.max(0, Math.min(count - 1, Number(candidate.step) || 0)),
        seconds_by_step: merged,
        rest_seconds_by_step: mergedRest,
        phase: candidate.phase === 'rest' ? 'rest' : 'pose',
        phase_elapsed: Math.max(
          0,
          Math.min(
            86400,
            Number(
              candidate.phase_elapsed ??
                (candidate.phase === 'rest' ? mergedRest[candidate.step] : merged[candidate.step]),
            ) || 0,
          ),
        ),
        completed: [...new Set([...remote.progress.completed, ...candidate.completed])].filter(
          (index) => Number.isInteger(index) && index >= 0 && index < count,
        ),
      };
      active.value = Math.max(
        localTime,
        [...merged, ...mergedRest].reduce((sum, value) => sum + value, 0),
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
                rest_seconds_by_step: Array.from(
                  { length: progress.value.rest_seconds_by_step?.length ?? 0 },
                  (_, index) => Math.floor(progress.value.rest_seconds_by_step?.[index] || 0),
                ),
                phase: resting.value ? 'rest' : 'pose',
                phase_elapsed: Math.floor(phaseSeconds.value),
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
            metronome: metronome.value,
          }),
        })
      ).data;
      adopt(remote);
      if (finished.value) return;
    }
    if (disposed) return;
    if (document.hidden || !navigator.onLine) {
      notice.value = 'Практика готова. Вернитесь на страницу и нажмите «Продолжить».';
      await persist('paused');
      return;
    }
    if (metronome.value.enabled) {
      try {
        await metronomeEngine.start(metronome.value, metronomeStarted.value);
        metronomeStarted.value = true;
      } catch {
        notice.value = 'Метроном недоступен. Таймер практики продолжит работать.';
      }
    }
    if (disposed || document.hidden || !navigator.onLine) {
      metronomeEngine.stop();
      if (!disposed) await persist('paused');
      return;
    }
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
  await Promise.all([
    unlockSound(),
    metronome.value.enabled
      ? metronomeEngine.unlock().catch(() => {
          notice.value = 'Не удалось включить звук метронома. Практика остаётся доступной.';
        })
      : Promise.resolve(),
  ]);
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
  progress.value.phase_elapsed = phaseSeconds.value + delta;
  if (resting.value) {
    progress.value.rest_seconds_by_step ??= [];
    progress.value.rest_seconds_by_step[progress.value.step] =
      (progress.value.rest_seconds_by_step[progress.value.step] || 0) + delta;
  } else {
    progress.value.seconds_by_step[progress.value.step] =
      (progress.value.seconds_by_step[progress.value.step] || 0) + delta;
  }
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
  metronomeEngine.stop();
  void wake?.release();
  wake = null;
  void audio?.suspend();
  void persist('paused');
}
function extend() {
  if (mode.value !== 'teach' || !started.value) return;
  const targets = resting.value ? restTargets : teacherTargets;
  targets.value[progress.value.step] = Math.max(target.value ?? 0, phaseSeconds.value) + 30;
  draft(true);
}
function adjustTime(seconds: number) {
  if (!started.value || preparing.value || finished.value) return;
  const targets = resting.value ? restTargets : teacherTargets;
  targets.value[progress.value.step] = Math.min(
    86400,
    Math.max(resting.value ? 0 : 1, (target.value ?? 60) + seconds),
  );
  draft(true);
}
function move(delta: number, complete = false) {
  if (!plan.value || !started.value || starting.value || preparing.value || finished.value) return;
  accrue();
  if (delta < 0 && resting.value) {
    progress.value.phase = 'pose';
    progress.value.phase_elapsed = 0;
    bell();
    draft(true);
    void persist(running.value ? 'running' : 'paused');
    return;
  }
  if (complete && !resting.value) {
    if (!progress.value.completed.includes(progress.value.step))
      progress.value.completed.push(progress.value.step);
    if (restDuration.value > 0) {
      progress.value.phase = 'rest';
      progress.value.phase_elapsed = 0;
      last = performance.now();
      bell();
      draft(true);
      void persist(running.value ? 'running' : 'paused');
      return;
    }
  }
  const index = progress.value.step + delta;
  if (index >= plan.value.steps.length) {
    void finish(true);
    return;
  }
  progress.value.step = Math.max(0, index);
  progress.value.phase = 'pose';
  progress.value.phase_elapsed = 0;
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
  metronomeEngine.stop();
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
    target.value !== null &&
    phaseSeconds.value >= target.value
  )
    move(1, !resting.value);
  if (++syncCounter >= 100) {
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
  progress.value = {
    step: 0,
    seconds_by_step: [],
    rest_seconds_by_step: [],
    completed: [],
    phase: 'pose',
    phase_elapsed: 0,
  };
  teacherTargets.value = {};
  restTargets.value = {};
  metronomeEngine.stop(true);
  metronomeStarted.value = false;
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
    restTargets.value = stored?.restTargets ?? {};
    if (!session.value) metronome.value = normalizeMetronome(stored?.metronome ?? plan.value?.metronome);
  } catch (failure) {
    error.value =
      failure instanceof Error
        ? failure.message
        : 'Не удалось сверить прежнюю сессию. Прогресс на устройстве сохранён.';
  } finally {
    loading.value = false;
    last = performance.now();
    ticker = setInterval(tick, 100);
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
  metronomeEngine.dispose();
});
watch(running, (value) => {
  if (!value) metronomeEngine.stop();
});
watch(metronome, () => draft(true), { deep: true });
</script>
<template>
  <AppShell section="practices"
    ><Head :title="plan?.title || 'Выполнение практики'" />
    <header class="page-header player-heading">
      <div>
        <h1 class="page-title">{{ plan?.title || 'Практика' }}</h1>
      </div>
      <div class="player-header-actions">
        <Link
          class="btn btn-secondary"
          :href="publicToken ? `/p/${publicToken}` : `/shared-practices/${documentId}`"
          >← К листу</Link
        >
        <Link class="btn btn-secondary" v-if="!publicToken" href="/practice-history">История</Link>
      </div>
    </header>
    <p v-if="loading" class="muted" role="status">Загружаем план…</p>
    <p v-if="error" class="alert" role="alert">{{ error }}</p>
    <p v-if="notice" class="player-notice" role="status">{{ notice }}</p>
    <template v-if="plan && !loading">
      <div class="player-settings">
        <label class="player-field"
          >Режим
          <AppSelect class="select" v-model="mode" :disabled="started || starting || preparing">
            <option value="teach">Вести занятие</option>
            <option value="practice">Практиковать</option>
          </AppSelect></label
        ><label class="check-label"><input v-model="sound" type="checkbox" /> Сигнал</label
        ><label class="check-label"><input v-model="showNames" type="checkbox" /> Названия</label
        ><label class="check-label" v-if="mode === 'practice'"
          ><input v-model="auto" type="checkbox" /> Переход по параметрам</label
        ><button class="btn btn-secondary" @click="fullscreen">Полный экран</button>
        <label class="player-field preparation-field"
          >Подготовка перед стартом, секунд<input
            class="input"
            v-model.number="preparationSeconds"
            type="number"
            min="0"
            max="120"
            :disabled="started || starting || preparing"
        /></label>
      </div>
      <div v-if="userId" class="player-settings">
        <Link href="/practices" class="btn btn-secondary">Создать мою практику</Link
        ><Link
          :href="publicToken ? `/p/${publicToken}` : `/shared-practices/${documentId}`"
          class="btn btn-secondary"
          >Лист и разрешённое копирование</Link
        ><span class="muted">Копия создаётся в вашем пространстве только при разрешении автора.</span>
      </div>
      <details v-if="!started" class="planned-coverage disclosure">
        <summary>
          <span class="disclosure-icon"><Activity aria-hidden="true" /></span>
          <span class="disclosure-copy"><strong>Плановые зоны и длительность</strong></span>
        </summary>
        <div class="coverage-body disclosure-body">
          <p>
            Шагов: {{ plan.steps.length }}.
            {{
              planned.total === null
                ? 'Общая длительность не задана для всех шагов.'
                : `По заданным параметрам: ${clock(planned.total)}.`
            }}
          </p>
          <p v-if="planned.rest">В том числе отдых между асанами: {{ clock(planned.rest) }}.</p>
          <BodyCoverage
            :zones="planned.zones"
            :total="planned.total"
            :unclassified="planned.unclassified"
            planned
            :total-steps="plan.steps.length"
            :unknown-count="planned.unknownCount"
            :unknown-time-count="planned.unknownTimeCount"
          />
        </div>
      </details>
      <p v-if="publicToken" class="muted">Прогресс этой публикации хранится только на устройстве.</p>
      <div v-if="current && !finished" class="pose-stage">
        <p>
          Шаг {{ progress.step + 1 }} из {{ plan.steps.length }}
          <span v-if="resting"> · Отдых</span>
          <span v-if="current.side">· {{ current.side === 'left' ? 'Левая сторона' : 'Правая сторона' }}</span
          ><span v-if="current.repeat > 1"> · Повтор {{ current.repeat }}</span>
        </p>
        <img v-if="stagePose?.image_url" :src="stagePose.image_url" :alt="stagePose.name" />
        <div v-else class="text-pose">{{ stagePose?.name }}</div>
        <h2 v-if="resting">Отдых</h2>
        <h2 v-else-if="showNames">{{ current.name }}</h2>
        <p v-if="resting && showNames">Далее — {{ next?.name }}</p>
        <p v-if="!resting && mode === 'teach' && current.note">{{ current.note }}</p>
        <div v-if="preparing" class="timer" role="timer">{{ clock(Math.ceil(prepLeft)) }}</div>
        <p v-if="preparing">
          {{
            prepPaused ? 'Подготовка на паузе' : 'Устройтесь удобно — подготовка не входит в активное время'
          }}
        </p>
        <div v-else class="timer" aria-live="off">
          {{ remaining === null ? clock(phaseSeconds) : clock(remaining) }}
        </div>
        <p v-if="!preparing && target !== null" class="phase-duration">
          {{ resting ? 'Отдых' : 'Асана' }} · {{ clock(target) }}
        </p>
        <p v-if="!resting && (current.advance_mode === 'manual' || target === null)">Ручной переход</p>
        <label class="breath-setting" v-if="!resting && current.advance_mode === 'breaths'"
          >Секунд на дыхательный цикл
          <input class="input" v-model.number="breathSeconds" type="number" min="0" max="60" /> ·
          {{ current.breath_count }} циклов
          <span v-if="running && breathSeconds > 0">{{
            stepSeconds % breathSeconds < breathSeconds / 2 ? 'Вдох' : 'Выдох'
          }}</span></label
        >
        <p v-if="!resting && current.advance_mode === 'breaths' && breathSeconds <= 0" class="muted">
          Темп дыхания не выбран. Переход ручной; секунды не угадываются.
        </p>
        <div v-if="metronome.enabled" class="practice-metronome" aria-label="Метроном практики">
          <div class="metronome-dots" aria-hidden="true">
            <span
              v-for="(beat, index) in metronome.pattern"
              :key="index"
              :class="[
                'metronome-dot',
                beat,
                { current: running && metronomeState?.running && metronomeState.beat === index },
              ]"
              :title="metronomeBeatLabels[beat]"
              >{{ beat === 'strong' ? '›' : beat === 'accent' ? '·' : '' }}</span
            >
          </div>
          <p>
            <strong>{{ metronomeStarted && metronomeState ? metronomeState.bpm : metronome.bpm }} BPM</strong>
            · {{ metronome.beats }}/{{ metronome.beat_unit }}
            <span v-if="metronomeState?.countingIn"> · Предварительный отсчёт</span>
          </p>
          <p v-if="metronome.tracker.mode !== 'off'">Метроном · {{ metronomeTrackerValue }}</p>
          <p v-if="metronomeState?.reason === 'limit'" class="muted">
            Метроном завершил отсчёт. Практика продолжается.
          </p>
        </div>
        <div class="player-controls">
          <button
            class="btn btn-secondary"
            :disabled="!started || (progress.step === 0 && !resting)"
            @click="move(-1)"
          >
            ← Назад
          </button>
          <button
            v-if="started && !preparing"
            class="btn btn-secondary adjust-time"
            @click="adjustTime(-15)"
            aria-label="Уменьшить текущее время на 15 секунд"
          >
            −15 с
          </button>
          <button
            v-if="!running && (!preparing || prepPaused)"
            class="btn btn-primary"
            :disabled="starting || closing"
            @click="start"
          >
            {{ starting ? 'Запускаем…' : started || preparing ? 'Продолжить' : 'Начать' }}</button
          ><button v-else class="btn btn-primary" @click="pause">Пауза</button>
          <button
            v-if="started && !preparing"
            class="btn btn-secondary adjust-time"
            @click="adjustTime(15)"
            aria-label="Добавить 15 секунд к текущему времени"
          >
            +15 с
          </button>
          <button
            class="btn btn-secondary"
            :disabled="!started || starting || closing || preparing"
            @click="move(1, true)"
          >
            {{
              resting
                ? 'Пропустить отдых →'
                : progress.step === plan.steps.length - 1
                  ? 'Завершить'
                  : 'Далее →'
            }}
          </button>
          <button v-if="mode === 'teach' && started" class="btn btn-secondary" @click="extend">
            +30 секунд к таймеру
          </button>
          <button
            v-if="preparing"
            class="btn btn-secondary"
            @click="
              preparing = false;
              prepPaused = false;
            "
          >
            Отменить подготовку
          </button>
        </div>
        <button
          v-if="!started && !preparing"
          class="btn btn-secondary metronome-trigger"
          :disabled="starting"
          @click="metronomeOpen = true"
        >
          {{ metronome.enabled ? 'Настроить метроном' : '+ Добавить метроном' }}
        </button>
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
        <button v-if="started" class="btn btn-ghost" @click="finish(false)">Закончить досрочно</button>
      </div>
      <section v-else-if="finished" class="pose-stage">
        <h2>Сессия сохранена</h2>
        <p>Активное время {{ clock(active) }}. Выполнено {{ progress.completed.length }} шагов.</p>
        <Link v-if="!publicToken" class="btn btn-primary" href="/practice-history"
          >Посмотреть сводку зон</Link
        >
        <button class="btn btn-secondary" :disabled="starting" @click="restart">
          Подготовить новый запуск
        </button>
      </section>
      <p v-else>В этой практике нет заполненных шагов. Добавьте асаны или текст в редакторе.</p>
      <p class="muted">
        При уходе приложения в фон включается пауза. Таймер учитывает работу с приложением, а не измеряет
        движения или дыхание.
      </p>
    </template>
    <MetronomeDialog
      :open="metronomeOpen"
      v-model="metronome"
      :authenticated="Boolean(userId)"
      @close="metronomeOpen = false"
    />
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
.phase-duration {
  display: inline-block;
  border-radius: 999px;
  padding: 5px 14px;
  background: var(--surface-soft);
  color: var(--muted);
  font-size: 13px;
}
.practice-metronome {
  margin: 20px auto;
  max-width: 520px;
  padding: 18px;
  border: 1px solid var(--line);
  border-radius: 20px;
  background: var(--surface-soft);
  font-size: 14px;
  line-height: 1.7;
}
.metronome-dots {
  display: flex;
  justify-content: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
}
.metronome-dot {
  width: 26px;
  height: 26px;
  display: grid;
  place-items: center;
  flex-shrink: 0;
  border-radius: 50%;
  background: #eaf1e1;
  border: 1px solid #dbe5d2;
  font-size: 25px;
  line-height: 1;
  color: var(--accent-dark);
}
.metronome-dot.mute {
  background: transparent;
  border-style: dashed;
}
.metronome-dot.accent,
.metronome-dot.strong {
  border: 2px solid var(--accent-dark);
}
.metronome-dot.current {
  background: var(--accent);
  color: #fff;
  box-shadow: 0 0 0 4px rgb(42 196 90 / 14%);
}
.metronome-trigger {
  margin: -4px 0 16px;
}
.player-heading {
  justify-content: space-between;
}
.player-heading h1 {
  flex: 1;
  min-width: 0;
  overflow-wrap: anywhere;
  color: #203d2c;
  font-weight: 550;
}
.player-settings {
  background: white;
  padding: var(--panel-padding);
  border: 1px solid var(--line);
  border-radius: var(--panel-radius);
  margin: var(--section-gap) 0;
  box-shadow: var(--panel-shadow);
}
.player-settings label {
  display: flex;
  align-items: center;
  gap: 7px;
  min-height: 44px;
  min-width: 0;
  font-size: 14px;
}
.player-settings .player-field {
  display: grid;
  flex: 0 1 220px;
}
.player-settings .preparation-field {
  flex-basis: 270px;
}
.player-settings input[type='checkbox'] {
  width: 18px;
  height: 18px;
  accent-color: var(--accent-dark);
}
.pose-stage {
  text-align: center;
  background: white;
  border: 1px solid var(--line);
  border-radius: var(--panel-radius);
  padding: var(--panel-padding);
  max-width: none;
  margin: auto;
  min-width: 0;
  overflow-wrap: anywhere;
  box-shadow: var(--panel-shadow);
  width: 100%;
}
.pose-stage > p {
  margin: 12px 0;
  line-height: 1.6;
}
.pose-stage h2 {
  font-size: 19px;
  margin: 16px 0;
  font-weight: 550;
  color: #29492f;
  letter-spacing: -0.025em;
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
  font-size: clamp(54px, 6.4vw, 78px);
  font-weight: 400;
  letter-spacing: -0.055em;
  font-variant-numeric: tabular-nums;
  color: var(--text);
}
.player-controls {
  justify-content: center;
  margin: 20px 0;
}
.muted {
  font-size: 0.85rem;
  color: var(--muted);
}
.alert {
  color: #992c28;
  background: #fff0ee;
  padding: 12px;
  border-radius: var(--field-radius);
}
.player-notice {
  background: var(--accent-soft);
  padding: 12px;
  border-radius: var(--field-radius);
  margin: 10px 0;
}
.planned-coverage {
  margin: var(--section-gap) 0;
}
.coverage-body {
  display: grid;
  gap: 16px;
}
.coverage-body > p {
  line-height: 1.6;
}
.next-pose {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  margin: 12px 0;
  color: var(--muted);
}
.pose-stage .next-pose img {
  width: 76px;
  height: 76px;
  object-fit: contain;
  margin: 0;
}
.pose-stage input[type='number'] {
  width: 80px;
}
.breath-setting {
  display: flex;
  justify-content: center;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin: 16px 0;
}
@media (max-width: 500px) {
  .pose-stage {
    padding: var(--panel-padding);
  }
  .pose-stage img {
    height: 260px;
  }
  .player-heading > div:first-child {
    flex: 1 1 100%;
  }
  .player-header-actions {
    width: 100%;
  }
  .player-settings {
    padding: var(--panel-padding);
    gap: 10px 16px;
  }
  .player-settings .player-field,
  .player-settings > .btn {
    flex: 1 1 100%;
  }
  .player-settings .btn,
  .player-controls .btn {
    white-space: normal;
  }
  .player-controls {
    gap: 7px;
  }
  .player-controls .btn {
    flex: 1;
  }
}
.player-header-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
.player-heading > div:first-child {
  flex: 1;
  min-width: 0;
}
</style>
