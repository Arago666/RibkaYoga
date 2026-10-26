<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Plus, X, Save, ExternalLink, Copy, RefreshCw, GripVertical } from '@lucide/vue';
import PracticePicker, { type PracticeChoice } from '../components/PracticePicker.vue';
import ActionMenu from '@/shared/ActionMenu.vue';
import AppShell from '@/shared/AppShell.vue';
import Modal from '@/shared/Modal.vue';
import AsanaPalette from '../components/AsanaPalette.vue';
import { api, ApiError } from '@/shared/api';
import type { Asana } from '@/modules/catalog/types';
import type { Practice } from '../types';
import { uid } from '../types';
import { usePaletteDrag, type PaletteDragPoint } from '../composables/usePaletteDrag';
import {
  isMesocycleEvent,
  mesocycleCellAtPoint,
  scrollMesocycleAtPoint,
  type MesocycleCommandPayload,
  type MesocycleTarget,
} from '../mesocycleChannel';
interface Week {
  id: string;
  position: number;
  document_id: string;
  document: Practice;
  title: string | null;
}
interface Cycle {
  id: string;
  title: string;
  theme: string | null;
  revision: number;
  cloud_asana_ids: string[];
  cloud_asanas: Asana[];
  weeks: Week[];
  archived_at: string | null;
  kind: 'cycle' | 'template';
}
const props = defineProps<{ mesocycleId: string }>();
const cycle = ref<Cycle | null>(null),
  loading = ref(true),
  saving = ref(false),
  error = ref(''),
  conflict = ref(false),
  cloudOpen = ref(false),
  notice = ref('');
const frames = new Map<string, HTMLIFrameElement>();
const activeDocumentId = ref<string | null>(null);
const switching = ref(false);
const boardPage = ref(0);
const visibleWeeks = computed(
  () => cycle.value?.weeks.slice(boardPage.value * 5, boardPage.value * 5 + 5) || [],
);
async function saveMountedWeeks() {
  for (const documentId of frames.keys()) if (!(await saveWeek(documentId))) return false;
  return true;
}
async function changeBoardPage(value: number) {
  if (switching.value) return;
  switching.value = true;
  try {
    if (!(await saveMountedWeeks())) {
      error.value = 'Сохраните открытые занятия перед сменой страницы.';
      return;
    }
    pointerDrag.cancel();
    target.value = null;
    boardPage.value = value;
  } finally {
    switching.value = false;
  }
}
const structureKey = ref(uid());
const replacementKey = ref(uid());
const previewUndo = ref<string | null>(null);
const frameSessions = new Map<string, string>();
const insertionSequences = new Map<string, number>();
const weekDirty = ref<Record<string, boolean>>({});
const weekSaveRequests = new Map<
  string,
  { requestId: string; resolve: (saved: boolean) => void; timer: ReturnType<typeof setTimeout> }
>();
const allSaving = ref(false);
const target = ref<MesocycleTarget | null>(null);
const dragTarget = ref<MesocycleTarget | null>(null);
const weeksBoard = ref<HTMLElement | null>(null);
let lastCloudPoint: PaletteDragPoint | null = null,
  dragScrollFrame = 0;
const replacementPositionOpen = ref(false);
const replacementCandidate = ref<PracticeChoice | null>(null);
const replacement = ref<Week | null>(null),
  sources = ref<{ id: string; title: string; revision: number }[]>([]),
  sourceId = ref(''),
  replaceBusy = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined,
  alive = true,
  inFlight: Promise<boolean> | null = null;
const acknowledged = ref('');
const signature = () =>
  JSON.stringify(
    cycle.value && {
      title: cycle.value.title,
      theme: cycle.value.theme,
      cloud_asana_ids: cycle.value.cloud_asana_ids,
    },
  );
