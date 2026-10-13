<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Crop, RotateCw, Download, Check, RotateCcw } from '@lucide/vue';
import {
  canvasFile,
  codexImageTask,
  downloadJson,
  imageFileName,
  loadCanvasImage,
  type ImageTaskContext,
} from '../imageTools';

const props = defineProps<{ source: string; context: ImageTaskContext; disabled?: boolean }>();
const emit = defineEmits<{ image: [file: File]; close: [] }>();
const canvas = ref<HTMLCanvasElement | null>(null);
const stage = ref<HTMLDivElement | null>(null);
const loading = ref(false),
  busy = ref(false),
  error = ref('');
const ready = ref(false);
const brightness = ref(100),
  contrast = ref(100),
  saturation = ref(100),
  grayscale = ref(false);
const rotation = ref(0);
const crop = ref({ x: 0, y: 0, width: 100, height: 100 });
const instructions = ref('');
let image: HTMLImageElement | null = null;
let rotated: HTMLCanvasElement | null = null;
let loadVersion = 0;
let start: { x: number; y: number; previous: typeof crop.value; pointer: number } | null = null;
const filter = computed(
  () =>
    `brightness(${brightness.value}%) contrast(${contrast.value}%) saturate(${saturation.value}%) grayscale(${grayscale.value ? 100 : 0}%)`,
);
const cropStyle = computed(() => ({
  left: `${crop.value.x}%`,
  top: `${crop.value.y}%`,
  width: `${crop.value.width}%`,
  height: `${crop.value.height}%`,
}));

