<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
  Plus,
  Search,
  SlidersHorizontal,
  Images,
  Layers3,
  Check,
  X,
  ChevronLeft,
  ChevronRight,
} from '@lucide/vue';
import AppShell from '@/shared/AppShell.vue';
import { api } from '@/shared/api';
import AsanaCard from '../components/AsanaCard.vue';
import AsanaPreview from '../components/AsanaPreview.vue';
import AsanaEditDialog from '../components/AsanaEditDialog.vue';
import UploadDialog from '../components/UploadDialog.vue';
import TaxonomyDialog from '../components/TaxonomyDialog.vue';
import AddToCollectionDialog from '../../collections/components/AddToCollectionDialog.vue';
import CollectionsPanel from '../../collections/components/CollectionsPanel.vue';
import {
  cardCount,
  categoryLabel,
  errorText,
  type Asana,
  type Category,
  type LibraryImageStyle,
  type Page,
} from '../types';
import CategoryQuickFilters from '../components/CategoryQuickFilters.vue';
import { emptyFilters, filtersFromUrl, setFilterParams } from '../facets';
import FacetFilters from '../components/FacetFilters.vue';
import ClassificationDialog from '../components/ClassificationDialog.vue';
const classificationOpen = ref(false);
const filters = ref(emptyFilters());
const initial = new URL(window.location.href);
filters.value = filtersFromUrl(initial.searchParams);
const tab = ref(initial.searchParams.get('tab') === 'collections' ? 'collections' : 'asanas');
const imageStyle = ref<LibraryImageStyle>(
  initial.searchParams.get('image_style') === 'volume' ? 'volume' : 'original',
);
const query = ref(initial.searchParams.get('q') || ''),
  category = ref(initial.searchParams.get('category') || ''),
  archive = ref(initial.searchParams.get('archive') || 'active'),
  sort = ref(initial.searchParams.get('sort') || 'name_asc');
const page = ref(Math.max(1, Number(initial.searchParams.get('page')) || 1)),
  result = ref<Page<Asana> | null>(null);
const categories = ref<Category[]>([]),
  selection = ref(new Map<string, Asana>());
const loading = ref(false),
  error = ref(''),
  success = ref(''),
  busy = ref(false),
  metadataError = ref('');
const uploadOpen = ref(false),
  taxonomyOpen = ref(false),
  addToCollection = ref(false),
  preview = ref<Asana | null>(null),
  editing = ref<Asana | null>(null);
