<script setup lang="ts">
import AppSelect from '@/shared/AppSelect.vue';
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
    standalone?: boolean;
    excludeFolder?: string;
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
    const params = new URLSearchParams({
      q: query.value,
      kind: kind.value,
      archive: 'active',
      include_cycle_documents: props.standalone ? '0' : '1',
      per_page: '15',
      page: String(page.value),
    });
    if (props.excludeFolder) params.set('exclude_folder_id', props.excludeFolder);
    const result = await api<{ data: PracticeChoice[]; last_page: number }>(`/api/practices?${params}`);
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
  { immediate: true },
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
      <AppSelect
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
      </AppSelect>
      <button class="btn btn-secondary" :disabled="loading || busy">Найти</button>
    </form>
    <p v-if="externalError" class="error-banner" role="alert">{{ externalError }}</p>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <p v-if="loading" role="status">Загружаем практики…</p>
    <div v-else class="practice-options">
      <button v-for="item in items" :key="item.id" :disabled="busy" @click="emit('choose', item)">
        <span class="practice-option-title">{{ item.title }}</span
        ><span class="practice-option-action">Добавить →</span>
      </button>
      <p v-if="!items.length" class="muted">Практик по этому запросу нет.</p>
    </div>
    <div v-if="lastPage > 1" class="practice-search">
      <button
        class="btn btn-secondary"
        :disabled="page <= 1 || loading"
        @click="
          page--;
          load();
        "
      >
        Назад</button
      ><span>{{ page }} / {{ lastPage }}</span
      ><button
        class="btn btn-secondary"
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
  gap: 12px;
  align-items: center;
  margin: var(--section-gap) 0;
  flex-wrap: wrap;
}
.practice-search .input {
  flex: 1;
  min-width: min(100%, 180px);
}
.practice-search .select {
  width: auto;
  max-width: 100%;
}
.practice-options {
  display: grid;
  gap: 12px;
}
.practice-options button {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  width: 100%;
  min-width: 0;
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: var(--panel-radius);
  box-shadow: var(--panel-shadow);
  padding: var(--panel-padding);
  text-align: left;
  color: var(--text);
  font: inherit;
  cursor: pointer;
  transition:
    border-color 0.15s,
    background 0.15s;
}
.practice-options button:hover:not(:disabled) {
  border-color: var(--accent-dark);
  background: var(--accent-soft);
}
.practice-option-title {
  min-width: 0;
  font-size: 15px;
  font-weight: 600;
  line-height: 1.5;
  overflow-wrap: anywhere;
}
.practice-option-action {
  font-size: 13px;
  color: var(--accent-dark);
  flex: none;
}
@media (max-width: 440px) {
  .practice-search .input {
    flex-basis: 100%;
  }
  .practice-search .select,
  .practice-search > .btn {
    flex: 1;
  }
  .practice-options button {
    align-items: flex-start;
    flex-direction: column;
    gap: 10px;
  }
}
</style>
