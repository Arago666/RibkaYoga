<script setup lang="ts">
import { ref, watch } from 'vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import type { Asana, Category } from '../types';
const props = defineProps<{ card: Asana | null; categories: Category[] }>();
const emit = defineEmits<{ close: []; saved: [] }>();
const ids = ref<string[]>([]),
  current = ref<Asana | null>(null),
  busy = ref(false),
  loading = ref(false),
  error = ref('');
let serial = 0;
watch(
  () => props.card,
  async (card) => {
    const version = ++serial;
    current.value = null;
    error.value = '';
    ids.value = [];
    loading.value = !!card;
    if (!card) return;
    try {
      const value = (await api<{ data: Asana }>(`/api/asanas/${card.id}`)).data;
      if (version === serial) {
        current.value = value;
        ids.value = value.categories?.map((c) => c.id) || [];
      }
    } catch (e) {
      if (version === serial) error.value = e instanceof Error ? e.message : 'Не удалось открыть категории.';
    } finally {
      if (version === serial) loading.value = false;
    }
  },
);
async function save() {
  if (!current.value || busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await api(`/api/asanas/${current.value.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ expected_revision: current.value.revision, category_ids: ids.value }),
    });
    emit('saved');
    emit('close');
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось сохранить категории.';
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <Modal :open="!!card" title="Добавить в категорию" @close="!busy && emit('close')">
    <p>{{ card?.name }}</p>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <p v-if="loading" role="status">Загружаем категории…</p>
    <div v-else class="category-options">
      <label v-for="category in categories" :key="category.id"
        ><input v-model="ids" type="checkbox" :value="category.id" :disabled="busy" />{{
          category.name
        }}</label
      >
      <p v-if="!categories.length" class="muted">
        Создайте личную категорию через меню библиотеки «Управление категориями».
      </p>
    </div>
    <template #footer
      ><button class="btn btn-secondary" :disabled="busy" @click="emit('close')">Отмена</button
      ><button class="btn btn-primary" :disabled="busy || loading || !current" @click="save">
        Сохранить
      </button></template
    >
  </Modal>
</template>
<style scoped>
.category-options {
  display: grid;
  gap: 12px;
  margin: 20px 0;
}
.category-options label {
  display: flex;
  min-height: var(--control-height);
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  border: 1px solid var(--line);
  border-radius: var(--field-radius);
  background: var(--glass);
  overflow-wrap: anywhere;
}
.category-options label:has(input:checked) {
  border-color: #a7be98;
  background: var(--accent-soft);
  color: var(--accent-dark);
}
.category-options input {
  flex-shrink: 0;
}
</style>
