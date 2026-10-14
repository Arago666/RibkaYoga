<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { DialogRoot, DialogOverlay, DialogContent, DialogTitle } from 'reka-ui';
import {
  Pencil,
  Eraser,
  Undo2,
  Redo2,
  Trash2,
  Download,
  Upload,
  Check,
  MousePointer2,
  Hand,
  Minus,
  Plus,
  Circle,
  Spline,
  PersonStanding,
  Copy,
  RotateCw,
  Maximize2,
  FlipHorizontal2,
} from '@lucide/vue';
import { uid } from '@/shared/uuid';
import { canvasFile, downloadJson, imageFileName, type ImageTaskContext } from '../imageTools';
import {
  cloneDrawing,
  drawingLimits,
  drawingPointCount,
  emptyDrawing,
  parseDrawing,
  type DrawingDocument,
  type DrawingElement,
  type DrawingKind,
  type Point,
} from '../drawing';
import {
  boundsCenter,
  curveMidpoint,
  confineElement,
  drawingHeadCenter,
  drawElement,
  editPoints,
  elementBounds,
  hitElement,
  jointNames,
  lineToCurve,
  mirrorElement,
  moveElement,
  rotatePoint,
  rotationHandle,
  quadraticControlPoint,
  selectionCorners,
  snapLineEndpoint,
  worldPoint,
  type DrawingGuide,
} from '../drawingGeometry';

const props = withDefaults(
  defineProps<{
    context: ImageTaskContext;
    disabled?: boolean;
    initialDrawing?: DrawingDocument | null;
    draftKey?: string;
    active?: boolean;
  }>(),
  { disabled: false, initialDrawing: null, draftKey: '', active: true },
);
const emit = defineEmits<{
  image: [file: File, drawing: DrawingDocument];
  change: [drawing: DrawingDocument];
  dirty: [dirty: boolean];
  close: [];
}>();

type Tool = DrawingKind | 'asana' | 'select' | 'rotate' | 'pan';
interface Snapshot {
  drawing: DrawingDocument;
  brushWidth: number;
}
interface Interaction {
  mode: 'draw' | 'move' | 'resize' | 'head-resize' | 'rotate' | 'point' | 'pan';
  pointer: number;
  start: Point;
  before: string;
  original?: DrawingElement;
  element?: DrawingElement;
  index?: number;
  pan?: Point;
  head?: boolean;
  headDragged?: boolean;
  mirrorAxis?: number;
  mirrorId?: string;
  previousSegment?: [Point, Point];
}
const root = ref<HTMLElement | null>(null);
const viewport = ref<HTMLDivElement | null>(null);
const canvas = ref<HTMLCanvasElement | null>(null);
const canvasWidth = ref(1024);
const importInput = ref<HTMLInputElement | null>(null);
const drawing = ref<DrawingDocument>(emptyDrawing());
const tool = ref<Tool>('asana');
const symmetry = ref(false),
  snapping = ref(true);
const headCenter = computed(() => drawingHeadCenter(drawing.value.elements));
const guides = ref<DrawingGuide[]>([]),
  snapLabel = ref<string | null>(null);
const branch = ref<{ start: Point; segment?: [Point, Point] } | null>(null);
const showSelection = computed(() => ['asana', 'select', 'rotate'].includes(tool.value));
const brushWidth = ref(5);
const selectedId = ref<string | null>(null);
const selected = computed(
  () => drawing.value.elements.find((element) => element.id === selectedId.value) ?? null,
);
const current = ref<DrawingElement | null>(null);
const interacting = ref(false);
const undoStack = ref<string[]>([]),
  redoStack = ref<string[]>([]);
const error = ref(''),
  draftError = ref(''),
  busy = ref(false),
  dirty = ref(false);
const restoreCandidate = ref<DrawingDocument | null>(null);
const zoom = ref(1),
  pan = ref<Point>({ x: 0, y: 0 }),
  fullscreen = ref(false);
const pointIndex = ref(0),
  spacePan = ref(false);
const locked = computed(() => props.disabled || busy.value);
const tools = [
  { id: 'asana', label: 'Асана', hint: 'Сначала голова, затем линии (A)', icon: PersonStanding },
  { id: 'select', label: 'Выделение', hint: 'Выделить и изменить элемент (V)', icon: MousePointer2 },
  { id: 'rotate', label: 'Вращение', hint: 'Вращать выбранный элемент (R)', icon: RotateCw },
  { id: 'pen', label: 'Перо', hint: 'Рисовать от руки (P)', icon: Pencil },
  { id: 'eraser', label: 'Ластик', hint: 'Стереть часть рисунка (E)', icon: Eraser },
  { id: 'line', label: 'Линия', hint: 'Провести прямую линию (L)', icon: Minus },
  { id: 'curve', label: 'Кривая', hint: 'Нарисовать изгиб и изменить его точки (C)', icon: Spline },
  { id: 'ellipse', label: 'Круг', hint: 'Нарисовать круг или эллипс (O)', icon: Circle },
  { id: 'pan', label: 'Рука', hint: 'Двигать увеличенный лист (H или пробел)', icon: Hand },
] as const;
const kindNames: Record<DrawingKind, string> = {
  pen: 'Линия от руки',
  eraser: 'След ластика',
  line: 'Прямая линия',
  curve: 'Кривая',
  ellipse: 'Круг / эллипс',
  figure: 'Человечек',
};
const editablePoints = computed(() =>
  selected.value && ['line', 'curve', 'figure'].includes(selected.value.kind)
    ? selected.value.points.map((point, index) => ({
        ...(selected.value!.kind === 'curve' && index === 1
          ? curveMidpoint(selected.value!)
          : worldPoint(point, selected.value!)),
        index,
      }))
    : [],
);
const corners = computed(() => (selected.value ? selectionCorners(selected.value) : []));
const rotateHandle = computed(() => (selected.value ? rotationHandle(selected.value) : null));
const selectedBounds = computed(() => (selected.value ? elementBounds(selected.value) : null));
const polygon = computed(() => corners.value.map((point) => `${point.x},${point.y}`).join(' '));
const surfaceStyle = computed(() => ({
  transform: `translate(${pan.value.x}px, ${pan.value.y}px) scale(${zoom.value})`,
}));
const pointNames = computed(() =>
  selected.value?.kind === 'figure'
    ? jointNames
    : selected.value?.kind === 'curve'
      ? ['Начало', 'Изгиб', 'Конец']
      : ['Начало', 'Конец'],
);
let interaction: Interaction | null = null;
let frame = 0;
let canvasObserver: ResizeObserver | null = null;
let draftTimer: ReturnType<typeof setTimeout> | null = null;
let currentDraftKey = '';
let appliedFingerprint = JSON.stringify(emptyDrawing());
let lastSnapshot = '';
let initialized = false;
let initialVersion = 0;
let pendingCardSave = false;
let lastWrittenDraft: string | null = null;
let offeredDraft: string | null = null;
let numericResize: { id: string; axis: 'width' | 'height'; original: DrawingElement; before: string } | null =
  null;

