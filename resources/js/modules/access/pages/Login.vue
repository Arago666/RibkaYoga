<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Eye, EyeOff } from '@lucide/vue';
import { ref } from 'vue';
import BrandMotif from '@/shared/BrandMotif.vue';
import '../../../../css/brand-app.css';
defineProps<{ status?: string }>();
const form = useForm({ login: '', password: '', remember: true });
const visible = ref(false);
function submit() {
  form.post('/login', { onFinish: () => form.reset('password') });
}
</script>
<template>
  <Head title="Вход">
    <meta
      head-key="description"
      name="description"
      content="Войдите в RibkaYoga. Ваша библиотека асан, практики и пространство для осознанного движения."
    />
  </Head>
  <main class="brand-login-page">
    <header class="brand-login-header">
      <Link href="/" class="brand" aria-label="RibkaYoga — главная страница">
        <img src="/favicon.svg" alt="" /><span>ribka<span class="brand-light">yoga</span></span>
      </Link>
      <Link href="/" class="brand-login-back"><ArrowLeft :size="16" />На главную</Link>
    </header>
    <div class="brand-login-layout">
      <aside class="brand-login-visual" aria-label="Пространство для вашей практики">
        <img
          src="/images/brand/glass-garden-hero.webp"
          alt="Стеклянная форма и зелёный островок мха"
          class="brand-login-art"
          width="1536"
          height="1024"
        />
        <BrandMotif class="brand-login-motif" />
        <div class="brand-login-caption">
          <span class="brand-login-kicker"
            ><span class="brand-seed" aria-hidden="true"></span>Меньше суеты. Больше практики.</span
          >
          <h2>Всё начинается<br />с движения.</h2>
          <p>
            Собирайте последовательности, сохраняйте идеи<br class="brand-login-desktop-break" />и оставляйте
            больше времени для йоги.
          </p>
        </div>
        <span class="brand-login-art-note" aria-hidden="true">RibkaYoga · пространство для практики</span>
      </aside>
      <section class="brand-login-form-panel">
        <div class="brand-login-form-inner">
          <span class="eyebrow">Ваше пространство</span>
          <h1>С возвращением.</h1>
          <p class="brand-login-intro">Войдите, чтобы продолжить свою практику.</p>
          <p v-if="status" class="success-banner" role="status">{{ status }}</p>
          <form @submit.prevent="submit" class="login-form">
            <label class="field">
              <span class="label">Логин</span>
              <input
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
                :aria-invalid="!!form.errors.login"
                :aria-describedby="form.errors.login ? 'login-error' : undefined"
              />
              <span v-if="form.errors.login" id="login-error" class="field-error">{{
                form.errors.login
              }}</span>
            </label>
            <label class="field">
              <span class="label">Пароль</span>
              <span class="password-field">
                <input
                  v-model="form.password"
                  class="input"
                  :type="visible ? 'text' : 'password'"
                  autocomplete="current-password"
                  required
                  placeholder="Введите пароль"
                  :aria-invalid="!!form.errors.password"
                  :aria-describedby="form.errors.password ? 'password-error' : undefined"
                />
                <button
                  type="button"
                  class="icon-btn"
                  :aria-label="visible ? 'Скрыть пароль' : 'Показать пароль'"
                  :aria-pressed="visible"
                  @click="visible = !visible"
                >
                  <component :is="visible ? EyeOff : Eye" :size="18" />
                </button>
              </span>
              <span v-if="form.errors.password" id="password-error" class="field-error">{{
                form.errors.password
              }}</span>
            </label>
            <div class="brand-login-options">
              <label class="check-label"
                ><input type="checkbox" v-model="form.remember" />Запомнить меня</label
              >
              <Link href="/forgot-password">Забыли пароль?</Link>
            </div>
            <button class="btn btn-primary login-submit" :disabled="form.processing">
              {{ form.processing ? 'Входим…' : 'Войти в пространство' }}<ArrowRight :size="19" />
            </button>
          </form>
          <p class="brand-login-register">
            Пока нет кабинета? <Link href="/register">Создать аккаунт <span aria-hidden="true">↗</span></Link>
          </p>
        </div>
        <p class="brand-login-footnote">Ваша практика. В вашем ритме.</p>
      </section>
    </div>
  </main>
</template>
