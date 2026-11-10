<script setup lang="ts">
import AppSelect from '@/shared/AppSelect.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { Download, ExternalLink, Printer } from '@lucide/vue';
import AppShell from '@/shared/AppShell.vue';
import SheetViewport from '@/shared/SheetViewport.vue';
import { api } from '@/shared/api';
import type { Review } from '../types';
const props = defineProps<{ reviewId: string }>();
const review = ref<Review | null>(null),
  error = ref(''),
  loading = ref(true),
  ready = ref(false);
const orientation = ref<'landscape' | 'portrait'>('landscape'),
  showNames = ref(false);
const frame = ref<HTMLIFrameElement | null>(null),
  height = ref(800);
const generation = ref(0);
const options = computed(
  () =>
    new URLSearchParams({
      orientation: orientation.value,
      show_names: showNames.value ? '1' : '0',
      expected_revision: String(review.value?.revision ?? 0),
    }),
);
const sheetUrl = computed(() => `/api/practice-reviews/${props.reviewId}/sheet?${options.value}`);
const pdfUrl = computed(() => `/api/practice-reviews/${props.reviewId}/pdf?${options.value}`);
let serial = 0;
async function load() {
  loading.value = true;
  error.value = '';
  try {
    review.value = (await api<{ data: Review }>(`/api/practice-reviews/${props.reviewId}`)).data;
    generation.value++;
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось открыть разбор.';
  } finally {
    loading.value = false;
  }
}
async function loaded() {
  const version = ++serial;
  ready.value = false;
  try {
    const content = frame.value?.contentDocument;
    if (
      !content ||
      content.querySelector('meta[name="ribka-review-id"]')?.getAttribute('content') !== props.reviewId ||
      content.querySelector('meta[name="ribka-review-revision"]')?.getAttribute('content') !==
        String(review.value?.revision)
    )
      throw new Error('Лист недоступен или разбор обновился. Обновите предварительный просмотр.');
    await content.fonts.ready;
    const results = await Promise.all(
      Array.from(content.images).map(
        (image) =>
          new Promise<boolean>((resolve) => {
            if (image.complete) {
              resolve(image.naturalWidth > 0);
              return;
            }
            const timer = setTimeout(() => resolve(false), 25000);
            image.addEventListener(
              'load',
              () => {
                clearTimeout(timer);
                resolve(image.naturalWidth > 0);
              },
              { once: true },
            );
            image.addEventListener(
              'error',
              () => {
                clearTimeout(timer);
                resolve(false);
              },
              { once: true },
            );
          }),
      ),
    );
    if (version !== serial) return;
    if (results.some((value) => !value))
      throw new Error('Не все страницы загрузились. Повторите подготовку.');
    height.value = Math.max(600, content.documentElement.scrollHeight + 24);
    ready.value = true;
    error.value = '';
  } catch (failure) {
    if (version === serial)
      error.value = failure instanceof Error ? failure.message : 'Не удалось подготовить лист.';
  }
}
function print() {
  if (ready.value) {
    frame.value?.contentWindow?.focus();
    frame.value?.contentWindow?.print();
  }
}
watch([orientation, showNames], () => {
  serial++;
  ready.value = false;
  error.value = '';
});
onMounted(load);
</script>
<template>
  <Head title="Предпросмотр разбора" />
  <AppShell section="reviews" wide>
    <header class="page-header">
      <div>
        <Link class="btn btn-ghost" :href="`/practice-reviews/${reviewId}`">← К пометкам</Link>
        <h1 class="page-title">Предпросмотр разбора</h1>
        <p class="page-description">{{ review?.title }}</p>
      </div>
      <div class="preview-actions">
        <button class="btn" :disabled="!ready" @click="print"><Printer :size="18" />Печать</button
        ><a v-if="review" class="btn" :href="`${pdfUrl}&inline=1`" target="_blank" rel="noopener"
          ><ExternalLink :size="17" />Открыть PDF</a
        ><a v-if="review" class="btn btn-primary" :href="pdfUrl"><Download :size="17" />Скачать PDF</a>
      </div>
    </header>
    <p v-if="error" class="error-banner" role="alert">
      {{ error }} <button class="btn" @click="load">Обновить просмотр</button>
    </p>
    <p v-if="loading" class="muted" role="status">Подготавливаем разбор…</p>
    <div v-if="review" class="card preview-settings">
      <label class="field"
        ><span class="label">Ориентация A4</span
        ><AppSelect v-model="orientation" class="select">
          <option value="landscape">Альбомная</option>
          <option value="portrait">Книжная</option>
        </AppSelect></label
      ><label class="check-label"><input v-model="showNames" type="checkbox" />Названия асан</label
      ><span class="muted" role="status">{{ ready ? 'Лист готов' : 'Подготавливаем страницы…' }}</span>
    </div>
    <div v-if="review" class="review-preview-region">
      <SheetViewport
        :width="orientation === 'landscape' ? 1120 : 800"
        :height="height"
        label="Лист разбора с комментариями"
      >
        <iframe
          ref="frame"
          :key="`${sheetUrl}:${generation}`"
          :src="sheetUrl"
          title="Разбор с комментариями, группами и стрелками"
          class="review-preview-frame"
          :class="orientation"
          :style="{ height: `${height}px` }"
          sandbox="allow-same-origin allow-modals"
          @load="loaded"
        />
      </SheetViewport>
    </div>
    <p v-if="review" class="preview-hint muted">
      Комментарии и стрелки включены в лист. Предпросмотр и PDF используют один макет; на телефоне лист можно
      подогнать по ширине или рассмотреть в масштабе 100% с прокруткой.
    </p>
  </AppShell>
</template>
<style scoped>
.page-header > div:first-child {
  flex: 1 1 400px;
  min-width: 0;
}
.page-title {
  margin-top: 12px;
  color: #203d2c;
  font-weight: 550;
}
.preview-actions,
.preview-settings {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  align-items: center;
}
.preview-settings {
  margin-bottom: 24px;
  gap: 24px;
  background: linear-gradient(150deg, #f8faf4, #fff 70%);
  border-color: #e0e7da;
}
.preview-settings > .field {
  min-width: 200px;
}
.preview-settings .check-label {
  min-height: 44px;
}
.preview-settings > .muted {
  font-size: 13px;
}
.review-preview-region {
  max-width: 100%;
  overflow: auto;
  border: 1px solid var(--line);
  border-radius: var(--panel-radius);
  background: var(--surface-soft);
}
.review-preview-frame {
  display: block;
  border: 0;
  min-width: 800px;
  width: 100%;
  background: var(--surface-soft);
}
.review-preview-frame.landscape {
  min-width: 1120px;
}
.preview-hint {
  font-size: 13px;
  line-height: 1.6;
  margin-top: 20px;
}
@media (max-width: 620px) {
  .preview-actions {
    width: 100%;
  }
  .preview-actions > .btn {
    flex: 1;
    font-size: 14px;
  }
  .preview-settings {
    align-items: flex-start;
    gap: 16px;
  }
}
</style>
