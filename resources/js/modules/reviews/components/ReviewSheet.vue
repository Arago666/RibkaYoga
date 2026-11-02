<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { cellFilled, cellGroupEdges, cellLabel, type Practice } from '../../practices/types';
import CellArrow from '../../practices/components/CellArrow.vue';
import { uid } from '@/shared/uuid';
import type { ReviewAnnotations, ReviewArrow, ReviewGroup } from '../types';

const props = withDefaults(
  defineProps<{
    document: Practice;
    annotations: ReviewAnnotations;
    interactive?: boolean;
    selected?: string[];
    arrowFrom?: string | null;
    showNames?: boolean;
  }>(),
  { interactive: false, selected: () => [], arrowFrom: null, showNames: false },
);
const emit = defineEmits<{ select: [cellId: string] }>();
const sheet = ref<HTMLElement | null>(null);
const geometry = ref({ width: 720, height: 1, arrows: [] as ArrowGeometry[] });
const markerPrefix = `review-arrow-${uid()}`;
let observer: ResizeObserver | null = null;
let scheduled = 0;
let disposed = false;

interface PointBox {
  x: number;
  y: number;
  width: number;
  height: number;
}
interface ArrowGeometry {
  id: string;
  path: string;
  color: string;
  label: string;
  labelX: number;
  labelY: number;
}
const rows = computed(() => props.document.blocks.flatMap((block) => block.rows));
const columns = computed(() => Math.max(1, ...rows.value.map((row) => row.cells.length)));
const sheetWidth = computed(() => Math.max(720, 176 + columns.value * 112 + (columns.value - 1) * 18));
const selectedIds = computed(() => new Set(props.selected));
const positions = computed(() => {
  const result = new Map<string, { label: string; order: number }>();
  let order = 0;
  props.document.blocks.forEach((block, blockIndex) =>
    block.rows.forEach((row, rowIndex) =>
      row.cells.forEach((cell, cellIndex) =>
        result.set(cell.id, { label: `${blockIndex + 1}.${rowIndex + 1}.${cellIndex + 1}`, order: order++ }),
      ),
    ),
  );
  return result;
});
const groupsByCell = computed(() => {
  const result = new Map<string, { group: ReviewGroup; number: number }[]>();
  props.annotations.groups.forEach((group, index) => {
    for (const id of group.cell_ids) {
      const entries = result.get(id) ?? [];
      entries.push({ group, number: index + 1 });
      result.set(id, entries);
    }
  });
  return result;
});
const groupNotesByCell = computed(() => {
  const result = new Map<string, { group: ReviewGroup; number: number }[]>();
  props.annotations.groups.forEach((group, index) => {
    const first = group.cell_ids
      .filter((id) => positions.value.has(id))
      .sort((a, b) => positions.value.get(a)!.order - positions.value.get(b)!.order)[0];
    if (!first || !group.body.trim()) return;
    const entries = result.get(first) ?? [];
    entries.push({ group, number: index + 1 });
    result.set(first, entries);
  });
  return result;
});
const commentsByCell = computed(() => {
  const result = new Map<string, ReviewAnnotations['comments']>();
  for (const comment of props.annotations.comments) {
    const entries = result.get(comment.cell_id) ?? [];
    entries.push(comment);
    result.set(comment.cell_id, entries);
  }
  return result;
});
function color(value: string) {
  return /^#[0-9a-f]{6}$/i.test(value) ? value : '#2ac45a';
}
function highlight(id: string) {
  const groups = groupsByCell.value.get(id) ?? [];
  if (!groups.length) return undefined;
  const stops = groups.flatMap(({ group }, index) => [
    `${color(group.color)}30 ${(index / groups.length) * 100}%`,
    `${color(group.color)}30 ${((index + 1) / groups.length) * 100}%`,
  ]);
  return { backgroundImage: `linear-gradient(90deg, ${stops.join(', ')})` };
}
function noteParts(note?: string | null) {
  return (note ?? '')
    .split(/[·;]/)
    .map((part) => part.trim())
    .filter(Boolean);
}
function transitionSign(note?: string | null) {
  return noteParts(note).find((part) => /^(→|↔|=|\/)$/.test(part));
}
function poseSigns(note?: string | null) {
  return [...new Set(noteParts(note).filter((part) => /^(S|★|СВ\*)$/.test(part)))];
}
function caption(note?: string | null) {
  return noteParts(note)
    .filter((part) => !/^(S|★|→|↔|=|\/|СВ\*)$/.test(part))
    .join(' · ');
}
function underlined(note: string | null | undefined, position: number, count: number, continues = false) {
  const definition = note?.match(/^Подчёркнуто:\s*([\d\s–;]+)$/)?.[1];
  return (definition ?? '').split(';').some((range) => {
    const [start, end = start] = range.trim().split('–').map(Number);
    return (
      start >= 1 &&
      end <= count &&
      end >= start &&
      position >= start &&
      (continues ? position < end : position <= end)
    );
  });
}
function rowNote(note?: string | null) {
  if (/^Подчёркнуто:/.test(note ?? '')) return '';
  return props.showNames ? note : poseSigns(note).join(' · ');
}
function groupPositions(group: ReviewGroup) {
  return group.cell_ids
    .map((id) => positions.value.get(id)?.label)
    .filter(Boolean)
    .join(', ');
}
function arrowDescription(arrow: ReviewArrow, index: number) {
  return `Стрелка ${index + 1}: ${positions.value.get(arrow.from_cell_id)?.label ?? '—'} → ${positions.value.get(arrow.to_cell_id)?.label ?? '—'}`;
}
function scheduleMeasure() {
  if (disposed || scheduled) return;
  scheduled = requestAnimationFrame(() => {
    scheduled = 0;
    measure();
  });
}
function measure() {
  const element = sheet.value;
  if (!element) return;
  const root = element.getBoundingClientRect();
  const scale = element.offsetWidth ? root.width / element.offsetWidth : 1;
  const width = element.offsetWidth;
  const height = element.offsetHeight;
  const anchors = new Map<string, PointBox>();
  element.querySelectorAll<HTMLElement>('[data-review-anchor]').forEach((target) => {
    const rect = target.getBoundingClientRect();
    const id = target.dataset.reviewAnchor;
    if (id)
      anchors.set(id, {
        x: (rect.left - root.left) / scale,
        y: (rect.top - root.top) / scale,
        width: rect.width / scale,
        height: rect.height / scale,
      });
  });
  const arrows: ArrowGeometry[] = [];
  props.annotations.arrows.forEach((arrow, index) => {
    const from = anchors.get(arrow.from_cell_id),
      to = anchors.get(arrow.to_cell_id);
    if (!from || !to || arrow.from_cell_id === arrow.to_cell_id) return;
    const lane = index % 4;
    const x1 = from.x + from.width / 2,
      x2 = to.x + to.width / 2;
    const y1 = from.y - 3,
      y2 = to.y - 3;
    let path: string, labelX: number, labelY: number;
    if (Math.abs(y1 - y2) < 2) {
      const top = Math.max(12, y1 - 25 - lane * 6);
      path = `M ${x1} ${y1} C ${x1} ${top}, ${x2} ${top}, ${x2} ${y2}`;
      labelX = (x1 + x2) / 2;
      labelY = top - 4;
    } else {
      const outerX = width - 18 - lane * 8;
      const top1 = Math.max(12, y1 - 20 - lane * 7),
        top2 = Math.max(12, y2 - 20 - lane * 7);
      path = `M ${x1} ${y1} L ${x1} ${top1 + 5} Q ${x1} ${top1} ${x1 + 5} ${top1} L ${outerX - 5} ${top1} Q ${outerX} ${top1} ${outerX} ${top1 + Math.sign(top2 - top1) * 5} L ${outerX} ${top2 - Math.sign(top2 - top1) * 5} Q ${outerX} ${top2} ${outerX - 5} ${top2} L ${x2 + 5} ${top2} Q ${x2} ${top2} ${x2} ${top2 + 5} L ${x2} ${y2}`;
      labelX = Math.min((x1 + outerX) / 2, width - 120);
      labelY = top1 - 4;
    }
    const body = (arrow.body ?? '').replace(/\s+/g, ' ').trim();
    arrows.push({
      id: arrow.id,
      path,
      color: color(arrow.color),
      label: `${index + 1}${body ? ` · ${body.length > 34 ? body.slice(0, 34) + '…' : body}` : ''}`,
      labelX,
      labelY,
    });
  });
  geometry.value = { width, height, arrows };
}
watch(
  () => [props.document, props.annotations, props.showNames],
  () => void nextTick(scheduleMeasure),
  { deep: true },
);
onMounted(() => {
  if (sheet.value) {
    observer = new ResizeObserver(scheduleMeasure);
    observer.observe(sheet.value);
  }
  window.addEventListener('resize', scheduleMeasure);
  void document.fonts.ready.then(scheduleMeasure);
  scheduleMeasure();
});
onBeforeUnmount(() => {
  disposed = true;
  if (scheduled) cancelAnimationFrame(scheduled);
  observer?.disconnect();
  window.removeEventListener('resize', scheduleMeasure);
});
</script>

