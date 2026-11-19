<script setup lang="ts">
import ResponsivePanel from '@/shared/ResponsivePanel.vue';
import AppSelect from '@/shared/AppSelect.vue';
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { CalendarDays, Plus, BookOpen } from '@lucide/vue';
import AppShell from '@/shared/AppShell.vue';
import PageHeading from '@/shared/PageHeading.vue';
import ActionMenu from '@/shared/ActionMenu.vue';
import Modal from '@/shared/Modal.vue';
import PracticeFolders, { type ProgramItem } from '../components/PracticeFolders.vue';
import PracticeNavigation from '../components/PracticeNavigation.vue';
import PracticePicker, { type PracticeChoice } from '../components/PracticePicker.vue';
import { api } from '@/shared/api';
import type { Page } from '@/modules/catalog/types';
import { uid } from '../types';
interface Item extends ProgramItem {
  title: string;
  theme: string | null;
  archived_at: string | null;
}
const params = new URLSearchParams(location.search);
const result = ref<Page<Item> | null>(null),
  loading = ref(false),
  error = ref(''),
  query = ref(''),
  archive = ref('active'),
  page = ref(1),
  type = ref(params.get('type') || 'all'),
  folderId = ref(params.get('folder') || '');
const folders = ref<InstanceType<typeof PracticeFolders> | null>(null),
  assigning = ref<Item | null>(null),
  practicePickerOpen = ref(false),
  pickerError = ref('');
const folderPath = computed(() => folders.value?.breadcrumbs ?? []);
const currentFolder = computed(() => folderPath.value.at(-1));
const practiceAction = computed(() => (folderId.value ? 'Переместить практику сюда' : 'Копировать практику'));
const breadcrumbs = computed(() => [
  { label: 'Программы и циклы', href: folderId.value ? folderUrl() : undefined },
  ...(folderPath.value.length
    ? folderPath.value.map((folder) => ({ label: folder.name, href: folderUrl(folder.id) }))
    : folderId.value
      ? [{ label: 'Папка' }]
      : []),
]);
const createOpen = ref(false),
  title = ref(''),
  theme = ref(''),
  count = ref(5),
  operationKey = ref(uid()),
  busy = ref(false);
