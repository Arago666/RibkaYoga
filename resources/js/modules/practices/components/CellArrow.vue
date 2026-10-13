<script setup lang="ts">
import ArrowSymbol from './ArrowSymbol.vue';
import { arrowLabels, type ArrowDirection } from '../types';
defineProps<{ direction: ArrowDirection }>();
</script>

<template>
  <span class="cell-arrow" role="img" :aria-label="`Стрелка: ${arrowLabels[direction]}`">
    <ArrowSymbol :direction="direction" :size="18" />
  </span>
</template>

<style scoped>
.cell-arrow {
  position: absolute;
  top: 2px;
  right: 2px;
  z-index: 2;
  display: flex;
  color: #1670cf;
  background: #fff;
  border-radius: 3px;
  pointer-events: none;
  print-color-adjust: exact;
  -webkit-print-color-adjust: exact;
}
@media print {
  .cell-arrow {
    color: #1670cf !important;
  }
}
</style>
