<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { UserRound, CreditCard, Shield, Save } from '@lucide/vue';
import AppShell from '@/shared/AppShell.vue';
import { api } from '@/shared/api';
import { errorText } from '@/modules/catalog/types';
import { localDate, subscriptionStatuses, type Subscription } from '../types';
interface AccountData {
  user: {
    id: number;
    name: string;
    login: string;
    email: string;
    phone: string | null;
    telegram: string | null;
    requisites: string | null;
    avatar_url: string | null;
    is_admin: boolean;
  };
  workspace: { id: string; name: string; role: string };
  subscription: Subscription;
  plans: Record<string, { name: string; limits: Record<string, number | null> }>;
}
const account = ref<AccountData | null>(null);
const error = ref(''),
  success = ref(''),
  loading = ref(true),
  busy = ref(false);
const form = useForm({
  name: '',
  login: '',
  email: '',
  phone: '',
  telegram: '',
  requisites: '',
  workspace_name: '',
  avatar: null as File | null,
  remove_avatar: false,
  profile_password: '',
});
const password = ref({ current_password: '', password: '', password_confirmation: '' });
async function load() {
  loading.value = true;
  error.value = '';
  try {
    account.value = await api<AccountData>('/api/account');
    const { user, workspace } = account.value;
    form.defaults({
      name: user.name,
      login: user.login,
      email: user.email,
      phone: user.phone || '',
      telegram: user.telegram || '',
      requisites: user.requisites || '',
      workspace_name: workspace.name,
      avatar: null,
      remove_avatar: false,
      profile_password: '',
    });
    form.reset();
  } catch (failure) {
    error.value = errorText(failure);
  } finally {
    loading.value = false;
  }
}
function save() {
  success.value = '';
  form.post('/account/profile', {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      success.value = 'Профиль сохранён.';
      void load();
    },
  });
}
function selectAvatar(event: Event) {
  const input = event.target as HTMLInputElement;
  form.avatar = input.files?.[0] || null;
  if (form.avatar) form.remove_avatar = false;
}
async function updatePassword() {
  if (busy.value) return;
  error.value = success.value = '';
  busy.value = true;
  try {
    const result = await api<{ message: string }>('/api/account/password', {
      method: 'POST',
      body: JSON.stringify(password.value),
    });
    success.value = result.message;
    password.value = { current_password: '', password: '', password_confirmation: '' };
  } catch (failure) {
    error.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}
async function cancelRenewal() {
  if (!account.value || busy.value) return;
  busy.value = true;
  try {
    account.value.subscription = await api<Subscription>('/api/account/subscription/cancel-renewal', {
      method: 'POST',
      body: JSON.stringify({ expected_revision: account.value.subscription.revision }),
    });
    success.value = 'Продление отменено. Доступ сохраняется до окончания периода.';
  } catch (failure) {
    error.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}
onMounted(load);
</script>
<template>
  <Head title="Личный кабинет" />
  <AppShell section="account">
    <header class="page-header">
      <div><h1 class="page-title">Личный кабинет</h1></div>
      <Link v-if="account?.user.is_admin" href="/admin" class="btn btn-secondary"
        ><Shield :size="17" />Администрирование</Link
      >
    </header>
    <div v-if="error" class="error-banner" role="alert">{{ error }}</div>
    <p v-if="success" class="success-banner" role="status">{{ success }}</p>
    <p v-if="loading" class="muted" role="status">Загружаем кабинет…</p>
    <div v-if="account" class="account-grid">
      <section class="account-card">
        <h2><UserRound :size="20" />Профиль</h2>
        <form class="profile-form" @submit.prevent="save">
          <label
            v-for="field in ['name', 'login', 'email', 'phone', 'telegram'] as const"
            :key="field"
            class="field"
            ><span class="label">{{
              {
                name: 'Имя',
                login: 'Логин',
                email: 'Электронная почта',
                phone: 'Телефон',
                telegram: 'Telegram',
              }[field]
            }}</span
            ><input
              v-model="form[field]"
              class="input"
              :type="field === 'email' ? 'email' : field === 'phone' ? 'tel' : 'text'"
              :required="['name', 'login', 'email'].includes(field)"
              :maxlength="field === 'email' ? 255 : field === 'login' || field === 'phone' ? 64 : 120"
            /><span v-if="form.errors[field]" class="field-error">{{ form.errors[field] }}</span></label
          >
          <label v-if="form.login !== account.user.login || form.email !== account.user.email" class="field">
            <span class="label">Текущий пароль для изменения логина или почты</span>
            <input
              v-model="form.profile_password"
              class="input"
              type="password"
              autocomplete="current-password"
              required
            />
            <span v-if="form.errors.profile_password" class="field-error">{{
              form.errors.profile_password
            }}</span>
          </label>
          <label class="field"
            ><span class="label">Название пространства</span
            ><input
              v-model="form.workspace_name"
              class="input"
              maxlength="160"
              required
              :readonly="account.workspace.role !== 'owner'"
            /><span v-if="form.errors.workspace_name" class="field-error">{{
              form.errors.workspace_name
            }}</span></label
          >
          <label class="field"
            ><span class="label">Реквизиты — необязательно</span
            ><textarea v-model="form.requisites" class="textarea" rows="3" maxlength="3000" /><span
              v-if="form.errors.requisites"
              class="field-error"
              >{{ form.errors.requisites }}</span
            ></label
          >
          <div class="avatar-field">
            <img
              v-if="account.user.avatar_url && !form.remove_avatar"
              :src="account.user.avatar_url"
              alt="Ваш аватар"
            /><label class="field"
              ><span class="label">Аватар</span
              ><input type="file" accept="image/png,image/jpeg,image/webp" @change="selectAvatar" /><small
                class="muted"
                >PNG, JPEG или WebP · до 2 МБ и 4 мегапикселей.</small
              ><span v-if="form.errors.avatar" class="field-error">{{ form.errors.avatar }}</span></label
            >
          </div>
          <label v-if="account.user.avatar_url" class="check-label"
            ><input v-model="form.remove_avatar" type="checkbox" @change="form.avatar = null" />Убрать
            аватар</label
          >
          <button class="btn btn-primary" :disabled="form.processing">
            <Save :size="17" />{{ form.processing ? 'Сохраняем…' : 'Сохранить профиль' }}
          </button>
        </form>
      </section>
      <div class="account-column">
        <section class="account-card">
          <h2><CreditCard :size="20" />Подписка</h2>
          <h3>{{ account.subscription.plan_name }}</h3>
          <p>{{ subscriptionStatuses[account.subscription.status] || account.subscription.status }}</p>
          <p class="muted">
            {{
              account.subscription.ends_at
                ? `Доступ до ${localDate(account.subscription.ends_at)}`
                : localDate(null)
            }}
          </p>
          <dl class="limits">
            <div>
              <dt>Асаны</dt>
              <dd>{{ account.subscription.limits.asanas ?? 'Без лимита' }}</dd>
            </div>
            <div>
              <dt>Практики и шаблоны</dt>
              <dd>{{ account.subscription.limits.practices ?? 'Без лимита' }}</dd>
            </div>
            <div>
              <dt>Мезоциклы</dt>
              <dd>{{ account.subscription.limits.mesocycles === 0 ? 'Тариф «Про»' : 'Без лимита' }}</dd>
            </div>
          </dl>
          <div class="local-billing">
            <strong>Оплата пока не подключена</strong>
            <p class="muted">
              Доступ выдаётся администратором вручную. Списаний с карты нет. Для онлайн-оплаты и автопродления
              нужно подключить платёжного провайдера.
            </p>
          </div>
          <p v-if="account.subscription.cancel_at_period_end" class="muted">Автопродление отключено.</p>
          <button
            v-else-if="account.workspace.role === 'owner'"
            type="button"
            class="btn btn-secondary"
            :disabled="busy"
            @click="cancelRenewal"
          >
            Отменить продление
          </button>
          <h3>История платежей</h3>
          <p v-if="!account.subscription.payments.length" class="muted">Платежей пока нет.</p>
          <ul v-else class="payments">
            <li v-for="payment in account.subscription.payments" :key="payment.id">
              {{ (payment.amount_minor / 100).toLocaleString('ru-RU') }} {{ payment.currency }} ·
              {{ payment.status }} · {{ localDate(payment.paid_at) }}
            </li>
          </ul>
        </section>
        <section class="account-card">
          <h2>Изменить пароль</h2>
          <form class="profile-form" @submit.prevent="updatePassword">
            <label class="field"
              ><span class="label">Текущий пароль</span
              ><input
                v-model="password.current_password"
                class="input"
                type="password"
                autocomplete="current-password"
                required /></label
            ><label class="field"
              ><span class="label">Новый пароль</span
              ><input
                v-model="password.password"
                class="input"
                type="password"
                autocomplete="new-password"
                minlength="8"
                required /></label
            ><label class="field"
              ><span class="label">Повторите новый пароль</span
              ><input
                v-model="password.password_confirmation"
                class="input"
                type="password"
                autocomplete="new-password"
                required /></label
            ><button class="btn btn-secondary" :disabled="busy">Сохранить пароль</button>
          </form>
        </section>
      </div>
    </div>
  </AppShell>
</template>
<style scoped>
.account-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 24px;
  margin-top: 24px;
  align-items: start;
}
.account-column {
  display: grid;
  gap: 24px;
}
.account-card {
  background: #fff;
  border: 1px solid #dfe6db;
  border-radius: 18px;
  padding: 24px;
  min-width: 0;
}
h2 {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 1.1rem;
  margin: 0 0 22px;
}
h3 {
  font-size: 0.97rem;
  margin: 20px 0 10px;
}
p {
  font-size: 0.86rem;
  line-height: 1.6;
}
.profile-form {
  display: grid;
  gap: 16px;
}
.avatar-field {
  display: flex;
  gap: 15px;
  align-items: center;
  min-width: 0;
}
.avatar-field img {
  width: 70px;
  height: 70px;
  object-fit: cover;
  border-radius: 50%;
}
.avatar-field input {
  width: 100%;
  font-size: 0.82rem;
}
.limits {
  display: grid;
  gap: 10px;
  font-size: 0.86rem;
}
.limits > div {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}
dd {
  margin: 0;
  font-weight: 600;
}
.local-billing {
  margin: 20px 0;
  padding: 14px;
  border-radius: 10px;
  background: #f6f9f3;
  font-size: 0.85rem;
}
.local-billing p {
  margin-bottom: 0;
}
.payments {
  padding-left: 16px;
  font-size: 0.83rem;
}
@media (max-width: 850px) {
  .account-grid {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 480px) {
  .account-card {
    padding: 18px;
  }
}
</style>
