<script setup lang="ts">
import ResponsivePanel from '@/shared/ResponsivePanel.vue';
import AppSelect from '@/shared/AppSelect.vue';
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
import ActionMenu from '@/shared/ActionMenu.vue';
import AsanaCategoryDialog from '../components/AsanaCategoryDialog.vue';
import AppShell from '@/shared/AppShell.vue';
import PageHeading from '@/shared/PageHeading.vue';
import { api } from '@/shared/api';
import AsanaCard from '../components/AsanaCard.vue';
import AsanaPreview from '../components/AsanaPreview.vue';
import AsanaEditDialog from '../components/AsanaEditDialog.vue';
import UploadDialog from '../components/UploadDialog.vue';
import TaxonomyDialog from '../components/TaxonomyDialog.vue';
import AddToCollectionDialog from '../../collections/components/AddToCollectionDialog.vue';
import CollectionsPanel from '../../collections/components/CollectionsPanel.vue';
import { cardCount, categoryLabel, errorText, type Asana, type Category, type Page } from '../types';
import CategoryQuickFilters from '../components/CategoryQuickFilters.vue';
import { emptyFilters, filtersFromUrl, setFilterParams } from '../facets';
import FacetFilters from '../components/FacetFilters.vue';
import ClassificationDialog from '../components/ClassificationDialog.vue';
const classificationOpen = ref(false);
const comparisonAvailable = ref(false);
const categoryCard = ref<Asana | null>(null);
const filters = ref(emptyFilters());
const initial = new URL(window.location.href);
filters.value = filtersFromUrl(initial.searchParams);
const tab = ref(initial.searchParams.get('tab') === 'collections' ? 'collections' : 'asanas');
const query = ref(initial.searchParams.get('q') || ''),
  category = ref(''),
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
  url.searchParams.delete('image_style');
  const values: Record<string, string> = {
    tab: tab.value === 'collections' ? 'collections' : '',
    q: query.value,
    category: category.value,
    archive: archive.value === 'active' ? '' : archive.value,
    sort: sort.value === 'name_asc' ? '' : sort.value,
    page: page.value > 1 ? String(page.value) : '',
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
      `/api/asanas?${setFilterParams(new URLSearchParams({ q: query.value, category: category.value, archive: archive.value, sort: sort.value, page: String(page.value) }), filters.value)}`,
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
onMounted(() => {
  void load();
  void loadMetadata();
  void api<{ available: boolean }>('/api/figure-comparison/availability')
    .then((response) => (comparisonAvailable.value = response.available))
    .catch(() => undefined);
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
async function duplicateCard(card: Asana) {
  const name = window.prompt('Название копии асаны', `${card.name} — копия`);
  if (!name?.trim() || busy.value) return;
  busy.value = true;
  try {
    const result = await api<{ data: Asana }>(`/api/asanas/${card.id}/duplicate`, {
      method: 'POST',
      body: JSON.stringify({ name: name.trim(), expected_revision: card.revision }),
    });
    await load();
    editing.value = result.data;
  } catch (e) {
    error.value = errorText(e);
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
    <PageHeading
      eyebrow="Коллекция асан"
      title="Библиотека асан"
      description="Ваши асаны, рисунки и подборки — в одном месте."
    >
      <div class="library-actions">
        <button class="btn btn-primary" @click="uploadOpen = true"><Plus :size="19" /> Добавить асаны</button>
      </div>
    </PageHeading>
    <div class="library-nav">
      <div class="tabs">
        <button class="tab" :class="{ active: tab === 'asanas' }" @click="tab = 'asanas'">
          <Images :size="17" /> Все асаны <span class="tab-count">{{ result?.total ?? '…' }}</span></button
        ><button class="tab" :class="{ active: tab === 'collections' }" @click="tab = 'collections'">
          <Layers3 :size="17" /> Подборки
        </button>
      </div>
      <ActionMenu label="Настройки библиотеки">
        <a v-if="comparisonAvailable" href="/figure-comparison" target="_blank" rel="noopener"
          >Сравнение рисунков</a
        >
        <a href="/settings">Настройки отображения</a>
        <button @click="taxonomyOpen = true">Управление категориями</button>
        <button @click="selectVisible">
          {{ visibleSelected ? 'Снять выбор с видимых' : 'Выбрать видимые' }}
        </button>
      </ActionMenu>
    </div>
    <div v-if="success" class="success-banner" role="status">{{ success }}</div>
    <div v-if="metadataError" class="error-banner" role="alert">
      {{ metadataError }}
      <button class="btn btn-ghost" @click="loadMetadata">Повторить загрузку категорий</button>
    </div>
    <template v-if="tab === 'asanas'">
      <section class="card catalog-controls" aria-label="Поиск и фильтры асан">
        <div class="library-tools">
          <div class="library-search">
            <Search :size="19" /><input
              v-model="query"
              class="input"
              placeholder="Поиск асан"
              aria-label="Поиск асан"
            /><button v-if="query" class="search-clear" aria-label="Очистить поиск" @click="query = ''">
              <X :size="16" />
            </button>
          </div>
        </div>
        <ResponsivePanel title="Категории и фильтры асан" :active="hasFilters">
          <FacetFilters v-model="filters" :context="{ q: query, category, archive, sort }" />
          <div class="sort-controls">
            <AppSelect v-model="archive" class="select small-select" aria-label="Активные или архивные асаны">
              <option value="active">Активные</option>
              <option value="archived">В архиве</option></AppSelect
            ><AppSelect v-model="sort" class="select small-select" aria-label="Сортировка асан">
              <option value="name_asc">По алфавиту: А–Я</option>
              <option value="name_desc">По алфавиту: Я–А</option>
              <option value="recent">Недавно добавленные</option>
              <option value="used_recently">Недавно использованные</option>
            </AppSelect>
          </div>
        </ResponsivePanel>
        <div class="library-subtools">
          <div class="library-result-count">
            <span v-if="result">{{ cardCount(result.total) }}</span
            ><button v-if="hasFilters" class="btn btn-ghost" @click="clearFilters">
              Сбросить фильтры <X :size="14" />
            </button>
          </div>
        </div>
      </section>
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
      <div v-if="loading && !result" class="catalog-loading" role="status">
        <span class="loading-dot" /> Загружаем карточки…
      </div>
      <template v-else-if="result?.data.length">
        <div class="asana-grid" :aria-busy="busy || loading">
          <AsanaCard
            v-for="card in result.data"
            :key="card.id"
            :card="card"
            :selected="selection.has(card.id)"
            :selectable="!card.archived_at"
            @select="toggle"
            @preview="preview = $event"
            @edit="editing = $event"
            @category="categoryCard = $event"
            @duplicate="duplicateCard"
            @collection="
              selection = new Map([[$event.id, $event]]);
              addToCollection = true;
            "
            @archive="archiveCard"
            @restore="archiveCard"
          /></div
      ></template>
      <div v-else-if="!error" class="card empty-state library-empty">
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
    <AsanaCategoryDialog
      :card="categoryCard"
      :categories="categories"
      @close="categoryCard = null"
      @saved="load"
    />
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
      @close="preview = null"
      @related="preview = $event"
      @created="
        editing = $event;
        load();
      "
      @insert="router.visit(`/practices?insert_asana=${$event.id}`)"
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
.library-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.library-nav {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  border-bottom: 0;
  margin-bottom: var(--section-gap);
}
.library-nav .tabs {
  min-width: 0;
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
  background: var(--surface-soft);
  padding: 2px 6px;
  font-size: 0.7rem;
  min-width: 30px;
  text-align: center;
  font-variant-numeric: tabular-nums;
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
  border: 1px solid var(--line);
  border-radius: 999px;
  background: var(--surface-soft);
}
.library-image-style button {
  min-height: var(--control-height);
  padding: 9px 15px;
  border: 1px solid transparent;
  border-radius: 999px;
  background: transparent;
  color: var(--muted);
  font: inherit;
  font-size: 0.85rem;
  cursor: pointer;
}
.library-image-style button.active {
  border-color: var(--line);
  background: #fff;
  color: var(--accent-dark);
  font-weight: 650;
}
.library-image-style button:focus-visible {
  outline: 3px solid color-mix(in srgb, var(--accent) 50%, transparent);
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
  color: var(--muted);
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
  color: var(--muted);
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
  margin: 20px 0 0;
}
.library-result-count {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 14px;
  color: var(--muted);
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
  min-height: var(--control-height) !important;
  font-size: 13px !important;
  padding-top: 10px !important;
  padding-bottom: 10px !important;
  border-color: var(--line) !important;
  background-color: white !important;
}
.selection-bar {
  position: sticky;
  top: 12px;
  z-index: 15;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 20px;
  margin-bottom: var(--section-gap);
  background: white;
  border: 1px solid var(--line);
  border-radius: var(--panel-radius);
  box-shadow: var(--panel-shadow);
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
  color: var(--accent-dark);
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
  color: var(--muted);
  font-size: 0.73rem;
}
.visible-selection .btn {
  font-size: 0.78rem;
  min-height: 30px;
  padding: 5px 0;
}
.asana-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: var(--section-gap);
}
.catalog-loading {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 10px;
  padding: 100px 20px;
  color: var(--muted);
}
.loading-dot {
  height: 10px;
  width: 10px;
  border-radius: 50%;
  background: var(--accent);
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
  background: var(--surface-soft);
  color: var(--accent-dark);
  border-radius: 23px;
  margin: 0 auto 24px;
}
.library-empty h2 {
  font-size: 19px;
  letter-spacing: -0.025em;
  margin: 0;
  font-weight: 650;
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
  color: var(--muted);
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
    align-items: center;
    flex-wrap: nowrap;
    gap: 8px;
  }
  .library-nav .tabs {
    flex: 1;
  }
  .library-nav .tab {
    flex: 1;
    padding-inline: 8px;
    gap: 5px;
    font-size: 12px;
  }
  .library-actions {
    width: 100%;
  }
  .library-actions .btn {
    width: 100%;
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
}
@media (max-width: 370px) {
  .sort-controls {
    flex-wrap: wrap;
  }
  .library-nav .tab {
    font-size: 0.78rem;
  }
}
.catalog-controls {
  display: grid;
  grid-template-columns: fit-content(280px) minmax(0, 1fr);
  grid-template-areas: 'facets search' 'count sort';
  align-items: start;
  gap: 20px;
  margin-bottom: var(--section-gap);
}
.catalog-controls :deep(.catalog-facets) {
  grid-area: facets;
  min-width: 0;
  margin: 0;
}
.catalog-controls .library-tools {
  grid-area: search;
  min-width: 0;
}
.catalog-controls .library-subtools {
  grid-area: count;
  margin: 0;
}
.catalog-controls :deep(.sort-controls) {
  grid-area: sort;
  justify-self: end;
}
@media (max-width: 760px) {
  .catalog-controls {
    grid-template-columns: minmax(0, 1fr) auto;
    grid-template-areas: 'search filters' 'count count';
    gap: 12px;
    padding: 14px;
  }
  .catalog-controls :deep(.responsive-panel-trigger) {
    grid-area: filters;
  }
  .sort-controls {
    width: 100%;
    margin-left: 0;
    flex-wrap: wrap;
  }
  .sort-controls .select {
    flex: 1;
    min-width: 0;
  }
  .asana-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
  }
}
@media (min-width: 701px) and (max-width: 950px) {
  .asana-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
