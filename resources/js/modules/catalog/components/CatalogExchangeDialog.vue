<script setup lang="ts">
import { ref } from 'vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
const props = defineProps<{ open: boolean; selectedIds: string[] }>();
const emit = defineEmits<{ close: []; imported: [] }>();
const busy = ref(false),
  error = ref(''),
  success = ref(''),
  input = ref<HTMLInputElement | null>(null);
async function exportJob() {
  busy.value = true;
  error.value = '';
  try {
    const result = await api('/api/catalog-json/export', {
      method: 'POST',
      body: JSON.stringify({ asana_ids: props.selectedIds }),
    });
    const url = URL.createObjectURL(
      new Blob([JSON.stringify(result, null, 2)], { type: 'application/json' }),
    );
    const link = document.createElement('a');
    link.href = url;
    link.download = 'ribka-catalog-job.json';
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 30000);
    success.value = 'Задание сохранено вместе с исходными изображениями и данными карточек.';
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось экспортировать.';
  } finally {
    busy.value = false;
  }
}
async function importResult(event: Event) {
  const field = event.target as HTMLInputElement,
    file = field.files?.[0];
  field.value = '';
  if (!file) return;
  busy.value = true;
  error.value = '';
  success.value = '';
  try {
    if (file.size > 48 * 1024 * 1024) throw new Error('JSON превышает 48 МБ.');
    const payload = JSON.parse(await file.text());
    if (
      !confirm(
        'Обновить изображения указанных карточек в библиотеке? Готовые практики останутся прежними до отдельного обновления.',
      )
    )
      return;
    const result = await api<{ message: string }>('/api/catalog-json/import', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    success.value = result.message;
    emit('imported');
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось импортировать.';
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <Modal :open="open" title="Обмен изображениями в JSON" @close="!busy && emit('close')">
    <p>
      Экспорт включает названия, категории, связи вариантов, версии карточек и исходные картинки.
      {{
        selectedIds.length
          ? `Выбрано карточек: ${selectedIds.length}.`
          : 'Будут выгружены первые 100 активных карточек. Для точного набора выделите их в библиотеке.'
      }}
    </p>
    <div class="exchange-actions">
      <button class="btn btn-primary" :disabled="busy" @click="exportJob">Выгрузить задание JSON</button
      ><button class="btn btn-secondary" :disabled="busy" @click="input?.click()">
        Загрузить результат JSON
      </button>
    </div>
    <input ref="input" type="file" accept=".json,application/json" hidden @change="importResult" />
    <p class="muted">
      Результат содержит PNG и results.json. Для импорта через сайт картинки включаются в поле image_data. Для
      файлов рядом с JSON используйте команду:
    </p>
    <code>php artisan ribka:catalog-json import results.json --user=ваш_логин</code>
    <p class="muted">Если карточка изменилась после экспорта, импорт остановится и попросит новое задание.</p>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <p v-if="success" class="success-banner" role="status">{{ success }}</p>
  </Modal>
</template>
<style scoped>
p {
  line-height: 1.6;
  margin-bottom: 16px;
}
.exchange-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin: 20px 0;
}
code {
  display: block;
  overflow-wrap: anywhere;
  background: #f5f8f3;
  padding: 13px;
  border-radius: 10px;
  font-size: 12px;
}
</style>