<template>
  <div
    class="review-sheet-scroll"
    tabindex="0"
    aria-label="Лист разбора. Длинные строки прокручиваются по горизонтали"
  >
    <article
      ref="sheet"
      class="review-sheet"
      :style="{ width: `${sheetWidth}px` }"
      :aria-label="`Разбор практики: ${document.title}`"
    >
      <header class="review-sheet-heading">
        <h2>{{ document.title }}</h2>
        <p v-if="showNames && document.theme">{{ document.theme }}</p>
      </header>
      <section
        v-for="(block, blockIndex) in document.blocks"
        :key="block.id"
        class="review-block"
        :aria-label="block.title || `Блок ${blockIndex + 1}`"
      >
        <header class="review-block-heading">
          <h3>
            <span>{{ blockIndex + 1 }}</span
            >{{ block.title || `Блок ${blockIndex + 1}` }}
          </h3>
          <p v-if="showNames && block.purpose">{{ block.purpose }}</p>
        </header>
        <div v-for="(row, rowIndex) in block.rows" :key="row.id" class="review-row">
          <div class="review-row-line">
            <span class="review-row-number">{{
              block.title === 'СВ' ? 'СВ' : `${blockIndex + 1}.${rowIndex + 1}`
            }}</span>
            <div class="review-cells">
              <div v-for="(cell, cellIndex) in row.cells" :key="cell.id" class="review-cell-column">
                <component
                  :is="interactive && cellFilled(cell) ? 'button' : 'div'"
                  :type="interactive && cellFilled(cell) ? 'button' : undefined"
                  :role="interactive && cellFilled(cell) ? undefined : 'group'"
                  :class="[
                    'review-pose',
                    {
                      filled: cellFilled(cell),
                      selected: selectedIds.has(cell.id),
                      'arrow-from': arrowFrom === cell.id,
                      underlined: underlined(row.note, cellIndex + 1, row.cells.length),
                      'underline-continues': underlined(row.note, cellIndex + 1, row.cells.length, true),
                    },
                  ]"
                  :style="highlight(cell.id)"
                  :data-review-anchor="cell.id"
                  :aria-label="`${positions.get(cell.id)?.label}: ${cellLabel(cell)}${selectedIds.has(cell.id) ? ', выбрана' : ''}${arrowFrom === cell.id ? ', начало стрелки' : ''}`"
                  :aria-pressed="interactive && cellFilled(cell) ? selectedIds.has(cell.id) : undefined"
                  :title="cellLabel(cell)"
                  @click="interactive && cellFilled(cell) && emit('select', cell.id)"
                >
                  <span class="review-position" aria-hidden="true">{{ cellIndex + 1 }}</span>
                  <span
                    v-if="cellGroupEdges(row, cellIndex).start"
                    class="source-bracket source-bracket-start"
                    role="img"
                    aria-label="Начало исходной группы"
                  />
                  <span
                    v-if="cellGroupEdges(row, cellIndex).end"
                    class="source-bracket source-bracket-end"
                    role="img"
                    aria-label="Конец исходной группы"
                  />
                  <CellArrow v-if="cell.arrow_direction" :direction="cell.arrow_direction" />
                  <span v-if="transitionSign(cell.note)" class="source-transition">{{
                    transitionSign(cell.note)
                  }}</span>
                  <span
                    v-for="sign in poseSigns(cell.note)"
                    :key="sign"
                    :class="['source-sign', { 'source-sign-right': sign === '★' }]"
                    >{{ sign }}</span
                  >
                  <img
                    v-if="cell.card"
                    :src="cell.card.image_url"
                    :alt="cell.card.name"
                    draggable="false"
                    @load="scheduleMeasure"
                    @error="scheduleMeasure"
                  />
                  <span v-else-if="cell.custom_name" class="review-custom-name">{{ cell.custom_name }}</span>
                  <span v-else class="review-empty">Пусто</span>
                </component>
                <div v-if="groupsByCell.has(cell.id)" class="review-group-badges" aria-label="Цветные группы">
                  <span
                    v-for="entry in groupsByCell.get(cell.id)"
                    :key="entry.group.id"
                    :style="{ '--annotation-color': color(entry.group.color) }"
                    :title="`Группа ${entry.number}: ${groupPositions(entry.group)}`"
                    >{{ entry.number }}</span
                  >
                </div>
                <p v-if="showNames && cell.card" class="review-pose-name">{{ cell.card.name }}</p>
                <p v-if="showNames && caption(cell.note)" class="review-source-note">
                  {{ caption(cell.note) }}
                </p>
                <div
                  v-for="comment in commentsByCell.get(cell.id)"
                  :key="comment.id"
                  class="review-comment"
                  :style="{
                    '--annotation-color': color(comment.color),
                    backgroundColor: `${color(comment.color)}12`,
                  }"
                >
                  <p>{{ comment.body }}</p>
                </div>
                <div
                  v-for="entry in groupNotesByCell.get(cell.id)"
                  :key="entry.group.id"
                  class="review-comment review-group-comment"
                  :style="{
                    '--annotation-color': color(entry.group.color),
                    backgroundColor: `${color(entry.group.color)}18`,
                  }"
                >
                  <strong>Группа {{ entry.number }}</strong>
                  <small>{{ groupPositions(entry.group) }}</small>
                  <p>{{ entry.group.body }}</p>
                </div>
              </div>
            </div>
          </div>
          <p v-if="rowNote(row.note)" class="review-row-note">{{ rowNote(row.note) }}</p>
        </div>
      </section>
      <section v-if="annotations.arrows.length" class="review-arrow-notes" aria-label="Связи между асанами">
        <div
          v-for="(arrow, index) in annotations.arrows"
          :key="arrow.id"
          :style="{ '--annotation-color': color(arrow.color) }"
        >
          <strong>{{ arrowDescription(arrow, index) }}</strong>
          <p v-if="arrow.body">{{ arrow.body }}</p>
        </div>
      </section>
      <svg
        v-if="geometry.arrows.length"
        class="review-arrow-overlay"
        :viewBox="`0 0 ${geometry.width} ${geometry.height}`"
        aria-hidden="true"
      >
        <defs>
          <marker
            v-for="arrow in geometry.arrows"
            :id="`${markerPrefix}-${arrow.id}`"
            :key="arrow.id"
            viewBox="0 0 10 10"
            refX="8"
            refY="5"
            markerWidth="7"
            markerHeight="7"
            orient="auto-start-reverse"
          >
            <path d="M 1 1 L 9 5 L 1 9 z" :fill="arrow.color" />
          </marker>
        </defs>
        <g v-for="arrow in geometry.arrows" :key="arrow.id">
          <path
            :d="arrow.path"
            :stroke="arrow.color"
            fill="none"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            :marker-end="`url(#${markerPrefix}-${arrow.id})`"
          />
          <text
            :x="arrow.labelX"
            :y="arrow.labelY"
            :fill="arrow.color"
            text-anchor="middle"
            class="review-arrow-label"
          >
            {{ arrow.label }}
          </text>
        </g>
      </svg>
    </article>
  </div>
