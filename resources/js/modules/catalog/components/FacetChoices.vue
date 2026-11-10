<script setup lang="ts" generic="T extends FacetAssignments">
import { ref } from 'vue';
import { SlidersHorizontal } from '@lucide/vue';
import {
  dimensions,
  dimensionLabels,
  type FacetAssignments,
  type FacetDictionary,
  type Dimension,
} from '../facets';
const model = defineModel<T>({ required: true });
defineProps<{ dictionary: FacetDictionary; disabled?: boolean; collapsible?: boolean }>();
const searches = ref<Record<Dimension, string>>({ effect: '', anatomy: '', position: '', difficulty: '' });
function select(dimension: Dimension, id: string) {
  const selected = model.value[dimension];
  model.value = {
    ...model.value,
    [dimension]: selected.includes(id)
      ? selected.filter((v) => v !== id)
      : dimension === 'difficulty'
        ? [id]
        : [...selected, id],
  };
}
</script>
<template>
  <div class="facet-choices">
    <details
      v-for="dimension in dimensions"
      class="disclosure"
      :key="dimension"
      :open="!collapsible || model[dimension].length > 0"
    >
      <summary>
        <span class="disclosure-icon"><SlidersHorizontal aria-hidden="true" /></span>
        <span class="disclosure-copy">
          <strong>{{ dimensionLabels[dimension] }}</strong>
          <small v-if="model[dimension].length">Выбрано: {{ model[dimension].length }}</small>
        </span>
      </summary>
      <div class="disclosure-body">
        <input
          v-if="dictionary[dimension]?.length > 10"
          v-model="searches[dimension]"
          class="input value-search"
          :aria-label="`Найти: ${dimensionLabels[dimension]}`"
          placeholder="Поиск значения"
        />
        <div class="facet-values" role="group" :aria-label="dimensionLabels[dimension]">
          <button
            type="button"
            :disabled="disabled"
            :aria-pressed="!model[dimension].length"
            @click="model = { ...model, [dimension]: [] }"
          >
            {{ dimension === 'difficulty' ? 'Любой' : 'Все' }}
          </button>
          <button
            v-for="value in (dictionary[dimension] || []).filter((v) =>
              v.name.toLocaleLowerCase('ru').includes(searches[dimension].toLocaleLowerCase('ru')),
            )"
            :key="value.id"
            type="button"
            :disabled="disabled"
            :aria-pressed="model[dimension].includes(value.id)"
            @click="select(dimension, value.id)"
          >
            {{ value.name }} <span v-if="value.count !== undefined">{{ value.count }}</span>
          </button>
        </div>
      </div>
    </details>
  </div>
</template>
<style scoped>
.facet-choices {
  display: grid;
  gap: 12px;
  min-width: 0;
}
.facet-values {
  display: flex;
  flex-wrap: wrap;
  gap: 7px;
  margin-top: 12px;
}
.facet-values button {
  min-height: var(--control-height);
  min-width: 44px;
  color: var(--text);
  border: 1px solid var(--line);
  background: var(--glass);
  border-radius: 999px;
  padding: 8px 12px;
  font: inherit;
  font-size: 13px;
  text-align: left;
  cursor: pointer;
  box-shadow: inset 0 1px 0 #fff;
}
.facet-values button[aria-pressed='true'] {
  background: var(--accent-soft);
  color: var(--accent-dark);
  border-color: #b8ccaa;
}
.facet-values span {
  opacity: 0.75;
  margin-left: 4px;
}
.value-search {
  width: 100%;
  margin-top: 10px;
}
@media screen and (max-width: 760px) {
  .facet-values button {
    font-size: 14px;
    min-height: 48px;
    line-height: 1.4;
  }
}
</style>
