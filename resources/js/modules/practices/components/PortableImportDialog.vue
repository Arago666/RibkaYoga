<script setup lang="ts">
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import { uid } from '../types';
interface ImportSummary {
  title: string;
  blocks: number;
  rows: number;
  cells: number;
  images: number;
  native_sources: number;
  missing_sources: number;
}
const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();
const bundle = ref<Record<string, unknown> | null>(null),
  summary = ref<{
    title: string;
    blocks: number;
    rows: number;
    cells: number;
    images: number;
    native_sources: number;
    missing_sources: number;
  } | null>(null),
  title = ref(''),
  busy = ref(false),
  error = ref(''),
  key = ref(uid());
watch(
  () => props.open,
  (open) => {
    if (open) {
      bundle.value = null;
      summary.value = null;
      title.value = '';
      error.value = '';
      key.value = uid();
    }
  },
);
async function select(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;
  error.value = '';
  summary.value = null;
  bundle.value = null;
  if (file.size > 44 * 1024 * 1024) {
    error.value = 'Пакет должен быть не больше 44 МБ.';
    return;
  }
  busy.value = true;
  try {
    const value = JSON.parse(await file.text()) as Record<string, unknown>;
    const response = await api<{ data: ImportSummary }>('/api/practice-bundles/preview', {
      method: 'POST',
      body: JSON.stringify({ bundle: value }),
    });
    summary.value = response.data;
    title.value = response.data.title;
    bundle.value = value;
    key.value = uid();
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось прочитать пакет.';
  } finally {
    busy.value = false;
  }
}
async function apply() {
  if (!bundle.value || !summary.value || busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    const response = await api<{ data: { id: string } }>('/api/practice-bundles/import', {
      method: 'POST',
      body: JSON.stringify({ bundle: bundle.value, title: title.value.trim(), idempotency_key: key.value }),
    });
    router.visit(`/practices/${response.data.id}/edit`);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось импортировать.';
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <Modal :open="open" title="Импорт независимой копии" @close="!busy && emit('close')"
    ><p class="muted">
      Выберите JSON-пакет RibkaYoga. Идентификаторы и изображения копируются в ваш кабинет; существующие
      документы не заменяются.
    </p>
    <input
      type="file"
      accept=".json,application/json"
      :disabled="busy"
      aria-label="Пакет RibkaYoga"
      @change="select"
    />
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <p v-if="busy" class="muted" role="status">Обрабатываем пакет…</p>
    <div v-if="summary" class="import-summary">
      <h3>{{ summary.title }}</h3>
      <p>
        {{ summary.blocks }} блоков · {{ summary.rows }} строк · {{ summary.cells }} ячеек ·
        {{ summary.images }} изображений
      </p>
      <p>Редактируемых исходников: {{ summary.native_sources }}.</p>
      <p v-if="summary.missing_sources" class="muted">
        У {{ summary.missing_sources }} изображений исходник отсутствует. Исторические PNG сохранятся точно;
        текущий рисунок из каталога вместо них не подставляется.
      </p>
      <label class="field"
        ><span class="label">Название копии</span
        ><input v-model="title" class="input" required maxlength="160"
      /></label>
    </div>
    <template #footer
      ><button class="btn btn-secondary" :disabled="busy" @click="emit('close')">Отмена</button
      ><button class="btn btn-primary" :disabled="busy || !summary || !title.trim()" @click="apply">
        Создать независимую копию
      </button></template
    ></Modal
  >
</template>
<style scoped>
.import-summary {
  display: grid;
  gap: 12px;
  margin-top: 20px;
}
</style>
