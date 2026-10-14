<script setup lang="ts">
import { cellGroupEdges, type Practice } from '../types';
import CellArrow from './CellArrow.vue';

const props = withDefaults(
  defineProps<{ document: Practice; showNames?: boolean; imageVersion?: number }>(),
  {
    showNames: false,
    imageVersion: 0,
  },
);

function imageSource(url: string) {
  return props.imageVersion
    ? `${url}${url.includes('?') ? '&' : '?'}preview_retry=${props.imageVersion}`
    : url;
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

function captionNote(note?: string | null) {
  if (!props.showNames) return '';
  const parts = noteParts(note);
  if (!parts.some((part) => /^(S|★|→|↔|=|\/|СВ\*)$/.test(part))) return note;
  return parts.filter((part) => !/^(S|★|→|↔|=|\/|СВ\*)$/.test(part)).join(' · ');
}

function rowNote(note?: string | null) {
  return props.showNames ? note : poseSigns(note).join(' · ');
}

function rowLabel(title: string, blockIndex: number, rowIndex: number) {
  return title === 'СВ' ? 'СВ' : `${blockIndex + 1}.${rowIndex + 1}`;
}

function underlined(note: string | null | undefined, position: number, cellCount: number, continues = false) {
  const definition = note?.match(/^Подчёркнуто:\s*([\d\s–;]+)$/)?.[1];
  return (definition ?? '').split(';').some((range) => {
    const [start, end = start] = range.trim().split('–').map(Number);
    return (
      start >= 1 &&
      end <= cellCount &&
      end >= start &&
      position >= start &&
      (continues ? position < end : position <= end)
    );
  });
}
</script>

<template>
  <article class="sequence-sheet" :aria-label="`Лист практики: ${document.title}`">
    <header class="sheet-heading">
      <div class="sheet-title-line">
        <h1>{{ document.title }}</h1>
      </div>
      <p v-if="showNames && document.theme" class="sheet-theme">{{ document.theme }}</p>
    </header>

    <section
      v-for="(block, blockIndex) in document.blocks"
      :key="block.id"
      class="sequence-block"
      :aria-label="block.title"
    >
      <div v-for="(row, rowIndex) in block.rows" :key="row.id" class="sequence-row-group">
        <header v-if="rowIndex === 0 && showNames" class="block-heading">
          <h2>
            <span class="block-index">{{ blockIndex + 1 }}</span
            >{{ block.title }}
          </h2>
          <p v-if="block.purpose">{{ block.purpose }}</p>
        </header>

        <div class="sequence-line">
          <span class="row-number" :aria-label="`Строка ${rowLabel(block.title, blockIndex, rowIndex)}`">{{
            rowLabel(block.title, blockIndex, rowIndex)
          }}</span>
          <div
            class="sequence-cells"
            :class="{ 'has-transitions': row.cells.some((cell) => transitionSign(cell.note)) }"
          >
            <figure
              v-for="(cell, cellIndex) in row.cells"
              :key="cell.id"
              :class="[
                'sequence-cell',
                { 'empty-cell': !cell.card && !cell.custom_name, 'grouped-cell': !!cell.group_key },
                {
                  underlined: underlined(row.note, cellIndex + 1, row.cells.length),
                  'underline-continues': underlined(row.note, cellIndex + 1, row.cells.length, true),
                },
              ]"
            >
              <span
                v-if="cellGroupEdges(row, cellIndex).start"
                class="group-bracket group-bracket-start"
                role="img"
                aria-label="Начало группы асан"
              ></span>
              <span
                v-if="cellGroupEdges(row, cellIndex).end"
                class="group-bracket group-bracket-end"
                role="img"
                aria-label="Конец группы асан"
              ></span>
              <CellArrow v-if="cell.arrow_direction" :direction="cell.arrow_direction" />
              <span v-if="transitionSign(cell.note)" class="transition-sign">{{
                transitionSign(cell.note)
              }}</span>
              <span
                v-for="sign in poseSigns(cell.note)"
                :key="sign"
                class="pose-sign"
                :class="{ 'pose-sign-right': sign === '★' }"
                >{{ sign }}</span
              >
              <template v-if="cell.card">
                <div class="card-image-box">
                  <img
                    :src="imageSource(cell.card.image_url)"
                    :alt="cell.card.name"
                    class="card-image"
                    loading="eager"
                    decoding="async"
                  />
                </div>
                <figcaption v-if="showNames">
                  <span v-if="showNames" class="asana-name">{{ cell.card.name }}</span>
                  <span v-if="captionNote(cell.note)" class="cell-note">{{ captionNote(cell.note) }}</span>
                </figcaption>
              </template>
              <template v-else-if="cell.custom_name">
                <div class="custom-content">{{ cell.custom_name }}</div>
                <figcaption v-if="captionNote(cell.note)">
                  <span class="cell-note">{{ captionNote(cell.note) }}</span>
                </figcaption>
              </template>
              <span v-else class="empty-space" aria-label="Пустая ячейка"></span>
            </figure>
          </div>
        </div>
        <p v-if="rowNote(row.note)" class="row-note">{{ rowNote(row.note) }}</p>
      </div>
    </section>
  </article>
