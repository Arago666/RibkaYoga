<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Download, Share2 } from '@lucide/vue';
import { api, ApiError } from './api';
import Modal from './Modal.vue';
import { loadTelegramPdfDownloader } from './telegramMiniApp';

defineOptions({ inheritAttrs: false });
const props = defineProps<{ href: string; title: string; disabled?: boolean; nativeDownload?: boolean }>();
type TicketResponse = { url: string; filename: string; expires_at: string; expires_in?: number };
type TelegramDownloader = NonNullable<Awaited<ReturnType<typeof loadTelegramPdfDownloader>>>;
type NativePdf = { url: string; filename: string; expiresAt: number; download: TelegramDownloader };

const page = usePage();
const open = ref(false);
const busy = ref(false);
const sending = ref(false);
const error = ref('');
const notice = ref('');
const file = ref<File | null>(null);
const fileUrl = ref('');
const nativePdf = ref<NativePdf | null>(null);
const offerFallback = ref(false);
const rateLimitMessage = 'Слишком много запросов PDF. Подождите минуту и повторите.';
let request: AbortController | undefined;
let nativeResponseTimer: ReturnType<typeof setTimeout> | undefined;
const canShare = computed(() => {
  try {
    return Boolean(
      file.value && typeof navigator.share === 'function' && navigator.canShare?.({ files: [file.value] }),
    );
  } catch {
    return false;
  }
});

function reset() {
  request?.abort();
  request = undefined;
  clearTimeout(nativeResponseTimer);
  nativePdf.value = null;
  if (fileUrl.value) URL.revokeObjectURL(fileUrl.value);
  fileUrl.value = '';
  file.value = null;
  busy.value = false;
  sending.value = false;
  error.value = '';
  notice.value = '';
  offerFallback.value = false;
}

function close() {
  open.value = false;
  reset();
}

function prepare(event: MouseEvent) {
  if (props.disabled) {
    event.preventDefault();
    return;
  }
  if (!page.props.miniApp) return;
  event.preventDefault();
  open.value = true;
  void preparePdf();
}

async function preparePdf(tryNative = true) {
  reset();
  busy.value = true;
  const current = new AbortController();
  request = current;
  try {
    const download =
      tryNative && props.nativeDownload && location.protocol === 'https:'
        ? await loadTelegramPdfDownloader()
        : null;
    if (current.signal.aborted) return;
    if (download) {
      offerFallback.value = true;
      const endpoint = new URL(props.href, location.origin);
      const ticket = await api<TicketResponse>(`${endpoint.pathname}/download-ticket${endpoint.search}`, {
        method: 'POST',
        signal: current.signal,
      });
      if (current.signal.aborted) return;
      const url = new URL(ticket.url);
      const lifetime =
        typeof ticket.expires_in === 'number'
          ? ticket.expires_in * 1000
          : Date.parse(ticket.expires_at) - Date.now();
      if (
        url.origin !== location.origin ||
        url.protocol !== 'https:' ||
        !ticket.filename ||
        !Number.isFinite(lifetime) ||
        lifetime <= 5000
      ) {
        throw new Error('Telegram не получил ссылку на PDF.');
      }
      nativePdf.value = {
        url: url.href,
        filename: ticket.filename,
        expiresAt: performance.now() + lifetime,
        download,
      };
      offerFallback.value = false;
      return;
    }

    const response = await fetch(props.href, {
      credentials: 'same-origin',
      signal: current.signal,
      cache: 'no-store',
      headers: { Accept: 'application/pdf, application/json' },
    });
    if (!response.ok || !response.headers.get('Content-Type')?.startsWith('application/pdf')) {
      throw new Error(
        response.status === 429
          ? rateLimitMessage
          : response.status === 401 || response.redirected
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
    if (current.signal.aborted) return;
    if (failure instanceof ApiError && [401, 419].includes(failure.status)) {
      error.value = 'Сессия закончилась. Откройте Mini App заново.';
      offerFallback.value = false;
    } else if (failure instanceof ApiError && failure.status === 429) {
      error.value = rateLimitMessage;
      offerFallback.value = false;
    } else {
      error.value =
        failure instanceof Error && failure.name !== 'TypeError'
          ? failure.message
          : 'Не удалось подготовить PDF. Проверьте соединение и повторите попытку.';
    }
  } finally {
    if (request === current) busy.value = false;
  }
}

function downloadInTelegram() {
  const pdf = nativePdf.value;
  if (!pdf || sending.value) return;
  if (performance.now() >= pdf.expiresAt - 5000) {
    void preparePdf();
    return;
  }
  sending.value = true;
  error.value = '';
  notice.value = '';
  offerFallback.value = false;
  nativeResponseTimer = setTimeout(() => {
    if (nativePdf.value !== pdf) return;
    notice.value = 'Если Telegram не показал окно загрузки, выберите другой способ.';
    offerFallback.value = true;
  }, 12000);
  try {
    // This call must start directly from a tap. The native downloader does not use WebView cookies.
    pdf.download(pdf.url, pdf.filename, (accepted) => {
      if (nativePdf.value !== pdf) return;
      clearTimeout(nativeResponseTimer);
      sending.value = false;
      if (accepted) {
        notice.value = 'Telegram начал загрузку. После её завершения откройте файл на устройстве.';
        offerFallback.value = false;
      } else {
        notice.value = 'Загрузка не началась. Можно повторить или выбрать другой способ.';
        offerFallback.value = true;
      }
    });
  } catch {
    clearTimeout(nativeResponseTimer);
    sending.value = false;
    error.value = 'Telegram не открыл окно загрузки. Попробуйте другой способ.';
    offerFallback.value = true;
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
    <p v-if="notice" role="status">{{ notice }}</p>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <p v-if="nativePdf || file" class="pdf-filename">{{ nativePdf?.filename || file?.name }}</p>
    <template v-if="!busy && (nativePdf || file || error)" #footer>
      <button v-if="offerFallback" class="btn btn-secondary" type="button" @click="preparePdf(false)">
        Другой способ
      </button>
      <button
        v-if="nativePdf"
        class="btn btn-primary"
        type="button"
        :disabled="sending"
        @click="downloadInTelegram"
      >
        <Download :size="17" />{{ sending ? 'Открываем Telegram…' : 'Скачать в Telegram' }}
      </button>
      <button v-else-if="!file" class="btn btn-secondary" type="button" @click="preparePdf()">
        Повторить
      </button>
      <button v-if="canShare" class="btn btn-primary" type="button" @click="share">
        <Share2 :size="17" />Поделиться
      </button>
      <a v-if="file" class="btn btn-secondary" :href="fileUrl" :download="file.name"
        ><Download :size="17" />Сохранить файл</a
      >
    </template>
  </Modal>
</template>

<style scoped>
.pdf-filename {
  overflow-wrap: anywhere;
}
</style>