function snapshot(): string {
  return JSON.stringify({ drawing: drawing.value, brushWidth: brushWidth.value } satisfies Snapshot);
}
function fingerprint(): string {
  return JSON.stringify(drawing.value);
}
function updateDirty() {
  dirty.value = fingerprint() !== appliedFingerprint;
  emit('dirty', dirty.value);
}
function trimHistory(stack: string[]) {
  let size = stack.reduce((total, item) => total + item.length, 0);
  while (stack.length > 30 || (size > 12 * 1024 * 1024 && stack.length > 1)) size -= stack.shift()!.length;
}
function changed() {
  restoreCandidate.value = null;
  updateDirty();
  emit('change', cloneDrawing(drawing.value));
  saveDraftSoon();
  scheduleRender();
}
function commit(before = lastSnapshot) {
  const after = snapshot();
  if (before && before !== after) {
    undoStack.value.push(before);
    trimHistory(undoStack.value);
    redoStack.value = [];
    changed();
  }
  lastSnapshot = after;
  numericResize = null;
  scheduleRender();
}
function restoreSnapshot(value: string) {
  const state = JSON.parse(value) as Snapshot;
  drawing.value = parseDrawing(state.drawing);
  brushWidth.value = state.brushWidth;
  if (!selected.value) selectedId.value = null;
  pointIndex.value = 0;
  branch.value = null;
  guides.value = [];
  snapLabel.value = null;
  lastSnapshot = snapshot();
  changed();
}
function undo() {
  if (locked.value || interacting.value) return;
  const previous = undoStack.value.pop();
  if (!previous) return;
  redoStack.value.push(snapshot());
  restoreSnapshot(previous);
}
function redo() {
  if (locked.value || interacting.value) return;
  const next = redoStack.value.pop();
  if (!next) return;
  undoStack.value.push(snapshot());
  restoreSnapshot(next);
}
function draftStorageKey(): string {
  return `ribka-drawing-draft:${currentDraftKey}`;
}
function saveDraftSoon() {
  if (draftTimer) clearTimeout(draftTimer);
  draftTimer = setTimeout(saveDraft, 350);
}
function saveDraft() {
  if (draftTimer) clearTimeout(draftTimer);
  draftTimer = null;
  if (!currentDraftKey || !initialized) return;
  try {
    const source = fingerprint();
    // Opening or closing an unchanged card must not replace a recoverable draft.
    if (source === appliedFingerprint && !pendingCardSave) {
      if (lastWrittenDraft && localStorage.getItem(draftStorageKey()) === lastWrittenDraft)
        localStorage.removeItem(draftStorageKey());
      lastWrittenDraft = null;
      return;
    }
    if (new TextEncoder().encode(source).length > drawingLimits.bytes)
      throw new Error('Исходник рисунка больше 4 МБ. Удалите лишние элементы и скачайте исходник.');
    localStorage.setItem(draftStorageKey(), source);
    lastWrittenDraft = source;
    draftError.value = '';
  } catch (failure) {
    draftError.value =
      failure instanceof Error && failure.message.includes('4 МБ')
        ? failure.message
        : 'Браузер не смог сохранить черновик. Скачайте исходник, чтобы не потерять рисунок.';
  }
}
function offerDraft() {
  restoreCandidate.value = null;
  offeredDraft = null;
  if (!currentDraftKey) return;
  try {
    const source = localStorage.getItem(draftStorageKey());
    if (!source || source === appliedFingerprint) return;
    restoreCandidate.value = parseDrawing(JSON.parse(source));
    offeredDraft = source;
  } catch {
    draftError.value =
      'Сохранённый черновик не удалось открыть. Можно продолжить работу и сохранить новый исходник.';
  }
}
function restoreDraft() {
  if (!restoreCandidate.value || locked.value || interacting.value) return;
  const before = snapshot();
  drawing.value = cloneDrawing(restoreCandidate.value);
  restoreCandidate.value = null;
  selectedId.value = null;
  commit(before);
}
function ignoreDraft() {
  if (locked.value) return;
  restoreCandidate.value = null;
  try {
    if (currentDraftKey && localStorage.getItem(draftStorageKey()) === offeredDraft)
      localStorage.removeItem(draftStorageKey());
  } catch {
    /* Storage can be unavailable. */
  }
  draftError.value = '';
  offeredDraft = null;
}
function discardDraft() {
  if (draftTimer) clearTimeout(draftTimer);
  draftTimer = null;
  try {
    if (currentDraftKey) {
      const stored = localStorage.getItem(draftStorageKey());
      if (stored === lastWrittenDraft || stored === fingerprint()) localStorage.removeItem(draftStorageKey());
    }
  } catch {
    /* The saved card remains authoritative. */
  }
  restoreCandidate.value = null;
  draftError.value = '';
  appliedFingerprint = fingerprint();
  pendingCardSave = false;
  lastWrittenDraft = null;
  offeredDraft = null;
  updateDirty();
}
function hasUnappliedChanges(): boolean {
  return (
    fingerprint() !== appliedFingerprint ||
    Boolean(current.value) ||
    Boolean(interaction && interaction.mode !== 'pan' && snapshot() !== interaction.before)
  );
}
watch(
  [() => props.initialDrawing, () => props.draftKey],
  async ([source, key]) => {
    const version = ++initialVersion;
    let initial: DrawingDocument;
    try {
      initial = source ? parseDrawing(source) : emptyDrawing();
    } catch (failure) {
      error.value = failure instanceof Error ? failure.message : 'Не удалось открыть рисунок.';
      return;
    }
    const nextFingerprint = JSON.stringify(initial);
    if (initialized && key === currentDraftKey && nextFingerprint === fingerprint()) {
      appliedFingerprint = nextFingerprint;
      updateDirty();
      return;
    }
    if (initialized && draftTimer) saveDraft();
    currentDraftKey = key;
    pendingCardSave = false;
    lastWrittenDraft = null;
    offeredDraft = null;
    drawing.value = initial;
    appliedFingerprint = nextFingerprint;
    selectedId.value = null;
    current.value = null;
    numericResize = null;
    interaction = null;
    branch.value = null;
    guides.value = [];
    snapLabel.value = null;
    interacting.value = false;
    undoStack.value = [];
    redoStack.value = [];
    lastSnapshot = snapshot();
    initialized = true;
    updateDirty();
    offerDraft();
    await nextTick();
    if (version === initialVersion) render();
  },
  { immediate: true },
);
watch(
  () => props.active,
  (value) => {
    if (!value) {
      spacePan.value = false;
      fullscreen.value = false;
      if (interaction) finish();
    }
  },
);
watch(fullscreen, async () => {
  await nextTick();
  render();
  if (props.active) canvas.value?.focus({ preventScroll: true });
});
watch(
  canvas,
  (element) => {
    canvasObserver?.disconnect();
    canvasObserver = null;
    if (!element) return;
    canvasWidth.value = element.getBoundingClientRect().width / zoom.value;
    canvasObserver = new ResizeObserver(([entry]) => {
      if (entry) canvasWidth.value = entry.contentRect.width;
    });
    canvasObserver.observe(element);
  },
  { flush: 'post' },
);

