<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Pencil, Eraser, Undo2, Redo2, Trash2, Download, Upload, Check } from '@lucide/vue';
import {
  canvasFile,
  codexImageTask,
  downloadJson,
  imageFileName,
  type ImageTaskContext,
} from '../imageTools';

interface Point {
  x: number;
  y: number;
}
interface Stroke {
  tool: 'pen' | 'eraser';
  width: number;
  points: Point[];
}
const props = defineProps<{ context: ImageTaskContext; disabled?: boolean }>();
const emit = defineEmits<{ image: [file: File]; close: [] }>();
const canvas = ref<HTMLCanvasElement | null>(null);
const importInput = ref<HTMLInputElement | null>(null);
const strokes = ref<Stroke[]>([]),
  redoStack = ref<Stroke[]>([]);
const tool = ref<'pen' | 'eraser'>('pen');
const width = ref(5),
  smoothing = ref(70),
  instructions = ref('');
const error = ref(''),
  busy = ref(false);
const active = ref(false);
let current: Stroke | null = null;
let pointer = -1;
let frame = 0;
const pointCount = computed(() => strokes.value.reduce((total, stroke) => total + stroke.points.length, 0));

function distance(point: Point, a: Point, b: Point): number {
  const dx = b.x - a.x,
    dy = b.y - a.y;
  const progress =
    dx || dy
      ? Math.max(0, Math.min(1, ((point.x - a.x) * dx + (point.y - a.y) * dy) / (dx * dx + dy * dy)))
      : 0;
  return Math.hypot(point.x - a.x - progress * dx, point.y - a.y - progress * dy);
}
function simplify(points: Point[], tolerance: number): Point[] {
  if (points.length < 3 || !tolerance) return points;
  const keep = new Set<number>([0, points.length - 1]);
  const queue: [number, number][] = [[0, points.length - 1]];
  while (queue.length) {
    const [first, last] = queue.pop()!;
    let maximum = tolerance,
      index = -1;
    for (let i = first + 1; i < last; i++) {
      const value = distance(points[i], points[first], points[last]);
      if (value > maximum) {
        maximum = value;
        index = i;
      }
    }
    if (index >= 0) {
      keep.add(index);
      queue.push([first, index], [index, last]);
    }
  }
  return [...keep].sort((a, b) => a - b).map((index) => points[index]);
}
function drawStroke(ctx: CanvasRenderingContext2D, stroke: Stroke) {
  const points = simplify(stroke.points, smoothing.value / 25);
  if (!points.length) return;
  ctx.strokeStyle = stroke.tool === 'eraser' ? '#ffffff' : '#111111';
  ctx.fillStyle = ctx.strokeStyle;
  ctx.lineWidth = stroke.width;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
  if (points.length === 1) {
    ctx.beginPath();
    ctx.arc(points[0].x, points[0].y, stroke.width / 2, 0, Math.PI * 2);
    ctx.fill();
    return;
  }
  ctx.beginPath();
  ctx.moveTo(points[0].x, points[0].y);
  if (smoothing.value) {
    for (let i = 1; i < points.length - 1; i++) {
      const next = points[i + 1];
      ctx.quadraticCurveTo(points[i].x, points[i].y, (points[i].x + next.x) / 2, (points[i].y + next.y) / 2);
    }
    const last = points[points.length - 1];
    ctx.lineTo(last.x, last.y);
  } else for (const point of points.slice(1)) ctx.lineTo(point.x, point.y);
  ctx.stroke();
}
function render() {
  const ctx = canvas.value?.getContext('2d');
  if (!ctx) return;
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, 1024, 1024);
  for (const stroke of strokes.value) drawStroke(ctx, stroke);
  if (current) drawStroke(ctx, current);
}
function scheduleRender() {
  if (frame) return;
  frame = window.requestAnimationFrame(() => {
    frame = 0;
    render();
  });
}
function position(event: PointerEvent): Point {
  const rect = canvas.value!.getBoundingClientRect();
  return {
    x: Math.max(0, Math.min(1024, ((event.clientX - rect.left) / rect.width) * 1024)),
    y: Math.max(0, Math.min(1024, ((event.clientY - rect.top) / rect.height) * 1024)),
  };
}
function begin(event: PointerEvent) {
  if (props.disabled || busy.value || active.value || !event.isPrimary || event.button !== 0) return;
  if (strokes.value.length >= 1000 || pointCount.value >= 50000) {
    error.value = 'Рисунок слишком подробный. Сохраните его и продолжите на новом листе.';
    return;
  }
  error.value = '';
  pointer = event.pointerId;
  current = {
    tool: tool.value,
    width: tool.value === 'eraser' ? width.value * 4 : width.value,
    points: [position(event)],
  };
  canvas.value?.setPointerCapture(pointer);
  active.value = true;
  scheduleRender();
}
function move(event: PointerEvent) {
  if (
    !current ||
    event.pointerId !== pointer ||
    current.points.length >= 3000 ||
    pointCount.value + current.points.length >= 50000
  )
    return;
  const point = position(event);
  const last = current.points[current.points.length - 1];
  if (Math.hypot(point.x - last.x, point.y - last.y) < 1) return;
  current.points.push(point);
  scheduleRender();
}
function finish(event?: PointerEvent) {
  if (!current || (event && event.pointerId !== pointer)) return;
  strokes.value.push(current);
  redoStack.value = [];
  current = null;
  active.value = false;
  pointer = -1;
  scheduleRender();
}
function undo() {
  if (active.value) return;
  const stroke = strokes.value.pop();
  if (stroke) redoStack.value.push(stroke);
  render();
}
function redo() {
  if (active.value) return;
  const stroke = redoStack.value.pop();
  if (stroke) strokes.value.push(stroke);
  render();
}
function clear() {
  if (
    active.value ||
    !strokes.value.length ||
    !window.confirm('Очистить рисунок? Сначала можно сохранить его в JSON.')
  )
    return;
  strokes.value = [];
  redoStack.value = [];
  render();
}
async function apply() {
  if (!canvas.value || active.value || busy.value || props.disabled || !strokes.value.length) return;
  busy.value = true;
  error.value = '';
  try {
    render();
    emit('image', await canvasFile(canvas.value, props.context.name));
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось сохранить рисунок.';
  } finally {
    busy.value = false;
  }
}
function exportTask() {
  if (!canvas.value || active.value || !strokes.value.length) return;
  render();
  const task = {
    ...codexImageTask(props.context, instructions.value, canvas.value.toDataURL('image/png')),
    drawing: { width: 1024, height: 1024, smoothing: smoothing.value, strokes: strokes.value },
    processing_note:
      'В приложении используется геометрическое сглаживание линий без нейросети. Для художественной доработки используйте source_image и instructions в локальной задаче Codex.',
  };
  downloadJson(task, imageFileName(props.context.name).replace(/\.png$/, '-рисунок.json'));
}
async function importDrawing(event: Event) {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  input.value = '';
  if (!file || busy.value || props.disabled) return;
  error.value = '';
  busy.value = true;
  try {
    if (file.size > 15 * 1024 * 1024) throw new Error('JSON больше 15 МБ.');
    const data = JSON.parse(await file.text());
    if (
      data.format !== 'ribka-image-task' ||
      data.version !== 1 ||
      !data.drawing ||
      data.drawing.width !== 1024 ||
      data.drawing.height !== 1024 ||
      !Array.isArray(data.drawing.strokes) ||
      data.drawing.strokes.length > 1000
    )
      throw new Error('Выберите JSON рисунка, сохранённый в RibkaYoga.');
    let total = 0;
    const imported: Stroke[] = data.drawing.strokes.map((stroke: Stroke) => {
      if (
        !stroke ||
        !['pen', 'eraser'].includes(stroke.tool) ||
        !Number.isFinite(stroke.width) ||
        stroke.width < 1 ||
        stroke.width > 100 ||
        !Array.isArray(stroke.points) ||
        stroke.points.length < 1 ||
        stroke.points.length > 3000
      )
        throw new Error('Некорректная линия в рисунке.');
      total += stroke.points.length;
      if (total > 50000) throw new Error('Рисунок содержит слишком много точек.');
      return {
        tool: stroke.tool,
        width: stroke.width,
        points: stroke.points.map((point) => {
          if (
            !point ||
            !Number.isFinite(point.x) ||
            !Number.isFinite(point.y) ||
            point.x < 0 ||
            point.x > 1024 ||
            point.y < 0 ||
            point.y > 1024
          )
            throw new Error('Некорректная точка в рисунке.');
          return { x: point.x, y: point.y };
        }),
      };
    });
    if (strokes.value.length && !window.confirm('Заменить текущий рисунок данными из JSON?')) return;
    strokes.value = imported;
    redoStack.value = [];
    smoothing.value = Number.isFinite(data.drawing.smoothing)
      ? Math.max(0, Math.min(100, data.drawing.smoothing))
      : 70;
    instructions.value = typeof data.instructions === 'string' ? data.instructions.slice(0, 5000) : '';
    await nextTick();
    render();
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось открыть JSON рисунка.';
  } finally {
    busy.value = false;
  }
}
onMounted(render);
onBeforeUnmount(() => {
  if (frame) window.cancelAnimationFrame(frame);
});
</script>

