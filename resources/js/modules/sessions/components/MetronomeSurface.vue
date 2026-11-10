<script setup lang="ts">
import Modal from '@/shared/Modal.vue';
defineProps<{ open: boolean; standalone?: boolean }>();
defineEmits<{ close: [] }>();
</script>

<template>
  <section v-if="standalone" aria-label="Управление метрономом"><slot /></section>
  <Modal v-else :open="open" title="Метроном" @close="$emit('close')">
    <slot />
    <template #footer><slot name="footer" /></template>
  </Modal>
</template>
