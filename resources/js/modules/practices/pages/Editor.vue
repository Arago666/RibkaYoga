<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
  ArrowLeft,
  ArrowUp,
  ArrowDown,
  Check,
  Cloud,
  Copy,
  Eye,
  GripVertical,
  Library,
  MoreHorizontal,
  Plus,
  Printer,
  Redo2,
  Save,
  StickyNote,
  Trash2,
  Undo2,
  X,
  ArrowRight,
  RefreshCw,
  Bookmark,
  Brackets,
  UserPlus,
} from '@lucide/vue';
import AppShell from '@/shared/AppShell.vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import AsanaPalette from '../components/AsanaPalette.vue';
import CellPicker from '../components/CellPicker.vue';
import SequenceRow from '../components/SequenceRow.vue';
import ArrowSymbol from '../components/ArrowSymbol.vue';
import DocumentSharingDialog from '../components/DocumentSharingDialog.vue';
const sharingOpen = ref(false);
import { useEditor } from '../composables/useEditor';
import type { PaletteDragPoint } from '../composables/usePaletteDrag';
import {
  isMesocycleCommand,
  isMesocycleId,
  type MesocycleCommand,
  type MesocycleEventPayload,
} from '../mesocycleChannel';
import {
  clone,
  arrowLabels,
  type ArrowDirection,
  cellFilled,
  cellLabel,
  copyBlock,
  copyRow,
  emptyBlock,
  emptyCell,
  emptyRow,
  payload,
  uid,
  type Block,
  type Cell,
  type Practice,
  type Row,
  type RowTemplate,
  type Snapshot,
  type TransitionRelation,
} from '../types';
import { relationLabels, type Asana, type AsanaRelation, type Page } from '@/modules/catalog/types';

const props = withDefaults(defineProps<{ documentId: string; rowMode?: boolean }>(), { rowMode: false });
const embedded = new URL(window.location.href).searchParams.get('embedded') === '1';
const sessionQuery = new URL(window.location.href).searchParams.get('mesocycle_session');
const mesocycleSession = embedded && isMesocycleId(sessionQuery) ? sessionQuery : null;
function sendToMesocycle(data: MesocycleEventPayload) {
  if (!mesocycleSession || window.parent === window) return;
  window.parent.postMessage(
    { ...data, documentId: props.documentId, sessionId: mesocycleSession },
    window.location.origin,
  );
}
const mesocycleHoverCell = ref<string | null>(null);
let consumedMesocycleSequence = 0;
const editor = useEditor(props.documentId, props.rowMode);
const {
  document: doc,
  loading,
  saving,
  error,
  conflict,
  pending,
  dirty,
  status,
  undoStack,
  redoStack,
} = editor;
const activeCell = ref<string | null>(null),
  mobilePalette = ref(false),
  notice = ref('');
// Keep the action target while its temporary focus highlight has already ended.
const highlightedCell = ref<string | null>(null);
const toolbarElement = ref<HTMLElement | null>(null);
const toolbarHeight = ref(0);
watch(
  toolbarElement,
  (element, _, onCleanup) => {
    if (!element) return;
    const measure = () => (toolbarHeight.value = element.getBoundingClientRect().height);
    const observer = new ResizeObserver(measure);
    observer.observe(element);
    measure();
    onCleanup(() => observer.disconnect());
  },
  { flush: 'post' },
);
const activeBlock = ref<string | null>(null),
  activeRow = ref<string | null>(null);
const pendingCells = ref(new Set<string>()),
  tokens = new Map<string, string>();
const navigating = ref(false);
const pointerDragging = ref(false);
const pointerTarget = ref<{ kind: 'cell' | 'row' | 'block'; id: string } | null>(null);
const pickerOpen = ref(false),
  pickerCellId = ref<string | null>(null),
  pickerError = ref('');
let pickerSession = 0;
const pickerCell = computed(() => (pickerCellId.value ? findCell(pickerCellId.value)?.cell || null : null));
const transfer = ref<{ mode: 'move' | 'copy'; source: string } | null>(null);
const grouping = ref(false);
const arrowMode = ref(false);
const arrowChoice = ref<ArrowDirection | null>('cross');
const annotationMode = computed(() => grouping.value || arrowMode.value);
const arrowDirections: ArrowDirection[] = ['cross', 'both'];
function beginArrows() {
  if (locked.value) return;
  cancelGrouping();
  closePicker();
  transfer.value = null;
  mobilePalette.value = false;
  arrowMode.value = true;
}
const groupStart = ref<{ rowId: string; cellId: string } | null>(null);
const groupEnd = ref<string | null>(null);
const groupIssue = ref('');
const groupRange = computed(() => {
  if (!groupStart.value) return null;
  const row = findRow(groupStart.value.rowId)?.row;
  if (!row) return null;
  const first = row.cells.findIndex((cell) => cell.id === groupStart.value!.cellId);
  const last = groupEnd.value ? row.cells.findIndex((cell) => cell.id === groupEnd.value) : first;
  if (first < 0 || last < 0) return null;
  const start = Math.min(first, last),
    end = Math.max(first, last);
  return { row, start, end, cells: row.cells.slice(start, end + 1) };
});
const groupPreviewCells = computed(() => new Set(groupRange.value?.cells.map((cell) => cell.id) || []));
const groupRangeError = computed(() => {
  if (!groupEnd.value || !groupRange.value) return '';
  if (groupRange.value.cells.length < 2) return 'Выберите минимум две ячейки одной строки.';
  if (groupRange.value.cells.some((cell) => cell.group_key))
    return 'Диапазон пересекает существующую группу. Сначала снимите её скобки или выберите другой диапазон.';
  return '';
});
const canGroup = computed(
  () => !!groupEnd.value && !!groupRange.value && !groupRangeError.value && !groupIssue.value,
);
const modalKind = ref(''),
  modalTitle = ref(''),
  modalText = ref(''),
  modalValue = ref(''),
  modalBusy = ref(false),
  modalError = ref('');
const savedRows = ref<RowTemplate[]>([]),
  savedQuery = ref(''),
  savedPage = ref(1),
  savedLastPage = ref(1);
const savedKind = ref<'row' | 'transition'>('row');
const transitionTarget = ref<string | null>(null);
const rowModal = computed(() => modalKind.value === 'rows' || modalKind.value === 'transitions');
interface SnapshotChange {
  snapshot_id: string;
  source_asana_id: string;
  source_revision: number;
  before_name: string;
  before_image_url: string;
  after_name: string;
  after_image_url: string;
}
const snapshotChanges = ref<SnapshotChange[]>([]);
const snapshotRevision = ref(0);
const variants = ref<AsanaRelation[]>([]);
const variantTarget = ref<string | null>(null);
const transitionOptions = ref<RowTemplate[]>([]);
const transitionRelationId = ref('');
const transitionRelationKind = ref<TransitionRelation['kind']>('simpler');
const transitionRelations = ref<TransitionRelation[]>([]);
async function editTransitionRelations() {
  if (!doc.value || modalBusy.value) return;
  modalKind.value = 'transition-relations';
  modalTitle.value = 'Варианты связки';
  modalBusy.value = true;
  modalError.value = '';
  transitionRelationId.value = '';
  transitionRelations.value = clone(doc.value.transition_relations || []);
  try {
    transitionOptions.value = [];
    let page = 1,
      last = 1;
    do {
      const response = await api<Page<RowTemplate>>(
        `/api/row-templates?kind=transition&per_page=100&page=${page}`,
      );
      transitionOptions.value.push(...response.data.filter((item) => item.id !== props.documentId));
      last = response.last_page;
      page++;
    } while (page <= last);
  } catch (e) {
    modalError.value = e instanceof Error ? e.message : 'Не удалось загрузить связки.';
  } finally {
    modalBusy.value = false;
  }
}
function addTransitionRelation() {
  const option = transitionOptions.value.find((item) => item.id === transitionRelationId.value);
  if (
    !option ||
    transitionRelations.value.some(
      (item) => item.transition_id === option.id && item.kind === transitionRelationKind.value,
    )
  )
    return;
  transitionRelations.value.push({
    transition_id: option.id,
    title: option.title,
    kind: transitionRelationKind.value,
  });
  transitionRelationId.value = '';
}
function applyTransitionRelations() {
  edit((document) => {
    document.transition_relations = clone(transitionRelations.value);
  });
  modalKind.value = '';
}
const insertion = ref<{ block: string; after: string | null } | null>(null);
const relocation = ref<{ block: string; row: string } | null>(null),
  destinationBlock = ref('');
let modalAction: ((value: string) => void | Promise<void>) | null = null;
let removeNavigateGuard: (() => void) | undefined;
let leaving = false,
  alive = true;
