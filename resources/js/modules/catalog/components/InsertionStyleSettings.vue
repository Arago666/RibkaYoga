<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { api } from '@/shared/api';
import type { Character, IllustrationStyle } from '../illustrations/mannequin';
defineProps<{ style: IllustrationStyle; character: Character; disabled?: boolean }>();
const emit = defineEmits<{ change: [value: { style: IllustrationStyle; character: Character }] }>();
const coverage = ref<{ total: number; matched: number } | null>(null);
onMounted(async () => {
  try {
    coverage.value = (
      await api<{ data: { coverage: { total: number; matched: number } } }>('/api/illustration-styles')
    ).data.coverage;
  } catch {
    /* The full settings page offers retry and details. */
  }
});
</script>
<template>
  <fieldset :disabled="disabled" class="insertion-style">
    <legend>Стиль будущих вставок этого занятия</legend>
    <label
      >Представление<select
        :value="style"
        @change="
          emit('change', {
            style: ($event.target as HTMLSelectElement).value as IllustrationStyle,
            character,
          })
        "
      >
        <option value="original">Мои рисунки</option>
        <option value="line">Линейная фигура</option>
        <option value="volume">Объёмная фигура</option>
      </select></label
    ><label
      >Персонаж<select
        :value="character"
        :disabled="style === 'original'"
        @change="
          emit('change', { style, character: ($event.target as HTMLSelectElement).value as Character })
        "
      >
        <option value="neutral">Нейтральная фигура</option>
        <option value="female">Женщина</option>
        <option value="male">Мужчина</option>
      </select></label
    >
    <p>
      Уже размещённые картинки сохраняются.
      <template v-if="coverage"
        >Проверенные альтернативы: {{ coverage.matched }} из {{ coverage.total }}; у остальных остаётся
        исходный рисунок.</template
      >
      <a href="/illustration-settings" target="_blank" rel="noopener">Посмотреть набор и 3D</a>
    </p>
  </fieldset>
</template>
<style scoped>
.insertion-style {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  border: 1px solid #dce5d7;
  border-radius: 0.7rem;
  padding: 0.8rem;
}
.insertion-style legend {
  font-weight: 600;
  padding: 0 0.3rem;
}
.insertion-style label {
  display: grid;
  gap: 0.3rem;
  min-width: 180px;
  flex: 1;
}
.insertion-style select {
  min-height: 44px;
  border: 1px solid #c5d0c0;
  border-radius: 0.4rem;
  padding: 0.4rem;
  background: white;
}
.insertion-style p {
  font-size: 0.8rem;
  color: #61705c;
  width: 100%;
}
.insertion-style a {
  text-decoration: underline;
}
</style>
