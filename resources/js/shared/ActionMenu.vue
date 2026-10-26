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
  background: white;
  border: 1px solid #dce5d6;
  border-radius: 12px;
  box-shadow: 0 8px 28px #153a1320;
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
  border-radius: 7px;
  padding: 10px 12px;
  min-height: 39px;
  background: transparent;
  color: #31442a;
  font: inherit;
  font-size: 13px;
  cursor: pointer;
  text-decoration: none;
  white-space: normal;
}
.action-options :deep(button:hover),
.action-options :deep(a:hover) {
  background: #eff6eb;
}
.action-options :deep(button:disabled) {
  opacity: 0.5;
  cursor: default;
}
</style>
