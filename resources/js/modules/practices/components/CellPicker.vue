<script setup lang="ts">
import { nextTick, ref, useId, watch } from 'vue';
import { Images, Type, LoaderCircle } from '@lucide/vue';
import Modal from '@/shared/Modal.vue';
import AsanaPalette from './AsanaPalette.vue';
import type { Asana } from '@/modules/catalog/types';
import type { Cell } from '../types';
import IllustrationSettings, {
  type PreparedIllustration,
} from '@/modules/catalog/components/IllustrationSettings.vue';

const props = defineProps<{
  open: boolean;
  documentId: string;
  cell: Cell | null;
  busy?: boolean;
  error?: string;
}>();
const emit = defineEmits<{ close: []; select: [card: Asana]; custom: [name: string] }>();
const mode = ref<'library' | 'custom' | 'illustration'>('library');
const styleCard = ref<Asana | null>(null),
  preparedStyle = ref<PreparedIllustration | null>(null);
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
    styleCard.value = null;
    preparedStyle.value = null;
    if (mode.value === 'custom') void nextTick(() => nameInput.value?.focus());
  },
);
function chooseMode(value: 'library' | 'custom' | 'illustration') {
  if (props.busy) return;
  mode.value = value;
  if (value === 'custom') void nextTick(() => nameInput.value?.focus());
}
function chooseCard(card: Asana) {
  if (mode.value === 'illustration') {
    styleCard.value = card;
    preparedStyle.value = null;
  } else emit('select', card);
}
function insertStyle() {
  if (styleCard.value && preparedStyle.value && !props.busy)
    emit('select', { ...styleCard.value, illustration_variant_id: preparedStyle.value.variant_id ?? null });
}
function saveName() {
  if (!props.busy && customName.value.trim()) emit('custom', customName.value.trim());
}
</script>

<template>
  <Modal :open="open" title="Заполнить ячейку" @close="emit('close')">
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
      <button
        class="tab"
        :class="{ active: mode === 'illustration' }"
        :aria-pressed="mode === 'illustration'"
        :disabled="busy"
        @click="chooseMode('illustration')"
      >
        Стиль и персонаж
      </button>
    </div>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <template v-if="mode === 'library' || mode === 'illustration'">
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
      <template v-if="mode === 'illustration' && styleCard"
        ><h3>{{ styleCard.name }}</h3>
        <IllustrationSettings
          :key="styleCard.id"
          :asana-id="styleCard.id"
          :source-asset-id="styleCard.image_asset_id"
          :image-url="styleCard.image_url"
          compact
          @select="preparedStyle = $event"
          @reset="preparedStyle = null"
      /></template>
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
      <button
        v-if="mode === 'illustration'"
        class="btn btn-primary"
        :disabled="busy || !preparedStyle"
        @click="insertStyle"
      >
        Вставить выбранное представление
      </button>
    </template>
  </Modal>
</template>

<style scoped>
.picker-modes {
  gap: 25px;
  margin-bottom: 18px;
}
.picker-description {
  color: #687568;
  font-size: 14px;
  line-height: 1.6;
  margin-bottom: 17px;
}
.picker-progress {
  display: flex;
  align-items: center;
  gap: 8px;
  color: #397a28;
  background: #edf7e8;
  padding: 10px 13px;
  border-radius: 10px;
  margin-bottom: 14px;
  font-size: 14px;
}
.custom-form {
  display: grid;
  gap: 15px;
}
.custom-form .picker-description {
  margin-bottom: 0;
}
.custom-counter {
  display: block;
  color: #839078;
  font-size: 12px;
  text-align: right;
  margin-top: 6px;
}
.custom-preview {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 88px;
  padding: 20px;
  border: 1px solid #d9e5d2;
  border-radius: 13px;
  background: #f6faf3;
  color: #3d5732;
  font-weight: 600;
  overflow-wrap: anywhere;
  text-align: center;
}
@media (max-width: 440px) {
  .picker-modes {
    gap: 14px;
  }
  .picker-modes .tab {
    font-size: 13px;
    gap: 6px;
  }
}
</style>
