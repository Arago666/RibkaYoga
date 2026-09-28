<script setup lang="ts">
import Modal from '@/shared/Modal.vue';
import type { Asana } from '../types';
defineProps<{ card: Asana | null }>();
defineEmits<{ close: [] }>();
</script>
<template>
  <Modal :open="Boolean(card)" :title="card?.name || 'Карточка асаны'" @close="$emit('close')">
    <template v-if="card">
      <img class="preview-image" :src="card.image_url" :alt="card.name" />
      <p v-if="card.aliases?.length" class="muted">{{ card.aliases.join(' · ') }}</p>
      <div v-if="card.categories.length" class="preview-categories">
        <span v-for="category in card.categories" :key="category.id" class="badge">{{ category.name }}</span>
      </div>
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
</style>
