<script setup lang="ts">
import { ArrowUp, ArrowDown, ArrowLeft, ArrowRight } from '@lucide/vue';
import type { ArrowDirection } from '../types';

withDefaults(defineProps<{ direction: ArrowDirection; size?: number }>(), { size: 20 });
const legacyIcons = { up: ArrowUp, down: ArrowDown, left: ArrowLeft, right: ArrowRight };
</script>

<template>
  <svg
    v-if="direction === 'cross' || direction === 'both'"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    aria-hidden="true"
  >
    <rect width="24" height="24" rx="3" fill="white" />
    <g stroke="#1670cf" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
      <path d="M3 12h18M7 8l-4 4 4 4m10-8 4 4-4 4" />
      <path v-if="direction === 'cross'" d="M12 3v18M8 7l4-4 4 4M8 17l4 4 4-4" />
      <path v-else d="M12 10v4" />
    </g>
  </svg>
  <component v-else :is="legacyIcons[direction]" :size="size" :stroke-width="2.7" aria-hidden="true" />
</template>
