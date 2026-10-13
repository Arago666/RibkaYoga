<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Download, ShieldCheck, Users, CircleCheck, Clock, CreditCard } from '@lucide/vue';
import AppShell from '@/shared/AppShell.vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import { errorText } from '@/modules/catalog/types';
import { localDate, subscriptionStatuses, type Subscription } from '../types';
interface AdminUser {
  id: number;
  name: string;
  login: string;
  email: string;
  is_admin: boolean;
  workspace_id: string | null;
  role: string | null;
  subscription: Subscription | null;
}
interface AdminData {
  users: { data: AdminUser[]; current_page: number; last_page: number; total: number };
  summary: {
    users: number;
    active_spaces: number;
    trial_spaces: number;
    expired_spaces: number;
    paid_count: number;
    revenue_minor: number;
    payment_provider_connected: boolean;
  };
}
const data = ref<AdminData | null>(null),
  target = ref<AdminUser | null>(null);
const search = ref(''),
  page = ref(1),
  loading = ref(false),
  busy = ref(false),
  error = ref(''),
  success = ref('');
const plan = ref<'basic' | 'pro'>('pro'),
  days = ref(30),
  unlimited = ref(false);
let requestVersion = 0,
  searchTimer: ReturnType<typeof setTimeout> | undefined;
async function load() {
  const version = ++requestVersion;
  loading.value = true;
  error.value = '';
  try {
    const result = await api<AdminData>(
      `/api/admin/users?${new URLSearchParams({ search: search.value, page: String(page.value) })}`,
    );
    if (version === requestVersion) data.value = result;
  } catch (failure) {
    if (version === requestVersion) error.value = errorText(failure);
  } finally {
    if (version === requestVersion) loading.value = false;
  }
}
function openGrant(user: AdminUser) {
  target.value = user;
  plan.value = user.subscription?.plan || 'pro';
  days.value = 30;
  unlimited.value = false;
}
async function grant() {
  if (!target.value || busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await api(`/api/admin/users/${target.value.id}/grant`, {
      method: 'POST',
      body: JSON.stringify({
        plan: plan.value,
        days: unlimited.value ? null : days.value,
        expected_revision: target.value.subscription?.revision || 0,
      }),
    });
    success.value = `Доступ предоставлен: ${target.value.name}. Платёж не создавался.`;
    target.value = null;
    await load();
  } catch (failure) {
    error.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}
function changePage(value: number) {
  page.value = value;
  void load();
}
watch(search, () => {
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    page.value = 1;
    void load();
  }, 300);
});
onMounted(load);
onBeforeUnmount(() => {
  requestVersion++;
  if (searchTimer) clearTimeout(searchTimer);
});
</script>
<template>
  <Head title="Администрирование" />
  <AppShell section="account" wide>
    <header class="page-header">
      <div>
        <h1 class="page-title">Администрирование</h1>
        <p class="muted">Пользователи, доступ и фактические платежи.</p>
      </div>
      <a href="/api/admin/export" class="btn btn-secondary" download><Download :size="17" />Выгрузить JSON</a>
    </header>
    <div v-if="error && !target" class="error-banner" role="alert">{{ error }}</div>
    <p v-if="success" class="success-banner" role="status">{{ success }}</p>
    <div v-if="data" class="admin-stats">
      <article>
        <Users :size="20" /><strong>{{ data.summary.users }}</strong
        ><span>Пользователей</span>
      </article>
      <article>
        <CircleCheck :size="20" /><strong>{{ data.summary.active_spaces }}</strong
        ><span>Пространств с доступом</span>
      </article>
      <article>
        <Clock :size="20" /><strong>{{ data.summary.trial_spaces }}</strong
        ><span>Пробных периодов</span>
      </article>
      <article>
        <CreditCard :size="20" /><strong
          >{{ (data.summary.revenue_minor / 100).toLocaleString('ru-RU') }} ₽</strong
        ><span>Подтверждённых платежей: {{ data.summary.paid_count }}</span>
      </article>
    </div>
    <p class="local-billing">
      Платёжный провайдер не подключён. Здесь учитываются только подтверждённые платежи; ручная выдача доступа
      не увеличивает выручку.
    </p>
    <label class="search-box"
      ><span class="sr-only">Найти пользователя</span
      ><input v-model="search" class="input" placeholder="Имя, логин или почта" maxlength="120"
    /></label>
    <p v-if="loading" class="muted" role="status">Загружаем пользователей…</p>
    <div v-if="data" class="users-table">
      <table>
        <thead>
          <tr>
            <th>Пользователь</th>
            <th>Контакты</th>
            <th>Доступ</th>
            <th>Срок</th>
            <th>Действия</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="user in data.users.data" :key="user.id">
            <td>
              <strong>{{ user.name }}</strong
              ><small>{{ user.login }} <span v-if="user.is_admin">· администратор</span></small>
            </td>
            <td>{{ user.email }}</td>
            <td>
              <strong>{{ user.subscription?.plan_name || 'Нет пространства' }}</strong
              ><small>{{
                user.subscription
                  ? subscriptionStatuses[user.subscription.status] || user.subscription.status
                  : ''
              }}</small>
            </td>
            <td>{{ user.subscription ? localDate(user.subscription.ends_at) : '—' }}</td>
            <td>
              <button
                v-if="user.workspace_id"
                type="button"
                class="btn btn-secondary"
                @click="openGrant(user)"
              >
                <ShieldCheck :size="16" />Выдать доступ
              </button>
            </td>
          </tr>
          <tr v-if="!data.users.data.length">
            <td colspan="5" class="muted">Пользователи не найдены.</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="data && data.users.last_page > 1" class="pagination">
      <button class="btn btn-secondary" :disabled="loading || page <= 1" @click="changePage(page - 1)">
        Назад</button
      ><span>{{ page }} / {{ data.users.last_page }}</span
      ><button
        class="btn btn-secondary"
        :disabled="loading || page >= data.users.last_page"
        @click="changePage(page + 1)"
      >
        Далее
      </button>
    </div>
    <Modal :open="Boolean(target)" title="Предоставить доступ вручную" @close="!busy && (target = null)"
      ><p>
        Пространство пользователя <strong>{{ target?.name }}</strong
        >. Доступ меняется для всех участников этого пространства.
      </p>
      <div v-if="error" class="error-banner" role="alert">{{ error }}</div>
      <form id="admin-grant" class="grant-form" @submit.prevent="grant">
        <label class="field"
          ><span class="label">Тариф</span
          ><select v-model="plan" class="select" :disabled="busy">
            <option value="basic">Базовый</option>
            <option value="pro">Про</option>
          </select></label
        ><label class="check-label"
          ><input v-model="unlimited" type="checkbox" :disabled="busy" />Без ограничения срока</label
        ><label v-if="!unlimited" class="field"
          ><span class="label">Срок с сегодняшнего дня</span
          ><input
            v-model.number="days"
            type="number"
            class="input"
            min="1"
            max="3650"
            required
            :disabled="busy"
          /><small class="muted">Количество дней. Предыдущий срок будет заменён.</small></label
        >
        <p class="muted">Оплата не создаётся и деньги не списываются.</p>
      </form>
      <template #footer
        ><button class="btn btn-secondary" type="button" :disabled="busy" @click="target = null">
          Отмена</button
        ><button class="btn btn-primary" form="admin-grant" type="submit" :disabled="busy">
          {{ busy ? 'Сохраняем…' : 'Предоставить доступ' }}
        </button></template
      ></Modal
    >
  </AppShell>
