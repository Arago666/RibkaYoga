<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
  Plus,
  Search,
  ArrowRight,
  BookOpen,
  LayoutTemplate,
  Rows3,
  Copy,
  Archive,
  RotateCcw,
  Pencil,
  ChevronLeft,
  ChevronRight,
  CalendarDays,
  X,
  UserPlus,
  LayoutGrid,
  List,
} from '@lucide/vue';
import ActionMenu from '@/shared/ActionMenu.vue';
import PracticePicker, { type PracticeChoice } from '../components/PracticePicker.vue';
import AppShell from '@/shared/AppShell.vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import { emptyRow, uid, type Cell } from '../types';
import DocumentSharingDialog from '../components/DocumentSharingDialog.vue';
import PracticeFolders from '../components/PracticeFolders.vue';
import PortableImportDialog from '../components/PortableImportDialog.vue';
const sharingItem = ref<PracticeListItem | null>(null);
type Tab = 'practices' | 'templates' | 'rows' | 'transitions';
interface PreviewCard {
  id: string;
  name: string;
  image_url: string;
}
interface PracticeListItem {
  id: string;
  title: string;
  theme: string | null;
  revision: number;
  duration_seconds?: number | null;
  difficulty?: string | null;
  folders?: { id: string; name: string }[];
  cycles?: { id: string; title: string; position: number }[];
  updated_at: string;
  archived_at: string | null;
  kind?: 'practice' | 'template';
  blocks_count?: number;
  block_count?: number;
  row_count?: number;
  cells_count?: number;
  cell_count?: number;
  preview_cards?: PreviewCard[];
  preview_cells?: Cell[];
}
interface Page<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}
interface DocumentData extends PracticeListItem {
  note: string | null;
  practice_date?: string | null;
  blocks?: unknown[];
  cells?: unknown[];
}
const initial = new URL(window.location.href);
const sortOptions = [
  { value: 'updated_desc', label: 'Сначала обновлённые' },
  { value: 'updated_asc', label: 'Давно не обновлялись' },
  { value: 'title_asc', label: 'Название: А–Я' },
  { value: 'title_desc', label: 'Название: Я–А' },
  { value: 'created_desc', label: 'Сначала новые' },
  { value: 'created_asc', label: 'Сначала созданные раньше' },
] as const;
type PracticeSort = (typeof sortOptions)[number]['value'];
const normalizedSort = (value: string | null): PracticeSort =>
  sortOptions.some((option) => option.value === value) ? (value as PracticeSort) : 'updated_desc';
const sort = ref<PracticeSort>(normalizedSort(initial.searchParams.get('sort')));
const view = ref<'grid' | 'list'>(initial.searchParams.get('view') === 'list' ? 'list' : 'grid');
const insertAsana = initial.searchParams.get('insert_asana');
const insertVariant = initial.searchParams.get('insert_variant');
const foldersOpen = ref(false);
const importOpen = ref(false);
const addPracticeOpen = ref(false);
const selection = ref(new Map<string, PracticeListItem>());
const includeCycleDocuments = ref(initial.searchParams.get('include_cycle_documents') === '1');
const folderId = ref(initial.searchParams.get('folder') || '');
const selectedDocuments = computed(() =>
  [...selection.value.values()].map(({ id, revision }) => ({ id, revision })),
);
function toggleSelection(item: PracticeListItem) {
  const next = new Map(selection.value);
  if (next.has(item.id)) next.delete(item.id);
  else next.set(item.id, item);
  selection.value = next;
}
const initialTab = initial.searchParams.get('tab');
const tab = ref<Tab>(
  initialTab === 'templates' || initialTab === 'rows' || initialTab === 'transitions'
    ? initialTab
    : 'practices',
);
const query = ref(initial.searchParams.get('q') || ''),
  archive = ref(initial.searchParams.get('archive') || 'active');
const page = ref(Math.max(1, Number(initial.searchParams.get('page')) || 1)),
  result = ref<Page<PracticeListItem> | null>(null),
  loading = ref(false),
  error = ref(''),
  busy = ref(false),
  success = ref('');
const createOpen = ref(false),
  createTitle = ref(''),
  createTheme = ref(''),
  createMode = ref<'blank' | 'template'>('blank'),
  createKind = ref<'practice' | 'template' | 'row' | 'transition'>('practice'),
  templateId = ref(''),
  createError = ref('');
const nameAction = ref<'rename' | 'template' | null>(null),
  namedItem = ref<PracticeListItem | null>(null),
  newName = ref(''),
  nameError = ref(''),
  renameDocument = ref<DocumentData | null>(null);