<template>
  <section class="asana-drawing" aria-label="Рисование асаны">
    <header>
      <strong><Pencil :size="17" /> Нарисовать асану</strong
      ><button
        type="button"
        class="btn btn-ghost"
        :disabled="busy || disabled || active"
        @click="emit('close')"
      >
        Закрыть
      </button>
    </header>
    <p class="muted help">
      Рисуйте пальцем, стилусом или мышью. Линии сглаживаются геометрически; для доработки нейросетью
      сохраните JSON задания.
    </p>
    <div v-if="error" class="error-banner" role="alert">{{ error }}</div>
    <fieldset :disabled="disabled || busy" class="drawing-controls">
      <div class="tools">
        <button
          type="button"
          class="btn btn-secondary"
          :class="{ selected: tool === 'pen' }"
          :aria-pressed="tool === 'pen'"
          :disabled="active"
          @click="tool = 'pen'"
        >
          <Pencil :size="16" />Перо
        </button>
        <button
          type="button"
          class="btn btn-secondary"
          :class="{ selected: tool === 'eraser' }"
          :aria-pressed="tool === 'eraser'"
          :disabled="active"
          @click="tool = 'eraser'"
        >
          <Eraser :size="16" />Ластик
        </button>
        <button
          type="button"
          class="icon-btn"
          title="Отменить линию"
          aria-label="Отменить линию"
          :disabled="active || !strokes.length"
          @click="undo"
        >
          <Undo2 :size="19" />
        </button>
        <button
          type="button"
          class="icon-btn"
          title="Повторить линию"
          aria-label="Повторить линию"
          :disabled="active || !redoStack.length"
          @click="redo"
        >
          <Redo2 :size="19" />
        </button>
        <button
          type="button"
          class="icon-btn"
          title="Очистить рисунок"
          aria-label="Очистить рисунок"
          :disabled="active || !strokes.length"
          @click="clear"
        >
          <Trash2 :size="18" />
        </button>
      </div>
      <div class="ranges">
        <label
          >Толщина <output>{{ width }}</output
          ><input v-model.number="width" type="range" min="2" max="20" :disabled="active"
        /></label>
        <label
          >Сглаживание <output>{{ smoothing }}%</output
          ><input
            v-model.number="smoothing"
            type="range"
            min="0"
            max="100"
            :disabled="active"
            @input="render"
        /></label>
      </div>
    </fieldset>
    <canvas
      ref="canvas"
      width="1024"
      height="1024"
      aria-label="Лист для рисунка, рисуйте мышью или касанием"
      :class="{ erasing: tool === 'eraser' }"
      @pointerdown.prevent="begin"
      @pointermove.prevent="move"
      @pointerup="finish"
      @pointercancel="finish"
      @lostpointercapture="finish"
    />
    <p class="muted help">
      Рисование требует мыши или касания. Готовый рисунок также можно загрузить обычным файлом в поле
      «Изображение».
    </p>
    <fieldset :disabled="disabled || busy || active" class="drawing-controls">
      <details class="task-export">
        <summary>Сохранить рисунок и задание для Codex</summary>
        <p class="muted help">
          JSON содержит линии, PNG и данные карточки. Его можно открыть здесь для продолжения рисунка либо
          передать локальному Codex для доработки. Полученный PNG загрузите в поле «Изображение».
        </p>
        <textarea
          v-model="instructions"
          class="textarea"
          rows="2"
          maxlength="5000"
          placeholder="Например: выровнять контур, сохранить положение рук и ног"
        />
        <div class="tools">
          <button type="button" class="btn btn-secondary" :disabled="!strokes.length" @click="exportTask">
            <Download :size="16" />Скачать JSON</button
          ><button type="button" class="btn btn-secondary" @click="importInput?.click()">
            <Upload :size="16" />Открыть JSON рисунка
          </button>
        </div>
      </details>
      <input ref="importInput" type="file" accept="application/json,.json" hidden @change="importDrawing" />
      <button type="button" class="btn btn-primary" :disabled="!strokes.length" @click="apply">
        <Check :size="17" />{{ busy ? 'Сохраняем…' : 'Использовать рисунок' }}
      </button>
    </fieldset>
  </section>
