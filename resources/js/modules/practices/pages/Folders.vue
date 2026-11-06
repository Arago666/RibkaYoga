<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
  Archive,
  ArrowLeft,
  ChevronLeft,
  ChevronRight,
  FolderOpen,
  FolderPlus,
  Layers3,
  Pencil,
  Plus,
  RotateCcw,
} from '@lucide/vue';
import ActionMenu from '@/shared/ActionMenu.vue';
import AppShell from '@/shared/AppShell.vue';
import Modal from '@/shared/Modal.vue';
import PageHeading from '@/shared/PageHeading.vue';
import { api } from '@/shared/api';
import PracticeCategoryCard, { type CategoryPractice } from '../components/PracticeCategoryCard.vue';
import PracticeCategoryDialog from '../components/PracticeCategoryDialog.vue';
import PracticeNavigation from '../components/PracticeNavigation.vue';
import PracticePicker, { type PracticeChoice } from '../components/PracticePicker.vue';

interface Category {
  id: string;
  name: string;
  revision: number;
  documents_count: number;
  preview_documents?: CategoryPractice[];
}
interface CategoryPage {
  data: CategoryPractice[];
  last_page: number;
}
type Confirmation =
  { type: 'category'; category: Category } | { type: 'practice'; practice: CategoryPractice };

const route = usePage();
const baseUrl = '/practice-folders';
const endpoint = '/api/practice-folders';
const params = computed(() => new URL(route.url, window.location.origin).searchParams);
const archived = computed(() => params.value.get('archive') === '1');
const folderId = computed(() => (archived.value ? '' : params.value.get('folder') || ''));
const pageNumber = computed(() => {
  const page = Number(params.value.get('page'));
  return Number.isSafeInteger(page) && page > 0 ? page : 1;
});
const folderUrl = (id: string, page = 1) =>
  `${baseUrl}?${new URLSearchParams({ folder: id, ...(page > 1 ? { page: String(page) } : {}) })}`;
const categories = ref<Category[]>([]);
const current = computed(() => categories.value.find((category) => category.id === folderId.value));
const details = ref<CategoryPage | null>(null);
const loading = ref(true);
const loadError = ref('');
const actionError = ref('');
const notice = ref('');
const busy = ref(false);
const categoryPractice = ref<CategoryPractice | null>(null);
const picker = ref<Category | null>(null);
const pickerError = ref('');
const nameDialog = ref<{ category?: Category } | null>(null);
const name = ref('');
const dialogError = ref('');
const confirmAction = ref<Confirmation | null>(null);
let request = 0;

const title = computed(
  () => current.value?.name || (archived.value ? 'Архив категорий' : 'Категории практик'),
);
const description = computed(() =>
  current.value
    ? practiceCount(current.value.documents_count)
    : archived.value
      ? 'Восстановите нужную категорию, чтобы снова видеть её в подборках.'
      : 'Собирайте отдельные занятия по цели, уровню или времени дня. Одна практика может быть в нескольких категориях.',
);
const plural = new Intl.PluralRules('ru');
function practiceCount(count: number) {
  const forms: Record<string, string> = { one: 'практика', few: 'практики', many: 'практик' };
  return `${count} ${forms[plural.select(count)] || 'практик'}`;
}
const failure = (error: unknown) =>
  error instanceof Error ? error.message : 'Не удалось выполнить действие. Попробуйте ещё раз.';

async function load() {
  const serial = ++request;
  const id = folderId.value;
  const page = pageNumber.value;
  loading.value = true;
  loadError.value = '';
  try {
    const response = await api<{ data: Category[] }>(
      `${endpoint}?archived=${archived.value ? '1' : '0'}${id ? '' : '&include_previews=1'}`,
    );
    if (serial !== request) return;
    categories.value = response.data;
    if (id && current.value) {
      const query = new URLSearchParams({
        'folder_ids[]': id,
        kind: 'practice',
        archive: 'active',
        include_cycle_documents: '0',
        page: String(page),
      });
      const result = await api<CategoryPage>(`/api/practices?${query}`);
      if (serial !== request) return;
      details.value = result;
      if (page > result.last_page) router.visit(folderUrl(id, result.last_page), { replace: true });
    } else details.value = null;
  } catch (error) {
    if (serial === request) loadError.value = failure(error);
  } finally {
    if (serial === request) loading.value = false;
  }
}
watch(
  [folderId, archived, pageNumber],
  () => {
    details.value = null;
    actionError.value = '';
    notice.value = '';
    void load();
  },
  { immediate: true },
);

