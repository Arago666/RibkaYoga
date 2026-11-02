<script setup lang="ts">
import AppSelect from '@/shared/AppSelect.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import AppShell from '@/shared/AppShell.vue';
import { api } from '@/shared/api';
import PublicationDialog from '../components/PublicationDialog.vue';
import type { Practice } from '../types';
const props = withDefaults(defineProps<{ documentId: string; printMode?: boolean }>(), { printMode: false });
const practice = ref<Practice | null>(null),
  loading = ref(true),
  error = ref(''),
  printReady = ref(false),
  preset = ref<'clean' | 'names' | 'teach'>('clean'),
  showNames = ref(false),
  orientation = ref<'portrait' | 'landscape'>('landscape'),
  preferenceRevision = ref<number | null>(null),
  preferencesMessage = ref(''),
  sharing = ref(false),
  frame = ref<HTMLIFrameElement | null>(null),
  frameHeight = ref(650);
const options = computed(
  () =>
    new URLSearchParams({
      preset: preset.value,
      show_names: showNames.value ? '1' : '0',
      orientation: orientation.value,
    }),
);
const sheetUrl = computed(() => `/api/practices/${props.documentId}/sheet?${options.value}`);
const pdfUrl = computed(() => `/api/practices/${props.documentId}/pdf?${options.value}`);
let autoPrinted = false,
  preparation = 0;
