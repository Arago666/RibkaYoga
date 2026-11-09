<script setup lang="ts">
import AppSelect from '@/shared/AppSelect.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
  Activity,
  SlidersHorizontal,
  X,
  Maximize2,
  Play,
  Pause,
  SkipBack,
  SkipForward,
  Music2,
} from '@lucide/vue';
import AppShell from '@/shared/AppShell.vue';
import PlayerWindow from '@/shared/PlayerWindow.vue';
import CountdownRing from '@/shared/CountdownRing.vue';
import Modal from '@/shared/Modal.vue';
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
const settingsOpen = ref(false);
const immersive = ref(false);
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
      : current.value?.advance_mode === 'breaths'
        ? null
        : (current.value?.duration_seconds ?? 60),
);
const target = computed(() =>
  resting.value
    ? (restTargets.value[progress.value.step] ?? restDuration.value)
    : (teacherTargets.value[progress.value.step] ?? baseTarget.value),
);
const remaining = computed(() =>
  target.value !== null ? Math.max(0, Math.ceil(target.value - phaseSeconds.value)) : null,
);
const timerLabel = computed(() =>
  preparing.value
    ? prepPaused.value
      ? 'Подготовка · пауза'
      : 'Подготовка'
    : !running.value && started.value
      ? 'На паузе'
      : resting.value
        ? 'Отдых · осталось'
        : remaining.value === null
          ? 'Прошло'
          : 'Осталось',
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
let playbackRequested = false;
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
          : step.advance_mode === 'breaths'
            ? null
            : (step.duration_seconds ?? 60);
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
  progress.value.metronome = normalizeMetronome(metronome.value);
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
  const liveSettings = remote.progress.metronome;
  const recoveredSettings =
    local?.sessionRevision === remote.revision ? local?.progress?.metronome : undefined;
  metronome.value = normalizeMetronome(recoveredSettings ?? liveSettings ?? remote.plan.metronome);
  // Old runs could restore a hidden tempo ramp. Require explicit opt-in in this player.
  if (!liveSettings && !recoveredSettings && local) metronome.value.automator.enabled = false;
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
        metronome: recoveredSettings ?? liveSettings,
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
                metronome: normalizeMetronome(metronome.value),
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
  if (!plan.value?.steps.length || starting.value || running.value || closing || !playbackRequested) return;
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
    if (document.hidden || !navigator.onLine || !playbackRequested) {
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
    if (disposed || document.hidden || !navigator.onLine || !playbackRequested) {
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
  playbackRequested = true;
  notice.value = '';
  immersive.value = true;
  await Promise.all([
    unlockSound(),
    metronome.value.enabled
      ? metronomeEngine.unlock().catch(() => {
          notice.value = 'Не удалось включить звук метронома. Практика остаётся доступной.';
        })
      : Promise.resolve(),
  ]);
  if (!playbackRequested || disposed) return;
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
  playbackRequested = false;
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
  playbackRequested = false;
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
    metronomeOpen.value ||
    settingsOpen.value ||
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
function fullscreen() {
  immersive.value = true;
}
function closeWindow() {
  playbackRequested = false;
  if (running.value || preparing.value) pause();
  immersive.value = false;
}
function openSettings() {
  playbackRequested = false;
  if (running.value || preparing.value) pause();
  settingsOpen.value = true;
}
function openMetronome() {
  playbackRequested = false;
  if (running.value || preparing.value) pause();
  metronomeOpen.value = true;
}
function applyMetronome(settings: MetronomeSettings) {
  metronome.value = normalizeMetronome(settings);
  progress.value.metronome = normalizeMetronome(settings);
  metronomeEngine.update(metronome.value);
  draft(true);
  if (started.value && !finished.value) {
    notice.value = 'Ритм обновлён. Нажмите «Продолжить» — текущая асана и её время сохранены.';
    void persist('paused');
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
    if (!session.value) {
      metronome.value = normalizeMetronome(stored?.metronome ?? plan.value?.metronome);
      if (!started.value) metronome.value.automator.enabled = false;
    }
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
  <AppShell section="practices">
    <Head :title="plan?.title || 'Выполнение практики'" />
    <header class="page-header player-heading">
      <div>
        <h1 class="page-title">{{ plan?.title || 'Практика' }}</h1>
      </div>
      <div class="player-header-actions">
        <Link class="btn" :href="publicToken ? `/p/${publicToken}` : `/shared-practices/${documentId}`"
          >← К листу</Link
        >
        <Link v-if="!publicToken" class="btn" href="/practice-history">История</Link>
      </div>
    </header>
    <p v-if="loading" class="muted" role="status">Загружаем план…</p>
    <p v-if="error && !immersive" class="alert" role="alert">{{ error }}</p>
    <template v-if="plan && !loading">
      <PlayerWindow :open="immersive" :title="plan.title" @close="closeWindow">
        <section
          v-if="current && !finished"
          class="pose-stage"
          :class="{ immersive }"
          aria-label="Плеер практики"
        >
          <header class="stage-header">
            <div class="stage-heading">
              <p>{{ plan.title }}</p>
              <span
                >Асана {{ progress.step + 1 }} из {{ plan.steps.length
                }}<template v-if="resting"> · Отдых</template
                ><template v-if="current.side">
                  · {{ current.side === 'left' ? 'Левая сторона' : 'Правая сторона' }}</template
                ><template v-if="current.repeat > 1"> · Повтор {{ current.repeat }}</template></span
              >
            </div>
            <button class="icon-btn" aria-label="Настройки плеера" @click="openSettings">
              <SlidersHorizontal :size="22" />
            </button>
            <button
              v-if="immersive"
              class="icon-btn"
              aria-label="Свернуть плеер и поставить на паузу"
              @click="closeWindow"
            >
              <X :size="23" />
            </button>
            <button v-else class="icon-btn" aria-label="На весь экран" @click="fullscreen">
              <Maximize2 :size="21" />
            </button>
          </header>
          <p v-if="error" class="alert" role="alert">{{ error }}</p>
          <p v-if="notice" class="player-notice" role="status">{{ notice }}</p>
          <div class="stage-figure">
            <img v-if="stagePose?.image_url" :src="stagePose.image_url" :alt="stagePose.name" />
            <div v-else class="text-pose">{{ stagePose?.name }}</div>
          </div>
          <div class="stage-name">
            <h2 v-if="resting">Отдых</h2>
            <h2 v-else-if="showNames">{{ current.name }}</h2>
            <p v-if="!resting && mode === 'teach' && current.note" class="stage-note">{{ current.note }}</p>
          </div>
          <div class="stage-timing">
            <CountdownRing
              :seconds="preparing ? prepLeft : (remaining ?? phaseSeconds)"
              :total="preparing ? preparationSeconds : target"
              :label="timerLabel"
              :paused="(!running && !preparing) || prepPaused"
            />
            <div class="time-adjustments" v-if="started && !preparing">
              <button class="btn" @click="adjustTime(-15)" aria-label="Уменьшить текущее время на 15 секунд">
                −15 с
              </button>
              <span
                >{{ resting ? 'Отдых' : 'Асана' }} ·
                {{ target === null ? 'по дыханию' : clock(target) }}</span
              >
              <button class="btn" @click="adjustTime(15)" aria-label="Добавить 15 секунд к текущему времени">
                +15 с
              </button>
            </div>
            <p v-if="!preparing" class="advance-label">
              {{
                mode === 'practice' && auto && target !== null
                  ? 'Следующая асана — автоматически'
                  : 'Ручной переход'
              }}
            </p>
            <label v-if="!resting && current.advance_mode === 'breaths'" class="breath-setting"
              >Секунд на дыхательный цикл<input
                class="input"
                v-model.number="breathSeconds"
                type="number"
                min="0"
                max="60"
              />
              · {{ current.breath_count }} циклов</label
            >
            <p v-if="!resting && current.advance_mode === 'breaths' && breathSeconds <= 0" class="muted">
              Укажите темп дыхания для автоматического перехода.
            </p>
          </div>
          <button
            class="practice-metronome"
            type="button"
            aria-label="Настроить метроном во время практики"
            aria-haspopup="dialog"
            :disabled="starting"
            @click="openMetronome"
          >
            <div v-if="metronome.enabled" class="metronome-dots" aria-hidden="true">
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
            <div class="metronome-summary">
              <Music2 :size="17" /><strong>{{
                metronome.enabled
                  ? `${metronomeStarted && metronomeState ? metronomeState.bpm : metronome.bpm} BPM · ${metronome.beats}/${metronome.beat_unit}`
                  : 'Добавить метроном'
              }}</strong
              ><SlidersHorizontal :size="16" />
            </div>
            <small v-if="metronome.enabled"
              >{{
                metronome.automator.enabled
                  ? `Автоизменение темпа до ${metronome.automator.target_bpm} BPM`
                  : 'Постоянный темп'
              }}
              · изменить</small
            >
            <small v-if="metronome.enabled && metronome.tracker.mode !== 'off'"
              >Метроном · {{ metronomeTrackerValue }}</small
            >
            <small v-if="metronomeState?.reason === 'limit'"
              >Ритм завершён, таймер практики продолжается</small
            >
          </button>
          <div class="next-pose" v-if="next">
            <span>ДАЛЕЕ</span
            ><img
              v-if="next.image_url"
              :src="next.image_url"
              :alt="showNames ? next.name : 'Следующая поза'"
            />
            <p>{{ showNames ? next.name : 'Следующая поза' }}</p>
          </div>
          <div v-else class="next-pose"><p>Последняя асана</p></div>
          <div class="stage-navigation">
            <div class="player-controls">
              <button
                class="transport"
                aria-label="Предыдущая асана"
                :disabled="!started || starting || preparing || (progress.step === 0 && !resting)"
                @click="move(-1)"
              >
                <SkipBack :size="28" fill="currentColor" />
              </button>
              <button
                class="transport transport-main"
                :disabled="starting || closing"
                :aria-label="
                  running || (preparing && !prepPaused)
                    ? 'Пауза'
                    : started || preparing
                      ? 'Продолжить'
                      : 'Начать'
                "
                @click="running || (preparing && !prepPaused) ? pause() : start()"
              >
                <Pause v-if="running || (preparing && !prepPaused)" :size="30" fill="currentColor" /><Play
                  v-else
                  :size="30"
                  fill="currentColor"
                />
              </button>
              <button
                class="transport"
                :aria-label="
                  resting
                    ? 'Пропустить отдых'
                    : progress.step === plan.steps.length - 1
                      ? 'Завершить практику'
                      : 'Следующая асана'
                "
                :disabled="!started || starting || closing || preparing"
                @click="move(1, true)"
              >
                <SkipForward :size="28" fill="currentColor" />
              </button>
            </div>
            <p class="transport-status" role="status">
              {{
                starting
                  ? 'Запускаем…'
                  : preparing
                    ? prepPaused
                      ? 'Подготовка на паузе'
                      : 'Устройтесь удобно'
                    : running
                      ? 'Практика идёт'
                      : started
                        ? 'Нажмите, чтобы продолжить'
                        : 'Начать практику'
              }}
            </p>
            <footer class="stage-footer">
              <span>Активное время: {{ clock(active) }}{{ saving ? ' · сохраняется…' : '' }}</span
              ><button v-if="started" class="btn btn-ghost" @click="finish(false)">Закончить досрочно</button
              ><button
                v-if="preparing"
                class="btn"
                @click="
                  preparing = false;
                  prepPaused = false;
                "
              >
                Отменить подготовку
              </button>
            </footer>
          </div>
        </section>
        <section v-else-if="finished" class="pose-stage completed-stage" :class="{ immersive }">
          <button
            v-if="immersive"
            class="icon-btn completed-close"
            aria-label="Свернуть плеер"
            @click="closeWindow"
          >
            <X />
          </button>
          <h2>{{ publicToken ? 'Практика завершена' : 'Сессия сохранена' }}</h2>
          <p>Активное время {{ clock(active) }}. Выполнено {{ progress.completed.length }} асан.</p>
          <Link v-if="!publicToken" class="btn btn-primary" href="/practice-history"
            >Посмотреть сводку зон</Link
          ><button class="btn" :disabled="starting" @click="restart">Подготовить новый запуск</button>
        </section>
        <p v-else>В этой практике нет заполненных шагов. Добавьте асаны или текст в редакторе.</p>
      </PlayerWindow>
      <details v-if="!started" class="planned-coverage disclosure">
        <summary>
          <span class="disclosure-icon"><Activity aria-hidden="true" /></span
          ><span class="disclosure-copy"><strong>Плановые зоны и длительность</strong></span>
        </summary>
        <div class="coverage-body disclosure-body">
          <p>
            Шагов: {{ plan.steps.length }}.
            {{
              planned.total === null
                ? 'Для шагов по дыханию укажите темп дыхания.'
                : `По заданным параметрам: ${clock(planned.total)}.`
            }}
          </p>
          <p>Асаны без заданного времени — по 60 секунд. Время можно менять в плеере.</p>
          <p v-if="planned.rest">Отдых между асанами: {{ clock(planned.rest) }}.</p>
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
      <p class="muted player-background-hint">
        При сворачивании страницы таймер и звук ставятся на паузу. Вернувшись, продолжите явно.
      </p>
      <p v-if="publicToken" class="muted">Прогресс этой публикации хранится только на устройстве.</p>
      <div v-if="userId" class="player-header-actions player-context-actions">
        <Link href="/practices" class="btn">Мои практики</Link>
        <Link :href="publicToken ? `/p/${publicToken}` : `/shared-practices/${documentId}`" class="btn"
          >Лист и разрешённое копирование</Link
        >
      </div>
    </template>
    <Modal :open="settingsOpen" title="Настройки плеера" @close="settingsOpen = false">
      <div class="player-options">
        <label class="field"
          ><span class="label">Режим</span
          ><AppSelect v-model="mode" :disabled="started || starting || preparing"
            ><option value="teach">Вести занятие</option>
            <option value="practice">Практиковать</option></AppSelect
          ></label
        >
        <label class="check-label"
          ><input v-model="sound" type="checkbox" /> Звуковой сигнал при смене асаны</label
        >
        <label class="check-label"><input v-model="showNames" type="checkbox" /> Показывать названия</label>
        <label v-if="mode === 'practice'" class="check-label"
          ><input v-model="auto" type="checkbox" /> Автоматически переключать асаны по таймеру</label
        >
        <label class="field"
          ><span class="label">Подготовка перед стартом, секунд</span
          ><input
            class="input"
            v-model.number="preparationSeconds"
            type="number"
            min="0"
            max="120"
            :disabled="started || starting || preparing"
        /></label>
        <button
          class="btn"
          @click="
            settingsOpen = false;
            openMetronome();
          "
        >
          <Music2 :size="18" /> Настроить метроном
        </button>
        <p class="muted">
          Изменения относятся к текущему запуску. Длительность асан можно менять кнопками −15 / +15 секунд.
        </p>
      </div>
      <template #footer
        ><button class="btn btn-primary" @click="settingsOpen = false">Готово</button></template
      >
    </Modal>
    <MetronomeDialog
      :open="metronomeOpen"
      :model-value="metronome"
      :authenticated="Boolean(userId)"
      @update:model-value="applyMetronome"
      @close="metronomeOpen = false"
    />
  </AppShell>
</template>
<style scoped>
.player-heading,
.player-header-actions {
  display: flex;
  gap: 12px;
  align-items: center;
  flex-wrap: wrap;
}
.player-heading {
  justify-content: space-between;
}
.player-heading > div:first-child {
  flex: 1;
  min-width: 0;
}
.pose-stage {
  width: 100%;
  max-width: 760px;
  margin: auto;
  background: white;
  border: 1px solid var(--line);
  border-radius: 28px;
  padding: 24px;
  text-align: center;
  box-shadow: var(--panel-shadow);
  min-width: 0;
  overflow-wrap: anywhere;
}
.pose-stage.immersive {
  min-height: 100%;
  max-width: 940px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: transparent;
  border: 0;
  box-shadow: none;
  border-radius: 0;
  padding: 0;
}
.stage-header {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  margin-bottom: 8px;
  text-align: left;
}
.stage-heading {
  flex: 1;
  min-width: 0;
}
.stage-heading p {
  font-size: clamp(16px, 2vw, 22px);
  font-weight: 600;
  line-height: 1.3;
}
.stage-heading span {
  display: block;
  color: var(--muted);
  font-size: 12px;
  margin-top: 4px;
}
.stage-header .icon-btn {
  flex-shrink: 0;
  background: rgb(255 255 255 / 75%);
  color: var(--accent-dark);
  border: 1px solid var(--line);
  border-radius: 50%;
  width: 44px;
  height: 44px;
}
.stage-figure {
  width: 100%;
  display: grid;
  place-items: center;
  min-height: 0;
}
.stage-figure img {
  display: block;
  width: min(100%, 400px);
  height: 260px;
  object-fit: contain;
  mix-blend-mode: multiply;
}
.immersive .stage-figure img {
  height: clamp(140px, 29dvh, 380px);
  width: min(100%, 500px);
}
.text-pose {
  padding: 32px 12px;
  font-size: clamp(24px, 4vw, 38px);
}
.stage-name {
  width: 100%;
  margin: 8px 0 12px;
}
.stage-name h2 {
  font-size: clamp(20px, 3vw, 30px);
  font-weight: 550;
  line-height: 1.2;
  letter-spacing: -0.03em;
  color: #29492f;
}
.stage-note {
  max-width: 600px;
  font-size: 14px;
  margin: 8px auto;
  padding: 12px;
  border-radius: 16px;
  background: white;
  line-height: 1.5;
}
.stage-timing {
  width: 100%;
}
.time-adjustments {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14px;
  margin-top: 5px;
}
.time-adjustments > span {
  font-size: 12px;
  color: var(--muted);
}
.time-adjustments .btn {
  font-size: 12px;
  min-height: 36px;
  padding: 6px 13px;
}
.advance-label {
  font-size: 11px;
  color: var(--muted);
  margin: 7px 0;
}
.practice-metronome {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 7px;
  margin: 10px auto;
  width: min(100%, 360px);
  border: 1px solid #dbe5d2;
  background: rgb(255 255 255 / 80%);
  color: var(--accent-dark);
  border-radius: 22px;
  padding: 12px 18px;
  cursor: pointer;
}
.practice-metronome:hover {
  border-color: var(--accent-dark);
}
.metronome-summary {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 9px;
  font-size: 13px;
}
.practice-metronome small {
  font-size: 11px;
}
.metronome-dots {
  display: flex;
  justify-content: center;
  flex-wrap: wrap;
  gap: 8px;
}
.metronome-dot {
  width: 16px;
  height: 16px;
  display: grid;
  place-items: center;
  flex-shrink: 0;
  border-radius: 50%;
  background: #eaf1e1;
  border: 1px solid #dbe5d2;
  font-size: 18px;
  line-height: 1;
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
  color: white;
}
.next-pose {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  min-height: 48px;
  max-width: 100%;
  color: var(--muted);
}
.next-pose > span {
  font-size: 10px;
  letter-spacing: 0.08em;
}
.next-pose > p {
  font-size: 12px;
  text-align: left;
}
.next-pose img {
  width: 48px;
  height: 48px;
  object-fit: contain;
  mix-blend-mode: multiply;
  flex-shrink: 0;
}
.player-controls {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 36px;
  margin: 10px 0 6px;
}
.transport {
  display: grid;
  place-items: center;
  width: 48px;
  height: 48px;
  border: 0;
  background: transparent;
  color: var(--accent-dark);
  border-radius: 50%;
  cursor: pointer;
}
.transport-main {
  width: 70px;
  height: 70px;
  color: white;
  background: var(--accent-dark);
  box-shadow: 0 6px 20px rgb(38 82 52 / 15%);
}
.transport:disabled {
  opacity: 0.35;
  cursor: default;
}
.transport-status {
  font-size: 11px;
  color: var(--muted);
}
.stage-navigation {
  width: 100%;
  flex-shrink: 0;
}
.stage-footer {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  align-items: center;
  gap: 8px;
  margin-top: 8px;
  color: var(--muted);
  font-size: 11px;
}
.stage-footer .btn {
  font-size: 11px;
  padding: 6px 10px;
  min-height: 36px;
}
.muted {
  font-size: 13px;
  color: var(--muted);
}
.alert {
  color: #992c28;
  background: #fff0ee;
  padding: 12px;
  border-radius: 12px;
  font-size: 13px;
  width: 100%;
}
.player-notice {
  background: var(--accent-soft);
  padding: 8px 12px;
  border-radius: 12px;
  margin: 8px 0;
  font-size: 12px;
  width: 100%;
}
.planned-coverage {
  margin: var(--section-gap) 0;
}
.coverage-body,
.player-options {
  display: grid;
  gap: 16px;
}
.player-background-hint {
  margin-block: 16px;
}
.breath-setting {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-wrap: wrap;
  gap: 8px;
  font-size: 12px;
}
.breath-setting input {
  width: 72px;
}
.completed-stage {
  position: relative;
  gap: 20px;
  min-height: 300px;
}
.completed-stage h2 {
  font-size: 28px;
}
.completed-stage > .btn {
  margin: 10px;
}
.completed-close {
  position: absolute;
  top: 0;
  right: 0;
}
@media (max-width: 500px) {
  .pose-stage {
    padding: 16px;
  }
  .stage-figure img {
    height: 220px;
  }
  .immersive .stage-figure img {
    height: clamp(120px, 25dvh, 280px);
  }
  .stage-name h2 {
    font-size: 22px;
  }
  .stage-timing {
    --countdown-size: 144px;
  }
  .stage-header {
    margin-bottom: 4px;
  }
  .stage-name {
    margin-block: 4px 8px;
  }
  .stage-heading p {
    font-size: 16px;
  }
  .stage-heading span {
    font-size: 11px;
  }
  .practice-metronome {
    margin-block: 6px;
    padding: 9px 12px;
  }
  .player-controls {
    gap: 34px;
    margin-block: 6px;
  }
  .transport-main {
    width: 62px;
    height: 62px;
  }
  .stage-footer {
    margin-top: 4px;
    gap: 0 8px;
  }
}
@media (max-height: 740px) {
  .immersive {
    --countdown-size: 124px;
  }
  .immersive .stage-figure img {
    height: clamp(100px, 21dvh, 180px);
  }
  .immersive .stage-name h2 {
    font-size: 20px;
  }
  .immersive .stage-header {
    margin-bottom: 0;
  }
  .immersive .stage-navigation {
    position: sticky;
    bottom: -12px;
    z-index: 1;
    padding: 6px 0 max(6px, env(safe-area-inset-bottom));
    border-radius: 20px 20px 0 0;
    background: rgb(248 250 247 / 96%);
  }
}
@media (max-height: 500px) and (min-width: 600px) {
  .pose-stage.immersive {
    display: grid;
    --countdown-size: 110px;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 6px 24px;
  }
  .immersive .stage-header,
  .immersive .stage-navigation,
  .immersive .alert,
  .immersive .player-notice {
    grid-column: 1 / -1;
  }
  .immersive .stage-figure {
    grid-column: 1;
    grid-row: 2 / 4;
  }
  .immersive .stage-figure img {
    height: 175px;
  }
  .immersive .stage-name,
  .immersive .stage-timing,
  .immersive .practice-metronome,
  .immersive .next-pose,
  .immersive .player-controls,
  .immersive .transport-status {
    grid-column: 2;
  }
  .immersive .stage-name {
    grid-row: 2;
  }
  .immersive .stage-timing {
    grid-row: 3;
  }
  .immersive .practice-metronome {
    grid-column: 1;
    grid-row: 4;
  }
  .immersive .next-pose {
    grid-column: 1;
    grid-row: 5;
  }
  .immersive .stage-navigation {
    grid-column: 2;
    grid-row: 4 / 6;
    position: static;
    padding: 0;
    background: transparent;
  }
  .immersive .transport-main {
    width: 54px;
    height: 54px;
  }
}
</style>
