<script setup lang="ts">
import AppSelect from '@/shared/AppSelect.vue';
import { onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppShell from '@/shared/AppShell.vue';
import Modal from '@/shared/Modal.vue';
import { api, ApiError } from '@/shared/api';
import { uid } from '@/shared/uuid';
import AsanaDrawing from '@/modules/catalog/components/AsanaDrawing.vue';
import { cloneDrawing, emptyDrawing, parseDrawing, type DrawingDocument } from '@/modules/catalog/drawing';
import NativeAsanaDialog from '../NativeAsanaDialog.vue';
import {
  clearSketchDraft,
  readSketchDrafts,
  removeSketchDraft,
  writeSketchDraft,
  type QueuedDrawing,
  type SketchDraft,
} from '../sketchDrafts';
const props = defineProps<{ pageId: string }>();
interface Sketch {
  id: string;
  title: string;
  revision: number;
  page_format: string;
  drawing_source: DrawingDocument | null;
  image_url: string | null;
  archived_at: string | null;
}
interface Exported {
  file: File;
  drawing: DrawingDocument;
}
interface Handle {
  exportDrawing(): Promise<Exported>;
  exportSelection(): Promise<Exported>;
  hasUnappliedChanges(): boolean;
  discardDraft(): void;
  flushDraft(): void;
}
const row = ref<Sketch | null>(null),
  initial = shallowRef<DrawingDocument | null>(null),
  drawing = shallowRef<DrawingDocument | null>(null),
  editor = ref<Handle | null>(null),
  title = ref(''),
  format = ref('square'),
  status = ref('Загружаем…'),
  error = ref(''),
  saving = ref(false),
  changed = ref(false),
  candidate = ref<Exported | null>(null),
  queue = ref<(QueuedDrawing & { url: string })[]>([]),
  showTransfer = ref(false),
  showCreate = ref(false),
  documents = ref<{ id: string; title: string }[]>([]),
  target = ref(''),
  working = ref(false);
const auth = usePage().props.auth as { user?: { id: number }; workspace?: { id: string } } | undefined;
const scopeFor = (id: string) => `sketch:${auth?.user?.id}:${auth?.workspace?.id}:${id}`;
const scope = scopeFor(props.pageId);
const draftKey = `${scope}:${uid()}`;
const legacyKey = `ribka-drawing-draft:${scope}`;
const draftReady = ref(false),
  draftWarning = ref(''),
  draftStored = ref(false),
  conflict = ref(false);
const recovery = shallowRef<SketchDraft | null>(null);
let legacyRaw: string | null = null;
let baseRevision = 0;
let writeNumber = 0;
let disposed = false;
let timer: ReturnType<typeof setTimeout> | undefined;
let generation = 0;

function queuedDrawings(): QueuedDrawing[] {
  return queue.value.map(({ id, name, file, drawing }) => ({
    id,
    name,
    file,
    drawing: cloneDrawing(drawing),
  }));
}
function draftValue(): SketchDraft {
  return {
    key: draftKey,
    scope,
    updatedAt: Date.now(),
    revision: baseRevision,
    title: title.value,
    format: format.value,
    drawing: drawing.value ? cloneDrawing(drawing.value) : null,
    queue: queuedDrawings(),
    target: target.value,
  };
}
async function flushDraft(): Promise<boolean> {
  if (!row.value || row.value.archived_at || !draftReady.value || recovery.value || disposed) return false;
  const request = ++writeNumber;
  const hasDraft = changed.value || queue.value.length > 0;
  try {
    if (hasDraft) await writeSketchDraft(draftValue());
    else await clearSketchDraft(draftKey);
    if (request === writeNumber) {
      draftStored.value = hasDraft;
      draftWarning.value = '';
    }
    return hasDraft;
  } catch {
    if (request === writeNumber) {
      draftStored.value = false;
      if (hasDraft) draftWarning.value = 'Локальный черновик не записан. Сохраните лист перед выходом.';
    }
    return false;
  }
}
function matchesServer(draft: SketchDraft, server: Sketch) {
  return (
    draft.title.trim() === server.title &&
    draft.format === server.page_format &&
    JSON.stringify(draft.drawing || emptyDrawing()) ===
      JSON.stringify(server.drawing_source || emptyDrawing())
  );
}
async function offerDraft() {
  if (!row.value || row.value.archived_at) return;
  try {
    for (const draft of await readSketchDrafts(scope)) {
      if (disposed) return;
      if (!draft.queue.length && matchesServer(draft, row.value)) {
        await removeSketchDraft(draft);
      } else {
        recovery.value = draft;
        return;
      }
    }
  } catch {
    draftWarning.value = 'Браузер не смог прочитать локальный черновик.';
  }
  // Earlier versions stored just the editable drawing and did not record its revision.
  try {
    const raw = localStorage.getItem(legacyKey);
    if (!raw) return;
    const draft = { ...draftValue(), revision: 0, drawing: parseDrawing(JSON.parse(raw)) };
    if (!matchesServer(draft, row.value)) {
      legacyRaw = raw;
      recovery.value = draft;
    } else if (localStorage.getItem(legacyKey) === raw) localStorage.removeItem(legacyKey);
  } catch {
    /* An unreadable entry does not replace a saved sheet. */
  }
}
async function removeRecovery(draft: SketchDraft) {
  if (legacyRaw) {
    if (localStorage.getItem(legacyKey) === legacyRaw) localStorage.removeItem(legacyKey);
    legacyRaw = null;
  } else await removeSketchDraft(draft);
}
async function discardRecovery() {
  const draft = recovery.value;
  if (!draft || working.value) return;
  try {
    await removeRecovery(draft);
    recovery.value = null;
    await offerDraft();
  } catch {
    draftWarning.value = 'Браузер не смог удалить локальный черновик.';
  }
}
async function restoreRecovery() {
  const draft = recovery.value;
  if (!draft || !row.value || working.value) return;
  changed.value = !matchesServer(draft, row.value);
  conflict.value = changed.value && draft.revision !== row.value.revision;
  baseRevision = changed.value ? draft.revision : row.value.revision;
  title.value = draft.title;
  format.value = draft.format;
  drawing.value = initial.value = draft.drawing;
  queue.value = draft.queue.map((item) => ({ ...item, url: URL.createObjectURL(item.file) }));
  if (!documents.value.length || documents.value.some((item) => item.id === draft.target))
    target.value = draft.target;
  recovery.value = null;
  if (await flushDraft()) {
    try {
      await removeRecovery(draft);
    } catch {
      /* Leave the other entry intact. */
    }
  }
  if (conflict.value) status.value = 'Черновик открыт · на сервере другая версия';
  else if (changed.value) schedule();
}

function cancelScheduledSave() {
  if (timer) clearTimeout(timer);
  timer = undefined;
}
async function save(exported?: Exported, exportedGeneration = generation) {
  if (!row.value || saving.value || conflict.value || recovery.value) return false;
  const current = exportedGeneration;
  saving.value = true;
  status.value = 'Сохраняется…';
  error.value = '';
  try {
    const form = new FormData();
    form.set('_method', 'PUT');
    form.set('expected_revision', String(row.value.revision));
    form.set('title', title.value);
    form.set('page_format', format.value);
    if (exported) {
      form.set('image', exported.file);
      form.set('drawing_source', JSON.stringify(exported.drawing));
    }
    row.value = (
      await api<{ data: Sketch }>(`/api/sketchbook/${props.pageId}`, { method: 'POST', body: form })
    ).data;
    baseRevision = row.value.revision;
    if (current === generation && (exported || !changed.value)) {
      changed.value = false;
      status.value = 'Сохранено на сервере';
      if (exported) editor.value?.discardDraft();
    } else schedule();
    void flushDraft();
    return !changed.value;
  } catch (f) {
    conflict.value = f instanceof ApiError && f.status === 409;
    status.value = 'Не сохранено на сервере';
    void flushDraft();
    error.value = f instanceof Error ? f.message : 'Ошибка сохранения';
    return false;
  } finally {
    saving.value = false;
  }
}
async function saveAll() {
  if (
    !row.value ||
    saving.value ||
    working.value ||
    !editor.value ||
    row.value.archived_at ||
    conflict.value ||
    recovery.value
  )
    return false;
  try {
    // Export checks the editor lock synchronously and captures the source before PNG encoding.
    const pending = editor.value.exportDrawing();
    const current = generation;
    working.value = true;
    cancelScheduledSave();
    return await save(await pending, current);
  } catch (f) {
    error.value = f instanceof Error ? f.message : 'Не удалось подготовить изображение.';
    return false;
  } finally {
    working.value = false;
  }
}
function schedule() {
  if (!row.value || row.value.archived_at || recovery.value) return;
  changed.value = true;
  draftStored.value = false;
  status.value = 'Есть изменения';
  generation++;
  cancelScheduledSave();
  void flushDraft();
  if (!conflict.value) timer = setTimeout(() => void saveAll(), 2200);
}
function drawingChanged(source: DrawingDocument) {
  drawing.value = source;
  schedule();
}
function queueChanged() {
  draftStored.value = false;
  void flushDraft();
}
async function selected() {
  error.value = '';
  try {
    candidate.value = await editor.value!.exportSelection();
    showTransfer.value = true;
  } catch (f) {
    error.value = f instanceof Error ? f.message : 'Сначала выделите фигуру или несколько штрихов.';
  }
}
function enqueue() {
  if (!candidate.value || queue.value.length >= 12) return;
  queue.value.push({
    ...candidate.value,
    id: uid(),
    name: `Рисунок ${queue.value.length + 1}`,
    url: URL.createObjectURL(candidate.value.file),
  });
  candidate.value = null;
  showTransfer.value = false;
  queueChanged();
}
function remove(index: number) {
  URL.revokeObjectURL(queue.value[index].url);
  queue.value.splice(index, 1);
  queueChanged();
}
async function insert() {
  if (
    !target.value ||
    !queue.value.length ||
    !row.value ||
    !editor.value ||
    row.value.archived_at ||
    conflict.value ||
    working.value ||
    saving.value
  )
    return;
  error.value = '';
  try {
    const pending = editor.value.exportDrawing();
    const current = generation;
    working.value = true;
    cancelScheduledSave();
    const saved = await save(await pending, current);
    if (!saved || changed.value || saving.value)
      throw new Error(
        `${error.value || 'Сначала сохраните лист.'} Очередь фигур остаётся в этом окне; вставку можно повторить.`,
      );
    const targetId = target.value;
    const ids = [];
    for (const item of queue.value) {
      const form = new FormData();
      form.set('id', item.id);
      form.set('name', item.name || 'Рисунок из скетчбука');
      form.set('image', item.file);
      form.set('drawing_source', JSON.stringify(item.drawing));
      const result = await api<{ data: { id: string } }>('/api/drawing-snapshots', {
        method: 'POST',
        body: form,
      });
      ids.push(result.data.id);
    }
    router.visit(`/practices/${targetId}/edit?insert_snapshots=${ids.join(',')}`);
  } catch (f) {
    error.value = f instanceof Error ? f.message : 'Ошибка подготовки вставки';
  } finally {
    working.value = false;
  }
}
async function copy() {
  if (!row.value || !editor.value || recovery.value || working.value || saving.value || row.value.archived_at)
    return;
  error.value = '';
  try {
    const pending = editor.value.exportDrawing();
    working.value = true;
    cancelScheduledSave();
    const exported = await pending;
    const form = new FormData();
    form.set('title', `${title.value.slice(0, 150)} — копия`);
    form.set('page_format', format.value);
    form.set('image', exported.file);
    form.set('drawing_source', JSON.stringify(exported.drawing));
    const created = (
      await api<{ data: Sketch }>('/api/sketchbook', {
        method: 'POST',
        body: form,
      })
    ).data;
    if (queue.value.length) {
      try {
        const copyScope = scopeFor(created.id);
        await writeSketchDraft({
          key: `${copyScope}:${uid()}`,
          scope: copyScope,
          updatedAt: Date.now(),
          revision: created.revision,
          title: created.title,
          format: created.page_format,
          drawing: created.drawing_source,
          queue: queuedDrawings(),
          target: target.value,
        });
        queue.value.forEach((item) => URL.revokeObjectURL(item.url));
        queue.value = [];
      } catch {
        // If the queue cannot be copied locally it remains available in the original draft.
      }
    }
    changed.value = false;
    await flushDraft();
    router.visit(`/sketchbook/${created.id}`);
  } catch (f) {
    error.value = f instanceof Error ? f.message : 'Не удалось сделать копию';
  } finally {
    working.value = false;
  }
}
async function print() {
  if (!(await saveAll())) return;
  if (changed.value || !row.value?.image_url) return;
  const image = new Image();
  image.src = row.value.image_url;
  try {
    await image.decode();
    window.print();
  } catch {
    error.value = 'Не удалось подготовить рисунок к печати.';
  }
}
function beforeUnload(event: BeforeUnloadEvent) {
  flushPendingDrawing();
  if ((changed.value || queue.value.length) && !draftStored.value) {
    event.preventDefault();
    event.returnValue = '';
  }
}
function flushPendingDrawing() {
  editor.value?.flushDraft();
  void flushDraft();
}
function flushHidden() {
  if (document.visibilityState === 'hidden') flushPendingDrawing();
}
let removeNavigateGuard: (() => void) | undefined;
onMounted(async () => {
  window.addEventListener('beforeunload', beforeUnload);
  window.addEventListener('pagehide', flushPendingDrawing);
  document.addEventListener('visibilitychange', flushHidden);
  removeNavigateGuard = router.on('before', () => {
    flushPendingDrawing();
    if ((changed.value || queue.value.length) && !draftStored.value)
      return window.confirm('Локальный черновик ещё не записан. Покинуть лист?');
  });
  try {
    row.value = (await api<{ data: Sketch }>(`/api/sketchbook/${props.pageId}`)).data;
    if (disposed) return;
    baseRevision = row.value.revision;
    initial.value = drawing.value = row.value.drawing_source;
    title.value = row.value.title;
    format.value = row.value.page_format;
    status.value = 'Сохранено на сервере';
    await offerDraft();
    draftReady.value = true;
    try {
      documents.value = (
        await api<{ data: { id: string; title: string }[] }>('/api/practices?kind=practice&per_page=100')
      ).data;
      if (!target.value) target.value = documents.value[0]?.id || '';
    } catch {
      /* Editing the sketch does not depend on the practice list. */
    }
  } catch (f) {
    error.value = f instanceof Error ? f.message : 'Лист недоступен';
  }
});
onBeforeUnmount(() => {
  flushPendingDrawing();
  disposed = true;
  if (timer) clearTimeout(timer);
  queue.value.forEach((x) => URL.revokeObjectURL(x.url));
  window.removeEventListener('beforeunload', beforeUnload);
  window.removeEventListener('pagehide', flushPendingDrawing);
  document.removeEventListener('visibilitychange', flushHidden);
  removeNavigateGuard?.();
});
</script>
<template>
  <AppShell section="sketchbook" wide
    ><Head :title="title || 'Скетчбук'" />
    <header class="page-header sketch-actions sketch-header">
      <div class="sketch-heading">
        <Link class="back-link" href="/sketchbook">← Все листы</Link>
        <h1 class="page-title">{{ title || 'Скетчбук' }}</h1>
        <strong role="status">
          {{ draftStored && changed && !saving ? `${status} · черновик на устройстве` : status }}
        </strong>
      </div>
      <div class="sketch-header-actions">
        <button
          class="btn btn-secondary"
          :disabled="!draftReady || !!recovery || !row || saving || working"
          @click="copy"
        >
          Сохранить копию</button
        ><button
          class="btn btn-primary"
          :disabled="!draftReady || !!recovery || !row || saving || working || conflict"
          @click="saveAll"
        >
          Сохранить</button
        ><button
          class="btn btn-secondary"
          :disabled="!!recovery || !row?.image_url || saving || working || conflict"
          @click="print"
        >
          Печать / PDF
        </button>
      </div>
    </header>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <p v-if="draftWarning" class="error-banner" role="alert">{{ draftWarning }}</p>
    <section v-if="recovery" class="card draft-choice">
      <div>
        <strong>На устройстве есть черновик листа</strong>
        <p v-if="row && recovery.revision !== row.revision && !matchesServer(recovery, row)">
          Откройте черновик и сохраните его отдельной копией.
        </p>
        <p v-else>Восстановите изменения листа и очередь фигур.</p>
      </div>
      <button class="btn btn-primary" @click="restoreRecovery">Восстановить</button>
      <button class="btn btn-secondary" @click="discardRecovery">Удалить черновик</button>
    </section>
    <p v-if="conflict" class="success-banner">
      На сервере уже другая версия. Сохраните этот черновик отдельной копией.
    </p>
    <template v-if="row"
      ><div class="sketch-actions sketch-meta card">
        <input
          class="input"
          v-model="title"
          aria-label="Название листа"
          maxlength="160"
          :disabled="working || !draftReady || !!recovery || !!row.archived_at"
          @input="schedule"
        /><AppSelect
          class="select"
          v-model="format"
          aria-label="Формат печати"
          :disabled="working || !draftReady || !!recovery || !!row.archived_at"
          @change="schedule"
        >
          <option value="square">Квадратный лист</option>
          <option value="a4-portrait">A4 — книжная печать</option>
          <option value="a4-landscape">A4 — альбомная печать</option></AppSelect
        ><button
          class="btn btn-secondary"
          :disabled="!!row.archived_at || !!recovery || saving || working"
          @click="selected"
        >
          Выделенное → асана или ячейка
        </button>
      </div>
      <p v-if="row.archived_at" class="success-banner">
        Лист в архиве. Для изменения восстановите его в списке.
      </p>
      <div class="drawing-region">
        <AsanaDrawing
          ref="editor"
          sketchbook
          :context="{ name: title, aliases: [], category_ids: [] }"
          :initial-drawing="initial"
          :disabled="!!row.archived_at || working || !draftReady || !!recovery"
          @change="drawingChanged"
          @image="(file, drawing) => save({ file, drawing })"
        />
      </div>
      <section v-if="queue.length" class="card transfer-queue">
        <h2>Фигуры для строки</h2>
        <p class="page-description">Порядок слева направо. Стрелки и номера остаются в скетчбуке.</p>
        <div class="queued">
          <article v-for="(item, index) in queue" :key="item.id">
            <img :src="item.url" :alt="item.name" /><input
              class="input"
              v-model="item.name"
              :disabled="working"
              @input="queueChanged"
              :aria-label="`Название фигуры ${index + 1}`"
            /><button class="btn btn-ghost" :disabled="working" @click="remove(index)">Убрать</button>
          </article>
        </div>
        <label class="target-field"
          >Вставить в практику
          <AppSelect class="select" v-model="target" :disabled="working" @change="queueChanged">
            <option v-for="doc in documents" :key="doc.id" :value="doc.id">{{ doc.title }}</option>
          </AppSelect></label
        ><button
          class="btn btn-primary"
          :disabled="working || saving || conflict || !!row.archived_at || !target"
          @click="insert"
        >
          Открыть практику для вставки
        </button>
        <p v-if="!documents.length">Сначала создайте практику в разделе «Практики».</p>
      </section>
      <div class="print-sketch" :class="format">
        <h1>{{ title }}</h1>
        <img v-if="row.image_url" :src="row.image_url" :alt="title" /></div></template
    ><Modal :open="showTransfer" title="Выделенный рисунок" @close="showTransfer = false"
      ><p>
        Вставка в ячейку сохраняет рисунок без добавления в каталог. Для нескольких поз выделите каждую
        отдельно.
      </p>
      <div class="sketch-actions">
        <button class="btn btn-primary" :disabled="queue.length >= 12" @click="enqueue">
          Добавить фигуру в очередь ячеек</button
        ><button
          class="btn btn-secondary"
          @click="
            showTransfer = false;
            showCreate = true;
          "
        >
          Создать карточку асаны
        </button>
      </div></Modal
    ><NativeAsanaDialog
      v-if="showCreate && candidate"
      :file="candidate.file"
      :drawing="candidate.drawing"
      @close="showCreate = false"
      @saved="
        showCreate = false;
        candidate = null;
        status = 'Карточка создана';
      "
  /></AppShell>
</template>
<style scoped>
.draft-choice {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  padding: var(--panel-padding);
  margin-bottom: 16px;
}
.draft-choice > div {
  flex: 1 1 260px;
}
.draft-choice p {
  margin: 6px 0 0;
}
.sketch-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin: 16px 0;
}
.sketch-actions strong {
  margin-right: auto;
  color: var(--accent-dark);
}
.sketch-header {
  margin: 0 0 var(--section-gap);
  justify-content: space-between;
}
.sketch-heading {
  min-width: 0;
}
.sketch-heading .page-title {
  margin: 12px 0 8px;
  overflow-wrap: anywhere;
  color: #203d2c;
  font-weight: 550;
}
.sketch-heading strong {
  font-size: 13px;
  font-weight: 500;
}
.back-link {
  color: var(--muted);
  font-size: 14px;
  text-decoration: none;
}
.sketch-header-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.sketch-meta {
  padding: var(--panel-padding);
  background: linear-gradient(150deg, #f8faf4, #fff 70%);
  border-color: #e0e7da;
}
.sketch-actions input {
  flex: 1;
  min-width: 0;
}
.sketch-meta .select {
  flex: 0 1 270px;
}
.target-field {
  display: grid;
  gap: 8px;
  margin: 18px 0 14px;
  max-width: 560px;
  min-width: 0;
}
.transfer-queue {
  margin-top: var(--section-gap);
  padding: var(--panel-padding);
  background: white;
  border: 1px solid var(--line);
  border-radius: var(--panel-radius);
  box-shadow: var(--panel-shadow);
}
.queued {
  display: flex;
  gap: 12px;
  overflow-x: auto;
  margin: 15px 0;
}
.queued article {
  flex: none;
  width: 145px;
}
.queued img {
  width: 100%;
  height: 125px;
  object-fit: contain;
}
.queued input {
  width: 100%;
}
.queued .btn {
  width: 100%;
  margin-top: 8px;
}
.transfer-queue h2 {
  font-size: 19px;
  font-weight: 550;
  color: #29492f;
  letter-spacing: -0.025em;
}
@media (max-width: 700px) {
  .sketch-header-actions {
    width: 100%;
  }
  .sketch-header-actions .btn {
    flex: 1 1 auto;
  }
  .sketch-meta > .input,
  .sketch-meta > .select,
  .sketch-meta > .btn {
    width: 100%;
    flex: 1 1 100%;
  }
  .transfer-queue > .btn,
  .sketch-actions .btn {
    white-space: normal;
  }
}
.print-sketch {
  display: none;
}
@media print {
  .sketch-actions,
  .drawing-region,
  .transfer-queue,
  .error-banner {
    display: none !important;
  }
  .print-sketch {
    display: block;
  }
  .print-sketch img {
    width: 100%;
    max-height: 250mm;
    object-fit: contain;
  }
  .print-sketch h1 {
    font-size: 14pt;
  }
}
</style>
