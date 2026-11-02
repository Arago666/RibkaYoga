<script setup lang="ts">
import { Minus, Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
const props = withDefaults(
  defineProps<{
    label: string;
    value: number;
    context?: string;
    min?: number;
    max?: number;
    disabled?: boolean;
    overridden?: boolean;
    resettable?: boolean;
  }>(),
  { min: 0, max: 86400 },
);
const emit = defineEmits<{ change: [value: number]; reset: [] }>();
const input = ref(String(props.value));
watch(
  () => props.value,
  (value) => (input.value = String(value)),
);
function commit(value: number) {
  if (props.disabled) return;
  const next = Number.isFinite(value)
    ? Math.max(props.min, Math.min(props.max, Math.round(value)))
    : props.value;
  input.value = String(next);
  emit('change', next);
}
function changeInput(event: Event) {
  const value = (event.target as HTMLInputElement).value;
  commit(value.trim() === '' ? props.value : Number(value));
}
function inputValue(event: Event) {
  const raw = (event.target as HTMLInputElement).value;
  input.value = raw;
  const value = Number(raw);
  if (
    !props.disabled &&
    raw.trim() !== '' &&
    Number.isInteger(value) &&
    value >= props.min &&
    value <= props.max
  )
    emit('change', value);
}
</script>
<template>
  <div class="seconds-control">
    <span class="seconds-label">{{ label }} <span>сек</span></span>
    <div class="seconds-stepper">
      <button
        type="button"
        class="seconds-button"
        :disabled="disabled || value <= min"
        :aria-label="`Уменьшить ${label.toLowerCase()} на 15 секунд${context ? ': ' + context : ''}`"
        @click="commit(value - 15)"
      >
        <Minus :size="16" />
      </button>
      <input
        :value="input"
        type="number"
        inputmode="numeric"
        :min="min"
        :max="max"
        step="1"
        :disabled="disabled"
        :aria-label="`${label}, секунд${context ? ': ' + context : ''}`"
        @input="inputValue"
        @change="changeInput"
        @blur="changeInput"
      />
      <button
        type="button"
        class="seconds-button"
        :disabled="disabled || value >= max"
        :aria-label="`Увеличить ${label.toLowerCase()} на 15 секунд${context ? ': ' + context : ''}`"
        @click="commit(value + 15)"
      >
        <Plus :size="16" />
      </button>
    </div>
    <button
      v-if="resettable"
      type="button"
      class="seconds-reset"
      :disabled="disabled || !overridden"
      :aria-label="`Использовать общее значение: ${label.toLowerCase()}${context ? ', ' + context : ''}`"
      @click="emit('reset')"
    >
      {{ overridden ? 'Как для всех' : 'Общее значение' }}
    </button>
  </div>
</template>
<style scoped>
.seconds-control {
  display: grid;
  gap: 6px;
  min-width: 0;
}
.seconds-label {
  font-size: 12px;
  color: var(--text);
  font-weight: 600;
}
.seconds-label > span {
  font-weight: 400;
  color: var(--muted);
}
.seconds-stepper {
  display: grid;
  grid-template-columns: 44px minmax(48px, 1fr) 44px;
  min-width: 0;
  border: 1px solid var(--line);
  border-radius: var(--field-radius);
  overflow: hidden;
  background: #fff;
}
.seconds-stepper input {
  width: 100%;
  min-width: 0;
  min-height: 44px;
  border: 0;
  background: transparent;
  color: var(--text);
  font-size: 14px;
  text-align: center;
  padding: 4px 0;
  appearance: textfield;
  -moz-appearance: textfield;
}
.seconds-stepper input::-webkit-inner-spin-button,
.seconds-stepper input::-webkit-outer-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
.seconds-stepper:focus-within {
  outline: 2px solid var(--accent-dark);
  outline-offset: 2px;
}
.seconds-button {
  display: grid;
  place-items: center;
  width: 44px;
  min-height: 44px;
  border: 0;
  color: var(--text);
  background: var(--glass);
}
.seconds-button:hover:not(:disabled) {
  background: var(--accent-soft);
}
.seconds-reset {
  min-height: 44px;
  padding: 3px 0;
  border: 0;
  background: transparent;
  font-size: 11px;
  text-align: left;
  color: var(--accent-dark);
}
.seconds-reset:disabled {
  color: var(--muted);
  opacity: 1;
}
</style>