</template>

<style scoped>
.sequence-sheet {
  width: 100%;
  min-width: 0;
  padding: 40px 36px;
  color: #253127;
  background: #fff;
  font-size: 13px;
}
.sheet-heading {
  margin-bottom: 28px;
}
.sheet-title-line {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 20px;
}
.sheet-title-line h1 {
  min-width: 0;
  font-size: 25px;
  line-height: 1.3;
  letter-spacing: -0.5px;
  font-weight: 750;
  overflow-wrap: anywhere;
}
.sheet-theme {
  font-size: 15px;
  margin-top: 8px;
  line-height: 1.5;
  color: #52624e;
  overflow-wrap: anywhere;
}
.sequence-block + .sequence-block {
  margin-top: 24px;
}
.block-heading {
  margin-bottom: 9px;
}
.block-heading h2 {
  display: flex;
  align-items: baseline;
  gap: 8px;
  font-size: 14px;
  line-height: 1.5;
  font-weight: 700;
  overflow-wrap: anywhere;
}
.block-index {
  flex-shrink: 0;
  color: #81947a;
  font-size: 12px;
  font-weight: 500;
}
.block-heading p {
  margin-top: 3px;
  font-size: 12px;
  color: #697568;
  line-height: 1.5;
  overflow-wrap: anywhere;
}
.sequence-row-group + .sequence-row-group {
  margin-top: 10px;
}
.sequence-line {
  display: grid;
  grid-template-columns: 26px minmax(0, 1fr);
  gap: 6px;
}
.row-number {
  color: #687568;
  font-size: 11px;
  line-height: 1.3;
  padding-top: 24px;
}
.sequence-cells {
  --sequence-gap: 6px;
  --pose-height: 64px;
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  gap: var(--sequence-gap);
  align-items: stretch;
}
.sequence-cells.has-transitions {
  --sequence-gap: 16px;
}
.transition-sign {
  position: absolute;
  top: calc(var(--pose-height) / 2);
  left: calc(100% + var(--sequence-gap) / 2);
  transform: translate(-50%, -50%);
  color: #151915;
  font-size: 15px;
  line-height: 1;
  white-space: nowrap;
}
.pose-sign {
  position: absolute;
  top: 0;
  left: 5px;
  color: #151915;
  font-size: 13px;
  line-height: 1;
}
.pose-sign-right {
  right: 5px;
  left: auto;
}
.underlined::after {
  content: '';
  position: absolute;
  top: calc(var(--pose-height) + 2px);
  right: 0;
  left: 0;
  border-top: 1px solid #151915;
}
.underline-continues::after {
  right: calc(-1 * var(--sequence-gap));
}
.sequence-cell {
  position: relative;
  min-width: 0;
  margin: 0;
  text-align: center;
}
.grouped-cell {
  padding-inline: 4px;
}
.group-bracket {
  position: absolute;
  top: 3px;
  bottom: 3px;
  width: 5px;
  border-top: 1.5px solid #253127;
  border-bottom: 1.5px solid #253127;
  pointer-events: none;
}
.group-bracket-start {
  left: -1px;
  border-left: 1.5px solid #253127;
}
.group-bracket-end {
  right: -1px;
  border-right: 1.5px solid #253127;
}
.card-image-box,
.empty-space {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: var(--pose-height);
}
.card-image {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
.custom-content {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 64px;
  padding: 6px 2px;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.4;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
.sequence-cell figcaption {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding-top: 4px;
  font-size: 12px;
  line-height: 1.35;
  overflow-wrap: anywhere;
}
.asana-name {
  font-weight: 600;
}
.cell-note {
  white-space: pre-wrap;
  color: #657160;
}
.row-note {
  margin: 6px 0 0 32px;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  color: #697568;
  font-size: 11px;
  line-height: 1.5;
}
@media screen and (max-width: 760px) {
  .sequence-sheet {
    padding: 25px 16px;
    min-width: 780px;
  }
  .sheet-title-line {
    display: block;
  }
  .sheet-title-line h1 {
    font-size: 21px;
  }
  .sheet-theme {
    font-size: 13px;
  }
  .sequence-cells {
    --sequence-gap: 3px;
    --pose-height: 46px;
  }
  .sequence-cells.has-transitions {
    --sequence-gap: 12px;
  }
  .sequence-line {
    grid-template-columns: 21px minmax(0, 1fr);
    gap: 3px;
  }
  .card-image-box,
  .empty-space {
    height: var(--pose-height);
  }
  .row-number {
    font-size: 9px;
    padding-top: 18px;
  }
  .custom-content {
    min-height: 46px;
  }
  .sequence-cell figcaption {
    font-size: 12px;
  }
  .row-note {
    margin-left: 24px;
  }
}
@media print {
  .group-bracket {
    top: 0.6mm;
    bottom: 0.6mm;
    width: 1.3mm;
    border-color: #151915;
    border-width: 0.3mm;
  }
  .grouped-cell {
    padding-inline: 1mm;
  }
  .custom-content {
    min-height: 13mm;
    padding: 1mm 0;
    font-size: 8pt;
  }
  .sequence-sheet {
    width: 100%;
    padding: 0;
    color: #151915;
    background: #fff;
    font-size: 8pt;
  }
  .sheet-heading {
    margin-bottom: 5mm;
  }
  .sheet-title-line {
    display: flex;
  }
  .sheet-title-line h1 {
    font-size: 16pt;
    line-height: 1.2;
  }
  .sheet-theme {
    margin-top: 2mm;
    font-size: 10pt;
  }
  .sequence-block {
    break-inside: auto;
    page-break-inside: auto;
  }
  .sequence-block + .sequence-block {
    margin-top: 4mm;
  }
  .sequence-row-group {
    break-inside: avoid;
    page-break-inside: avoid;
  }
  .sequence-row-group + .sequence-row-group {
    margin-top: 2mm;
  }
  .block-heading {
    margin-bottom: 1.5mm;
    break-after: avoid;
    page-break-after: avoid;
  }
  .block-heading h2 {
    font-size: 9pt;
    gap: 2mm;
  }
  .block-index {
    font-size: 7pt;
  }
  .block-heading p {
    margin-top: 0.5mm;
    font-size: 7pt;
  }
  .sequence-line {
    grid-template-columns: 7mm minmax(0, 1fr);
    gap: 1mm;
    break-inside: avoid;
    page-break-inside: avoid;
  }
  .sequence-cells {
    --sequence-gap: 1.5mm;
    --pose-height: 13mm;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: var(--sequence-gap);
  }
  .sequence-cells.has-transitions {
    --sequence-gap: 2.5mm;
  }
  .transition-sign {
    font-size: 7pt;
  }
  .pose-sign {
    font-size: 7pt;
  }
  .row-number {
    font-size: 7pt;
    padding-top: 4mm;
  }
  .card-image-box,
  .empty-space {
    height: var(--pose-height);
  }
  .sequence-cell figcaption {
    padding-top: 0.5mm;
    gap: 0.5mm;
    font-size: 6.5pt;
    line-height: 1.25;
  }
  .row-note {
    margin: 1mm 0 0 8mm;
    font-size: 7pt;
    line-height: 1.3;
  }
}
</style>
