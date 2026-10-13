<script setup lang="ts">
import { ref, watch } from 'vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import { errorText } from '../../catalog/types';
import type { CollectionFolder } from '../types';
import CollectionFolderSelect from './CollectionFolderSelect.vue';
const props = defineProps<{
  open: boolean;
  folder: CollectionFolder | null;
  folders: CollectionFolder[];
  parentId: string | null;
}>();
const emit = defineEmits<{ close: []; saved: [folder: CollectionFolder] }>();
const name = ref(''),
  parentId = ref(''),
  busy = ref(false),
  error = ref('');
watch(
  () => props.open,
  (open) => {
    if (!open) return;
    name.value = props.folder?.name || '';
    parentId.value = props.folder?.parent_id || props.parentId || '';
    error.value = '';
  },
);
async function save() {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    const response = await api<{ data: CollectionFolder }>(
      props.folder ? `/api/collection-folders/${props.folder.id}` : '/api/collection-folders',
      {
        method: props.folder ? 'PATCH' : 'POST',
        body: JSON.stringify({
          name: name.value.trim(),
          parent_id: parentId.value || null,
          ...(props.folder ? { expected_revision: props.folder.revision } : {}),
        }),
      },
    );
    emit('saved', response.data);
    emit('close');
  } catch (failure) {
    error.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <Modal :open="open" :title="folder ? 'Изменить папку' : 'Новая папка'" @close="!busy && $emit('close')">
    <form id="collection-folder-editor" class="folder-form" @submit.prevent="save">
      <div v-if="error" class="error-banner" role="alert">{{ error }}</div>
      <div class="field">
        <label class="label" for="folder-name">Название</label>
        <input
          id="folder-name"
          v-model="name"
          class="input"
          required
          maxlength="160"
          :disabled="busy"
          placeholder="Например, 2026, Октябрь или Неделя 1"
        />
      </div>
      <div class="field">
        <label class="label" for="folder-parent">Внутри папки</label>
        <CollectionFolderSelect
          id="folder-parent"
          v-model="parentId"
          :folders="folders"
          :disabled="busy"
          :omit-subtree-of="folder?.id"
          root-label="На верхнем уровне"
        />
      </div>
      <p class="muted">
        Создайте свои циклы: год → месяц → неделя. Названия и порядок вложенности можно выбрать свободно.
      </p>
    </form>
    <template #footer
      ><button class="btn btn-secondary" :disabled="busy" @click="$emit('close')">Отмена</button>
      <button
        class="btn btn-primary"
        type="submit"
        form="collection-folder-editor"
        :disabled="busy || !name.trim()"
      >
        {{ busy ? 'Сохраняем…' : 'Сохранить папку' }}
      </button></template
    >
  </Modal>
</template>
<style scoped>
.folder-form {
  display: grid;
  gap: 18px;
}
.folder-form p {
  font-size: 0.85rem;
  line-height: 1.5;
  margin: 0;
}
</style>
