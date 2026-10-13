<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Plus, X, Save, ExternalLink, Copy, RefreshCw } from '@lucide/vue';
import AppShell from '@/shared/AppShell.vue';
import Modal from '@/shared/Modal.vue';
import AsanaPalette from '../components/AsanaPalette.vue';
import { api, ApiError } from '@/shared/api';
import type { Asana, Page } from '@/modules/catalog/types';
import type { Practice } from '../types';
interface Week {
  id: string;
  position: number;
  document_id: string;
  document: Practice;
}
interface Cycle {
  id: string;
  title: string;
  theme: string | null;
  revision: number;
  cloud_asana_ids: string[];
  cloud_asanas: Asana[];
  weeks: Week[];
  archived_at: string | null;
}
const props = defineProps<{ mesocycleId: string }>();
const cycle = ref<Cycle | null>(null),
  loading = ref(true),
  saving = ref(false),
  error = ref(''),
  conflict = ref(false),
  cloudOpen = ref(false),
  notice = ref('');
const frames = new Map<string, HTMLIFrameElement>();
const weekDirty = ref<Record<string, boolean>>({});
const weekSaveRequests = new Map<
  string,
  { resolve: (saved: boolean) => void; timer: ReturnType<typeof setTimeout> }
>();
const allSaving = ref(false);
const target = ref<{ documentId: string; cellId: string; label: string } | null>(null);
const replacement = ref<Week | null>(null),
  sources = ref<{ id: string; title: string }[]>([]),
  sourceId = ref(''),
  sourceKind = ref('practice'),
  replaceBusy = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined,
  alive = true,
  inFlight: Promise<boolean> | null = null;
const acknowledged = ref('');
const signature = () =>
  JSON.stringify(
    cycle.value && {
      title: cycle.value.title,
      theme: cycle.value.theme,
      cloud_asana_ids: cycle.value.cloud_asana_ids,
    },
  );
