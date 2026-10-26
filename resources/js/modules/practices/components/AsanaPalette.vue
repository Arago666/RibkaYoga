<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
  Search,
  Library,
  ChevronLeft,
  ChevronRight,
  RefreshCw,
  X,
  SlidersHorizontal,
  GripVertical,
} from '@lucide/vue';
import { uiPreferencesKey, readUiPreferences } from '@/shared/uiPreferences';
import { api } from '@/shared/api';
import type { Asana, Category, Collection, Page } from '@/modules/catalog/types';
import FacetFilters from '@/modules/catalog/components/FacetFilters.vue';
import { dimensions, emptyFilters, setFilterParams } from '@/modules/catalog/facets';
import { usePaletteDrag, type PaletteDragPoint } from '../composables/usePaletteDrag';
const props = defineProps<{
  documentId: string;
  active: boolean;
  mobileOpen: boolean;
  disabled?: boolean;
  embedded?: boolean;
}>();
const emit = defineEmits<{
  select: [card: Asana];
  close: [];
  dragMove: [point: PaletteDragPoint];
  dragDrop: [card: Asana, point: PaletteDragPoint];
  dragEnd: [];
}>();
const pointerDrag = usePaletteDrag({
  enabled: () => !props.disabled && !props.embedded,
  move: (point) => emit('dragMove', point),
  drop: (card, point) => emit('dragDrop', card, point),
  end: () => emit('dragEnd'),
});
const dragPreview = pointerDrag.preview;
const previewStyle = computed(() =>
  dragPreview.value
    ? {
        left: `${Math.max(8, Math.min(dragPreview.value.x + 16, window.innerWidth - 140))}px`,
        top: `${Math.max(8, Math.min(dragPreview.value.y + 16, window.innerHeight - 138))}px`,
      }
    : {},
);
function selectCard(event: MouseEvent, card: Asana) {
  if (pointerDrag.allowClick(event) && !props.disabled && !card.archived_at) emit('select', card);
}
watch(
  () => props.disabled,
  (value) => {
    if (value) pointerDrag.cancel();
  },
);
const pageProps = usePage();
const sourceInputId = useId();
const searchInput = ref<HTMLInputElement | null>(null);
const facetControl = ref<InstanceType<typeof FacetFilters> | null>(null);
const key = `ribka:palette:${(pageProps.props.auth as { user?: { id: number } })?.user?.id}:${props.documentId}`;
const filters = ref(emptyFilters());
const query = ref(''),
  category = ref(''),
  source = ref('all'),
  sort = ref('name_asc'),
  page = ref(1);
const cards = ref<Asana[]>([]),
  collections = ref<Collection[]>([]),
  categories = ref<Category[]>([]);
const loading = ref(true),
  error = ref(''),
  total = ref(0),
  lastPage = ref(1);
let timer: ReturnType<typeof setTimeout>,
  serial = 0;
