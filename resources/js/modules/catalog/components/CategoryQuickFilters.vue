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
  display: flex;
  align-items: center;
  gap: 7px;
  background: #fff;
  border: 1px solid #dce6d6;
  padding: 8px 13px;
  border-radius: 10px;
  font-size: 13px;
}
.quick-categories button.active {
  background: #edf7e8;
  border-color: #397a28;
  color: #2e6a22;
  font-weight: 650;
}
.quick-categories span {
  width: 9px;
  height: 9px;
  border-radius: 50%;
}
</style>