const collectionsPanel = ref<InstanceType<typeof CollectionsPanel> | null>(null);
function uploaded() {
  void load();
  void collectionsPanel.value?.refresh();
}
let sequence = 0;
let timer: ReturnType<typeof setTimeout> | undefined;
const selectedIds = computed(() => [...selection.value.keys()]);
const hasFilters = computed(() =>
  Boolean(
    query.value ||
    category.value ||
    archive.value !== 'active' ||
    sort.value === 'used_recently' ||
    JSON.stringify(filters.value) !== JSON.stringify(emptyFilters()),
  ),
);
const visibleSelected = computed(
  () =>
    Boolean(result.value?.data.length) &&
    result.value!.data.filter((card) => !card.archived_at).every((card) => selection.value.has(card.id)),
);
function updateUrl() {
  const url = new URL(window.location.href);
  url.searchParams.delete('tag');
  const values: Record<string, string> = {
    tab: tab.value === 'collections' ? 'collections' : '',
    q: query.value,
    category: category.value,
    archive: archive.value === 'active' ? '' : archive.value,
    sort: sort.value === 'name_asc' ? '' : sort.value,
    page: page.value > 1 ? String(page.value) : '',
    image_style: imageStyle.value === 'volume' ? 'volume' : '',
  };
  for (const [key, value] of Object.entries(values))
    value ? url.searchParams.set(key, value) : url.searchParams.delete(key);
  setFilterParams(url.searchParams, filters.value);
  window.history.replaceState(window.history.state, '', url);
}
async function load() {
  const request = ++sequence;
  loading.value = true;
  error.value = '';
  updateUrl();
  try {
    const response = await api<Page<Asana>>(
      `/api/asanas?${setFilterParams(new URLSearchParams({ q: query.value, category: category.value, archive: archive.value, sort: sort.value, page: String(page.value), image_style: imageStyle.value }), filters.value)}`,
    );
    if (request === sequence) {
      if (page.value > response.last_page) {
        page.value = Math.max(1, response.last_page);
        await load();
      } else result.value = response;
    }
  } catch (failure) {
    if (request === sequence) error.value = errorText(failure);
  } finally {
    if (request === sequence) loading.value = false;
  }
}
async function loadMetadata() {
  metadataError.value = '';
  try {
    categories.value = (await api<{ data: Category[] }>('/api/categories')).data;
    if (category.value && !categories.value.some((item) => item.id === category.value)) category.value = '';
  } catch (failure) {
    metadataError.value = errorText(failure);
  }
}
watch(query, () => {
  clearTimeout(timer);
  timer = setTimeout(() => {
    page.value = 1;
    void load();
  }, 250);
});
watch([category, archive, sort], () => {
  page.value = 1;
  void load();
});
watch(
  filters,
  () => {
    page.value = 1;
    void load();
  },
  { deep: true },
);
watch(tab, updateUrl);
watch(imageStyle, () => {
  result.value = null;
  void load();
});
onMounted(() => {
  void load();
  void loadMetadata();
});
onBeforeUnmount(() => {
  clearTimeout(timer);
  sequence++;
});
function toggle(card: Asana) {
  const next = new Map(selection.value);
  next.has(card.id) ? next.delete(card.id) : next.set(card.id, card);
  selection.value = next;
  success.value = '';
}
function selectVisible() {
  const next = new Map(selection.value);
  const remove = visibleSelected.value;
  for (const card of result.value?.data || [])
    if (!card.archived_at) remove ? next.delete(card.id) : next.set(card.id, card);
  selection.value = next;
}
function clearFilters() {
  filters.value = emptyFilters();
  query.value = '';
  category.value = '';
  archive.value = 'active';
  sort.value = 'name_asc';
}
function go(value: number) {
  page.value = value;
  void load();
}
async function archiveCard(card: Asana) {
  if (busy.value) return;
  if (
    !card.archived_at &&
    !window.confirm(`Убрать «${card.name}» в архив? В сохранённых практиках изображение останется.`)
  )
    return;
  busy.value = true;
  error.value = '';
  success.value = '';
  try {
    await api(`/api/asanas/${card.id}/${card.archived_at ? 'restore' : 'archive'}`, {
      method: 'POST',
      body: JSON.stringify({ expected_revision: card.revision }),
    });
    selection.value.delete(card.id);
    selection.value = new Map(selection.value);
    await load();
    success.value = card.archived_at ? 'Карточка восстановлена.' : 'Карточка перемещена в архив.';
  } catch (failure) {
    error.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}
function collectionSaved(name: string) {
  selection.value = new Map();
  success.value = `Карточки добавлены в подборку «${name}».`;
}
async function taxonomyChanged() {
  await loadMetadata();
  await load();
}
</script>
<template>
  <Head title="Библиотека асан" />
  <AppShell section="library">
    <header class="page-header library-heading">
      <div>
        <p class="eyebrow">КОЛЛЕКЦИЯ АСАН</p>
        <h1 class="page-title">Библиотека асан</h1>
      </div>
      <div style="display: flex; gap: 10px; flex-wrap: wrap">
        <button class="btn btn-primary" @click="uploadOpen = true"><Plus :size="19" /> Добавить асаны</button>
      </div>
    </header>
    <div class="library-nav">
      <div class="tabs">
        <button class="tab" :class="{ active: tab === 'asanas' }" @click="tab = 'asanas'">
          <Images :size="17" /> Все асаны
          <span v-if="result && tab === 'asanas'" class="tab-count">{{ result.total }}</span></button
        ><button class="tab" :class="{ active: tab === 'collections' }" @click="tab = 'collections'">
          <Layers3 :size="17" /> Подборки
        </button>
      </div>
      <button class="btn btn-ghost taxonomy-button" @click="taxonomyOpen = true">
        <SlidersHorizontal :size="17" /> Категории
      </button>
    </div>
    <div v-if="success" class="success-banner" role="status">{{ success }}</div>
    <div v-if="metadataError" class="error-banner" role="alert">
      {{ metadataError }}
      <button class="btn btn-ghost" @click="loadMetadata">Повторить загрузку категорий</button>
    </div>
    <template v-if="tab === 'asanas'">
      <div class="library-image-style" role="group" aria-label="Стиль изображений в библиотеке">
        <button
          type="button"
          :class="{ active: imageStyle === 'original' }"
          :aria-pressed="imageStyle === 'original'"
          @click="imageStyle = 'original'"
        >
          Мои рисунки
        </button>
        <button
          type="button"
          :class="{ active: imageStyle === 'volume' }"
          :aria-pressed="imageStyle === 'volume'"
          @click="imageStyle = 'volume'"
        >
          Объёмные фигуры
        </button>
      </div>
      <FacetFilters v-model="filters" :context="{ q: query, category, archive, sort }" />
      <div class="library-tools">
        <div class="library-search">
          <Search :size="19" /><input
            v-model="query"
            class="input"
            placeholder="Название асаны или другое название"
            aria-label="Поиск асан"
          /><button v-if="query" class="search-clear" aria-label="Очистить поиск" @click="query = ''">
            <X :size="16" />
          </button>
        </div>
        <select v-model="category" class="select category-select" aria-label="Фильтр по категории">
          <option value="">Мои категории: все</option>
          <option v-for="item in categories" :key="item.id" :value="item.id">
            {{ categoryLabel(item) }}
          </option>
        </select>
      </div>
      <div class="library-subtools">
        <div class="library-result-count">
          <span v-if="result">{{ cardCount(result.total) }}</span
          ><button v-if="hasFilters" class="btn btn-ghost" @click="clearFilters">
            Сбросить фильтры <X :size="14" />
          </button>
        </div>
        <div class="sort-controls">
          <select v-model="archive" class="select small-select" aria-label="Активные или архивные асаны">
            <option value="active">Активные</option>
            <option value="archived">В архиве</option></select
          ><select v-model="sort" class="select small-select" aria-label="Сортировка асан">
            <option value="name_asc">По алфавиту: А–Я</option>
            <option value="name_desc">По алфавиту: Я–А</option>
            <option value="recent">Недавно добавленные</option>
            <option value="used_recently">Недавно использованные</option>
          </select>
        </div>
      </div>
      <div v-if="selection.size" class="selection-bar">
        <div>
          <span class="selected-count"><Check :size="17" /> Выбрано {{ selection.size }}</span
          ><button class="btn btn-ghost" @click="selection = new Map()">Сбросить</button>
        </div>
        <button class="btn btn-secondary" @click="classificationOpen = true">Разметить</button>
        <button class="btn btn-primary" @click="addToCollection = true">
          <Layers3 :size="17" /> Добавить в подборку
        </button>
      </div>
      <div v-if="error" class="error-banner" role="alert">
        {{ error }} <button class="btn btn-ghost" @click="load">Повторить</button>
      </div>
      <div v-if="loading" class="catalog-loading" role="status">
        <span class="loading-dot" /> Загружаем карточки…
      </div>
      <template v-else-if="result?.data.length"
        ><div v-if="archive === 'active'" class="visible-selection">
          <button class="btn btn-ghost" @click="selectVisible">
            {{ visibleSelected ? 'Снять выбор с видимых' : 'Выбрать видимые' }}</button
          ><span>Выбор сохраняется между страницами</span>
        </div>
        <div class="asana-grid" :aria-busy="busy">
          <AsanaCard
            v-for="card in result.data"
            :key="card.id"
            :card="card"
            :display-image="imageStyle === 'volume' ? card.display_image : undefined"
            :selected="selection.has(card.id)"
            :selectable="!card.archived_at"
            @select="toggle"
            @preview="preview = $event"
            @edit="editing = $event"
            @archive="archiveCard"
            @restore="archiveCard"
          /></div
      ></template>
      <div v-else-if="!error" class="empty-state library-empty">
        <div class="empty-icon"><Images :size="38" :stroke-width="1.5" /></div>
        <h2>{{ hasFilters ? 'Подходящих карточек пока нет' : 'Здесь начинается ваша практика' }}</h2>
        <p>
          {{
            hasFilters
              ? 'Измените запрос или очистите фильтры, чтобы увидеть другие асаны.'
              : 'Добавьте готовые изображения асан, чтобы собирать из них последовательности.'
          }}
        </p>
        <button v-if="hasFilters" class="btn btn-secondary" @click="clearFilters">Сбросить фильтры</button
        ><button v-else class="btn btn-primary" @click="uploadOpen = true">
          <Plus :size="18" /> Добавить первые асаны
        </button>
      </div>
      <div v-if="result && result.last_page > 1" class="library-pagination">
        <button class="btn btn-secondary" :disabled="loading || page <= 1" @click="go(page - 1)">
          <ChevronLeft :size="17" /> Назад</button
        ><span>{{ result.current_page }} из {{ result.last_page }}</span
        ><button
          class="btn btn-secondary"
          :disabled="loading || page >= result.last_page"
          @click="go(page + 1)"
        >
          Дальше <ChevronRight :size="17" />
        </button>
      </div>
    </template>
    <CollectionsPanel v-else ref="collectionsPanel" />
    <ClassificationDialog
      :open="classificationOpen"
      :cards="[...selection.values()]"
      @close="classificationOpen = false"
      @saved="
        selection = new Map();
        load();
      "
    />
    <UploadDialog
      :open="uploadOpen"
      :categories="categories"
      @close="uploadOpen = false"
      @uploaded="uploaded"
    />
    <AsanaEditDialog :card="editing" :categories="categories" @close="editing = null" @saved="load" />
    <AsanaPreview
      :card="preview"
      :image-style="imageStyle"
      @close="preview = null"
      @related="preview = $event"
      @created="
        editing = $event;
        load();
      "
      @insert="
        router.visit(
          `/practices?insert_asana=${$event.id}${'illustration_variant_id' in $event ? `&insert_variant=${encodeURIComponent($event.illustration_variant_id ?? 'original')}` : ''}`,
        )
      "
    />
    <TaxonomyDialog
      :open="taxonomyOpen"
      :categories="categories"
      @close="taxonomyOpen = false"
      @changed="taxonomyChanged"
    />
    <AddToCollectionDialog
      :open="addToCollection"
      :asana-ids="selectedIds"
      @close="addToCollection = false"
      @saved="collectionSaved"
    />
  </AppShell>
</template>
<style scoped>
.library-heading {
  margin-bottom: 30px;
}
.library-heading > .btn {
  flex: none;
}
.library-nav {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  border-bottom: 1px solid #e5ebe1;
  margin-bottom: 26px;
}
.library-nav .tabs {
  margin-bottom: 0;
  border-bottom: 0;
}
.library-nav .tab {
  display: flex;
  gap: 8px;
  align-items: center;
}
.tab-count {
  border-radius: 7px;
  background: #edf3e8;
  padding: 2px 6px;
  font-size: 0.7rem;
}
.taxonomy-button {
  font-size: 0.82rem;
}
.library-image-style {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  width: fit-content;
  padding: 4px;
  margin: 0 0 20px;
  border: 1px solid #dfe7da;
  border-radius: 14px;
  background: #f5f8f2;
}
.library-image-style button {
  min-height: 40px;
  padding: 9px 15px;
  border: 1px solid transparent;
  border-radius: 10px;
  background: transparent;
  color: #687568;
  font: inherit;
  font-size: 0.85rem;
  cursor: pointer;
}
.library-image-style button.active {
  border-color: #caddc0;
  background: #fff;
  color: #2f6f20;
  font-weight: 650;
}
.library-image-style button:focus-visible {
  outline: 3px solid #397a2880;
  outline-offset: 2px;
}
.library-tools {
  display: flex;
  gap: 12px;
  align-items: flex-start;
}
.library-search {
  flex: 1;
  position: relative;
  min-width: 200px;
}
.library-search > svg {
  position: absolute;
  left: 15px;
  top: 15px;
  color: #81907b;
}
.library-search .input {
  width: 100%;
  padding-left: 44px;
  padding-right: 38px;
}
.search-clear {
  position: absolute;
  right: 10px;
  top: 13px;
  border: 0;
  background: transparent;
  color: #687568;
  cursor: pointer;
}
.category-select {
  width: 230px;
}
.library-subtools {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 14px;
  margin: 15px 0 20px;
}
.library-result-count {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 0.8rem;
  color: #687568;
}
.library-result-count .btn {
  font-size: 0.73rem;
  min-height: 32px;
  padding: 5px 8px;
}
.sort-controls {
  display: flex;
  gap: 10px;
}
.small-select {
  min-height: 35px !important;
  font-size: 0.77rem !important;
  padding-top: 7px !important;
  padding-bottom: 7px !important;
  border-color: transparent !important;
  background-color: transparent !important;
}
.selection-bar {
  position: sticky;
  top: 12px;
  z-index: 15;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 17px;
  margin-bottom: 16px;
  background: #edf7e8;
  border: 1px solid #d6e7cc;
  border-radius: 15px;
  box-shadow: 0 5px 18px #2531270a;
}
.selection-bar > div {
  display: flex;
  align-items: center;
  gap: 15px;
}
.selected-count {
  display: flex;
  align-items: center;
  gap: 7px;
  color: #2f6f20;
  font-size: 0.9rem;
  font-weight: 650;
}
.selection-bar .btn-ghost {
  font-size: 0.75rem;
}
.visible-selection {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin: 0 0 8px;
  color: #899482;
  font-size: 0.73rem;
}
.visible-selection .btn {
  font-size: 0.78rem;
  min-height: 30px;
  padding: 5px 0;
}
.asana-grid {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 17px;
}
.catalog-loading {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 10px;
  padding: 100px 20px;
  color: #687568;
}
.loading-dot {
  height: 10px;
  width: 10px;
  border-radius: 50%;
  background: #88ad78;
  animation: pulse 1s ease-in-out infinite;
}
.library-empty {
  padding: 68px 20px;
}
.empty-icon {
  width: 78px;
  height: 78px;
  display: grid;
  place-items: center;
  background: #edf5e7;
  color: #679455;
  border-radius: 23px;
  margin: 0 auto 24px;
}
.library-empty h2 {
  font-size: 1.35rem;
  letter-spacing: -0.025em;
  margin: 0;
}
.library-empty p {
  max-width: 400px;
  margin: 13px auto 24px;
  line-height: 1.65;
  font-size: 0.9rem;
}
.library-pagination {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 20px;
  margin: 30px 0 5px;
  color: #687568;
  font-size: 0.82rem;
}
@keyframes pulse {
  50% {
    opacity: 0.35;
  }
}
@media (prefers-reduced-motion: reduce) {
  .loading-dot {
    animation: none;
  }
}
@media (max-width: 1200px) {
  .asana-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}
@media (max-width: 950px) {
  .asana-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
  .category-select {
    width: 190px;
  }
  .taxonomy-button {
    font-size: 0.73rem;
  }
}
@media (max-width: 700px) {
  .library-nav {
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 6px;
    padding-bottom: 8px;
  }
  .library-tools {
    flex-wrap: wrap;
  }
  .library-search {
    min-width: 100%;
  }
  .category-select {
    flex: 1;
    width: auto;
  }
  .library-subtools {
    align-items: flex-start;
    flex-wrap: wrap;
  }
  .sort-controls {
    margin-left: auto;
  }
  .selection-bar {
    position: static;
    flex-wrap: wrap;
  }
  .selection-bar > .btn {
    width: 100%;
    justify-content: center;
  }
  .visible-selection > span {
    display: none;
  }
  .asana-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 11px;
  }
  .library-heading > .btn {
    margin-top: 5px;
  }
}
@media (max-width: 370px) {
  .sort-controls {
    flex-wrap: wrap;
  }
  .library-nav .tab {
    font-size: 0.78rem;
  }
}
</style>
