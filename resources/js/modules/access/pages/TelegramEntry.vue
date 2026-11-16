<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { ArrowRight, RefreshCw } from '@lucide/vue';
import { api } from '@/shared/api';
import { getTelegramLaunchData } from '@/shared/telegramMiniApp';
const busy = ref(true),
  error = ref(''),
  outside = ref(false);
async function enter() {
  busy.value = true;
  error.value = '';
  try {
    const proof = await getTelegramLaunchData();
    if (!proof) {
      outside.value = true;
      return;
    }
    await api('/api/telegram/session', {
      method: 'POST',
      body: JSON.stringify({ init_data: proof }),
    });
    // Keep the SDK and its safe-area state alive after the authenticated session rotation.
    router.visit('/dashboard', { replace: true });
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось войти. Откройте Mini App заново.';
  } finally {
    busy.value = false;
  }
}
onMounted(() => {
  void enter();
});
</script>
<template>
  <Head title="Вход через Telegram" />
  <main class="telegram-entry">
    <div class="telegram-brand">
      <img src="/favicon.svg" alt="" width="42" height="42" /><strong>ribkayoga</strong>
    </div>
    <section class="card telegram-entry-card" aria-live="polite" :aria-busy="busy">
      <template v-if="outside">
        <h1>Откройте RibkaYoga в Telegram</h1>
        <p>Там вы войдёте автоматически через свой аккаунт Telegram.</p>
        <a class="btn btn-primary" href="https://t.me/ribkayogabot?startapp&mode=fullscreen"
          >Открыть Mini App<ArrowRight :size="18"
        /></a>
        <Link class="telegram-site-link" href="/">Перейти на сайт</Link>
      </template>
      <template v-else-if="error">
        <h1>Не удалось войти</h1>
        <p role="alert">{{ error }}</p>
        <button class="btn btn-secondary" :disabled="busy" @click="enter()">
          <RefreshCw :size="18" />Повторить
        </button>
      </template>
      <template v-else>
        <span class="telegram-entry-spinner" aria-hidden="true"></span>
        <h1>Входим через Telegram</h1>
        <p>Открываем ваше пространство для практики…</p>
      </template>
    </section>
  </main>
</template>
<style scoped>
.telegram-entry {
  min-height: 100dvh;
  max-width: 460px;
  margin: 0 auto;
  padding: max(24px, var(--app-safe-top)) 20px max(24px, var(--app-safe-bottom));
}
.telegram-brand {
  display: flex;
  gap: 12px;
  align-items: center;
  margin-bottom: 32px;
  font-size: 24px;
  letter-spacing: -0.04em;
}
.telegram-entry-card {
  display: grid;
  gap: 20px;
  padding: 28px 24px;
  border-radius: 30px;
}
h1 {
  font-size: 26px;
  line-height: 1.15;
  letter-spacing: -0.04em;
  margin: 0;
}
p {
  margin: 0;
  color: var(--muted);
  line-height: 1.6;
  font-size: 15px;
  overflow-wrap: anywhere;
}
.telegram-site-link {
  text-align: center;
  color: var(--accent-dark);
  padding: 8px;
}
.telegram-entry-spinner {
  width: 30px;
  height: 30px;
  border: 2px solid var(--line);
  border-top-color: var(--accent-dark);
  border-radius: 50%;
  animation: entry-spin 1s linear infinite;
}
@keyframes entry-spin {
  to {
    transform: rotate(360deg);
  }
}
@media (prefers-reduced-motion: reduce) {
  .telegram-entry-spinner {
    animation: none;
  }
}
</style>