let alive = true;
const title = computed(() =>
  source.value === 'all'
    ? 'Все асаны'
    : collections.value.find((item) => item.id === source.value)?.name || 'Подборка',
);
const activeFilterCount = computed(
  () =>
    filters.value.categories.length +
    dimensions.reduce((count, dimension) => count + filters.value[dimension].length, 0) +
    Number(filters.value.favorite) +
    Number(filters.value.unclassified) +
    Number(!!filters.value.has_bind) +
    Number(!!filters.value.equipment),
);
const hasSearchFilter = computed(
  () => !!query.value || !!category.value || JSON.stringify(filters.value) !== JSON.stringify(emptyFilters()),
);
function remember() {
  if (!readUiPreferences(uiPreferencesKey(pageProps.props.auth)).rememberFilters) return;
  try {
    localStorage.setItem(key, JSON.stringify({ source: source.value }));
  } catch {
    /* Optional UI preference. */
  }
}
async function load() {
  if (!alive) return;
  const current = ++serial;
  loading.value = true;
  error.value = '';
  try {
    const search = new URLSearchParams({
      q: query.value,
      sort: sort.value,
      page: String(page.value),
      per_page: '24',
      archive: 'active',
    });
    if (category.value) search.set('category', category.value);
    if (source.value !== 'all') search.set('collection', source.value);
    const result = await api<Page<Asana>>(`/api/asanas?${setFilterParams(search, filters.value)}`);
    if (current !== serial) return;
    cards.value = result.data;
    total.value = result.total;
    lastPage.value = result.last_page;
  } catch (e) {
    if (current === serial) error.value = e instanceof Error ? e.message : 'Не удалось загрузить асаны.';
  } finally {
    if (current === serial) loading.value = false;
  }
}
watch([query, category, source, sort], () => {
  serial++;
  loading.value = true;
  page.value = 1;
  remember();
  clearTimeout(timer);
  timer = setTimeout(load, 250);
});
watch(
  filters,
  () => {
    page.value = 1;
    void load();
  },
  { deep: true },
);
watch(page, load);
onMounted(async () => {
  if (props.embedded) void nextTick(() => searchInput.value?.focus());
  try {
    let selected = new URLSearchParams(location.search).get('collection');
    if (!selected && readUiPreferences(uiPreferencesKey(pageProps.props.auth)).rememberFilters) {
      try {
        selected = JSON.parse(localStorage.getItem(key) || '{}').source;
      } catch {
        /* Ignore invalid preference. */
      }
    }
    const results = await Promise.all([
      api<Page<Collection>>('/api/collections?per_page=100'),
      api<{ data: Category[] }>('/api/categories'),
    ]);
    if (!alive) return;
    collections.value = results[0].data;
    categories.value = results[1].data;
    for (let n = 2; n <= results[0].last_page; n++) {
      const result = await api<Page<Collection>>(`/api/collections?per_page=100&page=${n}`);
      if (!alive) return;
      collections.value.push(...result.data);
    }
    if (selected && collections.value.some((item) => item.id === selected)) source.value = selected;
    await load();
  } catch (e) {
    if (alive) {
      error.value = e instanceof Error ? e.message : 'Не удалось открыть библиотеку.';
      loading.value = false;
    }
  }
});
onBeforeUnmount(() => {
  alive = false;
  clearTimeout(timer);
  serial++;
});
</script>
<template>
  <aside :class="['asana-palette', { 'mobile-open': mobileOpen, embedded }]" aria-label="Выбор асаны">
    <header v-if="!embedded" class="palette-heading">
      <div>
        <h2>Асаны</h2>
        <p>Ваши карточки для практики</p>
      </div>
      <button class="icon-btn close-palette" aria-label="Закрыть выбор асаны" @click="emit('close')">
        <X :size="20" />
      </button>
    </header>
    <div class="palette-filters">
      <label class="sr-only" :for="sourceInputId">Подборка асан</label
      ><select :id="sourceInputId" v-model="source" class="select" :disabled="disabled">
        <option value="all">Все асаны</option>
        <optgroup label="Подборки">
          <option v-for="item in collections" :key="item.id" :value="item.id">{{ item.name }}</option>
        </optgroup>
      </select>
      <div class="palette-search">
        <Search :size="17" /><input
          ref="searchInput"
          v-model="query"
          class="input"
          placeholder="Найти асану"
          aria-label="Найти асану в панели"
          :disabled="disabled"
        /><button class="icon-btn" aria-label="Категории асан" @click="facetControl?.begin()">
          <SlidersHorizontal :size="17" /><span v-if="activeFilterCount" class="filter-count">{{
            activeFilterCount
          }}</span>
        </button>
      </div>
      <FacetFilters
        ref="facetControl"
        hide-trigger
        v-model="filters"
        :context="{ q: query, category, collection: source === 'all' ? '' : source, sort, archive: 'active' }"
        compact
      />
    </div>
    <p v-if="!embedded" class="palette-hint">
      {{ active ? 'Нажмите на асану, чтобы вставить её' : 'Выберите ячейку или перетащите асану' }}
    </p>
    <div v-if="error" class="palette-message error-banner">
      {{ error }}<button class="btn btn-ghost" @click="load"><RefreshCw :size="16" />Повторить</button>
    </div>
    <div v-else-if="loading" class="palette-grid">
      <div v-for="n in 6" :key="n" class="skeleton" style="height: 130px" />
    </div>
    <div v-else-if="!cards.length" class="palette-empty">
      <Library :size="30" />
      <h3>{{ hasSearchFilter ? 'Асаны не найдены' : 'Здесь появятся ваши асаны' }}</h3>
      <p>
        {{
          hasSearchFilter
            ? 'Попробуйте другое название или выберите другую категорию.'
            : 'Загрузите готовые изображения в библиотеку и соберите первую подборку.'
        }}
      </p>
      <Link href="/library" class="btn btn-secondary">В библиотеку</Link>
    </div>
    <div v-else class="palette-grid">
      <button
        v-for="card in cards"
        :key="card.id"
        class="palette-card"
        :disabled="disabled || !!card.archived_at"
        draggable="false"
        @dragstart.prevent
        @pointerdown="pointerDrag.start($event, card)"
        @click="selectCard($event, card)"
      >
        <span
          v-if="!embedded"
          data-palette-drag-handle
          class="palette-drag-handle"
          title="Перетащить карточку"
          aria-hidden="true"
          ><GripVertical :size="17"
        /></span>
        <img
          :src="card.thumbnail_url || card.image_url"
          :alt="card.name"
          loading="lazy"
          draggable="false"
        /><span>{{ card.name }}</span
        ><small v-if="card.archived_at">В архиве</small>
        <small v-if="card.facet_values?.some((v) => v.dimension === 'difficulty')">{{
          card.facet_values.find((v) => v.dimension === 'difficulty')?.name
        }}</small>
      </button>
    </div>
    <footer class="palette-footer">
      <span>{{ title }} · {{ total }}</span>
      <div v-if="lastPage > 1">
        <button
          class="icon-btn"
          :disabled="page <= 1 || loading"
          aria-label="Предыдущие асаны"
          @click="page--"
        >
          <ChevronLeft :size="17" /></button
        ><span>{{ page }} / {{ lastPage }}</span
        ><button
          class="icon-btn"
          :disabled="page >= lastPage || loading"
          aria-label="Следующие асаны"
          @click="page++"
        >
          <ChevronRight :size="17" />
        </button>
      </div>
    </footer>
  </aside>
  <Teleport to="body">
    <div v-if="dragPreview" class="palette-drag-preview" :style="previewStyle" aria-hidden="true">
      <img :src="dragPreview.card.thumbnail_url || dragPreview.card.image_url" alt="" />
      <span>{{ dragPreview.card.name }}</span>
    </div>
  </Teleport>