function rotatedImage(): HTMLCanvasElement {
  if (!image) throw new Error('Сначала загрузите изображение.');
  if (rotated) return rotated;
  const output = document.createElement('canvas');
  const quarter = rotation.value % 180 !== 0;
  const scale = Math.min(1, 4096 / Math.max(image.naturalWidth, image.naturalHeight));
  const width = Math.max(1, Math.round(image.naturalWidth * scale));
  const height = Math.max(1, Math.round(image.naturalHeight * scale));
  output.width = quarter ? height : width;
  output.height = quarter ? width : height;
  const ctx = output.getContext('2d');
  if (!ctx) throw new Error('Браузер не поддерживает рисование.');
  ctx.translate(output.width / 2, output.height / 2);
  ctx.rotate((rotation.value * Math.PI) / 180);
  ctx.drawImage(image, -width / 2, -height / 2, width, height);
  rotated = output;
  return output;
}
function render() {
  if (!image || !canvas.value) return;
  const source = rotatedImage();
  const scale = Math.min(1, 900 / Math.max(source.width, source.height));
  canvas.value.width = Math.max(1, Math.round(source.width * scale));
  canvas.value.height = Math.max(1, Math.round(source.height * scale));
  const ctx = canvas.value.getContext('2d');
  if (!ctx) return;
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, canvas.value.width, canvas.value.height);
  ctx.filter = filter.value;
  ctx.drawImage(source, 0, 0, canvas.value.width, canvas.value.height);
}
function result(): HTMLCanvasElement {
  const source = rotatedImage();
  const rect = crop.value;
  const width = (source.width * rect.width) / 100;
  const height = (source.height * rect.height) / 100;
  const scale = Math.min(1, 2048 / Math.max(width, height));
  const output = document.createElement('canvas');
  output.width = Math.max(1, Math.round(width * scale));
  output.height = Math.max(1, Math.round(height * scale));
  const ctx = output.getContext('2d');
  if (!ctx) throw new Error('Браузер не поддерживает рисование.');
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, output.width, output.height);
  ctx.filter = filter.value;
  ctx.drawImage(
    source,
    (source.width * rect.x) / 100,
    (source.height * rect.y) / 100,
    width,
    height,
    0,
    0,
    output.width,
    output.height,
  );
  return output;
}
function reset() {
  brightness.value = contrast.value = saturation.value = 100;
  grayscale.value = false;
  rotation.value = 0;
  rotated = null;
  crop.value = { x: 0, y: 0, width: 100, height: 100 };
  render();
}
function turn() {
  rotation.value = (rotation.value + 90) % 360;
  rotated = null;
  crop.value = { x: 0, y: 0, width: 100, height: 100 };
  render();
}
function updateCrop() {
  crop.value.x = Math.min(99, Math.max(0, Number(crop.value.x) || 0));
  crop.value.y = Math.min(99, Math.max(0, Number(crop.value.y) || 0));
  crop.value.width = Math.min(100 - crop.value.x, Math.max(1, Number(crop.value.width) || 1));
  crop.value.height = Math.min(100 - crop.value.y, Math.max(1, Number(crop.value.height) || 1));
}
function square() {
  if (!image) return;
  const source = rotatedImage();
  const side = Math.min(source.width, source.height);
  crop.value = {
    x: ((source.width - side) / 2 / source.width) * 100,
    y: ((source.height - side) / 2 / source.height) * 100,
    width: (side / source.width) * 100,
    height: (side / source.height) * 100,
  };
}
function position(event: PointerEvent) {
  const rect = stage.value!.getBoundingClientRect();
  return {
    x: Math.max(0, Math.min(100, ((event.clientX - rect.left) / rect.width) * 100)),
    y: Math.max(0, Math.min(100, ((event.clientY - rect.top) / rect.height) * 100)),
  };
}
function begin(event: PointerEvent) {
  if (!image || loading.value || busy.value || props.disabled || event.button !== 0 || !event.isPrimary)
    return;
  const point = position(event);
  start = { ...point, previous: { ...crop.value }, pointer: event.pointerId };
  stage.value?.setPointerCapture(event.pointerId);
}
function move(event: PointerEvent) {
  if (!start || start.pointer !== event.pointerId) return;
  const point = position(event);
  crop.value = {
    x: Math.min(start.x, point.x),
    y: Math.min(start.y, point.y),
    width: Math.abs(point.x - start.x),
    height: Math.abs(point.y - start.y),
  };
}
function finish(event: PointerEvent) {
  if (!start || start.pointer !== event.pointerId) return;
  if (crop.value.width < 1 || crop.value.height < 1) crop.value = start.previous;
  start = null;
}
async function apply() {
  if (!image || busy.value || loading.value || props.disabled) return;
  busy.value = true;
  error.value = '';
  try {
    emit('image', await canvasFile(result(), props.context.name));
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось обработать изображение.';
  } finally {
    busy.value = false;
  }
}
function exportTask() {
  try {
    const task = {
      ...codexImageTask(props.context, instructions.value, result().toDataURL('image/png')),
      processing: {
        brightness: brightness.value,
        contrast: contrast.value,
        saturation: saturation.value,
        grayscale: grayscale.value,
        rotation: rotation.value,
        crop_percent: crop.value,
      },
    };
    downloadJson(task, imageFileName(props.context.name).replace(/\.png$/, '-задание.json'));
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось выгрузить задание.';
  }
}
watch(
  () => props.source,
  async (source) => {
    const version = ++loadVersion;
    image = null;
    rotated = null;
    ready.value = false;
    error.value = '';
    loading.value = true;
    try {
      const loaded = await loadCanvasImage(source);
      if (version !== loadVersion) return;
      image = loaded;
      ready.value = true;
      await nextTick();
      reset();
    } catch (failure) {
      if (version === loadVersion)
        error.value = failure instanceof Error ? failure.message : 'Не удалось загрузить изображение.';
    } finally {
      if (version === loadVersion) loading.value = false;
    }
  },
  { immediate: true },
);
watch([brightness, contrast, saturation, grayscale], render);
onBeforeUnmount(() => {
  loadVersion++;
});
</script>

