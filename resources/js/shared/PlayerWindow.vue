<script setup lang="ts">
import { DialogRoot, DialogPortal, DialogOverlay, DialogContent, DialogTitle } from 'reka-ui';
defineProps<{ open: boolean; title: string }>();
const emit = defineEmits<{ close: [] }>();
</script>
<template>
  <slot v-if="!open" />
  <DialogRoot
    :open="open"
    @update:open="
      (value) => {
        if (!value) emit('close');
      }
    "
  >
    <DialogPortal>
      <DialogOverlay class="player-window-overlay" />
      <DialogContent class="player-window" :aria-describedby="undefined" @pointer-down-outside.prevent>
        <DialogTitle class="sr-only">{{ title }}</DialogTitle>
        <slot />
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
<style>
.player-window-overlay {
  position: fixed;
  inset: 0;
  background: #f5f8f3;
  z-index: 80;
}
.player-window {
  position: fixed;
  inset: 0;
  z-index: 81;
  width: 100%;
  height: 100%;
  height: 100dvh;
  overflow: auto;
  overscroll-behavior: contain;
  outline: none;
  background: radial-gradient(ellipse at 50% 0%, #eaf2e8 0%, #f8faf7 65%);
  padding: max(16px, env(safe-area-inset-top)) max(16px, env(safe-area-inset-right))
    max(16px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left));
}
@media (max-width: 480px) {
  .player-window {
    padding: max(12px, env(safe-area-inset-top)) max(12px, env(safe-area-inset-right))
      max(12px, env(safe-area-inset-bottom)) max(12px, env(safe-area-inset-left));
  }
}
</style>
