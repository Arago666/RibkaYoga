<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ArrowLeft, ArrowRight, Check, Pause, Play, RotateCcw } from '@lucide/vue';

type Mode = 'compose' | 'review' | 'play';
const mode = ref<Mode>('compose');
const poses = [
  { file: 'seated', name: 'Сукхасана' },
  { file: 'standing', name: 'Тадасана' },
  { file: 'downward', name: 'Адхо Мукха Шванасана' },
  { file: 'upward', name: 'Урдхва Мукха Шванасана' },
  { file: 'rest', name: 'Шавасана' },
];
const sequence = ref([0, 1, 2, 3, 0, 4]);
const selected = ref(2);
const group = ref([2, 3]);
const comment = ref('Мягкий переход от опоры к раскрытию.');
const current = ref(0);
const remaining = ref(60);
const playing = ref(false);
const currentPose = computed(() => poses[sequence.value[current.value]]);
const clock = computed(
  () => `${Math.floor(remaining.value / 60)}:${String(remaining.value % 60).padStart(2, '0')}`,
);
const progress = computed(() => `${((60 - remaining.value) / 60) * 360}deg`);
let timer: ReturnType<typeof setInterval> | undefined;
function choose(index: number) {
  if (mode.value === 'compose') selected.value = index;
  if (mode.value === 'review') {
    group.value = group.value.includes(index)
      ? group.value.filter((item) => item !== index)
      : [...group.value, index].sort();
  }
}
function togglePlay() {
  if (!playing.value && remaining.value === 0) {
    current.value = 0;
    remaining.value = 60;
  }
  playing.value = !playing.value;
}
function move(direction: number) {
  current.value = (current.value + direction + sequence.value.length) % sequence.value.length;
  remaining.value = 60;
}
function reset() {
  sequence.value = [0, 1, 2, 3, 0, 4];
  selected.value = 2;
  current.value = 0;
  remaining.value = 60;
  playing.value = false;
  group.value = [2, 3];
  comment.value = 'Мягкий переход от опоры к раскрытию.';
}
function pauseWhenHidden() {
  if (document.hidden) playing.value = false;
}
watch(mode, () => {
  playing.value = false;
});
onMounted(() => {
  timer = setInterval(() => {
    if (!playing.value) return;
    if (remaining.value > 1) remaining.value--;
    else if (current.value < sequence.value.length - 1) move(1);
    else {
      remaining.value = 0;
      playing.value = false;
    }
  }, 1000);
  document.addEventListener('visibilitychange', pauseWhenHidden);
});
onBeforeUnmount(() => {
  clearInterval(timer);
  document.removeEventListener('visibilitychange', pauseWhenHidden);
});
</script>

