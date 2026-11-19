<script setup lang="ts">
import AppSelect from '@/shared/AppSelect.vue';
import { onMounted, ref, watch } from 'vue';
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
  documentId: string;
  revision: number;
  showNames?: boolean;
  orientation?: string;
}>();
const busy = defineModel<boolean>('busy', { required: true });
const items = ref<Publication[]>([]),
  names = ref(props.showNames ?? false),
  notes = ref(false),
  allowCopy = ref(false),
  days = ref(0),
  orientation = ref(props.orientation === 'portrait' ? 'portrait' : 'landscape'),
  error = ref(''),
  preview = ref(''),
  link = ref(''),
  token = ref(''),
  publishedId = ref(''),
  revokeTarget = ref<string | null>(null);
let prepared: ReturnType<typeof body> | null = null;
onMounted(load);
watch([names, notes, allowCopy, days, orientation], () => {
  preview.value = '';
  prepared = null;
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
    orientation: orientation.value,
    expires_at: days.value ? new Date(Date.now() + days.value * 86400000).toISOString() : null,
  };
}
async function prepare() {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  preview.value = '';
  prepared = null;
  const options = body();
  try {
    preview.value = (
      await api<{ data: { html: string } }>(`/api/practices/${props.documentId}/publications/preview`, {
        method: 'POST',
        body: JSON.stringify(options),
      })
    ).data.html;
    prepared = options;
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось подготовить публикацию.';
  } finally {
    busy.value = false;
  }
}
async function publish() {
  if (!preview.value || !prepared || busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    const result = await api<{ data: { id: string; url: string; token: string } }>(
      `/api/practices/${props.documentId}/publications`,
      { method: 'POST', body: JSON.stringify(prepared) },
    );
    link.value = result.data.url;
    token.value = result.data.token;
    publishedId.value = result.data.id;
    await load();
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось создать ссылку.';
  } finally {
    busy.value = false;
  }
}
async function revoke(item: Publication) {
  if (busy.value) return;
  if (revokeTarget.value !== item.id) {
    revokeTarget.value = item.id;
    return;
  }
  busy.value = true;
  error.value = '';
  try {
    await api(`/api/practices/${props.documentId}/publications/${item.id}/revoke`, {
      method: 'POST',
      body: JSON.stringify({ expected_revision: item.revision }),
    });
    revokeTarget.value = null;
    if (item.id === publishedId.value) {
      link.value = '';
      token.value = '';
      publishedId.value = '';
    }
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
  <div>
    <p class="muted">
      Любой, у кого есть ссылка, сможет открыть опубликованную версию. Поздние правки занятия её не изменят.
      Личное аудио и исходники рисунков не передаются.
    </p>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <div class="publication-options">
      <label class="check-label"
        ><input v-model="names" type="checkbox" :disabled="busy" />Названия асан</label
      ><label class="check-label"
        ><input v-model="notes" type="checkbox" :disabled="busy" />Опубликовать подписи и пояснения</label
      ><label class="check-label"
        ><input v-model="allowCopy" type="checkbox" :disabled="busy" />Разрешить независимую копию в свой
        кабинет</label
      ><label class="field"
        ><span class="label">Срок ссылки</span
        ><AppSelect v-model.number="days" class="select" :disabled="busy">
          <option :value="0">До отзыва</option>
          <option :value="1">1 день</option>
          <option :value="7">7 дней</option>
          <option :value="30">30 дней</option>
        </AppSelect></label
      >
      <label class="field">
        <span class="label">Ориентация A4</span>
        <AppSelect v-model="orientation" class="select" :disabled="busy">
          <option value="landscape">Альбомная</option>
          <option value="portrait">Книжная</option>
        </AppSelect>
      </label>
    </div>
    <button class="btn btn-secondary" :disabled="busy" @click="prepare">Проверить публикуемый лист</button
    ><iframe
      v-if="preview"
      class="publication-preview"
      :srcdoc="preview"
      title="Предпросмотр публикуемой версии"
      sandbox="allow-same-origin"
    />
    <button
      v-if="preview && !link"
      class="btn btn-primary publish-button"
      type="button"
      :disabled="busy"
      @click="publish"
    >
      Опубликовать проверенную версию
    </button>
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
        {{ revokeTarget === item.id ? 'Подтвердить отзыв' : 'Отозвать' }}
      </button>
      <button
        v-if="revokeTarget === item.id"
        type="button"
        class="btn btn-ghost"
        :disabled="busy"
        @click="revokeTarget = null"
      >
        Отмена
      </button>
      <p v-if="revokeTarget === item.id" class="muted revoke-message" role="status">
        Лист и изображения по ссылке станут недоступны. Уже скачанные копии останутся у получателей.
      </p>
    </div>
  </div>
</template>
<style scoped>
.publication-options,
.created-link {
  display: grid;
  gap: 20px;
  margin: var(--section-gap) 0;
  min-width: 0;
}
.publication-options .check-label {
  min-height: var(--control-height);
}
.created-link {
  padding: var(--panel-padding);
  border: 1px solid var(--line);
  border-radius: var(--panel-radius);
  background: var(--surface);
  box-shadow: var(--panel-shadow);
  overflow-wrap: anywhere;
}
.created-link strong {
  font-size: 15px;
  line-height: 1.6;
  font-weight: 600;
}
.publication-preview {
  display: block;
  width: 100%;
  height: 380px;
  margin-top: var(--section-gap);
  border: 1px solid var(--line);
  background: var(--surface);
  border-radius: var(--panel-radius);
  box-shadow: var(--panel-shadow);
}
.publish-button {
  max-width: 100%;
  margin-top: 14px;
  text-align: left;
  white-space: normal;
}
.publication-row {
  display: flex;
  gap: 16px;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  padding: var(--panel-padding);
  border: 1px solid var(--line);
  border-radius: var(--panel-radius);
  background: var(--surface);
  box-shadow: var(--panel-shadow);
  margin: 12px 0;
  min-width: 0;
}
.publication-row > span {
  min-width: 0;
  font-size: 14px;
  line-height: 1.6;
  overflow-wrap: anywhere;
}
.publication-row > .btn {
  flex: none;
}
.publication-row .revoke-message {
  font-size: 13px;
  margin: 0;
  width: 100%;
}
.created-link img {
  margin: auto;
}
h3 {
  font-size: 18px;
  font-weight: 650;
  margin-top: var(--section-gap);
}
@media (max-width: 440px) {
  .publication-row {
    align-items: flex-start;
    flex-direction: column;
    gap: 8px;
  }
  .created-link .btn {
    white-space: normal;
  }
}
</style>
