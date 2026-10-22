<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppShell from '@/shared/AppShell.vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import { uid } from '@/shared/uuid';
import AsanaDrawing from '@/modules/catalog/components/AsanaDrawing.vue';
import { emptyDrawing, type DrawingDocument } from '@/modules/catalog/drawing';
import NativeAsanaDialog from '../NativeAsanaDialog.vue';
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
}
const row = ref<Sketch | null>(null),
  initial = ref<DrawingDocument | null>(null),
  editor = ref<Handle | null>(null),
  title = ref(''),
  format = ref('square'),
  status = ref('Загружаем…'),
  error = ref(''),
  saving = ref(false),
  changed = ref(false),
  candidate = ref<Exported | null>(null),
  queue = ref<(Exported & { id: string; name: string; url: string })[]>([]),
  showTransfer = ref(false),
  showCreate = ref(false),
  documents = ref<{ id: string; title: string }[]>([]),
  target = ref(''),
  working = ref(false);
const auth = usePage().props.auth as { user?: { id: number }; workspace?: { id: string } } | undefined;
const draftKey = computed(() => `sketch:${auth?.user?.id}:${auth?.workspace?.id}:${props.pageId}`);
let timer: ReturnType<typeof setTimeout> | undefined;
let generation = 0;
async function save(exported?: Exported) {
  if (!row.value || saving.value) return false;
  const current = generation;
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
    if (current === generation && (exported || !changed.value)) {
      changed.value = false;
      status.value = 'Сохранено';
    } else schedule();
    return !changed.value;
  } catch (f) {
    status.value = 'Не сохранено — черновик на устройстве';
    error.value = f instanceof Error ? f.message : 'Ошибка сохранения';
    return false;
  } finally {
    saving.value = false;
  }
}
async function saveAll() {
  if (!row.value || saving.value || !editor.value) return false;
  try {
    const exported = await editor.value.exportDrawing();
    return await save(exported);
  } catch (f) {
    error.value = f instanceof Error ? f.message : 'Не удалось подготовить изображение.';
    return false;
  }
}
function schedule() {
  changed.value = true;
  status.value = 'Есть изменения';
  generation++;
  if (timer) clearTimeout(timer);
  timer = setTimeout(() => void saveAll(), 2200);
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
}
function remove(index: number) {
  URL.revokeObjectURL(queue.value[index].url);
  queue.value.splice(index, 1);
}
async function insert() {
  if (!target.value || !queue.value.length) return;
  working.value = true;
  error.value = '';
  try {
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
    const saved = await saveAll();
    if (!saved || changed.value || saving.value)
      throw new Error('Сначала сохраните лист. Вставка подготовлена, её можно повторить.');
    router.visit(`/practices/${target.value}/edit?insert_snapshots=${ids.join(',')}`);
  } catch (f) {
    error.value = f instanceof Error ? f.message : 'Ошибка подготовки вставки';
  } finally {
    working.value = false;
  }
}
async function copy() {
  if (!row.value || !editor.value) return;
  working.value = true;
  error.value = '';
  try {
    const exported = await editor.value.exportDrawing();
    const created = (
      await api<{ data: Sketch }>('/api/sketchbook', {
        method: 'POST',
        body: JSON.stringify({ title: `${title.value.slice(0, 150)} — копия`, page_format: format.value }),
      })
    ).data;
    const form = new FormData();
    form.set('_method', 'PUT');
    form.set('expected_revision', String(created.revision));
    form.set('title', created.title);
    form.set('page_format', created.page_format);
    form.set('image', exported.file);
    form.set('drawing_source', JSON.stringify(exported.drawing));
    await api(`/api/sketchbook/${created.id}`, { method: 'POST', body: form });
    changed.value = false;
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
  if (changed.value) {
    event.preventDefault();
    event.returnValue = '';
  }
}
onMounted(async () => {
  try {
    row.value = (await api<{ data: Sketch }>(`/api/sketchbook/${props.pageId}`)).data;
    initial.value = row.value.drawing_source;
    title.value = row.value.title;
    format.value = row.value.page_format;
    status.value = 'Сохранено';
    documents.value = (
      await api<{ data: { id: string; title: string }[] }>('/api/practices?kind=practice&per_page=100')
    ).data;
    target.value = documents.value[0]?.id || '';
  } catch (f) {
    error.value = f instanceof Error ? f.message : 'Лист недоступен';
  }
  window.addEventListener('beforeunload', beforeUnload);
});
onBeforeUnmount(() => {
  if (timer) clearTimeout(timer);
  queue.value.forEach((x) => URL.revokeObjectURL(x.url));
  window.removeEventListener('beforeunload', beforeUnload);
});
</script>
<template>
  <AppShell section="sketchbook" wide
    ><Head :title="title || 'Скетчбук'" />
    <div class="sketch-actions">
      <Link href="/sketchbook">← Все листы</Link><strong role="status">{{ status }}</strong
      ><button class="btn" :disabled="!row || saving || working" @click="copy">Сохранить копию</button
      ><button class="btn primary" :disabled="!row || saving || working" @click="saveAll">Сохранить</button
      ><button class="btn" :disabled="!row?.image_url" @click="print">Печать / PDF</button>
    </div>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <template v-if="row"
      ><div class="sketch-actions">
        <input
          v-model="title"
          aria-label="Название листа"
          maxlength="160"
          :disabled="working"
          @change="schedule"
        /><select v-model="format" aria-label="Формат печати" :disabled="working" @change="schedule">
          <option value="square">Квадратный лист</option>
          <option value="a4-portrait">A4 — книжная печать</option>
          <option value="a4-landscape">A4 — альбомная печать</option></select
        ><button class="btn" :disabled="!!row.archived_at || working" @click="selected">
          Выделенное → асана или ячейка
        </button>
      </div>
      <p v-if="row.archived_at">Лист в архиве. Для изменения восстановите его в списке.</p>
      <div class="drawing-region">
        <AsanaDrawing
          ref="editor"
          :context="{ name: title, aliases: [], category_ids: [] }"
          :initial-drawing="initial"
          :draft-key="draftKey"
          :disabled="!!row.archived_at || working"
          @change="schedule"
          @image="(file, drawing) => save({ file, drawing })"
        />
      </div>
      <section v-if="queue.length" class="transfer-queue">
        <h2>Фигуры для строки</h2>
        <p>Порядок слева направо. Стрелки и номера остаются в скетчбуке.</p>
        <div class="queued">
          <article v-for="(item, index) in queue" :key="item.id">
            <img :src="item.url" :alt="item.name" /><input
              v-model="item.name"
              :aria-label="`Название фигуры ${index + 1}`"
            /><button class="btn" @click="remove(index)">Убрать</button>
          </article>
        </div>
        <label
          >Вставить в практику
          <select v-model="target">
            <option v-for="doc in documents" :key="doc.id" :value="doc.id">{{ doc.title }}</option>
          </select></label
        ><button class="btn primary" :disabled="working || !target" @click="insert">
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
        <button class="btn primary" :disabled="queue.length >= 12" @click="enqueue">
          Добавить фигуру в очередь ячеек</button
        ><button
          class="btn"
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
.sketch-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin: 16px 0;
}
.sketch-actions strong {
  margin-right: auto;
  color: #477538;
}
.sketch-actions input {
  flex: 1;
  min-width: 200px;
}
.transfer-queue {
  margin-top: 22px;
  padding: 20px;
  background: white;
  border: 1px solid #dce5d6;
  border-radius: 15px;
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