watch([preset, showNames, orientation], () => {
  printReady.value = false;
  error.value = '';
});
function choosePreset() {
  showNames.value = preset.value !== 'clean';
}
async function load() {
  loading.value = true;
  error.value = '';
  try {
    const [document, preferences] = await Promise.all([
      api<{ data: Practice }>(`/api/practices/${props.documentId}`),
      api<{
        data: {
          revision: number | null;
          preferences: {
            preset: 'clean' | 'names' | 'teach';
            show_names: boolean;
            orientation: 'portrait' | 'landscape';
          };
        };
      }>('/api/practice-output-preferences'),
    ]);
    practice.value = document.data;
    preferenceRevision.value = preferences.data.revision;
    preset.value = preferences.data.preferences.preset;
    showNames.value = preferences.data.preferences.show_names;
    orientation.value = preferences.data.preferences.orientation;
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось открыть практику.';
  } finally {
    loading.value = false;
  }
}
async function loadedSheet() {
  const version = ++preparation;
  printReady.value = false;
  try {
    const content = frame.value?.contentDocument;
    if (!content || content.querySelector('h1')?.textContent !== practice.value?.title)
      throw new Error('Не удалось загрузить полный лист. Обновите страницу.');
    await content.fonts.ready;
    const images = Array.from(content.images);
    const results = await Promise.all(
      images.map(
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
    if (version !== preparation) return;
    if (results.some((value) => !value))
      throw new Error('Не все изображения загрузились. Повторите подготовку листа.');
    frameHeight.value = Math.max(500, content.documentElement.scrollHeight + 30);
    printReady.value = true;
    if (props.printMode && !autoPrinted) {
      autoPrinted = true;
      printSheet();
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось подготовить лист.';
  }
}
function printSheet() {
  if (!printReady.value) return;
  frame.value?.contentWindow?.focus();
  frame.value?.contentWindow?.print();
}
async function savePreferences() {
  preferencesMessage.value = '';
  try {
    const result = await api<{ data: { revision: number } }>('/api/practice-output-preferences', {
      method: 'PUT',
      body: JSON.stringify({
        preset: preset.value,
        show_names: showNames.value,
        orientation: orientation.value,
        expected_revision: preferenceRevision.value,
      }),
    });
    preferenceRevision.value = result.data.revision;
    preferencesMessage.value = 'Настройки листа сохранены для вашего кабинета.';
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось сохранить настройки.';
  }
}
onMounted(load);
</script>
<template>
  <Head :title="practice ? `${practice.title} — готовый лист` : 'Лист практики'" />
  <AppShell section="practices" wide>
    <header class="page-header">
      <div>
        <Link class="btn btn-ghost" :href="`/practices/${documentId}/edit`">К редактированию</Link>
        <h1 class="page-title">Готовый лист</h1>
        <p v-if="practice" class="page-description">{{ practice.title }}</p>
      </div>
      <div class="output-actions">
        <button class="btn btn-primary" :disabled="!printReady" @click="printSheet">
          Печать / PDF браузера</button
        ><a class="btn btn-secondary" :href="pdfUrl" :aria-disabled="!practice" target="_blank" rel="noopener"
          >Скачать PDF</a
        ><a class="btn btn-secondary" :href="`/api/practices/${documentId}/bundle`"
          >Экспорт JSON с рисунками</a
        ><button class="btn btn-secondary" :disabled="!practice" @click="sharing = true">
          Поделиться по ссылке
        </button>
      </div>
    </header>
    <p v-if="error" class="error-banner" role="alert">
      {{ error }} <button class="btn btn-ghost" @click="load">Повторить</button>
    </p>
    <p v-if="loading" class="muted" role="status">Открываем лист…</p>
    <div v-if="practice" class="card output-settings">
      <label class="field"
        ><span class="label">Преднастройка</span
        ><AppSelect v-model="preset" class="select" @change="choosePreset">
          <option value="clean">Чистый лист</option>
          <option value="names">С названиями</option>
          <option value="teach">Для проведения</option>
        </AppSelect></label
      ><label class="field"
        ><span class="label">Ориентация A4</span
        ><AppSelect v-model="orientation" class="select">
          <option value="landscape">Альбомная</option>
          <option value="portrait">Книжная</option>
        </AppSelect></label
      ><label class="check-label"
        ><input v-model="showNames" type="checkbox" :disabled="preset === 'clean'" />Названия асан</label
      ><button class="btn btn-ghost" @click="savePreferences">Запомнить настройки</button
      ><span class="muted" role="status">{{ printReady ? 'Лист готов' : 'Загружаем рисунки…' }}</span>
    </div>
    <p v-if="preferencesMessage" class="success-banner" role="status">{{ preferencesMessage }}</p>
    <div v-if="practice" class="sheet-region">
      <iframe
        ref="frame"
        :key="sheetUrl"
        :src="sheetUrl"
        class="sheet-frame"
        :class="orientation"
        :style="{ height: `${frameHeight}px` }"
        title="Готовый лист практики"
        sandbox="allow-same-origin allow-modals"
        @load="loadedSheet"
      />
    </div>
    <p v-if="practice" class="muted output-hint">
      На узком экране лист прокручивается по горизонтали. Просмотр и скачиваемый PDF используют один макет;
      исходные изображения остаются в базе.
    </p>
    <PublicationDialog
      v-if="practice"
      :open="sharing"
      :document-id="documentId"
      :revision="practice.revision"
      :show-names="showNames"
      :orientation="orientation"
      @close="sharing = false"
    />
  </AppShell>
</template>
<style scoped>
.output-actions,
.output-settings {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.page-header > div:first-child {
  flex: 1 1 260px;
  min-width: 0;
}
.output-actions {
  justify-content: flex-end;
}
.output-settings {
  align-items: flex-end;
  margin: var(--section-gap) 0;
  padding: var(--panel-padding);
  gap: 20px;
}
.output-settings .field {
  flex: 1 1 180px;
  max-width: 260px;
  min-width: 0;
}
.output-settings .check-label {
  min-height: var(--control-height);
}
.output-settings > .muted {
  flex-basis: 100%;
  font-size: 13px;
}
.sheet-region {
  overflow: auto;
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: var(--panel-radius);
  padding: var(--panel-padding);
  box-shadow: var(--panel-shadow);
  min-width: 0;
}
.sheet-frame {
  display: block;
  border: 0;
  width: 100%;
  background: #fff;
}
.sheet-frame.landscape {
  min-width: 1050px;
}
.sheet-frame.portrait {
  min-width: 730px;
}
.output-hint {
  margin-top: 16px;
  font-size: 13px;
  line-height: 1.65;
}
@media (max-width: 700px) {
  .output-actions {
    justify-content: flex-start;
    width: 100%;
  }
  .output-actions .btn {
    flex: 1 1 auto;
    white-space: normal;
  }
  .output-settings .field {
    max-width: none;
  }
}
</style>
