<script setup lang="ts">
import AppSelect from '@/shared/AppSelect.vue';
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { UserPlus, MessageSquare, RotateCcw, X } from '@lucide/vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import type { PracticeShare, SharingPermissions } from '../sharing-types';
const props = defineProps<{ open: boolean; documentId: string; title?: string }>();
const allowCopy = ref(false);
defineEmits<{ close: [] }>();
const state = ref<SharingPermissions | null>(null),
  loading = ref(false),
  busy = ref(false),
  error = ref(''),
  login = ref(''),
  permission = ref<'view' | 'comment'>('comment'),
  success = ref('');
const current = computed(() =>
  state.value?.data.find(
    (share) => share.recipient.login.toLocaleLowerCase('ru') === login.value.trim().toLocaleLowerCase('ru'),
  ),
);
let request = 0;
async function load() {
  const sequence = ++request;
  loading.value = true;
  error.value = '';
  try {
    const response = await api<SharingPermissions>(`/api/practices/${props.documentId}/shares`);
    if (sequence === request) state.value = response;
  } catch (failure) {
    if (sequence === request)
      error.value = failure instanceof Error ? failure.message : 'Не удалось загрузить доступы.';
  } finally {
    if (sequence === request) loading.value = false;
  }
}
watch(
  () => props.open,
  (open) => {
    request++;
    if (!open) return;
    state.value = null;
    login.value = '';
    permission.value = 'comment';
    allowCopy.value = false;
    success.value = '';
    void load();
  },
);
async function grant(share?: PracticeShare) {
  if (!state.value || busy.value) return;
  const target = share || current.value;
  busy.value = true;
  error.value = '';
  success.value = '';
  try {
    state.value = await api<SharingPermissions>(`/api/practices/${props.documentId}/shares`, {
      method: 'POST',
      body: JSON.stringify({
        login: share?.recipient.login || login.value.trim(),
        permission: share?.permission || permission.value,
        allow_copy: share?.allow_copy ?? allowCopy.value,
        expected_document_revision: state.value.document_revision,
        ...(target ? { expected_revision: target.revision } : {}),
      }),
    });
    login.value = '';
    success.value = 'Доступ сохранён. Преподаватель увидит практику в разделе «Мне дали доступ».';
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось дать доступ.';
  } finally {
    busy.value = false;
  }
}
async function revoke(share: PracticeShare) {
  if (
    !state.value ||
    busy.value ||
    !window.confirm(
      `Закрыть доступ к практике для ${share.recipient.name}? История комментариев сохранится у вас.`,
    )
  )
    return;
  busy.value = true;
  error.value = '';
  success.value = '';
  try {
    state.value = await api<SharingPermissions>(
      `/api/practices/${props.documentId}/shares/${share.id}/revoke`,
      {
        method: 'POST',
        body: JSON.stringify({
          expected_revision: share.revision,
          expected_document_revision: state.value.document_revision,
        }),
      },
    );
    success.value = 'Доступ закрыт.';
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось закрыть доступ.';
  } finally {
    busy.value = false;
  }
}
function choose(share: PracticeShare) {
  login.value = share.recipient.login;
  permission.value = share.permission;
  allowCopy.value = share.allow_copy;
  success.value = '';
}
</script>
<template>
  <Modal :open="open" title="Доступ преподавателю" @close="!busy && $emit('close')">
    <div class="sharing-dialog">
      <p v-if="title">
        <strong>{{ title }}</strong>
      </p>
      <p class="muted">
        Преподаватель получит только этот лист. Он сможет смотреть его и, если вы разрешите, комментировать.
      </p>
      <div v-if="error" class="error-banner" role="alert">
        {{ error }} <button class="btn btn-ghost" :disabled="busy" @click="load">Обновить доступы</button>
      </div>
      <p v-if="success" class="sharing-success" role="status">{{ success }}</p>
      <p v-if="loading" class="muted" role="status">Загружаем доступы…</p>
      <form v-else-if="state" id="document-sharing-form" class="sharing-form" @submit.prevent="grant()">
        <div class="field">
          <label class="label" for="sharing-login">Логин существующего аккаунта</label>
          <input
            id="sharing-login"
            v-model="login"
            class="input"
            required
            maxlength="64"
            autocomplete="off"
            :disabled="busy"
            placeholder="Введите точный логин"
          />
        </div>
        <div class="field">
          <label class="label" for="sharing-permission">Разрешить</label
          ><AppSelect id="sharing-permission" v-model="permission" class="select" :disabled="busy">
            <option value="view">Только просмотр</option>
            <option value="comment">Просмотр и комментарии</option>
          </AppSelect>
        </div>
        <label class="check-label"
          ><input v-model="allowCopy" type="checkbox" :disabled="busy" />Разрешить независимую копию в
          собственном кабинете</label
        >
        <button class="btn btn-primary" type="submit" :disabled="busy || !login.trim()">
          <UserPlus :size="17" />{{ busy ? 'Сохраняем…' : current ? 'Обновить доступ' : 'Дать доступ' }}
        </button>
      </form>
      <div v-if="state?.data.length" class="sharing-list">
        <article v-for="share in state.data" :key="share.id" class="sharing-person">
          <div>
            <strong>{{ share.recipient.name }}</strong
            ><span
              >{{ share.recipient.login }} ·
              {{ share.permission === 'comment' ? 'Просмотр и комментарии' : 'Только просмотр'
              }}{{ share.revoked_at ? ' · Доступ закрыт' : '' }}</span
            >
          </div>
          <button v-if="share.revoked_at" class="btn btn-ghost" :disabled="busy" @click="grant(share)">
            <RotateCcw :size="15" />Вернуть
          </button>
          <template v-else
            ><button class="btn btn-ghost" :disabled="busy" @click="choose(share)">Права</button
            ><button
              class="icon-btn"
              :disabled="busy"
              :aria-label="`Закрыть доступ: ${share.recipient.name}`"
              @click="revoke(share)"
            >
              <X :size="17" /></button
          ></template>
        </article>
      </div>
      <Link v-if="state" :href="`/shared-practices/${documentId}`" class="btn btn-secondary"
        ><MessageSquare :size="17" />Открыть лист и комментарии</Link
      >
    </div>
    <template #footer
      ><button class="btn btn-secondary" :disabled="busy" @click="$emit('close')">Закрыть</button></template
    >
  </Modal>
