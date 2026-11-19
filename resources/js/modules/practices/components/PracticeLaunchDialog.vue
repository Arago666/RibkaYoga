<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Clock3, Presentation } from '@lucide/vue';
import Modal from '@/shared/Modal.vue';

defineProps<{ practice: { id: string; title: string } | null }>();
const emit = defineEmits<{ close: [] }>();
const modes = [
  {
    value: 'practice',
    title: 'Практиковать',
    description: 'Позы сменяются по заданному времени.',
    icon: Clock3,
  },
  {
    value: 'teach',
    title: 'Вести занятие',
    description: 'Переключайте позы вручную и смотрите подсказки.',
    icon: Presentation,
  },
] as const;
</script>

<template>
  <Modal :open="!!practice" title="Начать занятие" @close="emit('close')">
    <p v-if="practice" class="launch-title">{{ practice.title }}</p>
    <div v-if="practice" class="launch-modes">
      <Link
        v-for="mode in modes"
        :key="mode.value"
        :href="`/practices/${practice.id}/run?mode=${mode.value}&from=owned`"
        class="launch-option"
        @click="emit('close')"
      >
        <component :is="mode.icon" :size="22" aria-hidden="true" />
        <span
          ><strong>{{ mode.title }}</strong
          ><small>{{ mode.description }}</small></span
        >
        <ArrowRight :size="18" aria-hidden="true" />
      </Link>
    </div>
  </Modal>
</template>

<style scoped>
.launch-title {
  margin: 0 0 18px;
  color: var(--muted);
  overflow-wrap: anywhere;
}
.launch-modes {
  display: grid;
  gap: 10px;
}
.launch-option {
  display: grid;
  grid-template-columns: 24px minmax(0, 1fr) 18px;
  align-items: center;
  gap: 12px;
  padding: 14px;
  color: var(--text);
  text-decoration: none;
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 16px;
}
.launch-option:hover,
.launch-option:focus-visible {
  background: var(--accent-soft);
  border-color: var(--accent);
}
.launch-option svg {
  color: var(--accent-dark);
}
.launch-option small {
  display: block;
  margin-top: 4px;
  color: var(--muted);
}
</style>
