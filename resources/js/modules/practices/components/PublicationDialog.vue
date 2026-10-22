<script setup lang="ts">
import { ref, watch } from 'vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
interface Publication {
  id: string;
  revision: number;
  allow_copy: boolean;
  expires_at: string | null;
  revoked_at: string | null;
  created_at: string;
}
const props = defineProps<{
  open: boolean;
  documentId: string;
  revision: number;
  showNames?: boolean;
  orientation?: string;
}>();
const emit = defineEmits<{ close: [] }>();
const items = ref<Publication[]>([]),
  names = ref(false),
  notes = ref(false),
  allowCopy = ref(false),
  days = ref(0),
  busy = ref(false),
  error = ref(''),
  preview = ref(''),
  link = ref(''),
  token = ref('');
watch(
  () => props.open,
  (open) => {
    if (open) {
      names.value = props.showNames || false;
      notes.value = false;
      allowCopy.value = false;
      days.value = 0;
      preview.value = '';
      link.value = '';
      token.value = '';
      error.value = '';
      void load();
    }
  },
);
watch([names, notes, allowCopy, days], () => {
  preview.value = '';
});
async function load() {
  try {
    items.value = (
      await api<{ data: Publication[] }>(`/api/practices/${props.documentId}/publications`)
    ).data;
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось открыть ссылки.';
  }
}
function body() {
  return {
    expected_revision: props.revision,
    show_names: names.value,
    include_notes: notes.value,
    allow_copy: allowCopy.value,
    orientation: props.orientation || 'landscape',
    expires_at: days.value ? new Date(Date.now() + days.value * 86400000).toISOString() : null,
  };
}
async function prepare() {
  busy.value = true;
  error.value = '';
  try {
    preview.value = (
      await api<{ data: { html: string } }>(`/api/practices/${props.documentId}/publications/preview`, {
        method: 'POST',
        body: JSON.stringify(body()),
      })
    ).data.html;
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось подготовить публикацию.';
  } finally {
    busy.value = false;
  }
}
async function publish() {
  if (!preview.value || busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    const result = await api<{ data: { url: string; token: string } }>(
      `/api/practices/${props.documentId}/publications`,
      { method: 'POST', body: JSON.stringify(body()) },
    );
    link.value = result.data.url;
    token.value = result.data.token;
    await load();
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось создать ссылку.';
  } finally {
    busy.value = false;
  }
}
async function revoke(item: Publication) {
  if (
    !window.confirm(
      'Отозвать ссылку? По ней закроются и лист, и изображения. Уже скачанные копии останутся у получателей.',
    )
  )
    return;
  busy.value = true;
  error.value = '';
  try {
    await api(`/api/practices/${props.documentId}/publications/${item.id}/revoke`, {
      method: 'POST',
      body: JSON.stringify({ expected_revision: item.revision }),
    });
    link.value = '';
    token.value = '';
    await load();
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось отозвать ссылку.';
  } finally {
    busy.value = false;
  }
}
async function copyLink() {
  try {
    await navigator.clipboard.writeText(link.value);
  } catch {
    error.value = 'Выделите ссылку и скопируйте вручную.';
  }
}
</script>
<template>
  <Modal :open="open" title="Поделиться по ссылке" @close="!busy && emit('close')"
    ><p class="muted">
      Будет опубликована отдельная сохранённая версия. Поздние правки занятия её не изменят. Личное аудио и
      исходники рисунков не передаются.
    </p>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <div class="publication-options">
      <label class="check-label"><input v-model="names" type="checkbox" />Названия асан</label
      ><label class="check-label"
        ><input v-model="notes" type="checkbox" />Опубликовать подписи и пояснения</label
      ><label class="check-label"
        ><input v-model="allowCopy" type="checkbox" />Разрешить независимую копию в свой кабинет</label
      ><label class="field"
        ><span class="label">Срок ссылки</span
        ><select v-model.number="days" class="select">
          <option :value="0">До отзыва</option>
          <option :value="1">1 день</option>
          <option :value="7">7 дней</option>
          <option :value="30">30 дней</option>
        </select></label
      >
    </div>
    <button class="btn btn-secondary" :disabled="busy" @click="prepare">Проверить публикуемый лист</button
    ><iframe
      v-if="preview"
      class="publication-preview"
      :srcdoc="preview"
      title="Предпросмотр публикуемой версии"
      sandbox="allow-same-origin"
    />
    <div v-if="link" class="created-link">
      <strong>Ссылка создана. Сохраните её: повторно показать её нельзя.</strong
      ><input
        class="input"
        :value="link"
        readonly
        aria-label="Ссылка на опубликованную практику"
        @focus="($event.target as HTMLInputElement).select()"
      /><button class="btn btn-secondary" @click="copyLink">Скопировать</button
      ><a :href="link" class="btn btn-ghost" target="_blank" rel="noopener noreferrer">Открыть публикацию</a
      ><img
        :src="`/api/published/${token}/qr`"
        alt="QR-код опубликованной практики"
        width="180"
        height="180"
      />
    </div>
    <h3 v-if="items.length">Созданные публикации</h3>
    <div v-for="item in items" :key="item.id" class="publication-row">
      <span
        >{{ new Date(item.created_at).toLocaleString('ru-RU') }} ·
        {{
          item.revoked_at
            ? 'Отозвана'
            : item.expires_at && new Date(item.expires_at) < new Date()
              ? 'Срок истёк'
              : 'Действует'
        }}</span
      ><button v-if="!item.revoked_at" class="btn btn-ghost" :disabled="busy" @click="revoke(item)">
        Отозвать
      </button>
    </div>
    <template #footer
      ><button class="btn btn-secondary" :disabled="busy" @click="emit('close')">Закрыть</button
      ><button class="btn btn-primary" :disabled="busy || !preview || !!link" @click="publish">
        Опубликовать проверенную версию
      </button></template
    ></Modal
  >
</template>
<style scoped>
.publication-options,
.created-link {
  display: grid;
  gap: 14px;
  margin: 18px 0;
}
.publication-preview {
  width: 100%;
  height: 380px;
  border: 1px solid #dce6d6;
  background: white;
  border-radius: 8px;
}
.publication-row {
  display: flex;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  margin: 10px 0;
}
.created-link img {
  margin: auto;
}
</style>