function renderTo(target: HTMLCanvasElement) {
  const ctx = target.getContext('2d');
  if (!ctx) throw new Error('Браузер не поддерживает рисование.');
  ctx.clearRect(0, 0, 1024, 1024);
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, 1024, 1024);
  for (const element of drawing.value.elements) drawElement(ctx, element, drawing.value.smoothing);
  if (current.value) drawElement(ctx, current.value, drawing.value.smoothing);
  const mirrored = mirroredCurrent();
  if (mirrored) drawElement(ctx, mirrored, drawing.value.smoothing);
}
function render() {
  if (canvas.value) renderTo(canvas.value);
}
function scheduleRender() {
  if (frame) return;
  frame = requestAnimationFrame(() => {
    frame = 0;
    render();
  });
}
function pointFromEvent(event: Pick<MouseEvent, 'clientX' | 'clientY'>, clamp = false): Point {
  const rect = canvas.value!.getBoundingClientRect();
  const point = {
    x: ((event.clientX - rect.left) / rect.width) * 1024,
    y: ((event.clientY - rect.top) / rect.height) * 1024,
  };
  return clamp ? clampPoint(point) : point;
}
function clampPoint(point: Point): Point {
  return { x: Math.max(0, Math.min(1024, point.x)), y: Math.max(0, Math.min(1024, point.y)) };
}
function hitRadius(): number {
  return (11 * 1024) / Math.max(1, canvasWidth.value * zoom.value);
}
function near(a: Point, b: Point, radius = hitRadius()): boolean {
  return Math.hypot(a.x - b.x, a.y - b.y) <= radius;
}
function setTool(value: Tool) {
  if (locked.value || interacting.value) return;
  tool.value = value;
  branch.value = null;
  guides.value = [];
  snapLabel.value = null;
  error.value = '';
}
function capture(event: PointerEvent, value: Interaction) {
  interaction = value;
  interacting.value = true;
  viewport.value?.setPointerCapture(event.pointerId);
}
function endpointAt(point: Point) {
  for (const element of [...drawing.value.elements].reverse()) {
    if (element.kind === 'ellipse') {
      const bounds = elementBounds(element),
        center = boundsCenter(bounds);
      const local = rotatePoint(point, center, -element.rotation);
      const angle = Math.atan2((local.y - center.y) / bounds.height, (local.x - center.x) / bounds.width);
      const rim = worldPoint(
        {
          x: center.x + (Math.cos(angle) * bounds.width) / 2,
          y: center.y + (Math.sin(angle) * bounds.height) / 2,
        },
        element,
      );
      if (near(rim, point)) return { element, index: 1, point: rim, segment: undefined };
      continue;
    }
    if (!['line', 'curve'].includes(element.kind)) continue;
    for (const index of [element.points.length - 1, 0]) {
      const endpoint = worldPoint(element.points[index], element);
      if (near(endpoint, point)) {
        const adjacent = worldPoint(element.points[index === 0 ? 1 : index - 1], element);
        return { element, index, point: endpoint, segment: [adjacent, endpoint] as [Point, Point] };
      }
    }
  }
  return null;
}
function elementAt(point: Point) {
  const ctx = canvas.value!.getContext('2d')!;
  return [...drawing.value.elements]
    .reverse()
    .find((item) => hitElement(ctx, item, point, drawing.value.smoothing, hitRadius() / 2));
}
function mirroredCurrent(): DrawingElement | null {
  if (!current.value || interaction?.mirrorAxis === undefined || !interaction.mirrorId) return null;
  const mirrored = mirrorElement(current.value, interaction.mirrorAxis, interaction.mirrorId);
  return current.value.points.every((point, index) => near(point, mirrored.points[index], 0.001))
    ? null
    : mirrored;
}
function limitDrawPoint(point: Point, state: Interaction): Point {
  const margin = (state.element?.width ?? brushWidth.value) / 2 + 1;
  const low =
    state.mirrorAxis === undefined ? margin : Math.max(margin, 2 * state.mirrorAxis - 1024 + margin);
  const high =
    state.mirrorAxis === undefined ? 1024 - margin : Math.min(1024 - margin, 2 * state.mirrorAxis - margin);
  return { x: Math.max(low, Math.min(high, point.x)), y: Math.max(margin, Math.min(1024 - margin, point.y)) };
}
function snappedPoint(
  start: Point,
  end: Point,
  event: PointerEvent,
  previousSegment?: [Point, Point],
): Point {
  guides.value = [];
  snapLabel.value = null;
  if ((!snapping.value && !event.shiftKey) || event.altKey) return end;
  if (event.shiftKey) {
    end =
      Math.abs(end.x - start.x) < Math.abs(end.y - start.y)
        ? { x: start.x, y: end.y }
        : { x: end.x, y: start.y };
  }
  const result = snapLineEndpoint(start, end, {
    threshold: hitRadius() * 0.65,
    headCenter: headCenter.value,
    previousSegment,
  });
  guides.value = result.guides;
  snapLabel.value = result.label;
  return result.point;
}
function beginDrawing(
  event: PointerEvent,
  base: Interaction,
  kind: DrawingKind,
  start: Point,
  previousSegment?: [Point, Point],
  head = false,
) {
  const axis =
    symmetry.value && headCenter.value && ['line', 'curve'].includes(kind) ? headCenter.value.x : undefined;
  // A gesture on the symmetry axis needs only one element; check the actual pair on release.
  const copies = 1;
  const requiredPoints = kind === 'curve' ? 3 : ['line', 'ellipse'].includes(kind) ? 2 : 1;
  if (
    drawing.value.elements.length + copies > drawingLimits.elements ||
    drawingPointCount(drawing.value) + requiredPoints * copies > drawingLimits.points
  ) {
    error.value = 'Достигнут предел размера рисунка. Скачайте исходник или удалите лишние элементы.';
    return;
  }
  const element: DrawingElement = {
    id: uid(),
    kind,
    width: kind === 'eraser' ? Math.min(100, brushWidth.value * 4) : brushWidth.value,
    rotation: 0,
    points: Array.from({ length: requiredPoints }, () => ({ ...start })),
  };
  const state: Interaction = {
    ...base,
    mode: 'draw',
    start,
    element,
    previousSegment,
    head,
    mirrorAxis: axis,
    mirrorId: axis === undefined ? undefined : uid(),
  };
  if (axis !== undefined && !near(start, limitDrawPoint(start, state), 0.001)) {
    error.value =
      'От этой точки зеркальная линия выйдет за лист. Выберите другую точку или отключите симметрию.';
    return;
  }
  if (head) {
    const radius = 32,
      margin = radius + element.width / 2 + 1;
    const center = {
      x: Math.max(margin, Math.min(1024 - margin, start.x)),
      y: Math.max(margin, Math.min(1024 - margin, start.y)),
    };
    state.start = center;
    element.points = [
      { x: center.x - radius, y: center.y - radius },
      { x: center.x + radius, y: center.y + radius },
    ];
  }
  current.value = element;
  selectedId.value = null;
  capture(event, state);
  scheduleRender();
}
function begin(event: PointerEvent) {
  if (locked.value || interaction || !event.isPrimary || !canvas.value || ![0, 1, 2].includes(event.button))
    return;
  canvas.value.focus({ preventScroll: true });
  error.value = '';
  const point = pointFromEvent(event);
  const base: Interaction = { mode: 'pan', pointer: event.pointerId, start: point, before: snapshot() };
  if (tool.value === 'pan' || spacePan.value || event.button === 1) {
    capture(event, { ...base, start: { x: event.clientX, y: event.clientY }, pan: { ...pan.value } });
    return;
  }
  if (event.button === 2) {
    if (!['asana', 'line', 'curve', 'select'].includes(tool.value)) return;
    const endpoint = endpointAt(point);
    if (endpoint) beginDrawing(event, base, 'line', endpoint.point, endpoint.segment);
    return;
  }
  if (branch.value) {
    const next = branch.value;
    branch.value = null;
    beginDrawing(event, base, 'line', next.start, next.segment);
    if (interaction) move(event);
    return;
  }
  if (tool.value === 'rotate') {
    const found = elementAt(point) ?? selected.value;
    if (found) {
      selectedId.value = found.id;
      capture(event, { ...base, mode: 'rotate', original: JSON.parse(JSON.stringify(found)) });
    }
    return;
  }
  if (tool.value === 'asana') {
    const endpoint = endpointAt(point);
    if (endpoint && endpoint.element.kind !== 'ellipse') {
      selectedId.value = endpoint.element.id;
      pointIndex.value = endpoint.index;
      capture(event, {
        ...base,
        mode: 'point',
        index: endpoint.index,
        original: JSON.parse(JSON.stringify(endpoint.element)),
      });
      return;
    }
    const head = drawing.value.elements.find((element) => element.kind === 'ellipse');
    if (head && Math.abs(elementBounds(head).width - elementBounds(head).height) < 1) {
      const center = boundsCenter(elementBounds(head));
      const radius = elementBounds(head).width / 2;
      if (Math.abs(Math.hypot(point.x - center.x, point.y - center.y) - radius) <= hitRadius()) {
        selectedId.value = head.id;
        capture(event, { ...base, mode: 'head-resize', original: JSON.parse(JSON.stringify(head)) });
        return;
      }
    }
  }
  if (tool.value === 'select' || tool.value === 'asana') {
    const element = selected.value;
    if (element) {
      const node = editablePoints.value.find((handle) => near(handle, point));
      if (node) {
        pointIndex.value = node.index;
        capture(event, {
          ...base,
          mode: 'point',
          original: JSON.parse(JSON.stringify(element)),
          index: node.index,
        });
        return;
      }
      const handle = rotationHandle(element);
      if (near(handle, point)) {
        capture(event, { ...base, mode: 'rotate', original: JSON.parse(JSON.stringify(element)) });
        return;
      }
      const corner = selectionCorners(element).findIndex((handle) => near(handle, point));
      if (corner >= 0) {
        capture(event, {
          ...base,
          mode: 'resize',
          original: JSON.parse(JSON.stringify(element)),
          index: corner,
        });
        return;
      }
    }
    const found = elementAt(point);
    selectedId.value = found?.id ?? null;
    pointIndex.value = 0;
    if (found) {
      capture(event, { ...base, mode: 'move', original: JSON.parse(JSON.stringify(found)) });
      return;
    }
    if (tool.value === 'select') return;
  }
  const head = tool.value === 'asana' && !drawing.value.elements.length;
  beginDrawing(
    event,
    base,
    tool.value === 'asana' ? (head ? 'ellipse' : 'line') : (tool.value as DrawingKind),
    clampPoint(point),
    undefined,
    head,
  );
}
function replaceSelected(element: DrawingElement) {
  const index = drawing.value.elements.findIndex((item) => item.id === element.id);
  if (index >= 0) drawing.value.elements[index] = confineElement(element);
  scheduleRender();
}
function move(event: PointerEvent) {
  if (!interaction || interaction.pointer !== event.pointerId || !canvas.value) return;
  const state = interaction;
  if (state.mode === 'pan') {
    pan.value = {
      x: state.pan!.x + event.clientX - state.start.x,
      y: state.pan!.y + event.clientY - state.start.y,
    };
    return;
  }
  const point = pointFromEvent(event);
  if (state.mode === 'draw') {
    const element = current.value!,
      end = limitDrawPoint(clampPoint(point), state);
    if (element.kind === 'pen' || element.kind === 'eraser') {
      if (
        element.points.length >= drawingLimits.strokePoints ||
        drawingPointCount(drawing.value) + element.points.length >= drawingLimits.points
      )
        return;
      if (!near(element.points[element.points.length - 1], end, 1)) element.points.push(end);
    } else if (state.head) {
      if (!state.headDragged && near(end, state.start, hitRadius() * 0.4)) return;
      state.headDragged = true;
      const radius = Math.max(
        3,
        Math.min(
          Math.hypot(end.x - state.start.x, end.y - state.start.y),
          state.start.x - element.width / 2 - 1,
          1024 - state.start.x - element.width / 2 - 1,
          state.start.y - element.width / 2 - 1,
          1024 - state.start.y - element.width / 2 - 1,
        ),
      );
      element.points = [
        { x: state.start.x - radius, y: state.start.y - radius },
        { x: state.start.x + radius, y: state.start.y + radius },
      ];
    } else if (element.kind === 'curve') {
      const dx = end.x - state.start.x,
        dy = end.y - state.start.y;
      element.points = [
        state.start,
        limitDrawPoint(
          { x: (state.start.x + end.x) / 2 - dy * 0.2, y: (state.start.y + end.y) / 2 + dx * 0.2 },
          state,
        ),
        end,
      ];
    } else {
      let destination = end;
      if (element.kind === 'line') {
        destination = snappedPoint(state.start, end, event, state.previousSegment);
        const limited = limitDrawPoint(destination, state);
        if (!near(limited, destination, 0.001)) {
          guides.value = [];
          snapLabel.value = null;
        }
        destination = limited;
      }
      if (element.kind === 'ellipse' && event.shiftKey) {
        const side = Math.min(Math.abs(end.x - state.start.x), Math.abs(end.y - state.start.y));
        destination = {
          x: state.start.x + Math.sign(end.x - state.start.x) * side,
          y: state.start.y + Math.sign(end.y - state.start.y) * side,
        };
      }
      element.points[1] = destination;
    }
    scheduleRender();
    return;
  }
  const original = state.original!;
  if (state.mode === 'move') {
    replaceSelected(moveElement(original, point.x - state.start.x, point.y - state.start.y));
  } else if (state.mode === 'rotate') {
    const center = boundsCenter(elementBounds(original));
    const angle =
      ((Math.atan2(point.y - center.y, point.x - center.x) -
        Math.atan2(state.start.y - center.y, state.start.x - center.x)) *
        180) /
      Math.PI;
    let rotation = original.rotation + angle;
    if (event.shiftKey) rotation = Math.round(rotation / 15) * 15;
    else if (snapping.value && !event.altKey && Math.abs(rotation - Math.round(rotation / 90) * 90) <= 4) {
      rotation = Math.round(rotation / 90) * 90;
    }
    snapLabel.value = `${Math.round(((rotation + 540) % 360) - 180)}°`;
    replaceSelected({ ...original, rotation: ((rotation + 540) % 360) - 180 });
  } else if (state.mode === 'point') {
    const world =
      original.kind === 'line'
        ? snappedPoint(
            worldPoint(original.points[state.index === 0 ? 1 : 0], original),
            clampPoint(point),
            event,
          )
        : point;
    const local = clampPoint(rotatePoint(world, boundsCenter(elementBounds(original)), -original.rotation));
    const points = original.points.map((previous, index) =>
      index === state.index
        ? original.kind === 'curve' && index === 1
          ? quadraticControlPoint(original.points[0], original.points[2], local)
          : local
        : { ...previous },
    );
    replaceSelected(editPoints(original, points));
  } else if (state.mode === 'head-resize') {
    const center = boundsCenter(elementBounds(original));
    const radius = Math.max(
      3,
      Math.min(
        Math.hypot(point.x - center.x, point.y - center.y),
        center.x - original.width / 2 - 1,
        1024 - center.x - original.width / 2 - 1,
        center.y - original.width / 2 - 1,
        1024 - center.y - original.width / 2 - 1,
      ),
    );
    replaceSelected({
      ...original,
      rotation: 0,
      points: [
        { x: center.x - radius, y: center.y - radius },
        { x: center.x + radius, y: center.y + radius },
      ],
    });
  } else if (state.mode === 'resize') {
    const bounds = elementBounds(original),
      center = boundsCenter(bounds);
    const local = rotatePoint(point, center, -original.rotation);
    const fixed = [
      { x: bounds.x + bounds.width, y: bounds.y + bounds.height },
      { x: bounds.x, y: bounds.y + bounds.height },
      { x: bounds.x, y: bounds.y },
      { x: bounds.x + bounds.width, y: bounds.y },
    ][state.index!];
    const scaleX = Math.max(0.03, Math.abs(local.x - fixed.x) / bounds.width);
    const scaleY = Math.max(0.03, Math.abs(local.y - fixed.y) / bounds.height);
    const sx = event.shiftKey ? Math.min(scaleX, scaleY) : scaleX,
      sy = event.shiftKey ? Math.min(scaleX, scaleY) : scaleY;
    const points = original.points.map((previous) =>
      clampPoint({ x: fixed.x + (previous.x - fixed.x) * sx, y: fixed.y + (previous.y - fixed.y) * sy }),
    );
    replaceSelected(editPoints(original, points));
  }
}
function finish(event?: PointerEvent) {
  if (!interaction || (event && event.pointerId !== interaction.pointer)) return;
  const state = interaction;
  if (state.mode === 'draw' && current.value) {
    const element = current.value;
    const valid =
      ['pen', 'eraser'].includes(element.kind) ||
      Math.hypot(
        element.points[0].x - element.points[element.points.length - 1].x,
        element.points[0].y - element.points[element.points.length - 1].y,
      ) >= 2;
    if (valid) {
      const mirrored = mirroredCurrent();
      const additions = mirrored ? [element, mirrored] : [element];
      if (
        drawing.value.elements.length + additions.length > drawingLimits.elements ||
        drawingPointCount(drawing.value) + additions.reduce((sum, item) => sum + item.points.length, 0) >
          drawingLimits.points
      ) {
        error.value =
          'Для зеркальной пары не хватает места в исходнике. Удалите лишние элементы или отключите симметрию.';
      } else {
        drawing.value.elements.push(state.mirrorAxis === undefined ? confineElement(element) : element);
        if (mirrored) drawing.value.elements.push(mirrored);
        selectedId.value = element.id;
        pointIndex.value = element.points.length - 1;
        if (element.kind === 'curve') tool.value = 'select';
      }
    } else if (element.kind === 'line' && event?.type === 'pointerup') {
      branch.value = { start: state.start, segment: state.previousSegment };
    }
  }
  current.value = null;
  interaction = null;
  interacting.value = false;
  guides.value = [];
  snapLabel.value = null;
  releasePointer(state.pointer);
  if (state.mode !== 'pan') commit(state.before);
  scheduleRender();
}
function cancelInteraction(event?: PointerEvent) {
  if (!interaction || (event && event.pointerId !== interaction.pointer)) return;
  const state = interaction;
  if (state.original) replaceSelected(state.original);
  current.value = null;
  interaction = null;
  interacting.value = false;
  guides.value = [];
  snapLabel.value = null;
  releasePointer(state.pointer);
  scheduleRender();
}
function releasePointer(pointer: number) {
  if (viewport.value?.hasPointerCapture(pointer)) viewport.value.releasePointerCapture(pointer);
}
function bendSelected(id = selectedId.value) {
  if (locked.value || interacting.value) return;
  const element = drawing.value.elements.find((item) => item.id === id);
  if (!element || !['line', 'curve'].includes(element.kind)) return;
  if (element.kind === 'line') {
    if (drawingPointCount(drawing.value) >= drawingLimits.points) {
      error.value = 'В рисунке достигнут предел числа точек.';
      return;
    }
    const before = snapshot();
    replaceSelected(lineToCurve(element));
    commit(before);
  }
  selectedId.value = element.id;
  pointIndex.value = 1;
  tool.value = 'select';
  branch.value = null;
}
function doubleClick(event: MouseEvent) {
  if (
    locked.value ||
    interacting.value ||
    !canvas.value ||
    !['asana', 'select', 'line', 'curve'].includes(tool.value)
  )
    return;
  const point = pointFromEvent(event);
  const element = [...drawing.value.elements].reverse().find((item) => {
    if (item.kind === 'curve') return near(curveMidpoint(item), point, hitRadius() * 1.5);
    if (item.kind !== 'line') return false;
    const a = worldPoint(item.points[0], item),
      b = worldPoint(item.points[1], item);
    return near({ x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 }, point, hitRadius() * 1.5);
  });
  if (element) bendSelected(element.id);
}
function continueLine() {
  if (
    locked.value ||
    interacting.value ||
    !selected.value ||
    !['line', 'curve', 'ellipse'].includes(selected.value.kind)
  )
    return;
  const element = selected.value;
  if (element.kind === 'ellipse') {
    const bounds = elementBounds(element);
    branch.value = {
      start: worldPoint({ x: bounds.x + bounds.width / 2, y: bounds.y + bounds.height }, element),
    };
    tool.value = 'asana';
    canvas.value?.focus({ preventScroll: true });
    return;
  }
  const index = pointIndex.value === 0 ? 0 : element.points.length - 1;
  const endpoint = worldPoint(element.points[index], element);
  const adjacent = worldPoint(element.points[index === 0 ? 1 : index - 1], element);
  branch.value = { start: endpoint, segment: [adjacent, endpoint] };
  tool.value = 'asana';
  canvas.value?.focus({ preventScroll: true });
}
function clear() {
  if (locked.value || interacting.value || !drawing.value.elements.length) return;
  drawing.value.elements = [];
  selectedId.value = null;
  branch.value = null;
  tool.value = 'asana';
  commit();
}
function removeSelected() {
  if (locked.value || interacting.value || !selected.value) return;
  drawing.value.elements = drawing.value.elements.filter((element) => element.id !== selectedId.value);
  selectedId.value = null;
  commit();
}
function duplicateSelected() {
  if (locked.value || interacting.value || !selected.value) return;
  if (
    drawing.value.elements.length >= drawingLimits.elements ||
    drawingPointCount(drawing.value) + selected.value.points.length > drawingLimits.points
  ) {
    error.value = 'Достигнут предел размера рисунка.';
    return;
  }
  const copy = confineElement(
    moveElement(
      { ...selected.value, id: uid(), points: selected.value.points.map((point) => ({ ...point })) },
      24,
      24,
    ),
  );
  drawing.value.elements.push(copy);
  selectedId.value = copy.id;
  commit();
}
function addFigure() {
  if (locked.value || interacting.value) return;
  if (
    drawing.value.elements.length >= drawingLimits.elements ||
    drawingPointCount(drawing.value) + 15 > drawingLimits.points
  ) {
    error.value = 'Достигнут предел размера рисунка.';
    return;
  }
  const element: DrawingElement = {
    id: uid(),
    kind: 'figure',
    width: brushWidth.value,
    rotation: 0,
    points: [
      [512, 230],
      [512, 305],
      [460, 320],
      [408, 410],
      [358, 490],
      [564, 320],
      [616, 410],
      [666, 490],
      [512, 510],
      [480, 530],
      [455, 650],
      [435, 790],
      [544, 530],
      [569, 650],
      [589, 790],
    ].map(([x, y]) => ({ x, y })),
  };
  drawing.value.elements.push(element);
  selectedId.value = element.id;
  pointIndex.value = 0;
  tool.value = 'select';
  commit();
}
function setElementWidth(event: Event) {
  if (locked.value || !selected.value) return;
  replaceSelected({
    ...selected.value,
    width: Math.max(1, Math.min(100, Number((event.target as HTMLInputElement).value) || 1)),
  });
}
function setRotation(event: Event) {
  if (locked.value || !selected.value) return;
  replaceSelected({
    ...selected.value,
    rotation: Math.max(-360, Math.min(360, Number((event.target as HTMLInputElement).value) || 0)),
  });
}
function turnSelected() {
  if (locked.value || interacting.value || !selected.value) return;
  replaceSelected({ ...selected.value, rotation: ((selected.value.rotation + 195) % 360) - 180 });
  commit();
}
function resizeSelected(event: Event, axis: 'width' | 'height') {
  if (locked.value || interacting.value || !selected.value) return;
  if (!numericResize || numericResize.id !== selected.value.id || numericResize.axis !== axis) {
    if (numericResize) commit();
    numericResize = {
      id: selected.value.id,
      axis,
      original: JSON.parse(JSON.stringify(selected.value)),
      before: snapshot(),
    };
  }
  const text = (event.target as HTMLInputElement).value;
  if (!text || !Number.isFinite(Number(text)) || Number(text) < 1) return;
  // Each keystroke scales the geometry from the start of the field edit.
  // Intermediate values must not progressively flatten the original drawing.
  const original = numericResize.original,
    bounds = elementBounds(original);
  const value = Math.min(1024, Number(text));
  const scaleX = axis === 'width' ? value / bounds.width : 1;
  const scaleY = axis === 'height' ? value / bounds.height : 1;
  const points = original.points.map((point) =>
    clampPoint({
      x: bounds.x + (point.x - bounds.x) * scaleX,
      y: bounds.y + (point.y - bounds.y) * scaleY,
    }),
  );
  replaceSelected(editPoints(original, points));
}
function finishResize(event?: Event) {
  if (numericResize) {
    const state = numericResize;
    if (event && selected.value && state.id === selected.value.id)
      (event.target as HTMLInputElement).value = String(
        Math.round(elementBounds(selected.value)[state.axis]),
      );
    commit(state.before);
  }
}
function selectElement(event: Event) {
  if (locked.value || interacting.value) return;
  selectedId.value = (event.target as HTMLSelectElement).value || null;
  pointIndex.value = 0;
  if (tool.value !== 'rotate') tool.value = 'select';
  branch.value = null;
}
function nudge(dx: number, dy: number, node = false) {
  if (locked.value || interacting.value || !selected.value) return;
  const original = selected.value;
  if (node && editablePoints.value.length) {
    const index = Math.min(pointIndex.value, original.points.length - 1),
      points = original.points.map((point) => ({ ...point }));
    const destination = rotatePoint({ x: dx, y: dy }, { x: 0, y: 0 }, -original.rotation);
    const factor = original.kind === 'curve' && index === 1 ? 2 : 1;
    points[index] = clampPoint({
      x: points[index].x + destination.x * factor,
      y: points[index].y + destination.y * factor,
    });
    replaceSelected(editPoints(original, points));
  } else replaceSelected(moveElement(original, dx, dy));
  commit();
}
function setZoom(next: number, anchor?: Point) {
  if (interacting.value || !viewport.value) return;
  const limited = Math.max(0.5, Math.min(4, next));
  const focus = anchor ?? { x: viewport.value.clientWidth / 2, y: viewport.value.clientHeight / 2 };
  const ratio = limited / zoom.value;
  pan.value = { x: focus.x - (focus.x - pan.value.x) * ratio, y: focus.y - (focus.y - pan.value.y) * ratio };
  zoom.value = limited;
}
function resetView() {
  zoom.value = 1;
  pan.value = { x: 0, y: 0 };
}
function wheel(event: WheelEvent) {
  if (locked.value || interacting.value || !viewport.value) return;
  if (event.ctrlKey || event.metaKey) {
    event.preventDefault();
    const rect = viewport.value.getBoundingClientRect();
    setZoom(zoom.value * Math.exp(-event.deltaY * 0.005), {
      x: event.clientX - rect.left,
      y: event.clientY - rect.top,
    });
  } else if (zoom.value > 1) {
    event.preventDefault();
    pan.value = { x: pan.value.x - event.deltaX, y: pan.value.y - event.deltaY };
  }
}
function keydown(event: KeyboardEvent) {
  if (!props.active || locked.value || !root.value?.contains(document.activeElement)) return;
  const target = event.target as HTMLElement;
  if (target.closest('input,textarea,select,[contenteditable="true"]')) return;
  const key = event.key.toLowerCase(),
    physicalKey = event.code.startsWith('Key') ? event.code.slice(3).toLowerCase() : key,
    command = event.ctrlKey || event.metaKey;
  if (key === 'escape') {
    event.preventDefault();
    event.stopPropagation();
    cancelInteraction();
    branch.value = null;
    selectedId.value = null;
    fullscreen.value = false;
    return;
  }
  if (interacting.value) return;
  if (command && physicalKey === 'z') {
    event.preventDefault();
    event.shiftKey ? redo() : undo();
  } else if (command && physicalKey === 'y') {
    event.preventDefault();
    redo();
  } else if (command && physicalKey === 'd') {
    event.preventDefault();
    duplicateSelected();
  } else if (['delete', 'backspace'].includes(key) && selected.value) {
    event.preventDefault();
    removeSelected();
  } else if (key.startsWith('arrow') && selected.value) {
    event.preventDefault();
    const amount = event.shiftKey ? 10 : 1;
    nudge(
      key === 'arrowleft' ? -amount : key === 'arrowright' ? amount : 0,
      key === 'arrowup' ? -amount : key === 'arrowdown' ? amount : 0,
      event.altKey,
    );
  } else if (!command && event.code === 'Space' && !target.closest('button,a,summary')) {
    event.preventDefault();
    spacePan.value = true;
  } else if (!command && !event.altKey) {
    const shortcut: Record<string, Tool> = {
      a: 'asana',
      v: 'select',
      r: 'rotate',
      p: 'pen',
      e: 'eraser',
      l: 'line',
      c: 'curve',
      o: 'ellipse',
      h: 'pan',
    };
    if (physicalKey === 'b') {
      event.preventDefault();
      bendSelected();
    } else if (physicalKey === 'n') {
      event.preventDefault();
      continueLine();
    } else if (physicalKey === 's' && headCenter.value) {
      event.preventDefault();
      symmetry.value = !symmetry.value;
    } else if (shortcut[physicalKey]) {
      event.preventDefault();
      setTool(shortcut[physicalKey]);
    } else if (key === '+' || key === '=') {
      event.preventDefault();
      setZoom(zoom.value * 1.25);
    } else if (key === '-') {
      event.preventDefault();
      setZoom(zoom.value / 1.25);
    } else if (key === '0') {
      event.preventDefault();
      resetView();
    }
  }
}
function keyup(event: KeyboardEvent) {
  if (event.code === 'Space') spacePan.value = false;
}
function blur() {
  spacePan.value = false;
  finish();
}
function flushDraft() {
  if (interaction) finish();
  saveDraft();
}
async function apply(): Promise<boolean> {
  if (locked.value || interacting.value || !canvas.value) return false;
  commit();
  if (!drawing.value.elements.length) {
    error.value = 'Лист пуст. Нарисуйте асану или отмените очистку.';
    return false;
  }
  busy.value = true;
  error.value = '';
  try {
    const source = parseDrawing(drawing.value);
    const output = document.createElement('canvas');
    output.width = output.height = 1024;
    renderTo(output);
    const file = await canvasFile(output, props.context.name);
    appliedFingerprint = JSON.stringify(source);
    pendingCardSave = true;
    saveDraft();
    emit('image', file, source);
    updateDirty();
    return true;
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось сохранить рисунок.';
    return false;
  } finally {
    busy.value = false;
  }
}
function exportSource() {
  if (locked.value || interacting.value) return;
  downloadJson(drawing.value, imageFileName(props.context.name).replace(/\.png$/, '-исходник.json'));
}
async function exportPng() {
  if (locked.value || interacting.value || !drawing.value.elements.length) return;
  busy.value = true;
  try {
    const output = document.createElement('canvas');
    output.width = output.height = 1024;
    renderTo(output);
    const file = await canvasFile(output, props.context.name),
      url = URL.createObjectURL(file);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = file.name;
    anchor.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось скачать PNG.';
  } finally {
    busy.value = false;
  }
}
async function importSource(event: Event) {
  const input = event.target as HTMLInputElement,
    file = input.files?.[0];
  input.value = '';
  if (!file || locked.value || interacting.value) return;
  error.value = '';
  busy.value = true;
  try {
    if (file.size > 15 * 1024 * 1024) throw new Error('Файл больше 15 МБ.');
    const imported = parseDrawing(JSON.parse(await file.text()));
    if (drawing.value.elements.length && !window.confirm('Заменить текущий рисунок? Замену можно отменить.'))
      return;
    const before = snapshot();
    drawing.value = imported;
    selectedId.value = null;
    restoreCandidate.value = null;
    commit(before);
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось открыть исходник.';
  } finally {
    busy.value = false;
  }
}
function close() {
  if (locked.value || interacting.value) return;
  saveDraft();
  fullscreen.value = false;
  emit('close');
}
defineExpose({ hasUnappliedChanges, discardDraft, apply });
onMounted(() => {
  render();
  window.addEventListener('keydown', keydown, true);
  window.addEventListener('keyup', keyup);
  window.addEventListener('blur', blur);
  window.addEventListener('beforeunload', flushDraft);
  window.addEventListener('pagehide', flushDraft);
});
onBeforeUnmount(() => {
  initialVersion++;
  canvasObserver?.disconnect();
  if (interaction) finish();
  if (draftTimer) saveDraft();
  if (frame) cancelAnimationFrame(frame);
  window.removeEventListener('keydown', keydown, true);
  window.removeEventListener('keyup', keyup);
  window.removeEventListener('blur', blur);
  window.removeEventListener('beforeunload', flushDraft);
  window.removeEventListener('pagehide', flushDraft);
});
</script>

