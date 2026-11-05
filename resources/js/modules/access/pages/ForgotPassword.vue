<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import BrandAuthFrame from '@/shared/BrandAuthFrame.vue';
defineProps<{ status?: string; localMail: boolean; recoveryAvailable: boolean }>();
const form = useForm({ email: '' });
</script>
<template>
  <Head title="Восстановление пароля">
    <meta
      head-key="description"
      name="description"
      content="Восстановите доступ к своему кабинету RibkaYoga."
    />
  </Head>
  <BrandAuthFrame title="Восстановить пароль." eyebrow="Вернуться к своей практике">
    <template #intro>
      <p v-if="localMail">
        Локальный режим: письмо со ссылкой появится в <code>storage/logs/local-mail.log</code>.
      </p>
      <p v-else-if="recoveryAvailable">
        Укажите почту своего аккаунта. На неё придёт ссылка для смены пароля.
      </p>
      <p v-else>Отправка писем пока не подключена. Для восстановления доступа обратитесь к администратору.</p>
    </template>
    <p v-if="status" class="success-banner" role="status">{{ status }}</p>
    <form v-if="recoveryAvailable" class="login-form" @submit.prevent="form.post('/forgot-password')">
      <label class="field">
        <span class="label">Электронная почта</span>
        <input
          v-model="form.email"
          class="input"
          type="email"
          autocomplete="email"
          maxlength="255"
          required
          autofocus
          placeholder="Почта вашего аккаунта"
          :aria-invalid="!!form.errors.email"
          :aria-describedby="form.errors.email ? 'forgot-email-error' : undefined"
        />
        <span v-if="form.errors.email" id="forgot-email-error" class="field-error">{{
          form.errors.email
        }}</span>
      </label>
      <button class="btn btn-primary login-submit" :disabled="form.processing">
        {{ form.processing ? 'Отправляем…' : 'Получить ссылку' }}<ArrowRight :size="19" />
      </button>
    </form>
    <template #links
      ><Link href="/login">Вернуться ко входу <span aria-hidden="true">↗</span></Link></template
    >
  </BrandAuthFrame>
</template>
