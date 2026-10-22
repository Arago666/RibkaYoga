<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Crop, Pencil } from '@lucide/vue';
import type { Category } from '../types';
import type { DrawingDocument } from '../drawing';
import {
  emptyAssignments,
  emptyReference,
  type FacetAssignments,
  type ReferenceFields as ReferenceData,
} from '../facets';
import ReferenceFields from './ReferenceFields.vue';
import ImageEditor from './ImageEditor.vue';
import AsanaDrawing from './AsanaDrawing.vue';
const props = defineProps<{
  categories: Category[];
  prefix: string;
  disabled?: boolean;
  imageUrl: string;
  imageName?: string;
  imageRequired?: boolean;
  drawingSource?: DrawingDocument | null;
  draftKey?: string;
}>();
const reference = defineModel<ReferenceData>('reference', { default: emptyReference });
const facetAssignments = defineModel<FacetAssignments>('facetAssignments', { default: emptyAssignments });
const name = defineModel<string>('name', { required: true });
const aliases = defineModel<string>('aliases', { required: true });
const categoryIds = defineModel<string[]>('categoryIds', { required: true });
const emit = defineEmits<{ image: [file: File, drawing: DrawingDocument | null]; dirty: [value: boolean] }>();
const tool = ref<'edit' | 'draw' | null>(null);
const drawingOpened = ref(false);
const drawingDirty = ref(false);
interface DrawingHandle {
  hasUnappliedChanges(): boolean;
  discardDraft(): void;
  apply(): Promise<boolean>;
}
const drawingEditor = ref<DrawingHandle | null>(null);
const page = usePage();
const scopedDraftKey = computed(() => {
  const auth = page.props.auth as { user?: { id: number }; workspace?: { id: string } } | undefined;
  return `ribka-drawing:${auth?.user?.id ?? 'guest'}:${auth?.workspace?.id ?? 'none'}:${props.draftKey || props.prefix}`;
});
const taskContext = computed(() => ({
  name: name.value,
  aliases: aliases.value
    .split('\n')
    .map((value) => value.trim())
    .filter(Boolean),
  category_ids: categoryIds.value,
}));
async function useImage(file: File) {
  if (props.disabled) return;
  const valid =
    ['image/png', 'image/jpeg', 'image/webp'].includes(file.type) && file.size <= 10 * 1024 * 1024;
  if (!valid) {
    emit('image', file, null);
    return;
  }
  if (
    hasUnsavedDrawing() &&
    !window.confirm(
      'Использовать готовое изображение вместо рисунка? Черновик рисунка останется в этом браузере.',
    )
  )
    return;
  // Flush the old drawing under its existing draft key before its source changes.
  // A retained hidden editor must never reapply that drawing over the chosen photo.
  await resetDrawing();
  emit('image', file, null);
}
function useDrawing(file: File, drawing: DrawingDocument) {
  // The editor still has its own busy flag while delivering this event.
  if (props.disabled) return;
  emit('image', file, drawing);
  tool.value = null;
}
function markDrawingDirty(value: boolean) {
  drawingDirty.value = value;
  emit('dirty', value);
}
function hasUnsavedDrawing(): boolean {
  return drawingEditor.value?.hasUnappliedChanges() ?? drawingDirty.value;
}
async function guardBeforeSave(): Promise<boolean> {
  if (!hasUnsavedDrawing()) return true;
  const applied = (await drawingEditor.value?.apply()) ?? false;
  if (!applied) tool.value = 'draw';
  return applied;
}
function saved() {
  drawingEditor.value?.discardDraft();
  markDrawingDirty(false);
}
async function resetDrawing() {
  drawingOpened.value = false;
  tool.value = null;
  await nextTick();
  markDrawingDirty(false);
}
defineExpose({ guardBeforeSave, hasUnsavedDrawing, saved, resetDrawing });
function openDrawing() {
  drawingOpened.value = true;
  tool.value = tool.value === 'draw' ? null : 'draw';
}
function chooseImage(event: Event) {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (file) useImage(file);
  input.value = '';
}
</script>