const dirty = computed(() => !!cycle.value && signature() !== acknowledged.value);
const selectedWeek = computed(() =>
  target.value ? cycle.value?.weeks.find((week) => week.document_id === target.value!.documentId) : null,
);
const status = computed(() =>
  conflict.value
    ? 'Мезоцикл изменён в другом окне'
    : saving.value
      ? 'Сохраняем облако и тему…'
      : dirty.value
        ? 'Есть изменения'
        : error.value
          ? 'Не удалось сохранить'
          : 'Тема и облако сохранены',
);
async function load() {
  loading.value = true;
  error.value = '';
  try {
    const result = await api<{ data: Cycle }>(`/api/mesocycles/${props.mesocycleId}`);
    if (!alive) return;
    cycle.value = result.data;
    weekDirty.value = Object.fromEntries(
      result.data.weeks.map((week) => [week.document_id, weekDirty.value[week.document_id] || false]),
    );
    acknowledged.value = signature();
    conflict.value = false;
    target.value = null;
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось открыть мезоцикл.';
  } finally {
    loading.value = false;
  }
}
function schedule() {
  clearTimeout(timer);
  if (!conflict.value) timer = setTimeout(() => void saveMetadata(), 800);
}
async function performSave(): Promise<boolean> {
  if (!cycle.value || conflict.value || !alive) return false;
  if (!dirty.value) return true;
  saving.value = true;
  error.value = '';
  const sent = signature(),
    outgoing = {
      title: cycle.value.title.trim(),
      theme: cycle.value.theme?.trim() || null,
      cloud_asana_ids: [...cycle.value.cloud_asana_ids],
      expected_revision: cycle.value.revision,
    };
  try {
    const response = await api<{ data: Cycle }>(`/api/mesocycles/${props.mesocycleId}`, {
      method: 'PUT',
      body: JSON.stringify(outgoing),
    });
    if (!alive) return false;
    cycle.value.revision = response.data.revision;
    acknowledged.value = sent;
    return true;
  } catch (e) {
    if (e instanceof ApiError && e.status === 409) conflict.value = true;
    error.value = e instanceof Error ? e.message : 'Не удалось сохранить мезоцикл.';
    return false;
  } finally {
    saving.value = false;
  }
}
async function saveMetadata(): Promise<boolean> {
  clearTimeout(timer);
  if (inFlight) {
    await inFlight;
    if (!alive || conflict.value) return false;
    return dirty.value && !error.value ? saveMetadata() : !!cycle.value && !dirty.value;
  }
  const attempt = performSave();
  inFlight = attempt;
  const result = await attempt;
  if (inFlight === attempt) inFlight = null;
  return result && dirty.value ? saveMetadata() : result;
}
function addCloud(card: Asana) {
  if (!cycle.value || card.archived_at) return;
  if (!cycle.value.cloud_asana_ids.includes(card.id)) {
    cycle.value.cloud_asana_ids.push(card.id);
    cycle.value.cloud_asanas.push(card);
    schedule();
  }
  notice.value = `«${card.name}» добавлена в облако. Можно выбрать ещё асаны.`;
}
function removeCloud(id: string) {
  if (!cycle.value) return;
  cycle.value.cloud_asana_ids = cycle.value.cloud_asana_ids.filter((value) => value !== id);
  cycle.value.cloud_asanas = cycle.value.cloud_asanas.filter((value) => value.id !== id);
  schedule();
}
function insertCloud(card: Asana) {
  if (!target.value) {
    notice.value = 'Сначала нажмите на ячейку нужной недели.';
    return;
  }
  if (card.archived_at) {
    notice.value = 'Эта асана в архиве. Восстановите её в библиотеке.';
    return;
  }
  frames.get(target.value.documentId)?.contentWindow?.postMessage(
    {
      type: 'ribka:meso-asana',
      documentId: target.value.documentId,
      cellId: target.value.cellId,
      asanaId: card.id,
    },
    window.location.origin,
  );
}
function receive(event: MessageEvent) {
  if (event.origin !== window.location.origin) return;
  const documentId = event.data?.documentId;
  if (!frames.get(documentId) || event.source !== frames.get(documentId)?.contentWindow) return;
  if (event.data.type === 'ribka:meso-cell' && typeof event.data.cellId === 'string')
    target.value = { documentId, cellId: event.data.cellId, label: event.data.label };
  if (event.data.type === 'ribka:meso-clear-target' && target.value?.documentId === documentId)
    target.value = null;
  if (event.data.type === 'ribka:meso-status')
    weekDirty.value[documentId] = !!event.data.dirty || !!event.data.pending;
  if (event.data.type === 'ribka:meso-saved') {
    const request = weekSaveRequests.get(documentId);
    if (request) {
      clearTimeout(request.timer);
      request.resolve(!!event.data.saved);
      weekSaveRequests.delete(documentId);
    }
  }
}
function saveWeek(documentId: string): Promise<boolean> {
  const frame = frames.get(documentId);
  if (!frame?.contentWindow) return Promise.resolve(false);
  const previous = weekSaveRequests.get(documentId);
  if (previous) {
    clearTimeout(previous.timer);
    previous.resolve(false);
  }
  return new Promise((resolve) => {
    const timer = setTimeout(() => {
      weekSaveRequests.delete(documentId);
      resolve(false);
    }, 35000);
    weekSaveRequests.set(documentId, { resolve, timer });
    frame.contentWindow!.postMessage({ type: 'ribka:meso-save', documentId }, window.location.origin);
  });
}
async function saveAll() {
  if (allSaving.value || !cycle.value) return;
  allSaving.value = true;
  const saved = await saveMetadata();
  if (!saved) {
    allSaving.value = false;
    return;
  }
  notice.value = 'Сохраняем пять недель…';
  const results = await Promise.all(cycle.value.weeks.map((week) => saveWeek(week.document_id)));
  notice.value = results.every(Boolean)
    ? 'Тема, облако и все пять недель сохранены.'
    : `Сохранено ${results.filter(Boolean).length} из пяти недель. Проверьте сообщения в редакторах остальных недель.`;
  allSaving.value = false;
}
async function loadSources() {
  replaceBusy.value = true;
  try {
    sources.value = [];
    let page = 1,
      last = 1;
    do {
      const response = await api<Page<{ id: string; title: string }>>(
        `/api/practices?kind=${sourceKind.value}&per_page=100&page=${page}`,
      );
      sources.value.push(...response.data);
      last = response.last_page;
      page++;
    } while (page <= last);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось загрузить основы.';
  } finally {
    replaceBusy.value = false;
  }
}
async function openReplace(week: Week) {
  replacement.value = week;
  sourceId.value = '';
  await loadSources();
}
async function replaceWeek() {
  if (!cycle.value || !replacement.value || !sourceId.value || replaceBusy.value) return;
  if (!(await saveMetadata())) return;
  if (
    !window.confirm(
      'Заменить неделю независимой копией выбранной последовательности? Предыдущая практика останется в разделе «Практики». Если в редакторе недели есть несохранённые изменения, сначала сохраните её.',
    )
  )
    return;
  replaceBusy.value = true;
  error.value = '';
  try {
    if (!(await saveWeek(replacement.value.document_id)))
      throw new Error('Не удалось сохранить предыдущую неделю. Проверьте её редактор перед заменой.');
    const response = await api<{ data: Cycle }>(`/api/mesocycles/${props.mesocycleId}/replace-week`, {
      method: 'POST',
      body: JSON.stringify({
        expected_revision: cycle.value.revision,
        position: replacement.value.position,
        source_document_id: sourceId.value,
      }),
    });
    cycle.value = response.data;
    acknowledged.value = signature();
    replacement.value = null;
    target.value = null;
  } catch (e) {
    if (e instanceof ApiError && e.status === 409) conflict.value = true;
    error.value = e instanceof Error ? e.message : 'Не удалось заменить неделю.';
  } finally {
    replaceBusy.value = false;
  }
}
function beforeUnload(event: BeforeUnloadEvent) {
  if (dirty.value || Object.values(weekDirty.value).some(Boolean)) {
    event.preventDefault();
    event.returnValue = '';
  }
}
function reloadCurrent() {
  if (
    (!dirty.value && !Object.values(weekDirty.value).some(Boolean)) ||
    window.confirm(
      'Загрузить актуальную версию мезоцикла? Локальные изменения темы и облака будут потеряны. Если другая вкладка заменила неделю, её несохранённые изменения тоже будут потеряны.',
    )
  )
    void load();
}
let removeGuard: (() => void) | undefined;
onMounted(() => {
  void load();
  window.addEventListener('message', receive);
  window.addEventListener('beforeunload', beforeUnload);
  removeGuard = router.on(
    'before',
    () =>
      (!dirty.value && !Object.values(weekDirty.value).some(Boolean)) ||
      window.confirm('Есть несохранённые изменения в мезоцикле или его неделях. Покинуть экран?'),
  );
});
onBeforeUnmount(() => {
  alive = false;
  clearTimeout(timer);
  window.removeEventListener('message', receive);
  window.removeEventListener('beforeunload', beforeUnload);
  removeGuard?.();
  for (const request of weekSaveRequests.values()) {
    clearTimeout(request.timer);
    request.resolve(false);
  }
  weekSaveRequests.clear();
});
</script>
<template>
  <Head :title="cycle?.title || 'Мезоцикл'" />
  <AppShell section="practices" wide>
    <Link href="/mesocycles" class="btn btn-ghost"><ArrowLeft :size="16" />Мезоциклы</Link>
    <p v-if="loading" class="muted" role="status">Открываем пять недель…</p>
    <p v-if="error" class="error-banner" role="alert">
      {{ error }}
      <button v-if="conflict" class="btn btn-secondary" @click="reloadCurrent">
        <RefreshCw :size="16" />Загрузить актуальную</button
      ><button v-else class="btn btn-ghost" @click="cycle ? saveMetadata() : load()">Повторить</button>
    </p>
    <template v-if="cycle">
      <header class="cycle-heading">
        <div>
          <input
            v-model="cycle.title"
            class="cycle-title"
            maxlength="160"
            aria-label="Название мезоцикла"
            :disabled="!!cycle.archived_at || conflict"
            @input="schedule"
          /><label class="cycle-theme"
            >Тема месяца
            <input
              v-model="cycle.theme"
              class="input"
              maxlength="240"
              :disabled="!!cycle.archived_at || conflict"
              @input="schedule"
          /></label>
        </div>
        <button
          class="btn btn-primary"
          :disabled="saving || allSaving || conflict || !!cycle.archived_at"
          @click="saveAll"
        >
          <Save :size="17" />Сохранить всё
        </button>
      </header>
      <div class="cycle-cloud">
        <header>
          <strong>Облако асан</strong><span role="status">{{ status }}</span
          ><button
            class="btn btn-secondary"
            :disabled="conflict || !!cycle.archived_at"
            @click="cloudOpen = true"
          >
            <Plus :size="16" />Добавить асаны
          </button>
        </header>
        <p v-if="!cycle.cloud_asanas.length" class="muted">
          Добавьте асаны в общий буфер. Выберите ячейку недели, затем нажмите на асану в облаке.
        </p>
        <div class="cloud-cards">
          <div v-for="card in cycle.cloud_asanas" :key="card.id" class="cloud-card">
            <button
              :title="card.name"
              :disabled="!!card.archived_at || !!cycle.archived_at"
              @click="insertCloud(card)"
            >
              <img :src="card.thumbnail_url || card.image_url" :alt="card.name" /><span>{{
                card.name
              }}</span></button
            ><button
              class="cloud-remove"
              :disabled="conflict || !!cycle.archived_at"
              :aria-label="`Убрать ${card.name} из облака`"
              @click="removeCloud(card.id)"
            >
              <X :size="13" />
            </button>
          </div>
        </div>
        <p class="cloud-target">
          {{
            target
              ? `Неделя ${(selectedWeek?.position ?? 0) + 1}: выбрана ячейка «${target.label}». Нажмите на асану выше.`
              : 'Нажмите на ячейку любой недели для заполнения из общего облака.'
          }}
        </p>
      </div>
      <p v-if="notice" class="success-banner" role="status">{{ notice }}</p>
      <div class="weeks-board">
        <section v-for="week in cycle.weeks" :key="week.document_id" class="week-column">
          <header>
            <h2>Неделя {{ week.position + 1 }}</h2>
            <a
              :href="`/practices/${week.document_id}/edit`"
              class="icon-btn"
              target="_blank"
              rel="noopener"
              title="Открыть редактор отдельным окном"
              ><ExternalLink :size="17" /></a
            ><button
              class="icon-btn"
              :disabled="replaceBusy || conflict || !!cycle.archived_at"
              @click="openReplace(week)"
              title="Взять другую базовую последовательность"
            >
              <Copy :size="17" />
            </button>
          </header>
          <iframe
            :ref="
              (element) => {
                if (element) frames.set(week.document_id, element as HTMLIFrameElement);
                else frames.delete(week.document_id);
              }
            "
            :src="`/practices/${week.document_id}/edit?embedded=1`"
            :title="`Редактор недели ${week.position + 1}`"
          />
        </section>
      </div>
      <p class="muted board-help">
        Каждая неделя сохраняется автоматически и имеет отдельную историю отмены. Стрелки, скобки, подписи,
        связки и печать доступны внутри её редактора. При небольшом экране прокрутите недели по горизонтали.
      </p>
    </template>
    <Modal :open="cloudOpen" title="Добавить асаны в облако" @close="cloudOpen = false"
      ><p class="muted">Выберите несколько асан. Они будут доступны для каждой недели.</p>
      <AsanaPalette
        v-if="cloudOpen"
        :document-id="mesocycleId"
        :active="true"
        :mobile-open="false"
        embedded
        @select="addCloud"
      />
      <p v-if="notice" class="success-banner" role="status">{{ notice }}</p>
      <template #footer
        ><button class="btn btn-primary" @click="cloudOpen = false">Готово</button></template
      ></Modal
    >
    <Modal
      :open="!!replacement"
      title="Базовая последовательность для недели"
      @close="!replaceBusy && (replacement = null)"
      ><p class="muted">Создадим независимую копию. Предыдущая практика сохранится отдельно.</p>
      <label class="field"
        ><span class="label">Источник</span
        ><select
          v-model="sourceKind"
          class="select"
          @change="
            sourceId = '';
            loadSources();
          "
        >
          <option value="practice">Практики</option>
          <option value="template">Шаблоны</option>
        </select></label
      ><select v-model="sourceId" class="select" :disabled="replaceBusy">
        <option value="">Выберите последовательность</option>
        <option v-for="source in sources" :key="source.id" :value="source.id">{{ source.title }}</option>
      </select>
      <p v-if="error" class="error-banner">{{ error }}</p>
      <template #footer
        ><button class="btn btn-secondary" :disabled="replaceBusy" @click="replacement = null">Отмена</button
        ><button class="btn btn-primary" :disabled="replaceBusy || !sourceId" @click="replaceWeek">
          Создать копию для недели
        </button></template
      ></Modal
    >
  </AppShell>
