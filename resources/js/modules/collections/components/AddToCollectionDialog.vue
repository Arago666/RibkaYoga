<script setup lang="ts">
import { ref, watch } from 'vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import { errorText, type Collection, type Page } from '../../catalog/types';
const props = defineProps<{ open: boolean; asanaIds: string[] }>();
const emit = defineEmits<{ close: []; saved: [name: string] }>();
const mode = ref<'new' | 'existing'>('new'),
  name = ref(''),
  description = ref(''),
  collectionId = ref(''),
  error = ref('');
const collections = ref<Collection[]>([]),
  page = ref(0),
  lastPage = ref(1),
  loading = ref(false),
  busy = ref(false);
async function loadMore() {
  if (loading.value || page.value >= lastPage.value) return;
  loading.value = true;
  try {
    const response = await api<Page<Collection>>(`/api/collections?archive=active&page=${page.value + 1}`);
    collections.value.push(...response.data);
    page.value = response.current_page;
    lastPage.value = response.last_page;
  } catch (failure) {
    error.value = errorText(failure);
  } finally {
    loading.value = false;
  }
}
watch(
  () => props.open,
  (open) => {
    if (!open) return;
    mode.value = 'new';
    name.value = '';
    description.value = '';
    collectionId.value = '';
    error.value = '';
    collections.value = [];
    page.value = 0;
    lastPage.value = 1;
    void loadMore();
  },
);
async function save() {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    let savedName = name.value.trim();
    if (mode.value === 'new')
      await api('/api/collections', {
        method: 'POST',
        body: JSON.stringify({
          name: savedName,
          description: description.value || null,
          asana_ids: props.asanaIds,
        }),
      });
    else {
      const { data: current } = await api<{ data: Collection }>(`/api/collections/${collectionId.value}`);
      await api(`/api/collections/${collectionId.value}`, {
        method: 'PATCH',
        body: JSON.stringify({
          name: current.name,
          description: current.description,
          expected_revision: current.revision,
          asana_ids: [...new Set([...current.asana_ids, ...props.asanaIds])],
        }),
      });
      savedName = current.name;
    }
    emit('saved', savedName);
    emit('close');
  } catch (failure) {
    error.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <Modal :open="open" title="Добавить в подборку" @close="!busy && $emit('close')">
    <p class="muted">
      Выбрано карточек: {{ asanaIds.length }}. Их можно использовать в любом порядке при составлении практики.
    </p>
    <div class="tabs">
      <button class="tab" :class="{ active: mode === 'new' }" :disabled="busy" @click="mode = 'new'">
        Новая подборка</button
      ><button
        class="tab"
        :class="{ active: mode === 'existing' }"
        :disabled="busy"
        @click="mode = 'existing'"
      >
        Существующая
      </button>
    </div>
    <form id="add-to-collection" class="collection-form" @submit.prevent="save">
      <div v-if="error" class="error-banner" role="alert">{{ error }}</div>
      <template v-if="mode === 'new'"
        ><div class="field">
          <label class="label" for="new-collection-name">Название подборки</label
          ><input
            id="new-collection-name"
            v-model="name"
            class="input"
            required
            maxlength="160"
            placeholder="Например, мобильность плеч"
            :disabled="busy"
          />
        </div>
        <div class="field">
          <label class="label" for="new-collection-description">Описание · необязательно</label
          ><textarea
            id="new-collection-description"
            v-model="description"
            class="textarea"
            rows="3"
            maxlength="3000"
            :disabled="busy"
          /></div
      ></template>
      <template v-else
        ><label class="label" for="existing-collection">Подборка</label
        ><select id="existing-collection" v-model="collectionId" class="select" required :disabled="busy">
          <option value="" disabled>Выберите подборку</option>
          <option v-for="collection in collections" :key="collection.id" :value="collection.id">
            {{ collection.name }}
          </option>
        </select>
        <p v-if="!loading && !collections.length" class="muted">Подборок пока нет. Создайте новую.</p>
        <button
          v-if="page < lastPage"
          class="btn btn-ghost"
          type="button"
          :disabled="loading"
          @click="loadMore"
        >
          {{ loading ? 'Загружаем…' : 'Загрузить ещё подборки' }}
        </button></template
      >
    </form>
    <template #footer
      ><button class="btn btn-secondary" :disabled="busy" @click="$emit('close')">Отмена</button
      ><button
        class="btn btn-primary"
        form="add-to-collection"
        type="submit"
        :disabled="busy || !asanaIds.length || (mode === 'existing' && !collectionId)"
      >
        {{ busy ? 'Сохраняем…' : 'Добавить в подборку' }}
      </button></template
    >
  </Modal>
</template>
<style scoped>
.collection-form {
  display: grid;
  gap: 16px;
  margin-top: 20px;
}
</style>
