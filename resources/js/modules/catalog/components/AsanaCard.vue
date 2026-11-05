<script setup lang="ts">
import { Check, Expand } from '@lucide/vue';
import ActionMenu from '@/shared/ActionMenu.vue';
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
  category: [card: Asana];
  duplicate: [card: Asana];
  collection: [card: Asana];
}>();
</script>

<template>
  <article class="asana-card" :class="{ selected, archived: card.archived_at }">
    <div v-if="actions" class="card-menu">
      <ActionMenu :label="`Действия: ${card.name}`">
        <template v-if="!card.archived_at">
          <button @click="$emit('category', card)">Добавить в категорию</button>
          <button @click="$emit('collection', card)">Добавить в подборку</button>
          <button @click="$emit('edit', card)">Редактировать</button>
          <button @click="$emit('duplicate', card)">Дублировать</button>
          <button :disabled="favoriteBusy" @click="favorite(card)">
            {{ card.favorite ? 'Убрать из избранного' : 'В избранное' }}
          </button>
          <button v-if="selectable" @click="$emit('select', card)">
            {{ selected ? 'Снять выбор' : 'Выбрать для нескольких действий' }}
          </button>
          <button @click="$emit('archive', card)">Удалить в архив</button>
        </template>
        <button v-else @click="$emit('restore', card)">Восстановить</button>
      </ActionMenu>
    </div>
    <button
      v-if="selectable && !actions"
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
      <p v-if="card.alternate_name" class="asana-category" :title="card.alternate_name">
        {{ card.alternate_name }}
      </p>
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
    </div>
  </article>
</template>

<style scoped>
.asana-card {
  position: relative;
  overflow: visible;
  border: 1px solid #dfe6d8;
  border-radius: var(--panel-radius);
  background: #fff;
  transition:
    border-color 0.15s,
    box-shadow 0.15s;
  box-shadow: 0 3px 16px rgb(39 61 29 / 3%);
}
.asana-card.selected {
  border-color: var(--accent);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--accent) 13%, transparent);
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
  border: 2px solid var(--line);
  border-radius: 9px;
  background: #fff;
  color: #fff;
  cursor: pointer;
}
.selection-toggle.checked {
  color: #fff;
  background: var(--accent);
  border-color: var(--accent);
}
.card-menu {
  position: absolute;
  top: 7px;
  right: 7px;
  z-index: 3;
}
.asana-image {
  border-radius: var(--panel-radius) var(--panel-radius) 0 0;
  position: relative;
  display: block;
  width: 100%;
  height: 200px;
  border: 0;
  background: #fff;
  padding: var(--panel-padding);
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
  width: 32px;
  height: 32px;
  border-radius: 999px;
  background: #fff;
  color: var(--muted);
  opacity: 0;
}
.asana-image:hover .enlarge,
.asana-image:focus-visible .enlarge {
  opacity: 1;
}
.asana-info {
  padding: var(--panel-padding);
  padding-top: 16px;
  border-top: 1px solid #eef1e8;
  border-radius: 0 0 var(--panel-radius) var(--panel-radius);
  background: linear-gradient(180deg, #fbfcf8, #fff);
}
.asana-info h3 {
  font-size: 19px;
  font-weight: 550;
  color: #263e2d;
  line-height: 1.35;
  margin: 0 0 6px;
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  min-height: 2.7em;
  letter-spacing: -0.025em;
}
.asana-category {
  font-size: 13px;
  color: var(--muted);
  white-space: nowrap;
  text-overflow: ellipsis;
  overflow: hidden;
  margin: 0;
  min-height: 1.2em;
  line-height: 1.5;
}
.style-fallback {
  margin: 0 0 7px;
  font-size: 0.73rem;
  line-height: 1.4;
  color: var(--muted);
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
.archived .card-menu {
  position: absolute;
  top: 7px;
  right: 7px;
  z-index: 3;
}
.archived .asana-image {
  opacity: 0.65;
}
button:focus-visible {
  outline: 3px solid color-mix(in srgb, var(--accent) 50%, transparent);
  outline-offset: 2px;
}
@media (max-width: 700px) {
  .asana-image {
    height: 164px;
  }
  .asana-info h3 {
    font-size: 18px;
  }
}
@media (hover: hover) and (pointer: fine) {
  .asana-card:not(.selected):hover {
    border-color: #9bb38a;
    box-shadow: 0 7px 23px rgb(39 61 29 / 6%);
  }
}
@media print {
  .asana-card,
  .asana-card.selected,
  .asana-info {
    background: #fff;
    box-shadow: none;
  }
}
</style>
