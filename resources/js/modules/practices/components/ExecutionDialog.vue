<script setup lang="ts">
import { ref, watch } from 'vue';
import Modal from '@/shared/Modal.vue';
import { executionParameters, type Cell, type ExecutionParameters } from '../types';
const props = defineProps<{ open: boolean; cell: Cell | null }>();
const emit = defineEmits<{ close: []; save: [value: ExecutionParameters] }>();
const form = ref<ExecutionParameters>(executionParameters());
watch(
  () => props.open,
  (open) => {
    if (open) form.value = executionParameters(props.cell || undefined);
  },
);
function number(value: unknown): number | null {
  return value === '' || value === null ? null : Number(value);
}
function save() {
  const values = {
    ...form.value,
    duration_seconds: number(form.value.duration_seconds),
    breath_count: number(form.value.breath_count),
    repetitions: number(form.value.repetitions),
  };
  if (values.side === 'both' && !values.side_order) values.side_order = 'left_first';
  emit('save', values);
}
</script>
<template>
  <Modal :open="open" title="Параметры выполнения" @close="emit('close')">
    <form id="execution-parameters" class="execution-fields" @submit.prevent="save">
      <p class="muted">
        Время и дыхания относятся к одному повторению на одной стороне. Пустые значения означают ручной
        переход.
      </p>
      <label class="field"
        ><span class="label">Переход</span
        ><select v-model="form.advance_mode" class="select">
          <option :value="null">Не задан</option>
          <option value="manual">Вручную</option>
          <option value="time">По времени</option>
          <option value="breaths">По дыханиям</option>
        </select></label
      >
      <label class="field"
        ><span class="label">Удержание, секунд</span
        ><input
          v-model="form.duration_seconds"
          class="input"
          type="number"
          min="1"
          max="86400"
          :required="form.advance_mode === 'time'"
      /></label>
      <label class="field"
        ><span class="label">Дыхательных циклов</span
        ><input
          v-model="form.breath_count"
          class="input"
          type="number"
          min="1"
          max="1000"
          :required="form.advance_mode === 'breaths'"
      /></label>
      <label class="field"
        ><span class="label">Повторений на каждую сторону</span
        ><input v-model="form.repetitions" class="input" type="number" min="1" max="1000"
      /></label>
      <label class="field"
        ><span class="label">Сторона</span
        ><select v-model="form.side" class="select">
          <option :value="null">Не задана</option>
          <option value="left">Левая</option>
          <option value="right">Правая</option>
          <option value="both">Обе</option>
        </select></label
      >
      <label v-if="form.side === 'both'" class="field"
        ><span class="label">Порядок сторон</span
        ><select v-model="form.side_order" class="select">
          <option :value="null">Сначала левая</option>
          <option value="left_first">Сначала левая</option>
          <option value="right_first">Сначала правая</option>
        </select></label
      >
    </form>
    <template #footer
      ><button class="btn btn-secondary" @click="emit('close')">Отмена</button
      ><button class="btn btn-primary" type="submit" form="execution-parameters">Применить</button></template
    >
  </Modal>
</template>
<style scoped>
.execution-fields {
  display: grid;
  gap: 14px;
}
</style>
