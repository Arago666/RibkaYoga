<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppShell from '@/shared/AppShell.vue';
import { api } from '@/shared/api';
import { uid, type Practice } from '../types';
const props = defineProps<{ token: string }>();
const page = usePage();
const signedIn = computed(() => !!(page.props.auth as { user?: unknown } | undefined)?.user);
const data = ref<{ document: Practice; allow_copy: boolean; expires_at: string | null } | null>(null),
  frame = ref<HTMLIFrameElement | null>(null),
  height = ref(650),
  ready = ref(false),
  error = ref(''),
  busy = ref(false),
  operationKey = ref(uid());
async function load() {
  try {
    data.value = (await api<{ data: NonNullable<typeof data.value> }>(`/api/published/${props.token}`)).data;
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Ссылка недоступна.';
  }
}
async function frameLoaded() {
  const content = frame.value?.contentDocument;
  ready.value = false;
  if (content?.querySelector('h1')) {
    await content.fonts.ready;
    await Promise.all(
      Array.from(content.images).map((image) =>
        image.complete
          ? Promise.resolve()
          : new Promise<void>((resolve) => {
              image.onload = () => resolve();
              image.onerror = () => resolve();
            }),
      ),
    );
    if (Array.from(content.images).some((image) => !image.naturalWidth)) {
      error.value = 'Не все изображения загрузились. Обновите лист перед печатью.';
      return;
    }
    ready.value = true;
    height.value = Math.max(500, content.documentElement.scrollHeight + 30);
  }
}
function print() {
  if (ready.value) frame.value?.contentWindow?.print();
}
async function copy() {
  if (busy.value || !data.value?.allow_copy) return;
  busy.value = true;
  error.value = '';
  try {
    const result = await api<{ data: { id: string } }>(`/api/published/${props.token}/copy`, {
      method: 'POST',
      body: JSON.stringify({ idempotency_key: operationKey.value }),
    });
    router.visit(`/practices/${result.data.id}/edit`);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось создать копию.';
  } finally {
    busy.value = false;
  }
}
onMounted(load);
</script>
<template>
  <Head :title="data?.document.title || 'Опубликованная практика'"
    ><meta name="robots" content="noindex,nofollow" /><meta name="referrer" content="no-referrer" /></Head
  ><AppShell section="practices" wide
    ><p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <header v-if="data" class="page-header">
      <div>
        <h1 class="page-title">{{ data.document.title }}</h1>
        <p class="muted">
          Опубликованная версия занятия. Правки автора после публикации не меняют этот лист.
        </p>
      </div>
      <div class="published-actions">
        <Link class="btn btn-primary" :href="`/p/${token}/run`">Практиковать</Link
        ><button class="btn btn-secondary" :disabled="!ready" @click="print">Печать / PDF</button
        ><button v-if="data.allow_copy && signedIn" class="btn btn-secondary" :disabled="busy" @click="copy">
          Создать копию в своём кабинете</button
        ><Link v-else-if="data.allow_copy" class="btn btn-secondary" href="/login"
          >Войти для независимой копии</Link
        >
      </div>
    </header>
    <div v-if="data" class="published-sheet">
      <iframe
        ref="frame"
        :src="`/api/published/${token}/sheet`"
        :style="{ height: `${height}px` }"
        title="Опубликованный лист"
        sandbox="allow-same-origin allow-modals"
        @load="frameLoaded"
      />
    </div>
    <p v-if="data" class="muted">
      Без входа выполнение остаётся на этом устройстве и не записывается в личную историю на сервере. Автор
      может отозвать ссылку.
    </p></AppShell
  >
</template>
<style scoped>
.published-actions {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
}
.published-sheet {
  background: #fff;
  border: 1px solid #dce6d6;
  border-radius: 12px;
  padding: 18px;
  overflow: auto;
  margin: 20px 0;
}
.published-sheet iframe {
  width: 100%;
  min-width: 1050px;
  border: 0;
  display: block;
}
</style>
