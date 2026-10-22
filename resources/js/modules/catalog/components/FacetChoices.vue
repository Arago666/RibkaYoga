<script setup lang="ts" generic="T extends FacetAssignments">
import { ref } from 'vue';
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
      :key="dimension"
      :open="!collapsible || model[dimension].length > 0"
    >
      <summary>
        {{ dimensionLabels[dimension] }}
        <span v-if="model[dimension].length">({{ model[dimension].length }})</span>
      </summary>
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
    </details>
  </div>
</template>
<style scoped>
.facet-choices {
  display: grid;
  gap: 12px;
  min-width: 0;
}
.facet-choices details {
  border: 1px solid #e3e9df;
  border-radius: 12px;
  padding: 12px;
}
.facet-choices summary {
  font-weight: 650;
  cursor: pointer;
  font-size: 0.87rem;
}
.facet-values {
  display: flex;
  flex-wrap: wrap;
  gap: 7px;
  margin-top: 12px;
}
.facet-values button {
  border: 1px solid #dce5d6;
  background: white;
  border-radius: 18px;
  padding: 8px 12px;
  font: inherit;
  font-size: 0.79rem;
  text-align: left;
  cursor: pointer;
}
.facet-values button[aria-pressed='true'] {
  background: #397a28;
  color: white;
  border-color: #397a28;
}
.facet-values span {
  opacity: 0.75;
  margin-left: 4px;
}
.value-search {
  width: 100%;
  margin-top: 10px;
}
</style>
