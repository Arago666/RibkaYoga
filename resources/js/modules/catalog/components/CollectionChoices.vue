<script setup lang="ts">
import { ref, watch } from 'vue';
import { api } from '@/shared/api';
import { errorText, type Collection, type Page } from '../types';
const props = defineProps<{ open?: boolean; disabled?: boolean }>();
const ids = defineModel<string[]>({ required: true });
const choices = ref<Collection[]>([]),
  loading = ref(false),
  error = ref(''),
  name = ref(''),
  creating = ref(false);
let serial = 0;
async function load() {
  const token = ++serial;
  loading.value = true;
  error.value = '';
  try {
    const items: Collection[] = [];
    for (let page = 1; ; page++) {
      const result = await api<Page<Collection>>(`/api/collections?archive=active&per_page=100&page=${page}`);
      if (token !== serial) return;
      items.push(...result.data);
      if (page >= result.last_page) break;
    }
    choices.value = items;
  } catch (e) {
    error.value = errorText(e);
  } finally {
    if (token === serial) loading.value = false;
  }
}
watch(
  () => props.open,
  (value) => {
    if (value !== false) void load();
  },
  { immediate: true },
);
async function create() {
  if (!name.value.trim() || creating.value) return;
  creating.value = true;
  error.value = '';
  try {
    const result = await api<{ data: Collection }>('/api/collections', {
      method: 'POST',
      body: JSON.stringify({ name: name.value.trim(), asana_ids: [] }),
    });
    choices.value.push(result.data);
    ids.value = [...ids.value, result.data.id];
    name.value = '';
  } catch (e) {
    error.value = errorText(e);
  } finally {
    creating.value = false;
  }
}
</script>
<template>
  <fieldset class="collection-choices" :disabled="disabled || loading || creating">
    <legend class="label">Подборки · выберите хотя бы одну</legend>
    <p v-if="loading" class="muted">Загружаем подборки…</p>
    <div class="choices-list">
      <label v-for="item in choices" :key="item.id"
        ><input v-model="ids" type="checkbox" :value="item.id" />{{
          item.folder_path ? item.folder_path + ' / ' : ''
        }}{{ item.name }}</label
      >
    </div>
    <div class="create-collection">
      <input
        v-model="name"
        class="input"
        placeholder="Название новой подборки"
        maxlength="160"
        aria-label="Новая подборка"
        @keydown.enter.prevent="create"
      /><button type="button" class="btn btn-secondary" :disabled="!name.trim()" @click="create">
        Создать
      </button>
    </div>
    <p v-if="error" class="error-banner" role="alert">
      {{ error }}<button type="button" class="btn btn-ghost" @click="load">Повторить</button>
    </p>
  </fieldset>
</template>
<style scoped>
.collection-choices {
  border: 0;
  padding: 0;
  margin: 18px 0;
}
.choices-list {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  max-height: 180px;
  overflow: auto;
  margin: 10px 0;
}
.choices-list label {
  display: flex;
  align-items: center;
  gap: 7px;
  background: #f7f9f6;
  padding: 9px;
  border-radius: 9px;
  font-size: 13px;
}
.create-collection {
  display: flex;
  gap: 8px;
}
.create-collection input {
  min-width: 0;
  flex: 1;
}
</style>
