<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{ active: 'all' | 'programs' | 'categories' | 'shared' }>();

const sections = [
  { key: 'all', href: '/practices', label: 'Все практики' },
  { key: 'programs', href: '/mesocycles', label: 'Программы и циклы' },
  { key: 'categories', href: '/practice-folders', label: 'Категории' },
  { key: 'shared', href: '/shared-practices', label: 'Мне дали доступ' },
] as const;
</script>

<template>
  <nav class="tabs practice-navigation" aria-label="Разделы практик">
    <Link
      v-for="section in sections"
      :key="section.key"
      :href="section.href"
      class="tab"
      :class="{ active: active === section.key }"
      :aria-current="active === section.key ? 'page' : undefined"
    >
      {{ section.label }}
    </Link>
  </nav>
</template>

<style scoped>
.practice-navigation {
  margin-bottom: var(--section-gap);
}
.practice-navigation .tab {
  min-height: var(--control-height);
}
@media (max-width: 650px) {
  .practice-navigation {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 6px 8px;
    overflow: visible;
  }
  .practice-navigation .tab {
    justify-content: center;
    text-align: center;
    white-space: normal;
    padding: 12px 6px;
    font-size: 13px;
  }
}
</style>
