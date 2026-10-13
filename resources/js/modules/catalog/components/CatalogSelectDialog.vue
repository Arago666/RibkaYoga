<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { Search, Check, ChevronLeft, ChevronRight } from '@lucide/vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import { errorText, type Asana, type Page, type Category } from '../types';
import CategoryQuickFilters from './CategoryQuickFilters.vue';
const categories = ref<Category[]>([]),
  category = ref('');
const props = withDefaults(defineProps<{ open: boolean; existingIds?: string[]; existingLabel?: string }>(), {
  existingIds: () => [],
  existingLabel: 'Уже в подборке',
});
const emit = defineEmits<{ close: []; selected: [cards: Asana[]] }>();
const query = ref(''),
  page = ref(1),
  result = ref<Page<Asana> | null>(null),
  busy = ref(false),
  error = ref('');
const selection = ref(new Map<string, Asana>());
let sequence = 0;
let timer: ReturnType<typeof setTimeout> | undefined;
async function load() {
  const request = ++sequence;
  busy.value = true;
  error.value = '';
  try {
    const response = await api<Page<Asana>>(
      `/api/asanas?${new URLSearchParams({ q: query.value, category: category.value, page: String(page.value), archive: 'active', sort: 'name_asc' })}`,
    );
    if (request === sequence) result.value = response;
  } catch (failure) {
    if (request === sequence) error.value = errorText(failure);
  } finally {
    if (request === sequence) busy.value = false;
  }
}
watch(
  () => props.open,
  (open) => {
    if (open) {
      selection.value = new Map();
      query.value = '';
      page.value = 1;
      void load();
      void api<{ data: Category[] }>('/api/categories')
        .then((result) => (categories.value = result.data))
        .catch(() => {});
    } else sequence++;
  },
);
watch(query, () => {
  clearTimeout(timer);
  timer = setTimeout(() => {
    page.value = 1;
    void load();
  }, 250);
});
watch(category, () => {
  page.value = 1;
  void load();
});
function toggle(card: Asana) {
  const next = new Map(selection.value);
  next.has(card.id) ? next.delete(card.id) : next.set(card.id, card);
  selection.value = next;
}
function go(value: number) {
  page.value = value;
  void load();
}
function choose() {
  emit('selected', [...selection.value.values()]);
  emit('close');
}
onBeforeUnmount(() => {
  clearTimeout(timer);
  sequence++;
});
</script>
<template>
  <Modal :open="open" title="Выбрать асаны" @close="$emit('close')">
    <CategoryQuickFilters v-model="category" :categories="categories" />
    <div class="picker-search">
      <Search :size="18" /><input
        v-model="query"
        class="input"
        placeholder="Найти по названию"
        aria-label="Поиск асан для подборки"
      />
    </div>
    <div v-if="error" class="error-banner" role="alert">
      {{ error }} <button class="btn btn-ghost" @click="load">Повторить</button>
    </div>
    <p v-if="busy" class="muted" role="status">Загружаем карточки…</p>
    <div v-else class="picker-grid">
      <button
        v-for="card in result?.data || []"
        :key="card.id"
        type="button"
        class="picker-card"
        :class="{ selected: selection.has(card.id), existing: existingIds.includes(card.id) }"
        :disabled="existingIds.includes(card.id)"
        :aria-pressed="selection.has(card.id) || existingIds.includes(card.id)"
        @click="toggle(card)"
      >
        <span v-if="selection.has(card.id) || existingIds.includes(card.id)" class="picker-check"
          ><Check :size="15" /></span
        ><img :src="card.thumbnail_url || card.image_url" :alt="card.name" loading="lazy" /><span>{{
          card.name
        }}</span
        ><small v-if="existingIds.includes(card.id)">{{ existingLabel }}</small>
      </button>
    </div>
    <p v-if="!busy && result && !result.total" class="empty-state">
      {{ query ? 'По вашему запросу ничего не найдено.' : 'Сначала добавьте изображения в библиотеку.' }}
    </p>
    <div v-if="result && result.last_page > 1" class="picker-pagination">
      <button
        class="icon-btn"
        :disabled="busy || page <= 1"
        aria-label="Предыдущая страница"
        @click="go(page - 1)"
      >
        <ChevronLeft :size="20" /></button
      ><span>{{ result.current_page }} из {{ result.last_page }}</span
      ><button
        class="icon-btn"
        :disabled="busy || page >= result.last_page"
        aria-label="Следующая страница"
        @click="go(page + 1)"
      >
        <ChevronRight :size="20" />
      </button>
    </div>
    <template #footer
      ><span class="muted picker-count">Выбрано: {{ selection.size }}</span
      ><button class="btn btn-secondary" @click="$emit('close')">Отмена</button
      ><button class="btn btn-primary" :disabled="!selection.size" @click="choose">
        Добавить {{ selection.size || '' }}
      </button></template
    >
  </Modal>
</template>
<style scoped>
.picker-search {
  position: relative;
  margin-bottom: 18px;
}
.picker-search > svg {
  position: absolute;
  left: 14px;
  top: 14px;
  color: #687568;
}
.picker-search .input {
  padding-left: 42px;
  width: 100%;
}
.picker-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
  max-height: 48vh;
  overflow: auto;
  padding: 2px;
}
.picker-card {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 7px;
  border: 1px solid #e2e8df;
  background: #fff;
  border-radius: 13px;
  padding: 12px;
  cursor: pointer;
  text-align: center;
  font: inherit;
  align-items: center;
  min-width: 0;
}
.picker-card img {
  width: 100%;
  height: 84px;
  object-fit: contain;
}
.picker-card > span:not(.picker-check) {
  font-size: 0.78rem;
  line-height: 1.4;
  overflow-wrap: anywhere;
}
.picker-card.selected {
  border-color: #397a28;
  background: #f3f9ef;
}
.picker-card.existing {
  opacity: 0.55;
  cursor: default;
}
.picker-card small {
  font-size: 0.67rem;
  color: #687568;
}
.picker-check {
  position: absolute;
  top: 7px;
  left: 7px;
  display: grid;
  place-items: center;
  width: 21px;
  height: 21px;
  border-radius: 7px;
  background: #397a28;
  color: white;
}
.picker-pagination {
  display: flex;
  gap: 12px;
  align-items: center;
  justify-content: center;
  margin-top: 16px;
  font-size: 0.82rem;
  color: #687568;
}
.picker-count {
  margin-right: auto;
  font-size: 0.8rem;
}
@media (max-width: 450px) {
  .picker-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