let sequence = 0;
let restoringUrl = false;
let timer: ReturnType<typeof setTimeout> | undefined;
const rowTab = computed(() => tab.value === 'rows' || tab.value === 'transitions');
const kind = computed(() =>
  tab.value === 'transitions'
    ? 'transition'
    : tab.value === 'rows'
      ? 'row'
      : tab.value === 'templates'
        ? 'template'
        : 'practice',
);
const createIsRow = computed(() => createKind.value === 'row' || createKind.value === 'transition');
const createLabel = computed(() =>
  rowTab.value
    ? tab.value === 'transitions'
      ? 'Создать связку'
      : 'Создать строку'
    : tab.value === 'templates'
      ? 'Новый шаблон'
      : 'Создать практику',
);
const heading = computed(() =>
  tab.value === 'templates'
    ? 'Шаблоны практик'
    : rowTab.value
      ? tab.value === 'transitions'
        ? 'Связки и переходы'
        : 'Сохранённые строки'
      : 'Мои практики',
);
const resultCount = computed(() => {
  const total = result.value?.total ?? 0;
  return tab.value === 'templates'
    ? noun(total, 'шаблон', 'шаблона', 'шаблонов')
    : rowTab.value
      ? tab.value === 'transitions'
        ? noun(total, 'связка', 'связки', 'связок')
        : noun(total, 'строка', 'строки', 'строк')
      : noun(total, 'практика', 'практики', 'практик');
});
function failureText(failure: unknown) {
  return failure instanceof Error ? failure.message : 'Не удалось выполнить действие. Попробуйте ещё раз.';
}
function updateUrl() {
  const url = new URL(window.location.href);
  const values = {
    tab: tab.value === 'practices' ? '' : tab.value,
    q: query.value,
    archive: archive.value === 'active' ? '' : archive.value,
    page: page.value > 1 ? String(page.value) : '',
    folder: folderId.value,
    sort: sort.value === 'updated_desc' ? '' : sort.value,
    view: view.value === 'list' ? 'list' : '',
    include_cycle_documents: includeCycleDocuments.value ? '1' : '',
  };
  for (const [key, value] of Object.entries(values))
    value ? url.searchParams.set(key, value) : url.searchParams.delete(key);
  window.history.replaceState(window.history.state, '', url);
}
async function load() {
  const request = ++sequence;
  loading.value = true;
  error.value = '';
  updateUrl();
  const endpoint = rowTab.value ? '/api/row-templates' : '/api/practices';
  try {
    const response = await api<Page<PracticeListItem>>(
      `${endpoint}?${new URLSearchParams({ kind: kind.value, q: query.value, archive: archive.value, sort: sort.value, page: String(page.value), folder_id: folderId.value, include_cycle_documents: includeCycleDocuments.value ? '1' : '0' })}`,
    );
    if (request === sequence) {
      if (page.value > response.last_page) {
        page.value = Math.max(1, response.last_page);
        await load();
      } else result.value = response;
    }
  } catch (failure) {
    if (request === sequence) error.value = failureText(failure);
  } finally {
    if (request === sequence) loading.value = false;
  }
}
watch(query, () => {
  clearTimeout(timer);
  if (restoringUrl) return;
  timer = setTimeout(() => {
    page.value = 1;
    void load();
  }, 250);
});
watch([tab, archive, folderId, includeCycleDocuments, sort], () => {
  if (restoringUrl) return;
  page.value = 1;
  result.value = null;
  success.value = '';
  void load();
});
watch(view, () => {
  if (!restoringUrl) updateUrl();
});
async function restoreUrl() {
  restoringUrl = true;
  clearTimeout(timer);
  const params = new URL(window.location.href).searchParams;
  const nextTab = params.get('tab');
  tab.value =
    nextTab === 'templates' || nextTab === 'rows' || nextTab === 'transitions' ? nextTab : 'practices';
  query.value = params.get('q') || '';
  archive.value = params.get('archive') === 'archived' ? 'archived' : 'active';
  folderId.value = params.get('folder') || '';
  includeCycleDocuments.value = params.get('include_cycle_documents') === '1';
  sort.value = normalizedSort(params.get('sort'));
  view.value = params.get('view') === 'list' ? 'list' : 'grid';
  page.value = Math.max(1, Number(params.get('page')) || 1);
  await nextTick();
  restoringUrl = false;
  await load();
}
onMounted(() => {
  window.addEventListener('popstate', restoreUrl);
  void load();
});
onBeforeUnmount(() => {
  clearTimeout(timer);
  sequence++;
  window.removeEventListener('popstate', restoreUrl);
});
function go(value: number) {
  page.value = value;
  void load();
}
function editUrl(item: PracticeListItem) {
  const path = rowTab.value ? `/row-templates/${item.id}/edit` : `/practices/${item.id}/edit`;
  return insertAsana
    ? `${path}?${new URLSearchParams({ insert_asana: insertAsana, ...(insertVariant ? { insert_variant: insertVariant } : {}) })}`
    : path;
}
function formatDate(value: string) {
  if (!value) return '';
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime())
    ? ''
    : new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short' }).format(parsed);
}
function noun(count: number, one: string, few: string, many: string) {
  const last = count % 10,
    lastTwo = count % 100;
  return `${count} ${lastTwo >= 11 && lastTwo <= 14 ? many : last === 1 ? one : last >= 2 && last <= 4 ? few : many}`;
}
function counts(item: PracticeListItem) {
  return rowTab.value
    ? noun(item.cells_count ?? item.cell_count ?? 0, 'ячейка', 'ячейки', 'ячеек')
    : `${noun(item.blocks_count ?? item.block_count ?? 0, 'блок', 'блока', 'блоков')} · ${noun(item.row_count ?? 0, 'строка', 'строки', 'строк')}`;
}
function openCreate(template?: PracticeListItem, asTemplate = false) {
  createOpen.value = true;
  createKind.value = asTemplate
    ? 'template'
    : !template && rowTab.value
      ? tab.value === 'transitions'
        ? 'transition'
        : 'row'
      : 'practice';
  createTitle.value = template ? `${template.title} — практика` : '';
  createTheme.value = template?.theme || '';
  createMode.value = template ? 'template' : 'blank';
  templateId.value = template?.id || '';
  createError.value = '';
}
async function create() {
  if (busy.value || !createTitle.value.trim()) return;
  busy.value = true;
  createError.value = '';
  const transitionKey = uid();
  const isRow = createKind.value === 'row' || createKind.value === 'transition';
  try {
    const response = await api<{ data: { id: string } }>(isRow ? '/api/row-templates' : '/api/practices', {
      method: 'POST',
      body: JSON.stringify({
        title: createTitle.value.trim(),
        theme: createTheme.value.trim() || null,
        ...(isRow
          ? {
              kind: createKind.value,
              cells: emptyRow(createKind.value === 'transition' ? 2 : 6).cells.map((cell) => ({
                ...cell,
                group_key: createKind.value === 'transition' ? transitionKey : null,
              })),
            }
          : {
              kind: createKind.value,
              ...(createMode.value === 'template' && templateId.value
                ? { template_id: templateId.value }
                : {}),
            }),
      }),
    });
    createOpen.value = false;
    router.visit(`/${isRow ? 'row-templates' : 'practices'}/${response.data.id}/edit`);
  } catch (failure) {
    createError.value = failureText(failure);
  } finally {
    busy.value = false;
  }
}
function dragDocument(event: DragEvent, item: PracticeListItem) {
  if (rowTab.value || item.archived_at) {
    event.preventDefault();
    return;
  }
  event.dataTransfer?.setData(
    'application/ribka-document',
    JSON.stringify({ id: item.id, revision: item.revision, type: 'practice' }),
  );
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'link';
}
async function addExistingPractice(item: PracticeChoice) {
  await duplicate(item as PracticeListItem);
}
async function duplicate(item: PracticeListItem) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    const response = await api<{ data: { id: string } }>(`/api/practices/${item.id}/duplicate`, {
      method: 'POST',
      body: JSON.stringify({ expected_revision: item.revision }),
    });
    router.visit(`/practices/${response.data.id}/edit`);
  } catch (failure) {
    error.value = failureText(failure);
  } finally {
    busy.value = false;
  }
}
async function changeArchive(item: PracticeListItem) {
  if (busy.value) return;
  if (
    !item.archived_at &&
    !window.confirm(`Убрать «${item.title}» в архив? Документ можно будет восстановить.`)
  )
    return;
  busy.value = true;
  error.value = '';
  try {
    await api(
      `${rowTab.value ? '/api/row-templates' : '/api/practices'}/${item.id}/${item.archived_at ? 'restore' : 'archive'}`,
      { method: 'POST', body: JSON.stringify({ expected_revision: item.revision }) },
    );
    success.value = item.archived_at ? 'Документ восстановлен.' : 'Документ перемещён в архив.';
    await load();
  } catch (failure) {
    error.value = failureText(failure);
  } finally {
    busy.value = false;
  }
}
async function openName(item: PracticeListItem, action: 'rename' | 'template') {
  namedItem.value = item;
  nameAction.value = action;
  newName.value = item.title;
  nameError.value = '';
  renameDocument.value = null;
  if (action === 'rename') {
    busy.value = true;
    try {
      const response = await api<{ data: DocumentData }>(
        `${rowTab.value ? '/api/row-templates' : '/api/practices'}/${item.id}`,
      );
      renameDocument.value = response.data;
      newName.value = response.data.title;
    } catch (failure) {
      nameError.value = failureText(failure);
    } finally {
      busy.value = false;
    }
  }
}
async function saveName() {
  if (!namedItem.value || busy.value) return;
  busy.value = true;
  nameError.value = '';
  try {
    if (nameAction.value === 'template') {
      await api(`/api/practices/${namedItem.value.id}/save-as-template`, {
        method: 'POST',
        body: JSON.stringify({ title: newName.value.trim(), expected_revision: namedItem.value.revision }),
      });
      success.value = 'Шаблон сохранён. Он доступен во вкладке «Шаблоны».';
    } else {
      if (!renameDocument.value)
        throw new Error('Не удалось загрузить документ. Закройте окно и повторите действие.');
      const current = renameDocument.value;
      await api(`${rowTab.value ? '/api/row-templates' : '/api/practices'}/${current.id}`, {
        method: 'PUT',
        body: JSON.stringify({
          title: newName.value.trim(),
          theme: current.theme,
          note: current.note,
          expected_revision: current.revision,
          ...(rowTab.value
            ? { cells: current.cells }
            : { practice_date: current.practice_date || null, blocks: current.blocks }),
        }),
      });
      success.value = 'Название изменено.';
    }
    nameAction.value = null;
    namedItem.value = null;
    await load();
  } catch (failure) {
    nameError.value = failureText(failure);
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <PracticePicker
    :external-error="error"
    :open="addPracticeOpen"
    :busy="busy"
    @close="addPracticeOpen = false"
    @choose="addExistingPractice"
  />
  <PortableImportDialog :open="importOpen" @close="importOpen = false" />
  <Modal :open="foldersOpen" title="Категории практик" @close="foldersOpen = false">
    <PracticeFolders :documents="selectedDocuments" @changed="load" />
    <template #footer><button class="btn btn-primary" @click="foldersOpen = false">Готово</button></template>
  </Modal>
  <Head :title="heading" />
  <AppShell section="practices">
    <header class="page-header practices-heading">
      <div>
        <p class="eyebrow">ПРОСТРАНСТВО ДЛЯ ВАШЕЙ ПРАКТИКИ</p>
        <h1 class="page-title">{{ heading }}</h1>
      </div>
      <button class="btn btn-primary" @click="openCreate(undefined, tab === 'templates')">
        <Plus :size="19" /> {{ createLabel }}
      </button>
      <button v-if="!rowTab" class="btn btn-secondary" @click="addPracticeOpen = true">
        <Plus :size="18" />Добавить практику
      </button>
      <ActionMenu label="Действия со списком практик">
        <button @click="importOpen = true">Импорт JSON</button>
        <button @click="includeCycleDocuments = !includeCycleDocuments">
          {{ includeCycleDocuments ? 'Скрыть занятия внутри циклов' : 'Показывать занятия внутри циклов' }}
        </button>
        <button v-if="folderId" @click="folderId = ''">Все категории</button>
        <button v-if="selection.size" @click="foldersOpen = true">
          Категории выбранных ({{ selection.size }})
        </button>
      </ActionMenu>
    </header>
    <div class="practices-nav tabs">
      <Link class="tab active" href="/practices">Все практики</Link>
      <Link class="tab" href="/mesocycles">Программы и циклы</Link>
      <Link class="tab" href="/practice-folders">Категории</Link>
      <Link class="tab" href="/shared-practices">Мне дали доступ</Link>
    </div>
    <p v-if="insertAsana" class="success-banner">Выберите практику, затем ячейку для выбранной асаны.</p>
    <div class="practices-toolbar">
      <div class="practice-search">
        <Search :size="19" /><input
          v-model="query"
          class="input"
          :placeholder="rowTab ? 'Найти строку по названию или теме' : 'Найти по названию или теме'"
          aria-label="Поиск документов"
        /><button v-if="query" aria-label="Очистить поиск" @click="query = ''"><X :size="16" /></button>
      </div>
      <select v-model="archive" class="select" aria-label="Состояние документов">
        <option value="active">Активные</option>
        <option value="archived">В архиве</option>
      </select>
      <label class="practice-sort"
        ><span>Сортировка</span>
        <select v-model="sort" class="select">
          <option v-for="option in sortOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>
      <div class="view-switch" role="group" aria-label="Вид практик">
        <button class="btn btn-ghost" :aria-pressed="view === 'grid'" @click="view = 'grid'">
          <LayoutGrid :size="18" />Карточки
        </button>
        <button class="btn btn-ghost" :aria-pressed="view === 'list'" @click="view = 'list'">
          <List :size="18" />Список
        </button>
      </div>
      <span v-if="result && !loading" class="document-count">{{ resultCount }}</span>
    </div>
    <div v-if="success" class="success-banner" role="status">{{ success }}</div>
    <div v-if="error" class="error-banner" role="alert">
      {{ error }} <button class="btn btn-ghost" @click="load">Повторить</button>
    </div>
    <div v-if="loading" class="practices-loading" role="status">
      Загружаем ваши {{ rowTab ? 'строки' : 'практики' }}…
    </div>
    <div v-else-if="result?.data.length" class="practice-grid" :class="{ 'practice-list': view === 'list' }">
      <article
        v-for="item in result.data"
        :key="item.id"
        class="card practice-card"
        :class="{ 'selected-document': selection.has(item.id) }"
        :draggable="!rowTab && !item.archived_at"
        @dragstart="dragDocument($event, item)"
      >
        <div class="practice-card-menu">
          <ActionMenu :label="`Действия: ${item.title}`">
            <template v-if="!item.archived_at">
              <button
                v-if="!rowTab"
                @click="
                  selection = new Map([[item.id, item]]);
                  foldersOpen = true;
                "
              >
                Добавить в категорию
              </button>
              <Link :href="editUrl(item)">Редактировать</Link>
              <button @click="openName(item, 'rename')">Переименовать</button>
              <button v-if="!rowTab" :disabled="busy" @click="duplicate(item)">Дублировать</button>
              <button v-if="!rowTab" @click="sharingItem = item">Дать доступ</button>
              <button v-if="tab === 'practices'" @click="openName(item, 'template')">
                Сохранить как шаблон
              </button>
              <button v-if="!rowTab" @click="toggleSelection(item)">
                {{ selection.has(item.id) ? 'Снять выбор' : 'Выбрать для нескольких категорий' }}
              </button>
              <button :disabled="busy" @click="changeArchive(item)">Удалить в архив</button>
            </template>
            <button v-else :disabled="busy" @click="changeArchive(item)">Восстановить</button>
          </ActionMenu>
        </div>
        <Link class="practice-cover" :href="editUrl(item)" :aria-label="`Открыть: ${item.title}`"
          ><span class="cover-kind"
            ><Rows3 v-if="rowTab" :size="14" /><LayoutTemplate
              v-else-if="tab === 'templates'"
              :size="14"
            /><BookOpen v-else :size="14" />{{
              rowTab
                ? tab === 'transitions'
                  ? 'СВЯЗКА'
                  : 'СТРОКА'
                : tab === 'templates'
                  ? 'ШАБЛОН'
                  : 'ПРАКТИКА'
            }}</span
          >
          <div v-if="item.preview_cells?.length || item.preview_cards?.length" class="cover-poses">
            <template v-if="item.preview_cells?.length">
              <span v-for="cell in item.preview_cells.slice(0, 5)" :key="cell.id" class="cover-cell">
                <img v-if="cell.card" :src="cell.card.image_url" :alt="cell.card.name" loading="lazy" />
                <span v-else class="cover-custom-name" :title="cell.custom_name || ''">{{
                  cell.custom_name
                }}</span>
              </span>
            </template>
            <template v-else>
              <img
                v-for="(card, index) in item.preview_cards?.slice(0, 5)"
                :key="`${card.id}-${index}`"
                :src="card.image_url"
                :alt="card.name"
                loading="lazy"
              />
            </template>
          </div>
          <div v-else class="cover-empty" aria-hidden="true"><span /><span /><span /><span /><span /></div>
          <span class="cover-line"
        /></Link>
        <div class="practice-content">
          <span v-if="item.theme" class="practice-theme">{{ item.theme }}</span
          ><span v-else class="practice-theme neutral">{{
            rowTab ? 'Для ваших последовательностей' : 'В своём ритме'
          }}</span
          ><Link class="practice-title" :href="editUrl(item)">{{ item.title }}</Link>
          <div v-if="item.cycles?.length" class="cycle-context">
            <Link v-for="cycle in item.cycles" :key="cycle.id" :href="`/mesocycles/${cycle.id}/edit`"
              >{{ cycle.title }} · занятие {{ cycle.position + 1 }}</Link
            >
          </div>
          <div v-if="item.folders?.length" class="cycle-context">
            <button
              v-for="folder in item.folders"
              :key="folder.id"
              class="btn btn-ghost"
              @click="folderId = folder.id"
            >
              {{ folder.name }}
            </button>
          </div>
          <p v-if="item.duration_seconds" class="muted">{{ Math.ceil(item.duration_seconds / 60) }} мин</p>
          <p
            class="practice-difficulty"
            title="Максимальный подтверждённый уровень всех поз. Неполная разметка не определяет уровень практики."
          >
            Уровень: {{ item.difficulty || 'не указан' }}
          </p>
          <div class="practice-meta">
            <span>{{ counts(item) }}</span
            ><span v-if="item.updated_at"><CalendarDays :size="13" /> {{ formatDate(item.updated_at) }}</span>
          </div>
          <template v-if="!item.archived_at"
            ><button
              v-if="tab === 'templates'"
              class="btn btn-primary open-practice"
              :disabled="busy"
              @click="openCreate(item)"
            >
              Создать практику <ArrowRight :size="17" /></button
            ><Link v-else class="btn btn-secondary open-practice" :href="editUrl(item)"
              >{{
                rowTab ? (tab === 'transitions' ? 'Изменить связку' : 'Изменить строку') : 'Открыть практику'
              }}<ArrowRight :size="17"
            /></Link>
          </template>
          <button
            v-else
            class="btn btn-secondary open-practice"
            :disabled="busy"
            @click="changeArchive(item)"
          >
            Восстановить
          </button>
        </div>
      </article>
    </div>
    <div v-else-if="!error" class="empty-state practices-empty">
      <div class="empty-symbol">
        <Rows3 v-if="rowTab" :size="39" :stroke-width="1.4" /><LayoutTemplate
          v-else-if="tab === 'templates'"
          :size="39"
          :stroke-width="1.4"
        /><BookOpen v-else :size="39" :stroke-width="1.4" />
      </div>
      <h2>
        {{
          query
            ? 'Ничего не нашлось'
            : archive === 'archived'
              ? 'В архиве пока пусто'
              : rowTab
                ? 'Сохраняйте удачные сочетания'
                : tab === 'templates'
                  ? 'Ваша основа для новых практик'
                  : 'Хорошая практика начинается с идеи'
        }}
      </h2>
      <p>
        {{
          query
            ? 'Попробуйте другое название или тему.'
            : archive === 'archived'
              ? 'Нет удаленных элементов.'
              : rowTab
                ? 'Создайте строку с нуля или сохраните удачное сочетание из практики. Готовую строку можно вставлять в другие практики.'
                : tab === 'templates'
                  ? 'Сохраните готовую практику как шаблон или создайте основу с нуля.'
                  : 'Создайте первый лист и наполните его асанами из вашей библиотеки.'
        }}
      </p>
      <button v-if="query" class="btn btn-secondary" @click="query = ''">Очистить поиск</button
      ><button
        v-else-if="archive === 'active'"
        class="btn btn-primary"
        @click="openCreate(undefined, tab === 'templates')"
      >
        <Plus :size="18" />
        {{ createLabel }}
      </button>
    </div>
    <div v-if="result && result.last_page > 1" class="practice-pagination">
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
    <Modal
      :open="createOpen"
      :title="
        createKind === 'transition'
          ? 'Новая связка'
          : createKind === 'row'
            ? 'Новая строка'
            : createKind === 'template'
              ? 'Новый шаблон'
              : 'Новая практика'
      "
      @close="!busy && (createOpen = false)"
    >
      <form id="create-practice" class="practice-form" @submit.prevent="create">
        <div v-if="createError" class="error-banner" role="alert">{{ createError }}</div>
        <div class="field">
          <label class="label" for="practice-title">Название</label
          ><input
            id="practice-title"
            v-model="createTitle"
            class="input"
            required
            maxlength="160"
            :disabled="busy"
            :placeholder="createIsRow ? 'Например, разминка для плеч' : 'Например, мягкая практика для спины'"
          />
        </div>
        <div class="field">
          <label class="label" for="practice-theme">Тема · необязательно</label
          ><input
            id="practice-theme"
            v-model="createTheme"
            class="input"
            maxlength="240"
            :disabled="busy"
            placeholder="На чём сосредоточимся?"
          />
        </div>
        <p class="muted create-hint">
          {{
            createKind === 'transition'
              ? 'Откроется связка с двумя пустыми ячейками в скобках. Её можно заполнить и вставлять целиком вместо одной ячейки практики.'
              : createKind === 'row'
                ? 'Откроется строка с 6 пустыми ячейками. Добавьте асаны и настройте количество ячеек — готовую строку можно вставлять в практики.'
                : createKind === 'template'
                  ? 'Шаблон сохранится отдельно. Созданные из него практики можно редактировать независимо.'
                  : 'Названия блоков, количество строк и ячеек можно изменить в конструкторе.'
          }}
        </p>
      </form>
      <template #footer
        ><button class="btn btn-secondary" :disabled="busy" @click="createOpen = false">Отмена</button
        ><button
          class="btn btn-primary"
          type="submit"
          form="create-practice"
          :disabled="
            busy ||
            !createTitle.trim() ||
            (createKind === 'practice' && createMode === 'template' && !templateId)
          "
        >
          {{
            busy
              ? 'Создаём…'
              : createKind === 'transition'
                ? 'Создать связку'
                : createKind === 'row'
                  ? 'Создать строку'
                  : 'Открыть конструктор'
          }}
          <ArrowRight :size="17" /></button
      ></template>
    </Modal>
    <Modal
      :open="Boolean(nameAction)"
      :title="nameAction === 'template' ? 'Сохранить как шаблон' : 'Изменить название'"
      @close="!busy && (nameAction = null)"
      ><form id="document-name" class="practice-form" @submit.prevent="saveName">
        <div v-if="nameError" class="error-banner" role="alert">{{ nameError }}</div>
        <p v-if="nameAction === 'template'" class="muted">
          Сохранится отдельная копия. Исходная практика останется прежней.
        </p>
        <div class="field">
          <label class="label" for="document-new-name">Название</label
          ><input
            id="document-new-name"
            v-model="newName"
            class="input"
            required
            maxlength="160"
            :disabled="busy"
          />
        </div>
      </form>
      <template #footer
        ><button class="btn btn-secondary" :disabled="busy" @click="nameAction = null">Отмена</button
        ><button
          class="btn btn-primary"
          form="document-name"
          type="submit"
          :disabled="busy || !newName.trim() || (nameAction === 'rename' && !renameDocument)"
        >
          {{ busy ? 'Подождите…' : 'Сохранить' }}
        </button></template
      ></Modal
    >
  </AppShell>
  <DocumentSharingDialog
    :open="Boolean(sharingItem)"
    :document-id="sharingItem?.id || ''"
    :title="sharingItem?.title"
    @close="sharingItem = null"
  />
</template>
<style scoped>
.folder-tools,
.cycle-context {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  align-items: center;
  margin: 12px 0;
}
.practice-selection {
  display: flex;
  gap: 8px;
  padding: 10px 16px;
  font-size: 13px;
}
.cycle-context > a {
  font-size: 13px;
  color: var(--muted);
}
.practices-heading {
  margin-bottom: 31px;
}
.practices-heading > .btn {
  flex: none;
}
.practices-nav {
  margin-bottom: 25px;
}
.practices-nav .tab {
  display: flex;
  align-items: center;
  gap: 8px;
}
.practices-toolbar {
  display: flex;
  gap: 13px;
  align-items: center;
  margin-bottom: 25px;
  flex-wrap: wrap;
}
.practice-sort {
  display: grid;
  gap: 4px;
  font-size: 12px;
  color: var(--muted);
}
.view-switch {
  display: flex;
  gap: 4px;
  flex-wrap: wrap;
}
.view-switch button[aria-pressed='true'] {
  background: #edf5e8;
  color: #376c28;
  box-shadow: inset 0 0 0 1px #91b483;
}
.practice-difficulty {
  color: var(--muted);
  font-size: 13px;
  margin: 8px 0;
}
.practices-toolbar > .select {
  width: 160px;
  flex: none;
}
.practice-search {
  position: relative;
  flex: 1;
  max-width: 530px;
}
.practice-search > svg {
  position: absolute;
  left: 15px;
  top: 15px;
  color: #7b8975;
}
.practice-search .input {
  width: 100%;
  padding-left: 44px;
  padding-right: 38px;
}
.practice-search > button {
  position: absolute;
  right: 11px;
  top: 14px;
  background: none;
  border: 0;
  color: #687568;
  cursor: pointer;
}
.document-count {
  margin-left: auto;
  color: #86907e;
  font-size: 0.78rem;
}
.practice-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 22px;
}
.practice-grid.practice-list {
  grid-template-columns: minmax(0, 1fr);
  gap: 12px;
}
.practice-list .practice-card {
  display: grid;
  grid-template-columns: minmax(160px, 240px) minmax(0, 1fr);
}
.practice-list .practice-selection {
  grid-column: 1 / -1;
}
.practice-list .practice-cover {
  height: 100%;
  min-height: 150px;
}
.practice-list .practice-content {
  padding-top: 12px;
}
.practice-list .practice-title {
  min-height: 0;
}
.practice-card {
  padding: 0 !important;
  overflow: visible;
  position: relative;
  transition:
    box-shadow 0.15s,
    border-color 0.15s;
}
.practice-card-menu {
  position: absolute;
  right: 10px;
  top: 10px;
  z-index: 5;
}
.selected-document {
  outline: 2px solid #7ba962;
}
.practice-cover {
  border-radius: 18px 18px 0 0;
}
.practice-card:hover {
  border-color: #c8d9bf;
  box-shadow: 0 6px 22px #23351c07;
}
.practice-cover {
  position: relative;
  display: flex;
  flex-direction: column;
  height: 166px;
  background: #f7faf4;
  padding: 20px 21px 23px;
  text-decoration: none;
  color: #819574;
  overflow: hidden;
}
.cover-kind {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.6rem;
  letter-spacing: 0.1em;
  font-weight: 650;
}
.cover-poses {
  display: flex;
  flex: 1;
  align-items: center;
  justify-content: center;
  gap: 13px;
  padding-top: 10px;
}
.cover-poses img {
  flex: 1;
  min-width: 0;
  max-width: 18%;
  height: 69px;
  object-fit: contain;
}
.cover-cell {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 1;
  min-width: 0;
  max-width: 23%;
  height: 69px;
}
.cover-cell img {
  max-width: 100%;
}
.cover-custom-name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 3;
  overflow: hidden;
  overflow-wrap: anywhere;
  color: #4c683e;
  font-size: 12px;
  line-height: 1.35;
  text-align: center;
}
.cover-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  flex: 1;
  padding-top: 8px;
}
.cover-empty span {
  width: 43px;
  height: 48px;
  border: 1.5px dashed #d9e4d3;
  border-radius: 8px;
  background: #ffffff50;
}
.cover-line {
  position: absolute;
  left: 21px;
  right: 21px;
  bottom: 20px;
  height: 1px;
  background: #e3ebdc;
}
.practice-content {
  padding: 22px 21px 13px;
}
.practice-theme {
  display: inline-block;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.7rem;
  font-weight: 550;
  color: #487539;
  background: #eff6e9;
  padding: 5px 8px;
  border-radius: 7px;
  margin-bottom: 10px;
}
.practice-theme.neutral {
  background: transparent;
  color: #7d8a74;
  padding-left: 0;
  font-weight: 400;
}
.practice-title {
  display: block;
  color: #253127;
  font-size: 1.14rem;
  font-weight: 650;
  letter-spacing: -0.025em;
  line-height: 1.35;
  text-decoration: none;
  min-height: 2.7em;
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}
.practice-title:hover {
  color: #397a28;
}
.practice-meta {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-size: 0.73rem;
  color: #7e8a77;
  margin: 14px 0 20px;
}
.practice-meta span:last-child {
  display: flex;
  align-items: center;
  gap: 5px;
  white-space: nowrap;
}
.open-practice {
  width: 100%;
  justify-content: space-between;
}
.practice-actions {
  display: flex;
  align-items: center;
  gap: 4px;
  margin: 10px -5px 0;
}
.practice-actions .icon-btn {
  width: 34px;
  height: 34px;
}
.template-action {
  font-size: 0.74rem;
  padding: 7px;
  min-height: 34px;
}
.archive-action {
  margin-left: auto;
}
.practices-empty {
  padding: 70px 20px;
}
.empty-symbol {
  display: grid;
  place-items: center;
  width: 82px;
  height: 82px;
  background: #eef5e8;
  color: #79a068;
  border-radius: 25px;
  margin: 0 auto 24px;
}
.practices-empty h2 {
  font-size: 1.4rem;
  letter-spacing: -0.025em;
  margin: 0;
}
.practices-empty p {
  max-width: 425px;
  margin: 13px auto 25px;
  line-height: 1.65;
  font-size: 0.92rem;
}
.practices-loading {
  padding: 100px 20px;
  text-align: center;
  color: #687568;
}
.practice-pagination {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 20px;
  margin-top: 30px;
  color: #687568;
  font-size: 0.82rem;
}
.practice-form {
  display: grid;
  gap: 18px;
}
.create-source {
  margin: 0;
  padding: 0;
  border: 0;
}
.source-options {
  display: grid;
  gap: 9px;
  margin-top: 10px;
}
.source-options label {
  display: flex;
  gap: 13px;
  align-items: center;
  padding: 15px;
  border: 1px solid #e2e8df;
  border-radius: 13px;
  cursor: pointer;
}
.source-options label.chosen {
  background: #f3f9ee;
  border-color: #82ab70;
}
.source-options input {
  accent-color: #397a28;
}
.source-options svg {
  color: #68865c;
  flex: none;
}
.source-options span {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.source-options strong {
  font-size: 0.86rem;
  font-weight: 600;
}
.source-options small {
  font-size: 0.77rem;
  color: #7a8774;
}
.create-hint {
  font-size: 0.8rem;
  line-height: 1.6;
  margin: 0;
}
@media (max-width: 1080px) {
  .practice-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .practice-meta {
    gap: 6px;
  }
}
@media (max-width: 680px) {
  .practice-list .practice-card {
    grid-template-columns: 1fr;
  }
  .practice-list .practice-cover {
    height: 110px;
    min-height: 0;
  }
  .practice-grid {
    grid-template-columns: 1fr;
    gap: 17px;
  }
  .practices-toolbar {
    flex-wrap: wrap;
  }
  .practice-search {
    min-width: 100%;
    max-width: none;
  }
  .document-count {
    font-size: 0.73rem;
  }
  .practices-nav .tab {
    font-size: 0.76rem;
    gap: 5px;
    padding-left: 9px;
    padding-right: 9px;
  }
  .practices-nav .tab > svg {
    display: none;
  }
  .practice-cover {
    height: 150px;
  }
  .practice-title {
    min-height: auto;
  }
  .practices-heading > .btn {
    margin-top: 5px;
  }
  .practices-empty {
    padding: 50px 10px;
  }
  .practices-empty h2 {
    font-size: 1.2rem;
  }
}
</style>