const dirty = computed(() => !!cycle.value && signature() !== acknowledged.value);
const selectedWeek = computed(() =>
  target.value ? cycle.value?.weeks.find((week) => week.document_id === target.value!.documentId) : null,
);
const status = computed(() =>
  conflict.value
    ? 'Мезоцикл изменён в другом окне'
    : saving.value
      ? 'Сохраняем облако и тему…'
      : dirty.value
        ? 'Есть изменения'
        : error.value
          ? 'Не удалось сохранить'
          : 'Тема и облако сохранены',
);
const cloudDisabled = computed(
  () =>
    loading.value || conflict.value || !!cycle.value?.archived_at || !!replacement.value || cloudOpen.value,
);
function frameSource(documentId: string) {
  let sessionId = frameSessions.get(documentId);
  if (!sessionId) {
    sessionId = uid();
    frameSessions.set(documentId, sessionId);
  }
  return `/practices/${documentId}/edit?embedded=1&mesocycle_session=${sessionId}`;
}
function sendToWeek(documentId: string, command: MesocycleCommandPayload) {
  const frame = frames.get(documentId),
    sessionId = frameSessions.get(documentId);
  if (!frame?.contentWindow || !sessionId) return false;
  frame.contentWindow.postMessage({ ...command, documentId, sessionId }, window.location.origin);
  return true;
}
function setDragTarget(next: MesocycleTarget | null) {
  if (dragTarget.value?.documentId === next?.documentId && dragTarget.value?.cellId === next?.cellId) return;
  if (dragTarget.value) sendToWeek(dragTarget.value.documentId, { type: 'ribka:meso-hover', cellId: null });
  dragTarget.value = next;
  if (next) sendToWeek(next.documentId, { type: 'ribka:meso-hover', cellId: next.cellId });
}
function moveCloud(point: PaletteDragPoint) {
  lastCloudPoint = point;
  setDragTarget(cloudTargetAtPoint(point));
  if (!dragScrollFrame) dragScrollFrame = requestAnimationFrame(scrollCloud);
}
function scrollCloud() {
  dragScrollFrame = 0;
  if (!lastCloudPoint || !dragPreview.value) return;
  scrollMesocycleAtPoint(frames, weeksBoard.value, lastCloudPoint);
  setDragTarget(cloudTargetAtPoint(lastCloudPoint));
  dragScrollFrame = requestAnimationFrame(scrollCloud);
}
function endCloudDrag() {
  lastCloudPoint = null;
  cancelAnimationFrame(dragScrollFrame);
  dragScrollFrame = 0;
  setDragTarget(null);
}
const pointerDrag = usePaletteDrag({
  enabled: () => !cloudDisabled.value,
  move: moveCloud,
  drop: (card, point) => {
    const destination = cloudTargetAtPoint(point);
    if (destination) insertCloud(card, destination);
  },
  end: endCloudDrag,
});
const dragPreview = pointerDrag.preview;
const dragPreviewStyle = computed(() =>
  dragPreview.value
    ? {
        left: `${Math.max(8, Math.min(dragPreview.value.x + 16, window.innerWidth - 140))}px`,
        top: `${Math.max(8, Math.min(dragPreview.value.y + 16, window.innerHeight - 130))}px`,
      }
    : {},
);
function clickCloud(event: MouseEvent, card: Asana) {
  if (pointerDrag.allowClick(event)) insertCloud(card);
}
watch(cloudDisabled, (disabled) => {
  if (disabled) pointerDrag.cancel();
});
async function load() {
  pointerDrag.cancel();
  loading.value = true;
  error.value = '';
  try {
    const result = await api<{ data: Cycle }>(`/api/mesocycles/${props.mesocycleId}`);
    if (!alive) return;
    cycle.value = result.data;
    if (
      !activeDocumentId.value ||
      !result.data.weeks.some((week) => week.document_id === activeDocumentId.value)
    )
      activeDocumentId.value = result.data.weeks[0]?.document_id || null;
    weekDirty.value = Object.fromEntries(
      result.data.weeks.map((week) => [week.document_id, weekDirty.value[week.document_id] || false]),
    );
    acknowledged.value = signature();
    conflict.value = false;
    target.value = null;
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось открыть мезоцикл.';
  } finally {
    loading.value = false;
  }
}
function schedule() {
  clearTimeout(timer);
  if (!conflict.value) timer = setTimeout(() => void saveMetadata(), 800);
}
async function performSave(): Promise<boolean> {
  if (!cycle.value || conflict.value || !alive) return false;
  if (!dirty.value) return true;
  saving.value = true;
  error.value = '';
  const sent = signature(),
    outgoing = {
      title: cycle.value.title.trim(),
      theme: cycle.value.theme?.trim() || null,
    };
  try {
    const previous = JSON.parse(acknowledged.value || '{}') as Partial<Cycle>;
    const cloud = [...cycle.value.cloud_asana_ids];
    const response = await api<{ data: Cycle }>(`/api/mesocycles/${props.mesocycleId}`, {
      method: 'PATCH',
      body: JSON.stringify({ ...outgoing, expected_revision: cycle.value.revision }),
    });
    if (!alive) return false;
    cycle.value.revision = response.data.revision;
    acknowledged.value = JSON.stringify({ ...outgoing, cloud_asana_ids: previous.cloud_asana_ids || [] });
    if (JSON.stringify(cloud) !== JSON.stringify(previous.cloud_asana_ids || [])) {
      const cloudResponse = await api<{ data: Cycle }>(`/api/mesocycles/${props.mesocycleId}`, {
        method: 'PATCH',
        body: JSON.stringify({ cloud_asana_ids: cloud, expected_revision: cycle.value.revision }),
      });
      if (!alive) return false;
      cycle.value.revision = cloudResponse.data.revision;
    }
    acknowledged.value = sent;
    return true;
  } catch (e) {
    if (e instanceof ApiError && e.status === 409) conflict.value = true;
    error.value = e instanceof Error ? e.message : 'Не удалось сохранить мезоцикл.';
    return false;
  } finally {
    saving.value = false;
  }
}
async function saveMetadata(): Promise<boolean> {
  clearTimeout(timer);
  if (inFlight) {
    await inFlight;
    if (!alive || conflict.value) return false;
    return dirty.value && !error.value ? saveMetadata() : !!cycle.value && !dirty.value;
  }
  const attempt = performSave();
  inFlight = attempt;
  const result = await attempt;
  if (inFlight === attempt) inFlight = null;
  return result && dirty.value ? saveMetadata() : result;
}
function addCloud(card: Asana) {
  if (!cycle.value || card.archived_at) return;
  if (!cycle.value.cloud_asana_ids.includes(card.id)) {
    cycle.value.cloud_asana_ids.push(card.id);
    cycle.value.cloud_asanas.push(card);
    schedule();
  }
  notice.value = `«${card.name}» добавлена в облако. Можно выбрать ещё асаны.`;
}
function removeCloud(id: string) {
  if (!cycle.value) return;
  pointerDrag.cancel();
  cycle.value.cloud_asana_ids = cycle.value.cloud_asana_ids.filter((value) => value !== id);
  cycle.value.cloud_asanas = cycle.value.cloud_asanas.filter((value) => value.id !== id);
  schedule();
}
function cloudTargetAtPoint(point: PaletteDragPoint): MesocycleTarget | null {
  const frameTarget = mesocycleCellAtPoint(frames, point);
  if (frameTarget) return frameTarget;
  const element = document
    .elementFromPoint(point.x, point.y)
    ?.closest<HTMLElement>('[data-cycle-preview-cell]');
  return element?.dataset.documentId && element.dataset.cellId
    ? {
        documentId: element.dataset.documentId,
        cellId: element.dataset.cellId,
        label: element.dataset.label || 'Пустая ячейка',
      }
    : null;
}
async function insertCloud(card: Asana, destination = target.value) {
  if (cloudDisabled.value || !cycle.value?.cloud_asana_ids.includes(card.id)) return;
  if (!destination) {
    notice.value = 'Сначала нажмите на ячейку нужной недели.';
    return;
  }
  if (card.archived_at) {
    notice.value = 'Эта асана в архиве. Восстановите её в библиотеке.';
    return;
  }
  if (!frames.has(destination.documentId)) {
    const week = cycle.value?.weeks.find((item) => item.document_id === destination.documentId);
    const cell = week?.document.blocks
      .flatMap((block) => block.rows.flatMap((row) => row.cells))
      .find((item) => item.id === destination.cellId);
    if (!week || !cell || !(await saveMetadata())) return;
    const occupied = !!(cell.card_snapshot_id || cell.custom_name);
    if (
      occupied &&
      !window.confirm(`Заменить «${destination.label}»? Позиционные стрелки и скобки сохранятся.`)
    )
      return;
    const operationId = uid();
    try {
      const response = await api<{ data: Cycle }>(`/api/mesocycles/${props.mesocycleId}/insert`, {
        method: 'POST',
        body: JSON.stringify({
          expected_revision: cycle.value!.revision,
          idempotency_key: operationId,
          document_id: week.document_id,
          expected_document_revision: week.document.revision,
          cell_id: cell.id,
          asana_id: card.id,
          confirm_replace: occupied,
        }),
      });
      cycle.value = response.data;
      acknowledged.value = signature();
      notice.value = 'Асана вставлена. Откройте занятие для дальнейшего редактирования.';
      previewUndo.value = operationId;
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Не удалось вставить асану. Обновите превью.';
    }
    return;
  }
  const sequence = (insertionSequences.get(destination.documentId) || 0) + 1;
  insertionSequences.set(destination.documentId, sequence);
  sendToWeek(destination.documentId, {
    type: 'ribka:meso-asana',
    cellId: destination.cellId,
    asanaId: card.id,
    name: card.name.slice(0, 160),
    requestId: uid(),
    sequence,
  });
}
async function undoPreviewInsert() {
  if (!previewUndo.value) return;
  try {
    const response = await api<{ data: Cycle }>(`/api/mesocycles/${props.mesocycleId}/undo-insert`, {
      method: 'POST',
      body: JSON.stringify({ operation_id: previewUndo.value, idempotency_key: uid() }),
    });
    cycle.value = response.data;
    acknowledged.value = signature();
    previewUndo.value = null;
    notice.value = 'Вставка отменена.';
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось отменить вставку.';
  }
}
function receive(event: MessageEvent) {
  if (event.origin !== window.location.origin || !isMesocycleEvent(event.data)) return;
  const data = event.data,
    documentId = data.documentId;
  if (
    !frames.get(documentId) ||
    event.source !== frames.get(documentId)?.contentWindow ||
    data.sessionId !== frameSessions.get(documentId)
  )
    return;
  if (data.type === 'ribka:meso-cell') {
    activeDocumentId.value = documentId;
    target.value = { documentId, cellId: data.cellId, label: data.label };
  }
  if (data.type === 'ribka:meso-clear-target' && target.value?.documentId === documentId) target.value = null;
  if (data.type === 'ribka:meso-status') weekDirty.value[documentId] = data.dirty || data.pending > 0;
  if (data.type === 'ribka:meso-saved') {
    const request = weekSaveRequests.get(documentId);
    if (request && request.requestId === data.requestId) {
      clearTimeout(request.timer);
      request.resolve(data.saved);
      weekSaveRequests.delete(documentId);
    }
  }
}
function saveWeek(documentId: string): Promise<boolean> {
  const frame = frames.get(documentId);
  if (!frame?.contentWindow) return Promise.resolve(!weekDirty.value[documentId]);
  const previous = weekSaveRequests.get(documentId);
  if (previous) {
    clearTimeout(previous.timer);
    previous.resolve(false);
  }
  return new Promise((resolve) => {
    const requestId = uid();
    const timer = setTimeout(() => {
      weekSaveRequests.delete(documentId);
      resolve(false);
    }, 35000);
    weekSaveRequests.set(documentId, { requestId, resolve, timer });
    sendToWeek(documentId, { type: 'ribka:meso-save', requestId });
  });
}
async function saveAll() {
  if (allSaving.value || !cycle.value) return;
  allSaving.value = true;
  const saved = await saveMetadata();
  if (!saved) {
    allSaving.value = false;
    return;
  }
  notice.value = 'Сохраняем занятия…';
  const results: boolean[] = [];
  for (const week of cycle.value.weeks) results.push(await saveWeek(week.document_id));
  notice.value = results.every(Boolean)
    ? 'Тема, облако и все занятия сохранены.'
    : 'Не удалось сохранить одно из открытых занятий. Проверьте сообщение в его редакторе.';
  allSaving.value = false;
}
function openReplace(week: Week) {
  replacementKey.value = uid();
  replacement.value = week;
  replacementCandidate.value = null;
  sourceId.value = '';
}
async function chooseSource(item: PracticeChoice) {
  sourceId.value = item.id;
  sources.value = [item];
  replacementCandidate.value = item;
}
async function replaceWeek() {
  if (!cycle.value || !replacement.value || !sourceId.value || replaceBusy.value) return;
  if (!(await saveMetadata())) return;
  replaceBusy.value = true;
  error.value = '';
  try {
    if (!(await saveWeek(replacement.value.document_id)))
      throw new Error('Не удалось сохранить предыдущую неделю. Проверьте её редактор перед заменой.');
    const response = await api<{ data: Cycle }>(`/api/mesocycles/${props.mesocycleId}/replace-week`, {
      method: 'POST',
      body: JSON.stringify({
        expected_revision: cycle.value.revision,
        position: replacement.value.position,
        source_document_id: sourceId.value,
        expected_source_revision: sources.value.find((source) => source.id === sourceId.value)?.revision,
        idempotency_key: replacementKey.value,
      }),
    });
    cycle.value = response.data;
    activeDocumentId.value =
      response.data.weeks.find((week) => week.position === replacement.value?.position)?.document_id ||
      response.data.weeks[0]?.document_id ||
      null;
    acknowledged.value = signature();
    replacement.value = null;
    replacementCandidate.value = null;
    target.value = null;
  } catch (e) {
    if (e instanceof ApiError && e.status === 409) conflict.value = true;
    error.value = e instanceof Error ? e.message : 'Не удалось заменить неделю.';
  } finally {
    replaceBusy.value = false;
  }
}
async function changeStructure(
  positions: { id?: string; title?: string | null }[],
  removed: { id: string; action: 'keep' | 'archive'; expected_revision: number }[] = [],
) {
  if (!cycle.value || switching.value || positions.length < 1 || positions.length > 52) return;
  if (!(await saveMetadata()) || !(await saveMountedWeeks())) return;
  switching.value = true;
  error.value = '';
  try {
    const response = await api<{ data: Cycle }>(`/api/mesocycles/${props.mesocycleId}/structure`, {
      method: 'POST',
      body: JSON.stringify({
        expected_revision: cycle.value.revision,
        idempotency_key: structureKey.value,
        positions,
        removed,
      }),
    });
    cycle.value = response.data;
    boardPage.value = Math.min(boardPage.value, Math.max(0, Math.ceil(response.data.weeks.length / 5) - 1));
    acknowledged.value = signature();
    structureKey.value = uid();
    if (!response.data.weeks.some((week) => week.document_id === activeDocumentId.value))
      activeDocumentId.value = response.data.weeks[0]?.document_id || null;
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось изменить структуру.';
  } finally {
    switching.value = false;
  }
}
function movePosition(week: Week, offset: number) {
  if (!cycle.value) return;
  const positions = cycle.value.weeks.map(({ id, title }) => ({ id, title }));
  const targetIndex = week.position + offset;
  if (targetIndex < 0 || targetIndex >= positions.length) return;
  [positions[week.position], positions[targetIndex]] = [positions[targetIndex]!, positions[week.position]!];
  void changeStructure(positions);
}
function renamePosition(week: Week) {
  const title = window.prompt('Название занятия в цикле', week.title || `Занятие ${week.position + 1}`);
  if (title !== null && cycle.value)
    void changeStructure(
      cycle.value.weeks.map((item) => ({
        id: item.id,
        title: item.id === week.id ? title.slice(0, 160) : item.title,
      })),
    );
}
async function removePosition(week: Week) {
  if (!cycle.value || cycle.value.weeks.length <= 1) return;
  if (!window.confirm('Убрать позицию из цикла? Само занятие и изображения сохранятся отдельно.')) return;
  if (!(await saveMountedWeeks())) return;
  const document = (await api<{ data: Practice }>(`/api/practices/${week.document_id}`)).data;
  const action = window.confirm('Сохранить занятие в активных практиках? Отмена предложит архивирование.')
    ? 'keep'
    : window.confirm('Архивировать занятие? Его можно будет восстановить.')
      ? 'archive'
      : null;
  if (action)
    await changeStructure(
      cycle.value.weeks.filter((item) => item.id !== week.id).map(({ id, title }) => ({ id, title })),
      [{ id: week.id, action, expected_revision: document.revision }],
    );
}
async function duplicateCycle(asTemplate: boolean) {
  if (!cycle.value || !(await saveMetadata()) || !(await saveMountedWeeks())) return;
  const title = window.prompt(
    asTemplate ? 'Название шаблона цикла' : 'Название новой программы',
    cycle.value.title,
  );
  if (!title?.trim()) return;
  try {
    const response = await api<{ data: Cycle }>(`/api/mesocycles/${props.mesocycleId}/duplicate`, {
      method: 'POST',
      body: JSON.stringify({
        title,
        kind: asTemplate ? 'template' : 'cycle',
        expected_revision: cycle.value.revision,
        idempotency_key: uid(),
      }),
    });
    router.visit(`/mesocycles/${response.data.id}/edit`);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось создать копию.';
  }
}
function beforeUnload(event: BeforeUnloadEvent) {
  if (dirty.value || Object.values(weekDirty.value).some(Boolean)) {
    event.preventDefault();
    event.returnValue = '';
  }
}
function reloadCurrent() {
  if (
    (!dirty.value && !Object.values(weekDirty.value).some(Boolean)) ||
    window.confirm(
      'Загрузить актуальную версию мезоцикла? Локальные изменения темы и облака будут потеряны. Если другая вкладка заменила неделю, её несохранённые изменения тоже будут потеряны.',
    )
  )
    void load();
}
let removeGuard: (() => void) | undefined;
onMounted(() => {
  void load();
  window.addEventListener('message', receive);
  window.addEventListener('beforeunload', beforeUnload);
  removeGuard = router.on(
    'before',
    () =>
      (!dirty.value && !Object.values(weekDirty.value).some(Boolean)) ||
      window.confirm('Есть несохранённые изменения в мезоцикле или его неделях. Покинуть экран?'),
  );
});
onBeforeUnmount(() => {
  pointerDrag.cancel();
  alive = false;
  clearTimeout(timer);
  window.removeEventListener('message', receive);
  window.removeEventListener('beforeunload', beforeUnload);
  removeGuard?.();
  for (const request of weekSaveRequests.values()) {
    clearTimeout(request.timer);
    request.resolve(false);
  }
  weekSaveRequests.clear();
});
</script>
<template>
  <Head :title="cycle?.title || 'Мезоцикл'" />
  <AppShell section="practices" wide>
    <Link :href="cycle?.kind === 'template' ? '/templates?type=cycle' : '/mesocycles'" class="btn btn-ghost"
      ><ArrowLeft :size="16" />Программы и циклы</Link
    >
    <p v-if="loading" class="muted" role="status">Открываем занятия…</p>
    <p v-if="error" class="error-banner" role="alert">
      {{ error }}
      <button v-if="conflict" class="btn btn-secondary" @click="reloadCurrent">
        <RefreshCw :size="16" />Загрузить актуальную</button
      ><button v-else class="btn btn-ghost" @click="cycle ? saveMetadata() : load()">Повторить</button>
    </p>
    <template v-if="cycle">
      <header class="cycle-heading">
        <div>
          <input
            v-model="cycle.title"
            class="cycle-title"
            maxlength="160"
            aria-label="Название мезоцикла"
            :disabled="!!cycle.archived_at || conflict"
            @input="schedule"
          /><label class="cycle-theme"
            >Тема месяца
            <input
              v-model="cycle.theme"
              class="input"
              maxlength="240"
              :disabled="!!cycle.archived_at || conflict"
              @input="schedule"
          /></label>
        </div>
        <button
          class="btn btn-primary"
          :disabled="saving || allSaving || conflict || !!cycle.archived_at"
          @click="saveAll"
        >
          <Save :size="17" />Сохранить всё
        </button>
        <button
          class="btn btn-secondary"
          :disabled="switching || conflict || !!cycle.archived_at"
          @click="duplicateCycle(cycle.kind !== 'template')"
        >
          {{ cycle.kind === 'template' ? 'Создать программу' : 'Сохранить как шаблон цикла' }}
        </button>
      </header>
      <div class="cycle-cloud">
        <header>
          <strong>Облако асан</strong><span role="status">{{ status }}</span
          ><button
            class="btn btn-secondary"
            :disabled="conflict || !!cycle.archived_at"
            @click="cloudOpen = true"
          >
            <Plus :size="16" />Добавить асаны
          </button>
        </header>
        <p v-if="!cycle.cloud_asanas.length" class="muted">
          Добавьте асаны в общий буфер. Выберите ячейку недели, затем нажмите на асану в облаке.
        </p>
        <div class="cloud-cards">
          <div v-for="card in cycle.cloud_asanas" :key="card.id" class="cloud-card">
            <button
              :title="card.name"
              :disabled="!!card.archived_at || cloudDisabled"
              draggable="false"
              @dragstart.prevent
              @pointerdown="pointerDrag.start($event, card)"
              @click="clickCloud($event, card)"
            >
              <span
                data-palette-drag-handle
                class="cloud-drag-handle"
                title="Перетащить асану"
                aria-hidden="true"
                ><GripVertical :size="17"
              /></span>
              <img :src="card.thumbnail_url || card.image_url" :alt="card.name" draggable="false" /><span>{{
                card.name
              }}</span></button
            ><button
              class="cloud-remove"
              :disabled="conflict || !!cycle.archived_at"
              :aria-label="`Убрать ${card.name} из облака`"
              @click="removeCloud(card.id)"
            >
              <X :size="13" />
            </button>
          </div>
        </div>
        <p class="cloud-target">
          {{
            dragPreview
              ? dragTarget
                ? 'Отпустите асану в подсвеченную ячейку. Esc — отмена.'
                : 'Перетащите асану в ячейку любой недели. Esc — отмена.'
              : target
                ? `Неделя ${(selectedWeek?.position ?? 0) + 1}: выбрана ячейка «${target.label}». Нажмите на асану выше.`
                : 'Нажмите на ячейку любой недели для заполнения из общего облака.'
          }}
        </p>
        <p v-if="target || dragPreview" class="cloud-help">
          Перетащите рисунок в любую неделю. На сенсорном экране тяните за значок ⋮⋮. Можно выбрать ячейку и
          нажать на асану; с клавиатуры используйте Tab и Enter.
        </p>
      </div>
      <p v-if="notice" class="success-banner" role="status">{{ notice }}</p>
      <button v-if="previewUndo" class="btn btn-secondary" @click="undoPreviewInsert">
        Отменить вставку в превью
      </button>
      <div class="cycle-structure-tools">
        <button
          class="btn btn-secondary"
          :disabled="replaceBusy || conflict || !!cycle.archived_at"
          @click="replacementPositionOpen = true"
        >
          Добавить практику
        </button>
        <span>{{ cycle.weeks.length }} занятий</span
        ><button
          class="btn btn-secondary"
          :disabled="switching || cycle.weeks.length >= 52 || conflict || !!cycle.archived_at"
          @click="changeStructure([...cycle.weeks.map(({ id, title }) => ({ id, title })), {}])"
        >
          Добавить занятие
        </button>
      </div>
      <div
        ref="weeksBoard"
        class="weeks-board"
        :style="{ '--visible-weeks': Math.min(5, visibleWeeks.length) }"
      >
        <section
          v-for="week in visibleWeeks"
          :key="week.document_id"
          class="week-column"
          :class="{ 'active-week': activeDocumentId === week.document_id }"
        >
          <header>
            <h2>{{ week.title || `Занятие ${week.position + 1}` }}</h2>
            <ActionMenu :label="`Действия с занятием ${week.position + 1}`">
              <button :disabled="replaceBusy || conflict || !!cycle.archived_at" @click="openReplace(week)">
                Добавить практику из существующих
              </button>
              <a :href="`/practices/${week.document_id}/edit`" target="_blank" rel="noopener"
                >Открыть в отдельном окне</a
              >
              <button :disabled="replaceBusy || conflict || !!cycle.archived_at" @click="openReplace(week)">
                Копировать другую последовательность
              </button>
              <button :disabled="switching || conflict || !!cycle.archived_at" @click="renamePosition(week)">
                Переименовать
              </button>
              <button
                :disabled="switching || week.position === 0 || conflict || !!cycle.archived_at"
                @click="movePosition(week, -1)"
              >
                Переместить левее
              </button>
              <button
                :disabled="
                  switching || week.position === cycle.weeks.length - 1 || conflict || !!cycle.archived_at
                "
                @click="movePosition(week, 1)"
              >
                Переместить правее
              </button>
              <button
                :disabled="switching || cycle.weeks.length <= 1 || conflict || !!cycle.archived_at"
                @click="removePosition(week)"
              >
                Убрать из цикла
              </button>
            </ActionMenu>
          </header>
          <iframe
            :ref="
              (element) => {
                if (element) frames.set(week.document_id, element as HTMLIFrameElement);
                else frames.delete(week.document_id);
              }
            "
            :src="frameSource(week.document_id)"
            :title="`Редактор занятия ${week.position + 1}`"
          />
        </section>
      </div>
      <div v-if="cycle.weeks.length > 5" class="cycle-structure-tools">
        <button
          class="btn btn-secondary"
          :disabled="boardPage <= 0 || switching"
          @click="changeBoardPage(boardPage - 1)"
        >
          Предыдущие занятия</button
        ><span>{{ boardPage + 1 }} / {{ Math.ceil(cycle.weeks.length / 5) }}</span
        ><button
          class="btn btn-secondary"
          :disabled="(boardPage + 1) * 5 >= cycle.weeks.length || switching"
          @click="changeBoardPage(boardPage + 1)"
        >
          Следующие занятия
        </button>
      </div>
      <p class="muted board-help">
        Все пять занятий доступны для редактирования одновременно. Перед сменой страницы или структуры
        открытые занятия сохраняются.
      </p>
    </template>
    <Modal
      :open="replacementPositionOpen"
      title="Куда добавить практику"
      @close="replacementPositionOpen = false"
    >
      <p class="muted">
        Выберите занятие цикла. Создадим независимую копию выбранной далее практики; прежнее занятие
        сохранится отдельно.
      </p>
      <div class="replacement-positions">
        <button
          v-for="week in cycle?.weeks || []"
          :key="week.id"
          class="btn btn-secondary"
          @click="
            replacementPositionOpen = false;
            openReplace(week);
          "
        >
          {{ week.title || `Занятие ${week.position + 1}` }}
        </button>
      </div>
    </Modal>
    <Modal :open="cloudOpen" title="Добавить асаны в облако" @close="cloudOpen = false"
      ><p class="muted">Выберите несколько асан. Они будут доступны для каждой недели.</p>
      <AsanaPalette
        v-if="cloudOpen"
        :document-id="mesocycleId"
        :active="true"
        :mobile-open="false"
        embedded
        @select="addCloud"
      />
      <p v-if="notice" class="success-banner" role="status">{{ notice }}</p>
      <template #footer
        ><button class="btn btn-primary" @click="cloudOpen = false">Готово</button></template
      ></Modal
    >
    <Modal
      :open="!!replacement && !!replacementCandidate"
      title="Добавить выбранную практику"
      @close="!replaceBusy && (replacementCandidate = null)"
    >
      <p>
        Добавить «{{ replacementCandidate?.title }}» в
        {{ replacement?.title || `занятие ${(replacement?.position ?? 0) + 1}` }}?
      </p>
      <p class="muted">
        Сначала сохраним текущее занятие, затем создадим независимую копию выбранной практики. Прежняя
        практика останется в общем списке. Скобки, стрелки и изображения копируются вместе с
        последовательностью.
      </p>
      <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
      <template #footer
        ><button class="btn btn-secondary" :disabled="replaceBusy" @click="replacementCandidate = null">
          Назад к выбору</button
        ><button class="btn btn-primary" :disabled="replaceBusy" @click="replaceWeek">
          {{ replaceBusy ? 'Добавляем…' : 'Добавить практику' }}
        </button></template
      >
    </Modal>
    <PracticePicker
      :external-error="error"
      :open="!!replacement && !replacementCandidate"
      :busy="replaceBusy"
      templates
      title="Добавить существующую практику"
      description="Выберите последовательность для занятия. Создадим независимую копию; прежняя практика останется в общем списке."
      @close="replacement = null"
      @choose="chooseSource"
    />
  </AppShell>
  <Teleport to="body">
    <div v-if="dragPreview" data-mesocycle-drag-overlay class="cloud-drag-overlay" aria-hidden="true">
      <div class="cloud-drag-preview" :class="{ 'has-target': !!dragTarget }" :style="dragPreviewStyle">
        <img :src="dragPreview.card.thumbnail_url || dragPreview.card.image_url" alt="" />
        <span>{{ dragPreview.card.name }}</span>
      </div>
    </div>
  </Teleport>