let serial = 0;
function folderUrl(id = '') {
  const params = new URLSearchParams();
  if (id) params.set('folder', id);
  if (type.value !== 'all') params.set('type', type.value);
  return params.size ? `/mesocycles?${params}` : '/mesocycles';
}
async function load() {
  const version = ++serial;
  loading.value = true;
  error.value = '';
  const filters = new URLSearchParams({
    q: query.value,
    archive: archive.value,
    page: String(page.value),
    folder_id: folderId.value,
    type: type.value,
  });
  const url = new URL(location.href);
  folderId.value ? url.searchParams.set('folder', folderId.value) : url.searchParams.delete('folder');
  type.value !== 'all' ? url.searchParams.set('type', type.value) : url.searchParams.delete('type');
  history.replaceState(history.state, '', url);
  try {
    const value = await api<Page<Item>>(`/api/program-library?${filters}`);
    if (version === serial) {
      if (page.value > value.last_page) {
        page.value = Math.max(1, value.last_page);
        await load();
        return;
      }
      result.value = value;
    }
  } catch (e) {
    if (version === serial) error.value = failure(e);
  } finally {
    if (version === serial) loading.value = false;
  }
}
function failure(e: unknown) {
  return e instanceof Error ? e.message : 'Не удалось выполнить действие.';
}
watch([archive, type, folderId], () => {
  page.value = 1;
  void load();
});
function openCreate() {
  count.value = 5;
  operationKey.value = uid();
  title.value = '';
  theme.value = '';
  createOpen.value = true;
}
async function assignToCurrentFolder(item: ProgramItem) {
  const destination = folderId.value;
  if (!destination) return;
  const folder = (await api<{ data: { id: string; revision: number }[] }>('/api/program-folders')).data.find(
    (folder) => folder.id === destination,
  );
  if (!folder) throw new Error('Папка недоступна. Обновите список.');
  await api(`/api/program-folders/${folder.id}/items`, {
    method: 'POST',
    body: JSON.stringify({
      expected_revision: folder.revision,
      items: [{ id: item.id, type: item.type, expected_revision: item.revision }],
    }),
  });
}
async function create() {
  if (busy.value || !title.value.trim()) return;
  busy.value = true;
  error.value = '';
  try {
    const response = await api<{ data: { id: string; revision: number } }>('/api/mesocycles', {
      method: 'POST',
      body: JSON.stringify({
        title: title.value.trim(),
        count: count.value,
        idempotency_key: operationKey.value,
        theme: theme.value.trim() || null,
      }),
    });
    await assignToCurrentFolder({ ...response.data, type: 'cycle' });
    router.visit(`/mesocycles/${response.data.id}/edit`);
  } catch (e) {
    error.value = failure(e);
  } finally {
    busy.value = false;
  }
}
function itemUrl(item: Item) {
  return item.type === 'cycle' ? `/mesocycles/${item.id}/edit` : `/practices/${item.id}/edit`;
}
function drag(event: DragEvent, item: Item) {
  if (item.archived_at) {
    event.preventDefault();
    return;
  }
  event.dataTransfer?.setData('application/ribka-document', JSON.stringify(item));
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'link';
}
function openPracticePicker() {
  pickerError.value = '';
  practicePickerOpen.value = true;
}
async function applyPracticeAction(item: PracticeChoice) {
  if (busy.value) return;
  busy.value = true;
  pickerError.value = '';
  try {
    if (!folderId.value) {
      const copy = await api<{ data: { id: string } }>(`/api/practices/${item.id}/duplicate`, {
        method: 'POST',
        body: JSON.stringify({ expected_revision: item.revision }),
      });
      practicePickerOpen.value = false;
      router.visit(`/practices/${copy.data.id}/edit`);
      return;
    }
    await assignToCurrentFolder({ ...item, type: 'practice' });
    practicePickerOpen.value = false;
    await load();
    await folders.value?.refresh();
  } catch (e) {
    pickerError.value = failure(e);
  } finally {
    busy.value = false;
  }
}
async function changeArchive(item: Item) {
  if (
    busy.value ||
    (!item.archived_at &&
      !window.confirm(`Убрать «${item.title}» в архив? Документы и изображения сохранятся.`))
  )
    return;
  busy.value = true;
  error.value = '';
  try {
    await api(
      `/api/${item.type === 'cycle' ? 'mesocycles' : 'practices'}/${item.id}/${item.archived_at ? 'restore' : 'archive'}`,
      { method: 'POST', body: JSON.stringify({ expected_revision: item.revision }) },
    );
    await load();
  } catch (e) {
    error.value = failure(e);
  } finally {
    busy.value = false;
  }
}
async function removeFromFolder(item: Item) {
  if (busy.value || !folderId.value) return;
  busy.value = true;
  try {
    const folder = (
      await api<{ data: { id: string; revision: number }[] }>('/api/program-folders')
    ).data.find((f) => f.id === folderId.value);
    if (!folder) throw new Error('Папка недоступна.');
    await api(`/api/program-folders/${folder.id}/items`, {
      method: 'POST',
      body: JSON.stringify({
        expected_revision: folder.revision,
        remove: true,
        items: [{ id: item.id, type: item.type, expected_revision: item.revision }],
      }),
    });
    await load();
    await folders.value?.refresh();
  } catch (e) {
    error.value = failure(e);
  } finally {
    busy.value = false;
  }
}
onMounted(load);
</script>
<template>
  <Head title="Программы и циклы" />
  <AppShell section="practices" :breadcrumbs="breadcrumbs">
    <PageHeading
      eyebrow="ПОСЛЕДОВАТЕЛЬНОСТЬ ЗАНЯТИЙ"
      title="Программы и циклы"
      description="Собирайте последовательности и мезоциклы в свои папки."
    >
      <div class="program-header-actions">
        <button class="btn btn-primary" @click="openCreate"><Plus :size="18" />Создать мезоцикл</button
        ><button class="btn btn-secondary" :disabled="busy" @click="openPracticePicker">
          {{ practiceAction }}
        </button>
      </div>
    </PageHeading>
    <PracticeNavigation active="programs" />
    <PracticeFolders
      ref="folders"
      mode="program"
      :compact="!!folderId"
      :selected-folder="folderId"
      @select="folderId = $event"
      @changed="load"
    />
    <div class="folder-context">
      <h2>
        {{
          currentFolder
            ? `Документы папки «${currentFolder.name}»`
            : folderId
              ? 'Документы папки'
              : 'Документы без папки'
        }}
      </h2>
    </div>
    <form
      class="card cycle-filters"
      @submit.prevent="
        page = 1;
        load();
      "
    >
      <input v-model="query" class="input" placeholder="Название или тема" aria-label="Поиск программ" />
      <ResponsivePanel title="Фильтры программ" :active="type !== 'all' || archive !== 'active'">
        <AppSelect v-model="type" class="select" aria-label="Вид документа">
          <option value="all">Все документы</option>
          <option value="cycle">Мезоциклы</option>
          <option value="practice">Последовательности</option></AppSelect
        ><AppSelect v-model="archive" class="select" aria-label="Архив программ">
          <option value="active">Активные</option>
          <option value="archived">В архиве</option></AppSelect
        ></ResponsivePanel
      ><button class="btn btn-secondary">Найти</button>
    </form>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <p v-if="loading" class="muted" role="status">Загружаем документы…</p>
    <div v-else-if="result?.data.length" class="cycle-grid" :class="{ 'in-folder': !!folderId }">
      <article
        v-for="item in result.data"
        :key="`${item.type}:${item.id}`"
        class="card cycle-card"
        :draggable="!item.archived_at"
        @dragstart="drag($event, item)"
      >
        <div class="cycle-card-top">
          <CalendarDays v-if="item.type === 'cycle'" :size="28" /><BookOpen v-else :size="28" /><ActionMenu
            :label="`Действия: ${item.title}`"
            ><button v-if="!item.archived_at" @click="assigning = item">Переместить в папку</button
            ><button v-if="folderId && !item.archived_at" :disabled="busy" @click="removeFromFolder(item)">
              Убрать из этой папки</button
            ><Link v-if="!item.archived_at" :href="itemUrl(item)">Редактировать</Link
            ><button :disabled="busy" @click="changeArchive(item)">
              {{ item.archived_at ? 'Восстановить' : 'Отправить в архив' }}
            </button></ActionMenu
          >
        </div>
        <small>{{ item.type === 'cycle' ? 'Мезоцикл' : 'Последовательность' }}</small>
        <h2>{{ item.title }}</h2>
        <p>{{ item.theme || 'Без темы' }}</p>
        <Link v-if="!item.archived_at" class="btn btn-secondary" :href="itemUrl(item)"
          >Открыть {{ item.type === 'cycle' ? 'занятия' : 'практику' }}</Link
        >
      </article>
    </div>
    <div v-else class="empty-state">
      <h2>{{ archive === 'archived' ? 'В архиве пока пусто' : 'Здесь пока нет документов' }}</h2>
      <p v-if="archive !== 'archived'">
        {{
          folderId
            ? 'Сюда можно переместить существующую практику или создать новый мезоцикл.'
            : 'Документы, распределённые по папкам, доступны внутри этих папок.'
        }}
      </p>
    </div>
    <div v-if="result && result.last_page > 1" class="cycle-filters">
      <button
        class="btn btn-secondary"
        :disabled="page <= 1 || loading"
        @click="
          page--;
          load();
        "
      >
        Назад</button
      ><span>{{ page }} / {{ result.last_page }}</span
      ><button
        class="btn btn-secondary"
        :disabled="page >= result.last_page || loading"
        @click="
          page++;
          load();
        "
      >
        Далее
      </button>
    </div>
    <Modal :open="createOpen" title="Новый мезоцикл" @close="!busy && (createOpen = false)"
      ><form id="cycle-create" @submit.prevent="create">
        <p v-if="currentFolder" class="muted">
          Место создания · {{ folderPath.map((folder) => folder.name).join(' / ') }}
        </p>
        <p class="muted">
          Пять занятий на месяц по умолчанию. Количество можно изменить; каждое занятие создаётся с пятью
          пустыми блоками.
        </p>
        <p v-if="error" class="error-banner">{{ error }}</p>
        <label class="field"
          ><span class="label">Название</span
          ><input v-model="title" class="input" maxlength="160" required /></label
        ><label class="field"
          ><span class="label">Количество занятий</span
          ><input v-model.number="count" class="input" type="number" min="1" max="52" required /></label
        ><label class="field"
          ><span class="label">Тема месяца</span><input v-model="theme" class="input" maxlength="240"
        /></label>
      </form>
      <template #footer
        ><button class="btn btn-secondary" :disabled="busy" @click="createOpen = false">Отмена</button
        ><button class="btn btn-primary" form="cycle-create" type="submit" :disabled="busy || !title.trim()">
          Создать мезоцикл
        </button></template
      ></Modal
    >
    <Modal :open="!!assigning" title="Переместить в папку" @close="assigning = null"
      ><PracticeFolders
        v-if="assigning"
        mode="program"
        :program-items="[assigning]"
        @changed="
          load();
          folders?.refresh();
        "
      /><template #footer
        ><button class="btn btn-primary" @click="assigning = null">Готово</button></template
      ></Modal
    >
    <PracticePicker
      :external-error="pickerError"
      :open="practicePickerOpen"
      :busy="busy"
      :title="practiceAction"
      :action-label="folderId ? 'Переместить' : 'Копировать'"
      :description="
        folderId
          ? currentFolder
            ? `Выберите практику для перемещения в «${folderPath.map((folder) => folder.name).join(' / ')}».`
            : 'Выберите практику для перемещения в открытую папку.'
          : 'Выберите практику. Откроется отдельная копия для редактирования.'
      "
      @close="practicePickerOpen = false"
      @choose="applyPracticeAction"
    />
  </AppShell>
