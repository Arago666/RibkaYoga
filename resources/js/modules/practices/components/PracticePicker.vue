<script setup lang="ts">
import { ref, watch } from 'vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
export interface PracticeChoice {
  id: string;
  title: string;
  revision: number;
  kind?: string;
}
const props = withDefaults(
  defineProps<{
    open: boolean;
    title?: string;
    description?: string;
    busy?: boolean;
    templates?: boolean;
    externalError?: string;
  }>(),
  {
    title: 'Добавить практику',
    description: 'Выберите существующую последовательность. Будет создана независимая копия.',
  },
);
const emit = defineEmits<{ close: []; choose: [item: PracticeChoice] }>();
const query = ref(''),
  page = ref(1),
  lastPage = ref(1),
  items = ref<PracticeChoice[]>([]),
  loading = ref(false),
  error = ref('');
const kind = ref('practice');
let serial = 0;
async function load() {
  const version = ++serial;
  loading.value = true;
  error.value = '';
  try {
    const result = await api<{ data: PracticeChoice[]; last_page: number }>(
      `/api/practices?${new URLSearchParams({ q: query.value, kind: kind.value, archive: 'active', include_cycle_documents: '1', per_page: '15', page: String(page.value) })}`,
    );
    if (version === serial) {
      items.value = result.data;
      lastPage.value = result.last_page;
    }
  } catch (e) {
    if (version === serial) error.value = e instanceof Error ? e.message : 'Не удалось загрузить практики.';
  } finally {
    if (version === serial) loading.value = false;
  }
}
watch(
  () => props.open,
  (open) => {
    if (open) {
      query.value = '';
      page.value = 1;
      kind.value = 'practice';
      void load();
    } else serial++;
  },
);
</script>
<template>
  <Modal :open="open" :title="title" @close="!busy && emit('close')">
    <p class="muted">{{ description }}</p>
    <form
      class="practice-search"
      @submit.prevent="
        page = 1;
        load();
      "
    >
      <input
        v-model="query"
        class="input"
        placeholder="Название практики"
        aria-label="Поиск существующих практик"
      />
      <select
        v-if="templates"
        v-model="kind"
        class="select"
        aria-label="Источник практики"
        @change="
          page = 1;
          load();
        "
      >
        <option value="practice">Практики</option>
        <option value="template">Шаблоны</option>
      </select>
      <button class="btn btn-secondary" :disabled="loading || busy">Найти</button>
    </form>
    <p v-if="externalError" class="error-banner" role="alert">{{ externalError }}</p>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <p v-if="loading" role="status">Загружаем практики…</p>
    <div v-else class="practice-options">
      <button v-for="item in items" :key="item.id" :disabled="busy" @click="emit('choose', item)">
        {{ item.title }}<span>Добавить →</span>
      </button>
      <p v-if="!items.length" class="muted">Практик по этому запросу нет.</p>
    </div>
    <div v-if="lastPage > 1" class="practice-search">
      <button
        class="btn"
        :disabled="page <= 1 || loading"
        @click="
          page--;
          load();
        "
      >
        Назад</button
      ><span>{{ page }} / {{ lastPage }}</span
      ><button
        class="btn"
        :disabled="page >= lastPage || loading"
        @click="
          page++;
          load();
        "
      >
        Далее
      </button>
    </div>
    <template #footer
      ><button class="btn btn-secondary" :disabled="busy" @click="emit('close')">Закрыть</button></template
    >
  </Modal>
</template>
<style scoped>
.practice-search {
  display: flex;
  gap: 10px;
  align-items: center;
  margin: 18px 0;
  flex-wrap: wrap;
}
.practice-search .input {
  flex: 1;
  min-width: 180px;
}
.practice-search .select {
  width: auto;
}
.practice-options {
  display: grid;
  gap: 8px;
}
.practice-options button {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  width: 100%;
  background: white;
  border: 1px solid #dce6d6;
  border-radius: 12px;
  padding: 15px;
  text-align: left;
  font: inherit;
  cursor: pointer;
}
.practice-options button:hover {
  background: #f2f8ee;
}
.practice-options span {
  font-size: 12px;
  color: #547746;
  flex: none;
}
</style>
