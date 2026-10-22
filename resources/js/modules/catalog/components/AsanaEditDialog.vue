<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import { errorText, type Asana, type Category, type AsanaRelation } from '../types';
import { emptyAssignments, emptyReference, referenceFromCard, appendReference } from '../facets';
import type { DrawingDocument } from '../drawing';
import AsanaFields from './AsanaFields.vue';
import CollectionChoices from './CollectionChoices.vue';
import RelationsEditor from './RelationsEditor.vue';
const props = defineProps<{ card: Asana | null; categories: Category[] }>();
const emit = defineEmits<{ close: []; saved: [] }>();
const name = ref(''),
  aliases = ref(''),
  error = ref('');
const reference = ref(emptyReference()),
  facetAssignments = ref(emptyAssignments());
const categoryIds = ref<string[]>([]);
const collectionIds = ref<string[]>([]),
  relations = ref<AsanaRelation[]>([]);
const replacement = ref<File | null>(null);
const replacementDrawing = ref<DrawingDocument | null>(null);
const replacementUrl = ref('');
const imageError = ref('');
const busy = ref(false);
const preparing = ref(false);
const loading = ref(false);
const currentCard = ref<Asana | null>(null);
const fields = ref<InstanceType<typeof AsanaFields> | null>(null);
const drawingDirty = ref(false);
const baseline = ref('');
let loadVersion = 0;
function formState(): string {
  return JSON.stringify({
    reference: reference.value,
    facetAssignments: facetAssignments.value,
    name: name.value,
    aliases: aliases.value,
    categoryIds: categoryIds.value,
    collectionIds: collectionIds.value,
    relations: relations.value,
  });
}
const changed = computed(
  () => Boolean(replacement.value) || drawingDirty.value || formState() !== baseline.value,
);
const drawingSource = computed(() =>
  replacement.value ? replacementDrawing.value : (currentCard.value?.drawing_source ?? null),
);
function clearReplacement() {
  if (replacementUrl.value) URL.revokeObjectURL(replacementUrl.value);
  replacement.value = null;
  replacementDrawing.value = null;
  replacementUrl.value = '';
  imageError.value = '';
}
function replaceImage(file: File, drawing: DrawingDocument | null = null) {
  if (busy.value) return;
  imageError.value = !['image/png', 'image/jpeg', 'image/webp'].includes(file.type)
    ? 'Поддерживаются только PNG, JPEG и WebP.'
    : file.size > 10 * 1024 * 1024
      ? 'Размер файла превышает 10 МБ.'
      : '';
  if (imageError.value) return;
  clearReplacement();
  replacement.value = file;
  replacementDrawing.value = drawing;
  replacementUrl.value = URL.createObjectURL(file);
}
async function cancelReplacement() {
  if (busy.value || preparing.value) return;
  if (
    fields.value?.hasUnsavedDrawing() &&
    !window.confirm('Отменить замену изображения? Черновик рисунка останется в этом браузере.')
  )
    return;
  await fields.value?.resetDrawing();
  clearReplacement();
}
watch(
  () => props.card,
  async (card) => {
    const version = ++loadVersion;
    clearReplacement();
    currentCard.value = card;
    drawingDirty.value = false;
    error.value = '';
    loading.value = false;
    if (!card) return;
    function populate(value: Asana) {
      currentCard.value = value;
      name.value = value.name;
      reference.value = referenceFromCard(value);
      facetAssignments.value = JSON.parse(JSON.stringify(value.facet_assignments ?? emptyAssignments()));
      aliases.value = value.aliases?.join('\n') || '';
      categoryIds.value = value.categories?.map((item) => item.id) || [];
      collectionIds.value = [...(value.collection_ids || [])];
      relations.value = JSON.parse(JSON.stringify(value.relations || []));
      baseline.value = formState();
    }
    populate(card);
    loading.value = true;
    try {
      const response = await api<{ data: Asana }>(`/api/asanas/${card.id}`);
      if (version === loadVersion) populate(response.data);
    } catch (failure) {
      if (version === loadVersion) {
        currentCard.value = null;
        error.value = errorText(failure);
      }
    } finally {
      if (version === loadVersion) loading.value = false;
    }
  },
  { immediate: true },
);
async function save() {
  if (!props.card || busy.value || preparing.value || loading.value || imageError.value) return;
  const card = currentCard.value;
  if (!card) return;
  preparing.value = true;
  try {
    if (!(await fields.value?.guardBeforeSave())) return;
  } finally {
    preparing.value = false;
  }
  if (!name.value.trim()) {
    error.value = 'Укажите название карточки.';
    return;
  }
  if (!collectionIds.value.length) {
    error.value = 'Выберите хотя бы одну подборку.';
    return;
  }
  busy.value = true;
  error.value = '';
  const data = new FormData();
  data.set('_method', 'PATCH');
  data.set('name', name.value.trim());
  data.set(
    'aliases',
    JSON.stringify(
      aliases.value
        .split('\n')
        .map((item) => item.trim())
        .filter(Boolean),
    ),
  );
  appendReference(data, reference.value, facetAssignments.value);
  data.set('category_ids', JSON.stringify(categoryIds.value));
  data.set('collection_ids', JSON.stringify(collectionIds.value));
  data.set('relations', JSON.stringify(relations.value.map(({ asana_id, kind }) => ({ asana_id, kind }))));
  data.set('expected_revision', String(card.revision));
  if (replacement.value) {
    data.set('image', replacement.value);
    data.set('drawing_source', JSON.stringify(replacementDrawing.value));
  }
  try {
    await api(`/api/asanas/${card.id}`, { method: 'POST', body: data });
    if (replacement.value && replacementDrawing.value) fields.value?.saved();
    emit('saved');
    emit('close');
  } catch (failure) {
    error.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}
function close() {
  if (busy.value || preparing.value) return;
  if (
    (changed.value || fields.value?.hasUnsavedDrawing()) &&
    !window.confirm(
      'Закрыть карточку без сохранения? Поля будут сброшены, черновик рисунка сохранится в этом браузере.',
    )
  )
    return;
  emit('close');
}
onBeforeUnmount(clearReplacement);
</script>
<template>
  <Modal :open="Boolean(card)" title="Изменить карточку" @close="close">
    <form id="asana-edit-form" class="edit-form" @submit.prevent="save">
      <p v-if="loading" class="muted" role="status">Загружаем карточку…</p>
      <CollectionChoices
        v-model="collectionIds"
        :open="Boolean(card)"
        :disabled="busy || loading || !currentCard"
      />
      <RelationsEditor
        v-if="card"
        v-model="relations"
        :asana-id="card.id"
        :disabled="busy || loading || !currentCard"
      />
      <div v-if="error" class="error-banner" role="alert">{{ error }}</div>
      <div v-if="imageError" class="error-banner" role="alert">
        {{ imageError
        }}<button type="button" class="btn btn-ghost" @click="imageError = ''">
          Оставить выбранное изображение
        </button>
      </div>
      <AsanaFields
        :key="card?.id"
        ref="fields"
        v-model:reference="reference"
        v-model:facet-assignments="facetAssignments"
        v-model:name="name"
        v-model:aliases="aliases"
        v-model:category-ids="categoryIds"
        prefix="asana-edit"
        :categories="categories"
        :disabled="busy || loading || !currentCard"
        :image-url="replacementUrl || currentCard?.image_url || currentCard?.thumbnail_url || ''"
        :image-name="replacement?.name"
        :drawing-source="drawingSource"
        :draft-key="`card:${card?.id}`"
        @image="replaceImage"
        @dirty="drawingDirty = $event"
      >
        <template #image-help>
          <button
            v-if="replacement"
            type="button"
            class="btn btn-ghost"
            :disabled="busy"
            @click="cancelReplacement"
          >
            Отменить замену
          </button>
          <p class="muted replacement-help">
            Ранее сохранённые практики сохранят прежнее изображение и название.
          </p>
        </template>
      </AsanaFields>
    </form>
    <template #footer
      ><button class="btn btn-secondary" type="button" :disabled="busy || preparing" @click="close">
        Отмена</button
      ><button
        class="btn btn-primary"
        form="asana-edit-form"
        type="submit"
        :disabled="busy || preparing || loading || !currentCard || !!imageError"
      >
        {{ busy ? 'Сохраняем…' : 'Сохранить' }}
      </button></template
    >
  </Modal>
</template>
<style scoped>
.edit-form {
  display: grid;
  gap: 18px;
}
.replacement-help {
  font-size: 0.78rem;
  line-height: 1.5;
  margin-bottom: 0;
}
</style>