const selected = computed(() => (activeCell.value ? findCell(activeCell.value) : null));
const transitionDocument = computed(() => props.rowMode && doc.value?.row_template_kind === 'transition');
watch(
  () => (selected.value ? cellLabel(selected.value.cell) : null),
  (label) => {
    if (!embedded) return;
    if (label !== null && activeCell.value)
      sendToMesocycle({ type: 'ribka:meso-cell', cellId: activeCell.value, label });
    else sendToMesocycle({ type: 'ribka:meso-clear-target' });
  },
);
watch(activeCell, (id) => {
  if (!id) highlightedCell.value = null;
});
const counts = computed(() => ({
  rows: doc.value?.blocks.reduce((n, block) => n + block.rows.length, 0) ?? 0,
  cards:
    doc.value?.blocks.reduce(
      (n, block) => n + block.rows.reduce((m, row) => m + row.cells.filter(cellFilled).length, 0),
      0,
    ) ?? 0,
}));
const readOnly = computed(() => !!doc.value?.archived_at);
const locked = computed(() => readOnly.value || navigating.value || loading.value);
function findCell(id: string) {
  for (const block of doc.value?.blocks ?? [])
    for (const row of block.rows) {
      const cell = row.cells.find((item) => item.id === id);
      if (cell) return { block, row, cell };
    }
  return null;
}
function findRow(id: string) {
  for (const block of doc.value?.blocks ?? []) {
    const row = block.rows.find((item) => item.id === id);
    if (row) return { block, row };
  }
  return null;
}
function message(text: string) {
  notice.value = text;
}
function resetGroupRange() {
  groupStart.value = null;
  groupEnd.value = null;
  groupIssue.value = '';
}
function cancelGrouping() {
  grouping.value = false;
  highlightedCell.value = null;
  resetGroupRange();
}
function beginGrouping() {
  if (locked.value) return;
  arrowMode.value = false;
  closePicker();
  transfer.value = null;
  mobilePalette.value = false;
  resetGroupRange();
  grouping.value = true;
}
function selectGroupEndpoint(row: Row, cell: Cell) {
  groupIssue.value = '';
  if (!groupStart.value || !findCell(groupStart.value.cellId)) {
    groupStart.value = { rowId: row.id, cellId: cell.id };
    groupEnd.value = null;
    return;
  }
  if (row.id !== groupStart.value.rowId) {
    groupIssue.value =
      'Первая и последняя ячейки должны быть в одной строке. Выберите конец в начатой строке или начните заново.';
    return;
  }
  groupEnd.value = cell.id;
}
function confirmGrouping() {
  if (locked.value || !canGroup.value || !groupRange.value) return;
  const ids = new Set(groupRange.value.cells.map((cell) => cell.id));
  const rowId = groupRange.value.row.id;
  edit(() => {
    const row = findRow(rowId)?.row;
    if (!row || row.cells.filter((cell) => ids.has(cell.id)).some((cell) => cell.group_key)) return;
    const key = uid();
    for (const cell of row.cells) if (ids.has(cell.id)) cell.group_key = key;
  });
  cancelGrouping();
  message('Ячейки объединены. Содержимое можно менять независимо от группы.');
}
function removeSelectedGroup() {
  const current = selected.value;
  if (locked.value || !current?.cell.group_key) return;
  const rowId = current.row.id,
    key = current.cell.group_key;
  edit(() => {
    for (const cell of findRow(rowId)?.row.cells || []) if (cell.group_key === key) cell.group_key = null;
  });
  groupIssue.value = '';
  message('Скобки сняты. Содержимое ячеек сохранено.');
}
// Undo, reload and rollback replace the document; an uncommitted range belongs to its old positions.
watch(doc, () => {
  arrowMode.value = false;
  cancelGrouping();
  notice.value = '';
});
function syncPending() {
  pendingCells.value = new Set(tokens.keys());
  pending.value = tokens.size;
  editor.schedule();
}
function cancelInsertion(id: string) {
  if (tokens.delete(id)) syncPending();
}
function cancelAllInsertions() {
  tokens.clear();
  syncPending();
}
function edit(action: (document: Practice) => void, group: string | null = null) {
  if (locked.value) return;
  editor.change(action, group);
  // Deleted cells must not be restored by a late resolve reply, including after undo.
  for (const id of tokens.keys()) if (!findCell(id)) tokens.delete(id);
  syncPending();
  if (activeCell.value && !findCell(activeCell.value)) activeCell.value = null;
  if (highlightedCell.value && !findCell(highlightedCell.value)) highlightedCell.value = null;
  if (transfer.value && !findCell(transfer.value.source)) transfer.value = null;
  if (groupStart.value && !findCell(groupStart.value.cellId)) resetGroupRange();
  else if (groupEnd.value && !findCell(groupEnd.value)) groupEnd.value = null;
}
function selectCell(id: string, showPicker = true) {
  if (locked.value) return;
  const found = findCell(id);
  if (!found) return;
  highlightedCell.value = id;
  if (arrowMode.value) {
    activeCell.value = id;
    activeBlock.value = found.block.id;
    activeRow.value = found.row.id;
    if (showPicker) {
      edit(() => {
        found.cell.arrow_direction = arrowChoice.value;
      });
      // Keep the tool active for marking several cells in succession.
      arrowMode.value = true;
    }
    return;
  }
  if (grouping.value) {
    activeCell.value = id;
    activeBlock.value = found.block.id;
    activeRow.value = found.row.id;
    if (showPicker) selectGroupEndpoint(found.row, found.cell);
    return;
  }
  const transferring = Boolean(transfer.value);
  if (transfer.value) {
    moveContent(transfer.value.source, id, transfer.value.mode === 'copy');
    transfer.value = null;
  }
  activeCell.value = id;
  activeBlock.value = found.block.id;
  activeRow.value = found.row.id;
  if (embedded) sendToMesocycle({ type: 'ribka:meso-cell', cellId: id, label: cellLabel(found.cell) });
  if (!embedded && !transferring && showPicker) openPicker(id);
}
function openPicker(id: string) {
  if (locked.value || annotationMode.value || !findCell(id)) return;
  pickerSession++;
  pickerCellId.value = id;
  pickerError.value = '';
  pickerOpen.value = true;
  mobilePalette.value = false;
}
function closePicker() {
  if (pickerCellId.value) cancelInsertion(pickerCellId.value);
  pickerSession++;
  pickerOpen.value = false;
  pickerCellId.value = null;
  pickerError.value = '';
  highlightedCell.value = null;
}
function releaseCellHighlight(event: PointerEvent | FocusEvent) {
  if (!highlightedCell.value) return;
  const target = event.type === 'focusout' ? (event as FocusEvent).relatedTarget : event.target;
  const cellId =
    target instanceof Element ? target.closest<HTMLElement>('[data-editor-cell]')?.dataset.editorCell : null;
  if (cellId !== highlightedCell.value) highlightedCell.value = null;
}
function blurEditor() {
  highlightedCell.value = null;
}
async function pickAsana(card: Asana) {
  const target = pickerCellId.value,
    session = pickerSession;
  if (!target || pendingCells.value.has(target)) return;
  pickerError.value = '';
  const inserted = await insertAsana(card.id, target);
  if (!pickerOpen.value || session !== pickerSession) return;
  if (inserted) closePicker();
  else pickerError.value = notice.value || 'Не удалось добавить карточку. Попробуйте ещё раз.';
}
function pickCustomName(name: string) {
  const target = pickerCellId.value,
    value = name.trim();
  if (!target || !value || locked.value) return;
  if (value.length > 160) {
    pickerError.value = 'Название может содержать до 160 символов.';
    return;
  }
  cancelInsertion(target);
  edit(() => {
    const cell = findCell(target)?.cell;
    if (cell)
      Object.assign(cell, {
        card: null,
        card_snapshot_id: null,
        custom_name: value,
        note: cell.custom_name ? cell.note : null,
      });
  });
  closePicker();
}
function moveContent(from: string, to: string, copy = false) {
  if (from === to || locked.value) return;
  cancelInsertion(from);
  cancelInsertion(to);
  edit(() => {
    const source = findCell(from)?.cell,
      target = findCell(to)?.cell;
    if (!source || !cellFilled(source) || !target) return;
    const previous = {
      card: clone(target.card),
      card_snapshot_id: target.card_snapshot_id,
      custom_name: target.custom_name || null,
      note: target.note,
    };
    Object.assign(target, {
      card: clone(source.card),
      card_snapshot_id: source.card_snapshot_id,
      custom_name: source.custom_name || null,
      note: source.note,
    });
    if (!copy) Object.assign(source, previous);
  });
}
async function insertAsana(asanaId: string, targetId = activeCell.value): Promise<boolean> {
  if (!targetId || !findCell(targetId)) {
    message('Сначала выберите ячейку в последовательности.');
    return false;
  }
  if (locked.value) return false;
  const token = uid();
  tokens.set(targetId, token);
  syncPending();
  notice.value = '';
  try {
    const result = await api<{ data: Snapshot[] }>('/api/editor/cards/resolve', {
      method: 'POST',
      body: JSON.stringify({ asana_ids: [asanaId] }),
      signal: AbortSignal.timeout(30000),
    });
    if (!alive || tokens.get(targetId) !== token || locked.value) return false;
    const card = result.data[0];
    if (!card) throw new Error('Не удалось добавить карточку.');
    edit(() => {
      const found = findCell(targetId);
      if (found)
        Object.assign(found.cell, {
          card: clone(card),
          card_snapshot_id: card.id,
          custom_name: null,
          note: null,
        });
    });
    mobilePalette.value = false;
    return findCell(targetId)?.cell.card_snapshot_id === card.id;
  } catch (e) {
    if (alive && tokens.get(targetId) === token)
      message(
        `${e instanceof Error ? e.message : 'Не удалось добавить асану.'} Выберите карточку ещё раз, чтобы повторить.`,
      );
    return false;
  } finally {
    if (tokens.get(targetId) === token) {
      tokens.delete(targetId);
      syncPending();
    }
  }
}
function paletteSelect(card: Asana) {
  if (annotationMode.value) return;
  void insertAsana(card.id);
}
function paletteTarget(point: PaletteDragPoint): { kind: 'cell' | 'row' | 'block'; id: string } | null {
  if (annotationMode.value) return null;
  if (locked.value) return null;
  const element = window.document.elementFromPoint(point.x, point.y);
  if (!element?.closest('.practice-canvas')) return null;
  const cellId = element.closest<HTMLElement>('[data-editor-cell]')?.dataset.editorCell;
  if (cellId && findCell(cellId)) return { kind: 'cell', id: cellId };
  const rowId = element.closest<HTMLElement>('[data-editor-row]')?.dataset.editorRow;
  if (rowId && findRow(rowId)) return { kind: 'row', id: rowId };
  const blockId = element.closest<HTMLElement>('[data-editor-block]')?.dataset.editorBlock;
  if (blockId && doc.value?.blocks.some((block) => block.id === blockId))
    return { kind: 'block', id: blockId };
  return null;
}
function movePalettePointer(point: PaletteDragPoint) {
  pointerDragging.value = true;
  pointerTarget.value = paletteTarget(point);
}
function endPalettePointer() {
  pointerDragging.value = false;
  pointerTarget.value = null;
}
function dropPalettePointer(card: Asana, point: PaletteDragPoint) {
  const target = paletteTarget(point);
  endPalettePointer();
  if (!target) return;
  if (target.kind === 'cell') void insertAsana(card.id, target.id);
  else if (target.kind === 'row') insertIntoRow(card.id, target.id);
  else insertIntoBlock(card.id, target.id);
}
function clearCell(id: string) {
  if (locked.value) return;
  cancelInsertion(id);
  edit(() => {
    const cell = findCell(id)?.cell;
    if (cell) Object.assign(cell, { card: null, card_snapshot_id: null, custom_name: null, note: null });
  });
}
function ask(title: string, text: string, action: () => void | Promise<void>) {
  modalTitle.value = title;
  modalText.value = text;
  modalKind.value = 'confirm';
  modalError.value = '';
  modalAction = action;
}
function prompt(
  title: string,
  value: string | null,
  action: (text: string) => void | Promise<void>,
  long = false,
) {
  modalTitle.value = title;
  modalValue.value = value || '';
  modalText.value = '';
  modalKind.value = long ? 'note' : 'text';
  modalError.value = '';
  modalAction = action;
}
async function applyModal() {
  if (modalBusy.value || locked.value) return;
  modalBusy.value = true;
  modalError.value = '';
  try {
    await modalAction?.(modalValue.value);
    modalKind.value = '';
  } catch (e) {
    modalError.value = e instanceof Error ? e.message : 'Не удалось выполнить действие.';
  } finally {
    modalBusy.value = false;
  }
}
function resizeRow(id: string, count: number) {
  const found = findRow(id);
  if (!found || count < 1 || count > 12) return;
  const removed = found.row.cells.slice(count).filter(cellFilled);
  const run = () =>
    edit(() => {
      const row = findRow(id)?.row;
      if (!row) return;
      while (row.cells.length < count) row.cells.push(emptyCell());
      row.cells.splice(count);
    });
  if (removed.length)
    ask(
      'Уменьшить строку?',
      `Будут удалены заполненные ячейки: ${removed.map(cellLabel).join(', ')}. Действие можно отменить.`,
      run,
    );
  else run();
}
function removeCell() {
  const id = activeCell.value;
  if (!id) return;
  const found = findCell(id);
  if (!found) return;
  if (found.row.cells.length === 1) {
    clearCell(id);
    return;
  }
  const run = () => {
    edit(() => {
      const row = findCell(id)?.row;
      if (row)
        row.cells.splice(
          row.cells.findIndex((cell) => cell.id === id),
          1,
        );
    });
    activeCell.value = null;
  };
  if (cellFilled(found.cell))
    ask('Удалить ячейку?', `«${cellLabel(found.cell)}» и место в строке будут удалены.`, run);
  else run();
}
function addRow(blockId: string, after?: string | null, source?: Row) {
  edit((document) => {
    const block = document.blocks.find((block) => block.id === blockId);
    if (!block) return;
    if (block.rows.length >= 20) {
      message('В одном блоке может быть до 20 строк.');
      return;
    }
    const index = after ? block.rows.findIndex((row) => row.id === after) + 1 : block.rows.length;
    const row = source ? copyRow(source) : emptyRow();
    block.rows.splice(index, 0, row);
    activeRow.value = row.id;
    activeBlock.value = block.id;
  });
}
function removeRow(id: string) {
  const found = findRow(id);
  if (!found) return;
  if (found.block.rows.length <= 1) {
    message('В блоке должна остаться хотя бы одна строка. Можно очистить её ячейки.');
    return;
  }
  const run = () =>
    edit(() => {
      const item = findRow(id);
      if (item)
        item.block.rows.splice(
          item.block.rows.findIndex((row) => row.id === id),
          1,
        );
    });
  const filled = found.row.cells.filter(cellFilled).length;
  if (filled) ask('Удалить строку?', `В строке ${filled} заполненных ячеек. Удаление можно отменить.`, run);
  else run();
}
function moveRow(id: string, direction: number) {
  edit(() => {
    const found = findRow(id);
    if (!found) return;
    const index = found.block.rows.findIndex((row) => row.id === id),
      target = index + direction;
    if (target >= 0 && target < found.block.rows.length) {
      const [row] = found.block.rows.splice(index, 1);
      found.block.rows.splice(target, 0, row);
    }
  });
}
function relocateRow(id: string) {
  const found = findRow(id);
  if (!found) return;
  if (found.block.rows.length === 1) {
    message('Сначала добавьте ещё одну строку в исходный блок.');
    return;
  }
  relocation.value = { block: found.block.id, row: id };
  destinationBlock.value = doc.value!.blocks.find((block) => block.id !== found.block.id)?.id || '';
  if (!destinationBlock.value) {
    message('Добавьте ещё один блок для переноса строки.');
    return;
  }
  modalKind.value = 'relocate';
  modalTitle.value = 'Переместить строку';
  modalError.value = '';
}
function transferRow(sourceId: string, targetBlockId: string, afterId?: string) {
  const origin = findRow(sourceId),
    target = doc.value?.blocks.find((block) => block.id === targetBlockId);
  if (!origin || !target || sourceId === afterId) return;
  if (origin.block.id !== target.id && origin.block.rows.length <= 1) {
    message('В исходном блоке должна остаться хотя бы одна строка.');
    return;
  }
  if (origin.block.id !== target.id && target.rows.length >= 20) {
    message('В блоке уже 20 строк.');
    return;
  }
  edit(() => {
    const source = findRow(sourceId)!;
    const [row] = source.block.rows.splice(
      source.block.rows.findIndex((item) => item.id === sourceId),
      1,
    );
    const index = afterId ? target.rows.findIndex((item) => item.id === afterId) + 1 : target.rows.length;
    target.rows.splice(index, 0, row);
  });
}
function changeBlockOrder(id: string, direction: number) {
  edit((document) => {
    const index = document.blocks.findIndex((block) => block.id === id),
      target = index + direction;
    if (target >= 0 && target < document.blocks.length) {
      const [block] = document.blocks.splice(index, 1);
      document.blocks.splice(target, 0, block);
    }
  });
}
function duplicateBlock(block: Block) {
  if (doc.value!.blocks.length >= 20) {
    message('В практике может быть до 20 блоков.');
    return;
  }
  edit((document) =>
    document.blocks.splice(
      document.blocks.findIndex((item) => item.id === block.id) + 1,
      0,
      copyBlock(block),
    ),
  );
}
function removeBlock(block: Block) {
  if (doc.value!.blocks.length <= 1) {
    message('В практике должен остаться хотя бы один блок.');
    return;
  }
  const count = block.rows.reduce((n, row) => n + row.cells.filter(cellFilled).length, 0);
  const run = () =>
    edit((document) =>
      document.blocks.splice(
        document.blocks.findIndex((item) => item.id === block.id),
        1,
      ),
    );
  if (count)
    ask('Удалить блок?', `«${block.title}»: ${block.rows.length} строк, ${count} заполненных ячеек.`, run);
  else run();
}
function dropData(event: DragEvent): { kind: string; id: string } | null {
  try {
    return JSON.parse(event.dataTransfer?.getData('application/ribka') || 'null');
  } catch {
    return null;
  }
}
function dropCell(event: DragEvent, id: string) {
  if (annotationMode.value) return;
  const data = dropData(event);
  if (!data) return;
  if (data.kind === 'asana') void insertAsana(data.id, id);
  if (data.kind === 'cell') moveContent(data.id, id);
}
function dropRow(event: DragEvent, row: Row, block: Block) {
  if (annotationMode.value) return;
  const data = dropData(event);
  if (data?.kind === 'row') transferRow(data.id, block.id, row.id);
  if (data?.kind === 'asana') insertIntoRow(data.id, row.id);
}
function insertIntoRow(asanaId: string, rowId: string) {
  if (locked.value) return;
  const row = findRow(rowId)?.row;
  if (!row) return;
  const available = row.cells.find((cell) => !cellFilled(cell) && !pendingCells.value.has(cell.id));
  if (available) {
    void insertAsana(asanaId, available.id);
    return;
  }
  if (row.cells.length >= 12) {
    message('В строке уже 12 ячеек. Перетащите карточку в нужную ячейку для замены.');
    return;
  }
  const added = emptyCell();
  edit(() => {
    const current = findRow(rowId)?.row;
    if (current && current.cells.length < 12) current.cells.push(added);
  });
  if (findCell(added.id)) void insertAsana(asanaId, added.id);
}
function dragBlock(event: DragEvent, id: string) {
  if (annotationMode.value) {
    event.preventDefault();
    return;
  }
  event.dataTransfer?.setData('application/ribka', JSON.stringify({ kind: 'block', id }));
}
function insertIntoBlock(asanaId: string, blockId: string) {
  const block = doc.value?.blocks.find((item) => item.id === blockId);
  if (!block || locked.value) return;
  const available = block.rows
    .flatMap((row) => row.cells)
    .find((cell) => !cellFilled(cell) && !pendingCells.value.has(cell.id));
  if (available) void insertAsana(asanaId, available.id);
  else {
    const last = block.rows[block.rows.length - 1];
    if (last) insertIntoRow(asanaId, last.id);
  }
}
function dropBlock(event: DragEvent, id: string) {
  if (annotationMode.value) return;
  const data = dropData(event);
  if (data?.kind === 'asana') {
    insertIntoBlock(data.id, id);
    return;
  }
  if (data?.kind !== 'block' || data.id === id) return;
  edit((document) => {
    const from = document.blocks.findIndex((block) => block.id === data.id),
      to = document.blocks.findIndex((block) => block.id === id);
    if (from < 0 || to < 0) return;
    const [block] = document.blocks.splice(from, 1);
    document.blocks.splice(to, 0, block);
  });
}
function cellNote() {
  const id = activeCell.value;
  const found = id ? findCell(id) : null;
  if (!found || !cellFilled(found.cell)) return;
  prompt('Подпись к ячейке', found.cell.note, (value) =>
    edit(() => {
      const cell = findCell(id!)?.cell;
      if (cell) cell.note = value.slice(0, 160) || null;
    }),
  );
}
function rowNote(row: Row) {
  prompt(
    'Заметка к строке',
    row.note,
    (value) =>
      edit(() => {
        const target = findRow(row.id)?.row;
        if (target) target.note = value.slice(0, 1000) || null;
      }),
    true,
  );
}
function saveRow(row: Row) {
  prompt('Сохранить строку', 'Моя последовательность', async (value) => {
    if (!value.trim()) throw new Error('Введите название строки.');
    if (!(await editor.save())) throw new Error('Сначала сохраните изменения практики.');
    const current = findRow(row.id)?.row;
    if (!current) throw new Error('Строка больше не существует в текущей практике.');
    await api('/api/row-templates', {
      method: 'POST',
      body: JSON.stringify({
        title: value.trim(),
        theme: doc.value?.theme,
        note: current.note,
        cells: copyRow(current).cells.map(
          ({ id, card_snapshot_id, custom_name, group_key, arrow_direction, note }) => ({
            id,
            card_snapshot_id,
            custom_name: custom_name || null,
            group_key: group_key || null,
            arrow_direction: arrow_direction || null,
            note,
          }),
        ),
      }),
    });
    message('Строка сохранена. Она доступна в разделе «Практики → Сохранённые строки».');
  });
}
function saveSelectedTransition() {
  const current = selected.value;
  if (!current?.cell.group_key) return;
  const rowId = current.row.id,
    key = current.cell.group_key;
  prompt('Сохранить связку', 'Мой переход', async (value) => {
    if (!value.trim()) throw new Error('Введите название связки.');
    if (!(await editor.save())) throw new Error('Сначала сохраните изменения практики.');
    const cells = findRow(rowId)?.row.cells.filter((cell) => cell.group_key === key) || [];
    if (cells.length < 2) throw new Error('Выбранная группа больше не существует.');
    await api('/api/row-templates', {
      method: 'POST',
      body: JSON.stringify({
        title: value.trim(),
        kind: 'transition',
        theme: doc.value?.theme,
        note: null,
        cells: cells.map(({ card_snapshot_id, custom_name, arrow_direction, note }) => ({
          id: uid(),
          card_snapshot_id,
          custom_name,
          group_key: key,
          arrow_direction,
          note,
        })),
      }),
    });
    message('Связка сохранена. Найти её можно во вкладке «Связки и переходы».');
  });
}
function chooseTransition() {
  if (!selected.value) {
    message('Сначала выберите ячейку, вместо которой вставить связку.');
    return;
  }
  transitionTarget.value = selected.value.cell.id;
  savedKind.value = 'transition';
  modalKind.value = 'transitions';
  modalTitle.value = 'Вставить связку вместо ячейки';
  savedQuery.value = '';
  savedPage.value = 1;
  void loadSavedRows();
}
async function chooseVariants() {
  const current = selected.value;
  if (!current?.cell.card?.source_asana_id) return;
  variantTarget.value = current.cell.id;
  modalBusy.value = true;
  modalKind.value = 'variants';
  modalTitle.value = 'Варианты асаны';
  modalError.value = '';
  variants.value = [];
  try {
    const result = await api<{ data: Asana }>(`/api/asanas/${current.cell.card.source_asana_id}`);
    variants.value = result.data.relations || [];
  } catch (e) {
    modalError.value = e instanceof Error ? e.message : 'Не удалось открыть варианты.';
  } finally {
    modalBusy.value = false;
  }
}
async function insertVariant(id: string) {
  if (!variantTarget.value || modalBusy.value) return;
  modalBusy.value = true;
  modalError.value = '';
  try {
    if (await insertAsana(id, variantTarget.value)) modalKind.value = '';
    else modalError.value = notice.value || 'Не удалось заменить асану.';
  } finally {
    modalBusy.value = false;
  }
}
async function openLibraryUpdates() {
  if (locked.value || modalBusy.value) return;
  if (!(await editor.save())) {
    message('Сначала сохраните изменения практики.');
    return;
  }
  modalBusy.value = true;
  try {
    const result = await api<{ data: { revision: number; changes: SnapshotChange[] } }>(
      `/api/practices/${props.documentId}/library-updates`,
    );
    snapshotChanges.value = result.data.changes;
    snapshotRevision.value = result.data.revision;
    if (!snapshotChanges.value.length) {
      message('Названия и изображения уже совпадают с библиотекой. Архивные асаны остаются как были.');
      return;
    }
    modalKind.value = 'library-updates';
    modalTitle.value = 'Обновить из библиотеки';
    modalError.value = '';
  } catch (e) {
    message(e instanceof Error ? e.message : 'Не удалось проверить библиотеку.');
  } finally {
    modalBusy.value = false;
  }
}
async function applyLibraryUpdates() {
  if (modalBusy.value) return;
  modalBusy.value = true;
  modalError.value = '';
  try {
    const result = await api<{ data: Practice }>(`/api/practices/${props.documentId}/refresh-snapshots`, {
      method: 'POST',
      body: JSON.stringify({
        expected_revision: snapshotRevision.value,
        source_asana_ids: [...new Set(snapshotChanges.value.map((change) => change.source_asana_id))],
        expected_asana_revisions: [
          ...new Map(
            snapshotChanges.value.map((change) => [
              change.source_asana_id,
              { asana_id: change.source_asana_id, revision: change.source_revision },
            ]),
          ).values(),
        ],
      }),
    });
    editor.acceptSaved(result.data);
    modalKind.value = '';
    message(
      `Обновлено ${snapshotChanges.value.length} карточек. Старые изображения сохранены в прежних документах.`,
    );
  } catch (e) {
    modalError.value = e instanceof Error ? e.message : 'Не удалось обновить карточки.';
  } finally {
    modalBusy.value = false;
  }
}
function receiveMesocycle(event: MessageEvent) {
  if (
    !mesocycleSession ||
    event.origin !== window.location.origin ||
    event.source !== window.parent ||
    !isMesocycleCommand(event.data) ||
    event.data.documentId !== props.documentId ||
    event.data.sessionId !== mesocycleSession
  )
    return;
  const data = event.data;
  if (data.type === 'ribka:meso-hover') {
    mesocycleHoverCell.value =
      data.cellId && canReceiveCloud.value && findCell(data.cellId) && !pendingCells.value.has(data.cellId)
        ? data.cellId
        : null;
  }
  if (data.type === 'ribka:meso-asana') void insertMesocycleAsana(data);
  if (data.type === 'ribka:meso-save')
    void editor
      .save()
      .then((saved) => sendToMesocycle({ type: 'ribka:meso-saved', requestId: data.requestId, saved }));
}
const cloudEditingAllowed = computed(
  () =>
    !!mesocycleSession &&
    !!doc.value &&
    !locked.value &&
    !conflict.value &&
    !annotationMode.value &&
    !pickerOpen.value,
);
const canReceiveCloud = computed(() => cloudEditingAllowed.value && !modalKind.value && !modalBusy.value);
watch(canReceiveCloud, (allowed) => {
  if (!allowed) mesocycleHoverCell.value = null;
});
async function insertMesocycleAsana(data: Extract<MesocycleCommand, { type: 'ribka:meso-asana' }>) {
  if (data.sequence <= consumedMesocycleSequence) return;
  // Consume each insertion once for the whole frame session, including cancelled confirmations.
  consumedMesocycleSequence = data.sequence;
  mesocycleHoverCell.value = null;
  const current = findCell(data.cellId);
  if (!canReceiveCloud.value || !current || pendingCells.value.has(data.cellId)) return;
  const insert = async () => {
    // applyModal awaits the action before closing. Recheck editing permissions while our own confirmation is open.
    if (!cloudEditingAllowed.value || !findCell(data.cellId) || pendingCells.value.has(data.cellId)) return;
    activeCell.value = data.cellId;
    highlightedCell.value = null;
    const inserted = await insertAsana(data.asanaId, data.cellId);
    if (!inserted && modalKind.value === 'confirm')
      throw new Error(notice.value || 'Не удалось заменить асану. Закройте окно и повторите вставку.');
  };
  if (cellFilled(current.cell))
    ask(
      'Заменить асану?',
      `Заменить «${cellLabel(current.cell)}» асаной «${data.name}»? Действие можно отменить.`,
      insert,
    );
  else await insert();
}
function saveTemplate() {
  prompt('Сохранить как шаблон', doc.value?.title || '', async (value) => {
    if (!value.trim()) throw new Error('Введите название шаблона.');
    if (!(await editor.save())) throw new Error('Сначала сохраните изменения практики.');
    await api(`/api/practices/${props.documentId}/save-as-template`, {
      method: 'POST',
      body: JSON.stringify({ title: value.trim(), expected_revision: doc.value!.revision }),
    });
    message('Шаблон сохранён. Из него можно создавать независимые практики.');
  });
}
async function loadSavedRows() {
  modalBusy.value = true;
  modalError.value = '';
  try {
    const result = await api<Page<RowTemplate>>(
      `/api/row-templates?${new URLSearchParams({ kind: savedKind.value, q: savedQuery.value, page: String(savedPage.value), per_page: '12' })}`,
    );
    savedRows.value = result.data;
    savedLastPage.value = result.last_page;
  } catch (e) {
    modalError.value = e instanceof Error ? e.message : 'Не удалось загрузить строки.';
  } finally {
    modalBusy.value = false;
  }
}
function chooseSavedRow(blockId: string) {
  savedKind.value = 'row';
  insertion.value = { block: blockId, after: activeBlock.value === blockId ? activeRow.value : null };
  modalKind.value = 'rows';
  modalTitle.value = 'Вставить сохранённую строку';
  savedQuery.value = '';
  savedPage.value = 1;
  void loadSavedRows();
}
async function insertSaved(id: string) {
  modalBusy.value = true;
  modalError.value = '';
  try {
    const { data } = await api<{ data: RowTemplate }>(`/api/row-templates/${id}`);
    if (data.archived_at) throw new Error('Последовательность уже находится в архиве. Обновите список.');
    if (savedKind.value === 'transition') {
      if (data.kind !== 'transition') throw new Error('Выберите готовую связку.');
      const target = transitionTarget.value ? findCell(transitionTarget.value) : null;
      if (!target) throw new Error('Выберите ячейку заново.');
      if (target.cell.group_key)
        throw new Error('Сначала снимите скобки у группы, в которую входит выбранная ячейка.');
      if (target.row.cells.length + data.cells.length - 1 > 12)
        throw new Error('Связка не помещается: в строке может быть до 12 ячеек.');
      if (
        cellFilled(target.cell) &&
        !window.confirm(
          `Заменить «${cellLabel(target.cell)}» связкой «${data.title}»? Действие можно отменить.`,
        )
      )
        return;
      const position = target.row.cells.indexOf(target.cell),
        key = uid();
      edit(() =>
        target.row.cells.splice(
          position,
          1,
          ...data.cells.map((cell) => ({ ...clone(cell), id: uid(), group_key: key })),
        ),
      );
      activeCell.value = null;
      modalKind.value = '';
      return;
    }
    if (insertion.value)
      addRow(insertion.value.block, insertion.value.after, {
        id: data.id,
        note: data.note,
        cells: data.cells,
      });
    modalKind.value = '';
  } catch (e) {
    modalError.value = e instanceof Error ? e.message : 'Не удалось вставить строку.';
  } finally {
    modalBusy.value = false;
  }
}
function visit(path: string) {
  leaving = true;
  router.visit(path, {
    onFinish: () => {
      if (alive) {
        leaving = false;
        navigating.value = false;
      }
    },
  });
}
async function go(path: string) {
  if (navigating.value) return;
  if (pending.value) {
    message('Подождите, пока добавится асана, или отмените её вставку.');
    return;
  }
  navigating.value = true;
  const separate = embedded ? window.open('about:blank', '_blank') : null;
  if (separate) separate.opener = null;
  try {
    if (await editor.save()) {
      if (separate) separate.location.href = path;
      else visit(path);
    } else separate?.close();
  } finally {
    if (!leaving) navigating.value = false;
  }
}
async function copyLocal() {
  if (!doc.value || pending.value || navigating.value || modalBusy.value) return;
  modalBusy.value = true;
  navigating.value = true;
  try {
    const data = payload(doc.value, props.rowMode);
    if (props.rowMode && 'cells' in data) data.cells = data.cells.map((cell) => ({ ...cell, id: uid() }));
    data.title = `${doc.value.title.slice(0, 150)} — копия`;
    const result = await api<{ data: Practice | RowTemplate }>(
      props.rowMode ? '/api/row-templates' : '/api/practices/copy-local',
      { method: 'POST', body: JSON.stringify(data) },
    );
    visit(`/${props.rowMode ? 'row-templates' : 'practices'}/${result.data.id}/edit`);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось сохранить копию.';
  } finally {
    modalBusy.value = false;
    if (!leaving) navigating.value = false;
  }
}
function refreshConflict() {
  ask(
    'Загрузить актуальную версию?',
    'Локальные несохранённые изменения будут потеряны. Их можно сначала сохранить отдельной копией.',
    () => {
      cancelAllInsertions();
      closePicker();
      cancelGrouping();
      transfer.value = null;
      activeCell.value = null;
      activeRow.value = null;
      activeBlock.value = null;
      mobilePalette.value = false;
      void editor.load();
    },
  );
}
function keydown(event: KeyboardEvent) {
  const target = event.target as HTMLElement;
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
    event.preventDefault();
    if (!locked.value && !modalKind.value && !pickerOpen.value && !pointerDragging.value) void editor.save();
    return;
  }
  if (
    ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) ||
    target.isContentEditable ||
    modalKind.value ||
    pickerOpen.value ||
    pointerDragging.value
  )
    return;
  if (locked.value) return;
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
    event.preventDefault();
    event.shiftKey ? editor.redo() : editor.undo();
  }
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'y') {
    event.preventDefault();
    editor.redo();
  }
  if (event.key === 'Escape') {
    arrowMode.value = false;
    transfer.value = null;
    mobilePalette.value = false;
    cancelGrouping();
  }
  if (event.key === 'Delete' && activeCell.value && !annotationMode.value) clearCell(activeCell.value);
  if (['ArrowLeft', 'ArrowRight'].includes(event.key) && selected.value) {
    const { row, cell } = selected.value,
      index = row.cells.indexOf(cell) + (event.key === 'ArrowLeft' ? -1 : 1);
    if (row.cells[index]) {
      event.preventDefault();
      selectCell(row.cells[index].id, false);
      window.document
        .querySelector<HTMLElement>(`[data-editor-cell="${row.cells[index].id}"]`)
        ?.focus({ preventScroll: true });
    }
  }
}
function beforeUnload(event: BeforeUnloadEvent) {
  if (dirty.value && !leaving) {
    event.preventDefault();
    event.returnValue = '';
  }
}
onMounted(() => {
  void editor.load();
  window.addEventListener('keydown', keydown);
  if (embedded) window.addEventListener('message', receiveMesocycle);
  window.addEventListener('beforeunload', beforeUnload);
  window.addEventListener('pointerdown', releaseCellHighlight, true);
  window.addEventListener('focusout', releaseCellHighlight);
  window.addEventListener('blur', blurEditor);
  removeNavigateGuard = router.on('before', () => {
    if (dirty.value && !leaving && !window.confirm('Изменения ещё не сохранены. Покинуть редактор?'))
      return false;
  });
});
watch([dirty, pending], () => {
  if (embedded) sendToMesocycle({ type: 'ribka:meso-status', dirty: dirty.value, pending: pending.value });
});
onBeforeUnmount(() => {
  alive = false;
  tokens.clear();
  pendingCells.value.clear();
  pending.value = 0;
  window.removeEventListener('keydown', keydown);
  window.removeEventListener('message', receiveMesocycle);
  window.removeEventListener('beforeunload', beforeUnload);
  window.removeEventListener('pointerdown', releaseCellHighlight, true);
  window.removeEventListener('focusout', releaseCellHighlight);
  window.removeEventListener('blur', blurEditor);
  removeNavigateGuard?.();
});
</script>