<template>
  <div class="drawing-host">
    <DialogRoot v-model:open="fullscreen">
      <Teleport to="body" :disabled="!fullscreen">
        <DialogOverlay v-if="fullscreen" class="drawing-overlay" />
        <component
          :is="fullscreen ? DialogContent : 'div'"
          :key="fullscreen ? 'fullscreen-dialog' : 'embedded-editor'"
          v-bind="fullscreen ? { 'aria-describedby': undefined } : {}"
          @open-auto-focus.prevent
          @close-auto-focus.prevent
          @pointer-down-outside.prevent
        >
          <section
            ref="root"
            class="asana-drawing"
            :class="{ fullscreen }"
            aria-label="Редактор рисунка асаны"
          >
            <header class="drawing-header">
              <component :is="fullscreen ? DialogTitle : 'strong'" class="drawing-title"
                ><Pencil :size="17" /> Нарисовать асану</component
              >
              <div class="toolbar">
                <button
                  type="button"
                  class="icon-btn"
                  :disabled="locked || interacting"
                  :title="fullscreen ? 'Свернуть редактор' : 'Развернуть редактор'"
                  :aria-label="fullscreen ? 'Свернуть редактор' : 'Развернуть редактор'"
                  @click="
                    fullscreen = !fullscreen;
                    resetView();
                  "
                >
                  <Maximize2 :size="18" />
                </button>
                <button type="button" class="btn btn-ghost" :disabled="locked || interacting" @click="close">
                  Закрыть
                </button>
              </div>
            </header>
            <div v-if="restoreCandidate" class="draft-notice" role="status">
              <p>В этом браузере есть несохранённый рисунок.</p>
              <div class="toolbar">
                <button type="button" class="btn btn-secondary" :disabled="locked" @click="restoreDraft">
                  Восстановить черновик</button
                ><button type="button" class="btn btn-ghost" :disabled="locked" @click="ignoreDraft">
                  Удалить черновик
                </button>
              </div>
            </div>
            <div v-if="error" class="error-banner" role="alert">{{ error }}</div>
            <div v-if="draftError" class="draft-warning" role="alert">{{ draftError }}</div>
            <fieldset class="drawing-controls" :disabled="locked || interacting">
              <div class="toolbar tools" aria-label="Инструменты рисунка">
                <button
                  v-for="item in tools"
                  :key="item.id"
                  type="button"
                  class="tool-button"
                  :class="{ selected: tool === item.id }"
                  :title="item.hint"
                  :aria-pressed="tool === item.id"
                  @click="setTool(item.id)"
                >
                  <component :is="item.icon" :size="18" /><span>{{ item.label }}</span>
                </button>
                <button
                  type="button"
                  class="tool-button"
                  :class="{ selected: symmetry }"
                  :aria-pressed="symmetry"
                  :disabled="!headCenter"
                  title="Рисовать зеркальные линии относительно центра головы (S)"
                  @click="symmetry = !symmetry"
                >
                  <FlipHorizontal2 :size="18" /><span>Симметрия</span>
                </button>
                <button
                  type="button"
                  class="tool-button"
                  title="Добавить человечка с подвижными суставами"
                  @click="addFigure"
                >
                  <PersonStanding :size="18" /><span>Человечек</span>
                </button>
              </div>
              <div class="settings-row">
                <label
                  >Толщина <output>{{ brushWidth }}</output
                  ><input v-model.number="brushWidth" type="range" min="1" max="25" @change="commit()"
                /></label>
                <label
                  >Сглаживание <output>{{ drawing.smoothing }}%</output
                  ><input
                    v-model.number="drawing.smoothing"
                    type="range"
                    min="0"
                    max="100"
                    @input="scheduleRender"
                    @change="commit()"
                /></label>
                <div class="toolbar history-tools">
                  <button
                    type="button"
                    class="icon-btn"
                    title="Отменить (Ctrl / ⌘ Z)"
                    aria-label="Отменить изменение"
                    :disabled="!undoStack.length"
                    @click="undo"
                  >
                    <Undo2 :size="19" />
                  </button>
                  <button
                    type="button"
                    class="icon-btn"
                    title="Повторить (Ctrl / ⌘ Shift Z)"
                    aria-label="Повторить изменение"
                    :disabled="!redoStack.length"
                    @click="redo"
                  >
                    <Redo2 :size="19" />
                  </button>
                  <button
                    type="button"
                    class="icon-btn"
                    title="Очистить лист — действие можно отменить"
                    aria-label="Очистить рисунок"
                    :disabled="!drawing.elements.length"
                    @click="clear"
                  >
                    <Trash2 :size="18" />
                  </button>
                </div>
              </div>
              <label class="snap-setting"
                ><input v-model="snapping" type="checkbox" /> Привязка к осям и углам 90°
                <span class="muted">Alt — временно отключить</span>
              </label>
            </fieldset>
            <div class="view-tools toolbar">
              <button
                type="button"
                class="icon-btn"
                title="Уменьшить"
                aria-label="Уменьшить масштаб"
                :disabled="locked || interacting || zoom <= 0.5"
                @click="setZoom(zoom / 1.25)"
              >
                <Minus :size="18" />
              </button>
              <output aria-label="Масштаб">{{ Math.round(zoom * 100) }}%</output>
              <button
                type="button"
                class="icon-btn"
                title="Увеличить"
                aria-label="Увеличить масштаб"
                :disabled="locked || interacting || zoom >= 4"
                @click="setZoom(zoom * 1.25)"
              >
                <Plus :size="18" />
              </button>
              <button
                type="button"
                class="btn btn-ghost"
                :disabled="locked || interacting"
                @click="resetView"
              >
                Весь лист
              </button>
              <span class="muted draft-status" role="status">{{
                dirty
                  ? 'Есть неприменённые изменения'
                  : drawing.elements.length
                    ? 'Рисунок применён'
                    : 'Пустой лист'
              }}</span>
            </div>
            <label v-if="drawing.elements.length && showSelection" class="element-choice">
              Выбрать элемент
              <select
                class="select"
                :value="selectedId || ''"
                :disabled="locked || interacting"
                @change="selectElement"
              >
                <option value="">Ничего не выбрано</option>
                <option v-for="(element, index) in drawing.elements" :key="element.id" :value="element.id">
                  {{ index + 1 }}. {{ kindNames[element.kind] }}
                </option>
              </select>
            </label>
            <div
              ref="viewport"
              class="drawing-viewport"
              :class="{
                panning: tool === 'pan' || spacePan,
                selecting: tool === 'select',
                rotating: tool === 'rotate',
                drawing: !['select', 'rotate', 'pan'].includes(tool),
              }"
              @pointerdown.prevent="begin"
              @pointermove.prevent="move"
              @pointerup="finish"
              @pointercancel="cancelInteraction"
              @lostpointercapture="finish"
              @contextmenu.prevent
              @dblclick.prevent="doubleClick"
              @wheel="wheel"
            >
              <div class="drawing-surface" :style="surfaceStyle">
                <canvas
                  ref="canvas"
                  width="1024"
                  height="1024"
                  tabindex="0"
                  aria-label="Лист для рисунка. Рисуйте мышью или касанием. Выбранный элемент перемещается клавишами со стрелками."
                />
                <svg
                  v-if="(headCenter && (symmetry || tool === 'asana')) || guides.length || branch"
                  class="selection-overlay"
                  viewBox="0 0 1024 1024"
                  aria-hidden="true"
                >
                  <line
                    v-if="headCenter && (symmetry || tool === 'asana')"
                    :x1="headCenter.x"
                    :y1="headCenter.y"
                    :x2="headCenter.x"
                    y2="1024"
                    class="symmetry-axis"
                  />
                  <line
                    v-for="(guide, index) in guides"
                    :key="index"
                    :x1="guide.from.x"
                    :y1="guide.from.y"
                    :x2="guide.to.x"
                    :y2="guide.to.y"
                    class="snap-guide"
                  />
                  <circle
                    v-if="branch"
                    :cx="branch.start.x"
                    :cy="branch.start.y"
                    :r="hitRadius() * 0.75"
                    class="point-handle active"
                  />
                </svg>
                <svg
                  v-if="selected && showSelection"
                  class="selection-overlay"
                  viewBox="0 0 1024 1024"
                  aria-hidden="true"
                >
                  <polygon :points="polygon" class="selection-box" />
                  <line
                    v-if="rotateHandle"
                    :x1="(corners[0].x + corners[1].x) / 2"
                    :y1="(corners[0].y + corners[1].y) / 2"
                    :x2="rotateHandle.x"
                    :y2="rotateHandle.y"
                    class="selection-box"
                  />
                  <circle
                    v-if="rotateHandle"
                    :cx="rotateHandle.x"
                    :cy="rotateHandle.y"
                    :r="hitRadius() * 0.72"
                    class="rotate-handle"
                  />
                  <rect
                    v-for="(corner, index) in corners"
                    :key="`corner-${index}`"
                    :x="corner.x - hitRadius() * 0.55"
                    :y="corner.y - hitRadius() * 0.55"
                    :width="hitRadius() * 1.1"
                    :height="hitRadius() * 1.1"
                    class="resize-handle"
                  />
                  <polyline
                    v-if="selected.kind === 'curve'"
                    :points="editablePoints.map((point) => `${point.x},${point.y}`).join(' ')"
                    class="curve-guide"
                  />
                  <circle
                    v-for="point in editablePoints"
                    :key="`point-${point.index}`"
                    :cx="point.x"
                    :cy="point.y"
                    :r="hitRadius() * 0.68"
                    class="point-handle"
                    :class="{ active: point.index === pointIndex }"
                  />
                </svg>
              </div>
              <output v-if="snapLabel" class="snap-status" role="status">{{ snapLabel }}</output>
            </div>
            <p class="muted help" v-if="branch">
              Нажмите на место нового конца или потяните линию. Escape отменяет продолжение.
            </p>
            <p class="muted help" v-else-if="tool === 'asana' && !drawing.elements.length">
              Начните с головы: нажмите для круга или потяните, чтобы задать его размер. Затем рисуйте линии.
            </p>
            <p class="muted help" v-else-if="tool === 'asana'">
              Потяните для линии или нажмите её начало и конец. Левой кнопкой двигайте конец, правой —
              продолжайте линию от него. Двойной щелчок по середине включает изгиб. Край головы меняет её
              размер.
            </p>
            <p class="muted help" v-else-if="tool === 'rotate'">
              Выделите элемент и потяните вокруг него для поворота. Угол также можно задать в поле «Поворот».
              Shift — шаг 15°.
            </p>
            <p class="muted help" v-else-if="tool === 'curve'">
              Потяните от начала к концу. Затем двигайте среднюю точку, чтобы изменить изгиб.
            </p>
            <p class="muted help" v-else-if="tool === 'ellipse'">
              Потяните по диагонали для круга или эллипса. Shift сохраняет круглую форму.
            </p>
            <p class="muted help" v-else-if="tool === 'pan'">
              Двигайте лист мышью или пальцем. Масштаб меняется кнопками «−» и «+».
            </p>
            <p class="muted help" v-else>
              Рисуйте мышью, пальцем или стилусом. Выберите «Выделение», чтобы двигать части рисунка и суставы
              человечка.
            </p>
            <fieldset
              v-if="selected && showSelection"
              class="selection-controls"
              :disabled="locked || interacting"
            >
              <legend>{{ kindNames[selected.kind] }}</legend>
              <div v-if="['line', 'curve', 'ellipse'].includes(selected.kind)" class="toolbar">
                <button
                  v-if="['line', 'curve'].includes(selected.kind)"
                  type="button"
                  class="btn btn-secondary"
                  @click="bendSelected()"
                  title="Изменить изгиб (B)"
                >
                  <Spline :size="16" />Изогнуть линию
                </button>
                <button
                  type="button"
                  class="btn btn-secondary"
                  @click="continueLine"
                  title="Новая линия от выбранного конца (N)"
                >
                  <Plus :size="16" />Продолжить линию
                </button>
              </div>
              <div class="selection-settings">
                <label
                  >Толщина элемента<input
                    type="number"
                    class="input"
                    min="1"
                    max="100"
                    :value="selected.width"
                    @input="setElementWidth"
                    @change="commit()"
                /></label>
                <label
                  >Поворот, °<input
                    type="number"
                    class="input"
                    min="-360"
                    max="360"
                    :value="Math.round(selected.rotation)"
                    @input="setRotation"
                    @change="commit()"
                /></label>
                <div class="toolbar">
                  <button
                    type="button"
                    class="icon-btn"
                    title="Повернуть на 15°"
                    aria-label="Повернуть элемент на 15 градусов"
                    @click="turnSelected"
                  >
                    <RotateCw :size="18" /></button
                  ><button
                    type="button"
                    class="icon-btn"
                    title="Копировать (Ctrl / ⌘ D)"
                    aria-label="Копировать элемент"
                    @click="duplicateSelected"
                  >
                    <Copy :size="18" /></button
                  ><button
                    type="button"
                    class="icon-btn"
                    title="Удалить (Delete)"
                    aria-label="Удалить элемент"
                    @click="removeSelected"
                  >
                    <Trash2 :size="18" />
                  </button>
                </div>
              </div>
              <div class="selection-settings">
                <label
                  >Ширина, px<input
                    class="input"
                    type="number"
                    min="1"
                    max="1024"
                    :value="Math.round(selectedBounds!.width)"
                    @input="resizeSelected($event, 'width')"
                    @change="finishResize"
                    @blur="finishResize"
                /></label>
                <label
                  >Высота, px<input
                    class="input"
                    type="number"
                    min="1"
                    max="1024"
                    :value="Math.round(selectedBounds!.height)"
                    @input="resizeSelected($event, 'height')"
                    @change="finishResize"
                    @blur="finishResize"
                /></label>
                <div class="toolbar" aria-label="Переместить элемент">
                  <button
                    type="button"
                    class="nudge-button"
                    aria-label="Сдвинуть элемент влево"
                    @click="nudge(-5, 0)"
                  >
                    ←
                  </button>
                  <button
                    type="button"
                    class="nudge-button"
                    aria-label="Сдвинуть элемент вверх"
                    @click="nudge(0, -5)"
                  >
                    ↑
                  </button>
                  <button
                    type="button"
                    class="nudge-button"
                    aria-label="Сдвинуть элемент вниз"
                    @click="nudge(0, 5)"
                  >
                    ↓
                  </button>
                  <button
                    type="button"
                    class="nudge-button"
                    aria-label="Сдвинуть элемент вправо"
                    @click="nudge(5, 0)"
                  >
                    →
                  </button>
                </div>
              </div>
              <div v-if="editablePoints.length" class="joint-controls">
                <label
                  >Точка<select v-model.number="pointIndex" class="select">
                    <option v-for="(name, index) in pointNames" :key="index" :value="index">
                      {{ name }}
                    </option>
                  </select></label
                >
                <div class="toolbar">
                  <button
                    type="button"
                    class="nudge-button"
                    aria-label="Сдвинуть точку влево"
                    @click="nudge(-5, 0, true)"
                  >
                    ←</button
                  ><button
                    type="button"
                    class="nudge-button"
                    aria-label="Сдвинуть точку вверх"
                    @click="nudge(0, -5, true)"
                  >
                    ↑</button
                  ><button
                    type="button"
                    class="nudge-button"
                    aria-label="Сдвинуть точку вниз"
                    @click="nudge(0, 5, true)"
                  >
                    ↓</button
                  ><button
                    type="button"
                    class="nudge-button"
                    aria-label="Сдвинуть точку вправо"
                    @click="nudge(5, 0, true)"
                  >
                    →
                  </button>
                </div>
              </div>
              <p class="muted help">
                Квадраты меняют размер, верхний кружок — поворот. Стрелки клавиатуры двигают элемент; Alt +
                стрелки — выбранную точку, Shift — шаг 10.
              </p>
            </fieldset>
            <footer class="drawing-footer">
              <div class="toolbar file-tools">
                <button
                  type="button"
                  class="btn btn-secondary"
                  :disabled="locked || interacting"
                  @click="exportSource"
                >
                  <Download :size="16" />Исходник
                </button>
                <button
                  type="button"
                  class="btn btn-secondary"
                  :disabled="locked || interacting"
                  @click="importInput?.click()"
                >
                  <Upload :size="16" />Открыть
                </button>
                <button
                  type="button"
                  class="btn btn-secondary"
                  :disabled="locked || interacting || !drawing.elements.length"
                  @click="exportPng"
                >
                  <Download :size="16" />PNG
                </button>
              </div>
              <input
                ref="importInput"
                type="file"
                accept="application/json,.json"
                hidden
                @change="importSource"
              />
              <button
                type="button"
                class="btn btn-primary"
                :disabled="locked || interacting || !drawing.elements.length"
                @click="apply"
              >
                <Check :size="17" />{{ busy ? 'Сохраняем…' : 'Использовать рисунок' }}
              </button>
            </footer>
            <p class="muted help">
              Кнопка «Использовать рисунок» обновит изображение карточки. Для сохранения на сайте нажмите
              «Сохранить» или «Создать карточку» в форме. Исходник позволяет продолжить редактирование.
            </p>
          </section>
        </component>
      </Teleport>
    </DialogRoot>
  </div>
