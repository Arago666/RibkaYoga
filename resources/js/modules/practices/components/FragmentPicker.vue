<script setup lang="ts">
import { ref, watch } from 'vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import type { Practice, RowTemplate } from '../types';
export type FragmentPlacement = 'rows' | 'blocks' | 'after' | 'replace';
export interface FragmentSource {
  source_type: 'document' | 'row';
  document?: Practice;
  row?: RowTemplate;
}
interface Item {
  id: string;
  title: string;
  source_type: 'document' | 'row';
  subtype: string;
}
const props = defineProps<{ open: boolean; hasCell: boolean }>();
const emit = defineEmits<{ close: []; insert: [source: FragmentSource, placement: FragmentPlacement] }>();
const query = ref(''),
  page = ref(1),
  lastPage = ref(1),
  items = ref<Item[]>([]),
  busy = ref(false),
  error = ref(''),
  placement = ref<FragmentPlacement>('rows');
let request = 0;
async function load() {
  const version = ++request;
  busy.value = true;
  error.value = '';
  try {
    const result = await api<{ data: Item[]; last_page: number }>(
      `/api/templates?${new URLSearchParams({ type: 'fragment', q: query.value, page: String(page.value), per_page: '12' })}`,
    );
    if (version === request) {
      items.value = result.data;
      lastPage.value = result.last_page;
    }
  } catch (e) {
    if (version === request) error.value = e instanceof Error ? e.message : 'Не удалось загрузить фрагменты.';
  } finally {
    if (version === request) busy.value = false;
  }
}
watch(
  () => props.open,
  (open) => {
    if (open) {
      query.value = '';
      page.value = 1;
      placement.value = 'rows';
      void load();
    } else request++;
  },
);
async function choose(item: Item) {
  busy.value = true;
  error.value = '';
  try {
    if (item.source_type === 'row') {
      const result = await api<{ data: RowTemplate }>(`/api/row-templates/${item.id}`);
      if (result.data.archived_at) throw new Error('Фрагмент находится в архиве.');
      emit('insert', { source_type: 'row', row: result.data }, placement.value);
    } else {
      const result = await api<{ data: Practice }>(`/api/practices/${item.id}`);
      if (result.data.archived_at) throw new Error('Фрагмент находится в архиве.');
      emit('insert', { source_type: 'document', document: result.data }, placement.value);
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось открыть фрагмент.';
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <Modal :open="open" title="Вставить фрагмент" @close="!busy && emit('close')"
    ><p class="muted">
      Вставка создаёт независимую копию. Вариант «Заменить» сохраняет стрелки и скобки целевых позиций.
    </p>
    <label class="field"
      ><span class="label">Способ вставки</span
      ><select v-model="placement" class="select">
        <option value="rows">Добавить строками</option>
        <option value="blocks">Добавить блоками</option>
        <option value="after" :disabled="!hasCell">Добавить после выбранной ячейки</option>
        <option value="replace" :disabled="!hasCell">Заменить позиции от выбранной ячейки</option>
      </select></label
    >
    <form
      class="fragment-search"
      @submit.prevent="
        page = 1;
        load();
      "
    >
      <input
        v-model="query"
        class="input"
        placeholder="Название фрагмента"
        aria-label="Поиск фрагментов"
      /><button class="btn btn-secondary" :disabled="busy">Найти</button>
    </form>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <button
      v-for="item in items"
      :key="`${item.source_type}:${item.id}`"
      class="fragment-option"
      :disabled="busy"
      @click="choose(item)"
    >
      <strong>{{ item.title }}</strong
      ><small>{{
        item.subtype === 'block'
          ? 'Блок'
          : item.subtype === 'transition'
            ? 'Связка / переход'
            : 'Строка / последовательность'
      }}</small>
    </button>
    <p v-if="!busy && !items.length" class="muted">
      Фрагментов пока нет. Сохраните строку, выделенные позиции или блок в редакторе.
    </p>
    <div v-if="lastPage > 1" class="fragment-search">
      <button
        class="btn btn-secondary"
        :disabled="busy || page <= 1"
        @click="
          page--;
          load();
        "
      >
        Назад</button
      ><span>{{ page }} / {{ lastPage }}</span
      ><button
        class="btn btn-secondary"
        :disabled="busy || page >= lastPage"
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
    ></Modal
  >
</template>
<style scoped>
.fragment-search {
  display: flex;
  gap: 10px;
  margin: 16px 0;
  align-items: center;
}
.fragment-option {
  display: flex;
  flex-direction: column;
  gap: 4px;
  text-align: left;
  width: 100%;
  border: 1px solid #dce6d6;
  border-radius: 10px;
  padding: 13px;
  margin: 7px 0;
  background: white;
}
.fragment-option small {
  color: var(--muted);
}
</style>
