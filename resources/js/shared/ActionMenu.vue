<script setup lang="ts">
import { MoreVertical } from '@lucide/vue';
import { dismissMenu as vDismissMenu } from './dismissMenu';
withDefaults(defineProps<{ label?: string }>(), { label: 'Действия' });
function choose(event: MouseEvent) {
  if ((event.target as HTMLElement).closest('button:not(:disabled), a[href]'))
    (event.currentTarget as HTMLElement).closest('details')?.removeAttribute('open');
}
</script>
<template>
  <details v-dismiss-menu class="action-menu">
    <summary class="icon-btn" :aria-label="label" :title="label"><MoreVertical :size="20" /></summary>
    <div class="action-options" @click="choose"><slot /></div>
  </details>
</template>
<style scoped>
.action-menu {
  position: relative;
  flex: none;
}
summary {
  list-style: none;
  cursor: pointer;
}
summary::-webkit-details-marker {
  display: none;
}
.action-options {
  position: absolute;
  right: 0;
  top: calc(100% + 4px);
  z-index: 60;
  width: max-content;
  min-width: 210px;
  max-width: min(300px, 85vw);
  padding: 7px;
  background: var(--popup-surface);
  border: 1px solid var(--line);
  border-radius: var(--popup-radius);
  box-shadow: var(--popup-shadow);
  backdrop-filter: blur(20px);
  display: grid;
  gap: 3px;
}
.action-options :deep(button),
.action-options :deep(a) {
  display: flex;
  gap: 9px;
  align-items: center;
  width: 100%;
  text-align: left;
  border: 0;
  border-radius: 12px;
  padding: 10px 12px;
  min-height: 44px;
  background: transparent;
  color: var(--text);
  font: inherit;
  font-size: 13px;
  cursor: pointer;
  text-decoration: none;
  white-space: normal;
}
.action-options :deep(button:hover),
.action-options :deep(a:hover) {
  background: var(--accent-soft);
}
.action-options :deep(button:disabled) {
  opacity: 0.5;
  cursor: default;
}
@media screen and (max-width: 760px) {
  .action-options {
    max-height: min(60dvh, 480px);
    overflow-y: auto;
    overscroll-behavior: contain;
  }
  .action-options :deep(button),
  .action-options :deep(a) {
    font-size: 15px;
    min-height: 48px;
  }
}
</style>
