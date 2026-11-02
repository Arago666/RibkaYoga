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
        >Представление<select class="select" v-model="style" :disabled="busy">
          <option value="original">Исходные рисунки</option>
          <option value="line">Линейная фигура</option>
          <option value="volume">Объёмная фигура</option>
        </select></label
      ><label
        >Персонаж<select class="select" v-model="character" :disabled="busy || style === 'original'">
          <option value="neutral">Нейтральный</option>
          <option value="female">Женщина</option>
          <option value="male">Мужчина</option>
        </select></label
      >
    </div>
    <p v-if="error" role="alert" class="error-banner">{{ error }}</p>
    <button class="btn btn-secondary" :disabled="busy" @click="prepare">
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
      ><button class="btn btn-secondary" :disabled="busy" @click="emit('close')">Закрыть</button
      ><button class="btn btn-primary" :disabled="busy || !preview || !selected.length" @click="apply">
        Применить выбранные замены
      </button></template
    ></Modal
  >
</template>
<style scoped>
.style-fields {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20px;
  margin: var(--section-gap) 0;
}
.style-fields label {
  display: grid;
  gap: 8px;
  min-width: 0;
  font-size: 14px;
  font-weight: 600;
}
.style-changes {
  display: grid;
  gap: 16px;
  margin-top: var(--section-gap);
}
.style-changes > label {
  display: grid;
  gap: 16px;
  min-width: 0;
  padding: var(--panel-padding);
  border: 1px solid var(--line);
  border-radius: var(--panel-radius);
  background: var(--surface);
  box-shadow: var(--panel-shadow);
}
.style-changes > label > span:first-child {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: var(--control-height);
  overflow-wrap: anywhere;
}
.style-pair {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
}
.style-pair img {
  width: 42%;
  min-width: 0;
  max-width: 220px;
  aspect-ratio: 1;
  object-fit: contain;
}
.style-changes input {
  accent-color: var(--accent-dark);
  flex: none;
}
details {
  margin-top: var(--section-gap);
}
summary {
  cursor: pointer;
  min-height: var(--control-height);
  padding: 12px 0;
  font-size: 14px;
  font-weight: 600;
}
li {
  margin: 8px 0;
  line-height: 1.6;
}
@media (max-width: 500px) {
  .style-fields {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