</template>
<style scoped>
.admin-stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
  margin: 24px 0;
}
.admin-stats article {
  display: grid;
  grid-template-columns: 24px 1fr;
  gap: 8px;
  padding: 20px;
  border: 1px solid #dfe6db;
  border-radius: 16px;
  background: #fff;
}
.admin-stats svg {
  color: #397a28;
}
.admin-stats strong {
  font-size: 1.25rem;
}
.admin-stats span {
  grid-column: 1/-1;
  font-size: 0.8rem;
  color: #687568;
}
.local-billing {
  background: #f0f5eb;
  border-radius: 12px;
  padding: 14px;
  font-size: 0.86rem;
  line-height: 1.6;
}
.search-box {
  display: block;
  max-width: 540px;
  margin: 24px 0 18px;
}
.users-table {
  overflow: auto;
  background: #fff;
  border: 1px solid #dfe6db;
  border-radius: 14px;
}
table {
  border-collapse: collapse;
  width: 100%;
  font-size: 0.85rem;
}
th,
td {
  padding: 16px;
  text-align: left;
  border-bottom: 1px solid #e9eee6;
}
th {
  color: #687568;
  font-size: 0.8rem;
}
td small {
  display: block;
  margin-top: 5px;
  color: #687568;
}
td .btn {
  white-space: nowrap;
}
.pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 15px;
  margin: 22px 0;
}
.grant-form {
  display: grid;
  gap: 15px;
}
.grant-form > p {
  margin: 0;
  font-size: 0.85rem;
}
@media (max-width: 900px) {
  .admin-stats {
    grid-template-columns: 1fr 1fr;
  }
}
@media (max-width: 450px) {
  .admin-stats {
    grid-template-columns: 1fr;
  }
}
</style>
