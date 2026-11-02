<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, Eye, EyeOff } from '@lucide/vue';
import { ref } from 'vue';
defineProps<{ status?: string }>();
const form = useForm({ login: '', password: '', remember: true });
const visible = ref(false);
function submit() {
  form.post('/login', { onFinish: () => form.reset('password') });
}
</script>
<template>
  <Head title="Вход" />
  <main class="login-page">
    <div class="login-brand brand">
      <img src="/favicon.svg" alt="" /><span>ribka<span class="brand-light">yoga</span></span>
    </div>
    <section class="login-card card">
      <h1 class="page-title">Добро пожаловать</h1>
      <p v-if="status" class="success-banner" role="status">{{ status }}</p>
      <form @submit.prevent="submit" class="login-form">
        <label class="field"
          ><span class="label">Логин</span
          ><input
            v-model="form.login"
            class="input"
            type="text"
            autocomplete="username"
            autocapitalize="none"
            :spellcheck="false"
            maxlength="64"
            required
            autofocus
            placeholder="Введите логин"
          /><span v-if="form.errors.login" class="field-error">{{ form.errors.login }}</span></label
        >
        <label class="field"
          ><span class="label">Пароль</span
          ><span class="password-field"
            ><input
              v-model="form.password"
              class="input"
              :type="visible ? 'text' : 'password'"
              autocomplete="current-password"
              required /><button
              type="button"
              class="icon-btn"
              :aria-label="visible ? 'Скрыть пароль' : 'Показать пароль'"
              @click="visible = !visible"
            >
              <component :is="visible ? EyeOff : Eye" :size="18" /></button></span
          ><span v-if="form.errors.password" class="field-error">{{ form.errors.password }}</span></label
        >
        <label class="check-label"><input type="checkbox" v-model="form.remember" />Запомнить меня</label>
        <button class="btn btn-primary login-submit" :disabled="form.processing">
          {{ form.processing ? 'Входим…' : 'Войти' }}<ArrowRight :size="18" />
        </button>
      </form>
      <div class="auth-links">
        <Link href="/register">Создать кабинет</Link><Link href="/forgot-password">Забыли пароль?</Link>
      </div>
    </section>
  </main>
</template>
<style scoped>
.login-card.card {
  padding: var(--panel-padding);
}
.auth-links {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  margin-top: 24px;
  font-size: 0.84rem;
  flex-wrap: wrap;
}
.auth-links a {
  color: var(--accent-dark);
}
.login-card .page-title {
  font-size: var(--page-title-size);
  font-weight: var(--page-title-weight);
  letter-spacing: -0.045em;
  line-height: 1.1;
}
</style>
