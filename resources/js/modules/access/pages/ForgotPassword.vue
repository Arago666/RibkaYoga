<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
defineProps<{ status?: string; localMail: boolean }>();
const form = useForm({ email: '' });
</script>
<template>
  <Head title="Восстановление пароля" />
  <main class="login-page">
    <div class="login-brand brand">
      <img src="/favicon.svg" alt="" /><span>ribka<span class="brand-light">yoga</span></span>
    </div>
    <section class="login-card">
      <h1>Восстановить пароль</h1>
      <p v-if="localMail" class="muted">
        Локальный режим: письмо со ссылкой появится в <code>storage/logs/local-mail.log</code>.
      </p>
      <p v-else class="muted">Укажите почту своего аккаунта. На неё придёт ссылка для смены пароля.</p>
      <p v-if="status" class="success-banner" role="status">{{ status }}</p>
      <form class="login-form" @submit.prevent="form.post('/forgot-password')">
        <label class="field"
          ><span class="label">Электронная почта</span
          ><input
            v-model="form.email"
            class="input"
            type="email"
            autocomplete="email"
            maxlength="255"
            required
            autofocus
          /><span v-if="form.errors.email" class="field-error">{{ form.errors.email }}</span></label
        ><button class="btn btn-primary" :disabled="form.processing">
          {{ form.processing ? 'Отправляем…' : 'Получить ссылку' }}
        </button>
      </form>
      <p class="auth-links"><Link href="/login">Вернуться ко входу</Link></p>
    </section>
  </main>
</template>
<style scoped>
.muted {
  font-size: 0.86rem;
  line-height: 1.5;
  overflow-wrap: anywhere;
}
.auth-links {
  margin: 22px 0 0;
  text-align: center;
  font-size: 0.86rem;
}
.auth-links a {
  color: #397a28;
}
</style>
