<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue';
import { Images, Type, LoaderCircle, Trash2 } from '@lucide/vue';
import Modal from '@/shared/Modal.vue';
import AsanaPalette from './AsanaPalette.vue';
import type { Asana } from '@/modules/catalog/types';
import { cellFilled, type Cell } from '../types';

const props = defineProps<{
  open: boolean;
  documentId: string;
  cell: Cell | null;
  busy?: boolean;
  error?: string;
}>();
const emit = defineEmits<{
  close: [];
  select: [card: Asana];
  custom: [name: string];
  fragment: [];
  remove: [];
}>();
const hasCurrent = computed(() => !!props.cell && cellFilled(props.cell));
const currentName = computed(() => props.cell?.card?.name || props.cell?.custom_name || 'Текущая асана');
const mode = ref<'library' | 'custom'>('library');
const customName = ref('');
const nameInput = ref<HTMLInputElement | null>(null);
const formId = useId();
const inputId = useId();
watch(
  () => props.open,
  (open) => {
    if (!open) return;
    customName.value = props.cell?.custom_name || '';
    mode.value = props.cell?.custom_name ? 'custom' : 'library';
    if (mode.value === 'custom') void nextTick(() => nameInput.value?.focus());
  },
);
function chooseMode(value: 'library' | 'custom') {
  if (props.busy) return;
  mode.value = value;
  if (value === 'custom') void nextTick(() => nameInput.value?.focus());
}
function chooseCard(card: Asana) {
  emit('select', card);
}
function saveName() {
  if (!props.busy && customName.value.trim()) emit('custom', customName.value.trim());
}
</script>

<template>
  <Modal :open="open" :title="hasCurrent ? 'Изменить асану' : 'Заполнить ячейку'" @close="emit('close')">
    <section v-if="hasCurrent" class="current-asana" aria-label="Текущая асана в ячейке">
      <img v-if="cell?.card?.image_url" :src="cell.card.image_url" :alt="currentName" />
      <div v-else class="current-asana-text" aria-hidden="true"><Type :size="26" /></div>
      <div class="current-asana-caption">
        <p>В ячейке сейчас</p>
        <h2>{{ currentName }}</h2>
      </div>
    </section>
    <div class="tabs picker-modes" role="group" aria-label="Способ заполнения ячейки">
      <button
        class="tab"
        :class="{ active: mode === 'library' }"
        :aria-pressed="mode === 'library'"
        :disabled="busy"
        @click="chooseMode('library')"
      >
        <Images :size="17" /> Из библиотеки
      </button>
      <button
        class="tab"
        :class="{ active: mode === 'custom' }"
        :aria-pressed="mode === 'custom'"
        :disabled="busy"
        @click="chooseMode('custom')"
      >
        <Type :size="18" /> Своё название
      </button>
      <button class="tab" :disabled="busy" @click="emit('fragment')">Вставить фрагмент / строку</button>
    </div>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <template v-if="mode === 'library'">
      <p class="picker-description">
        Найдите асану по названию или откройте подборку. Нажмите на карточку, чтобы вставить её.
      </p>
      <p v-if="busy" class="picker-progress" role="status"><LoaderCircle :size="16" /> Добавляем карточку…</p>
      <AsanaPalette
        v-if="open"
        :document-id="documentId"
        :active="true"
        :mobile-open="false"
        :disabled="busy"
        embedded
        @select="chooseCard"
      />
    </template>
    <form v-else :id="formId" class="custom-form" @submit.prevent="saveName">
      <p class="picker-description">
        Введите название асаны, перехода или другого действия. Оно появится в ячейке и на печатном листе без
        изображения.
      </p>
      <div class="field">
        <label class="label" :for="inputId">Название</label
        ><input
          :id="inputId"
          ref="nameInput"
          v-model="customName"
          class="input"
          required
          maxlength="160"
          placeholder="Например, отдых или дыхательная практика"
          :disabled="busy"
        /><span class="custom-counter">{{ customName.length }} / 160</span>
      </div>
      <div v-if="customName.trim()" class="custom-preview" aria-label="Предпросмотр названия">
        {{ customName.trim() }}
      </div>
    </form>
    <template #footer>
      <button
        v-if="hasCurrent"
        class="btn btn-danger picker-remove"
        :disabled="busy"
        aria-label="Удалить асану из ячейки"
        @click="emit('remove')"
      >
        <Trash2 :size="17" /> Удалить
      </button>
      <button class="btn btn-secondary" @click="emit('close')">
        {{ busy ? 'Отменить вставку' : 'Закрыть' }}
      </button>
      <button
        v-if="mode === 'custom'"
        class="btn btn-primary"
        type="submit"
        :form="formId"
        :disabled="busy || !customName.trim()"
      >
        Вставить название
      </button>
    </template>
  </Modal>
</template>

<style scoped>
.current-asana {
  display: flex;
  align-items: center;
  gap: 18px;
  padding: 16px;
  margin-bottom: var(--section-gap);
  border: 1px solid var(--line);
  border-radius: 18px;
  background: var(--surface-soft);
}
.current-asana > img,
.current-asana-text {
  width: 88px;
  height: 88px;
  flex: 0 0 88px;
  border-radius: 12px;
  background: #fff;
}
.current-asana > img {
  object-fit: contain;
  padding: 6px;
}
.current-asana-text {
  display: grid;
  place-items: center;
  color: var(--muted);
}
.current-asana-caption {
  min-width: 0;
}
.current-asana-caption p {
  color: var(--muted);
  font-size: 12px;
  line-height: 1.5;
  margin-bottom: 6px;
}
.current-asana-caption h2 {
  font-size: 17px;
  line-height: 1.4;
  font-weight: 650;
  overflow-wrap: anywhere;
}
.picker-remove {
  margin-right: auto;
}
.picker-modes {
  gap: 16px;
  margin-bottom: var(--section-gap);
}
.picker-description {
  color: var(--muted);
  font-size: 14px;
  line-height: 1.6;
  margin-bottom: var(--section-gap);
}
.picker-progress {
  display: flex;
  align-items: center;
  gap: 10px;
  color: var(--accent-dark);
  background: var(--accent-soft);
  padding: 14px 16px;
  border-radius: var(--field-radius);
  margin-bottom: 20px;
  font-size: 14px;
}
.custom-form {
  display: grid;
  gap: var(--section-gap);
}
.custom-form .picker-description {
  margin-bottom: 0;
}
.custom-counter {
  display: block;
  color: var(--muted);
  font-size: 12px;
  text-align: right;
  margin-top: 8px;
}
.custom-preview {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 104px;
  padding: var(--panel-padding);
  border: 1px solid var(--line);
  border-radius: var(--panel-radius);
  background: var(--surface);
  box-shadow: var(--panel-shadow);
  color: var(--text);
  font-weight: 600;
  overflow-wrap: anywhere;
  text-align: center;
}
@media (max-width: 500px) {
  .picker-modes {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0 12px;
    overflow: visible;
  }
  .picker-modes .tab {
    min-height: var(--control-height);
    justify-content: center;
    text-align: center;
    white-space: normal;
    min-width: 0;
    font-size: 13px;
    gap: 6px;
  }
  .picker-modes .tab:last-child {
    grid-column: 1 / -1;
  }
}
</style>
