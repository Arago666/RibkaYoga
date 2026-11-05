<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Archive, ArrowUpRight, Check, FileText, Folder, Plus } from '@lucide/vue';
import ActionMenu from '@/shared/ActionMenu.vue';
import BrandMotif from '@/shared/BrandMotif.vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
interface FolderItem {
  id: string;
  name: string;
  revision: number;
  archived_at: string | null;
  documents_count?: number;
  items_count?: number;
}
export interface ProgramItem {
  id: string;
  revision: number;
  type: 'practice' | 'cycle';
  title?: string;
}
const props = withDefaults(
  defineProps<{
    documents?: { id: string; revision: number }[];
    compact?: boolean;
    mode?: 'category' | 'program';
    programItems?: ProgramItem[];
    selectedFolder?: string;
  }>(),
  { documents: () => [], compact: false, mode: 'category', programItems: () => [], selectedFolder: '' },
);
const emit = defineEmits<{ changed: []; select: [id: string] }>();
const items = ref<FolderItem[]>([]),
  busy = ref(false),
  error = ref(''),
  notice = ref(''),
  archived = ref(false),
  creating = ref(false),
  name = ref(''),
  dropTarget = ref<string | null>(null);
const endpoint = computed(() => (props.mode === 'program' ? 'program-folders' : 'practice-folders'));
const hasSelection = computed(
  () => !!(props.mode === 'program' ? props.programItems.length : props.documents.length),
);
async function load() {
  try {
    items.value = (
      await api<{ data: FolderItem[] }>(`/api/${endpoint.value}?archived=${archived.value ? 1 : 0}`)
    ).data;
  } catch (e) {
    error.value = failure(e);
  }
}
function failure(e: unknown) {
  return e instanceof Error ? e.message : 'Не удалось изменить папку.';
}
async function create() {
  if (busy.value || !name.value.trim()) return;
  busy.value = true;
  error.value = '';
  try {
    await api(`/api/${endpoint.value}`, {
      method: 'POST',
      body: JSON.stringify({ name: name.value.trim() }),
    });
    name.value = '';
    creating.value = false;
    await load();
    emit('changed');
  } catch (e) {
    error.value = failure(e);
  } finally {
    busy.value = false;
  }
}
async function change(folder: FolderItem, values: Record<string, unknown>) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await api(`/api/${endpoint.value}/${folder.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ expected_revision: folder.revision, ...values }),
    });
    await load();
    if (values.archived === true && props.selectedFolder === folder.id) emit('select', '');
    emit('changed');
  } catch (e) {
    error.value = failure(e);
  } finally {
    busy.value = false;
  }
}
function rename(folder: FolderItem) {
  const value = window.prompt('Название папки', folder.name);
  if (value?.trim()) void change(folder, { name: value.trim() });
}
async function assign(folder: FolderItem, remove = false, dropped?: ProgramItem[]) {
  const records =
    dropped ||
    (props.mode === 'program'
      ? props.programItems
      : props.documents.map((item) => ({ ...item, type: 'practice' as const })));
  if (busy.value || !records.length) return;
  busy.value = true;
  error.value = '';
  notice.value = '';
  try {
    await api(`/api/${endpoint.value}/${folder.id}/${props.mode === 'program' ? 'items' : 'documents'}`, {
      method: 'POST',
      body: JSON.stringify({
        expected_revision: folder.revision,
        remove,
        [props.mode === 'program' ? 'items' : 'documents']: records.map((item) => ({
          id: item.id,
          expected_revision: item.revision,
          ...(props.mode === 'program' ? { type: item.type } : {}),
        })),
      }),
    });
    await load();
    notice.value = remove
      ? 'Документ убран из папки. Сам документ сохранён.'
      : `Добавлено в «${folder.name}».`;
    emit('changed');
  } catch (e) {
    error.value = failure(e);
  } finally {
    busy.value = false;
  }
}
function drop(event: DragEvent, folder: FolderItem) {
  dropTarget.value = null;
  try {
    const value = JSON.parse(
      event.dataTransfer?.getData('application/ribka-document') || 'null',
    ) as ProgramItem | null;
    if (
      !value ||
      !['practice', 'cycle'].includes(value.type) ||
      typeof value.id !== 'string' ||
      !Number.isInteger(value.revision) ||
      value.revision < 1 ||
      (props.mode === 'category' && value.type !== 'practice')
    )
      return;
    void assign(folder, false, [value]);
  } catch {
    error.value = 'Не удалось прочитать переносимый документ. Используйте меню карточки «Добавить в папку».';
  }
}
function openFolder(folder: FolderItem) {
  if (hasSelection.value) void assign(folder);
  else emit('select', folder.id);
}
onMounted(load);
defineExpose({ refresh: load });
</script>
<template>
  <section
    :class="{ 'folder-section-compact': compact }"
    :aria-label="mode === 'program' ? 'Папки программ и циклов' : 'Категории практик'"
  >
    <div class="folder-heading">
      <button class="btn btn-secondary" @click="creating = true">
        <Plus :size="17" />{{ mode === 'program' ? 'Создать папку' : 'Создать категорию' }}</button
      ><ActionMenu label="Действия с папками"
        ><button
          @click="
            archived = !archived;
            load();
          "
        >
          {{ archived ? 'Показать активные' : 'Показать архив папок' }}
        </button></ActionMenu
      >
    </div>
    <p v-if="error" class="error-banner" role="alert">
      {{ error }}
      <button
        class="btn btn-ghost"
        @click="
          error = '';
          load();
        "
      >
        Обновить
      </button>
    </p>
    <p v-if="notice" class="success-banner" role="status">{{ notice }}</p>
    <p v-if="hasSelection" class="muted">
      Нажмите на папку, чтобы добавить выбранный документ. Его прежние
      {{ mode === 'program' ? 'папки' : 'категории' }} сохранятся.
    </p>
    <div class="folder-grid">
      <article
        v-for="folder in items"
        :key="folder.id"
        class="folder-card"
        :class="{
          selected: selectedFolder === folder.id,
          'drop-target': dropTarget === folder.id,
          'folder-archived': !!folder.archived_at,
        }"
        @dragover.prevent="!folder.archived_at && (dropTarget = folder.id)"
        @dragleave="dropTarget = null"
        @drop.prevent="!folder.archived_at && drop($event, folder)"
      >
        <div class="folder-card-heading">
          <div class="folder-decoration" aria-hidden="true"><BrandMotif /></div>
          <span class="folder-icon" aria-hidden="true"
            ><Archive v-if="folder.archived_at" :size="25" :stroke-width="1.5" /><Folder
              v-else
              :size="25"
              :stroke-width="1.5"
          /></span>
          <ActionMenu :label="`Действия с папкой: ${folder.name}`"
            ><template v-if="hasSelection && !folder.archived_at"
              ><button :disabled="busy" @click="assign(folder)">Добавить выбранные</button
              ><button :disabled="busy" @click="assign(folder, true)">Убрать выбранные</button></template
            ><button :disabled="busy" @click="rename(folder)">Переименовать</button
            ><button :disabled="busy" @click="change(folder, { archived: !folder.archived_at })">
              {{ folder.archived_at ? 'Восстановить' : 'В архив — документы сохранятся' }}
            </button></ActionMenu
          >
        </div>
        <Link
          v-if="mode === 'category' && !hasSelection && !folder.archived_at"
          :href="`/practices?folder=${folder.id}`"
          class="folder-title"
          ><span>{{ folder.name }}</span
          ><ArrowUpRight :size="18" aria-hidden="true"
        /></Link>
        <button
          v-else
          class="folder-title"
          :disabled="busy || !!folder.archived_at"
          :aria-pressed="mode === 'program' && !hasSelection ? selectedFolder === folder.id : undefined"
          @click="openFolder(folder)"
        >
          <span>{{ folder.name }}</span>
          <Check v-if="!folder.archived_at && selectedFolder === folder.id" :size="18" aria-hidden="true" />
          <Plus v-else-if="!folder.archived_at && hasSelection" :size="18" aria-hidden="true" />
          <ArrowUpRight v-else-if="!folder.archived_at" :size="18" aria-hidden="true" />
        </button>
        <div class="folder-meta">
          <FileText :size="14" aria-hidden="true" />
          <small
            >{{
              mode === 'program' ? folder.items_count || 0 : folder.documents_count || 0
            }}
            документов</small
          >
          <span v-if="folder.archived_at" class="folder-archive-label">В архиве</span>
        </div>
      </article>
    </div>
    <p v-if="!items.length" class="muted">
      {{
        archived
          ? 'В архиве папок пока нет.'
          : 'Создайте папку, чтобы собрать занятия по теме. Документы можно перетаскивать на неё или добавлять через меню «⋮».'
      }}
    </p>
    <Modal
      :open="creating"
      :title="mode === 'program' ? 'Новая папка' : 'Новая категория практик'"
      @close="!busy && (creating = false)"
      ><form id="folder-create" @submit.prevent="create">
        <label class="field"
          ><span class="label">Название</span
          ><input
            v-model="name"
            class="input"
            required
            maxlength="160"
            placeholder="Например, вечерние практики"
        /></label>
        <p v-if="error" class="error-banner">{{ error }}</p>
      </form>
      <template #footer
        ><button class="btn btn-secondary" :disabled="busy" @click="creating = false">Отмена</button
        ><button class="btn btn-primary" form="folder-create" type="submit" :disabled="busy || !name.trim()">
          Создать
        </button></template
      ></Modal
    >
  </section>
</template>
<style scoped>
.folder-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin: 12px 0;
}
.folder-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 236px), 1fr));
  gap: var(--section-gap);
  margin: var(--section-gap) 0;
}
.folder-card {
  display: flex;
  flex-direction: column;
  border: 1px solid #e0e7da;
  border-radius: var(--panel-radius);
  padding: var(--panel-padding);
  background: linear-gradient(155deg, #f5f8ee 0, #fcfdf9 40%, #fff 70%);
  box-shadow: var(--panel-shadow);
  min-width: 0;
}
.folder-card-heading {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin: -12px -12px 18px;
  padding: 12px;
  color: #416448;
}
.folder-decoration {
  position: absolute;
  inset: 0;
  overflow: hidden;
  pointer-events: none;
  border-radius: 18px;
}
.folder-decoration .brand-motif {
  position: absolute;
  width: 190px;
  height: 190px;
  right: -34px;
  top: -88px;
  opacity: 0.12;
}
.folder-icon {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 52px;
  height: 52px;
  flex-shrink: 0;
  border-radius: 17px;
  border: 1px solid #dce6d3;
  background: linear-gradient(145deg, #ffffffdf, #e8f0dc);
  box-shadow:
    inset 0 1px 0 #fff,
    0 4px 12px #3f5a2f06;
}
.folder-title {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
  width: 100%;
  min-height: var(--control-height);
  font-size: 17px;
  font-weight: 550;
  line-height: 1.4;
  letter-spacing: -0.025em;
  text-decoration: none;
  color: #29492f;
  background: none;
  border: 0;
  padding: 0;
  cursor: pointer;
  text-align: left;
  overflow-wrap: anywhere;
}
.folder-title > span {
  min-width: 0;
}
.folder-title > svg {
  flex-shrink: 0;
  margin-top: 3px;
  color: #6b845b;
}
.folder-title:disabled {
  cursor: default;
  color: #697761;
}
.folder-title:focus-visible {
  outline: 2px solid #668a56;
  outline-offset: 5px;
  border-radius: 5px;
}
.folder-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 7px;
  margin-top: auto;
  padding-top: 18px;
  border-top: 1px solid #e5eadf;
  color: #73816c;
}
.folder-card small {
  display: block;
  font-size: 12px;
  font-variant-numeric: tabular-nums;
}
.folder-archive-label {
  margin-left: auto;
  font-size: 10px;
}
.folder-archived {
  background: #fafbf8;
}
.folder-archived .folder-decoration {
  display: none;
}
.folder-card.selected,
.folder-card.drop-target {
  background: #eef5e8;
  border-color: #648853;
  outline: 2px solid #648853;
}
.folder-section-compact .folder-grid {
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 170px), 1fr));
  gap: 10px;
}
.folder-section-compact .folder-card {
  padding: 12px;
}
.folder-section-compact .folder-card-heading {
  margin: 0 0 10px;
  padding: 0;
}
.folder-section-compact .folder-icon {
  width: 36px;
  height: 36px;
  border-radius: 12px;
}
.folder-section-compact .folder-icon > svg {
  width: 20px;
  height: 20px;
}
.folder-section-compact .folder-title {
  font-size: 15px;
  min-height: 44px;
}
.folder-section-compact .folder-meta {
  padding-top: 10px;
}
@media (hover: hover) and (pointer: fine) {
  .folder-card:not(.folder-archived, .selected, .drop-target):hover {
    border-color: #b9cbae;
    box-shadow: 0 7px 24px #294b2510;
  }
  .folder-title:hover:not(:disabled) {
    color: #376d43;
  }
}
@media print {
  .folder-card,
  .folder-icon {
    background: #fff;
    box-shadow: none;
  }
  .folder-decoration {
    display: none;
  }
}
</style>
