<script setup lang="ts">
import { ref, watch } from 'vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import type { Practice } from '../types';
import type { Character, IllustrationStyle } from '@/modules/catalog/illustrations/mannequin';
const props = defineProps<{ open: boolean; documentId: string; revision: number }>();
const emit = defineEmits<{ close: []; updated: [Practice] }>();
interface Preview {
  token: string;
  revision: number;
  changes: { snapshot_id: string; name: string; before_image_url: string; after_image_url: string }[];
  skipped: { snapshot_id: string; name: string; reason: string }[];
}
const style = ref<IllustrationStyle>('volume'),
  character = ref<Character>('neutral'),
  preview = ref<Preview | null>(null),
  selected = ref<string[]>([]),
  busy = ref(false),
  error = ref('');
watch(
  () => [props.open, props.documentId, props.revision],
  () => {
    preview.value = null;
    selected.value = [];
    error.value = '';
  },
);
watch(
  () => [style.value, character.value],
  () => {
    preview.value = null;
    selected.value = [];
  },
);
async function prepare(): Promise<void> {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    preview.value = (
      await api<{ data: Preview }>(`/api/practices/${props.documentId}/illustration-preview`, {
        method: 'POST',
        body: JSON.stringify({
          expected_revision: props.revision,
          style: style.value,
          character: character.value,
        }),
      })
    ).data;
    selected.value = preview.value.changes.map((change) => change.snapshot_id);
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'Не удалось подготовить предпросмотр.';
  } finally {
    busy.value = false;
  }
}
async function apply(): Promise<void> {
  if (!preview.value || !selected.value.length || busy.value) return;
  if (
    !window.confirm(
      `Заменить выбранные изображения (${selected.value.length})? Старые снимки сохранятся, действие можно отменить в редакторе.`,
    )
  )
    return;
  busy.value = true;
  error.value = '';
  try {
    const result = await api<{ data: Practice }>(`/api/practices/${props.documentId}/illustration-apply`, {
      method: 'POST',
      body: JSON.stringify({
        expected_revision: preview.value.revision,
        token: preview.value.token,
        snapshot_ids: selected.value,
      }),
    });
    emit('updated', result.data);
    emit('close');
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'Не удалось применить изменения.';
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <Modal :open="open" title="Изменить представления на сохранённом листе" @close="!busy && emit('close')"
    ><p>
      Сначала сохраните текущие изменения. Заменятся только проверенные точные версии поз. Исторические и
      неизвестные изображения останутся прежними; названия, структура, стрелки, скобки и параметры выполнения
      сохранятся.
    </p>
    <div class="style-fields">
      <label
        >Представление<select v-model="style" :disabled="busy">
          <option value="original">Исходные рисунки</option>
          <option value="line">Линейная фигура</option>
          <option value="volume">Объёмная фигура</option>
        </select></label
      ><label
        >Персонаж<select v-model="character" :disabled="busy || style === 'original'">
          <option value="neutral">Нейтральный</option>
          <option value="female">Женщина</option>
          <option value="male">Мужчина</option>
        </select></label
      >
    </div>
    <p v-if="error" role="alert" class="error-banner">{{ error }}</p>
    <button class="btn" :disabled="busy" @click="prepare">
      {{ busy ? 'Подготавливаем…' : 'Показать изменения' }}</button
    ><template v-if="preview"
      ><p>
        {{ preview.changes.length }} разных изображений можно заменить. Одна выбранная замена действует на все
        использования этого снимка в листе.
      </p>
      <p v-if="!preview.changes.length">Подходящих изменений нет. Лист сохранён без подмены изображений.</p>
      <div class="style-changes">
        <label v-for="change in preview.changes" :key="change.snapshot_id"
          ><span
            ><input v-model="selected" type="checkbox" :value="change.snapshot_id" :disabled="busy" />
            {{ change.name }}</span
          ><span class="style-pair"
            ><img :src="change.before_image_url" alt="До замены" /><span aria-hidden="true">→</span
            ><img :src="change.after_image_url" alt="После замены" /></span
        ></label>
      </div>
      <details v-if="preview.skipped.length">
        <summary>Без изменений: {{ preview.skipped.length }}</summary>
        <ul>
          <li v-for="item in preview.skipped" :key="item.snapshot_id">{{ item.name }} — {{ item.reason }}</li>
        </ul>
      </details></template
    ><template #footer
      ><button class="btn" :disabled="busy" @click="emit('close')">Закрыть</button
      ><button class="btn btn-primary" :disabled="busy || !preview || !selected.length" @click="apply">
        Применить выбранные замены
      </button></template
    ></Modal
  >
</template>
<style scoped>
.style-fields {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  margin: 1rem 0;
}
.style-fields label {
  display: grid;
  gap: 0.3rem;
  flex: 1;
  min-width: 180px;
}
.style-fields select {
  min-height: 44px;
  padding: 0.5rem;
  border: 1px solid #c5d0c0;
  border-radius: 0.5rem;
  background: white;
}
.style-changes {
  display: grid;
  gap: 1rem;
}
.style-changes > label {
  padding: 0.7rem;
  border: 1px solid #dae3d7;
  border-radius: 0.6rem;
}
.style-pair {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
}
.style-pair img {
  width: 42%;
  max-width: 220px;
  aspect-ratio: 1;
  object-fit: contain;
}
.style-changes input {
  accent-color: #397a28;
}
details {
  margin-top: 1rem;
}
summary {
  cursor: pointer;
  padding: 0.6rem;
}
li {
  margin: 0.4rem 0;
}
</style>
