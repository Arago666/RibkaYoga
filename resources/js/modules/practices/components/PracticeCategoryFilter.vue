<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { SlidersHorizontal } from '@lucide/vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';

const props = defineProps<{ modelValue: string[] }>();
const emit = defineEmits<{ 'update:modelValue': [ids: string[]] }>();
const categories = ref<{ id: string; name: string }[]>([]);
const draft = ref<string[]>([]);
const open = ref(false);
const loading = ref(false);
const error = ref('');
const limit = 100;
let request: AbortController | undefined;

async function loadCategories() {
  request?.abort();
  const controller = new AbortController();
  request = controller;
  loading.value = true;
  error.value = '';

  try {
    const { data } = await api<{ data: typeof categories.value }>('/api/practice-folders', {
      signal: controller.signal,
    });
    if (controller.signal.aborted) return;
    categories.value = data;
    const active = new Set(data.map((category) => category.id));
    draft.value = draft.value.filter((id) => active.has(id));
  } catch (failure) {
    if (!controller.signal.aborted) {
      error.value = failure instanceof Error ? failure.message : 'Не удалось загрузить категории.';
    }
  } finally {
    if (!controller.signal.aborted) loading.value = false;
  }
}

function begin() {
  draft.value = [...props.modelValue];
  open.value = true;
  void loadCategories();
}

function close() {
  request?.abort();
  open.value = false;
}

function apply() {
  emit('update:modelValue', [...draft.value]);
  close();
}

onBeforeUnmount(() => request?.abort());
</script>

<template>
  <button
    type="button"
    class="btn btn-secondary category-filter"
    :class="{ 'filter-active': modelValue.length }"
    aria-haspopup="dialog"
    @click="begin"
  >
    <SlidersHorizontal :size="17" aria-hidden="true" /> Категории
    <span v-if="modelValue.length" class="filter-count">{{ modelValue.length }}</span>
  </button>
  <Modal :open="open" title="Категории практик" @close="close">
    <p v-if="loading" class="muted" role="status">Загружаем категории…</p>
    <p v-else-if="error" class="error-banner" role="alert">
      {{ error }}
      <button type="button" class="btn btn-ghost" @click="loadCategories">Повторить</button>
    </p>
    <template v-else-if="categories.length">
      <p class="muted filter-hint">
        Выберите одну или несколько категорий. В списке будут практики из любой выбранной категории.
      </p>
      <fieldset class="category-choices">
        <legend class="label">Мои категории</legend>
        <label v-for="category in categories" :key="category.id" class="category-choice">
          <input
            v-model="draft"
            type="checkbox"
            :value="category.id"
            :disabled="draft.length >= limit && !draft.includes(category.id)"
          />
          <span>{{ category.name }}</span>
        </label>
      </fieldset>
      <p v-if="draft.length >= limit" class="muted">Можно выбрать до {{ limit }} категорий.</p>
    </template>
    <template v-else>
      <p class="muted">Здесь появятся ваши категории для разовых практик.</p>
      <Link class="btn btn-secondary" href="/practice-folders">Открыть раздел категорий</Link>
    </template>
    <template #footer>
      <button type="button" class="btn btn-ghost" :disabled="!draft.length" @click="draft = []">
        Сбросить
      </button>
      <button type="button" class="btn btn-secondary" @click="close">Отмена</button>
      <button
        type="button"
        class="btn btn-primary"
        :disabled="loading || (Boolean(error) && draft.length > 0)"
        @click="apply"
      >
        Показать практики
      </button>
    </template>
  </Modal>
</template>

<style scoped>
.category-filter {
  flex: none;
}
.category-filter.filter-active {
  background: var(--accent-soft);
  color: var(--accent-dark);
}
.filter-count {
  min-width: 23px;
  padding: 2px 5px;
  border-radius: 999px;
  background: var(--surface);
  font-size: 12px;
  text-align: center;
}
.filter-hint {
  margin: 0 0 16px;
}
.category-choices {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  min-width: 0;
  margin: 0;
  padding: 0;
  border: 0;
}
.category-choices legend {
  margin-bottom: 10px;
}
.category-choice {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  max-width: 100%;
  min-height: var(--control-height);
  padding: 8px 14px;
  border: 1px solid var(--line);
  border-radius: 999px;
  background: var(--glass);
  cursor: pointer;
  font-size: 14px;
  overflow-wrap: anywhere;
}
.category-choice:has(:checked) {
  border-color: var(--accent);
  background: var(--accent-soft);
}
.category-choice:has(:disabled) {
  opacity: 0.6;
  cursor: default;
}
</style>
