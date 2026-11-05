<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { MessageSquare, Highlighter, MoveUpRight, Undo2, Redo2, Save, Eye, X, Trash2 } from '@lucide/vue';
import AppShell from '@/shared/AppShell.vue';
import Modal from '@/shared/Modal.vue';
import { api, ApiError } from '@/shared/api';
import { uid } from '@/shared/uuid';
import ReviewSheet from '../components/ReviewSheet.vue';
import { emptyReviewAnnotations, type Review, type ReviewAnnotations } from '../types';

const props = defineProps<{ reviewId: string }>();
const page = usePage();
const review = ref<Review | null>(null),
  annotations = ref<ReviewAnnotations>(emptyReviewAnnotations());
const title = ref(''),
  loading = ref(true),
  saving = ref(false),
  error = ref(''),
  notice = ref('');
const mode = ref<'comment' | 'group' | 'arrow'>('comment');
const selected = ref<string[]>([]),
  body = ref(''),
  color = ref('#f1cc48'),
  editingId = ref<string | null>(null);
const composerBaseline = ref('');
interface ComposerState {
  mode: 'comment' | 'group' | 'arrow';
  selected: string[];
  body: string;
  color: string;
  editingId: string | null;
}
interface ComposerDraft extends ComposerState {
  baseline: string;
}
const composerState = (): ComposerState => ({
  mode: mode.value,
  selected: [...selected.value],
  body: body.value,
  color: color.value,
  editingId: editingId.value,
});
function composerHasChanges(value: ComposerDraft | null | undefined) {
  if (!value) return false;
  const { baseline, ...state } = value;
  if (state.editingId) return JSON.stringify(state) !== baseline;
  return !!state.body.trim() || (state.mode !== 'comment' && state.selected.length >= 2);
}
const composerDraft = (): ComposerDraft => ({ ...composerState(), baseline: composerBaseline.value });
const unsavedComposer = computed(() => composerHasChanges(composerDraft()));
const guardOpen = ref(false);
let guardedAction: (() => void) | null = null;
let guardedKind: 'change' | 'history' | 'delete' = 'change';
const showNames = ref(false),
  conflict = ref(false),
  copying = ref(false);
const panel = ref<HTMLElement | null>(null),
  commentInput = ref<HTMLTextAreaElement | null>(null);
const past = ref<string[]>([]),
  future = ref<string[]>([]);
const savedSignature = ref('');
const clone = <T,>(value: T): T => JSON.parse(JSON.stringify(value));
const signature = () => JSON.stringify({ title: title.value, annotations: annotations.value });
const dirty = computed(() => !!review.value && signature() !== savedSignature.value);
const auth = page.props.auth as { user?: { id: number }; workspace?: { id: string } } | undefined;
const baseKey = `ribka-review:${auth?.user?.id ?? 'guest'}:${auth?.workspace?.id ?? 'none'}:${props.reviewId}`;
let tabKey = uid();
try {
  tabKey = sessionStorage.getItem(`${baseKey}:window`) || tabKey;
  sessionStorage.setItem(`${baseKey}:window`, tabKey);
} catch {
  /* Draft persistence is optional. */
}
const draftKey = `${baseKey}:${tabKey}`;
interface ReviewDraft {
  base_revision: number;
  title: string;
  annotations: ReviewAnnotations;
  composer?: ComposerDraft;
  saved_at: number;
}
const pendingDraft = ref<ReviewDraft | null>(null),
  draftOpen = ref(false);
const oldDraft = computed(
  () => !!pendingDraft.value && pendingDraft.value.base_revision !== review.value?.revision,
);
let hydrating = true,
  disposed = false,
  autoSave: ReturnType<typeof setTimeout> | undefined;
