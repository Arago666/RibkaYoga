<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import {
  Plus,
  Search,
  Layers3,
  ArrowRight,
  Pencil,
  Archive,
  RotateCcw,
  ChevronLeft,
  ChevronRight,
  FolderInput,
} from '@lucide/vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import CollectionEditor from './CollectionEditor.vue';
import CollectionFolderTree from './CollectionFolderTree.vue';
import CollectionFolderEditor from './CollectionFolderEditor.vue';
import CollectionFolderSelect from './CollectionFolderSelect.vue';
import type { CollectionFolder } from '../types';
import { errorText, type Collection, type Page } from '../../catalog/types';
const initial = new URL(window.location.href);
const query = ref(initial.searchParams.get('collection_q') || ''),
  archive = ref(initial.searchParams.get('collection_archive') || 'active');
const selectedFolder = ref(initial.searchParams.get('collection_folder') || 'all'),
  includeDescendants = ref(initial.searchParams.get('collection_descendants') === '1'),
  folders = ref<CollectionFolder[]>([]),
  folderError = ref(''),
  foldersLoading = ref(false),
  folderEditorOpen = ref(false),
  editingFolder = ref<CollectionFolder | null>(null),
  parentFolderId = ref<string | null>(null),
  movingCollection = ref<Collection | null>(null),
  moveFolderId = ref(''),
  moveError = ref('');
const page = ref(1),
  result = ref<Page<Collection> | null>(null),
  loading = ref(false),
  error = ref(''),
  busy = ref(false);
const editorOpen = ref(false),
  editing = ref<Collection | null>(null),
  creatingPractice = ref<Collection | null>(null);
const practiceTitle = ref(''),
  practiceTheme = ref(''),
  templateId = ref(''),
  createError = ref('');
interface Template {
  id: string;
  title: string;
}
const templates = ref<Template[]>([]),
  templatePage = ref(0),
  templateLastPage = ref(1),
  templatesLoading = ref(false);
