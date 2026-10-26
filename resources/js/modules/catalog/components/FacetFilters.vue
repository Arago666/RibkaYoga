<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { SlidersHorizontal } from '@lucide/vue';
import type { Category } from '../types';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import { errorText } from '../types';
import {
  dimensions,
  emptyFilters,
  setFilterParams,
  type CatalogFilters,
  type FacetDictionary,
} from '../facets';
import FacetChoices from './FacetChoices.vue';
const props = withDefaults(
  defineProps<{ context?: Record<string, string>; compact?: boolean; hideTrigger?: boolean }>(),
  {
    context: () => ({}),
  },
);
const model = defineModel<CatalogFilters>({ required: true });
const open = ref(false),
  draft = ref<CatalogFilters>(emptyFilters()),
  error = ref(''),
  busy = ref(false),
  total = ref(0);
const dictionary = ref<FacetDictionary>({ effect: [], anatomy: [], position: [], difficulty: [] });
const draftDictionary = ref<FacetDictionary>({ effect: [], anatomy: [], position: [], difficulty: [] });
const categories = ref<Category[]>([]),
  categoryError = ref('');
async function loadCategories() {
  categoryError.value = '';
  try {
    categories.value = (await api<{ data: Category[] }>('/api/categories')).data;
  } catch (e) {
    categoryError.value = errorText(e);
  }
}
onMounted(loadCategories);
const selected = computed(() =>
  dimensions.flatMap((d) => (dictionary.value[d] || []).filter((v) => model.value[d].includes(v.id))),
);
const count = computed(
  () =>
    selected.value.length +
    model.value.categories.length +
    Number(model.value.favorite) +
    Number(model.value.unclassified) +
    Number(!!model.value.has_bind) +
    Number(!!model.value.equipment),
);
let sequence = 0,
  timer: ReturnType<typeof setTimeout> | undefined;
async function load() {
  const version = ++sequence;
  busy.value = true;
  error.value = '';
  try {
    const params = setFilterParams(
      new URLSearchParams(props.context),
      open.value ? draft.value : model.value,
    );
    const result = await api<{ facets: FacetDictionary; total: number }>(`/api/asanas/facets?${params}`);
    if (version === sequence) {
      if (open.value) draftDictionary.value = result.facets;
      else dictionary.value = result.facets;
      total.value = result.total;
    }
  } catch (failure) {
    if (version === sequence) error.value = errorText(failure);
  } finally {
    if (version === sequence) busy.value = false;
  }
}
function begin() {
  draft.value = JSON.parse(JSON.stringify(model.value));
  draftDictionary.value = dictionary.value;
  open.value = true;
  void load();
}
defineExpose({ begin });
function apply() {
  model.value = JSON.parse(JSON.stringify(draft.value));
  dictionary.value = draftDictionary.value;
  open.value = false;
}
watch(
  () => [props.context, model.value, open.value ? draft.value : null],
  () => {
    clearTimeout(timer);
    timer = setTimeout(load, 120);
  },
  { deep: true, immediate: true },
);
onBeforeUnmount(() => {
  sequence++;
  clearTimeout(timer);
});
</script>
<template>
  <div class="catalog-facets">
    <div v-if="!hideTrigger" class="filter-toolbar">
      <button type="button" class="btn btn-secondary" @click="begin">
        <SlidersHorizontal :size="17" /> Категории{{ count ? ` (${count})` : '' }}
      </button>
    </div>
    <div v-if="selected.length" class="selected-facets">
      <button
        v-for="value in selected"
        :key="value.id"
        class="btn btn-ghost"
        type="button"
        :aria-label="`Снять фильтр ${value.name}`"
        @click="
          model = { ...model, [value.dimension]: model[value.dimension].filter((id) => id !== value.id) }
        "
      >
        {{ value.name }} ×
      </button>
    </div>
    <p v-if="error" class="error-banner" role="alert">
      {{ error }} <button class="btn btn-ghost" type="button" @click="load">Повторить</button>
    </p>
    <Modal :open="open" title="Категории асан" @close="open = false">
      <p v-if="categoryError" class="error-banner" role="alert">
        {{ categoryError }}
        <button class="btn btn-ghost" @click="loadCategories">Повторить загрузку личных категорий</button>
      </p>
      <fieldset v-if="categories.length" class="personal-categories">
        <legend>Мои категории</legend>
        <label v-for="category in categories" :key="category.id" class="category-chip">
          <input v-model="draft.categories" type="checkbox" :value="category.id" />{{ category.name }}
        </label>
      </fieldset>
      <div class="filter-toolbar modal-quick-filters">
        <label><input v-model="draft.favorite" type="checkbox" /> Избранное</label>
        <label><input v-model="draft.unclassified" type="checkbox" /> Не размечены</label>
      </div>
      <FacetChoices v-model="draft" :dictionary="draftDictionary" />
      <div class="advanced-filters">
        <label
          >Замок / связанное положение рук<select v-model="draft.has_bind" class="select">
            <option value="">Любое</option>
            <option value="yes">С замком</option>
            <option value="no">Без замка</option>
            <option value="unknown">Не указано</option>
          </select></label
        ><label
          >Оборудование<input v-model="draft.equipment" class="input" placeholder="Например: блок"
        /></label>
      </div>
      <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
      <template #footer
        ><button type="button" class="btn btn-ghost" @click="draft = emptyFilters()">Сбросить</button
        ><button type="button" class="btn btn-secondary" @click="open = false">Отмена</button
        ><button type="button" class="btn btn-primary" :disabled="busy || !!error" @click="apply">
          {{ busy ? 'Считаем…' : `Показать ${total} асан` }}
        </button></template
      >
    </Modal>
  </div>
</template>
<style scoped>
.catalog-facets {
  margin: 14px 0;
}
.filter-toolbar,
.selected-facets {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.filter-toolbar label {
  font-size: 0.83rem;
  display: flex;
  align-items: center;
  gap: 5px;
}
.selected-facets {
  margin-top: 8px;
}
.selected-facets .btn {
  font-size: 0.75rem;
  min-height: 30px;
}
.desktop-facets {
  margin-top: 12px;
}
.desktop-facets :deep(.facet-choices) {
  grid-template-columns: repeat(4, minmax(0, 1fr));
  align-items: start;
}
.personal-categories {
  border: 0;
  padding: 0;
  margin-bottom: 20px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.personal-categories legend {
  margin-bottom: 10px;
  font-weight: 600;
}
.category-chip {
  display: inline-flex;
  gap: 7px;
  align-items: center;
  border: 1px solid #dce5d6;
  border-radius: 24px;
  padding: 10px 14px;
}
.category-chip:has(input:checked) {
  background: #edf7e8;
  border-color: #80a76c;
}
.modal-quick-filters {
  margin-bottom: 20px;
}
.advanced-filters {
  display: grid;
  gap: 14px;
  margin-top: 20px;
}
.advanced-filters label {
  display: grid;
  gap: 6px;
  font-size: 0.85rem;
}
@media (max-width: 800px) {
  .desktop-facets {
    display: none;
  }
}
@supports (padding: env(safe-area-inset-bottom)) {
  :deep(.modal-footer) {
    padding-bottom: max(16px, env(safe-area-inset-bottom));
  }
}
</style>