</template>

<style scoped>
.drawing-host {
  min-width: 0;
  width: 100%;
}
.asana-drawing {
  border: 1px solid #dce7d7;
  border-radius: 14px;
  background: #fff;
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-width: 0;
}
.drawing-header,
.drawing-title,
.toolbar {
  display: flex;
  align-items: center;
  gap: 7px;
  flex-wrap: wrap;
}
.drawing-header {
  justify-content: space-between;
}
.drawing-title {
  font-size: 0.92rem;
  margin: 0;
}
.drawing-controls {
  border: 0;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 12px;
  min-width: 0;
}
.tool-button {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  min-width: 54px;
  min-height: 50px;
  border: 1px solid #dce5d7;
  border-radius: 9px;
  background: #fff;
  padding: 7px 9px;
  color: #43583b;
  font-size: 0.7rem;
  cursor: pointer;
}
.tool-button.selected {
  border-color: #397a28;
  color: #397a28;
  background: #eef6e9;
}
.tool-button:focus-visible,
.nudge-button:focus-visible {
  outline: 3px solid #397a2870;
  outline-offset: 2px;
}
.tool-button:disabled {
  opacity: 0.5;
  cursor: default;
}
.settings-row {
  display: grid;
  grid-template-columns: 1fr 1fr auto;
  gap: 12px;
  align-items: center;
}
.settings-row label {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 5px;
  font-size: 0.78rem;
}
.settings-row input {
  width: 100%;
  grid-column: 1 / -1;
  accent-color: #397a28;
}
.snap-setting {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 7px;
  font-size: 0.77rem;
}
.snap-setting input {
  accent-color: #397a28;
}
.snap-setting .muted {
  font-size: 0.72rem;
}
.rotating {
  cursor: grab;
}
.snap-status {
  position: absolute;
  top: 8px;
  left: 8px;
  padding: 5px 9px;
  border: 1px solid #bed8b3;
  border-radius: 7px;
  color: #24551a;
  background: #fff;
  font-size: 0.75rem;
  pointer-events: none;
}
.symmetry-axis,
.snap-guide {
  fill: none;
  stroke: #8aaa7b;
  stroke-width: 1;
  stroke-dasharray: 6 5;
  vector-effect: non-scaling-stroke;
}
.snap-guide {
  stroke: #397a28;
  stroke-width: 2;
}
.help {
  font-size: 0.77rem;
  line-height: 1.5;
  margin: 0;
}
.view-tools {
  gap: 5px;
}
.view-tools output {
  font-size: 0.78rem;
  min-width: 42px;
  text-align: center;
}
.draft-status {
  font-size: 0.7rem;
  margin-left: auto;
}
.drawing-viewport {
  position: relative;
  overflow: hidden;
  width: 100%;
  aspect-ratio: 1;
  flex: 0 0 auto;
  align-self: flex-start;
  border: 1px solid #d7e1d1;
  border-radius: 10px;
  background-color: #f2f4f0;
  background-image:
    linear-gradient(45deg, #e8ece5 25%, transparent 25%, transparent 75%, #e8ece5 75%),
    linear-gradient(45deg, #e8ece5 25%, transparent 25%, transparent 75%, #e8ece5 75%);
  background-position:
    0 0,
    8px 8px;
  background-size: 16px 16px;
  touch-action: none;
}
.drawing-surface {
  width: 100%;
  position: relative;
  transform-origin: 0 0;
}
canvas {
  display: block;
  width: 100%;
  aspect-ratio: 1;
  background: #fff;
  outline: none;
}
canvas:focus-visible {
  box-shadow: inset 0 0 0 3px #397a2870;
}
.panning {
  cursor: grab;
}
.panning:active {
  cursor: grabbing;
}
.selecting {
  cursor: default;
}
.drawing {
  cursor: crosshair;
}
.selection-overlay {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
  overflow: visible;
}
.selection-box {
  fill: none;
  stroke: #397a28;
  stroke-width: 1.5;
  vector-effect: non-scaling-stroke;
  stroke-dasharray: 5 3;
}
.resize-handle,
.rotate-handle {
  fill: #fff;
  stroke: #397a28;
  stroke-width: 1.5;
  vector-effect: non-scaling-stroke;
}
.point-handle {
  fill: #fff;
  stroke: #397a28;
  stroke-width: 1.5;
  vector-effect: non-scaling-stroke;
}
.point-handle.active {
  fill: #d2edc5;
  stroke: #24551a;
  stroke-width: 2;
}
.curve-guide {
  fill: none;
  stroke: #809378;
  stroke-width: 1;
  stroke-dasharray: 3 4;
  vector-effect: non-scaling-stroke;
}
.selection-controls {
  border: 1px solid #e3e9df;
  border-radius: 10px;
  padding: 10px;
  margin: 0;
  display: grid;
  gap: 10px;
  min-width: 0;
}
.selection-controls legend {
  font-size: 0.8rem;
  padding: 0 5px;
  color: #397a28;
}
.element-choice {
  display: grid;
  gap: 5px;
  font-size: 0.75rem;
}
.element-choice .select {
  padding: 6px 10px;
  font-size: 0.8rem;
}
.selection-settings {
  display: grid;
  grid-template-columns: 1fr 1fr auto;
  gap: 10px;
  align-items: end;
}
.selection-settings label,
.joint-controls label {
  display: grid;
  gap: 5px;
  font-size: 0.75rem;
  min-width: 0;
}
.selection-settings .input {
  padding: 6px 8px;
  width: 100%;
  min-width: 0;
}
.joint-controls {
  display: flex;
  flex-wrap: wrap;
  align-items: end;
  gap: 10px;
}
.joint-controls .select {
  padding: 6px 10px;
  font-size: 0.8rem;
}
.nudge-button {
  width: 34px;
  height: 34px;
  border: 1px solid #dce5d7;
  border-radius: 8px;
  background: #fff;
  color: #397a28;
  cursor: pointer;
}
.drawing-footer {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 10px;
  border-top: 1px solid #e3e9df;
  padding-top: 12px;
}
.file-tools .btn {
  font-size: 0.76rem;
  padding: 8px 10px;
}
.draft-notice {
  background: #eef6e9;
  border: 1px solid #cfe0c7;
  border-radius: 10px;
  padding: 10px;
}
.draft-notice p {
  margin: 0 0 8px;
  font-size: 0.8rem;
}
.draft-warning {
  font-size: 0.8rem;
  line-height: 1.5;
  background: #fff5d8;
  border: 1px solid #efdc9d;
  border-radius: 8px;
  padding: 10px;
}
.fullscreen {
  position: fixed;
  inset: 12px;
  z-index: 151;
  overflow: auto;
  border: 1px solid #cddbc6;
  box-shadow: 0 12px 70px #20351d40;
  display: flex;
  align-items: stretch;
}
.drawing-overlay {
  position: fixed;
  inset: 0;
  z-index: 150;
  background: #20351d66;
}
.fullscreen .drawing-viewport {
  width: min(100%, 68vh);
  align-self: center;
}
@media (max-width: 480px) {
  .asana-drawing {
    padding: 10px;
    gap: 10px;
  }
  .tools {
    gap: 5px;
  }
  .tool-button {
    min-width: 48px;
    padding: 6px;
    min-height: 48px;
  }
  .settings-row {
    grid-template-columns: 1fr 1fr;
  }
  .history-tools {
    grid-column: 1 / -1;
  }
  .draft-status {
    flex-basis: 100%;
    margin-left: 0;
  }
  .selection-settings {
    grid-template-columns: 1fr 1fr;
  }
  .selection-settings .toolbar {
    grid-column: 1 / -1;
  }
  .fullscreen {
    inset: 5px;
  }
}
</style>