</template>
<style scoped>
.asana-palette {
  position: sticky;
  top: 24px;
  align-self: start;
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 18px;
  overflow: hidden;
  max-height: calc(100vh - 48px);
  display: flex;
  flex-direction: column;
}
.palette-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 22px 20px 17px;
}
.palette-heading h2 {
  font-size: 18px;
  font-weight: 700;
}
.palette-heading p {
  font-size: 12px;
  color: #8a9781;
  margin-top: 5px;
}
.palette-filters {
  padding: 0 18px;
}
.palette-filters .select {
  font-size: 14px;
}
.palette-search {
  position: relative;
  display: flex;
  align-items: center;
  margin-top: 10px;
}
.palette-search > svg {
  position: absolute;
  left: 11px;
  color: #99a58f;
}
.palette-search input {
  padding-left: 35px;
  font-size: 14px;
  padding-right: 37px;
}
.palette-search button {
  position: absolute;
  right: 4px;
}
.filter-count {
  position: absolute;
  right: 0;
  top: 0;
  min-width: 15px;
  height: 15px;
  border-radius: 50%;
  padding: 1px 3px;
  color: white;
  background: #397a28;
  font-size: 10px;
  line-height: 13px;
}
.palette-extra {
  display: grid;
  gap: 7px;
  margin-top: 9px;
}
.palette-hint {
  font-size: 12px;
  line-height: 1.6;
  color: #7f8e76;
  padding: 14px 19px;
  background: #fafcf8;
  border-bottom: 1px solid #edf0e9;
  margin-top: 16px;
}
.palette-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  padding: 16px;
  overflow-y: auto;
  min-height: 120px;
}
.palette-card {
  position: relative;
  user-select: none;
  border: 1px solid #e3e9df;
  background: #fff;
  border-radius: 12px;
  padding: 12px 8px 9px;
  text-align: center;
  min-width: 0;
  cursor: grab;
}
.palette-card > img,
.palette-card > span:not(.palette-drag-handle),
.palette-card > small {
  pointer-events: none;
}
.palette-drag-handle {
  position: absolute;
  top: 2px;
  right: 2px;
  display: grid !important;
  place-items: center;
  width: 28px;
  height: 28px;
  margin: 0 !important;
  color: #8ca47f;
  cursor: grab;
  touch-action: none;
}
.palette-drag-handle svg {
  pointer-events: none;
}
.palette-drag-preview {
  position: fixed;
  z-index: 150;
  width: 126px;
  padding: 10px;
  border: 1px solid #83aa71;
  border-radius: 13px;
  background: #fff;
  box-shadow: 0 10px 30px #25312730;
  pointer-events: none;
  transform: rotate(-3deg);
}
.palette-drag-preview img {
  display: block;
  width: 100%;
  height: 72px;
  object-fit: contain;
}
.palette-drag-preview span {
  display: block;
  margin-top: 6px;
  font-size: 12px;
  line-height: 1.3;
  color: #48643c;
  text-align: center;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
@media (pointer: coarse) {
  .palette-drag-handle {
    width: 38px;
    height: 38px;
  }
}
.palette-card:hover {
  border-color: #8eaf7d;
  background: #f8fcf5;
}
.palette-card img {
  height: 74px;
  width: 100%;
  object-fit: contain;
}
.palette-card span {
  display: block;
  font-size: 12px;
  color: #68745f;
  margin-top: 8px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.palette-card small {
  display: block;
  font-size: 11px;
  margin-top: 4px;
}
.palette-empty {
  padding: 35px 24px 40px;
  text-align: center;
}
.palette-empty svg {
  margin: auto;
  color: #a0b890;
}
.palette-empty h3 {
  font-size: 16px;
  margin: 18px 0 9px;
  font-weight: 650;
}
.palette-empty p {
  font-size: 13px;
  line-height: 1.7;
  color: var(--muted);
  margin-bottom: 19px;
}
.palette-footer {
  padding: 12px 16px;
  border-top: 1px solid var(--border);
  font-size: 12px;
  color: #91a086;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
}
.palette-footer > span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.palette-footer > div {
  display: flex;
  align-items: center;
  white-space: nowrap;
}
.palette-message {
  margin: 16px;
}
.close-palette {
  display: none;
}
.asana-palette.embedded {
  position: static;
  display: flex;
  border: 0;
  border-radius: 0;
  max-height: none;
  box-shadow: none;
  min-width: 0;
}
.embedded .palette-filters {
  padding: 0;
}
.embedded .palette-grid {
  grid-template-columns: repeat(3, minmax(0, 1fr));
  padding: 3px;
  margin: 15px -3px 0;
  max-height: 43dvh;
  min-height: 150px;
}
.embedded .palette-card {
  cursor: pointer;
}
.embedded .palette-card span {
  white-space: normal;
  overflow-wrap: anywhere;
  line-height: 1.4;
}
.embedded .palette-footer {
  padding: 13px 0 0;
  margin-top: 14px;
}
.embedded .palette-empty {
  padding: 25px 10px;
}
.embedded .palette-message {
  margin: 16px 0 0;
}
@media (max-width: 1000px) {
  .asana-palette {
    display: none;
  }
  .asana-palette.mobile-open {
    display: flex;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    top: auto;
    z-index: 70;
    border-radius: 24px 24px 0 0;
    max-height: 68dvh;
    box-shadow: 0 -10px 60px #223b2020;
  }
  .close-palette {
    display: inline-flex;
  }
  .palette-grid {
    grid-template-columns: repeat(auto-fill, minmax(95px, 1fr));
  }
  .palette-heading {
    padding: 16px 20px 12px;
  }
  .palette-hint {
    margin-top: 10px;
    padding: 10px 20px;
  }
  .palette-card img {
    height: 66px;
  }
  .palette-empty {
    padding: 22px;
  }
}
@media (max-width: 500px) {
  .embedded .palette-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
