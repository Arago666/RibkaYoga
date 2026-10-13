<script setup lang="ts">
import { Check, Expand, Pencil, Archive, RotateCcw } from '@lucide/vue';
import type { Asana } from '../types';
import CategoryMarkers from './CategoryMarkers.vue';
withDefaults(defineProps<{ card: Asana; selected?: boolean; selectable?: boolean; actions?: boolean }>(), {
  selected: false,
  selectable: true,
  actions: true,
});
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
    <CategoryMarkers
      :categories="card.categories"
      style="position: absolute; right: 12px; top: 15px; z-index: 1"
    />
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
      <img :src="card.thumbnail_url || card.image_url" :alt="card.name" loading="lazy" />
      <span class="enlarge"><Expand :size="15" aria-hidden="true" /></span>
    </button>
    <div class="asana-info">
      <h3 :title="card.name">{{ card.name }}</h3>
      <span v-if="card.archived_at" class="badge">В архиве</span>
      <p v-else class="asana-category">
        {{ card.categories?.map((item) => item.name).join(' · ') || 'Без категории' }}
      </p>
      <div v-if="actions" class="asana-actions">
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
