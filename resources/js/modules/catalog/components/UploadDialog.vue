<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { Upload, CheckCircle2, AlertCircle, X, LoaderCircle, ChevronDown, ChevronUp } from '@lucide/vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import { uid } from '@/shared/uuid';
import { errorText, type Category } from '../types';
import type { DrawingDocument } from '../drawing';
import AsanaFields from './AsanaFields.vue';
import CollectionChoices from './CollectionChoices.vue';
const props = defineProps<{ open: boolean; categories: Category[] }>();
const emit = defineEmits<{ close: []; uploaded: [] }>();
// Массовая загрузка временно скрыта по запросу владельца.
const batchUploadEnabled = false;
interface UploadItem {
  id: string;
  file: File | null;
  url: string;
  name: string;
  aliases: string;
  categoryIds: string[];
  collectionIds: string[];
  expanded: boolean;
  state: 'ready' | 'uploading' | 'done' | 'error';
  error: string;
  invalid: boolean;
  drawing: DrawingDocument | null;
  drawingDirty: boolean;
}
function emptyItem(): UploadItem {
  return {
    id: uid(),
    file: null,
    url: '',
    name: '',
    aliases: '',
    categoryIds: [],
    collectionIds: [],
    expanded: true,
    state: 'ready',
    error: '',
    invalid: false,
    drawing: null,
    drawingDirty: false,
  };
}
const items = ref<UploadItem[]>([emptyItem()]);
const input = ref<HTMLInputElement | null>(null);
const busy = ref(false);
const preparing = ref(false);
const fieldRefs = new Map<string, InstanceType<typeof AsanaFields>>();
function setFields(id: string, component: unknown) {
  if (component) fieldRefs.set(id, component as InstanceType<typeof AsanaFields>);
  else fieldRefs.delete(id);
}
const error = ref('');
const commonCategory = ref('');
const dragging = ref(false);
const succeeded = computed(() => items.value.filter((item) => item.state === 'done').length);
const pending = computed(() =>
  items.value.filter(
    (item) => ['ready', 'error'].includes(item.state) && ((item.file && !item.invalid) || item.drawingDirty),
  ),
);
function replaceImage(item: UploadItem, file: File, drawing: DrawingDocument | null = null) {
  if (busy.value || item.state === 'done') return;
  let issue = '';
  if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type))
    issue = 'Поддерживаются только PNG, JPEG и WebP.';
  else if (file.size > 10 * 1024 * 1024) issue = 'Размер файла превышает 10 МБ.';
  if (item.url) URL.revokeObjectURL(item.url);
  item.file = file;
  item.drawing = issue ? null : drawing;
  item.url = issue ? '' : URL.createObjectURL(file);
  if (!item.name.trim())
    item.name =
      file.name
        .replace(/\.[^.]+$/, '')
        .replace(/[_-]+/g, ' ')
        .trim()
        .slice(0, 160) || 'Новая асана';
  item.state = issue ? 'error' : 'ready';
  item.error = issue;
  item.invalid = Boolean(issue);
  if (issue) item.expanded = true;
}
function addFiles(files: FileList | File[] | null) {
  if (!files || busy.value) return;
  error.value = '';
  const available = Math.max(0, 30 - items.value.filter((item) => item.file).length);
  if (files.length > available)
    error.value = 'За один раз можно добавить до 30 изображений. Остальные загрузите следующей партией.';
  for (const file of Array.from(files).slice(0, available)) {
    let item = items.value.find((entry) => !entry.file && entry.state !== 'done');
    if (!item) {
      item = emptyItem();
      item.categoryIds = commonCategory.value ? [commonCategory.value] : [];
      item.expanded = false;
      items.value.push(item);
    }
    replaceImage(item, file);
  }
  if (input.value) input.value.value = '';
}
function removeItem(item: UploadItem) {
  if (busy.value) return;
  if (item.url) URL.revokeObjectURL(item.url);
  items.value = items.value.filter((entry) => entry.id !== item.id);
  if (!items.value.length) items.value = [emptyItem()];
}
function applyCategory() {
  if (!commonCategory.value || busy.value) return;
  for (const item of items.value)
    if (item.state !== 'done') item.categoryIds = [...new Set([...item.categoryIds, commonCategory.value])];
}
function onDrop(event: DragEvent) {
  dragging.value = false;
  addFiles(event.dataTransfer?.files || null);
}
async function upload() {
  if (busy.value || preparing.value || !pending.value.length) return;
  preparing.value = true;
  try {
    for (const item of items.value) {
      if (item.state === 'done') continue;
      const fields = fieldRefs.get(item.id);
      if (fields && !(await fields.guardBeforeSave())) {
        item.expanded = true;
        return;
      }
    }
  } finally {
    preparing.value = false;
  }
  if (!pending.value.length) return;
  busy.value = true;
  error.value = '';
  const queue = [...pending.value];
  let cursor = 0;
  async function worker() {
    while (cursor < queue.length) {
      const item = queue[cursor++];
      if (!item?.file) continue;
      if (!item.name.trim()) {
        item.state = 'error';
        item.error = 'Укажите название карточки.';
        item.expanded = true;
        continue;
      }
      if (!item.categoryIds.length || !item.collectionIds.length) {
        item.state = 'error';
        item.error = 'Выберите хотя бы одну категорию и одну подборку.';
        item.expanded = true;
        continue;
      }
      item.state = 'uploading';
      item.error = '';
      const data = new FormData();
      data.set('image', item.file);
      data.set('drawing_source', JSON.stringify(item.drawing));
      data.set('name', item.name.trim());
      data.set(
        'aliases',
        JSON.stringify(
          item.aliases
            .split('\n')
            .map((alias) => alias.trim())
            .filter(Boolean),
        ),
      );
      data.set('category_ids', JSON.stringify(item.categoryIds));
      data.set('collection_ids', JSON.stringify(item.collectionIds));
      try {
        await api('/api/asanas', { method: 'POST', body: data });
        if (item.drawing) fieldRefs.get(item.id)?.saved();
        item.drawingDirty = false;
        item.state = 'done';
        if (items.value.length > 1) item.expanded = false;
      } catch (failure) {
        item.state = 'error';
        item.error = errorText(failure);
        item.expanded = true;
      }
    }
  }
  try {
    await Promise.all([worker(), worker(), worker()]);
  } finally {
    busy.value = false;
    if (succeeded.value) emit('uploaded');
  }
}
function close() {
  if (busy.value || preparing.value) return;
  if (
    items.value.some(
      (item) =>
        item.state !== 'done' &&
        (item.drawingDirty ||
          fieldRefs.get(item.id)?.hasUnsavedDrawing() ||
          item.file ||
          item.name.trim() ||
          item.aliases.trim() ||
          item.categoryIds.length ||
          item.collectionIds.length),
    ) &&
    !window.confirm(
      'Закрыть создание? Незагруженные карточки и поля будут сброшены, черновик рисунка сохранится в этом браузере.',
    )
  )
    return;
  for (const item of items.value) if (item.url) URL.revokeObjectURL(item.url);
  items.value = [emptyItem()];
  error.value = '';
  commonCategory.value = '';
  dragging.value = false;
  emit('close');
}
onBeforeUnmount(() => {
  for (const item of items.value) if (item.url) URL.revokeObjectURL(item.url);
});
</script>