</template>
<style scoped>
.sharing-dialog,
.sharing-form {
  display: grid;
  gap: var(--section-gap);
  min-width: 0;
}
.sharing-dialog p {
  margin: 0;
  line-height: 1.6;
  font-size: 14px;
}
.sharing-success {
  color: var(--accent-dark);
}
.sharing-form {
  border-bottom: 1px solid var(--line);
  padding-bottom: var(--section-gap);
}
.sharing-form > .btn {
  justify-self: start;
}
.sharing-list {
  display: grid;
  gap: 12px;
}
.sharing-person {
  display: flex;
  gap: 12px;
  align-items: center;
  border: 1px solid var(--line);
  border-radius: var(--panel-radius);
  padding: var(--panel-padding);
  background: var(--surface);
  box-shadow: var(--panel-shadow);
  min-width: 0;
}
.sharing-person > div {
  flex: 1;
  min-width: 0;
}
.sharing-person strong,
.sharing-person span {
  display: block;
  overflow-wrap: anywhere;
}
.sharing-person strong {
  font-size: 15px;
  font-weight: 600;
}
.sharing-person span {
  font-size: 13px;
  line-height: 1.5;
  color: var(--muted);
  margin-top: 6px;
}
.sharing-person .btn,
.sharing-person .icon-btn {
  flex: none;
}
@media (max-width: 440px) {
  .sharing-person {
    gap: 8px;
    flex-wrap: wrap;
  }
  .sharing-person > div {
    flex-basis: 100%;
  }
  .sharing-dialog > .btn {
    white-space: normal;
  }
}
</style>
