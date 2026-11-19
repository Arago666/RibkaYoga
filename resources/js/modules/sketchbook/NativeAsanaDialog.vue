<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from 'vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import AsanaFields from '@/modules/catalog/components/AsanaFields.vue';
import CollectionChoices from '@/modules/catalog/components/CollectionChoices.vue';
import { emptyAssignments, emptyReference, appendReference } from '@/modules/catalog/facets';
import type { Category } from '@/modules/catalog/types';
import type { DrawingDocument } from '@/modules/catalog/drawing';
const props = defineProps<{ file: File; drawing: DrawingDocument }>();
const emit = defineEmits<{ close: []; saved: [] }>();
const name = ref(''),
  aliases = ref(''),
  categoryIds = ref<string[]>([]),
  collectionIds = ref<string[]>([]),
  categories = ref<Category[]>([]),
  reference = ref(emptyReference()),
  facets = ref(emptyAssignments()),
  error = ref(''),
  busy = ref(false),
  preparing = ref(false),
  fields = ref<InstanceType<typeof AsanaFields> | null>(null),
  file = ref(props.file),
  drawing = ref<DrawingDocument | null>(props.drawing);
const url = ref(URL.createObjectURL(props.file));
function replace(image: File, source: DrawingDocument | null) {
  URL.revokeObjectURL(url.value);
  url.value = URL.createObjectURL(image);
  file.value = image;
  drawing.value = source;
}
async function save() {
  if (busy.value || preparing.value || !name.value.trim() || !collectionIds.value.length) return;
  preparing.value = true;
  try {
    if (fields.value && !(await fields.value.guardBeforeSave())) return;
  } finally {
    preparing.value = false;
  }
  busy.value = true;
  error.value = '';
  try {
    const form = new FormData();
    form.set('name', name.value.trim());
    form.set('aliases', JSON.stringify(aliases.value.split('\n').filter(Boolean)));
    form.set('category_ids', JSON.stringify(categoryIds.value));
    form.set('collection_ids', JSON.stringify(collectionIds.value));
    form.set('image', file.value);
    if (drawing.value) form.set('drawing_source', JSON.stringify(drawing.value));
    appendReference(form, reference.value, facets.value);
    await api('/api/asanas', { method: 'POST', body: form });
    if (drawing.value) fields.value?.saved();
    emit('saved');
  } catch (f) {
    error.value = f instanceof Error ? f.message : 'Не удалось создать карточку.';
  } finally {
    busy.value = false;
  }
}
onMounted(async () => {
  try {
    categories.value = (await api<{ data: Category[] }>('/api/categories')).data;
  } catch (f) {
    error.value = f instanceof Error ? f.message : 'Ошибка загрузки категорий';
  }
});
onBeforeUnmount(() => URL.revokeObjectURL(url.value));
</script>
<template>
  <Modal open title="Создать асану из рисунка" @close="!busy && !preparing && emit('close')"
    ><p>
      Карточка попадёт в библиотеку. Для обычной вставки рисунка в занятие добавлять её сюда не обязательно.
    </p>
    <p v-if="error" role="alert">{{ error }}</p>
    <AsanaFields
      ref="fields"
      v-model:name="name"
      v-model:aliases="aliases"
      v-model:category-ids="categoryIds"
      v-model:reference="reference"
      v-model:facet-assignments="facets"
      prefix="sketch-new-asana"
      :categories="categories"
      :image-url="url"
      :drawing-source="drawing"
      :disabled="busy"
      @image="replace"
    />
    <CollectionChoices v-model="collectionIds" :disabled="busy" />
    <template #footer
      ><button class="btn btn-secondary" :disabled="busy || preparing" @click="emit('close')">Отмена</button
      ><button
        class="btn btn-primary"
        :disabled="busy || preparing || !name.trim() || !collectionIds.length"
        @click="save"
      >
        Создать карточку
      </button></template
    ></Modal
  >
</template>
