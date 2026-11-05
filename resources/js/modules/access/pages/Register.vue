<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import BrandAuthFrame from '@/shared/BrandAuthFrame.vue';
defineProps<{ trialDays: number }>();
const form = useForm({ name: '', login: '', email: '', password: '', password_confirmation: '' });
function submit() {
  form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>
<template>
  <Head title="Регистрация">
    <meta
      head-key="description"
      name="description"
      content="Создайте кабинет RibkaYoga: своя библиотека асан, подборки и практики в одном пространстве."
    />
  </Head>
  <BrandAuthFrame title="Создать кабинет." eyebrow="Первый шаг к вашей практике">
    <template #intro>
      <p>
        Своя библиотека, подборки и практики.
        <span v-if="trialDays">Тариф «Про» на {{ trialDays }} дней без оплаты.</span>
      </p>
    </template>
    <form class="login-form" @submit.prevent="submit">
      <label class="field">
        <span class="label">Имя</span>
        <input
          v-model="form.name"
          class="input"
          autocomplete="name"
          maxlength="120"
          required
          autofocus
          placeholder="Как к вам обращаться"
          :aria-invalid="!!form.errors.name"
          :aria-describedby="form.errors.name ? 'register-name-error' : undefined"
        />
        <span v-if="form.errors.name" id="register-name-error" class="field-error">{{
          form.errors.name
        }}</span>
      </label>
      <label class="field">
        <span class="label">Логин</span>
        <input
          v-model="form.login"
          class="input"
          autocomplete="username"
          autocapitalize="none"
          :spellcheck="false"
          maxlength="64"
          pattern="[a-zA-Z0-9][a-zA-Z0-9._-]*"
          required
          placeholder="Придумайте логин"
          :aria-invalid="!!form.errors.login"
          :aria-describedby="
            form.errors.login ? 'register-login-hint register-login-error' : 'register-login-hint'
          "
        />
        <small id="register-login-hint" class="muted"
          >Латинские буквы, цифры, точка, дефис и подчёркивание.</small
        >
        <span v-if="form.errors.login" id="register-login-error" class="field-error">{{
          form.errors.login
        }}</span>
      </label>
      <label class="field">
        <span class="label">Электронная почта</span>
        <input
          v-model="form.email"
          class="input"
          type="email"
          autocomplete="email"
          maxlength="255"
          required
          placeholder="you@example.com"
          :aria-invalid="!!form.errors.email"
          :aria-describedby="form.errors.email ? 'register-email-error' : undefined"
        />
        <span v-if="form.errors.email" id="register-email-error" class="field-error">{{
          form.errors.email
        }}</span>
      </label>
      <label class="field">
        <span class="label">Пароль</span>
        <input
          v-model="form.password"
          class="input"
          type="password"
          autocomplete="new-password"
          minlength="8"
          required
          placeholder="Придумайте пароль"
          :aria-invalid="!!form.errors.password"
          :aria-describedby="
            form.errors.password ? 'register-password-hint register-password-error' : 'register-password-hint'
          "
        />
        <small id="register-password-hint" class="muted">Не менее 8 символов.</small>
        <span v-if="form.errors.password" id="register-password-error" class="field-error">{{
          form.errors.password
        }}</span>
      </label>
      <label class="field">
        <span class="label">Повторите пароль</span>
        <input
          v-model="form.password_confirmation"
          class="input"
          type="password"
          autocomplete="new-password"
          required
          placeholder="Введите пароль ещё раз"
          :aria-invalid="!!form.errors.password_confirmation"
          :aria-describedby="form.errors.password_confirmation ? 'register-confirmation-error' : undefined"
        />
        <span v-if="form.errors.password_confirmation" id="register-confirmation-error" class="field-error">{{
          form.errors.password_confirmation
        }}</span>
      </label>
      <button class="btn btn-primary login-submit" :disabled="form.processing">
        {{ form.processing ? 'Создаём кабинет…' : 'Зарегистрироваться' }}<ArrowRight :size="19" />
      </button>
    </form>
    <template #links
      >Уже есть аккаунт? <Link href="/login">Войти <span aria-hidden="true">↗</span></Link></template
    >
  </BrandAuthFrame>
</template>
