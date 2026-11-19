<script setup lang="ts">
import { computed } from 'vue';
import { ListPlus } from '@lucide/vue';
import { cellFilled, cellLabel, resolvedExecutionParameters, type Cell, type Practice } from '../types';

const props = defineProps<{ practice: Practice }>();
const blocks = computed(() =>
  props.practice.blocks
    .map((block) => ({
      id: block.id,
      title: block.title,
      rows: block.rows
        .map((row) => ({ ...row, cells: row.cells.filter(cellFilled) }))
        .filter((row) => row.cells.length),
    }))
    .filter((block) => block.rows.length),
);
function holdLabel(cell: Cell) {
  const values = resolvedExecutionParameters(cell, props.practice);
  if (values.advance_mode === 'breaths' && values.breath_count) return `${values.breath_count} дых. циклов`;
  if (values.advance_mode === 'time' && values.duration_seconds) {
    const minutes = Math.floor(values.duration_seconds / 60);
    const seconds = values.duration_seconds % 60;
    if (!minutes) return `${seconds} сек`;
    return seconds ? `${minutes} мин ${seconds} сек` : `${minutes} мин`;
  }
  return '';
}
function details(cell: Cell) {
  return [
    cell.side === 'left'
      ? 'Левая сторона'
      : cell.side === 'right'
        ? 'Правая сторона'
        : cell.side === 'both'
          ? 'Обе стороны'
          : '',
    cell.repetitions && cell.repetitions > 1 ? `Повторы: ${cell.repetitions}` : '',
  ]
    .filter(Boolean)
    .join(' · ');
}
</script>

<template>
  <div v-if="blocks.length" class="sequence-list">
    <section v-for="block in blocks" :key="block.id" class="card sequence-list-block">
      <h2>{{ block.title }}</h2>
      <template v-for="row in block.rows" :key="row.id">
        <ol>
          <li v-for="cell in row.cells" :key="cell.id" class="sequence-list-pose">
            <img
              v-if="cell.card?.image_url"
              :src="cell.card.image_url"
              alt=""
              width="56"
              height="56"
              loading="lazy"
              decoding="async"
            />
            <span v-else class="pose-placeholder" aria-hidden="true"><ListPlus :size="20" /></span>
            <div>
              <strong>{{ cellLabel(cell) }}</strong>
              <small v-if="details(cell)">{{ details(cell) }}</small>
              <p v-if="cell.note">{{ cell.note }}</p>
            </div>
            <span v-if="holdLabel(cell)" class="pose-hold">{{ holdLabel(cell) }}</span>
          </li>
        </ol>
        <p v-if="row.note" class="row-note">{{ row.note }}</p>
      </template>
    </section>
  </div>
  <p v-else class="card muted">В этой практике пока нет заполненных шагов.</p>
</template>

<style scoped>
.sequence-list {
  display: grid;
  gap: 14px;
}
.sequence-list-block {
  min-width: 0;
  overflow: hidden;
  padding: 0;
}
h2 {
  margin: 0;
  padding: 12px 14px;
  font-size: 1rem;
  background: var(--accent-soft);
  overflow-wrap: anywhere;
}
ol {
  margin: 0;
  padding: 0;
  list-style: none;
}
.sequence-list-pose {
  display: grid;
  grid-template-columns: 48px minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
  min-height: 68px;
  padding: 10px 12px;
  border-top: 1px solid var(--line);
}
.sequence-list-pose strong {
  overflow-wrap: anywhere;
}
.sequence-list-pose img,
.pose-placeholder {
  width: 48px;
  height: 48px;
  object-fit: contain;
}
.pose-placeholder {
  display: grid;
  place-items: center;
  color: var(--accent-dark);
}
.sequence-list-pose small,
.sequence-list-pose p,
.row-note {
  display: block;
  margin: 4px 0 0;
  color: var(--muted);
  font-size: 0.82rem;
  overflow-wrap: anywhere;
}
.row-note {
  margin: 0;
  padding: 8px 12px;
  border-top: 1px solid var(--line);
}
.pose-hold {
  max-width: 76px;
  color: var(--accent-dark);
  font-size: 0.8rem;
  text-align: end;
  font-variant-numeric: tabular-nums;
}
</style>
