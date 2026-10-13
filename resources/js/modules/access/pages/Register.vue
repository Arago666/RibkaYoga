<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
defineProps<{ trialDays: number }>();
const form = useForm({ name: '', login: '', email: '', password: '', password_confirmation: '' });
function submit() {
  form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>
<template>
  <Head title="Регистрация" />
  <main class="login-page">
    <div class="login-brand brand">
      <img src="/favicon.svg" alt="" /><span>ribka<span class="brand-light">yoga</span></span>
    </div>
    <section class="login-card">
      <h1>Создать кабинет</h1>
      <p class="muted">
        Своя библиотека, подборки и практики.
        <span v-if="trialDays">Тариф «Про» на {{ trialDays }} дней без оплаты.</span>
      </p>
      <form class="login-form" @submit.prevent="submit">
        <label class="field"
          ><span class="label">Имя</span
          ><input
            v-model="form.name"
            class="input"
            autocomplete="name"
            maxlength="120"
            required
            autofocus
          /><span v-if="form.errors.name" class="field-error">{{ form.errors.name }}</span></label
        >
        <label class="field"
          ><span class="label">Логин</span
          ><input
            v-model="form.login"
            class="input"
            autocomplete="username"
            autocapitalize="none"
            :spellcheck="false"
            maxlength="64"
            pattern="[a-zA-Z0-9][a-zA-Z0-9._-]*"
            required
          /><small class="muted">Латинские буквы, цифры, точка, дефис и подчёркивание.</small
          ><span v-if="form.errors.login" class="field-error">{{ form.errors.login }}</span></label
        >
        <label class="field"
          ><span class="label">Электронная почта</span
          ><input
            v-model="form.email"
            class="input"
            type="email"
            autocomplete="email"
            maxlength="255"
            required
          /><span v-if="form.errors.email" class="field-error">{{ form.errors.email }}</span></label
        >
        <label class="field"
          ><span class="label">Пароль</span
          ><input
            v-model="form.password"
            class="input"
            type="password"
            autocomplete="new-password"
            minlength="8"
            required
          /><small class="muted">Не менее 8 символов.</small
          ><span v-if="form.errors.password" class="field-error">{{ form.errors.password }}</span></label
        >
        <label class="field"
          ><span class="label">Повторите пароль</span
          ><input
            v-model="form.password_confirmation"
            class="input"
            type="password"
            autocomplete="new-password"
            required
        /></label>
        <button class="btn btn-primary" :disabled="form.processing">
          {{ form.processing ? 'Создаём кабинет…' : 'Зарегистрироваться' }}
        </button>
      </form>
      <p class="auth-links"><Link href="/login">Уже есть аккаунт — войти</Link></p>
    </section>
  </main>
</template>
<style scoped>
.auth-links {
  margin: 22px 0 0;
  text-align: center;
  font-size: 0.86rem;
}
.auth-links a {
  color: #397a28;
}
.login-card > .muted {
  font-size: 0.88rem;
  line-height: 1.5;
}
</style>
