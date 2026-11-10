<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';

const navigation = ref<HTMLElement | null>(null);
onMounted(() => {
  const active = navigation.value?.querySelector<HTMLElement>('[aria-current="page"]');
  if (active && navigation.value)
    navigation.value.scrollLeft = active.offsetLeft - navigation.value.offsetLeft - 6;
});

defineProps<{ active: 'all' | 'programs' | 'categories' | 'shared' }>();

const sections = [
  { key: 'all', href: '/practices', label: 'Все практики' },
  { key: 'programs', href: '/mesocycles', label: 'Программы и циклы' },
  { key: 'categories', href: '/practice-folders', label: 'Категории' },
  { key: 'shared', href: '/shared-practices', label: 'Мне дали доступ' },
] as const;
</script>

<template>
  <nav ref="navigation" class="tabs practice-navigation" aria-label="Разделы практик">
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
@media screen and (max-width: 760px) {
  .practice-navigation {
    display: flex;
    flex-wrap: nowrap;
    overflow-x: auto;
    overscroll-behavior-x: contain;
    scrollbar-width: none;
    scroll-snap-type: x proximity;
    padding: 5px;
    gap: 4px;
  }
  .practice-navigation::-webkit-scrollbar {
    display: none;
  }
  .practice-navigation .tab {
    flex: 0 0 auto;
    white-space: nowrap;
    padding: 12px 16px;
    font-size: 14px;
    scroll-snap-align: start;
  }
}
</style>