<template>
  <Modal :open="open" :title="items.length === 1 ? 'Добавить асану' : 'Добавить асаны'" @close="close">
    <p class="intro muted">
      Заполните карточку и выберите готовое изображение. Другие названия помогут находить её в поиске.
    </p>
    <div v-if="error" class="error-banner" role="alert">{{ error }}</div>
    <div v-if="items.length > 1 && categories.length" class="batch-category">
      <label class="label" for="batch-category">Добавить категорию всем незагруженным карточкам</label>
      <div class="batch-category-controls">
        <select id="batch-category" v-model="commonCategory" class="select" :disabled="busy">
          <option value="">Выберите категорию</option>
          <option v-for="category in categories" :key="category.id" :value="category.id">
            {{ category.name }}
          </option>
        </select>
        <button
          class="btn btn-secondary"
          type="button"
          :disabled="busy || !commonCategory"
          @click="applyCategory"
        >
          Применить
        </button>
      </div>
    </div>
    <div class="upload-list">
      <article
        v-for="(item, index) in items"
        :key="item.id"
        class="upload-item"
        :class="{ 'batch-item': items.length > 1 }"
      >
        <header v-if="items.length > 1" class="upload-item-header">
          <img v-if="item.url" :src="item.url" :alt="item.name || 'Предпросмотр карточки'" />
          <div v-else class="invalid-image"><AlertCircle :size="24" /></div>
          <button
            class="item-toggle"
            type="button"
            :aria-expanded="item.expanded"
            :aria-controls="`upload-fields-${item.id}`"
            @click="item.expanded = !item.expanded"
          >
            <span
              ><strong>{{ index + 1 }}. {{ item.name || 'Новая асана' }}</strong
              ><small>{{ item.file?.name }}</small></span
            >
            <component :is="item.expanded ? ChevronUp : ChevronDown" :size="19" />
          </button>
          <button
            v-if="item.state !== 'done'"
            class="icon-btn"
            type="button"
            :disabled="busy"
            :aria-label="`Убрать ${item.name || item.file?.name || 'карточку'}`"
            @click="removeItem(item)"
          >
            <X :size="17" />
          </button>
        </header>
        <div v-if="item.state === 'done'" class="upload-status good" role="status">
          <CheckCircle2 :size="16" /> Карточка загружена
        </div>
        <div v-else-if="item.state === 'uploading'" class="upload-status" role="status">
          <LoaderCircle :size="16" class="spin" /> Загружаем…
        </div>
        <div v-if="item.error" class="error-banner" role="alert">{{ item.error }}</div>
        <AsanaFields
          :ref="(component) => setFields(item.id, component)"
          v-show="items.length === 1 || item.expanded"
          :id="`upload-fields-${item.id}`"
          v-model:name="item.name"
          v-model:aliases="item.aliases"
          v-model:category-ids="item.categoryIds"
          :prefix="`asana-create-${item.id}`"
          :categories="categories"
          :disabled="busy || item.state === 'done'"
          :image-url="item.url"
          :image-name="item.file?.name"
          :drawing-source="item.drawing"
          :draft-key="index === 0 ? 'create:primary' : `create:${item.id}`"
          image-required
          @image="(file, drawing) => replaceImage(item, file, drawing)"
          @dirty="item.drawingDirty = $event"
        />
        <CollectionChoices
          v-model="item.collectionIds"
          :open="open"
          :disabled="busy || item.state === 'done'"
        />
      </article>
    </div>
    <input
      ref="input"
      type="file"
      accept="image/png,image/jpeg,image/webp"
      multiple
      hidden
      @change="addFiles(($event.target as HTMLInputElement).files)"
    />
    <button
      v-if="batchUploadEnabled"
      type="button"
      class="upload-drop"
      :class="{ dragging }"
      :disabled="busy || items.length >= 30"
      @click="input?.click()"
      @dragover.prevent="dragging = true"
      @dragleave.prevent="dragging = false"
      @drop.prevent="onDrop"
    >
      <Upload :size="22" aria-hidden="true" />
      <span
        ><strong>Добавить несколько изображений</strong
        ><small>Выберите файлы или перетащите сюда · до 30 файлов</small></span
      >
    </button>
    <p v-if="succeeded" class="success-banner">
      Добавлено карточек: {{ succeeded }}. Для остальных можно исправить поля и повторить загрузку — готовые
      карточки повторно не отправляются.
    </p>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="busy || preparing" @click="close">
        {{ succeeded && !pending.length ? 'Готово' : 'Отмена' }}
      </button>
      <button
        class="btn btn-primary"
        type="button"
        :disabled="busy || preparing || !pending.length"
        @click="upload"
      >
        <Upload :size="17" />{{
          busy ? 'Загружаем…' : items.length === 1 ? 'Создать карточку' : `Загрузить (${pending.length})`
        }}
      </button>
    </template>
  </Modal>
