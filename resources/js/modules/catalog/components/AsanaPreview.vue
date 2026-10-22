<script setup lang="ts">
import { ref, watch } from 'vue';
import Modal from '@/shared/Modal.vue';
import { relationLabels, errorText, type Asana } from '../types';
import { api } from '@/shared/api';
import AddToCollectionDialog from '../../collections/components/AddToCollectionDialog.vue';
import IllustrationSettings, { type PreparedIllustration } from './IllustrationSettings.vue';
const props = defineProps<{ card: Asana | null }>();
const emit = defineEmits<{
  close: [];
  related: [card: Asana];
  created: [card: Asana];
  insert: [card: Asana];
}>();
const detail = ref<Asana | null>(null),
  error = ref(''),
  loading = ref(false),
  busy = ref(false),
  collectionOpen = ref(false);
const styleOpen = ref(false),
  preparedStyle = ref<PreparedIllustration | null>(null);
let sequence = 0;
watch(
  () => props.card,
  async (card) => {
    const version = ++sequence;
    detail.value = card;
    styleOpen.value = false;
    preparedStyle.value = null;
    error.value = '';
    if (!card) return;
    loading.value = true;
    try {
      const value = (await api<{ data: Asana }>(`/api/asanas/${card.id}`)).data;
      if (version === sequence) detail.value = value;
    } catch (failure) {
      if (version === sequence) error.value = errorText(failure);
    } finally {
      if (version === sequence) loading.value = false;
    }
  },
  { immediate: true },
);
async function related(id: string) {
  error.value = '';
  try {
    emit('related', (await api<{ data: Asana }>(`/api/asanas/${id}`)).data);
  } catch (failure) {
    error.value = errorText(failure);
  }
}
function insert() {
  if (!detail.value) return;
  emit(
    'insert',
    preparedStyle.value
      ? { ...detail.value, illustration_variant_id: preparedStyle.value.variant_id ?? null }
      : detail.value,
  );
}
async function favorite() {
  const card = detail.value;
  if (!card || busy.value) return;
  busy.value = true;
  try {
    await api(`/api/asanas/${card.id}/favorite`, {
      method: 'PUT',
      body: JSON.stringify({ favorite: !card.favorite }),
    });
    card.favorite = !card.favorite;
  } catch (failure) {
    error.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}
async function duplicate() {
  const card = detail.value;
  if (!card || busy.value) return;
  const name = window.prompt('Название отдельного варианта', `${card.name} — мой вариант`.slice(0, 160));
  if (!name?.trim()) return;
  busy.value = true;
  error.value = '';
  try {
    const result = await api<{ data: Asana }>(`/api/asanas/${card.id}/duplicate`, {
      method: 'POST',
      body: JSON.stringify({ name: name.trim(), expected_revision: card.revision }),
    });
    emit('created', result.data);
    emit('close');
  } catch (failure) {
    error.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <Modal :open="Boolean(card)" :title="detail?.name || 'Карточка асаны'" @close="!busy && emit('close')">
    <template v-if="detail">
      <img class="preview-image" :src="detail.image_url" :alt="detail.name" />
      <p v-if="detail.sanskrit_cyrillic || detail.sanskrit_latin" class="sanskrit">
        {{ [detail.sanskrit_cyrillic, detail.sanskrit_latin].filter(Boolean).join(' · ') }}
      </p>
      <p v-if="detail.aliases?.length" class="muted">{{ detail.aliases.join(' · ') }}</p>
      <div class="preview-actions">
        <button
          type="button"
          class="btn btn-secondary"
          :aria-pressed="!!detail.favorite"
          :disabled="busy"
          @click="favorite"
        >
          {{ detail.favorite ? '★ В избранном' : '☆ В избранное' }}</button
        ><button v-if="!detail.archived_at" type="button" class="btn btn-primary" @click="insert">
          {{ preparedStyle ? 'Добавить выбранное представление в практику' : 'Добавить в практику' }}</button
        ><button
          v-if="!detail.archived_at"
          type="button"
          class="btn btn-secondary"
          @click="collectionOpen = true"
        >
          Добавить в подборку</button
        ><button type="button" class="btn btn-secondary" :disabled="busy || loading" @click="duplicate">
          Создать свой вариант
        </button>
        <button
          v-if="!detail.archived_at"
          type="button"
          class="btn btn-secondary"
          :aria-expanded="styleOpen"
          @click="styleOpen = !styleOpen"
        >
          Стиль и персонаж
        </button>
      </div>
      <IllustrationSettings
        v-if="styleOpen"
        :asana-id="detail.id"
        :source-asset-id="detail.image_asset_id"
        :image-url="detail.image_url"
        compact
        @select="preparedStyle = $event"
        @reset="preparedStyle = null"
      />
      <p v-if="loading" class="muted" role="status">Загружаем справку…</p>
      <div v-if="detail.categories.length || detail.facet_values?.length" class="preview-categories">
        <span
          v-for="category in [...(detail.facet_values || []), ...detail.categories]"
          :key="category.id"
          class="badge"
          >{{ category.name }}</span
        >
      </div>
      <p v-if="detail.classification_status !== 'reviewed'" class="muted">
        Разметка: {{ detail.classification_status === 'partial' ? 'частичная' : 'требует проверки' }}
      </p>
      <details v-if="detail.steps?.length">
        <summary>Шаги выполнения</summary>
        <ol>
          <li v-for="(step, index) in detail.steps" :key="index">{{ step }}</li>
        </ol>
      </details>
      <details v-if="detail.teacher_cues?.length">
        <summary>Подсказки преподавателю</summary>
        <ul>
          <li v-for="(cue, index) in detail.teacher_cues" :key="index">{{ cue }}</li>
        </ul>
      </details>
      <details v-if="detail.equipment?.length || (detail.has_bind !== null && detail.has_bind !== undefined)">
        <summary>Оборудование и положение рук</summary>
        <p v-if="detail.equipment?.length">{{ detail.equipment.join(' · ') }}</p>
        <p v-if="detail.has_bind !== null && detail.has_bind !== undefined">
          Замок / связанное положение рук: {{ detail.has_bind ? 'да' : 'нет' }}
        </p>
      </details>
      <details v-if="detail.precautions?.length || detail.adaptations?.length">
        <summary>Предосторожности и адаптации</summary>
        <h4 v-if="detail.precautions?.length">Предосторожности</h4>
        <ul>
          <li v-for="(text, index) in detail.precautions" :key="index">{{ text }}</li>
        </ul>
        <h4 v-if="detail.adaptations?.length">Адаптации</h4>
        <ul>
          <li v-for="(text, index) in detail.adaptations" :key="index">{{ text }}</li>
        </ul>
      </details>
      <section v-if="detail.relations?.length" class="related-options">
        <h3>Связанные варианты</h3>
        <button
          v-for="item in detail.relations"
          :key="item.asana_id + item.kind"
          type="button"
          class="btn btn-secondary"
          @click="related(item.asana_id)"
        >
          {{ relationLabels[item.kind] }}: {{ item.name }}{{ item.archived_at ? ' (в архиве)' : '' }}
        </button>
      </section>
      <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
      <p v-if="detail.archived_at" class="muted">
        Карточка в архиве. Ранее сохранённые практики содержат прежнее изображение.
      </p>
    </template>
  </Modal>
  <AddToCollectionDialog
    :open="collectionOpen"
    :asana-ids="detail ? [detail.id] : []"
    @close="collectionOpen = false"
    @saved="collectionOpen = false"
  />
</template>
<style scoped>
.preview-image {
  display: block;
  width: 100%;
  height: min(45vh, 360px);
  object-fit: contain;
  padding: 20px;
  background: #f8faf6;
  border-radius: 16px;
}
.preview-categories,
.preview-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin: 16px 0;
}
.sanskrit {
  color: #596650;
  font-size: 0.9rem;
}
.related-options {
  display: grid;
  gap: 8px;
  margin-top: 20px;
}
details {
  border: 1px solid #e4eadf;
  border-radius: 12px;
  padding: 12px;
  margin-top: 12px;
}
summary {
  font-weight: 600;
  cursor: pointer;
}
li {
  margin: 8px 0;
  line-height: 1.5;
}
</style>