<template>
  <div class="practice-demo">
    <div class="demo-topbar">
      <span class="demo-document"><span class="demo-status-dot"></span>Утренняя практика</span>
      <button type="button" class="demo-reset" aria-label="Сбросить демонстрацию" @click="reset">
        <RotateCcw :size="16" />
      </button>
    </div>
    <div class="demo-modes" aria-label="Возможности конструктора">
      <button
        v-for="item in [
          { id: 'compose', label: 'Собрать' },
          { id: 'review', label: 'Разобрать' },
          { id: 'play', label: 'Провести' },
        ] as const"
        :key="item.id"
        type="button"
        :aria-pressed="mode === item.id"
        :class="{ active: mode === item.id }"
        @click="mode = item.id"
      >
        {{ item.label }}
      </button>
    </div>
    <template v-if="mode !== 'play'">
      <div class="demo-section-title"><span>01 / Начало практики</span><span>6 асан</span></div>
      <div class="demo-sequence">
        <button
          v-for="(pose, index) in sequence"
          :key="index"
          type="button"
          :class="[
            'demo-cell',
            {
              selected: mode === 'compose' && selected === index,
              grouped: mode === 'review' && group.includes(index),
            },
          ]"
          :aria-label="`${index + 1}. ${poses[pose].name}. ${mode === 'compose' ? 'Выбрать для замены' : 'Выделить для комментария'}`"
          :aria-pressed="mode === 'compose' ? selected === index : group.includes(index)"
          @click="choose(index)"
        >
          <span class="demo-cell-number">0{{ index + 1 }}</span
          ><img
            :src="`/images/brand/poses/${poses[pose].file}.svg`"
            :alt="poses[pose].name"
            width="100"
            height="100"
          /><Check v-if="mode === 'review' && group.includes(index)" class="demo-cell-check" :size="13" />
        </button>
      </div>
      <div v-if="mode === 'compose'" class="demo-library">
        <div class="demo-hint"><span>Ваша библиотека</span><span>Нажмите на рисунок для замены</span></div>
        <div class="demo-palette">
          <button
            v-for="(pose, index) in poses"
            :key="pose.file"
            type="button"
            :aria-label="`Вставить: ${pose.name}`"
            :title="pose.name"
            @click="sequence[selected] = index"
          >
            <img :src="`/images/brand/poses/${pose.file}.svg`" :alt="pose.name" width="60" height="60" />
          </button>
        </div>
      </div>
      <div v-else class="demo-review-note">
        <label for="demo-comment"
          ><span class="demo-note-dot"></span
          >{{
            group.length
              ? `Комментарий к ${group.map((index) => index + 1).join(', ')}`
              : 'Выберите асаны для комментария'
          }}</label
        >
        <textarea
          id="demo-comment"
          v-model="comment"
          maxlength="180"
          rows="2"
          :disabled="!group.length"
          placeholder="Ваша мысль о последовательности…"
        ></textarea>
        <p>Выделите несколько асан, чтобы объединить их одной мыслью.</p>
      </div>
    </template>
    <div v-else class="demo-player">
      <div class="demo-player-image">
        <img
          :src="`/images/brand/poses/${currentPose.file}.svg`"
          :alt="currentPose.name"
          width="150"
          height="150"
        />
      </div>
      <div class="demo-player-info">
        <span>Асана {{ current + 1 }} из {{ sequence.length }}</span>
        <h3>{{ currentPose.name }}</h3>
        <div class="demo-clock" :style="{ '--demo-progress': progress }">
          <span>{{ clock }}</span>
        </div>
      </div>
      <div class="demo-player-controls">
        <button type="button" aria-label="Предыдущая асана" @click="move(-1)"><ArrowLeft :size="18" /></button
        ><button
          type="button"
          class="demo-play"
          :aria-label="playing ? 'Приостановить демонстрацию' : 'Запустить демонстрацию'"
          @click="togglePlay"
        >
          <component :is="playing ? Pause : Play" :size="21" /></button
        ><button type="button" aria-label="Следующая асана" @click="move(1)">
          <ArrowRight :size="18" />
        </button>
      </div>
    </div>
    <p class="demo-footnote">Интерактивный пример · без сохранения и звука</p>
  </div>
</template>

