<script setup lang="ts">
import { computed, ref } from 'vue';
const props = defineProps<{
  zones: { name: string; seconds: number | null; count?: number }[];
  total: number | null;
  unclassified: number;
  planned?: boolean;
  totalSteps?: number;
  unknownCount?: number;
  unknownTimeCount?: number;
}>();
const selected = ref('');
const items = computed(() =>
  [...props.zones].sort((a, b) => (b.seconds ?? b.count ?? 0) - (a.seconds ?? a.count ?? 0)),
);
const fill = (pattern: RegExp) =>
  props.zones.some(
    (z) =>
      (!selected.value || selected.value === z.name) &&
      ((z.seconds ?? 0) > 0 || (z.count ?? 0) > 0) &&
      pattern.test(z.name),
  )
    ? '#63a64b'
    : '#e4e9e1';
</script>
<template>
  <section class="coverage" aria-label="Покрытие зон тела">
    <div class="body-pair">
      <svg viewBox="0 0 190 250" role="img" aria-label="Условная схема зон тела спереди и сзади">
        <g
          v-for="offset in [0, 100]"
          :key="offset"
          :transform="`translate(${offset},0)`"
          stroke="#75816e"
          stroke-width="1.5"
        >
          <circle cx="45" cy="23" r="12" :fill="fill(/Шея/)" />
          <rect x="39" y="35" width="12" height="12" :fill="fill(/Шея/)" />
          <path d="M23 49Q45 39 67 49L62 85H28Z" :fill="fill(offset ? /спина|Позвоночник/ : /Грудь/)" />
          <path d="M28 85H62L58 119H32Z" :fill="fill(offset ? /спина|Позвоночник/ : /Кор/)" />
          <path d="M32 119H58L64 143H26Z" :fill="fill(/Бёдра|ягодицы/)" />
          <path
            d="M22 49L10 93L7 125L16 127L23 99L29 64Z M68 49L80 93L83 125L74 127L67 99L61 64Z"
            :fill="fill(/Плечи|руки|Запястья/)"
          />
          <path
            d="M26 143L28 182L25 225H38L44 181L45 144Z M45 144L47 181L52 225H65L62 182L64 143Z"
            :fill="fill(/бедра|мышц|квадрицепсов|сухожилий|Бёдра/)"
          />
          <path d="M25 205H39L38 230H21Z M51 205H64L68 230H53Z" :fill="fill(/Икры|лодыжки/)" />
        </g>
        <text x="45" y="247" text-anchor="middle" font-size="9">Спереди</text>
        <text x="145" y="247" text-anchor="middle" font-size="9">Сзади</text>
      </svg>
    </div>
    <div class="zone-list">
      <p class="muted">Покрытие по разметке поз, не измерение активности мышц.</p>
      <button
        v-for="zone in items"
        :key="zone.name"
        class="zone"
        :aria-pressed="selected === zone.name"
        @click="selected = selected === zone.name ? '' : zone.name"
      >
        <span>{{ zone.name }}</span
        ><strong
          ><template v-if="planned">{{ zone.count ?? 0 }} поз · </template
          >{{
            zone.seconds === null ? 'время не задано' : `${Math.round((zone.seconds / 60) * 10) / 10} мин`
          }}</strong
        >
        <progress
          :max="Math.max(total ?? totalSteps ?? 1, 1)"
          :value="zone.seconds !== null && total !== null ? zone.seconds : (zone.count ?? 0)"
          :aria-label="zone.name"
        />
      </button>
      <p v-if="!items.length">Для этих рисунков ещё нет проверенной разметки.</p>
      <p class="muted">
        Без разметки: {{ Math.round((unclassified / 60) * 10) / 10 }} мин. Одна поза может относиться к
        нескольким зонам; покрытия складывать нельзя.
      </p>
      <p v-if="planned" class="muted">
        Без проверенной разметки: {{ unknownCount ?? 0 }} поз. Без заданного времени:
        {{ unknownTimeCount ?? 0 }} поз. Время таких шагов не угадывается.
      </p>
    </div>
  </section>
</template>
<style scoped>
.coverage {
  display: flex;
  gap: 24px;
  align-items: flex-start;
}
.body-pair {
  width: 190px;
  flex: none;
}
.body-pair svg {
  width: 100%;
}
.zone-list {
  flex: 1;
}
.zone {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 6px;
  width: 100%;
  text-align: left;
  padding: 10px;
  border: 1px solid #e0e7dc;
  border-radius: 10px;
  background: white;
  margin: 6px 0;
}
.zone[aria-pressed='true'] {
  border-color: #427f2c;
}
.zone progress {
  grid-column: 1/-1;
  width: 100%;
  height: 7px;
  accent-color: #5b953e;
}
.muted {
  font-size: 0.85rem;
  color: #66725f;
}
@media (max-width: 600px) {
  .coverage {
    display: block;
  }
  .body-pair {
    margin: auto;
    width: 150px;
  }
}
</style>