</template>
<style scoped>
.upload-collections {
  border: 0;
  padding: 0;
  margin: 0;
  min-width: 0;
}
.collection-options {
  display: grid;
  gap: 8px;
  max-height: 200px;
  overflow-y: auto;
  margin-top: 10px;
}
.collection-options label {
  overflow-wrap: anywhere;
}
.intro {
  margin: 0 0 20px;
  line-height: 1.5;
}
.upload-drop {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 16px;
  margin: 22px 0 0;
  border: 2px dashed #cddfc7;
  border-radius: 14px;
  background: #f8fbf6;
  color: #397a28;
  cursor: pointer;
  text-align: left;
}
.upload-drop span {
  display: grid;
  gap: 5px;
}
.upload-drop strong {
  font-size: 0.9rem;
}
.upload-drop small {
  font-size: 0.78rem;
  color: #687568;
  line-height: 1.4;
}
.upload-drop.dragging {
  background: #edf7e8;
  border-color: #397a28;
}
.upload-drop:disabled {
  opacity: 0.55;
  cursor: default;
}
.batch-category {
  margin: 0 0 20px;
}
.batch-category-controls {
  display: flex;
  gap: 8px;
  margin-top: 8px;
}
.batch-category-controls .select {
  flex: 1;
  min-width: 0;
}
.upload-list {
  display: grid;
  gap: 14px;
}
.upload-item {
  display: grid;
  gap: 14px;
  min-width: 0;
}
.batch-item {
  padding: 14px;
  border: 1px solid #e2e8df;
  border-radius: 14px;
}
.upload-item-header {
  display: flex;
  gap: 12px;
  align-items: center;
}
.upload-item-header > img,
.invalid-image {
  width: 54px;
  height: 60px;
  object-fit: contain;
  border-radius: 10px;
  background: #f7f9f6;
  padding: 5px;
  flex: none;
}
.invalid-image {
  display: grid;
  place-items: center;
  color: #b55a41;
}
.item-toggle {
  display: flex;
  flex: 1;
  gap: 8px;
  align-items: center;
  min-width: 0;
  padding: 4px 0;
  border: 0;
  background: transparent;
  text-align: left;
  color: #344e2b;
}
.item-toggle > span {
  flex: 1;
  min-width: 0;
}
.item-toggle strong {
  display: block;
  font-size: 0.92rem;
  overflow-wrap: anywhere;
}
.item-toggle small {
  display: block;
  font-size: 0.76rem;
  margin-top: 5px;
  color: #687568;
  overflow-wrap: anywhere;
}
.item-toggle > svg {
  flex: none;
}
.upload-status {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.82rem;
}
.good {
  color: #397a28;
}
.spin {
  animation: rotate 1s linear infinite;
}
@keyframes rotate {
  to {
    transform: rotate(360deg);
  }
}
@media (prefers-reduced-motion: reduce) {
  .spin {
    animation: none;
  }
}
@media (max-width: 500px) {
  .upload-item-header {
    gap: 8px;
  }
  .batch-item {
    padding: 10px;
  }
  .upload-item-header > img,
  .invalid-image {
    width: 42px;
    height: 50px;
  }
  .batch-category-controls {
    flex-direction: column;
  }
}
</style>
