<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import { errorText, type Asana, type Category, type AsanaRelation } from '../types';
import AsanaFields from './AsanaFields.vue';
import CollectionChoices from './CollectionChoices.vue';
import RelationsEditor from './RelationsEditor.vue';
const props = defineProps<{ card: Asana | null; categories: Category[] }>();
const emit = defineEmits<{ close: []; saved: [] }>();
const name = ref(''),
  aliases = ref(''),
  error = ref('');
const categoryIds = ref<string[]>([]);
const collectionIds = ref<string[]>([]),
  relations = ref<AsanaRelation[]>([]);
const replacement = ref<File | null>(null);
const replacementUrl = ref('');
const imageError = ref('');
const busy = ref(false);
function clearReplacement() {
  if (replacementUrl.value) URL.revokeObjectURL(replacementUrl.value);
  replacement.value = null;
  replacementUrl.value = '';
  imageError.value = '';
}
function replaceImage(file: File) {
  if (busy.value) return;
  imageError.value = !['image/png', 'image/jpeg', 'image/webp'].includes(file.type)
    ? 'Поддерживаются только PNG, JPEG и WebP.'
    : file.size > 10 * 1024 * 1024
      ? 'Размер файла превышает 10 МБ.'
      : '';
  if (imageError.value) return;
  clearReplacement();
  replacement.value = file;
  replacementUrl.value = URL.createObjectURL(file);
}
watch(
  () => props.card,
  (card) => {
    clearReplacement();
    if (!card) return;
    name.value = card.name;
    aliases.value = card.aliases?.join('\n') || '';
    categoryIds.value = card.categories?.map((item) => item.id) || [];
    collectionIds.value = [...(card.collection_ids || [])];
    relations.value = JSON.parse(JSON.stringify(card.relations || []));
    error.value = '';
  },
  { immediate: true },
);
async function save() {
  if (!props.card || busy.value || imageError.value) return;
  if (!name.value.trim()) {
    error.value = 'Укажите название карточки.';
    return;
  }
  if (!categoryIds.value.length || !collectionIds.value.length) {
    error.value = 'Выберите хотя бы одну категорию и одну подборку.';
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
  data.set('category_ids', JSON.stringify(categoryIds.value));
  data.set('collection_ids', JSON.stringify(collectionIds.value));
  data.set('relations', JSON.stringify(relations.value.map(({ asana_id, kind }) => ({ asana_id, kind }))));
  data.set('expected_revision', String(props.card.revision));
  if (replacement.value) data.set('image', replacement.value);
  try {
    await api(`/api/asanas/${props.card.id}`, { method: 'POST', body: data });
    emit('saved');
    emit('close');
  } catch (failure) {
    error.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}
onBeforeUnmount(clearReplacement);
</script>
<template>
  <Modal :open="Boolean(card)" title="Изменить карточку" @close="!busy && $emit('close')">
    <form id="asana-edit-form" class="edit-form" @submit.prevent="save">
      <CollectionChoices v-model="collectionIds" :open="Boolean(card)" :disabled="busy" />
      <RelationsEditor v-if="card" v-model="relations" :asana-id="card.id" :disabled="busy" />
      <div v-if="error" class="error-banner" role="alert">{{ error }}</div>
      <div v-if="imageError" class="error-banner" role="alert">
        {{ imageError
        }}<button type="button" class="btn btn-ghost" @click="imageError = ''">
          Оставить выбранное изображение
        </button>
      </div>
      <AsanaFields
        v-model:name="name"
        v-model:aliases="aliases"
        v-model:category-ids="categoryIds"
        prefix="asana-edit"
        :categories="categories"
        :disabled="busy"
        :image-url="replacementUrl || card?.image_url || card?.thumbnail_url || ''"
        :image-name="replacement?.name"
        @image="replaceImage"
      >
        <template #image-help>
          <button
            v-if="replacement"
            type="button"
            class="btn btn-ghost"
            :disabled="busy"
            @click="clearReplacement"
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
      ><button class="btn btn-secondary" type="button" :disabled="busy" @click="$emit('close')">Отмена</button
      ><button class="btn btn-primary" form="asana-edit-form" type="submit" :disabled="busy || !!imageError">
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
