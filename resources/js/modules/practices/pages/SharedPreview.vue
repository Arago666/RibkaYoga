<script setup lang="ts">
import AppSelect from '@/shared/AppSelect.vue';
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { uid } from '../types';
import { ArrowLeft, RefreshCw, MessageSquare, Pencil, Trash2, CornerDownRight } from '@lucide/vue';
import AppShell from '@/shared/AppShell.vue';
import SheetViewport from '@/shared/SheetViewport.vue';
import { api, ApiError } from '@/shared/api';
import type { PracticeComment, SharedPracticeData } from '../sharing-types';
const props = defineProps<{ documentId: string }>();
const copyKey = ref(uid());
async function copyAllowed() {
  if (!state.value?.access.can_copy) return;
  try {
    const result = await api<{ data: { id: string } }>(`/api/shared-practices/${props.documentId}/copy`, {
      method: 'POST',
      body: JSON.stringify({ idempotency_key: copyKey.value }),
    });
    router.visit(`/practices/${result.data.id}/edit`);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось создать независимую копию.';
  }
}
const state = ref<SharedPracticeData | null>(null),
  loading = ref(true),
  error = ref(''),
  busy = ref(false),
  body = ref(''),
  cellId = ref(''),
  shareId = ref(''),
  editing = ref<PracticeComment | null>(null),
  showNames = ref(false);
const composer = ref<HTMLTextAreaElement | null>(null);
const frame = ref<HTMLIFrameElement | null>(null),
  frameHeight = ref(650),
  printReady = ref(false);