let sequence = 0;
let timer: ReturnType<typeof setTimeout> | undefined;
async function load() {
  const request = ++sequence;
  loading.value = true;
  error.value = '';
  const url = new URL(window.location.href);
  query.value ? url.searchParams.set('collection_q', query.value) : url.searchParams.delete('collection_q');
  archive.value !== 'active'
    ? url.searchParams.set('collection_archive', archive.value)
    : url.searchParams.delete('collection_archive');
  selectedFolder.value !== 'all'
    ? url.searchParams.set('collection_folder', selectedFolder.value)
    : url.searchParams.delete('collection_folder');
  includeDescendants.value
    ? url.searchParams.set('collection_descendants', '1')
    : url.searchParams.delete('collection_descendants');
  window.history.replaceState(window.history.state, '', url);
  try {
    const response = await api<Page<Collection>>(
      `/api/collections?${new URLSearchParams({
        q: query.value,
        archive: archive.value,
        page: String(page.value),
        ...(selectedFolder.value !== 'all' ? { folder_id: selectedFolder.value } : {}),
        include_descendants: includeDescendants.value ? '1' : '0',
      })}`,
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
watch(query, () => {
  clearTimeout(timer);
  timer = setTimeout(() => {
    page.value = 1;
    void load();
  }, 250);
});
watch(archive, () => {
  page.value = 1;
  void load();
});
watch([selectedFolder, includeDescendants], () => {
  page.value = 1;
  void load();
});
async function loadFolders() {
  foldersLoading.value = true;
  folderError.value = '';
  try {
    folders.value = (await api<{ data: CollectionFolder[] }>('/api/collection-folders')).data;
    if (
      !['all', 'root'].includes(selectedFolder.value) &&
      !folders.value.some((folder) => folder.id === selectedFolder.value)
    )
      selectedFolder.value = 'all';
  } catch (failure) {
    folderError.value = errorText(failure);
  } finally {
    foldersLoading.value = false;
  }
}
async function refresh() {
  await Promise.all([load(), loadFolders()]);
}
onMounted(refresh);
defineExpose({ refresh });
onBeforeUnmount(() => {
  clearTimeout(timer);
  sequence++;
});
function go(value: number) {
  page.value = value;
  void load();
}
async function edit(collection: Collection) {
  busy.value = true;
  error.value = '';
  try {
    const response = await api<{ data: Collection }>(`/api/collections/${collection.id}`);
    editing.value = response.data;
    editorOpen.value = true;
  } catch (failure) {
    error.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}
function create() {
  editing.value = null;
  editorOpen.value = true;
}
function createFolder(parentId: string | null) {
  editingFolder.value = null;
  parentFolderId.value = parentId;
  folderEditorOpen.value = true;
}
function editFolder(folder: CollectionFolder) {
  editingFolder.value = folder;
  parentFolderId.value = folder.parent_id;
  folderEditorOpen.value = true;
}
async function removeFolder(folder: CollectionFolder) {
  if (
    !window.confirm(
      `Удалить пустую папку «${folder.name}»? Подборки и подпапки перед удалением нужно переместить.`,
    )
  )
    return;
  busy.value = true;
  folderError.value = '';
  try {
    await api(`/api/collection-folders/${folder.id}`, {
      method: 'DELETE',
      body: JSON.stringify({ expected_revision: folder.revision }),
    });
    await loadFolders();
  } catch (failure) {
    folderError.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}
async function folderSaved(folder: CollectionFolder) {
  await loadFolders();
  selectedFolder.value = folder.id;
  await load();
}
function openMove(collection: Collection) {
  movingCollection.value = collection;
  moveFolderId.value = collection.folder_id || '';
  moveError.value = '';
  void loadFolders();
}
async function moveCollection() {
  if (!movingCollection.value || busy.value) return;
  busy.value = true;
  moveError.value = '';
  try {
    await api(`/api/collections/${movingCollection.value.id}`, {
      method: 'PATCH',
      body: JSON.stringify({
        folder_id: moveFolderId.value || null,
        expected_revision: movingCollection.value.revision,
      }),
    });
    movingCollection.value = null;
    await refresh();
  } catch (failure) {
    moveError.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}
async function changeArchive(collection: Collection) {
  if (
    !collection.archived_at &&
    !window.confirm(`Убрать подборку «${collection.name}» в архив? Сохранённые практики не изменятся.`)
  )
    return;
  busy.value = true;
  error.value = '';
  try {
    await api(`/api/collections/${collection.id}/${collection.archived_at ? 'restore' : 'archive'}`, {
      method: 'POST',
      body: JSON.stringify({ expected_revision: collection.revision }),
    });
    await refresh();
  } catch (failure) {
    error.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}
async function loadTemplates() {
  if (templatesLoading.value || templatePage.value >= templateLastPage.value) return;
  templatesLoading.value = true;
  try {
    const response = await api<Page<Template>>(
      `/api/practices?kind=template&archive=active&page=${templatePage.value + 1}`,
    );
    templates.value.push(...response.data);
    templatePage.value = response.current_page;
    templateLastPage.value = response.last_page;
  } catch (failure) {
    createError.value = errorText(failure);
  } finally {
    templatesLoading.value = false;
  }
}
function openPractice(collection: Collection) {
  creatingPractice.value = collection;
  practiceTitle.value = collection.name;
  practiceTheme.value = '';
  templateId.value = '';
  createError.value = '';
  templates.value = [];
  templatePage.value = 0;
  templateLastPage.value = 1;
  void loadTemplates();
}
async function createPractice() {
  if (!creatingPractice.value || busy.value) return;
  busy.value = true;
  createError.value = '';
  try {
    const response = await api<{ data: { id: string } }>('/api/practices', {
      method: 'POST',
      body: JSON.stringify({
        title: practiceTitle.value.trim(),
        theme: practiceTheme.value || null,
        ...(templateId.value ? { template_id: templateId.value } : {}),
      }),
    });
    router.visit(`/practices/${response.data.id}/edit?collection=${creatingPractice.value.id}`);
  } catch (failure) {
    createError.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <div class="collections-header">
    <div>
      <h2>Ваши подборки</h2>
    </div>
    <button class="btn btn-primary" @click="create"><Plus :size="18" /> Новая подборка</button>
  </div>
  <div class="toolbar collections-toolbar">
    <div class="collection-search">
      <Search :size="19" /><input
        v-model="query"
        class="input"
        placeholder="Найти подборку"
        aria-label="Поиск подборок"
      />
    </div>
    <select v-model="archive" class="select" aria-label="Состояние подборок">
      <option value="active">Активные</option>
      <option value="archived">В архиве</option>
    </select>
  </div>
  <div v-if="error" class="error-banner" role="alert">
    {{ error }} <button class="btn btn-ghost" @click="load">Повторить</button>
  </div>
  <div v-if="folderError" class="error-banner" role="alert">
    {{ folderError }} <button class="btn btn-ghost" @click="loadFolders">Обновить папки</button>
  </div>
  <div class="collections-with-folders">
    <CollectionFolderTree
      :folders="folders"
      :selected="selectedFolder"
      :disabled="busy || foldersLoading"
      @select="selectedFolder = $event"
      @create="createFolder"
      @edit="editFolder"
      @remove="removeFolder"
    />
    <div class="folder-content">
      <div class="folder-current">
        <span>{{
          selectedFolder === 'all'
            ? 'Все подборки'
            : selectedFolder === 'root'
              ? 'Без папки'
              : folders.find((folder) => folder.id === selectedFolder)?.path
        }}</span>
        <label v-if="!['all', 'root'].includes(selectedFolder)"
          ><input v-model="includeDescendants" type="checkbox" /> С подпапками</label
        >
      </div>
      <p v-if="loading" class="muted" role="status">Загружаем подборки…</p>
      <div v-else-if="result?.data.length" class="collection-grid">
        <article v-for="collection in result.data" :key="collection.id" class="card collection-card">
          <div class="collection-cover">
            <template v-if="collection.items?.length"
              ><img
                v-for="card in collection.items.slice(0, 3)"
                :key="card.id"
                :src="card.thumbnail_url || card.image_url"
                :alt="card.name"
                loading="lazy" /></template
            ><Layers3 v-else :size="36" :stroke-width="1.5" aria-hidden="true" />
          </div>
          <div class="collection-content">
            <span class="collection-count"
              >{{
                collection.items_count ?? collection.asana_ids?.length ?? collection.items?.length ?? 0
              }}
              карточек</span
            >
            <h3>{{ collection.name }}</h3>
            <p v-if="collection.folder_path" class="collection-folder-path" :title="collection.folder_path">
              {{ collection.folder_path }}
            </p>
            <p v-if="collection.description">{{ collection.description }}</p>
            <button
              v-if="!collection.archived_at"
              class="btn btn-primary collection-start"
              :disabled="busy"
              @click="openPractice(collection)"
            >
              Создать практику <ArrowRight :size="17" />
            </button>
            <div class="collection-actions">
              <button class="btn btn-ghost" :disabled="busy" @click="edit(collection)">
                <Pencil :size="15" /> Изменить</button
              ><button class="btn btn-ghost" :disabled="busy" @click="openMove(collection)">
                <FolderInput :size="15" /> В папку
              </button>
              <button class="btn btn-ghost" :disabled="busy" @click="changeArchive(collection)">
                <RotateCcw v-if="collection.archived_at" :size="15" /><Archive v-else :size="15" />{{
                  collection.archived_at ? 'Восстановить' : 'В архив'
                }}
              </button>
            </div>
          </div>
        </article>
      </div>
      <div v-else-if="!error" class="empty-state collection-empty">
        <Layers3 :size="42" :stroke-width="1.4" aria-hidden="true" />
        <h3>
          {{ query || archive === 'archived' ? 'Подборки не найдены' : 'Соберите свою первую подборку' }}
        </h3>
        <p>
          {{
            query
              ? 'Попробуйте другое название.'
              : archive === 'archived'
                ? 'Здесь будут архивные подборки.'
                : 'Выберите асаны для мобильности, гибкости или любой другой темы.'
          }}
        </p>
        <button v-if="!query && archive === 'active'" class="btn btn-primary" @click="create">
          <Plus :size="17" /> Создать подборку
        </button>
      </div>
      <div v-if="result && result.last_page > 1" class="collection-pagination">
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
    </div>
  </div>
  <CollectionEditor
    :open="editorOpen"
    :collection="editing"
    :default-folder-id="['all', 'root'].includes(selectedFolder) ? null : selectedFolder"
    @close="editorOpen = false"
    @saved="refresh"
  />
  <CollectionFolderEditor
    :open="folderEditorOpen"
    :folder="editingFolder"
    :folders="folders"
    :parent-id="parentFolderId"
    @close="folderEditorOpen = false"
    @saved="folderSaved"
  />
  <Modal
    :open="Boolean(movingCollection)"
    title="Переместить подборку"
    @close="!busy && (movingCollection = null)"
  >
    <form id="move-collection" class="new-practice-form" @submit.prevent="moveCollection">
      <p>{{ movingCollection?.name }}</p>
      <div v-if="moveError" class="error-banner" role="alert">{{ moveError }}</div>
      <div class="field">
        <label class="label" for="move-collection-folder">Папка</label>
        <CollectionFolderSelect
          id="move-collection-folder"
          v-model="moveFolderId"
          :folders="folders"
          :disabled="busy || foldersLoading"
        />
      </div>
    </form>
    <template #footer
      ><button class="btn btn-secondary" :disabled="busy" @click="movingCollection = null">Отмена</button>
      <button class="btn btn-primary" type="submit" form="move-collection" :disabled="busy || foldersLoading">
        {{ busy ? 'Перемещаем…' : 'Переместить' }}
      </button></template
    >
  </Modal>
  <Modal
    :open="Boolean(creatingPractice)"
    title="Практика из подборки"
    @close="!busy && (creatingPractice = null)"
  >
    <form id="practice-from-collection" class="new-practice-form" @submit.prevent="createPractice">
      <p class="muted">Подборка «{{ creatingPractice?.name }}» откроется рядом с вашим листом.</p>
      <div v-if="createError" class="error-banner" role="alert">{{ createError }}</div>
      <div class="field">
        <label class="label" for="collection-practice-name">Название практики</label
        ><input
          id="collection-practice-name"
          v-model="practiceTitle"
          class="input"
          required
          maxlength="160"
          :disabled="busy"
        />
      </div>
      <div class="field">
        <label class="label" for="collection-practice-theme">Тема · необязательно</label
        ><input
          id="collection-practice-theme"
          v-model="practiceTheme"
          class="input"
          maxlength="240"
          :disabled="busy"
        />
      </div>
      <div class="field">
        <label class="label" for="collection-practice-template">Начать с</label
        ><select id="collection-practice-template" v-model="templateId" class="select" :disabled="busy">
          <option value="">Пустая практика — 5 блоков</option>
          <option v-for="template in templates" :key="template.id" :value="template.id">
            {{ template.title }}
          </option></select
        ><button
          v-if="templatePage < templateLastPage"
          class="btn btn-ghost"
          type="button"
          :disabled="templatesLoading"
          @click="loadTemplates"
        >
          {{ templatesLoading ? 'Загружаем…' : 'Загрузить ещё шаблоны' }}
        </button>
      </div>
    </form>
    <template #footer
      ><button class="btn btn-secondary" :disabled="busy" @click="creatingPractice = null">Отмена</button
      ><button
        class="btn btn-primary"
        type="submit"
        form="practice-from-collection"
        :disabled="busy || !practiceTitle.trim()"
      >
        {{ busy ? 'Создаём…' : 'Открыть конструктор' }} <ArrowRight :size="17" /></button
    ></template>
  </Modal>
</template>
<style scoped>
.collections-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 20px;
  margin: 26px 0 20px;
}
.collections-header h2 {
  font-size: 1.2rem;
  margin: 0 0 7px;
}
.collections-header p {
  font-size: 0.87rem;
  margin: 0;
  line-height: 1.5;
}
.collections-header > .btn {
  flex: none;
}
.collections-toolbar {
  margin-bottom: 22px;
}
.collections-toolbar > .select {
  width: 160px;
  flex: none;
}
.collection-search {
  position: relative;
  flex: 1;
  max-width: 500px;
}
.collection-search svg {
  position: absolute;
  left: 14px;
  top: 14px;
  color: #687568;
}
.collection-search .input {
  width: 100%;
  padding-left: 42px;
}
.collection-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20px;
}
.collection-card {
  padding: 0 !important;
  overflow: hidden;
}
.collection-cover {
  height: 150px;
  background: #f6f9f3;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 18px;
  padding: 25px;
  color: #91a789;
}
.collection-cover img {
  min-width: 0;
  flex: 1;
  max-width: 29%;
  height: 95px;
  object-fit: contain;
}
.collection-content {
  padding: 21px;
}
.collection-count {
  font-size: 0.7rem;
  color: #687568;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.collection-content h3 {
  font-size: 1.1rem;
  margin: 9px 0;
}
.collection-content > p {
  font-size: 0.83rem;
  color: #687568;
  line-height: 1.5;
  min-height: 2.9em;
  margin: 0 0 18px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.collection-start {
  width: 100%;
  justify-content: space-between;
}
.collection-actions {
  display: flex;
  justify-content: space-between;
  gap: 5px;
  margin: 10px -7px -7px;
  flex-wrap: wrap;
}
.collection-actions > .btn {
  font-size: 0.77rem;
  padding: 8px;
}
.collection-empty {
  padding: 65px 20px;
}
.collection-empty > svg {
  color: #86a77b;
  margin-bottom: 15px;
}
.collection-empty h3 {
  margin: 0;
  font-size: 1.2rem;
}
.collection-empty p {
  max-width: 380px;
  line-height: 1.6;
  margin: 12px auto 22px;
}
.collection-pagination {
  display: flex;
  gap: 20px;
  align-items: center;
  justify-content: center;
  margin-top: 30px;
}
.collection-pagination span {
  font-size: 0.82rem;
  color: #687568;
}
.new-practice-form {
  display: grid;
  gap: 17px;
}
.collections-with-folders {
  display: grid;
  grid-template-columns: 300px minmax(0, 1fr);
  gap: 20px;
}
.folder-content {
  min-width: 0;
}
.folder-current {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: center;
  margin: 0 0 16px;
  color: #66725f;
  font-size: 0.82rem;
}
.folder-current > label {
  display: flex;
  gap: 6px;
  align-items: center;
  flex: none;
}
.collection-content > .collection-folder-path {
  min-height: 0;
  margin: 0 0 10px;
  font-size: 0.72rem;
  color: #768a69;
}
@media (max-width: 1000px) {
  .collections-with-folders {
    grid-template-columns: 260px minmax(0, 1fr);
  }
  .collection-grid {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 800px) {
  .collections-with-folders {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 650px) {
  .collections-header {
    align-items: flex-start;
    flex-direction: column;
  }
  .collection-grid {
    grid-template-columns: 1fr;
  }
  .collections-toolbar {
    flex-wrap: wrap;
  }
  .collection-search {
    max-width: none;
    min-width: 100%;
  }
  .collections-toolbar > .select {
    width: 100%;
  }
}
</style>