<template>
  <Head :title="doc?.title || 'Конструктор'" />
  <AppShell section="practices" wide :class="{ 'embedded-editor': embedded }">
    <div v-if="loading" class="editor-loading">
      <div class="skeleton" style="height: 64px; margin-bottom: 25px" />
      <div class="skeleton" style="height: 500px" />
    </div>
    <div v-else-if="!doc" class="empty-state">
      <h1>Не удалось открыть практику</h1>
      <p>{{ error }}</p>
      <button class="btn btn-primary" @click="editor.load"><RefreshCw :size="17" />Повторить</button>
    </div>
    <template v-else>
      <div v-if="!embedded" class="editor-breadcrumb">
        <button
          @click="
            go(rowMode ? `/practices?tab=${transitionDocument ? 'transitions' : 'rows'}` : '/practices')
          "
        >
          <ArrowLeft :size="15" />{{
            transitionDocument ? 'Связки и переходы' : rowMode ? 'Сохранённые строки' : 'Все практики'
          }}</button
        ><span>/</span
        ><span>{{
          transitionDocument
            ? 'Связка'
            : rowMode
              ? 'Сохранённая строка'
              : doc.kind === 'template'
                ? 'Шаблон практики'
                : 'Конструктор'
        }}</span>
      </div>
      <header class="editor-header">
        <div class="editor-title-area">
          <input
            class="document-title"
            :value="doc.title"
            maxlength="160"
            aria-label="Название практики"
            :disabled="locked"
            @input="edit((document) => (document.title = ($event.target as HTMLInputElement).value), 'title')"
            @blur="editor.endGroup"
          />
          <div class="editor-subtitle">
            <span v-if="doc.kind === 'template'" class="badge">{{
              transitionDocument ? 'Связка' : rowMode ? 'Строка' : 'Шаблон'
            }}</span
            ><span>{{ doc.blocks.length }} блоков</span><span class="divider-dot">·</span
            ><span>{{ counts.rows }} строк</span><span class="divider-dot">·</span
            ><span>{{ counts.cards }} элементов</span>
          </div>
        </div>
        <div class="editor-actions">
          <button
            v-if="!rowMode && !embedded"
            class="btn btn-secondary"
            :disabled="saving || pending > 0 || dirty"
            @click="sharingOpen = true"
          >
            <UserPlus :size="16" />Дать доступ
          </button>
          <button class="btn btn-secondary" :disabled="saving || pending > 0 || locked" @click="editor.save">
            <Save :size="16" />Сохранить</button
          ><button
            v-if="!rowMode"
            class="btn btn-primary"
            :disabled="pending > 0 || navigating"
            @click="go(`/practices/${doc.id}/preview`)"
          >
            <Eye :size="17" />Просмотр</button
          ><button
            v-if="!rowMode"
            class="icon-btn"
            :disabled="pending > 0 || navigating"
            title="Печать"
            aria-label="Печать практики"
            @click="go(`/practices/${doc.id}/print`)"
          >
            <Printer :size="19" />
          </button>
        </div>
      </header>
      <div class="document-meta">
        <div class="theme-field">
          <span>Тема</span
          ><input
            :value="doc.theme"
            placeholder="Например, мобильность плеч"
            maxlength="240"
            :disabled="locked"
            aria-label="Тема практики"
            @input="
              edit(
                (document) => (document.theme = ($event.target as HTMLInputElement).value || null),
                'theme',
              )
            "
            @blur="editor.endGroup"
          />
        </div>
        <button v-if="!rowMode && !readOnly" class="btn btn-ghost" @click="saveTemplate">
          <Bookmark :size="16" />Как шаблон
        </button>
      </div>
      <div
        ref="toolbarElement"
        class="editor-toolbar"
        role="region"
        aria-label="Действия с практикой и ячейками"
      >
        <div class="save-toolbar">
          <button
            v-if="rowMode && doc.row_template_kind === 'transition' && !readOnly"
            class="btn btn-secondary"
            :disabled="locked || annotationMode"
            @click="editTransitionRelations"
          >
            <RefreshCw :size="16" />Варианты связки
          </button>
          <div class="history-buttons">
            <button
              class="btn btn-ghost"
              :disabled="!undoStack.length || pending > 0 || locked"
              title="Отменить (Ctrl+Z)"
              aria-label="Отменить действие"
              @click="editor.undo"
            >
              <Undo2 :size="18" />Отменить</button
            ><button
              class="btn btn-ghost"
              :disabled="!redoStack.length || pending > 0 || locked"
              title="Повторить (Ctrl+Y или Ctrl+Shift+Z)"
              aria-label="Повторить действие"
              @click="editor.redo"
            >
              <Redo2 :size="18" />Повторить
            </button>
          </div>
          <button
            v-if="!readOnly"
            class="btn btn-secondary"
            :disabled="locked || annotationMode"
            @click="chooseTransition"
          >
            <Brackets :size="17" />Связки
          </button>
          <button
            v-if="!readOnly && selected?.cell.group_key"
            class="btn btn-secondary"
            :disabled="locked || annotationMode"
            @click="saveSelectedTransition"
          >
            <Bookmark :size="16" />Сохранить связку
          </button>
          <button
            v-if="!rowMode && !readOnly && !embedded"
            class="btn btn-ghost"
            :disabled="locked || annotationMode || modalBusy"
            @click="openLibraryUpdates"
          >
            <RefreshCw :size="16" />Обновить из библиотеки
          </button>
          <button
            v-if="!readOnly"
            class="btn btn-secondary group-mode-button"
            :disabled="locked || annotationMode"
            :aria-pressed="grouping"
            @click="beginGrouping"
          >
            <Brackets :size="17" />Объединить
          </button>
          <button
            v-if="!readOnly && selected?.cell.group_key"
            class="btn btn-ghost"
            :disabled="locked"
            @click="removeSelectedGroup"
          >
            <X :size="16" />Снять скобки
          </button>
          <button
            v-if="!readOnly"
            class="btn btn-secondary"
            :disabled="locked"
            :aria-pressed="arrowMode"
            @click="beginArrows"
          >
            <ArrowSymbol direction="cross" :size="20" />Стрелочка
          </button>
          <span :class="['save-status', { warning: error || conflict }]" role="status"
            ><component :is="dirty || saving || pending ? Cloud : Check" :size="14" />{{ status }}</span
          ><button
            v-if="!readOnly"
            class="btn btn-secondary mobile-picker"
            :disabled="locked || annotationMode"
            @click="mobilePalette = true"
          >
            <Library :size="16" />Асаны
          </button>
        </div>
        <div v-if="error" class="error-banner">
          {{ error }}<button class="btn btn-ghost" @click="editor.save">Повторить сохранение</button>
        </div>
        <div v-if="conflict" class="conflict-banner">
          <div>
            <strong>Сохранена другая версия</strong>
            <p>
              Ваши изменения остаются в этом окне. Сохраните их отдельной копией или откройте актуальный лист.
            </p>
          </div>
          <button class="btn btn-primary" :disabled="modalBusy" @click="copyLocal">Сохранить мою копию</button
          ><button class="btn btn-secondary" @click="refreshConflict">Загрузить актуальную</button>
        </div>
        <div v-if="readOnly" class="error-banner">
          Документ находится в архиве. Восстановите его в списке практик, чтобы редактировать.
        </div>
        <div v-if="notice" class="notice" role="status">
          <span>{{ notice }}</span
          ><button class="icon-btn" aria-label="Закрыть сообщение" @click="notice = ''">
            <X :size="16" />
          </button>
        </div>
        <div
          v-if="arrowMode"
          class="group-banner arrow-banner"
          role="region"
          aria-label="Стрелочки на ячейках"
        >
          <div class="group-instruction">
            <strong>Стрелочка</strong>
            <p>Выберите направление или «Убрать стрелку», затем нажмите на нужную ячейку.</p>
          </div>
          <div class="group-actions">
            <button
              v-for="direction in arrowDirections"
              :key="direction"
              class="btn btn-secondary"
              :aria-pressed="arrowChoice === direction"
              @click="arrowChoice = direction"
            >
              <ArrowSymbol :direction="direction" :size="24" />{{ arrowLabels[direction] }}
            </button>
            <button
              class="btn btn-secondary"
              :aria-pressed="arrowChoice === null"
              @click="arrowChoice = null"
            >
              <X :size="17" />Убрать стрелку
            </button>
            <button class="btn btn-ghost" @click="arrowMode = false">Готово</button>
          </div>
        </div>
        <div v-if="grouping" class="group-banner" role="region" aria-label="Объединение ячеек">
          <div class="group-instruction" aria-live="polite">
            <strong>Объединение ячеек</strong>
            <p v-if="!groupStart">Выберите первую, затем последнюю ячейку группы в одной строке.</p>
            <p v-else-if="!groupEnd">
              Теперь выберите последнюю ячейку в этой же строке. В группу войдут все ячейки между ними.
            </p>
            <p v-else-if="groupRange">
              Выбраны ячейки {{ groupRange.start + 1 }}–{{ groupRange.end + 1 }} ({{
                groupRange.cells.length
              }}). Нажмите «Объединить» или выберите другую последнюю ячейку.
            </p>
            <p v-if="groupIssue || groupRangeError" class="group-error" role="alert">
              {{ groupIssue || groupRangeError }}
            </p>
          </div>
          <div class="group-actions">
            <button class="btn btn-primary" :disabled="locked || !canGroup" @click="confirmGrouping">
              <Brackets :size="17" />Объединить
            </button>
            <button v-if="groupStart" class="btn btn-ghost" @click="resetGroupRange">Выбрать заново</button>
            <button class="btn btn-secondary" @click="cancelGrouping">Отмена</button>
          </div>
        </div>
        <div v-if="transfer" class="transfer-banner">
          <ArrowRight :size="18" /><span>{{
            transfer.mode === 'copy' ? 'Выберите ячейку для копии' : 'Выберите ячейку для переноса или обмена'
          }}</span
          ><button class="btn btn-ghost" @click="transfer = null">Отмена</button>
        </div>
        <div v-if="selected && !readOnly && !annotationMode" class="cell-toolbar">
          <button
            v-if="selected.cell.card?.source_asana_id"
            class="btn btn-ghost"
            :disabled="locked || pending > 0"
            @click="chooseVariants"
          >
            <RefreshCw :size="15" />Варианты
          </button>
          <span class="selected-label">{{ cellLabel(selected.cell) }}</span
          ><button class="btn btn-ghost" @click="openPicker(selected.cell.id)">
            <Library :size="15" />{{ cellFilled(selected.cell) ? 'Изменить' : 'Заполнить ячейку' }}</button
          ><button
            v-if="pendingCells.has(selected.cell.id)"
            class="btn btn-ghost"
            :disabled="locked"
            @click="cancelInsertion(selected.cell.id)"
          >
            Отменить вставку</button
          ><template v-if="cellFilled(selected.cell)"
            ><button class="btn btn-ghost" @click="cellNote"><StickyNote :size="15" />Подпись</button
            ><button class="btn btn-ghost" @click="transfer = { mode: 'move', source: activeCell! }">
              <ArrowRight :size="15" />Переместить</button
            ><button
              class="icon-btn"
              aria-label="Скопировать содержимое в другую ячейку"
              title="Скопировать в…"
              @click="transfer = { mode: 'copy', source: activeCell! }"
            >
              <Copy :size="16" /></button
            ><button class="btn btn-ghost" @click="clearCell(activeCell!)">Очистить</button></template
          ><button class="icon-btn" aria-label="Удалить ячейку" title="Удалить ячейку" @click="removeCell">
            <Trash2 :size="16" /></button
          ><button
            class="icon-btn"
            aria-label="Снять выбор ячейки"
            @click="
              activeCell = null;
              highlightedCell = null;
            "
          >
            <X :size="16" />
          </button>
        </div>
      </div>
      <div class="editor-layout" :style="{ '--editor-toolbar-height': `${toolbarHeight}px` }">
        <div class="practice-canvas" :data-mesocycle-drop-enabled="canReceiveCloud">
          <section
            v-for="(block, bi) in doc.blocks"
            :key="block.id"
            class="practice-block"
            :class="{
              'pointer-block-target': pointerTarget?.kind === 'block' && pointerTarget.id === block.id,
            }"
            :data-editor-block="block.id"
            @dragover.prevent
            @drop.prevent="dropBlock($event, block.id)"
          >
            <header class="block-header">
              <span
                v-if="!rowMode"
                class="block-handle"
                :draggable="!locked && !annotationMode"
                @dragstart="dragBlock($event, block.id)"
                title="Переместить блок"
                ><GripVertical :size="18" /></span
              ><span class="block-number">{{ bi + 1 }}</span
              ><input
                class="block-title"
                :value="block.title"
                maxlength="160"
                aria-label="Название блока"
                :disabled="locked || rowMode"
                @input="edit(() => (block.title = ($event.target as HTMLInputElement).value), block.id)"
                @blur="editor.endGroup"
              />
              <details v-if="!rowMode && !readOnly && !annotationMode" class="block-menu">
                <summary class="icon-btn" aria-label="Действия с блоком">
                  <MoreHorizontal :size="21" />
                </summary>
                <div
                  class="block-popover"
                  @click="($event.target as HTMLElement).closest('details')?.removeAttribute('open')"
                >
                  <button
                    @click="
                      prompt('Задача блока', block.purpose, (value) =>
                        edit(() => (block.purpose = value.slice(0, 240) || null)),
                      )
                    "
                  >
                    <StickyNote :size="16" />Задача блока</button
                  ><button @click="duplicateBlock(block)"><Copy :size="16" />Дублировать</button
                  ><button @click="changeBlockOrder(block.id, -1)"><ArrowUp :size="16" />Выше</button
                  ><button @click="changeBlockOrder(block.id, 1)"><ArrowDown :size="16" />Ниже</button
                  ><button class="danger-text" @click="removeBlock(block)">
                    <Trash2 :size="16" />Удалить блок
                  </button>
                </div>
              </details>
            </header>
            <p v-if="block.purpose" class="block-purpose">{{ block.purpose }}</p>
            <div class="block-rows">
              <SequenceRow
                v-for="(row, ri) in block.rows"
                :key="row.id"
                :row="row"
                :number="`${bi + 1}.${ri + 1}`"
                :active="highlightedCell"
                :pending="pendingCells"
                :disabled="locked"
                :only-row="rowMode"
                :group-mode="annotationMode"
                :group-range="groupPreviewCells"
                :group-invalid="!!groupRangeError"
                :pointer-cell="
                  mesocycleHoverCell || (pointerTarget?.kind === 'cell' ? pointerTarget.id : null)
                "
                :pointer-row="pointerTarget?.kind === 'row' ? pointerTarget.id : null"
                @select="selectCell"
                @drop-cell="dropCell"
                @drop-row="dropRow($event, row, block)"
                @count="resizeRow(row.id, $event)"
                @duplicate="addRow(block.id, row.id, row)"
                @remove="removeRow(row.id)"
                @move="moveRow(row.id, $event)"
                @relocate="relocateRow(row.id)"
                @save-row="saveRow(row)"
                @note="rowNote(row)"
              />
            </div>
            <footer v-if="!rowMode && !readOnly && !annotationMode" class="block-footer">
              <button class="btn btn-ghost" @click="addRow(block.id)">
                <Plus :size="16" />Добавить строку</button
              ><button class="btn btn-ghost" @click="chooseSavedRow(block.id)">
                <Bookmark :size="15" />Из сохранённых
              </button>
            </footer>
          </section>
          <button
            v-if="!rowMode && !readOnly && !annotationMode"
            class="add-block"
            :disabled="doc.blocks.length >= 20"
            @click="
              edit((document) => document.blocks.push(emptyBlock(`Блок ${document.blocks.length + 1}`)))
            "
          >
            <Plus :size="19" />Добавить блок
          </button>
          <p class="canvas-help">
            Нажмите на ячейку, чтобы выбрать картинку или ввести своё название. Асаны справа можно
            перетаскивать в последовательность.
          </p>
        </div>
        <AsanaPalette
          v-if="!readOnly && !embedded"
          :disabled="locked || annotationMode"
          :document-id="documentId"
          :active="!!activeCell"
          :mobile-open="mobilePalette"
          @select="paletteSelect"
          @drag-move="movePalettePointer"
          @drag-drop="dropPalettePointer"
          @drag-end="endPalettePointer"
          @close="mobilePalette = false"
        />
      </div>
    </template>
    <CellPicker
      :open="pickerOpen"
      :document-id="documentId"
      :cell="pickerCell"
      :busy="!!pickerCellId && pendingCells.has(pickerCellId)"
      :error="pickerError"
      @close="closePicker"
      @select="pickAsana"
      @custom="pickCustomName"
    />
    <Modal :open="!!modalKind" :title="modalTitle" @close="!modalBusy && (modalKind = '')">
      <div v-if="modalError" class="error-banner">{{ modalError }}</div>
      <p v-if="modalKind === 'confirm'" class="confirm-copy">{{ modalText }}</p>
      <label v-if="modalKind === 'text' || modalKind === 'note'" class="field"
        ><span class="label">{{ modalTitle }}</span
        ><textarea
          v-if="modalKind === 'note'"
          v-model="modalValue"
          class="textarea"
          rows="5"
          maxlength="3000" /><input
          v-else
          v-model="modalValue"
          class="input"
          maxlength="160"
          @keyup.enter="applyModal"
      /></label>
      <template v-if="rowModal"
        ><form
          class="saved-row-search"
          @submit.prevent="
            savedPage = 1;
            loadSavedRows();
          "
        >
          <input
            v-model="savedQuery"
            class="input"
            :placeholder="savedKind === 'transition' ? 'Поиск связки' : 'Поиск сохранённой строки'"
            :aria-label="savedKind === 'transition' ? 'Поиск связки' : 'Поиск сохранённой строки'"
          /><button class="btn btn-secondary" :disabled="modalBusy">Найти</button>
        </form>
        <p v-if="!savedRows.length && !modalBusy" class="muted">
          {{
            savedKind === 'transition'
              ? 'Связок пока нет. Выберите группу со скобками и сохраните её кнопкой «Сохранить связку».'
              : 'Сохранённых строк пока нет. Создайте одну через меню строки в редакторе.'
          }}
        </p>
        <div v-for="row in savedRows" :key="row.id" class="saved-row-entry">
          <button class="saved-row-option" :disabled="modalBusy" @click="insertSaved(row.id)">
            <Bookmark :size="18" /><span
              ><strong>{{ row.title }}</strong
              ><small>{{ row.theme || 'Сохранённая последовательность' }}</small></span
            ><Plus :size="18" />
          </button>
          <div v-if="savedKind === 'transition' && row.relations?.length" class="saved-transition-variants">
            <button
              v-for="relation in row.relations"
              :key="relation.transition_id + relation.kind"
              class="btn btn-ghost"
              :disabled="modalBusy || !!relation.archived_at"
              @click="insertSaved(relation.transition_id)"
            >
              {{ relationLabels[relation.kind] }}: {{ relation.title
              }}{{ relation.archived_at ? ' · В архиве' : '' }}
            </button>
          </div>
        </div>
        <div v-if="savedLastPage > 1" class="form-actions">
          <button
            class="btn btn-secondary"
            :disabled="savedPage <= 1 || modalBusy"
            @click="
              savedPage--;
              loadSavedRows();
            "
          >
            Назад</button
          ><span>{{ savedPage }} / {{ savedLastPage }}</span
          ><button
            class="btn btn-secondary"
            :disabled="savedPage >= savedLastPage || modalBusy"
            @click="
              savedPage++;
              loadSavedRows();
            "
          >
            Далее
          </button>
        </div></template
      >
      <label v-if="modalKind === 'relocate'" class="field"
        ><span class="label">В конец блока</span
        ><select class="select" v-model="destinationBlock">
          <option
            v-for="block in doc?.blocks.filter((item) => item.id !== relocation?.block)"
            :key="block.id"
            :value="block.id"
          >
            {{ block.title }}
          </option>
        </select></label
      >
      <template v-if="modalKind === 'library-updates'">
        <p class="confirm-copy">
          Будут заменены только названия и изображения в этой практике. Подписи, стрелки, скобки и порядок
          ячеек сохранятся. Другие практики останутся прежними.
        </p>
        <div v-for="change in snapshotChanges" :key="change.snapshot_id" class="snapshot-change">
          <div>
            <img :src="change.before_image_url" :alt="change.before_name" /><span>{{
              change.before_name
            }}</span>
          </div>
          <ArrowRight :size="20" />
          <div>
            <img :src="change.after_image_url" :alt="change.after_name" /><span>{{ change.after_name }}</span>
          </div>
        </div>
      </template>
      <template v-if="modalKind === 'variants'">
        <p v-if="!variants.length && !modalBusy" class="muted">
          Варианты пока не заданы. Свяжите асаны в карточке библиотеки.
        </p>
        <button
          v-for="variant in variants"
          :key="variant.asana_id + variant.kind"
          class="saved-row-option"
          :disabled="modalBusy || !!variant.archived_at"
          @click="insertVariant(variant.asana_id)"
        >
          <img
            v-if="variant.image_url"
            :src="variant.image_url"
            :alt="variant.name"
            style="width: 55px; height: 55px; object-fit: contain"
          /><span
            ><strong>{{ variant.name }}</strong
            ><small
              >{{ relationLabels[variant.kind] }}{{ variant.archived_at ? ' · В архиве' : '' }}</small
            ></span
          >
        </button>
      </template>
      <template v-if="modalKind === 'transition-relations'">
        <p class="muted">Свяжите готовые переходы как упрощение, усложнение или подводящее упражнение.</p>
        <div
          v-for="(relation, index) in transitionRelations"
          :key="relation.transition_id + relation.kind"
          class="saved-row-option"
        >
          <span
            ><strong>{{ relation.title || 'Связка' }}</strong
            ><small
              >{{ relationLabels[relation.kind] }}{{ relation.archived_at ? ' · В архиве' : '' }}</small
            ></span
          ><button
            class="icon-btn"
            :aria-label="`Убрать вариант ${relation.title}`"
            @click="transitionRelations.splice(index, 1)"
          >
            <X :size="16" />
          </button>
        </div>
        <label class="field"
          ><span class="label">Вид варианта</span
          ><select v-model="transitionRelationKind" class="select">
            <option value="simpler">Упрощение</option>
            <option value="harder">Усложнение</option>
            <option value="preparation">Подводящее упражнение</option>
          </select></label
        >
        <label class="field"
          ><span class="label">Связка</span
          ><select v-model="transitionRelationId" class="select">
            <option value="">Выберите готовую связку</option>
            <option v-for="option in transitionOptions" :key="option.id" :value="option.id">
              {{ option.title }}
            </option>
          </select></label
        >
        <button
          class="btn btn-secondary"
          :disabled="!transitionRelationId || transitionRelations.length >= 30"
          @click="addTransitionRelation"
        >
          <Plus :size="16" />Добавить вариант
        </button>
      </template>
      <template #footer
        ><button class="btn btn-secondary" :disabled="modalBusy" @click="modalKind = ''">
          {{ rowModal ? 'Закрыть' : 'Отмена' }}</button
        ><button
          v-if="modalKind === 'relocate'"
          class="btn btn-primary"
          @click="
            relocation && transferRow(relocation.row, destinationBlock);
            modalKind = '';
          "
        >
          Переместить</button
        ><button
          v-else-if="modalKind === 'library-updates'"
          class="btn btn-primary"
          :disabled="modalBusy"
          @click="applyLibraryUpdates"
        >
          Подтвердить обновление</button
        ><button
          v-else-if="modalKind === 'transition-relations'"
          class="btn btn-primary"
          :disabled="modalBusy"
          @click="applyTransitionRelations"
        >
          Применить варианты</button
        ><button
          v-else-if="!rowModal && modalKind !== 'variants'"
          class="btn btn-primary"
          :disabled="modalBusy"
          @click="applyModal"
        >
          {{ modalBusy ? 'Сохраняем…' : modalKind === 'confirm' ? 'Подтвердить' : 'Сохранить' }}
        </button></template
      >
    </Modal>
    <DocumentSharingDialog
      v-if="doc && !rowMode && !embedded"
      :open="sharingOpen"
      :document-id="doc.id"
      :title="doc.title"
      @close="sharingOpen = false"
    />
  </AppShell>
