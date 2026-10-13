<script setup lang="ts">
import Modal from '@/shared/Modal.vue';
import { relationLabels, type Asana } from '../types';
import { api } from '@/shared/api';
import { ref } from 'vue';
const error = ref('');
defineProps<{ card: Asana | null }>();
const emit = defineEmits<{ close: []; related: [card: Asana] }>();
async function related(id: string) {
  error.value = '';
  try {
    emit('related', (await api<{ data: Asana }>(`/api/asanas/${id}`)).data);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось открыть вариант.';
  }
}
</script>
<template>
  <Modal :open="Boolean(card)" :title="card?.name || 'Карточка асаны'" @close="$emit('close')">
    <template v-if="card">
      <img class="preview-image" :src="card.image_url" :alt="card.name" />
      <p v-if="card.aliases?.length" class="muted">{{ card.aliases.join(' · ') }}</p>
      <div v-if="card.categories.length" class="preview-categories">
        <span v-for="category in card.categories" :key="category.id" class="badge">{{ category.name }}</span>
      </div>
      <section v-if="card.relations?.length" class="related-options">
        <h3>Связанные варианты</h3>
        <button
          v-for="item in card.relations"
          :key="item.asana_id + item.kind"
          type="button"
          class="btn btn-secondary"
          @click="related(item.asana_id)"
        >
          {{ relationLabels[item.kind] }}: {{ item.name }}{{ item.archived_at ? ' (в архиве)' : '' }}
        </button>
      </section>
      <p v-if="error" class="error-banner">{{ error }}</p>
      <p v-if="card.archived_at" class="muted">
        Карточка в архиве. Ранее сохранённые практики по-прежнему содержат её изображение.
      </p>
    </template>
  </Modal>
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
.preview-categories {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  margin-top: 16px;
}
.related-options {
  display: grid;
  gap: 8px;
  margin-top: 20px;
}
.related-options h3 {
  font-weight: 650;
}
</style>
