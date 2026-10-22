<script setup lang="ts">
import { nextTick, onMounted, ref, watch } from 'vue';
import { renderMannequin, type Character, type MannequinPose } from '../illustrations/mannequin';
const props = withDefaults(
  defineProps<{ pose: MannequinPose; style?: 'line' | 'volume'; character?: Character }>(),
  { style: 'volume', character: 'neutral' },
);
const canvas = ref<HTMLCanvasElement | null>(null),
  yaw = ref(props.pose.camera.yaw),
  pitch = ref(props.pose.camera.pitch),
  error = ref('');
let drag: { id: number; x: number; y: number; yaw: number; pitch: number } | null = null;
function paint(): void {
  if (canvas.value)
    try {
      renderMannequin(canvas.value, props.pose, props.style, props.character, yaw.value, pitch.value);
      error.value = '';
    } catch (cause) {
      error.value = cause instanceof Error ? cause.message : 'Не удалось показать фигуру.';
    }
}
function reset(): void {
  yaw.value = props.pose.camera.yaw;
  pitch.value = props.pose.camera.pitch;
  paint();
}
function down(event: PointerEvent): void {
  if (event.button !== 0) return;
  drag = { id: event.pointerId, x: event.clientX, y: event.clientY, yaw: yaw.value, pitch: pitch.value };
  canvas.value?.setPointerCapture(event.pointerId);
}
function move(event: PointerEvent): void {
  if (!drag || drag.id !== event.pointerId) return;
  yaw.value = drag.yaw + (event.clientX - drag.x) * 0.5;
  pitch.value = Math.max(-85, Math.min(85, drag.pitch + (event.clientY - drag.y) * 0.4));
  paint();
}
function up(event: PointerEvent): void {
  if (drag?.id !== event.pointerId) return;
  if (canvas.value?.hasPointerCapture(event.pointerId)) canvas.value.releasePointerCapture(event.pointerId);
  drag = null;
}
function keys(event: KeyboardEvent): void {
  if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home'].includes(event.key)) return;
  event.preventDefault();
  if (event.key === 'Home') reset();
  else {
    yaw.value += event.key === 'ArrowLeft' ? -10 : event.key === 'ArrowRight' ? 10 : 0;
    pitch.value = Math.max(
      -85,
      Math.min(85, pitch.value + (event.key === 'ArrowUp' ? -10 : event.key === 'ArrowDown' ? 10 : 0)),
    );
    paint();
  }
}
watch(
  () => props.pose,
  async () => {
    await nextTick();
    reset();
  },
);
watch(() => [props.style, props.character], paint);
onMounted(paint);
</script>
<template>
  <div class="mannequin-view">
    <p v-if="error" role="alert">{{ error }}</p>
    <canvas
      ref="canvas"
      width="768"
      height="768"
      tabindex="0"
      role="img"
      :aria-label="`${pose.name}, параметрическая объёмная фигура. Стрелки вращают вид, Home возвращает исходный.`"
      @pointerdown="down"
      @pointermove="move"
      @pointerup="up"
      @pointercancel="up"
      @lostpointercapture="drag = null"
      @keydown="keys"
    ></canvas>
    <div class="mannequin-controls">
      <button
        class="btn"
        type="button"
        @click="
          yaw -= 15;
          paint();
        "
        aria-label="Повернуть влево"
      >
        ↶ 15°</button
      ><button class="btn" type="button" @click="reset">Исходный вид</button
      ><button
        class="btn"
        type="button"
        @click="
          yaw += 15;
          paint();
        "
        aria-label="Повернуть вправо"
      >
        15° ↷</button
      ><label
        >Наклон вида<input v-model.number="pitch" type="range" min="-85" max="85" @input="paint"
      /></label>
    </div>
    <p class="muted">
      Поверните фигуру мышью, пальцем или стрелками клавиатуры. PNG сохраняется в проверенном исходном
      ракурсе.
    </p>
  </div>
</template>
<style scoped>
.mannequin-view canvas {
  display: block;
  width: 100%;
  max-width: 520px;
  aspect-ratio: 1;
  margin: auto;
  background: white;
  touch-action: none;
  border-radius: 0.7rem;
  border: 1px solid #dae3d7;
  cursor: grab;
}
.mannequin-view canvas:active {
  cursor: grabbing;
}
.mannequin-controls {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.5rem;
  margin-top: 0.5rem;
}
.mannequin-controls label {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.muted {
  font-size: 0.8rem;
  color: #61705c;
  margin: 0.6rem 0;
  text-align: center;
}
</style>
