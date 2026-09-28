<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileDown, LoaderCircle, Printer, RefreshCw } from '@lucide/vue';
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import AppShell from '@/shared/AppShell.vue';
import { api } from '@/shared/api';
import SequencePreview from '../components/SequencePreview.vue';
import type { Practice } from '../types';

const props = withDefaults(defineProps<{ documentId: string; printMode?: boolean }>(), { printMode: false });
const practice = ref<Practice | null>(null);
const loading = ref(true);
const loadError = ref('');
const assetError = ref('');
const preparing = ref(false);
const printReady = ref(false);
const showNames = ref(false);
const imageVersion = ref(0);
const sheetRegion = ref<HTMLElement | null>(null);
let controller = new AbortController();
let preparation = 0;
let disposed = false;
let autoPrinted = false;

function waitForImage(image: HTMLImageElement, signal: AbortSignal): Promise<boolean> {
  return new Promise((resolve) => {
    if (signal.aborted) return resolve(false);
    if (image.complete) return resolve(image.naturalWidth > 0);
    const finish = (loaded: boolean) => {
      window.clearTimeout(timeout);
      image.removeEventListener('load', loadedImage);
      image.removeEventListener('error', failedImage);
      signal.removeEventListener('abort', failedImage);
      resolve(loaded);
    };
    const loadedImage = () => finish(image.naturalWidth > 0);
    const failedImage = () => finish(false);
    const timeout = window.setTimeout(failedImage, 25000);
    image.addEventListener('load', loadedImage, { once: true });
    image.addEventListener('error', failedImage, { once: true });
    signal.addEventListener('abort', failedImage, { once: true });
  });
}

async function prepareImages() {
  const current = ++preparation;
  preparing.value = true;
  printReady.value = false;
  assetError.value = '';
  await nextTick();
  try {
    await document.fonts.ready;
    const images = Array.from(sheetRegion.value?.querySelectorAll<HTMLImageElement>('img') ?? []);
    const results = await Promise.all(
      images.map(async (image) => {
        const loaded = await waitForImage(image, controller.signal);
        if (!loaded) return false;
        try {
          await image.decode();
          return image.naturalWidth > 0;
        } catch {
          return false;
        }
      }),
    );
    if (disposed || current !== preparation) return;
    const missing = images.filter((_image, index) => !results[index]);
    if (missing.length) {
      const names = [...new Set(missing.map((image) => image.alt))].slice(0, 3).join(', ');
      assetError.value = `Не удалось загрузить ${missing.length} изображений${names ? `: ${names}` : ''}. Повторите загрузку, чтобы напечатать лист полностью.`;
      return;
    }
    printReady.value = true;
    if (props.printMode && !autoPrinted) {
      autoPrinted = true;
      await printSheet();
    }
  } catch {
    if (!disposed && current === preparation)
      assetError.value = 'Не удалось подготовить лист к печати. Повторите загрузку изображений.';
  } finally {
    if (!disposed && current === preparation) preparing.value = false;
  }
}

async function loadPractice() {
  controller.abort();
  controller = new AbortController();
  loading.value = true;
  loadError.value = '';
  printReady.value = false;
  try {
    const result = await api<{ data: Practice }>(`/api/practices/${props.documentId}`, {
      signal: controller.signal,
    });
    if (disposed) return;
    practice.value = result.data;
    loading.value = false;
    await prepareImages();
  } catch (error) {
    if (disposed || (error instanceof DOMException && error.name === 'AbortError')) return;
    loadError.value = error instanceof Error ? error.message : 'Не удалось открыть практику.';
  } finally {
    if (!disposed) loading.value = false;
  }
}

async function retryImages() {
  imageVersion.value++;
  await prepareImages();
}

async function printSheet() {
  if (!printReady.value || !practice.value || disposed) return;
  await nextTick();
  await new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve())));
  if (!disposed && printReady.value) window.print();
}

onMounted(loadPractice);
onBeforeUnmount(() => {
  disposed = true;
  preparation++;
  controller.abort();
});
</script>