async function sheetLoaded() {
  printReady.value = false;
  const content = frame.value?.contentDocument;
  if (!content?.querySelector('h1')) return;
  await content.fonts.ready;
  await Promise.all(
    Array.from(content.images).map((image) =>
      image.complete
        ? Promise.resolve()
        : new Promise<void>((resolve) => {
            image.onload = () => resolve();
            image.onerror = () => resolve();
          }),
    ),
  );
  if (Array.from(content.images).some((image) => !image.naturalWidth)) {
    error.value = 'Не все изображения загрузились. Обновите лист перед печатью.';
    return;
  }
  frameHeight.value = Math.max(500, content.documentElement.scrollHeight + 30);
  printReady.value = true;
}
function printSheet() {
  if (printReady.value) frame.value?.contentWindow?.print();
}
const channels = computed(() => state.value?.access.channels.filter((share) => !share.revoked_at) || []);
const cells = computed(
  () =>
    state.value?.document.blocks.flatMap((block, blockIndex) =>
      block.rows.flatMap((row, rowIndex) =>
        row.cells.map((cell, cellIndex) => ({
          id: cell.id,
          name: `${blockIndex + 1}.${rowIndex + 1}.${cellIndex + 1} · ${cell.card?.name || cell.custom_name || 'Пустая ячейка'}`,
        })),
      ),
    ) || [],
);
function accept(data: SharedPracticeData) {
  state.value = data;
  if (data.access.owner) {
    if (!channels.value.some((channel) => channel.id === shareId.value))
      shareId.value = channels.value[0]?.id || '';
  } else shareId.value = data.access.share_id || '';
}
async function load() {
  loading.value = true;
  error.value = '';
  try {
    accept((await api<{ data: SharedPracticeData }>(`/api/shared-practices/${props.documentId}`)).data);
  } catch (failure) {
    state.value = null;
    error.value =
      failure instanceof ApiError && failure.status === 404
        ? 'Практика недоступна. Владелец мог закрыть доступ или убрать лист в архив.'
        : failure instanceof Error
          ? failure.message
          : 'Не удалось открыть лист.';
  } finally {
    loading.value = false;
  }
}
function cancelEdit() {
  editing.value = null;
  body.value = '';
}
function edit(comment: PracticeComment) {
  editing.value = comment;
  body.value = comment.body;
  composer.value?.focus();
}
function reply(comment: PracticeComment) {
  cancelEdit();
  shareId.value = comment.share_id;
  composer.value?.focus();
}
async function save() {
  if (!state.value || busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    const response = await api<{ data: SharedPracticeData }>(
      `/api/shared-practices/${props.documentId}/comments${editing.value ? `/${editing.value.id}` : ''}`,
      {
        method: editing.value ? 'PATCH' : 'POST',
        body: JSON.stringify({
          body: body.value.trim(),
          ...(editing.value
            ? { expected_revision: editing.value.revision }
            : {
                cell_id: cellId.value || null,
                share_id: shareId.value || null,
                expected_document_revision: state.value.document.revision,
              }),
        }),
      },
    );
    accept(response.data);
    cancelEdit();
    cellId.value = '';
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось сохранить комментарий.';
  } finally {
    busy.value = false;
  }
}
async function remove(comment: PracticeComment) {
  if (busy.value || !window.confirm('Удалить свой комментарий?')) return;
  busy.value = true;
  error.value = '';
  try {
    accept(
      (
        await api<{ data: SharedPracticeData }>(
          `/api/shared-practices/${props.documentId}/comments/${comment.id}`,
          {
            method: 'DELETE',
            body: JSON.stringify({ expected_revision: comment.revision }),
          },
        )
      ).data,
    );
    if (editing.value?.id === comment.id) cancelEdit();
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось удалить комментарий.';
  } finally {
    busy.value = false;
  }
}
function date(value: string) {
  return new Date(value).toLocaleString('ru-RU', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  });
}
onMounted(load);
</script>
<template>
  <Head :title="state ? `${state.document.title} — совместный просмотр` : 'Просмотр практики'" />
  <AppShell section="practices" wide>
    <header class="page-header shared-header">
      <div class="shared-intro">
        <Link :href="state?.access.owner ? '/practices' : '/shared-practices'" class="btn btn-ghost"
          ><ArrowLeft :size="16" />{{ state?.access.owner ? 'Мои практики' : 'Мне дали доступ' }}</Link
        >
        <h1 class="page-title">{{ state?.document.title || 'Просмотр практики' }}</h1>
      </div>
      <button class="btn btn-secondary" :disabled="loading || busy" @click="load">
        <RefreshCw :size="16" />Обновить лист
      </button>
    </header>
    <div v-if="error" class="error-banner" role="alert">{{ error }}</div>
    <p v-if="loading" class="muted" role="status">Открываем практику…</p>
    <div v-else-if="state" class="shared-layout">
      <section class="card shared-sheet">
        <div class="shared-owner">
          {{ state.access.owner_name }} ·
          {{
            state.access.owner
              ? 'Ваш лист'
              : state.access.can_comment
                ? 'Просмотр и комментарии'
                : 'Только просмотр'
          }}
          <label><input v-model="showNames" type="checkbox" />Названия асан</label>
          <button v-if="state.access.can_copy" class="btn btn-secondary" @click="copyAllowed">
            Создать копию в своём кабинете
          </button>
          <Link class="btn btn-primary" :href="`/practices/${documentId}/run?mode=practice`"
            >Практиковать</Link
          >
        </div>
        <button class="btn btn-secondary" :disabled="!printReady" @click="printSheet">
          Печать / PDF браузера
        </button>
        <div class="shared-renderer">
          <SheetViewport :width="1050" :height="frameHeight">
            <iframe
              ref="frame"
              :key="`${state.document.revision}:${showNames}`"
              :src="`/api/shared-practices/${documentId}/sheet?show_names=${showNames ? 1 : 0}`"
              :style="{ height: `${frameHeight}px` }"
              title="Лист практики"
              sandbox="allow-same-origin allow-modals"
              @load="sheetLoaded"
            />
          </SheetViewport>
        </div>
      </section>
      <aside class="card shared-comments">
        <h2><MessageSquare :size="19" />Комментарии</h2>
        <p class="muted">
          {{
            state.access.owner
              ? 'Вы видите комментарии всех преподавателей. Каждый преподаватель видит только свою переписку с вами.'
              : 'Здесь ваша переписка с владельцем практики.'
          }}
        </p>
        <form v-if="state.access.can_comment" class="comment-form" @submit.prevent="save">
          <div v-if="state.access.owner && !editing" class="field">
            <label class="label" for="comment-channel">Для преподавателя</label
            ><AppSelect id="comment-channel" v-model="shareId" class="select" :disabled="busy" required>
              <option value="" disabled>Выберите преподавателя</option>
              <option v-for="channel in channels" :key="channel.id" :value="channel.id">
                {{ channel.recipient.name }} · {{ channel.recipient.login }}
              </option>
            </AppSelect>
          </div>
          <div v-if="!editing" class="field">
            <label class="label" for="comment-cell">Комментарий к</label
            ><AppSelect id="comment-cell" v-model="cellId" class="select" :disabled="busy">
              <option value="">Практике в целом</option>
              <option v-for="cell in cells" :key="cell.id" :value="cell.id">{{ cell.name }}</option>
            </AppSelect>
          </div>
          <div class="field">
            <label class="label" for="comment-body">{{
              editing ? 'Изменить комментарий' : 'Новый комментарий'
            }}</label
            ><textarea
              id="comment-body"
              ref="composer"
              v-model="body"
              class="textarea"
              rows="3"
              required
              maxlength="2000"
              :disabled="busy"
            />
          </div>
          <div class="comment-submit">
            <button
              class="btn btn-primary"
              type="submit"
              :disabled="busy || !body.trim() || (!editing && !shareId)"
            >
              {{ busy ? 'Сохраняем…' : editing ? 'Сохранить' : 'Отправить' }}</button
            ><button v-if="editing" class="btn btn-ghost" type="button" :disabled="busy" @click="cancelEdit">
              Отмена
            </button>
          </div>
          <p v-if="state.access.owner && !channels.length" class="muted">
            Дайте преподавателю доступ, чтобы начать переписку.
          </p>
        </form>
        <p v-else class="muted">
          Владелец разрешил просмотр. Для отправки комментариев нужен доступ «Просмотр и комментарии».
        </p>
        <div class="comment-list">
          <article v-for="comment in state.comments" :key="comment.id" class="comment">
            <header>
              <strong>{{ comment.author }}</strong
              ><time>{{ date(comment.created_at) }}</time>
            </header>
            <span v-if="comment.context" class="comment-context"
              >{{ comment.context }} · версия {{ comment.document_revision }}</span
            >
            <p>{{ comment.body }}</p>
            <div class="comment-tools">
              <button
                v-if="state.access.owner && channels.some((channel) => channel.id === comment.share_id)"
                class="btn btn-ghost"
                :disabled="busy"
                @click="reply(comment)"
              >
                <CornerDownRight :size="14" />Ответить
              </button>
              <template v-if="comment.can_edit"
                ><button
                  class="icon-btn"
                  :disabled="busy"
                  aria-label="Изменить свой комментарий"
                  @click="edit(comment)"
                >
                  <Pencil :size="14" /></button
                ><button
                  class="icon-btn"
                  :disabled="busy"
                  aria-label="Удалить свой комментарий"
                  @click="remove(comment)"
                >
                  <Trash2 :size="14" /></button
              ></template>
            </div>
          </article>
          <p v-if="!state.comments.length" class="muted">Комментариев пока нет.</p>
        </div>
      </aside>
    </div>
  </AppShell>