</template>

<style scoped>
.saved-transition-variants {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin: -4px 0 12px 24px;
}
.saved-transition-variants .btn {
  font-size: 12px;
  white-space: normal;
  text-align: left;
}
.snapshot-change {
  display: flex;
  align-items: center;
  gap: 18px;
  margin: 16px 0;
}
.snapshot-change > div {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 1;
  font-size: 13px;
}
.snapshot-change img {
  width: 65px;
  height: 65px;
  object-fit: contain;
}
.embedded-editor :deep(.app-header),
.embedded-editor :deep(.app-footer) {
  display: none;
}
.embedded-editor :deep(.app-main) {
  width: 100%;
  padding: 12px;
}
.embedded-editor .editor-layout {
  display: block;
}
.embedded-editor .editor-title-area {
  display: none;
}
.embedded-editor .editor-header {
  margin-bottom: 8px;
}
.embedded-editor .document-meta {
  display: none;
}
.embedded-editor .editor-toolbar {
  max-height: 45vh;
}
.embedded-editor .block-header {
  padding: 10px;
}
.embedded-editor .block-rows {
  padding: 0 8px;
}
.embedded-editor .history-buttons .btn {
  padding: 6px;
  font-size: 11px;
}
.embedded-editor .editor-actions {
  gap: 4px;
  flex-wrap: wrap;
}
.embedded-editor .editor-actions .btn {
  padding: 6px;
  min-height: 32px;
  font-size: 10px;
}
.embedded-editor .save-toolbar {
  gap: 6px;
  padding-bottom: 8px;
}
.embedded-editor .save-toolbar .btn {
  font-size: 10px;
  min-height: 30px;
  padding: 5px;
}
.embedded-editor .save-status {
  font-size: 9px;
}
.embedded-editor :deep(.sequence-cell) {
  width: 30px;
  flex: 0 0 30px;
  height: 30px;
  padding: 2px;
  border-radius: 6px;
}
.embedded-editor .mobile-picker {
  display: none;
}
.embedded-editor :deep(.cells-grid) {
  gap: 3px;
}
.embedded-editor :deep(.cell-index) {
  left: 2px;
  top: 1px;
  font-size: 7px;
}
.embedded-editor :deep(.cell-empty-text) {
  display: none;
}
.embedded-editor :deep(.cell-plus) {
  width: 15px;
  height: 15px;
  margin-bottom: 0;
}
.embedded-editor :deep(.append-cell) {
  width: 20px;
  flex-basis: 20px;
  height: 28px;
}
.embedded-editor :deep(.row-header) {
  font-size: 9px;
  gap: 4px;
}
.embedded-editor .block-title {
  font-size: 12px;
}
.embedded-editor .block-number {
  width: 20px;
  height: 20px;
  font-size: 10px;
}
.embedded-editor .block-footer {
  flex-wrap: wrap;
  gap: 0;
  padding: 3px;
}
.embedded-editor .block-footer .btn {
  font-size: 10px;
  padding: 3px;
}
.editor-toolbar {
  position: sticky;
  top: 0;
  z-index: 40;
  display: flow-root;
  max-height: 50vh;
  max-height: 50dvh;
  overflow-y: auto;
  margin-bottom: 18px;
  padding: 0 0 10px;
  background: #f7f9f6;
  border-bottom: 1px solid var(--border);
  box-shadow: 0 6px 14px #283f2008;
}
.editor-toolbar > :last-child {
  margin-bottom: 0;
}
@media (min-width: 1001px) {
  .editor-layout :deep(.asana-palette) {
    top: calc(var(--editor-toolbar-height, 0px) + 12px);
    max-height: calc(100vh - var(--editor-toolbar-height, 0px) - 24px);
    max-height: calc(100dvh - var(--editor-toolbar-height, 0px) - 24px);
  }
}
.arrow-banner {
  border-color: #b4d5f5;
  background: #f2f8ff;
}
.arrow-banner button[aria-pressed='true'] {
  color: #125eae;
  border-color: #1670cf;
  background: #dfedff;
}

