<script setup lang="ts">
import { Check, Expand, Pencil, Archive, RotateCcw } from '@lucide/vue';
import type { Asana, AsanaDisplayImage } from '../types';
import { api } from '@/shared/api';
import { ref } from 'vue';
const favoriteBusy = ref(false),
  favoriteError = ref('');
async function favorite(card: Asana) {
  if (favoriteBusy.value) return;
  favoriteBusy.value = true;
  favoriteError.value = '';
  try {
    await api(`/api/asanas/${card.id}/favorite`, {
      method: 'PUT',
      body: JSON.stringify({ favorite: !card.favorite }),
    });
    card.favorite = !card.favorite;
  } catch (error) {
    favoriteError.value = error instanceof Error ? error.message : 'Не удалось изменить избранное.';
  } finally {
    favoriteBusy.value = false;
  }
}
withDefaults(
  defineProps<{
    card: Asana;
    displayImage?: AsanaDisplayImage;
    selected?: boolean;
    selectable?: boolean;
    actions?: boolean;
  }>(),
  {
    selected: false,
    selectable: true,
    actions: true,
  },
);
defineEmits<{
  select: [card: Asana];
  preview: [card: Asana];
  edit: [card: Asana];
  archive: [card: Asana];
  restore: [card: Asana];
}>();
</script>

<template>
  <article class="asana-card" :class="{ selected, archived: card.archived_at }">
    <button
      v-if="selectable"
      class="selection-toggle"
      :class="{ checked: selected }"
      type="button"
      :aria-label="`${selected ? 'Снять выбор' : 'Выбрать'}: ${card.name}`"
      :aria-pressed="selected"
      @click="$emit('select', card)"
    >
      <Check v-if="selected" :size="16" aria-hidden="true" />
    </button>
    <button
      class="asana-image"
      type="button"
      :aria-label="`Рассмотреть: ${card.name}`"
      @click="$emit('preview', card)"
    >
      <img
        :src="displayImage?.thumbnail_url || displayImage?.image_url || card.thumbnail_url || card.image_url"
        :alt="card.name"
        loading="lazy"
      />
      <span class="enlarge"><Expand :size="15" aria-hidden="true" /></span>
    </button>
    <div class="asana-info">
      <h3 :title="card.name">{{ card.name }}</h3>
      <p v-if="displayImage && !displayImage.available" class="style-fallback">Нет объёмного варианта</p>
      <p v-if="card.sanskrit_cyrillic || card.sanskrit_latin" class="asana-category">
        {{ card.sanskrit_cyrillic || card.sanskrit_latin }}
      </p>
      <span v-if="card.facet_values?.some((v) => v.dimension === 'difficulty')" class="badge">{{
        card.facet_values.find((v) => v.dimension === 'difficulty')?.name
      }}</span>
      <span v-if="card.archived_at" class="badge">В архиве</span>
      <p v-else class="asana-category">
        {{ card.categories?.map((item) => item.name).join(' · ') || 'Без категории' }}
      </p>
      <p v-if="favoriteError" class="error-banner" role="alert">{{ favoriteError }}</p>
      <div v-if="actions" class="asana-actions">
        <button
          type="button"
          class="icon-btn"
          :disabled="favoriteBusy"
          :aria-pressed="!!card.favorite"
          :aria-label="card.favorite ? 'Убрать из избранного' : 'В избранное'"
          @click="favorite(card)"
        >
          {{ card.favorite ? '★' : '☆' }}
        </button>
        <button type="button" class="btn btn-ghost" @click="$emit('edit', card)">
          <Pencil :size="14" aria-hidden="true" /> Изменить
        </button>
        <button
          v-if="card.archived_at"
          class="icon-btn"
          type="button"
          :aria-label="`Восстановить: ${card.name}`"
          title="Восстановить"
          @click="$emit('restore', card)"
        >
          <RotateCcw :size="17" />
        </button>
        <button
          v-else
          class="icon-btn"
          type="button"
          :aria-label="`В архив: ${card.name}`"
          title="В архив"
          @click="$emit('archive', card)"
        >
          <Archive :size="17" />
        </button>
      </div>
    </div>
  </article>
</template>

<style scoped>
.asana-card {
  position: relative;
  overflow: hidden;
  border: 1px solid #e2e8df;
  border-radius: 18px;
  background: #fff;
  transition:
    border-color 0.15s,
    box-shadow 0.15s;
}
.asana-card.selected {
  border-color: #397a28;
  box-shadow: 0 0 0 2px #397a2820;
}
.selection-toggle {
  position: absolute;
  top: 12px;
  left: 12px;
  z-index: 1;
  display: grid;
  place-items: center;
  width: 27px;
  height: 27px;
  padding: 0;
  border: 2px solid #d9e2d5;
  border-radius: 9px;
  background: #fff;
  color: #fff;
  cursor: pointer;
}
.selection-toggle.checked {
  background: #397a28;
  border-color: #397a28;
}
.asana-image {
  position: relative;
  display: block;
  width: 100%;
  height: 158px;
  border: 0;
  background: #fbfcfa;
  padding: 24px;
  cursor: zoom-in;
}
.asana-image img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
.enlarge {
  position: absolute;
  bottom: 9px;
  right: 10px;
  display: grid;
  place-items: center;
  width: 27px;
  height: 27px;
  border-radius: 8px;
  background: #fff;
  color: #687568;
  opacity: 0;
}
.asana-image:hover .enlarge,
.asana-image:focus-visible .enlarge {
  opacity: 1;
}
.asana-info {
  padding: 15px;
}
.asana-info h3 {
  font-size: 0.93rem;
  font-weight: 650;
  line-height: 1.4;
  margin: 0 0 6px;
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  min-height: 2.6em;
}
.asana-category {
  font-size: 0.76rem;
  color: #687568;
  white-space: nowrap;
  text-overflow: ellipsis;
  overflow: hidden;
  margin: 0;
  min-height: 1.2em;
}
.style-fallback {
  margin: 0 0 7px;
  font-size: 0.73rem;
  line-height: 1.4;
  color: #687568;
}
.asana-actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin: 12px -5px -5px;
  gap: 4px;
}
.asana-actions .btn {
  font-size: 0.75rem;
  padding: 7px;
  min-height: 34px;
}
.asana-actions .icon-btn {
  width: 32px;
  height: 32px;
}
.archived .asana-image {
  opacity: 0.65;
}
button:focus-visible {
  outline: 3px solid #397a2880;
  outline-offset: 2px;
}
</style>