</template>
<style scoped>
.cycle-structure-tools,
.position-actions {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
  margin: 12px 0;
}
.week-preview {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 22px;
  min-height: 300px;
}
.preview-poses {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 6px;
}
.preview-poses > button {
  aspect-ratio: 1;
  display: grid;
  place-items: center;
  background: #fff;
  border: 1px solid #e4eadf;
  border-radius: 8px;
}
.preview-poses > button.preview-target {
  outline: 3px solid #75a958;
}
.preview-poses img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
.active-week {
  outline: 2px solid #74a960;
}
.weeks-board {
  grid-template-columns: repeat(var(--visible-weeks), minmax(0, 1fr)) !important;
  align-items: start;
}
.week-column {
  min-width: 0;
}
.week-column > header h2 {
  min-width: 0;
  overflow-wrap: anywhere;
  font-size: 14px;
}
.week-column iframe {
  width: 100%;
  min-width: 0;
}
.replacement-positions {
  display: grid;
  gap: 10px;
  margin-top: 18px;
}
@media (max-width: 900px) {
  .weeks-board {
    grid-template-columns: repeat(5, 280px) !important;
    overflow-x: auto;
  }
}
.cycle-heading {
  display: flex;
  gap: 24px;
  align-items: center;
  margin: 12px 0 20px;
}
.cycle-heading > div {
  flex: 1;
}
.cycle-title {
  display: block;
  width: 100%;
  border: 0;
  background: transparent;
  font-size: 28px;
  font-weight: 700;
  padding: 6px 0;
  color: var(--text);
}
.cycle-theme {
  display: flex;
  gap: 12px;
  align-items: center;
  color: var(--muted);
  font-size: 13px;
}
.cycle-theme .input {
  max-width: 700px;
}
.cycle-cloud {
  position: sticky;
  top: 0;
  z-index: 30;
  max-height: 210px;
  overflow: auto;
  background: white;
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 16px;
  margin: 16px 0;
}
.cycle-cloud > header {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 10px;
}
.cycle-cloud > header > span {
  margin-left: auto;
  font-size: 12px;
  color: var(--muted);
}
.cloud-cards {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding: 6px 0;
}
.cloud-card {
  position: relative;
  flex: none;
  width: 92px;
}
.cloud-card > button:first-child {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  background: white;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 8px 5px;
  width: 100%;
  height: 84px;
}
.cloud-drag-handle {
  position: absolute;
  left: 2px;
  top: 2px;
  display: grid;
  place-items: center;
  width: 26px;
  height: 26px;
  border-radius: 6px;
  background: #edf5e7;
  color: #397a28;
  cursor: grab;
  touch-action: none;
}
.cloud-help {
  font-size: 12px;
  color: var(--muted);
  margin: 8px 0 0;
}
.cloud-drag-overlay {
  position: fixed;
  inset: 0;
  z-index: 10000;
  cursor: grabbing;
  touch-action: none;
}
.cloud-drag-preview {
  position: fixed;
  display: grid;
  justify-items: center;
  gap: 5px;
  width: 120px;
  padding: 8px;
  border: 2px solid #a0ad99;
  border-radius: 12px;
  background: white;
  box-shadow: 0 5px 24px #0003;
  pointer-events: none;
}
.cloud-drag-preview.has-target {
  border-color: #4d9638;
}
.cloud-drag-preview img {
  width: 80px;
  height: 80px;
  object-fit: contain;
}
.cloud-drag-preview span {
  font-size: 12px;
  max-width: 104px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.cloud-card img {
  width: 50px;
  height: 50px;
  object-fit: contain;
}
.cloud-card span {
  font-size: 10px;
  max-width: 80px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.cloud-remove {
  position: absolute;
  right: 0;
  top: 0;
  background: white;
  border: 1px solid var(--border);
  border-radius: 50%;
  width: 21px;
  height: 21px;
  display: grid;
  place-items: center;
}
.cloud-target {
  color: #397a28;
  font-size: 13px;
  margin-top: 8px;
}
.weeks-board {
  display: grid;
  grid-template-columns: repeat(5, minmax(200px, 1fr));
  gap: 14px;
  overflow-x: auto;
  padding-bottom: 12px;
}
.week-column {
  border: 1px solid var(--border);
  border-radius: 15px;
  overflow: visible;
  background: white;
}
.week-column > header {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 12px;
  background: #edf5e7;
  border-radius: 15px 15px 0 0;
}
.week-column h2 {
  flex: 1;
  font-size: 17px;
  margin: 0;
}
.week-column iframe {
  display: block;
  width: 100%;
  height: 720px;
  max-height: 78vh;
  border: 0;
  background: #f7f9f6;
}
.board-help {
  font-size: 12px;
  line-height: 1.7;
  margin: 14px 0;
}
@media (max-width: 650px) {
  .cycle-heading {
    flex-wrap: wrap;
  }
  .cycle-heading > div {
    width: 100%;
    flex: none;
  }
  .cycle-cloud > header {
    flex-wrap: wrap;
  }
  .cycle-cloud > header > span {
    margin-left: 0;
  }
}
</style>