.group-banner {
  display: flex;
  align-items: center;
  gap: 18px;
  flex-wrap: wrap;
  padding: 16px;
  margin-bottom: 18px;
  border: 1px solid #cbdcbe;
  border-radius: 14px;
  background: #f4f9ef;
  box-shadow: 0 4px 14px #2531270a;
}
.group-instruction {
  flex: 1;
  min-width: 240px;
  font-size: 14px;
  color: #466335;
}
.group-instruction p {
  margin: 5px 0 0;
  line-height: 1.5;
}
.group-instruction .group-error {
  color: #985030;
}
.group-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.group-mode-button {
  font-size: 13px;
}
.practice-block.pointer-block-target {
  outline: 3px solid #7daa65;
  outline-offset: 3px;
}
:deep(.pointer-row-target) {
  outline: 2px solid #7daa65;
  outline-offset: 2px;
  border-radius: 10px;
  background: #f3faed;
}
:deep(.pointer-cell-target) {
  outline: 3px solid #397a28;
  outline-offset: 2px;
  background: #edf7e8 !important;
}
.editor-breadcrumb {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 13px;
  color: #909c88;
  margin-bottom: 20px;
}
.editor-breadcrumb button {
  display: flex;
  align-items: center;
  gap: 6px;
  color: #6f7e64;
  background: none;
  border: 0;
  padding: 0;
}
.editor-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  margin-bottom: 14px;
}
.editor-title-area {
  flex: 1;
  min-width: 0;
}
.document-title {
  font-size: 29px;
  font-weight: 750;
  letter-spacing: -0.7px;
  background: none;
  border: 1px solid transparent;
  border-radius: 8px;
  padding: 4px 0;
  max-width: 100%;
  width: 100%;
  color: #253127;
  line-height: 1.3;
}
.document-title:hover,
.block-title:hover {
  border-color: #d8e2d1;
}
.editor-subtitle {
  display: flex;
  align-items: center;
  gap: 9px;
  font-size: 13px;
  color: #8a967e;
  margin-top: 5px;
}
.divider-dot {
  color: #b9c3b1;
}
.editor-actions {
  display: flex;
  align-items: center;
  gap: 9px;
}
.document-meta {
  display: flex;
  align-items: center;
  gap: 15px;
  padding: 10px 0 20px;
  flex-wrap: wrap;
}
.theme-field {
  display: flex;
  gap: 10px;
  align-items: center;
  min-width: 230px;
  flex: 1;
}
.theme-field > span {
  font-size: 13px;
  color: #839078;
}
.theme-field input {
  border: 0;
  background: transparent;
  font-size: 14px;
  flex: 1;
  min-width: 120px;
  color: #617754;
  padding: 7px 0;
}
.document-meta .btn {
  font-size: 13px;
  min-height: 34px;
  padding: 7px 8px;
}
.save-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 15px;
  border-top: 1px solid var(--border);
  padding: 10px 0 18px;
}
.history-buttons {
  display: flex;
  gap: 3px;
  padding-right: 13px;
  border-right: 1px solid var(--border);
}
.save-status {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #829576;
}
.save-status.warning {
  color: #a15b32;
}
.editor-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 300px;
  gap: 26px;
  align-items: start;
}
.practice-canvas {
  min-width: 0;
}
.practice-block {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 17px;
  margin-bottom: 19px;
  box-shadow: 0 3px 8px #22331d02;
}
.block-header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 19px 20px 15px;
  border-bottom: 1px solid #edf1e9;
}
.block-handle {
  display: flex;
  color: #b6c2ac;
  cursor: grab;
}
.block-number {
  width: 27px;
  height: 27px;
  background: #edf5e7;
  color: #62854d;
  font-size: 13px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  font-weight: 700;
  flex-shrink: 0;
}
.block-title {
  border: 1px solid transparent;
  border-radius: 6px;
  min-width: 0;
  background: transparent;
  padding: 3px 5px;
  font-size: 17px;
  font-weight: 650;
  flex: 1;
  color: #3d4e32;
}
.block-menu {
  position: relative;
  margin-left: auto;
}
.block-menu summary {
  list-style: none;
}
.block-menu summary::-webkit-details-marker {
  display: none;
}
.block-popover {
  position: absolute;
  right: 0;
  top: 38px;
  padding: 6px;
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 12px;
  z-index: 15;
  box-shadow: 0 8px 30px #22332215;
  min-width: 195px;
}
.block-popover button {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  background: transparent;
  border: 0;
  text-align: left;
  padding: 10px;
  border-radius: 8px;
  font-size: 13px;
}
.block-popover button:hover {
  background: #f2f7ee;
}
.danger-text {
  color: #a8442a;
}
.block-rows {
  padding: 0 22px;
}
.block-purpose {
  padding: 14px 22px 0;
  font-size: 14px;
  line-height: 1.6;
  color: #7b8d6c;
}
.block-footer {
  padding: 3px 18px 13px;
  display: flex;
  gap: 12px;
}
.block-footer .btn {
  font-size: 12px;
  color: #78906a;
  min-height: 33px;
  padding: 7px;
}
.add-block {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 9px;
  width: 100%;
  padding: 20px;
  background: transparent;
  border: 1.5px dashed #cfdcc6;
  border-radius: 15px;
  font-size: 14px;
  color: #789269;
  font-weight: 600;
}
.add-block:hover {
  background: #edf5e7;
}
.canvas-help {
  text-align: center;
  font-size: 12px;
  color: #96a18c;
  line-height: 1.7;
  margin: 22px 12px;
}
.cell-toolbar {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
  margin: 0 0 20px;
  padding: 9px 12px;
  border: 1px solid #d9e8ce;
  background: #f1f8ec;
  border-radius: 13px;
}
.selected-label {
  font-weight: 600;
  font-size: 13px;
  margin-right: auto;
  max-width: 220px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.cell-toolbar .btn {
  font-size: 12px;
  min-height: 32px;
  padding: 7px 9px;
}
.notice {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  background: #edf5e7;
  border: 1px solid #dce9d1;
  padding: 10px 15px;
  border-radius: 12px;
  margin-bottom: 18px;
  font-size: 14px;
  line-height: 1.6;
}
.conflict-banner {
  padding: 20px;
  background: #fff5e5;
  border: 1px solid #ead6b5;
  border-radius: 14px;
  display: flex;
  align-items: center;
  gap: 15px;
  margin-bottom: 22px;
  flex-wrap: wrap;
}
.conflict-banner > div {
  flex: 1;
  min-width: 240px;
}
.conflict-banner p {
  font-size: 14px;
  line-height: 1.6;
  margin-top: 6px;
  color: #897042;
}
.transfer-banner {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 17px;
  background: #e9f5e0;
  border: 1px solid #b9d4a6;
  border-radius: 12px;
  margin-bottom: 16px;
  font-size: 14px;
}
.transfer-banner span {
  flex: 1;
}
.mobile-picker {
  display: none;
  margin-left: auto;
}
.saved-row-search {
  display: flex;
  gap: 9px;
  margin-bottom: 18px;
}
.saved-row-option {
  display: flex;
  align-items: center;
  gap: 14px;
  width: 100%;
  border: 1px solid var(--border);
  background: white;
  border-radius: 12px;
  padding: 16px;
  margin-bottom: 10px;
  text-align: left;
}
.saved-row-option:hover {
  background: #f4f9ef;
}
.saved-row-option span {
  flex: 1;
}
.saved-row-option strong {
  display: block;
  font-size: 15px;
}
.saved-row-option small {
  display: block;
  font-size: 12px;
  color: var(--muted);
  margin-top: 5px;
}
.confirm-copy {
  line-height: 1.7;
  font-size: 15px;
}
.editor-loading {
  max-width: 100%;
}
@media (min-width: 1550px) {
  .editor-layout {
    grid-template-columns: minmax(0, 1fr) 330px;
    gap: 32px;
  }
}
@media (max-width: 1000px) {
  .editor-layout {
    grid-template-columns: 1fr;
  }
  .mobile-picker {
    display: inline-flex;
  }
  .document-title {
    font-size: 26px;
  }
  .editor-header {
    gap: 14px;
  }
  .editor-actions .btn {
    padding: 10px 12px;
  }
  .document-meta {
    gap: 6px;
  }
  .block-rows {
    padding: 0 17px;
  }
}
@media (max-width: 600px) {
  .editor-header {
    flex-wrap: wrap;
    gap: 16px;
  }
  .editor-title-area {
    flex-basis: 100%;
  }
  .editor-actions {
    width: 100%;
  }
  .editor-actions .btn {
    flex: 1;
  }
  .editor-subtitle {
    font-size: 12px;
  }
  .theme-field {
    flex-basis: 100%;
  }
  .save-toolbar {
    gap: 9px;
  }
  .save-status {
    font-size: 11px;
    max-width: 145px;
  }
  .mobile-picker {
    font-size: 12px;
    padding: 8px 11px;
    min-height: 36px;
  }
  .history-buttons {
    padding-right: 6px;
  }
  .history-buttons .icon-btn {
    width: 30px;
  }
  .block-header {
    padding: 15px 12px;
  }
  .block-rows {
    padding: 0 13px;
  }
  .block-footer {
    gap: 2px;
    padding: 3px 9px 12px;
    flex-wrap: wrap;
  }
  .block-title {
    font-size: 16px;
  }
  .selected-label {
    flex-basis: 100%;
    max-width: 100%;
    margin-bottom: 5px;
  }
  .canvas-help {
    margin-bottom: 40px;
  }
  .conflict-banner .btn {
    width: 100%;
  }
}
</style>
