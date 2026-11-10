<script setup lang="ts">
import { dismissMenu as vDismissMenu } from '@/shared/dismissMenu';
import { TooltipProvider, TooltipRoot, TooltipTrigger, TooltipPortal, TooltipContent } from 'reka-ui';
import {
  Plus,
  GripVertical,
  MoreHorizontal,
  ArrowUp,
  ArrowDown,
  Copy,
  Save,
  StickyNote,
  Trash2,
  ArrowRight,
} from '@lucide/vue';
import { arrowLabels, cellFilled, cellGroupEdges, cellLabel, type Row, type Cell } from '../types';
import CellArrow from './CellArrow.vue';
import SecondsControl from './SecondsControl.vue';
import { useCellLongPress } from '../composables/useCellLongPress';
const props = defineProps<{
  row: Row;
  number: string;
  active: string | null;
  pending: Set<string>;
  disabled?: boolean;
  onlyRow?: boolean;
  pointerCell?: string | null;
  pointerRow?: string | null;
  groupMode?: boolean;
  groupRange?: Set<string>;
  groupInvalid?: boolean;
  timingMode?: boolean;
  defaultDuration?: number;
  defaultRest?: number;
}>();
const emit = defineEmits<{
  select: [id: string];
  hold: [id: string];
  dropCell: [event: DragEvent, id: string];
  count: [count: number];
  duplicate: [];
  remove: [];
  move: [direction: number];
  saveRow: [];
  note: [];
  relocate: [];
  dropRow: [event: DragEvent];
  timing: [id: string, field: 'duration_seconds' | 'rest_seconds', value: number | null];
}>();
const cellHold = useCellLongPress({
  enabled: () => !props.disabled && !props.groupMode,
  hold: (id) => emit('hold', id),
});
function dragCell(event: DragEvent, cell: Cell) {
  if (!cellHold.allowNativeDrag(event)) return;
  if (!cellFilled(cell) || props.disabled || props.groupMode) {
    event.preventDefault();
    return;
  }
  event.stopPropagation();
  event.dataTransfer?.setData('application/ribka', JSON.stringify({ kind: 'cell', id: cell.id }));
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
}
function dragRow(event: DragEvent) {
  if (props.disabled || props.onlyRow || props.groupMode) {
    event.preventDefault();
    return;
  }
  event.stopPropagation();
  event.dataTransfer?.setData('application/ribka', JSON.stringify({ kind: 'row', id: props.row.id }));
}
function closeMenu(event: MouseEvent) {
  (event.target as HTMLElement).closest('details')?.removeAttribute('open');
}
</script>
<template>
  <section
    class="sequence-row"
    :class="{ 'pointer-row-target': pointerRow === row.id }"
    :data-editor-row="row.id"
    @dragover.prevent
    @drop.prevent.stop="!disabled && !groupMode && !onlyRow && emit('dropRow', $event)"
  >
    <div class="row-heading">
      <div class="row-label">
        <span
          class="drag-handle"
          :draggable="!disabled && !onlyRow && !groupMode"
          @dragstart="dragRow"
          title="Переместить строку"
          ><GripVertical :size="16" /></span
        ><span>{{ number }}</span>
      </div>
      <span class="row-caption">{{ row.cells.filter(cellFilled).length }} заполнено</span>
      <div class="row-tools">
        <span class="muted">{{ row.cells.length }} / 12 ячеек</span>
        <details v-dismiss-menu v-if="!disabled && !groupMode" class="action-menu">
          <summary class="icon-btn" aria-label="Действия со строкой"><MoreHorizontal :size="19" /></summary>
          <div class="action-popover" @click="closeMenu">
            <button @click="emit('note')"><StickyNote :size="16" />Заметка</button>
            <template v-if="!onlyRow"
              ><button @click="emit('duplicate')"><Copy :size="16" />Дублировать</button
              ><button @click="emit('saveRow')"><Save :size="16" />Сохранить строку</button
              ><button @click="emit('move', -1)"><ArrowUp :size="16" />Выше</button
              ><button @click="emit('move', 1)"><ArrowDown :size="16" />Ниже</button
              ><button @click="emit('relocate')"><ArrowRight :size="16" />В другой блок</button
              ><button class="danger-text" @click="emit('remove')">
                <Trash2 :size="16" />Удалить строку
              </button></template
            >
          </div>
        </details>
      </div>
    </div>
    <TooltipProvider :delay-duration="300" ignore-non-keyboard-focus>
      <div class="cells-scroll">
        <div :class="['cells-grid', { 'timing-grid': timingMode }]">
          <div
            v-for="(cell, index) in row.cells"
            :key="cell.id"
            :class="['cell-column', { 'timing-column': timingMode }]"
          >
            <TooltipRoot :disabled="!cellFilled(cell) || pending.has(cell.id)">
              <TooltipTrigger as-child>
                <button
                  :class="[
                    'sequence-cell',
                    {
                      selected: active === cell.id,
                      filled: cellFilled(cell),
                      'has-image': !!cell.card && !pending.has(cell.id),
                      pending: pending.has(cell.id),
                      'pointer-cell-target': pointerCell === cell.id,
                      grouped: !!cell.group_key,
                      'group-range': groupMode && groupRange?.has(cell.id),
                      'group-range-invalid': groupMode && groupInvalid && groupRange?.has(cell.id),
                    },
                  ]"
                  :aria-label="`${number}, ячейка ${index + 1}: ${cellLabel(cell) || 'пустая'}${cell.arrow_direction ? ', стрелка ' + arrowLabels[cell.arrow_direction].toLowerCase() : ''}${cell.group_key ? ', в группе со скобками' : ''}${groupMode && groupRange?.has(cell.id) ? ', выбрана для объединения' : ''}`"
                  :aria-pressed="groupMode ? !!groupRange?.has(cell.id) : active === cell.id"
                  :aria-disabled="disabled"
                  :data-editor-cell="cell.id"
                  aria-keyshortcuts="Shift+Enter Shift+Space"
                  :draggable="cellFilled(cell) && !disabled && !groupMode"
                  @pointerdown="cellHold.start($event, cell.id)"
                  @keydown="cellHold.keyboard($event, cell.id)"
                  @contextmenu="cellHold.contextMenu"
                  @dragstart="dragCell($event, cell)"
                  @dragover.prevent.stop
                  @drop.prevent.stop="!disabled && !groupMode && emit('dropCell', $event, cell.id)"
                  @click="!disabled && emit('select', cell.id)"
                >
                  <span
                    v-if="cellGroupEdges(row, index).start"
                    class="group-edge group-edge-start"
                    aria-hidden="true"
                  />
                  <span
                    v-if="cellGroupEdges(row, index).end"
                    class="group-edge group-edge-end"
                    aria-hidden="true"
                  />
                  <span class="cell-index">{{ index + 1 }}</span>
                  <CellArrow v-if="cell.arrow_direction" :direction="cell.arrow_direction" />
                  <span v-if="pending.has(cell.id)" class="cell-pending">Добавляем…</span>
                  <img
                    v-else-if="cell.card"
                    :src="cell.card.image_url"
                    :alt="cell.card.name"
                    draggable="false"
                  />
                  <span v-else-if="cell.custom_name" class="custom-name">{{ cell.custom_name }}</span>
                  <template v-else
                    ><Plus :size="23" class="cell-plus" /><span class="cell-empty-text">Асана</span></template
                  >
                  <span v-if="cell.note" class="note-indicator"><StickyNote :size="11" /></span>
                </button>
              </TooltipTrigger>
              <TooltipPortal>
                <TooltipContent
                  class="sequence-cell-tooltip"
                  side="top"
                  :side-offset="8"
                  :collision-padding="8"
                  hide-when-detached
                  position-strategy="fixed"
                >
                  {{ cellLabel(cell) }}
                  <span v-if="cell.note" class="cell-tooltip-note">{{ cell.note }}</span>
                </TooltipContent>
              </TooltipPortal>
            </TooltipRoot>
            <div v-if="timingMode && cellFilled(cell) && !pending.has(cell.id)" class="cell-timing">
              <SecondsControl
                label="Асана"
                :context="`${number}, ячейка ${index + 1}`"
                :value="cell.duration_seconds ?? defaultDuration ?? 60"
                :min="1"
                :disabled="disabled || groupMode"
                resettable
                :overridden="cell.duration_seconds != null"
                @change="emit('timing', cell.id, 'duration_seconds', $event)"
                @reset="emit('timing', cell.id, 'duration_seconds', null)"
              />
              <SecondsControl
                label="Отдых после"
                :context="`${number}, ячейка ${index + 1}`"
                :value="cell.rest_seconds ?? defaultRest ?? 0"
                :disabled="disabled || groupMode"
                resettable
                :overridden="cell.rest_seconds != null"
                @change="emit('timing', cell.id, 'rest_seconds', $event)"
                @reset="emit('timing', cell.id, 'rest_seconds', null)"
              />
              <p v-if="cell.advance_mode === 'breaths'" class="timing-hint">
                Сейчас переход по дыханиям. Изменение времени включит таймер.
              </p>
            </div>
          </div>
          <button
            v-if="!disabled && !groupMode && row.cells.length < 12"
            class="append-cell"
            :aria-label="`Добавить ячейку в строку ${number}`"
            title="Добавить ячейку"
            @click="emit('count', row.cells.length + 1)"
          >
            <Plus :size="21" />
          </button>
        </div>
      </div>
    </TooltipProvider>
    <p v-if="row.note" class="row-note"><StickyNote :size="14" />{{ row.note }}</p>
  </section>
