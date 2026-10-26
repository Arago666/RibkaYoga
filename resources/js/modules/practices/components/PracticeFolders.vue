<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Folder, Plus } from '@lucide/vue';
import ActionMenu from '@/shared/ActionMenu.vue';
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
  <section :aria-label="mode === 'program' ? 'Папки программ и циклов' : 'Категории практик'">
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
      Нажмите на папку, чтобы добавить выбранный документ. Его прежние категории сохранятся.
    </p>
    <div class="folder-grid">
      <article
        v-for="folder in items"
        :key="folder.id"
        class="folder-card"
        :class="{ selected: selectedFolder === folder.id, 'drop-target': dropTarget === folder.id }"
        @dragover.prevent="!folder.archived_at && (dropTarget = folder.id)"
        @dragleave="dropTarget = null"
        @drop.prevent="!folder.archived_at && drop($event, folder)"
      >
        <div class="folder-card-heading">
          <Folder :size="28" /><ActionMenu :label="`Действия с папкой: ${folder.name}`"
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
          >{{ folder.name }}</Link
        >
        <button
          v-else
          class="folder-title"
          :disabled="busy || !!folder.archived_at"
          @click="openFolder(folder)"
        >
          {{ folder.name }}
        </button>
        <small
          >{{ mode === 'program' ? folder.items_count || 0 : folder.documents_count || 0 }} документов</small
        >
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
.folder-heading,
.folder-card-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin: 12px 0;
}
.folder-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 210px), 1fr));
  gap: 14px;
  margin: 18px 0;
}
.folder-card {
  border: 1px solid #dce6d6;
  border-radius: 16px;
  padding: 17px;
  background: #fff;
  min-width: 0;
}
.folder-card-heading {
  margin: 0 0 14px;
  color: #71955c;
}
.folder-title {
  display: block;
  font-size: 17px;
  font-weight: 650;
  text-decoration: none;
  color: #30442a;
  background: none;
  border: 0;
  padding: 0;
  cursor: pointer;
  text-align: left;
  overflow-wrap: anywhere;
}
.folder-card small {
  display: block;
  margin-top: 10px;
  color: #73816b;
}
.folder-card.selected,
.folder-card.drop-target {
  background: #eff7e9;
  border-color: #75a958;
  outline: 2px solid #92b77c;
}
</style>