<template>
  <Head :title="practice ? `${practice.title} — лист практики` : 'Лист практики'" />
  <AppShell section="practices" :class="['practice-preview-shell', { 'print-blocked': !printReady }]">
    <div class="preview-controls">
      <div class="preview-heading">
        <Link :href="`/practices/${documentId}/edit`" class="preview-back"
          ><ArrowLeft :size="17" />К редактированию</Link
        >
        <h1 class="page-title">Готовый лист</h1>
        <p class="page-description">Посмотрите последовательность целиком и сохраните её для занятия.</p>
      </div>
      <button type="button" class="btn btn-primary" :disabled="!printReady" @click="printSheet">
        <Printer :size="18" />Печать / PDF
      </button>
    </div>

    <div v-if="loadError" class="error-banner preview-ui" role="alert">
      <p>{{ loadError }}</p>
      <button class="btn btn-secondary" type="button" @click="loadPractice">
        <RefreshCw :size="16" />Повторить
      </button>
    </div>
    <div v-if="assetError" class="error-banner preview-ui" role="alert">
      <p>{{ assetError }}</p>
      <button class="btn btn-secondary" type="button" :disabled="preparing" @click="retryImages">
        <RefreshCw :size="16" />Загрузить изображения снова
      </button>
    </div>

    <div v-if="practice" class="preview-settings preview-ui">
      <label class="check-label"><input v-model="showNames" type="checkbox" />Показывать названия асан</label>
      <span v-if="preparing" class="readiness-status" role="status"
        ><LoaderCircle :size="15" class="spinning" />Подготавливаем изображения…</span
      >
      <span v-else-if="printReady" class="readiness-status">A4 · альбомная ориентация</span>
    </div>

    <div v-if="loading" class="preview-loading preview-ui" role="status">
      <LoaderCircle :size="25" class="spinning" /><span>Открываем лист практики…</span>
    </div>
    <div v-if="practice" ref="sheetRegion" class="sheet-region">
      <SequencePreview :document="practice" :show-names="showNames" :image-version="imageVersion" />
    </div>
    <p v-if="practice" class="mobile-sheet-hint preview-ui">Лист можно прокручивать по горизонтали.</p>
    <p class="print-readiness-warning">
      Лист ещё не готов к печати. Дождитесь загрузки всех изображений на странице и нажмите «Печать / PDF».
    </p>
    <p v-if="practice" class="pdf-hint preview-ui">
      <FileDown :size="17" /><span
        >Чтобы получить PDF, выберите «Сохранить как PDF» в окне печати. Для чистого листа отключите
        колонтитулы браузера.</span
      >
    </p>
  </AppShell>
</template>

<style scoped>
.preview-controls {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  margin-bottom: 26px;
}
.preview-back {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  color: var(--muted);
  font-size: 14px;
  margin-bottom: 18px;
}
.preview-back:hover {
  color: var(--accent);
}
.preview-settings {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 16px;
}
.readiness-status {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-size: 13px;
  color: var(--muted);
}
.sheet-region {
  width: 100%;
  overflow: hidden;
  border: 1px solid var(--border);
  border-radius: 16px;
  box-shadow: 0 8px 30px #22361c04;
  background: #fff;
}
.preview-loading {
  min-height: 340px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 18px;
  color: var(--muted);
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 16px;
}
.error-banner .btn {
  margin-top: 10px;
}
.pdf-hint {
  display: flex;
  justify-content: center;
  align-items: flex-start;
  gap: 9px;
  font-size: 13px;
  color: var(--muted);
  line-height: 1.6;
  margin-top: 22px;
}
.pdf-hint svg {
  margin-top: 2px;
}
.print-readiness-warning {
  display: none;
}
.mobile-sheet-hint {
  display: none;
}
.spinning {
  animation: preview-spin 1.2s linear infinite;
}
@keyframes preview-spin {
  to {
    transform: rotate(360deg);
  }
}
@media (max-width: 760px) {
  .preview-controls {
    flex-wrap: wrap;
    gap: 20px;
  }
  .preview-controls > .btn {
    width: 100%;
  }
  .sheet-region {
    border-radius: 10px;
    overflow-x: auto;
  }
  .mobile-sheet-hint {
    display: block;
    margin-top: 10px;
    color: var(--muted);
    font-size: 12px;
  }
  .preview-settings {
    font-size: 13px;
  }
}
@page ribka-sequence {
  size: A4 landscape;
  margin: 10mm;
}
@media print {
  .practice-preview-shell {
    page: ribka-sequence;
    display: block !important;
    min-height: 0 !important;
    background: #fff !important;
  }
  .practice-preview-shell :deep(.app-header),
  .practice-preview-shell :deep(.app-footer),
  .preview-controls,
  .preview-ui {
    display: none !important;
  }
  .practice-preview-shell :deep(.app-main) {
    display: block !important;
    width: 100% !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
  }
  .sheet-region {
    display: block;
    width: 100%;
    overflow: visible;
    border: 0;
    border-radius: 0;
    box-shadow: none;
  }
  .print-blocked .sheet-region {
    display: none !important;
  }
  .print-blocked .print-readiness-warning {
    display: block;
    font-size: 12pt;
    padding: 10mm;
  }
}
</style>