</template>

<style scoped>
.review-sheet-scroll {
  max-width: 100%;
  min-width: 0;
  overflow: auto;
  border: 1px solid var(--line);
  border-radius: var(--panel-radius);
  background: #fff;
  overscroll-behavior-x: contain;
}
.review-sheet {
  position: relative;
  min-width: 720px;
  padding: 28px 80px 28px 28px;
  color: var(--text);
  background: #fff;
}
.review-sheet-heading {
  margin-bottom: 28px;
  max-width: 850px;
}
.review-sheet-heading h2 {
  font-size: 24px;
  font-weight: 650;
  line-height: 1.3;
  letter-spacing: -0.03em;
  overflow-wrap: anywhere;
}
.review-sheet-heading p {
  margin-top: 8px;
  color: var(--muted);
  font-size: 14px;
}
.review-block + .review-block {
  margin-top: 28px;
}
.review-block-heading h3 {
  display: flex;
  align-items: baseline;
  gap: 10px;
  font-size: 16px;
  font-weight: 650;
}
.review-block-heading h3 > span {
  font-size: 12px;
  color: var(--muted);
}
.review-block-heading p {
  font-size: 12px;
  color: var(--muted);
  margin-top: 6px;
}
.review-row {
  padding-top: 48px;
  padding-bottom: 18px;
}
.review-row + .review-row {
  border-top: 1px solid #eff2ef;
}
.review-row-line {
  display: grid;
  grid-template-columns: 40px auto;
  gap: 8px;
  align-items: start;
}
.review-row-number {
  font-size: 12px;
  color: var(--muted);
  padding-top: 42px;
  font-variant-numeric: tabular-nums;
}
.review-cells {
  display: grid;
  grid-auto-flow: column;
  grid-auto-columns: 112px;
  justify-content: start;
  gap: 18px;
  align-items: start;
}
.review-cell-column {
  min-width: 0;
}
.review-pose {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 112px;
  height: 112px;
  padding: 10px;
  border: 1px solid #e9eee9;
  border-radius: 12px;
  background-color: #fff;
  color: var(--text);
  font: inherit;
  text-align: center;
  user-select: none;
}
button.review-pose {
  touch-action: manipulation;
}
button.review-pose:hover {
  border-color: var(--accent-dark);
}
.review-pose.selected {
  outline: 3px solid var(--accent-dark);
  outline-offset: 3px;
}
.review-pose.arrow-from {
  outline: 3px dashed var(--accent-dark);
  outline-offset: 3px;
}
.review-pose img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  mix-blend-mode: multiply;
  pointer-events: none;
}
.review-position {
  position: absolute;
  top: 4px;
  left: 6px;
  font-size: 9px;
  color: var(--muted);
}
.review-custom-name {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  font-size: 13px;
  line-height: 1.4;
  font-weight: 550;
}
.review-empty {
  color: #959d98;
  font-size: 11px;
}
.review-pose:not(.filled) {
  border-style: dashed;
}
.source-bracket {
  position: absolute;
  top: 1px;
  bottom: 1px;
  width: 7px;
  border-top: 2px solid #d4ae00;
  border-bottom: 2px solid #d4ae00;
  z-index: 1;
  pointer-events: none;
}
.source-bracket-start {
  left: -1px;
  border-left: 3px solid #d4ae00;
}
.source-bracket-end {
  right: -1px;
  border-right: 3px solid #d4ae00;
}
.source-transition {
  position: absolute;
  right: -15px;
  top: 43px;
  width: 13px;
  font-size: 16px;
  text-align: center;
  z-index: 1;
}
.source-sign {
  position: absolute;
  bottom: 4px;
  left: 5px;
  font-size: 12px;
}
.source-sign-right {
  left: auto;
  right: 5px;
}
.review-pose.underlined::after {
  content: '';
  position: absolute;
  bottom: -4px;
  left: 0;
  width: 100%;
  border-bottom: 1px solid #151916;
}
.review-pose.underline-continues::after {
  width: calc(100% + 18px);
}
.review-group-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 7px;
}
.review-group-badges > span {
  display: grid;
  place-items: center;
  min-width: 18px;
  height: 18px;
  padding: 0 4px;
  border-radius: 999px;
  background: color-mix(in srgb, var(--annotation-color) 16%, white);
  border: 1px solid var(--annotation-color);
  color: var(--text);
  font-size: 10px;
}
.review-pose-name,
.review-source-note {
  margin-top: 7px;
  font-size: 11px;
  line-height: 1.4;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
.review-source-note {
  color: var(--muted);
}
.review-comment {
  margin-top: 9px;
  padding: 8px 7px;
  border-left: 3px solid var(--annotation-color);
  border-radius: 0 8px 8px 0;
  font-size: 12px;
  line-height: 1.45;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
.review-comment p {
  margin: 0;
}
.review-comment strong {
  display: block;
  font-size: 11px;
  margin-bottom: 4px;
}
.review-comment small {
  display: block;
  font-size: 9px;
  line-height: 1.35;
  color: var(--muted);
  margin-bottom: 5px;
}
.review-row-note {
  margin: 12px 0 0 48px;
  color: var(--muted);
  font-size: 11px;
  line-height: 1.5;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.review-arrow-notes {
  display: grid;
  gap: 10px;
  margin: 28px 0 0 48px;
  max-width: 850px;
  font-size: 12px;
  line-height: 1.5;
}
.review-arrow-notes > div {
  padding-left: 10px;
  border-left: 3px solid var(--annotation-color);
  overflow-wrap: anywhere;
}
.review-arrow-notes strong {
  font-weight: 600;
}
.review-arrow-notes p {
  margin-top: 3px;
  white-space: pre-wrap;
}
.review-arrow-overlay {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
  overflow: visible;
}
.review-arrow-label {
  font-family: var(--font-sans);
  font-size: 11px;
  font-weight: 600;
  paint-order: stroke;
  stroke: #fff;
  stroke-width: 4px;
  stroke-linejoin: round;
}
@media print {
  .review-sheet-scroll {
    overflow: visible;
    border: 0;
    border-radius: 0;
  }
  .review-sheet {
    print-color-adjust: exact;
    -webkit-print-color-adjust: exact;
  }
  .review-row {
    break-inside: avoid;
    page-break-inside: avoid;
  }
  .review-pose {
    outline: none !important;
  }
  .source-bracket {
    border-color: #151916;
  }
}
</style>