<template>
  <section class="image-editor" aria-label="Обработка изображения">
    <header>
      <strong><Crop :size="17" /> Обработка изображения</strong
      ><button type="button" class="btn btn-ghost" :disabled="busy || disabled" @click="emit('close')">
        Закрыть
      </button>
    </header>
    <p class="muted help">Выделите кадр пальцем или мышью. Размер можно задать в процентах ниже.</p>
    <p v-if="loading" class="muted" role="status">Открываем изображение…</p>
    <div v-if="error" class="error-banner" role="alert">{{ error }}</div>
    <div
      ref="stage"
      v-show="ready"
      class="crop-stage"
      @pointerdown.prevent="begin"
      @pointermove.prevent="move"
      @pointerup="finish"
      @pointercancel="finish"
    >
      <canvas ref="canvas" aria-label="Предпросмотр изображения с обработкой" />
      <div v-if="ready" class="crop-selection" :style="cropStyle"><span /></div>
    </div>
    <fieldset class="editor-controls" :disabled="disabled || busy || loading || !ready">
      <div class="crop-numbers">
        <label
          >X %<input
            v-model.number="crop.x"
            class="input"
            type="number"
            min="0"
            max="99"
            @change="updateCrop"
        /></label>
        <label
          >Y %<input
            v-model.number="crop.y"
            class="input"
            type="number"
            min="0"
            max="99"
            @change="updateCrop"
        /></label>
        <label
          >Ширина %<input
            v-model.number="crop.width"
            class="input"
            type="number"
            min="1"
            max="100"
            @change="updateCrop"
        /></label>
        <label
          >Высота %<input
            v-model.number="crop.height"
            class="input"
            type="number"
            min="1"
            max="100"
            @change="updateCrop"
        /></label>
      </div>
      <div class="actions">
        <button type="button" class="btn btn-secondary" @click="square">Квадрат по центру</button
        ><button type="button" class="btn btn-secondary" @click="turn"><RotateCw :size="16" />90°</button
        ><button type="button" class="btn btn-ghost" @click="reset"><RotateCcw :size="16" />Сбросить</button>
      </div>
      <label class="range-label"
        >Яркость <output>{{ brightness }}%</output
        ><input v-model.number="brightness" type="range" min="25" max="200"
      /></label>
      <label class="range-label"
        >Контраст <output>{{ contrast }}%</output
        ><input v-model.number="contrast" type="range" min="25" max="250"
      /></label>
      <label class="range-label"
        >Насыщенность <output>{{ saturation }}%</output
        ><input v-model.number="saturation" type="range" min="0" max="200"
      /></label>
      <label class="check-label"><input v-model="grayscale" type="checkbox" />Чёрно-белое</label>
      <details class="task-export">
        <summary>Задание для локального Codex</summary>
        <p class="muted help">
          JSON включает картинку, настройки и данные карточки. Полученный PNG загрузите в поле «Изображение».
        </p>
        <textarea
          v-model="instructions"
          class="textarea"
          rows="2"
          placeholder="Что нужно исправить или дорисовать"
        /><button type="button" class="btn btn-secondary" @click="exportTask">
          <Download :size="16" />Скачать JSON задания
        </button>
      </details>
      <button type="button" class="btn btn-primary" @click="apply">
        <Check :size="17" />{{ busy ? 'Обрабатываем…' : 'Использовать изображение' }}
      </button>
    </fieldset>
  </section>
</template>

<style scoped>
.image-editor {
  border: 1px solid #dce7d7;
  background: #fff;
  border-radius: 14px;
  padding: 14px;
  display: grid;
  gap: 12px;
  min-width: 0;
}
header,
header strong,
.actions {
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
.crop-stage {
  position: relative;
  width: fit-content;
  max-width: 100%;
  margin: auto;
  touch-action: none;
  overflow: hidden;
  cursor: crosshair;
  line-height: 0;
  border: 1px solid #d7e1d1;
  background: #fff;
}
canvas {
  display: block;
  max-width: 100%;
  max-height: 420px;
  width: auto;
  height: auto;
}
.crop-selection {
  position: absolute;
  border: 2px solid #397a28;
  pointer-events: none;
  box-shadow: 0 0 0 900px #20351d40;
}
.crop-selection span {
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(
      to right,
      transparent 32.9%,
      #fff8 33%,
      #fff8 33.3%,
      transparent 33.4%,
      transparent 66.2%,
      #fff8 66.3%,
      #fff8 66.6%,
      transparent 66.7%
    ),
    linear-gradient(
      to bottom,
      transparent 32.9%,
      #fff8 33%,
      #fff8 33.3%,
      transparent 33.4%,
      transparent 66.2%,
      #fff8 66.3%,
      #fff8 66.6%,
      transparent 66.7%
    );
}
.editor-controls {
  border: 0;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 13px;
  min-width: 0;
}
.crop-numbers {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
}
.crop-numbers label {
  font-size: 0.75rem;
  display: grid;
  gap: 5px;
  min-width: 0;
}
.crop-numbers .input {
  padding: 7px;
  min-width: 0;
  width: 100%;
}
.range-label {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 6px;
  font-size: 0.82rem;
}
.range-label input {
  width: 100%;
  grid-column: 1/-1;
  accent-color: #397a28;
}
.task-export {
  border-top: 1px solid #e3e9df;
  padding-top: 12px;
}
.task-export summary {
  cursor: pointer;
  color: #397a28;
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
  .crop-numbers {
    grid-template-columns: 1fr 1fr;
  }
}
</style>