let saveJob: Promise<boolean> | null = null;
let copyRequest: { signature: string; id: string } | null = null;
let lastWrittenDraft = '';
const palette = ['#f1cc48', '#7acb8c', '#6fc6e7', '#ee9bae', '#b49be4', '#eba76b'];
const cells = computed(
  () =>
    review.value?.document.blocks.flatMap((block, bi) =>
      block.rows.flatMap((row, ri) =>
        row.cells.map((cell, ci) => ({
          id: cell.id,
          label: `${bi + 1}.${ri + 1}.${ci + 1}`,
          name: cell.card?.name || cell.custom_name || 'Пустая ячейка',
        })),
      ),
    ) ?? [],
);
const positions = computed(() => new Map(cells.value.map((cell) => [cell.id, cell])));
const selectionText = computed(() =>
  selected.value.map((id) => positions.value.get(id)?.label || '').join(', '),
);
const selectionNames = computed(() =>
  selected.value.map((id) => positions.value.get(id)?.name || '').join(' → '),
);
const canApply = computed(() =>
  mode.value === 'comment'
    ? selected.value.length === 1 && !!body.value.trim()
    : mode.value === 'group'
      ? selected.value.length >= 2
      : selected.value.length === 2,
);
const selectionHint = computed(() =>
  mode.value === 'comment'
    ? 'Нажмите на асану и напишите комментарий под ней.'
    : mode.value === 'group'
      ? 'Выберите две или больше асан. Они получат общий цвет и комментарий.'
      : 'Нажмите на асану начала, затем на асану конца стрелки.',
);
function draft() {
  if (!review.value || hydrating) return;
  try {
    if (!dirty.value && !unsavedComposer.value) {
      if (lastWrittenDraft && localStorage.getItem(draftKey) === lastWrittenDraft)
        localStorage.removeItem(draftKey);
      lastWrittenDraft = '';
      return;
    }
    lastWrittenDraft = JSON.stringify({
      base_revision: review.value.revision,
      title: title.value,
      annotations: annotations.value,
      composer: composerDraft(),
      saved_at: Date.now(),
    } satisfies ReviewDraft);
    localStorage.setItem(draftKey, lastWrittenDraft);
  } catch {
    notice.value = 'Черновик не помещается в память браузера. Сохраните разбор кнопкой.';
  }
}
function clearDraft(state: string) {
  try {
    const value = JSON.parse(localStorage.getItem(draftKey) || 'null') as ReviewDraft | null;
    if (
      value &&
      !composerHasChanges(value.composer) &&
      JSON.stringify({ title: value.title, annotations: value.annotations }) === state
    )
      localStorage.removeItem(draftKey);
  } catch {
    /* Keep an unreadable draft. */
  }
}
async function load() {
  hydrating = true;
  loading.value = true;
  error.value = '';
  try {
    review.value = (await api<{ data: Review }>(`/api/practice-reviews/${props.reviewId}`)).data;
    annotations.value = clone(review.value.annotations);
    title.value = review.value.title;
    resetComposer();
    savedSignature.value = signature();
    past.value = [];
    future.value = [];
    conflict.value = false;
    try {
      const candidate = JSON.parse(localStorage.getItem(draftKey) || 'null') as ReviewDraft | null;
      if (
        candidate &&
        typeof candidate.title === 'string' &&
        candidate.annotations &&
        ['comments', 'groups', 'arrows'].every((key) =>
          Array.isArray(candidate.annotations[key as keyof ReviewAnnotations]),
        ) &&
        (JSON.stringify({ title: candidate.title, annotations: candidate.annotations }) !==
          savedSignature.value ||
          composerHasChanges(validComposer(candidate.composer)))
      ) {
        pendingDraft.value = candidate;
        draftOpen.value = true;
      }
    } catch {
      /* An invalid draft does not replace a saved review. */
    }
    await nextTick();
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось открыть разбор.';
  } finally {
    loading.value = false;
    hydrating = false;
  }
}
async function save(): Promise<boolean> {
  if (saveJob) return saveJob;
  if (!review.value || conflict.value) return false;
  if (!dirty.value) return true;
  if (autoSave) clearTimeout(autoSave);
  saving.value = true;
  error.value = '';
  saveJob = (async () => {
    while (dirty.value && review.value && !disposed) {
      const sent = signature();
      const values = JSON.parse(sent) as { title: string; annotations: ReviewAnnotations };
      try {
        const remote = (
          await api<{ data: Review }>(`/api/practice-reviews/${props.reviewId}`, {
            method: 'PUT',
            body: JSON.stringify({ expected_revision: review.value.revision, ...values }),
          })
        ).data;
        review.value = { ...remote, annotations: review.value.annotations };
        savedSignature.value = sent;
        if (!dirty.value && !unsavedComposer.value) clearDraft(sent);
        else draft();
      } catch (failure) {
        conflict.value = failure instanceof ApiError && failure.status === 409;
        error.value = failure instanceof Error ? failure.message : 'Не удалось сохранить пометки.';
        draft();
        return false;
      }
    }
    return !dirty.value;
  })();
  try {
    return await saveJob;
  } finally {
    saveJob = null;
    saving.value = false;
  }
}
function changed(action: () => void) {
  const before = JSON.stringify(annotations.value);
  action();
  if (before !== JSON.stringify(annotations.value)) {
    past.value = [...past.value.slice(-59), before];
    future.value = [];
  }
}
function undoNow() {
  const value = past.value.pop();
  if (value) {
    future.value.push(JSON.stringify(annotations.value));
    annotations.value = JSON.parse(value);
    resetComposer();
  }
}
function redoNow() {
  const value = future.value.pop();
  if (value) {
    past.value.push(JSON.stringify(annotations.value));
    annotations.value = JSON.parse(value);
    resetComposer();
  }
}
function resetComposer() {
  selected.value = [];
  body.value = '';
  editingId.value = null;
  composerBaseline.value = JSON.stringify(composerState());
}
function validComposer(value: unknown): ComposerDraft | null {
  if (!value || typeof value !== 'object') return null;
  const candidate = value as Partial<ComposerDraft>;
  if (
    !['comment', 'group', 'arrow'].includes(candidate.mode ?? '') ||
    !Array.isArray(candidate.selected) ||
    typeof candidate.body !== 'string' ||
    typeof candidate.color !== 'string' ||
    !/^#[0-9a-f]{6}$/i.test(candidate.color) ||
    (candidate.editingId !== null && typeof candidate.editingId !== 'string')
  )
    return null;
  return {
    mode: candidate.mode as ComposerState['mode'],
    selected: [
      ...new Set(candidate.selected.filter((id) => typeof id === 'string' && positions.value.has(id))),
    ],
    body: candidate.body,
    color: candidate.color,
    editingId: candidate.editingId ?? null,
    baseline: typeof candidate.baseline === 'string' ? candidate.baseline : '',
  };
}
function restoreComposer(value: ComposerDraft | null) {
  if (!value) {
    resetComposer();
    return;
  }
  mode.value = value.mode;
  selected.value = [...value.selected];
  body.value = value.body;
  color.value = value.color;
  editingId.value = value.editingId;
  composerBaseline.value = value.baseline;
}
function requestComposerAction(action: () => void, kind: typeof guardedKind = 'change') {
  if (!unsavedComposer.value) {
    action();
    return;
  }
  guardedAction = action;
  guardedKind = kind;
  guardOpen.value = true;
  draft();
}
function continueComposer() {
  guardOpen.value = false;
  guardedAction = null;
  void nextTick(() => commentInput.value?.focus({ preventScroll: true }));
}
function discardComposer() {
  const action = guardedAction;
  guardOpen.value = false;
  guardedAction = null;
  resetComposer();
  action?.();
  draft();
}
function applyGuardedComposer() {
  if (!canApply.value) return;
  const action = guardedAction;
  const kind = guardedKind;
  guardedAction = null;
  guardOpen.value = false;
  if (kind === 'history') {
    const value = composerDraft();
    resetComposer();
    action?.();
    restoreComposer(value);
    applyAnnotation();
  } else {
    applyAnnotation();
    if (kind === 'delete') notice.value = 'Пометка сохранена. Чтобы удалить её, нажмите корзину ещё раз.';
    else action?.();
  }
}
function undo() {
  if (past.value.length) requestComposerAction(undoNow, 'history');
}
function redo() {
  if (future.value.length) requestComposerAction(redoNow, 'history');
}
function clearSelection() {
  requestComposerAction(resetComposer);
}
function chooseMode(value: typeof mode.value) {
  if (mode.value === value) return;
  requestComposerAction(() => {
    mode.value = value;
    resetComposer();
  });
}
async function focusComposer() {
  await nextTick();
  panel.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  commentInput.value?.focus({ preventScroll: true });
}
function selectCell(id: string) {
  if (mode.value === 'comment') {
    if (selected.value.length === 1 && selected.value[0] === id) return;
    if (!selected.value.length && body.value.trim() && !editingId.value) {
      const existing = annotations.value.comments.find((item) => item.cell_id === id);
      selected.value = [id];
      editingId.value = existing?.id ?? null;
      composerBaseline.value = JSON.stringify({
        ...composerState(),
        body: existing?.body ?? '',
        color: existing?.color ?? color.value,
      });
      if (window.matchMedia('(max-width:1100px)').matches) void focusComposer();
      return;
    }
    requestComposerAction(() => {
      selected.value = [id];
      const comment = annotations.value.comments.find((item) => item.cell_id === id);
      editingId.value = comment?.id ?? null;
      body.value = comment?.body ?? '';
      if (comment) color.value = comment.color;
      composerBaseline.value = JSON.stringify(composerState());
      if (window.matchMedia('(max-width:1100px)').matches) void focusComposer();
    });
  } else if (mode.value === 'group')
    selected.value = selected.value.includes(id)
      ? selected.value.filter((value) => value !== id)
      : [...selected.value, id];
  else if (!selected.value.length) selected.value = [id];
  else if (selected.value.length !== 1) {
    requestComposerAction(() => {
      selected.value = [id];
      body.value = '';
      editingId.value = null;
      composerBaseline.value = JSON.stringify(composerState());
    });
  } else if (selected.value[0] !== id) selected.value = [...selected.value, id];
}
function composerCanApply(value: ComposerState) {
  return value.mode === 'comment'
    ? value.selected.length === 1 && !!value.body.trim()
    : value.mode === 'group'
      ? value.selected.length >= 2
      : value.selected.length === 2;
}
function withComposer(values: ReviewAnnotations, composer: ComposerState): ReviewAnnotations {
  const result = clone(values);
  const id = composer.editingId || uid();
  const text = composer.body.trim();
  if (composer.mode === 'comment') {
    const existing = result.comments.find((item) => item.id === id || item.cell_id === composer.selected[0]);
    const value = {
      id: existing?.id || id,
      cell_id: composer.selected[0],
      body: text,
      color: composer.color,
    };
    result.comments = [...result.comments.filter((item) => item.id !== value.id), value];
  } else if (composer.mode === 'group') {
    const ids = cells.value.filter((cell) => composer.selected.includes(cell.id)).map((cell) => cell.id);
    result.groups = [
      ...result.groups.filter((item) => item.id !== id),
      { id, cell_ids: ids, body: text, color: composer.color },
    ];
  } else {
    result.arrows = [
      ...result.arrows.filter((item) => item.id !== id),
      {
        id,
        from_cell_id: composer.selected[0],
        to_cell_id: composer.selected[1],
        body: text,
        color: composer.color,
      },
    ];
  }
  return result;
}
function applyAnnotation() {
  if (!canApply.value) return;
  changed(() => {
    annotations.value = withComposer(annotations.value, composerState());
  });
  notice.value =
    mode.value === 'comment'
      ? 'Комментарий добавлен под асаной.'
      : mode.value === 'group'
        ? 'Группа выделена выбранным цветом.'
        : 'Стрелка добавлена.';
  resetComposer();
}
function editAnnotation(kind: typeof mode.value, id: string) {
  if (editingId.value === id && mode.value === kind) return;
  requestComposerAction(() => {
    mode.value = kind;
    resetComposer();
    editingId.value = id;
    if (kind === 'comment') {
      const item = annotations.value.comments.find((item) => item.id === id);
      if (item) {
        selected.value = [item.cell_id];
        body.value = item.body;
        color.value = item.color;
      }
    } else if (kind === 'group') {
      const item = annotations.value.groups.find((item) => item.id === id);
      if (item) {
        selected.value = [...item.cell_ids];
        body.value = item.body;
        color.value = item.color;
      }
    } else {
      const item = annotations.value.arrows.find((item) => item.id === id);
      if (item) {
        selected.value = [item.from_cell_id, item.to_cell_id];
        body.value = item.body || '';
        color.value = item.color;
      }
    }
    composerBaseline.value = JSON.stringify(composerState());
    if (window.matchMedia('(max-width:1100px)').matches) void focusComposer();
  });
}
function removeAnnotation(kind: typeof mode.value, id: string) {
  const remove = () => {
    changed(() => {
      if (kind === 'comment')
        annotations.value.comments = annotations.value.comments.filter((item) => item.id !== id);
      else if (kind === 'group')
        annotations.value.groups = annotations.value.groups.filter((item) => item.id !== id);
      else annotations.value.arrows = annotations.value.arrows.filter((item) => item.id !== id);
    });
    if (editingId.value === id) resetComposer();
  };
  if (editingId.value === id) requestComposerAction(remove, 'delete');
  else remove();
}
function applyForSave() {
  if (!unsavedComposer.value) return true;
  if (!canApply.value) {
    error.value = 'Текст ещё не добавлен в пометки. Выберите асану или завершите выделение, затем сохраните.';
    void focusComposer();
    return false;
  }
  applyAnnotation();
  return true;
}
async function saveExplicit() {
  if (!applyForSave()) return false;
  return await save();
}
async function preview() {
  if (await saveExplicit()) router.visit(`/practice-reviews/${props.reviewId}/preview`);
}
function restoreDraft() {
  if (!pendingDraft.value || oldDraft.value) return;
  title.value = pendingDraft.value.title;
  annotations.value = clone(pendingDraft.value.annotations);
  restoreComposer(validComposer(pendingDraft.value.composer));
  pendingDraft.value = null;
  draftOpen.value = false;
  draft();
}
function discardDraft() {
  if (pendingDraft.value) {
    try {
      if (localStorage.getItem(draftKey) === JSON.stringify(pendingDraft.value))
        localStorage.removeItem(draftKey);
    } catch {
      /* Do not remove a newer or unreadable draft. */
    }
  }
  pendingDraft.value = null;
  draftOpen.value = false;
}
async function copyLocal(usePendingDraft = false) {
  if (copying.value || !review.value) return;
  if (!usePendingDraft && !applyForSave()) return;
  const sourceDraft = usePendingDraft ? pendingDraft.value : null;
  const composer = validComposer(sourceDraft?.composer);
  if (sourceDraft && composerHasChanges(composer) && composer && !composerCanApply(composer)) {
    const stale = oldDraft.value;
    title.value = sourceDraft.title;
    annotations.value = clone(sourceDraft.annotations);
    restoreComposer(composer);
    pendingDraft.value = null;
    draftOpen.value = false;
    if (stale) conflict.value = true;
    error.value =
      'Черновик текста сохранён. Выберите асану или завершите выделение, затем сохраните отдельный разбор.';
    draft();
    void focusComposer();
    return;
  }
  copying.value = true;
  error.value = '';
  const values = sourceDraft
    ? {
        title: sourceDraft.title,
        annotations:
          composerHasChanges(composer) && composer
            ? withComposer(sourceDraft.annotations, composer)
            : sourceDraft.annotations,
      }
    : { title: title.value, annotations: annotations.value };
  const state = JSON.stringify(values);
  if (copyRequest?.signature !== state) copyRequest = { signature: state, id: uid() };
  try {
    const remote = (await api<{ data: Review }>(`/api/practice-reviews/${props.reviewId}`)).data;
    const result = (
      await api<{ data: Review }>(`/api/practice-reviews/${props.reviewId}/copy`, {
        method: 'POST',
        body: JSON.stringify({
          id: copyRequest.id,
          expected_revision: remote.revision,
          title: `${values.title} — копия`.slice(0, 160),
          annotations: values.annotations,
        }),
      })
    ).data;
    if (sourceDraft) {
      try {
        if (localStorage.getItem(draftKey) === JSON.stringify(sourceDraft)) localStorage.removeItem(draftKey);
      } catch {
        /* A newer draft remains untouched. */
      }
    } else clearDraft(state);
    router.visit(`/practice-reviews/${result.id}`);
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось сохранить отдельный разбор.';
  } finally {
    copying.value = false;
  }
}
function beforeUnload(event: BeforeUnloadEvent) {
  draft();
  if (dirty.value || unsavedComposer.value) {
    event.preventDefault();
    event.returnValue = '';
  }
}
function keydown(event: KeyboardEvent) {
  if (event.defaultPrevented || guardOpen.value || draftOpen.value) return;
  if ((event.target as HTMLElement).closest('input,textarea,select,button,[contenteditable]')) return;
  if (event.key === 'Escape') clearSelection();
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
    event.preventDefault();
    event.shiftKey ? redo() : undo();
  }
}
watch(
  [title, annotations],
  () => {
    if (hydrating) return;
    draft();
    if (autoSave) clearTimeout(autoSave);
    if (dirty.value && !conflict.value)
      autoSave = setTimeout(() => {
        void save();
      }, 900);
  },
  { deep: true },
);
watch(
  [mode, selected, body, color, editingId, composerBaseline],
  () => {
    if (!hydrating) draft();
  },
  { deep: true },
);
onMounted(() => {
  void load();
  window.addEventListener('beforeunload', beforeUnload);
  window.addEventListener('keydown', keydown);
});
onBeforeUnmount(() => {
  draft();
  disposed = true;
  if (autoSave) clearTimeout(autoSave);
  window.removeEventListener('beforeunload', beforeUnload);
  window.removeEventListener('keydown', keydown);
});
</script>
<template>
  <Head :title="review ? `${title} — разбор` : 'Разбор практики'" />
  <AppShell section="reviews" wide>
    <header class="page-header review-heading">
      <div>
        <Link href="/practice-reviews" class="btn btn-ghost">← Все разборы</Link>
        <h1 class="page-title">Разбор практики</h1>
        <p class="page-description">Асаны и их порядок сохраняются. Здесь меняются только ваши пометки.</p>
      </div>
      <div class="review-actions">
        <button
          class="btn"
          :disabled="saving || (!dirty && !unsavedComposer) || conflict"
          @click="saveExplicit"
        >
          <Save :size="17" />{{ saving ? 'Сохраняем…' : 'Сохранить' }}</button
        ><button class="btn btn-primary" :disabled="loading || saving || conflict" @click="preview">
          <Eye :size="17" />Просмотр / PDF
        </button>
      </div>
    </header>
    <p v-if="error" class="error-banner" role="alert">
      {{ error
      }}<button v-if="conflict" class="btn" :disabled="copying" @click="copyLocal()">
        Сохранить отдельный разбор</button
      ><button v-else-if="review" class="btn" @click="saveExplicit">Повторить сохранение</button
      ><button v-else class="btn" @click="load">Повторить</button>
    </p>
    <p v-if="loading" role="status" class="muted">Открываем разбор…</p>
    <template v-if="review && !loading">
      <div class="card review-meta">
        <label class="field"
          ><span class="label">Название разбора</span
          ><input v-model="title" class="input" maxlength="160" aria-label="Название разбора"
        /></label>
        <p>
          Практика: <strong>{{ review.document.title }}</strong
          ><span v-if="review.source_changed" class="source-status"
            >Исходник обновлён. В разборе сохранена выбранная версия.</span
          >
        </p>
      </div>
      <div class="card review-toolbar" aria-label="Инструменты разбора">
        <div class="review-modes">
          <button
            :class="['btn', { chosen: mode === 'comment' }]"
            :aria-pressed="mode === 'comment'"
            @click="chooseMode('comment')"
          >
            <MessageSquare :size="17" />Комментарий</button
          ><button
            :class="['btn', { chosen: mode === 'group' }]"
            :aria-pressed="mode === 'group'"
            @click="chooseMode('group')"
          >
            <Highlighter :size="17" />Цветная группа</button
          ><button
            :class="['btn', { chosen: mode === 'arrow' }]"
            :aria-pressed="mode === 'arrow'"
            @click="chooseMode('arrow')"
          >
            <MoveUpRight :size="17" />Стрелка
          </button>
        </div>
        <div class="review-actions">
          <button class="icon-btn" :disabled="!past.length" aria-label="Отменить пометку" @click="undo">
            <Undo2 :size="18" /></button
          ><button class="icon-btn" :disabled="!future.length" aria-label="Повторить пометку" @click="redo">
            <Redo2 :size="18" /></button
          ><label class="check-label"><input v-model="showNames" type="checkbox" />Названия асан</label
          ><span class="review-save-status" role="status">{{
            conflict
              ? 'Нужна отдельная копия'
              : unsavedComposer
                ? 'Пометка ещё не добавлена на лист'
                : saving
                  ? 'Сохраняем…'
                  : dirty
                    ? 'Есть изменения'
                    : 'Все пометки сохранены'
          }}</span>
        </div>
      </div>
      <div class="review-layout">
        <div class="review-document">
          <ReviewSheet
            :document="review.document"
            :annotations="annotations"
            interactive
            :selected="selected"
            :arrow-from="mode === 'arrow' ? (selected[0] ?? null) : null"
            :show-names="showNames"
            @select="selectCell"
          />
        </div>
        <aside ref="panel" class="card annotation-panel" aria-label="Пометки разбора">
          <h2>
            {{
              mode === 'comment'
                ? 'Комментарий к асане'
                : mode === 'group'
                  ? 'Цветная группа'
                  : 'Связь между асанами'
            }}
          </h2>
          <p class="annotation-hint">{{ selectionHint }}</p>
          <div v-if="selected.length" class="selection-summary">
            <strong>Выбрано: {{ selectionText }}</strong>
            <p>{{ selectionNames }}</p>
            <button class="btn btn-ghost" @click="clearSelection"><X :size="15" />Снять выделение</button>
          </div>
          <form @submit.prevent="applyAnnotation">
            <fieldset class="color-choices">
              <legend>Цвет пометки</legend>
              <div>
                <button
                  v-for="value in palette"
                  :key="value"
                  type="button"
                  :style="{ backgroundColor: value }"
                  :class="{ active: color === value }"
                  :aria-label="`Цвет ${value}`"
                  :aria-pressed="color === value"
                  @click="color = value"
                /><input v-model="color" type="color" aria-label="Свой цвет пометки" />
              </div>
            </fieldset>
            <label class="field"
              ><span class="label">{{
                mode === 'comment'
                  ? 'Комментарий'
                  : mode === 'group'
                    ? 'Комментарий к группе'
                    : 'Подпись стрелки (необязательно)'
              }}</span
              ><textarea
                ref="commentInput"
                v-model="body"
                class="input annotation-input"
                :maxlength="mode === 'arrow' ? 500 : 3000"
                rows="4"
                :aria-label="
                  mode === 'comment'
                    ? 'Комментарий под асаной'
                    : mode === 'group'
                      ? 'Комментарий к группе'
                      : 'Подпись стрелки'
                "
                :placeholder="
                  mode === 'group' ? 'Что объединяет выбранные асаны?' : 'Ваши наблюдения и пояснения'
                "
              />
            </label>
            <p v-if="unsavedComposer" class="composer-pending" role="status">
              Введённая пометка ещё не добавлена на лист. Нажмите кнопку ниже или «Сохранить».
            </p>
            <button class="btn btn-primary apply-annotation" :disabled="!canApply">
              {{
                editingId
                  ? 'Сохранить пометку'
                  : mode === 'comment'
                    ? 'Добавить комментарий'
                    : mode === 'group'
                      ? 'Выделить группу'
                      : 'Добавить стрелку'
              }}
            </button>
          </form>
          <p v-if="notice" class="annotation-notice" role="status">{{ notice }}</p>
          <details class="annotation-list disclosure" open>
            <summary>
              <span class="disclosure-icon"><MessageSquare aria-hidden="true" /></span>
              <span class="disclosure-copy">
                <strong>Все пометки</strong>
                <small
                  >В разборе:
                  {{
                    annotations.comments.length + annotations.groups.length + annotations.arrows.length
                  }}</small
                >
              </span>
            </summary>
            <div class="disclosure-body">
              <article
                v-for="item in annotations.comments"
                :key="item.id"
                :style="{ '--note-color': item.color }"
              >
                <button class="note-open" @click="editAnnotation('comment', item.id)">
                  <strong>Асана {{ positions.get(item.cell_id)?.label }}</strong
                  ><span>{{ item.body }}</span></button
                ><button
                  class="icon-btn"
                  aria-label="Удалить комментарий"
                  @click="removeAnnotation('comment', item.id)"
                >
                  <Trash2 :size="16" />
                </button>
              </article>
              <article
                v-for="(item, index) in annotations.groups"
                :key="item.id"
                :style="{ '--note-color': item.color }"
              >
                <button class="note-open" @click="editAnnotation('group', item.id)">
                  <strong
                    >Группа {{ index + 1 }} ·
                    {{ item.cell_ids.map((id) => positions.get(id)?.label).join(', ') }}</strong
                  ><span>{{ item.body || 'Цветное выделение' }}</span></button
                ><button
                  class="icon-btn"
                  aria-label="Удалить цветную группу"
                  @click="removeAnnotation('group', item.id)"
                >
                  <Trash2 :size="16" />
                </button>
              </article>
              <article
                v-for="item in annotations.arrows"
                :key="item.id"
                :style="{ '--note-color': item.color }"
              >
                <button class="note-open" @click="editAnnotation('arrow', item.id)">
                  <strong
                    >{{ positions.get(item.from_cell_id)?.label }} →
                    {{ positions.get(item.to_cell_id)?.label }}</strong
                  ><span>{{ item.body || 'Стрелка между асанами' }}</span></button
                ><button
                  class="icon-btn"
                  aria-label="Удалить стрелку разбора"
                  @click="removeAnnotation('arrow', item.id)"
                >
                  <Trash2 :size="16" />
                </button>
              </article>
              <p
                v-if="
                  !annotations.comments.length && !annotations.groups.length && !annotations.arrows.length
                "
                class="muted"
              >
                Начните с асаны на листе.
              </p>
            </div>
          </details>
        </aside>
      </div>
    </template>
    <button v-if="selected.length" class="btn btn-primary mobile-composer-shortcut" @click="focusComposer">
      Пометка к выбранным · {{ selected.length }}
    </button>
    <Modal :open="draftOpen" title="Есть несохранённые пометки" @close="draftOpen = false"
      ><p class="draft-explanation">
        {{
          oldDraft
            ? 'Сохранённый разбор изменился в другом окне. Ваши пометки можно сохранить отдельно, не перезаписывая его.'
            : 'В этом окне остался черновик пометок. Вернуть его в разбор?'
        }}
      </p>
      <template #footer
        ><button class="btn" @click="discardDraft">Оставить сохранённую версию</button
        ><button v-if="oldDraft" class="btn btn-primary" :disabled="copying" @click="copyLocal(true)">
          Сохранить черновик отдельно</button
        ><button v-else class="btn btn-primary" @click="restoreDraft">Вернуть черновик</button></template
      ></Modal
    >
    <Modal :open="guardOpen" title="Пометка ещё не добавлена" @close="continueComposer">
      <p class="draft-explanation">
        В поле остались изменения. Добавьте пометку на лист или явно отбросьте её перед переходом.
      </p>
      <p v-if="body.trim()" class="composer-preview">{{ body }}</p>
      <p v-if="!canApply" class="draft-explanation">
        Чтобы добавить пометку, выберите асану или завершите выделение. Введённый текст сохранится при
        продолжении редактирования.
      </p>
      <template #footer>
        <button class="btn" @click="continueComposer">Продолжить редактирование</button>
        <button class="btn" @click="discardComposer">Отбросить изменения поля</button>
        <button class="btn btn-primary" :disabled="!canApply" @click="applyGuardedComposer">
          {{ editingId ? 'Сохранить пометку' : 'Добавить пометку' }}
        </button>
      </template>
    </Modal>
  </AppShell>