</template>
<style scoped>
.sequence-row {
  padding: 8px 0 10px;
  border-bottom: 1px solid var(--line);
}
.sequence-row:last-of-type {
  border-bottom: 0;
}
.row-heading {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 6px;
  min-height: var(--control-height);
}
.row-label {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 14px;
  font-weight: 650;
  color: var(--muted);
  min-width: 47px;
}
.drag-handle {
  cursor: grab;
  color: var(--muted);
  display: flex;
}
.row-caption {
  font-size: 12px;
  color: var(--muted);
}
.row-tools {
  display: flex;
  align-items: center;
  gap: 1px;
  margin-left: auto;
  font-size: 12px;
}
.row-tools > .muted {
  margin-right: 6px;
}
.row-tools .icon-btn {
  width: var(--control-height);
  height: var(--control-height);
}
.cells-scroll {
  overflow-x: auto;
  padding: 3px 3px 7px;
  margin: 0 -3px;
}
.cells-grid {
  display: flex;
  gap: 6px;
  align-items: flex-start;
}
.cell-column {
  display: contents;
}
.timing-column {
  display: flex;
  flex-direction: column;
  gap: 12px;
  flex: 0 0 156px;
  width: 156px;
  min-width: 0;
}
.timing-column .sequence-cell {
  flex: none;
  width: 72px;
  height: 72px;
  margin-inline: auto;
}
.cell-timing {
  display: grid;
  gap: 6px;
  min-width: 0;
}
.timing-hint {
  font-size: 11px;
  line-height: 1.4;
  color: var(--muted);
}
.sequence-cell {
  -webkit-touch-callout: none;
  -webkit-user-select: none;
  user-select: none;
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  flex: 0 0 clamp(56px, calc((100% - 66px) / 12), 72px);
  width: clamp(56px, calc((100% - 66px) / 12), 72px);
  aspect-ratio: 1;
  min-width: 0;
  overflow: hidden;
  background: var(--surface-soft);
  border: 1.5px dashed var(--line);
  border-radius: var(--field-radius);
  padding: 12px 4px 4px;
  color: var(--muted);
  text-align: center;
  transition:
    background 0.12s,
    border-color 0.12s;
}
.sequence-cell:hover {
  background: var(--accent-soft);
  border-color: var(--accent);
}
.sequence-cell.selected {
  border: 2px solid var(--accent);
  background: var(--accent-soft);
  box-shadow: 0 0 0 2px var(--line);
}
.sequence-cell.filled {
  border-style: solid;
  border-color: var(--line);
  background: white;
}
.sequence-cell.filled.selected {
  border-color: var(--accent);
  background: var(--accent-soft);
}
.sequence-cell.grouped {
  border-style: solid;
  border-color: #ecd982;
  background: #fff8d5;
}
.sequence-cell.grouped:hover {
  border-color: #dec25a;
  background: #fff2b7;
}
.sequence-cell.grouped.selected {
  border-color: #c6a12a;
  background: #fff2b7;
  box-shadow: 0 0 0 2px #f8e8a4;
}
.group-edge {
  position: absolute;
  top: -1px;
  bottom: -1px;
  width: 4px;
  background: #ffce00;
  pointer-events: none;
  z-index: 1;
}
.group-edge-start {
  left: -1px;
}
.group-edge-end {
  right: -1px;
}
.sequence-cell.group-range,
.sequence-cell.grouped.group-range {
  background: var(--accent-soft);
  border: 2px solid var(--accent);
  box-shadow: 0 0 0 2px var(--line);
}
.sequence-cell.group-range-invalid,
.sequence-cell.grouped.group-range-invalid {
  background: #fff2ec;
  border-color: #b66a4c;
  box-shadow: 0 0 0 2px #f6e4da;
}
.cell-index {
  position: absolute;
  left: 8px;
  top: 5px;
  font-size: 10px;
  color: var(--muted);
}
.cell-plus {
  color: var(--muted);
  margin-bottom: 5px;
}
.cell-empty-text {
  font-size: 12px;
}
.sequence-cell.has-image {
  padding: 4px;
}
.sequence-cell img {
  position: absolute;
  inset: 4px;
  height: calc(100% - 8px);
  width: calc(100% - 8px);
  object-fit: contain;
}
.sequence-cell.has-image .cell-index,
.sequence-cell.has-image .note-indicator {
  z-index: 2;
  background: #ffffffde;
  border-radius: 3px;
  padding: 0 2px;
  pointer-events: none;
}
/* Reka renders the portalled panel without this component's scope attribute. */
:global(.sequence-cell-tooltip) {
  z-index: 60;
  max-width: min(320px, calc(100vw - 16px));
  padding: 9px 12px;
  border: 1px solid var(--line);
  border-radius: 10px;
  background: #fff;
  color: var(--text);
  box-shadow: 0 4px 16px color-mix(in srgb, var(--text) 10%, transparent);
  font-size: 13px;
  line-height: 1.45;
  overflow-wrap: anywhere;
}
.cell-tooltip-note {
  display: block;
  margin-top: 4px;
  color: var(--muted);
  white-space: pre-wrap;
}
.custom-name {
  font-size: 12px;
  line-height: 1.25;
  color: var(--text);
  overflow-wrap: anywhere;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 3;
  overflow: hidden;
}
.note-indicator {
  position: absolute;
  right: 4px;
  top: 3px;
  color: var(--accent-dark);
}
.append-cell {
  align-self: center;
  display: grid;
  place-items: center;
  flex: 0 0 30px;
  width: 30px;
  height: 36px;
  border: 0;
  border-radius: 9px;
  color: var(--accent-dark);
  background: var(--surface-soft);
}
.append-cell:hover {
  background: var(--accent-soft);
}
.cell-pending {
  font-size: 12px;
  color: var(--accent-dark);
}
.row-note {
  display: flex;
  align-items: flex-start;
  gap: 7px;
  font-size: 13px;
  color: var(--muted);
  padding-top: 8px;
  white-space: pre-wrap;
}
.action-menu {
  position: relative;
}
.action-menu summary {
  list-style: none;
}
.action-menu summary::-webkit-details-marker {
  display: none;
}
.action-popover {
  position: absolute;
  right: 0;
  top: calc(100% + 8px);
  z-index: 15;
  background: var(--popup-surface);
  border: 1px solid var(--line);
  border-radius: var(--popup-radius);
  box-shadow: var(--popup-shadow);
  backdrop-filter: blur(20px);
  padding: 7px;
  min-width: 205px;
}
.action-popover button {
  display: flex;
  align-items: center;
  gap: 9px;
  width: 100%;
  padding: 10px 12px;
  min-height: 44px;
  border: 0;
  background: transparent;
  border-radius: 12px;
  text-align: left;
  font-size: 13px;
}
.action-popover button:hover {
  background: var(--accent-soft);
}
.danger-text {
  color: #a8442a;
}
.sequence-cell:focus-visible {
  outline-offset: 1px;
}
.sequence-cell.pending:not(.grouped) {
  background: var(--surface-soft);
}
@media screen and (max-width: 760px) {
  .cells-scroll {
    overscroll-behavior-x: contain;
  }
  .sequence-cell {
    flex-basis: 76px;
    width: 76px;
  }
  .append-cell {
    flex-basis: 44px;
    width: 44px;
    height: 44px;
  }
}
@media (max-width: 600px) {
  .row-caption {
    display: none;
  }
  .custom-name {
    font-size: 12px;
  }
  .cell-empty-text {
    font-size: 12px;
  }
}
</style>