<style scoped>
.practice-demo {
  color: #1d3027;
  background: #fff;
  border: 1px solid #e0e9e1;
  border-radius: 24px;
  padding: 25px;
  box-shadow: 0 28px 75px #061d2124;
  min-width: 0;
}
.demo-topbar,
.demo-section-title,
.demo-hint,
.demo-document {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.demo-document {
  justify-content: flex-start;
  font-size: 14px;
  font-weight: 600;
}
.demo-status-dot,
.demo-note-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #62b57a;
  flex-shrink: 0;
}
.demo-reset {
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: #f5f8f4;
  border: 1px solid #e4e9e1;
  color: #6a766d;
}
.demo-modes {
  display: flex;
  border-radius: 100px;
  background: #f3f5f1;
  padding: 5px;
  margin: 19px 0 27px;
}
.demo-modes button {
  flex: 1;
  border: 0;
  background: transparent;
  color: #758076;
  border-radius: 100px;
  font-size: 13px;
  font-weight: 550;
  padding: 11px 5px;
  min-height: 44px;
}
.demo-modes button.active {
  background: #fff;
  box-shadow: 0 2px 6px #223d2610;
  color: #1d5033;
}
.demo-section-title {
  font-size: 11px;
  color: #65705f;
  margin-bottom: 12px;
}
.demo-sequence {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 7px;
}
.demo-cell {
  position: relative;
  border: 1px solid #e6eae2;
  background: #fcfdfb;
  border-radius: 13px;
  padding: 14px 3px 3px;
  aspect-ratio: 0.83;
  min-width: 0;
  transition:
    border-color 0.2s,
    background 0.2s;
}
.demo-cell img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
.demo-cell.selected {
  border-color: #428d61;
  background: #edf6ed;
  box-shadow: 0 0 0 2px #428d6118;
}
.demo-cell.grouped {
  background: #e1efd5;
  border-color: #bfd6ad;
}
.demo-cell-number {
  position: absolute;
  top: 8px;
  left: 9px;
  font-size: 9px;
  color: #65705f;
}
.demo-cell-check {
  position: absolute;
  right: 6px;
  top: 6px;
  color: #3f6c32;
}
.demo-library {
  border-top: 1px solid #edf0e9;
  margin-top: 26px;
  padding-top: 18px;
}
.demo-hint {
  font-size: 10px;
  color: #65705f;
  margin-bottom: 13px;
  flex-wrap: wrap;
}
.demo-hint span:first-child {
  font-size: 12px;
  color: #344539;
  font-weight: 550;
}
.demo-palette {
  display: flex;
  gap: 8px;
}
.demo-palette button {
  border: 1px solid #e5eae1;
  background: #fff;
  border-radius: 11px;
  width: 57px;
  height: 56px;
  padding: 5px;
}
.demo-palette button:hover {
  border-color: #579a6c;
  background: #f0f8ed;
}
.demo-palette img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
.demo-footnote {
  font-size: 10px;
  text-align: right;
  margin-top: 23px;
  color: #65705f;
}
.demo-review-note {
  margin-top: 23px;
  min-height: 133px;
  border-radius: 13px;
  padding: 15px;
  background: #f2f7eb;
}
.demo-review-note label {
  display: flex;
  gap: 8px;
  align-items: center;
  font-size: 11px;
  font-weight: 550;
}
.demo-review-note textarea {
  margin-top: 9px;
  width: 100%;
  background: #ffffffa8;
  border: 1px solid #dce7d4;
  border-radius: 8px;
  padding: 8px;
  font-size: 12px;
  resize: vertical;
}
.demo-review-note p {
  margin-top: 7px;
  font-size: 10px;
  color: #65705f;
  line-height: 1.5;
}
.demo-player {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 15px;
  align-items: center;
  min-height: 250px;
}
.demo-player-image {
  height: 155px;
}
.demo-player-image img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
.demo-player-info > span {
  color: #65705f;
  font-size: 11px;
}
.demo-player-info h3 {
  font-size: 15px;
  line-height: 1.4;
  margin-top: 7px;
}
.demo-clock {
  margin-top: 14px;
  border-radius: 50%;
  width: 74px;
  height: 74px;
  padding: 3px;
  background: conic-gradient(#578455 var(--demo-progress), #e9efe4 0);
  display: grid;
  place-items: center;
}
.demo-clock span {
  width: 68px;
  height: 68px;
  display: grid;
  place-items: center;
  background: #fff;
  border-radius: 50%;
  font-variant-numeric: tabular-nums;
  font-size: 22px;
  letter-spacing: -0.04em;
}
.demo-player-controls {
  grid-column: 1/-1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 20px;
}
.demo-player-controls button {
  width: 44px;
  height: 44px;
  display: grid;
  place-items: center;
  border-radius: 50%;
  background: #f4f7f0;
  border: 1px solid #e4eadf;
  color: #376343;
}
.demo-player-controls .demo-play {
  width: 49px;
  height: 49px;
  color: #fff;
  background: #2d613f;
  border-color: #2d613f;
}
@media (max-width: 600px) {
  .practice-demo {
    padding: 18px;
    border-radius: 20px;
  }
  .demo-sequence {
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
  }
  .demo-cell {
    aspect-ratio: 1.15;
    padding: 13px 10px 4px;
  }
  .demo-modes {
    margin-bottom: 20px;
  }
  .demo-hint {
    gap: 5px;
  }
  .demo-hint span {
    width: 100%;
  }
  .demo-palette button {
    width: min(16vw, 57px);
  }
  .demo-footnote {
    text-align: left;
    font-size: 9px;
  }
}
@media (prefers-reduced-motion: reduce) {
  .demo-cell {
    transition: none;
  }
}
</style>
