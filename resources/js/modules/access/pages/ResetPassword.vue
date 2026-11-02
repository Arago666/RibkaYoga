<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
const props = defineProps<{ token: string; email: string }>();
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
</script>
<template>
  <Head title="Новый пароль" />
  <main class="login-page">
    <div class="login-brand brand">
      <img src="/favicon.svg" alt="" /><span>ribka<span class="brand-light">yoga</span></span>
    </div>
    <section class="login-card card">
      <h1 class="page-title">Новый пароль</h1>
      <form
        class="login-form"
        @submit.prevent="
          form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') })
        "
      >
        <label class="field"
          ><span class="label">Электронная почта</span
          ><input v-model="form.email" class="input" type="email" autocomplete="email" required /><span
            v-if="form.errors.email"
            class="field-error"
            >{{ form.errors.email }}</span
          ></label
        ><label class="field"
          ><span class="label">Пароль — не менее 8 символов</span
          ><input
            v-model="form.password"
            class="input"
            type="password"
            autocomplete="new-password"
            minlength="8"
            required
          /><span v-if="form.errors.password" class="field-error">{{ form.errors.password }}</span></label
        ><label class="field"
          ><span class="label">Повторите пароль</span
          ><input
            v-model="form.password_confirmation"
            class="input"
            type="password"
            autocomplete="new-password"
            required /></label
        ><button class="btn btn-primary" :disabled="form.processing">
          {{ form.processing ? 'Сохраняем…' : 'Сохранить пароль' }}
        </button>
      </form>
      <p class="auth-links"><Link href="/login">Вернуться ко входу</Link></p>
    </section>
  </main>
</template>
<style scoped>
.login-card.card {
  padding: var(--panel-padding);
}
.auth-links {
  margin: 22px 0 0;
  text-align: center;
  font-size: 0.86rem;
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