</template>
<style scoped>
.program-header-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 10px;
}
.cycle-filters {
  display: flex;
  gap: 12px;
  margin: var(--section-gap) 0;
  align-items: center;
  flex-wrap: wrap;
}
.cycle-filters .input {
  flex: 1;
  min-width: min(100%, 200px);
}
.cycle-filters .select {
  width: auto;
}
.cycle-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 260px), 1fr));
  gap: var(--section-gap);
}
.cycle-card {
  padding: var(--panel-padding);
  display: grid;
  gap: 16px;
  align-content: start;
  min-width: 0;
  border-color: #dfe7da;
  box-shadow: 0 4px 18px rgb(44 65 29 / 3%);
  transition:
    border-color 0.2s,
    box-shadow 0.2s;
}
.cycle-card-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  color: var(--accent-dark);
}
.cycle-card-top > svg {
  width: 44px;
  height: 44px;
  padding: 10px;
  border: 1px solid #dfe7d5;
  border-radius: 14px;
  background: linear-gradient(140deg, #fff, #eef4e6);
  color: #63834d;
  stroke-width: 1.5;
  box-shadow: inset 0 1px 0 #fff;
}
.cycle-card h2 {
  font-size: 19px;
  font-weight: 600;
  color: #253e2d;
  letter-spacing: -0.025em;
  overflow-wrap: anywhere;
}
.cycle-card small {
  color: #627452;
  font-size: 11px;
  font-weight: 550;
  letter-spacing: 0.025em;
}
.cycle-card > .btn-secondary {
  border-color: #dfe7d7;
  color: #315536;
}
.cycle-card > p {
  color: var(--muted);
  font-size: 14px;
  overflow-wrap: anywhere;
}
.folder-context {
  margin-top: var(--section-gap);
}
.folder-context h2 {
  font-size: 19px;
  font-weight: 650;
  overflow-wrap: anywhere;
}
.cycle-grid.in-folder {
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 190px), 1fr));
  gap: 12px;
}
.in-folder .cycle-card {
  padding: 16px;
  gap: 8px;
  border-radius: var(--panel-radius);
  min-width: 0;
}
.in-folder .cycle-card-top > svg {
  width: 30px;
  height: 30px;
  padding: 6px;
  border-radius: 10px;
}
.in-folder .cycle-card h2 {
  font-size: 16px;
  line-height: 1.4;
}
.in-folder .cycle-card small,
.in-folder .cycle-card > p {
  font-size: 12px;
}
.in-folder .cycle-card > .btn {
  font-size: 13px;
  padding: 8px 10px;
  white-space: normal;
  margin-top: 4px;
}
@media (hover: hover) and (pointer: fine) {
  .cycle-card:hover {
    border-color: #c6d5bb;
    box-shadow: 0 8px 24px rgb(44 65 29 / 5%);
  }
}
@media (prefers-reduced-motion: reduce) {
  .cycle-card {
    transition: none;
  }
}
@media screen and (max-width: 760px) {
  .program-header-actions {
    display: grid;
    grid-template-columns: 1fr;
    width: 100%;
  }
  .cycle-filters.card {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    padding: 14px;
  }
  .cycle-filters.card > .input {
    min-width: 0;
    width: 100%;
  }
  .cycle-filters.card > .btn {
    grid-column: 1 / -1;
  }
}
@media (max-width: 600px) {
  .program-header-actions {
    width: 100%;
  }
  .program-header-actions > .btn {
    flex: 1;
    white-space: normal;
  }
  .cycle-filters > .input {
    flex-basis: 100%;
  }
  .cycle-filters > .select {
    flex: 1;
    width: 100%;
    min-width: 0;
  }
  .cycle-grid.in-folder {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .in-folder .cycle-card {
    padding: 12px;
  }
}
</style>