</template>

<style scoped>
.asana-drawing {
  border: 1px solid #dce7d7;
  border-radius: 14px;
  background: #fff;
  padding: 14px;
  display: grid;
  gap: 12px;
  min-width: 0;
}
header,
header strong,
.tools {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
header {
  justify-content: space-between;
}
header strong {
  font-size: 0.92rem;
}
.help {
  font-size: 0.8rem;
  line-height: 1.5;
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
.selected {
  border-color: #397a28;
  color: #397a28;
  background: #eef6e9;
}
.ranges {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 18px;
}
.ranges label {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 6px;
  font-size: 0.8rem;
}
.ranges input {
  grid-column: 1/-1;
  width: 100%;
  accent-color: #397a28;
}
canvas {
  width: 100%;
  height: auto;
  aspect-ratio: 1;
  background: #fff;
  border: 1px solid #d7e1d1;
  border-radius: 12px;
  touch-action: none;
  cursor: crosshair;
}
canvas.erasing {
  cursor: cell;
}
.task-export {
  border-top: 1px solid #e3e9df;
  padding-top: 12px;
}
.task-export summary {
  color: #397a28;
  cursor: pointer;
  font-size: 0.82rem;
}
.task-export[open] {
  display: grid;
  gap: 10px;
}
.task-export .textarea {
  width: 100%;
  margin: 10px 0;
}
@media (max-width: 420px) {
  .ranges {
    grid-template-columns: 1fr;
  }
}
</style>