</template>
<style scoped>
.review-heading > div:first-child {
  flex: 1 1 400px;
  min-width: 0;
}
.review-heading h1 {
  margin-top: 12px;
  color: #203d2c;
  font-weight: 550;
}
.review-actions,
.review-modes {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.review-meta {
  display: grid;
  grid-template-columns: minmax(220px, 1fr) minmax(220px, 1fr);
  gap: 24px;
  align-items: end;
  margin-bottom: var(--section-gap);
}
.review-meta > p {
  font-size: 14px;
  line-height: 1.6;
  overflow-wrap: anywhere;
}
.source-status {
  display: block;
  margin-top: 8px;
  color: var(--accent-dark);
}
.review-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: var(--section-gap);
  padding: 16px;
  background: linear-gradient(150deg, #f8faf4, #fff 70%);
  border-color: #e0e7da;
}
.review-modes .chosen {
  background: var(--accent-soft);
  border-color: #cbdcbb;
  color: var(--accent-dark);
}
.review-save-status {
  font-size: 12px;
  color: var(--muted);
}
.review-toolbar .check-label {
  font-size: 13px;
  min-height: 44px;
}
.review-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 320px;
  gap: 24px;
  align-items: start;
}
.review-document {
  min-width: 0;
}
.annotation-panel {
  position: sticky;
  top: 24px;
  max-height: calc(100dvh - 48px);
  overflow-y: auto;
  display: grid;
  gap: 18px;
  min-width: 0;
  padding: 22px;
}
.annotation-panel h2 {
  font-size: 19px;
  font-weight: 550;
  color: #29492f;
}
.annotation-hint {
  font-size: 13px;
  color: var(--muted);
  line-height: 1.6;
}
.selection-summary {
  background: var(--surface-soft);
  border-radius: 14px;
  padding: 12px;
  overflow-wrap: anywhere;
}
.selection-summary strong {
  font-size: 13px;
}
.selection-summary p {
  font-size: 12px;
  line-height: 1.5;
  margin-top: 6px;
}
.selection-summary > .btn {
  font-size: 12px;
  min-height: 36px;
  padding: 6px 0;
}
.annotation-panel form {
  display: grid;
  gap: 16px;
}
.color-choices {
  border: 0;
  padding: 0;
  margin: 0;
  min-width: 0;
}
.color-choices legend {
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 10px;
}
.color-choices > div {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.color-choices button,
.color-choices input {
  width: 44px;
  height: 44px;
  border: 1px solid var(--line);
  border-radius: 9px;
  cursor: pointer;
  padding: 0;
  flex-shrink: 0;
}
.color-choices button.active {
  outline: 2px solid var(--text);
  outline-offset: 2px;
}
.annotation-input {
  resize: vertical;
  min-height: 112px;
  font-size: 14px;
  line-height: 1.5;
}
.composer-pending {
  color: var(--accent-dark);
  font-size: 12px;
  line-height: 1.5;
}
.composer-preview {
  margin: 16px 0;
  padding: 14px;
  max-height: 180px;
  overflow: auto;
  border-radius: 12px;
  background: var(--surface-soft);
  font-size: 14px;
  line-height: 1.6;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.apply-annotation {
  width: 100%;
  font-size: 13px;
}
.annotation-notice {
  font-size: 12px;
  line-height: 1.5;
  color: var(--accent-dark);
}
.annotation-list {
  min-width: 0;
}
.annotation-list article {
  display: flex;
  gap: 6px;
  border-left: 4px solid var(--note-color);
  border-bottom: 1px solid var(--line);
  margin-top: 8px;
  padding: 8px 0 8px 10px;
  min-width: 0;
}
.note-open {
  flex: 1;
  min-width: 0;
  border: 0;
  background: none;
  text-align: left;
  padding: 0;
  line-height: 1.5;
  overflow-wrap: anywhere;
}
.note-open strong {
  font-size: 13px;
  display: block;
}
.note-open span {
  font-size: 13px;
  color: var(--muted);
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 3;
  overflow: hidden;
  white-space: pre-wrap;
}
.annotation-list .icon-btn {
  align-self: start;
}
.draft-explanation {
  line-height: 1.6;
}
@media (max-width: 1100px) {
  .review-layout {
    grid-template-columns: minmax(0, 1fr);
  }
  .annotation-panel {
    position: static;
    max-height: none;
    overflow-y: visible;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 16px 24px;
  }
  .annotation-panel > h2,
  .annotation-panel > .annotation-hint {
    grid-column: 1/-1;
  }
  .annotation-panel form {
    grid-column: 1;
  }
  .annotation-list {
    grid-column: 2;
    grid-row: 3 / span 3;
  }
  .selection-summary {
    grid-column: 1;
  }
  .annotation-panel > .annotation-notice {
    grid-column: 1;
  }
}
@media (max-width: 620px) {
  .review-meta {
    grid-template-columns: 1fr;
    gap: 16px;
  }
  .annotation-panel {
    display: grid;
    grid-template-columns: 1fr;
  }
  .annotation-panel > * {
    grid-column: 1 !important;
    grid-row: auto !important;
  }
  .review-modes {
    width: 100%;
  }
  .review-modes > .btn {
    flex: 1;
    font-size: 12px;
    padding-inline: 10px;
  }
  .review-actions > .btn {
    flex: 1;
  }
  .review-heading > .review-actions {
    width: 100%;
  }
  .review-toolbar {
    padding: 12px;
  }
  .review-layout {
    gap: 20px;
  }
  .color-choices button,
  .color-choices input {
    width: 44px;
    min-height: 44px;
  }
}
.mobile-composer-shortcut {
  display: none;
}
@media (max-width: 1100px) {
  .mobile-composer-shortcut {
    display: flex;
    position: fixed;
    right: 24px;
    bottom: 100px;
    z-index: 60;
    max-width: calc(100% - 48px);
    box-shadow: 0 4px 24px #15191620;
  }
}
</style>
