<script setup lang="ts">
import { categoryColor, type Category } from '../types';
defineProps<{ categories: Category[] }>();
</script>
<template>
  <div class="category-markers" aria-label="Категории асаны">
    <span
      v-for="category in categories"
      :key="category.id"
      :title="category.name"
      :aria-label="category.name"
      :style="{ background: categoryColor(category.name) }"
    ></span>
  </div>
</template>
<style scoped>
.category-markers {
  display: flex;
  gap: 5px;
}
.category-markers span {
  width: 11px;
  height: 11px;
  border-radius: 50%;
  box-shadow: 0 0 0 2px #fff;
}
</style>
