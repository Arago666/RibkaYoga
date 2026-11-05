<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import BrandAuthFrame from '@/shared/BrandAuthFrame.vue';
const props = defineProps<{ token: string; email: string }>();
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
</script>
<template>
  <Head title="Новый пароль">
    <meta
      head-key="description"
      name="description"
      content="Задайте новый пароль для своего кабинета RibkaYoga."
    />
  </Head>
  <BrandAuthFrame title="Новый пароль." eyebrow="Ваше пространство под защитой">
    <template #intro><p>Задайте новый пароль, чтобы вернуться к своим практикам.</p></template>
    <form
      class="login-form"
      @submit.prevent="
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') })
      "
    >
      <label class="field">
        <span class="label">Электронная почта</span>
        <input
          v-model="form.email"
          class="input"
          type="email"
          autocomplete="email"
          required
          :aria-invalid="!!form.errors.email"
          :aria-describedby="form.errors.email ? 'reset-email-error' : undefined"
        />
        <span v-if="form.errors.email" id="reset-email-error" class="field-error">{{
          form.errors.email
        }}</span>
      </label>
      <label class="field">
        <span class="label">Пароль — не менее 8 символов</span>
        <input
          v-model="form.password"
          class="input"
          type="password"
          autocomplete="new-password"
          minlength="8"
          required
          placeholder="Придумайте новый пароль"
          :aria-invalid="!!form.errors.password"
          :aria-describedby="form.errors.password ? 'reset-password-error' : undefined"
        />
        <span v-if="form.errors.password" id="reset-password-error" class="field-error">{{
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
          placeholder="Введите новый пароль ещё раз"
          :aria-invalid="!!form.errors.password_confirmation"
          :aria-describedby="form.errors.password_confirmation ? 'reset-confirmation-error' : undefined"
        />
        <span v-if="form.errors.password_confirmation" id="reset-confirmation-error" class="field-error">{{
          form.errors.password_confirmation
        }}</span>
      </label>
      <button class="btn btn-primary login-submit" :disabled="form.processing">
        {{ form.processing ? 'Сохраняем…' : 'Сохранить пароль' }}<ArrowRight :size="19" />
      </button>
    </form>
    <template #links
      ><Link href="/login">Вернуться ко входу <span aria-hidden="true">↗</span></Link></template
    >
  </BrandAuthFrame>
</template>