</template>
<style scoped>
.cycle-heading {
  display: flex;
  gap: 24px;
  align-items: center;
  margin: 12px 0 20px;
}
.cycle-heading > div {
  flex: 1;
}
.cycle-title {
  display: block;
  width: 100%;
  border: 0;
  background: transparent;
  font-size: 28px;
  font-weight: 700;
  padding: 6px 0;
  color: var(--text);
}
.cycle-theme {
  display: flex;
  gap: 12px;
  align-items: center;
  color: var(--muted);
  font-size: 13px;
}
.cycle-theme .input {
  max-width: 700px;
}
.cycle-cloud {
  position: sticky;
  top: 0;
  z-index: 30;
  max-height: 250px;
  overflow: auto;
  background: white;
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 16px;
  margin: 16px 0;
}
.cycle-cloud > header {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 10px;
}
.cycle-cloud > header > span {
  margin-left: auto;
  font-size: 12px;
  color: var(--muted);
}
.cloud-cards {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding: 6px 0;
}
.cloud-card {
  position: relative;
  flex: none;
  width: 92px;
}
.cloud-card > button:first-child {
  display: flex;
  flex-direction: column;
  align-items: center;
  background: white;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 8px 5px;
  width: 100%;
  height: 108px;
}
.cloud-card img {
  width: 65px;
  height: 65px;
  object-fit: contain;
}
.cloud-card span {
  font-size: 10px;
  max-width: 80px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.cloud-remove {
  position: absolute;
  right: 0;
  top: 0;
  background: white;
  border: 1px solid var(--border);
  border-radius: 50%;
  width: 21px;
  height: 21px;
  display: grid;
  place-items: center;
}
.cloud-target {
  color: #397a28;
  font-size: 13px;
  margin-top: 8px;
}
.weeks-board {
  display: grid;
  grid-template-columns: repeat(5, minmax(200px, 1fr));
  gap: 14px;
  overflow-x: auto;
  padding-bottom: 12px;
}
.week-column {
  border: 1px solid var(--border);
  border-radius: 15px;
  overflow: hidden;
  background: white;
}
.week-column > header {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 12px;
  background: #edf5e7;
}
.week-column h2 {
  flex: 1;
  font-size: 17px;
  margin: 0;
}
.week-column iframe {
  display: block;
  width: 100%;
  height: 720px;
  max-height: 78vh;
  border: 0;
  background: #f7f9f6;
}
.board-help {
  font-size: 12px;
  line-height: 1.7;
  margin: 14px 0;
}
@media (max-width: 650px) {
  .cycle-heading {
    flex-wrap: wrap;
  }
  .cycle-heading > div {
    width: 100%;
    flex: none;
  }
  .cycle-cloud > header {
    flex-wrap: wrap;
  }
  .cycle-cloud > header > span {
    margin-left: 0;
  }
}
</style>
