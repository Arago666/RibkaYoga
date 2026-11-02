<script setup lang="ts">
import { computed } from 'vue';
import { categoryColor, type Category } from '../types';
const props = defineProps<{ categories: Category[]; disabled?: boolean }>();
const selected = defineModel<string>({ required: true });
const quick = computed(() =>
  ['Лёжа', 'Сидя', 'Стоя']
    .map((name) => ({
      name,
      category: props.categories.find(
        (c) => c.name.toLowerCase().replaceAll('ё', 'е') === name.toLowerCase().replaceAll('ё', 'е'),
      ),
    }))
    .filter((c) => c.category),
);
</script>
<template>
  <div class="quick-categories" role="group" aria-label="Положение тела">
    <button
      type="button"
      :class="{ active: !selected }"
      :aria-pressed="!selected"
      :disabled="disabled"
      @click="selected = ''"
    >
      Все
    </button>
    <button
      v-for="item in quick"
      :key="item.category!.id"
      type="button"
      :class="{ active: selected === item.category!.id }"
      :aria-pressed="selected === item.category!.id"
      :disabled="disabled"
      @click="selected = item.category!.id"
    >
      <span :style="{ background: categoryColor(item.name) }" aria-hidden="true"></span>{{ item.name }}
    </button>
  </div>
</template>
<style scoped>
.quick-categories {
  display: flex;
  gap: 7px;
  flex-wrap: wrap;
  margin: 12px 0;
}
.quick-categories button {
  min-height: var(--control-height);
  min-width: 44px;
  color: var(--text);
  display: flex;
  align-items: center;
  gap: 7px;
  background: var(--glass);
  border: 1px solid var(--line);
  padding: 8px 13px;
  border-radius: 999px;
  font-size: 13px;
  box-shadow: inset 0 1px 0 #fff;
}
.quick-categories button.active {
  background: var(--accent-soft);
  border-color: var(--accent);
  color: var(--accent-dark);
  font-weight: 650;
}
.quick-categories span {
  width: 9px;
  height: 9px;
  border-radius: 50%;
}
</style>