function openName(category?: Category) {
  name.value = category?.name || '';
  dialogError.value = '';
  nameDialog.value = { category };
}
async function saveName() {
  if (!nameDialog.value || !name.value.trim() || busy.value) return;
  const category = nameDialog.value.category;
  busy.value = true;
  dialogError.value = '';
  try {
    await api(category ? `${endpoint}/${category.id}` : endpoint, {
      method: category ? 'PATCH' : 'POST',
      body: JSON.stringify({
        name: name.value.trim(),
        ...(category ? { expected_revision: category.revision } : {}),
      }),
    });
    nameDialog.value = null;
    notice.value = category ? 'Название изменено.' : 'Категория создана.';
    await load();
  } catch (error) {
    dialogError.value = failure(error);
  } finally {
    busy.value = false;
  }
}
function ask(action: Confirmation) {
  dialogError.value = '';
  confirmAction.value = action;
}
async function changeArchive(action: Confirmation, restore = false) {
  if (busy.value) return;
  busy.value = true;
  dialogError.value = '';
  actionError.value = '';
  try {
    if (action.type === 'category') {
      const { category } = action;
      await api(`${endpoint}/${category.id}`, {
        method: 'PATCH',
        body: JSON.stringify({ expected_revision: category.revision, archived: !restore }),
      });
      confirmAction.value = null;
      notice.value = restore ? 'Категория восстановлена.' : 'Категория перемещена в архив.';
      if (folderId.value === category.id) router.visit(baseUrl);
      else await load();
    } else {
      await api(`/api/practices/${action.practice.id}/archive`, {
        method: 'POST',
        body: JSON.stringify({ expected_revision: action.practice.revision }),
      });
      confirmAction.value = null;
      notice.value = 'Практика перемещена в архив.';
      await load();
    }
  } catch (error) {
    if (restore) actionError.value = failure(error);
    else dialogError.value = failure(error);
  } finally {
    busy.value = false;
  }
}
async function duplicate(practice: CategoryPractice) {
  if (busy.value) return;
  busy.value = true;
  actionError.value = '';
  try {
    const { data } = await api<{ data: { id: string } }>(`/api/practices/${practice.id}/duplicate`, {
      method: 'POST',
      body: JSON.stringify({ expected_revision: practice.revision }),
    });
    router.visit(`/practices/${data.id}/edit`);
  } catch (error) {
    actionError.value = failure(error);
  } finally {
    busy.value = false;
  }
}
function openPicker(category: Category) {
  pickerError.value = '';
  picker.value = category;
}
async function assign(category: Category, practice: PracticeChoice, remove = false) {
  if (busy.value) return;
  busy.value = true;
  actionError.value = '';
  pickerError.value = '';
  try {
    await api(`${endpoint}/${category.id}/documents`, {
      method: 'POST',
      body: JSON.stringify({
        expected_revision: category.revision,
        documents: [{ id: practice.id, expected_revision: practice.revision }],
        remove,
      }),
    });
    picker.value = null;
    notice.value = remove ? 'Практика убрана из этой категории.' : `Практика добавлена в «${category.name}».`;
    await load();
  } catch (error) {
    if (picker.value) pickerError.value = failure(error);
    else actionError.value = failure(error);
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <Head :title="title" />
  <AppShell section="practices">
    <PageHeading eyebrow="Порядок в практике" :title="title" :description="description" />
    <PracticeNavigation active="categories" />

    <div class="category-toolbar">
      <Link v-if="folderId || archived" :href="baseUrl" class="btn btn-secondary">
        <ArrowLeft :size="16" /> Все категории
      </Link>
      <button v-if="!folderId && !archived" class="btn btn-primary" :disabled="busy" @click="openName()">
        <Plus :size="17" /> Создать категорию
      </button>
      <button v-if="current" class="btn btn-primary" :disabled="busy" @click="openPicker(current)">
        <Plus :size="17" /> Добавить практику
      </button>
      <div class="category-toolbar-menu">
        <ActionMenu v-if="!folderId && !archived" label="Действия с категориями">
          <Link :href="`${baseUrl}?archive=1`"><Archive :size="16" /> Архив категорий</Link>
        </ActionMenu>
        <ActionMenu v-else-if="current" :label="`Действия с категорией: ${current.name}`">
          <button :disabled="busy" @click="openName(current)"><Pencil :size="16" /> Переименовать</button>
          <button :disabled="busy" @click="ask({ type: 'category', category: current })">
            <Archive :size="16" /> В архив
          </button>
        </ActionMenu>
      </div>
    </div>

    <p v-if="loadError" class="error-banner" role="alert">
      {{ loadError }}
      <button class="btn btn-ghost" @click="load">Обновить</button>
    </p>
    <p v-if="actionError" class="error-banner" role="alert">
      {{ actionError }}
      <button
        class="btn btn-ghost"
        @click="
          actionError = '';
          load();
        "
      >
        Обновить
      </button>
    </p>
    <p v-if="notice" class="success-banner" role="status">{{ notice }}</p>

    <div
      v-if="loading && !loadError && (folderId ? !details : !categories.length)"
      class="category-empty card"
      role="status"
    >
      Загружаем {{ folderId ? 'практики' : 'категории' }}…
    </div>
    <template v-else-if="!loadError && folderId">
      <template v-if="current && details">
        <div v-if="details.data.length" class="category-detail-grid">
          <PracticeCategoryCard
            v-for="practice in details.data"
            :key="practice.id"
            :item="practice"
            :busy="busy"
            @categories="categoryPractice = practice"
            @duplicate="duplicate(practice)"
            @remove="assign(current, practice, true)"
            @archive="ask({ type: 'practice', practice })"
          />
        </div>
        <div v-else class="category-empty card">
          <span class="category-glyph"><FolderOpen :size="23" /></span>
          <p>В этой категории пока нет практик.</p>
          <button class="btn btn-secondary" :disabled="busy" @click="openPicker(current)">
            <Plus :size="17" /> Добавить практику
          </button>
        </div>
        <nav v-if="details.last_page > 1" class="category-pagination" aria-label="Страницы категории">
          <Link v-if="pageNumber > 1" class="btn btn-secondary" :href="folderUrl(current.id, pageNumber - 1)">
            <ChevronLeft :size="16" /> Назад
          </Link>
          <span class="muted">{{ pageNumber }} из {{ details.last_page }}</span>
          <Link
            v-if="pageNumber < details.last_page"
            class="btn btn-secondary"
            :href="folderUrl(current.id, pageNumber + 1)"
          >
            Дальше <ChevronRight :size="16" />
          </Link>
        </nav>
      </template>
      <div v-else class="category-empty card">Категория недоступна или перемещена в архив.</div>
    </template>
    <div v-else-if="!loadError && categories.length" class="category-shelves" :aria-busy="loading">
      <section
        v-for="category in categories"
        :key="category.id"
        class="category-shelf"
        :aria-labelledby="`category-${category.id}`"
      >
        <div class="category-shelf-header">
          <span class="category-glyph" aria-hidden="true"
            ><Archive v-if="archived" :size="22" /><Layers3 v-else :size="22" :stroke-width="1.6"
          /></span>
          <div class="category-shelf-copy">
            <h2 :id="`category-${category.id}`">
              <Link v-if="!archived" :href="folderUrl(category.id)" class="category-shelf-link">
                <span>{{ category.name }}</span
                ><ChevronRight :size="18" aria-hidden="true" />
              </Link>
              <span v-else>{{ category.name }}</span>
            </h2>
            <p>{{ practiceCount(category.documents_count) }}</p>
          </div>
          <ActionMenu :label="`Действия с категорией: ${category.name}`">
            <button
              v-if="archived"
              :disabled="busy"
              @click="changeArchive({ type: 'category', category }, true)"
            >
              <RotateCcw :size="16" /> Восстановить
            </button>
            <template v-else>
              <button :disabled="busy" @click="openPicker(category)">
                <FolderPlus :size="16" /> Добавить практику
              </button>
              <button :disabled="busy" @click="openName(category)">
                <Pencil :size="16" /> Переименовать
              </button>
              <button :disabled="busy" @click="ask({ type: 'category', category })">
                <Archive :size="16" /> В архив
              </button>
            </template>
          </ActionMenu>
        </div>
        <div
          v-if="category.preview_documents?.length"
          class="category-rail"
          :aria-label="`Практики: ${category.name}`"
        >
          <PracticeCategoryCard
            v-for="practice in category.preview_documents"
            :key="practice.id"
            :item="practice"
            :readonly="archived"
            :busy="busy"
            @categories="categoryPractice = practice"
            @duplicate="duplicate(practice)"
            @remove="assign(category, practice, true)"
            @archive="ask({ type: 'practice', practice })"
          />
          <Link
            v-if="!archived && category.documents_count > category.preview_documents.length"
            :href="folderUrl(category.id)"
            class="category-rail-more"
          >
            <span class="category-glyph" aria-hidden="true"><ChevronRight :size="21" /></span>
            Все практики
          </Link>
        </div>
        <div v-else-if="!archived" class="category-empty-shelf">
          <span>В этой категории пока пусто</span>
          <button type="button" class="btn btn-secondary" :disabled="busy" @click="openPicker(category)">
            <Plus :size="16" /> Добавить практику
          </button>
        </div>
      </section>
    </div>
    <div v-else-if="!loadError" class="category-empty card">
      <span class="category-glyph" aria-hidden="true"><FolderOpen :size="23" /></span>
      <template v-if="archived"><p>В архиве пока нет категорий.</p></template>
      <template v-else>
        <p><strong>Здесь будут ваши категории</strong></p>
        <p class="muted">
          Например, «Для начинающих», «Силовые» или «Вечерние». Создайте категорию и добавьте в неё занятия из
          своих практик.
        </p>
      </template>
    </div>

    <Modal
      :open="!!nameDialog"
      :title="nameDialog?.category ? 'Переименовать категорию' : 'Создать категорию'"
      @close="!busy && (nameDialog = null)"
    >
      <form id="category-name-form" @submit.prevent="saveName">
        <label class="category-name-field">
          Название
          <input v-model="name" class="input" maxlength="160" placeholder="Например, Вечерние" required />
        </label>
        <p v-if="dialogError" class="error-banner" role="alert">{{ dialogError }}</p>
      </form>
      <template #footer>
        <button class="btn btn-secondary" :disabled="busy" @click="nameDialog = null">Отмена</button>
        <button
          type="submit"
          form="category-name-form"
          class="btn btn-primary"
          :disabled="busy || !name.trim()"
        >
          {{ nameDialog?.category ? 'Сохранить' : 'Создать' }}
        </button>
      </template>
    </Modal>

    <Modal :open="!!confirmAction" title="Переместить в архив?" @close="!busy && (confirmAction = null)">
      <p v-if="confirmAction?.type === 'category'" class="muted">
        «{{ confirmAction.category.name }}» исчезнет из активных категорий. Практики сохранятся, а категорию
        можно будет восстановить.
      </p>
      <p v-else-if="confirmAction" class="muted">
        «{{ confirmAction.practice.title }}» можно будет восстановить в архиве практик.
      </p>
      <p v-if="dialogError" class="error-banner" role="alert">{{ dialogError }}</p>
      <template #footer>
        <button class="btn btn-secondary" :disabled="busy" @click="confirmAction = null">Отмена</button>
        <button
          class="btn btn-primary"
          :disabled="busy"
          @click="confirmAction && changeArchive(confirmAction)"
        >
          В архив
        </button>
      </template>
    </Modal>

    <PracticePicker
      v-if="picker"
      :key="picker.id"
      :open="true"
      :title="`Добавить в «${picker.name}»`"
      description="Выберите практику. Она останется в общем списке и в своих других категориях."
      standalone
      :exclude-folder="picker.id"
      :busy="busy"
      :external-error="pickerError"
      @close="picker = null"
      @choose="assign(picker, $event)"
    />
    <PracticeCategoryDialog
      v-if="categoryPractice"
      :practice="categoryPractice"
      @close="categoryPractice = null"
      @changed="load"
    />
  </AppShell>
</template>

<style scoped>
.category-toolbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 9px;
  margin-bottom: var(--section-gap);
}
.category-toolbar-menu {
  margin-left: auto;
}
.category-shelves {
  display: grid;
  gap: clamp(21px, 3vw, 36px);
  min-width: 0;
}
.category-shelf {
  min-width: 0;
  display: grid;
  gap: 10px;
}
.category-shelf + .category-shelf {
  padding-top: clamp(18px, 2.4vw, 28px);
  border-top: 1px solid var(--line);
}
.category-shelf-header {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}
.category-glyph {
  display: inline-grid;
  place-items: center;
  flex: none;
  width: 43px;
  height: 43px;
  border-radius: 15px;
  color: var(--accent-dark);
  background: var(--accent-soft);
}
.category-shelf-copy {
  flex: 1;
  min-width: 0;
}
.category-shelf h2 {
  margin: 0;
  font-size: clamp(17px, 2.3vw, 22px);
  line-height: 1.25;
  letter-spacing: -0.025em;
  overflow-wrap: anywhere;
}
.category-shelf-link {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 100%;
  color: var(--text);
  text-decoration: none;
}
.category-shelf-link:hover {
  color: var(--accent-dark);
}
.category-shelf-link svg {
  flex: none;
  color: var(--accent-dark);
}
.category-shelf-copy p {
  color: var(--muted);
  margin: 3px 0 0;
  font-size: 12px;
}
.category-rail {
  display: grid;
  grid-auto-flow: column;
  grid-auto-columns: clamp(174px, 44%, 240px);
  gap: 12px;
  min-width: 0;
  overflow-x: auto;
  padding: 2px 1px 10px;
  scroll-snap-type: x proximity;
  scrollbar-width: thin;
  scrollbar-color: var(--line) transparent;
}
.category-rail-more {
  min-width: 0;
  padding: 16px;
  border: 1px dashed var(--line);
  border-radius: 21px;
  display: grid;
  align-content: center;
  justify-items: center;
  gap: 12px;
  color: var(--accent-dark);
  background: var(--surface);
  font-size: 14px;
  font-weight: 650;
  text-align: center;
  text-decoration: none;
  scroll-snap-align: start;
}
.category-rail-more:hover {
  border-color: var(--accent-dark);
}
.category-detail-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 215px), 1fr));
  gap: 12px;
}
.category-empty-shelf {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 12px 14px;
  border: 1px dashed var(--line);
  border-radius: var(--panel-radius);
  background: var(--surface);
  color: var(--muted);
  font-size: 13px;
}
.category-empty {
  min-height: 150px;
  display: grid;
  gap: 10px;
  justify-items: center;
  align-content: center;
  padding: 24px;
  text-align: center;
}
.category-empty p {
  margin: 0;
  max-width: 52ch;
}
.category-empty .btn {
  margin-top: 5px;
}
.category-name-field {
  display: grid;
  gap: 8px;
}
.category-pagination {
  margin-top: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
}
@media (max-width: 580px) {
  .category-detail-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 360px) {
  .category-detail-grid {
    grid-template-columns: 1fr;
  }
}
</style>
