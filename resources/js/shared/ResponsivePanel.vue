<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { SlidersHorizontal } from '@lucide/vue';
import Modal from './Modal.vue';

withDefaults(
  defineProps<{ title?: string; label?: string; active?: boolean; doneLabel?: string | false }>(),
  {
    title: 'Фильтры и вид',
    label: 'Фильтры',
    active: false,
    doneLabel: 'Готово',
  },
);
const mobile = ref(false);
const open = ref(false);
let media: MediaQueryList | undefined;
function resize() {
  mobile.value = media?.matches ?? false;
  if (!mobile.value) open.value = false;
}
onMounted(() => {
  media = window.matchMedia('(max-width: 760px)');
  resize();
  media.addEventListener('change', resize);
});
onBeforeUnmount(() => media?.removeEventListener('change', resize));
</script>

<template>
  <div class="responsive-panel">
    <template v-if="mobile">
      <button
        type="button"
        class="btn btn-secondary responsive-panel-trigger"
        :class="{ 'has-filters': active }"
        :aria-label="title"
        aria-haspopup="dialog"
        :aria-expanded="open"
        @click="open = true"
      >
        <SlidersHorizontal :size="19" /><span>{{ label }}</span>
        <span v-if="active" class="filter-dot" aria-label="Есть выбранные фильтры" />
      </button>
      <Modal :open="open" :title="title" @close="open = false">
        <div class="responsive-panel-fields"><slot /></div>
        <template v-if="doneLabel" #footer>
          <button type="button" class="btn btn-primary" @click="open = false">{{ doneLabel }}</button>
        </template>
      </Modal>
    </template>
    <slot v-else />
  </div>
</template>

<style scoped>
.responsive-panel {
  display: contents;
}
.responsive-panel-fields {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 20px;
}
.responsive-panel-fields :deep(.select),
.responsive-panel-fields :deep(.btn) {
  width: 100%;
  max-width: none;
}
.responsive-panel-fields :deep(label) {
  font-size: 14px;
}
.responsive-panel-fields :deep(.view-switch) {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}
.responsive-panel-fields :deep(.sort-controls) {
  display: grid;
  width: 100%;
  gap: 12px;
}
.responsive-panel-fields :deep(.practice-sort) {
  min-width: 0;
}
.has-filters {
  border-color: var(--accent);
  color: var(--accent-dark);
}
.filter-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--accent);
}
@media screen and (max-width: 760px) {
  .responsive-panel-trigger {
    flex-shrink: 0;
    padding-inline: 14px;
  }
}
</style>
