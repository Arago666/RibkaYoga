<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Download, Share2 } from '@lucide/vue';
import Modal from './Modal.vue';

defineOptions({ inheritAttrs: false });
const props = defineProps<{ href: string; title: string; disabled?: boolean }>();
const page = usePage();
const open = ref(false);
const busy = ref(false);
const error = ref('');
const file = ref<File | null>(null);
const fileUrl = ref('');
let request: AbortController | undefined;
const canShare = computed(() => {
  try {
    return Boolean(file.value && typeof navigator.share === 'function' && navigator.canShare?.({ files: [file.value] }));
  } catch {
    return false;
  }
});

function close() {
  open.value = false;
  request?.abort();
  request = undefined;
  if (fileUrl.value) URL.revokeObjectURL(fileUrl.value);
  fileUrl.value = '';
  file.value = null;
  busy.value = false;
  error.value = '';
}

async function prepare(event: MouseEvent) {
  if (props.disabled) {
    event.preventDefault();
    return;
  }
  if (!page.props.miniApp) return;
  event.preventDefault();
  close();
  open.value = true;
  busy.value = true;
  const current = new AbortController();
  request = current;
  try {
    // Keep the private document in the signed-in WebView. A public URL would lose its session context.
    const response = await fetch(props.href, {
      credentials: 'same-origin',
      signal: current.signal,
      cache: 'no-store',
      headers: { Accept: 'application/pdf, application/json' },
    });
    if (!response.ok || !response.headers.get('Content-Type')?.startsWith('application/pdf')) {
      throw new Error(
        response.status === 401 || response.redirected
          ? 'Сессия закончилась. Откройте Mini App заново.'
          : 'Не удалось подготовить PDF. Проверьте соединение и повторите попытку.',
      );
    }
    const blob = await response.blob();
    if (current.signal.aborted) return;
    const name =
      props.title
        .replace(/[\\/:*?"<>|\u0000-\u001f]/g, '-')
        .trim()
        .slice(0, 120) || 'Практика';
    file.value = new File([blob], `${name}.pdf`, { type: 'application/pdf' });
    fileUrl.value = URL.createObjectURL(file.value);
  } catch (failure) {
    if (!current.signal.aborted)
      error.value = failure instanceof Error ? failure.message : 'Не удалось подготовить PDF.';
  } finally {
    if (request === current) busy.value = false;
  }
}

async function share() {
  if (!file.value || !canShare.value) return;
  try {
    await navigator.share({ files: [file.value], title: props.title });
  } catch (failure) {
    if (!(failure instanceof DOMException && failure.name === 'AbortError')) {
      error.value = 'Меню отправки недоступно. Попробуйте сохранить файл.';
    }
  }
}

watch(() => props.href, close);
onBeforeUnmount(close);
</script>

<template>
  <a
    v-bind="$attrs"
    :href="disabled ? undefined : href"
    :aria-disabled="disabled || undefined"
    @click="prepare"
    ><slot
  /></a>
  <Modal :open="open" title="PDF практики" @close="close">
    <p v-if="busy" role="status">Подготавливаем файл…</p>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <p v-if="file" class="pdf-filename">{{ file.name }}</p>
    <template v-if="file" #footer>
      <a class="btn btn-secondary" :href="fileUrl" :download="file.name"
        ><Download :size="17" />Сохранить файл</a
      >
      <button v-if="canShare" class="btn btn-primary" type="button" @click="share">
        <Share2 :size="17" />Поделиться
      </button>
    </template>
  </Modal>
</template>

<style scoped>
.pdf-filename {
  overflow-wrap: anywhere;
}
</style>
