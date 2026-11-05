<script setup lang="ts">
import { ref, watch } from 'vue';
import { ListOrdered, Package, SlidersHorizontal } from '@lucide/vue';
import Modal from '@/shared/Modal.vue';
import { relationLabels, errorText, type Asana, type LibraryImageStyle } from '../types';
import { api } from '@/shared/api';
import AddToCollectionDialog from '../../collections/components/AddToCollectionDialog.vue';
const props = withDefaults(defineProps<{ card: Asana | null; imageStyle?: LibraryImageStyle }>(), {
  imageStyle: 'original',
});
const emit = defineEmits<{
  close: [];
  related: [card: Asana];
  created: [card: Asana];
  insert: [card: Asana];
}>();
const detail = ref<Asana | null>(null),
  error = ref(''),
  loading = ref(false),
  informationReady = ref(false),
  busy = ref(false),
  collectionOpen = ref(false);
let sequence = 0;
function detailUrl(id: string): string {
  return `/api/asanas/${id}`;
}
watch(
  () => [props.card, props.imageStyle] as const,
  async ([card]) => {
    const version = ++sequence;
    detail.value = card;
    informationReady.value = false;
    error.value = '';
    if (!card) return;
    loading.value = true;
    try {
      const value = (await api<{ data: Asana }>(detailUrl(card.id))).data;
      if (version === sequence) {
        detail.value = value;
        informationReady.value = true;
      }
    } catch (failure) {
      if (version === sequence) error.value = errorText(failure);
    } finally {
      if (version === sequence) loading.value = false;
    }
  },
  { immediate: true },
);
async function related(id: string) {
  const version = sequence;
  error.value = '';
  try {
    const value = (await api<{ data: Asana }>(detailUrl(id))).data;
    if (version === sequence) emit('related', value);
  } catch (failure) {
    if (version === sequence) error.value = errorText(failure);
  }
}
function insert() {
  if (!detail.value) return;
  emit('insert', detail.value);
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
      <p v-if="detail.alternate_name" class="alternate-name">{{ detail.alternate_name }}</p>
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
          Добавить в практику</button
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
      </div>
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
      <section v-if="informationReady" class="asana-information" aria-labelledby="asana-information-title">
        <h3 id="asana-information-title">Информация</h3>
        <div class="information-part">
          <h4>Описание</h4>
          <p v-if="detail.information_description" class="information-description">
            {{ detail.information_description }}
          </p>
          <p v-else class="information-empty muted">Пока не заполнено.</p>
        </div>
        <div class="information-part">
          <h4>Подсказки преподавателю</h4>
          <ul v-if="detail.teacher_cues?.length">
            <li v-for="(cue, index) in detail.teacher_cues" :key="index">{{ cue }}</li>
          </ul>
          <p v-else class="information-empty muted">Пока не заполнено.</p>
        </div>
        <div class="information-part">
          <h4>Польза</h4>
          <ul v-if="detail.benefits?.length">
            <li v-for="(benefit, index) in detail.benefits" :key="index">{{ benefit }}</li>
          </ul>
          <p v-else class="information-empty muted">Пока не заполнено.</p>
        </div>
        <div class="information-part">
          <h4>Предосторожности</h4>
          <ul v-if="detail.precautions?.length">
            <li v-for="(precaution, index) in detail.precautions" :key="index">{{ precaution }}</li>
          </ul>
          <p v-else class="information-empty muted">Пока не заполнено.</p>
        </div>
      </section>
      <details v-if="detail.steps?.length" class="disclosure">
        <summary>
          <span class="disclosure-icon"><ListOrdered aria-hidden="true" /></span>
          <span class="disclosure-copy"><strong>Шаги выполнения</strong></span>
        </summary>
        <div class="disclosure-body">
          <ol>
            <li v-for="(step, index) in detail.steps" :key="index">{{ step }}</li>
          </ol>
        </div>
      </details>
      <details
        v-if="detail.equipment?.length || (detail.has_bind !== null && detail.has_bind !== undefined)"
        class="disclosure"
      >
        <summary>
          <span class="disclosure-icon"><Package aria-hidden="true" /></span>
          <span class="disclosure-copy"><strong>Оборудование и положение рук</strong></span>
        </summary>
        <div class="disclosure-body">
          <p v-if="detail.equipment?.length">{{ detail.equipment.join(' · ') }}</p>
          <p v-if="detail.has_bind !== null && detail.has_bind !== undefined">
            Замок / связанное положение рук: {{ detail.has_bind ? 'да' : 'нет' }}
          </p>
        </div>
      </details>
      <details v-if="detail.adaptations?.length" class="disclosure">
        <summary>
          <span class="disclosure-icon"><SlidersHorizontal aria-hidden="true" /></span>
          <span class="disclosure-copy"><strong>Адаптации</strong></span>
        </summary>
        <div class="disclosure-body">
          <ul>
            <li v-for="(text, index) in detail.adaptations" :key="index">{{ text }}</li>
          </ul>
        </div>
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
  padding: var(--panel-padding);
  background: #fff;
  border: 1px solid var(--line);
  border-radius: var(--panel-radius);
}
.preview-categories,
.preview-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin: 16px 0;
}
.sanskrit {
  color: var(--muted);
  font-size: 0.9rem;
}
.alternate-name {
  margin: 14px 0 4px;
  font-size: 16px;
  font-weight: 550;
}
.asana-information {
  display: grid;
  gap: var(--section-gap);
  margin-top: var(--section-gap);
  padding-top: var(--section-gap);
  border-top: 1px solid var(--line);
}
.asana-information h3 {
  margin: 0;
  font-size: 19px;
  font-weight: 550;
  color: #29492f;
  letter-spacing: -0.025em;
}
.information-part {
  padding: 16px 18px;
  border: 1px solid var(--line);
  border-radius: var(--field-radius);
  background: linear-gradient(150deg, #fafcf7, #fff);
}
.information-part h4 {
  margin: 0 0 8px;
  font-size: 14px;
  font-weight: 600;
  color: #355739;
}
.information-part p,
.information-part ul {
  margin: 0;
  font-size: 14px;
  line-height: 1.65;
}
.information-part ul {
  padding-left: 22px;
}
.information-description {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.information-part .information-empty {
  font-size: 13px;
}
.related-options {
  display: grid;
  gap: 12px;
  margin-top: var(--section-gap);
}
details {
  margin-top: 12px;
}
li {
  margin: 8px 0;
  line-height: 1.5;
}
</style>