<template>
  <div class="asana-fields">
    <div class="field">
      <label class="label" :for="`${prefix}-name`">Название</label>
      <input
        :id="`${prefix}-name`"
        v-model="name"
        class="input"
        required
        maxlength="160"
        :disabled="disabled"
      />
    </div>
    <div class="field">
      <label class="label" :for="`${prefix}-aliases`">Другие названия</label>
      <textarea
        :id="`${prefix}-aliases`"
        v-model="aliases"
        class="textarea"
        rows="2"
        placeholder="Каждое название — с новой строки"
        :disabled="disabled"
      />
    </div>
    <fieldset class="choice-group">
      <legend class="label">Мои категории</legend>
      <div v-if="categories.length" class="choices">
        <label v-for="category in categories" :key="category.id">
          <input v-model="categoryIds" type="checkbox" :value="category.id" :disabled="disabled" />{{
            category.name
          }}
        </label>
      </div>
      <p v-else class="muted">Категории можно создать в разделе «Категории».</p>
    </fieldset>
    <ReferenceFields
      v-model:reference="reference"
      v-model:assignments="facetAssignments"
      :disabled="disabled"
      :prefix="prefix"
    />
    <div class="image-field">
      <img v-if="imageUrl" :src="imageUrl" :alt="name || 'Предпросмотр карточки'" />
      <div class="field">
        <label class="label" :for="`${prefix}-image`"
          >Изображение{{ imageRequired ? ' (обязательно)' : '' }}</label
        >
        <input
          :id="`${prefix}-image`"
          type="file"
          accept="image/png,image/jpeg,image/webp"
          :disabled="disabled"
          @change="chooseImage"
        />
        <p v-if="imageName" class="image-name">{{ imageName }}</p>
        <p class="image-help muted">PNG, JPEG или WebP · до 10 МБ. Можно выбрать другое изображение.</p>
        <slot name="image-help" />
      </div>
    </div>
    <div class="image-tools">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="disabled || !imageUrl"
        :aria-expanded="tool === 'edit'"
        @click="tool = tool === 'edit' ? null : 'edit'"
      >
        <Crop :size="16" />Редактировать изображение
      </button>
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="disabled"
        :aria-expanded="tool === 'draw'"
        @click="openDrawing"
      >
        <Pencil :size="16" />{{ drawingSource ? 'Редактировать рисунок' : 'Нарисовать асану' }}
      </button>
    </div>
    <ImageEditor
      v-if="tool === 'edit' && imageUrl"
      :source="imageUrl"
      :context="taskContext"
      :disabled="disabled"
      @image="useImage"
      @close="tool = null"
    />
    <AsanaDrawing
      v-if="drawingOpened"
      v-show="tool === 'draw'"
      ref="drawingEditor"
      :context="taskContext"
      :disabled="disabled"
      :initial-drawing="drawingSource"
      :draft-key="scopedDraftKey"
      :active="tool === 'draw'"
      @image="useDrawing"
      @dirty="markDrawingDirty"
      @close="tool = null"
    />
  </div>
</template>

<style scoped>
.asana-fields {
  display: grid;
  gap: 18px;
  min-width: 0;
}
.choice-group {
  border: 0;
  padding: 0;
  margin: 0;
  min-width: 0;
}
.choices {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 8px;
  max-height: 160px;
  overflow: auto;
}
.choices label {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 8px 10px;
  background: #f7f9f6;
  border-radius: 9px;
  font-size: 0.85rem;
}
.choices input {
  accent-color: #397a28;
}
.image-field {
  display: flex;
  gap: 16px;
  align-items: center;
  padding: 14px;
  background: #f7f9f6;
  border-radius: 14px;
}
.image-field img {
  width: 80px;
  height: 90px;
  object-fit: contain;
  flex: none;
}
.image-field .field {
  min-width: 0;
}
.image-field input {
  max-width: 100%;
  font-size: 0.8rem;
}
.image-help,
.image-name {
  font-size: 0.78rem;
  line-height: 1.5;
  margin: 0;
}
.image-name {
  overflow-wrap: anywhere;
}
.image-tools {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
@media (max-width: 480px) {
  .image-field {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>