</template>
<style scoped>
.shared-renderer {
  overflow: auto;
  margin-top: var(--section-gap);
  padding-top: var(--section-gap);
  border-top: 1px solid var(--line);
}
.shared-renderer iframe {
  width: 100%;
  min-width: 1050px;
  border: 0;
  display: block;
}
.shared-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 20px;
  margin-bottom: var(--section-gap);
  flex-wrap: wrap;
}
.shared-intro {
  flex: 1 1 280px;
  min-width: 0;
}
.shared-intro .page-title {
  margin-top: 12px;
  color: #203d2c;
  overflow-wrap: anywhere;
}
.shared-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 360px;
  gap: var(--section-gap);
  align-items: start;
}
.shared-sheet {
  min-width: 0;
  padding: var(--panel-padding);
}
.shared-owner {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  align-items: center;
  font-size: 13px;
  color: var(--muted);
  margin-bottom: 20px;
}
.shared-owner label {
  display: flex;
  gap: 7px;
  align-items: center;
  flex: none;
  min-height: var(--control-height);
}
.shared-comments {
  padding: var(--panel-padding);
  min-width: 0;
}
.shared-comments h2 {
  display: flex;
  gap: 8px;
  align-items: center;
  font-size: 19px;
  font-weight: 550;
  color: #29492f;
  letter-spacing: -0.025em;
  margin: 0 0 20px;
}
.shared-comments > .muted {
  font-size: 13px;
  line-height: 1.65;
  margin: 0 0 16px;
}
.comment-form {
  display: grid;
  gap: 16px;
  padding-bottom: var(--section-gap);
  border-bottom: 1px solid var(--line);
}
.comment-submit {
  display: flex;
  gap: 8px;
}
.comment-list {
  display: grid;
  gap: 16px;
  margin-top: var(--section-gap);
}
.comment {
  padding: 16px;
  background: var(--surface-soft);
  border: 1px solid var(--line);
  border-radius: 18px;
}
.comment header {
  display: flex;
  justify-content: space-between;
  gap: 8px;
}
.comment strong {
  font-size: 0.82rem;
}
.comment time,
.comment-context {
  font-size: 11px;
  color: var(--muted);
}
.comment-context {
  display: block;
  margin-top: 7px;
}
.comment p {
  font-size: 0.84rem;
  line-height: 1.6;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  margin: 10px 0 0;
}
.comment-tools {
  display: flex;
  justify-content: flex-end;
  gap: 4px;
  margin-top: 7px;
  flex-wrap: wrap;
}
.comment-tools .btn {
  font-size: 12px;
  padding: 10px 12px;
}
.comment-tools .icon-btn {
  width: var(--control-height);
  height: var(--control-height);
}
@media (max-width: 1100px) {
  .shared-layout {
    grid-template-columns: minmax(0, 1fr) 310px;
  }
}
@media (max-width: 900px) {
  .shared-layout {
    grid-template-columns: 1fr;
  }
  .shared-comments {
    width: 100%;
  }
  .shared-owner {
    flex-wrap: wrap;
  }
}
@media print {
  .shared-header,
  .shared-owner,
  .shared-comments {
    display: none;
  }
  .shared-layout {
    display: block;
  }
}
</style>
