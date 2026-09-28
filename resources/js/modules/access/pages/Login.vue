<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowRight, Eye, EyeOff } from '@lucide/vue';
import { ref } from 'vue';
const form = useForm({ email: '', password: '', remember: true });
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
    <section class="login-card">
      <div class="eyebrow">ВАШЕ ПРОСТРАНСТВО ПРАКТИКИ</div>
      <h1>С возвращением</h1>
      <p class="muted">Войдите, чтобы продолжить свои последовательности.</p>
      <form @submit.prevent="submit" class="login-form">
        <label class="field"
          ><span class="label">Электронная почта</span
          ><input
            v-model="form.email"
            class="input"
            type="email"
            autocomplete="username"
            required
            autofocus
            placeholder="name@example.com"
          /><span v-if="form.errors.email" class="field-error">{{ form.errors.email }}</span></label
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
    </section>
    <p class="login-help">Доступ к пространству предоставляет его владелец.</p>
  </main>
</template>
