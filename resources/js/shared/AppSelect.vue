<script setup lang="ts" generic="T extends string | number | boolean | null | undefined = string">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  onUpdated,
  ref,
  shallowRef,
  useAttrs,
  type StyleValue,
} from 'vue';
import {
  SelectRoot,
  SelectTrigger,
  SelectPortal,
  SelectContent,
  SelectViewport,
  SelectGroup,
  SelectLabel,
  SelectItem,
  SelectItemText,
  SelectItemIndicator,
} from 'reka-ui';
import { Check, ChevronDown } from '@lucide/vue';

defineOptions({ inheritAttrs: false });
const props = defineProps<{
  modelValue?: T;
  value?: T;
  modelModifiers?: { number?: boolean; trim?: boolean };
  disabled?: boolean;
  required?: boolean;
  name?: string;
  form?: string;
  autocomplete?: string;
  placeholder?: string;
}>();
const emit = defineEmits<{
  'update:modelValue': [value: T];
  change: [event: Event];
}>();
const attrs = useAttrs();
interface Choice {
  token: string;
  value: T;
  label: string;
  disabled: boolean;
  group: string;
  groupLabel: string;
}
const native = ref<HTMLSelectElement>();
const trigger = ref<InstanceType<typeof SelectTrigger>>();
const choices = shallowRef<Choice[]>([]);
const inheritedDisabled = ref(false);
const open = ref(false);
let optionsObserver: MutationObserver | undefined;
let fieldsetsObserver: MutationObserver | undefined;
let fingerprint = '';
const current = computed(() => (props.modelValue !== undefined ? props.modelValue : props.value));
const disabled = computed(() => Boolean(props.disabled || inheritedDisabled.value));
function triggerAttrs() {
  const { class: _class, style: _style, ...rest } = attrs;
  return rest;
}
function sameValue(a: unknown, b: unknown) {
  return Object.is(a, b) || (a != null && b != null && String(a) === String(b));
}
const selected = computed(() => choices.value.find((choice) => sameValue(choice.value, current.value)));
const token = computed(() => selected.value?.token);
const sections = computed(() => {
  const result: { key: string; label: string; choices: Choice[] }[] = [];
  for (const choice of choices.value) {
    const previous = result[result.length - 1];
    if (previous?.key === choice.group) previous.choices.push(choice);
    else result.push({ key: choice.group, label: choice.groupLabel, choices: [choice] });
  }
  return result;
});

/** Keep Vue option values (including null and numbers), groups and native form behavior. */
function synchronize() {
  const control = native.value;
  if (!control) return;
  inheritedDisabled.value = control.matches(':disabled');
  const groups = Array.from(control.querySelectorAll('optgroup'));
  const next = Array.from(control.options).map((option, index) => {
    const parent = option.parentElement instanceof HTMLOptGroupElement ? option.parentElement : null;
    const groupIndex = parent ? groups.indexOf(parent) : -1;
    return {
      token: `option-${index}`,
      value: ('_value' in option ? (option as HTMLOptionElement & { _value: T })._value : option.value) as T,
      label: option.label.trim(),
      disabled: option.disabled || Boolean(parent?.disabled),
      group: parent ? `group-${groupIndex}` : 'ungrouped',
      groupLabel: parent?.label ?? '',
    };
  });
  const nextFingerprint = JSON.stringify(next.map((choice) => [choice, typeof choice.value]));
  if (fingerprint !== nextFingerprint) {
    fingerprint = nextFingerprint;
    choices.value = next;
  }
  const index = next.findIndex((choice) => sameValue(choice.value, current.value));
  if (control.selectedIndex !== index) control.selectedIndex = index;
  if (inheritedDisabled.value) open.value = false;
}
function change(event: Event) {
  const control = native.value;
  if (!control || control.matches(':disabled')) return;
  const option = control.selectedOptions[0];
  if (!option) return;
  let value: unknown =
    '_value' in option ? (option as HTMLOptionElement & { _value: T })._value : option.value;
  if (props.modelModifiers?.trim && typeof value === 'string') value = value.trim();
  if (props.modelModifiers?.number && typeof value === 'string') {
    const number = Number.parseFloat(value);
    if (!Number.isNaN(number)) value = number;
  }
  emit('update:modelValue', value as T);
  emit('change', event);
}
function choose(value: unknown) {
  const control = native.value;
  if (!control || control.matches(':disabled')) return;
  const index = choices.value.findIndex((choice) => choice.token === value);
  if (index < 0 || choices.value[index].disabled) return;
  control.selectedIndex = index;
  // Existing @change handlers continue receiving a real HTMLSelectElement target.
  control.dispatchEvent(new Event('change', { bubbles: true }));
}
function setOpen(value: boolean) {
  synchronize();
  open.value = value && !disabled.value;
}
function invalid(event: Event) {
  event.preventDefault();
  if (disabled.value) return;
  trigger.value?.$el?.focus();
  void nextTick(() => {
    open.value = true;
  });
}
onMounted(() => {
  synchronize();
  optionsObserver = new MutationObserver(synchronize);
  if (native.value) {
    optionsObserver.observe(native.value, {
      subtree: true,
      childList: true,
      characterData: true,
      attributes: true,
      attributeFilter: ['value', 'disabled', 'label', 'selected'],
    });
    fieldsetsObserver = new MutationObserver(synchronize);
    let parent = native.value.parentElement;
    while (parent) {
      if (parent instanceof HTMLFieldSetElement)
        fieldsetsObserver.observe(parent, { attributes: true, attributeFilter: ['disabled'] });
      parent = parent.parentElement;
    }
  }
});
onUpdated(synchronize);
onBeforeUnmount(() => {
  optionsObserver?.disconnect();
  fieldsetsObserver?.disconnect();
});
</script>

<template>
  <span class="app-select" :class="attrs.class" :style="attrs.style as StyleValue">
    <SelectRoot
      :model-value="token"
      :open="open"
      :disabled="disabled"
      :required="required"
      @update:model-value="choose"
      @update:open="setOpen"
    >
      <SelectTrigger
        ref="trigger"
        v-bind="triggerAttrs()"
        class="app-select-trigger"
        :aria-required="required || undefined"
        :data-placeholder="!selected ? '' : undefined"
      >
        <span class="app-select-value">{{ selected?.label || placeholder || 'Выберите' }}</span>
        <ChevronDown class="app-select-chevron" :size="17" aria-hidden="true" />
      </SelectTrigger>
      <SelectPortal>
        <SelectContent
          class="app-select-content"
          :style="{ backgroundColor: '#fff', position: 'relative', zIndex: 240, isolation: 'isolate' }"
          position="popper"
          :side-offset="7"
          :collision-padding="12"
          :body-lock="false"
          :aria-label="
            (attrs['aria-label'] as string | undefined) || selected?.label || placeholder || 'Варианты'
          "
          @pointer-down-outside="open = false"
        >
          <SelectViewport class="app-select-viewport">
            <SelectGroup v-for="section in sections" :key="section.key">
              <SelectLabel v-if="section.label" class="app-select-group-label">{{
                section.label
              }}</SelectLabel>
              <SelectItem
                v-for="choice in section.choices"
                :key="choice.token"
                :value="choice.token"
                :disabled="choice.disabled"
                :text-value="choice.label"
                class="app-select-option"
              >
                <SelectItemText class="app-select-option-label">{{ choice.label }}</SelectItemText>
                <SelectItemIndicator class="app-select-check"
                  ><Check :size="17" aria-hidden="true"
                /></SelectItemIndicator>
              </SelectItem>
            </SelectGroup>
          </SelectViewport>
        </SelectContent>
      </SelectPortal>
    </SelectRoot>
    <select
      ref="native"
      class="app-select-native"
      tabindex="-1"
      aria-hidden="true"
      :name="name"
      :form="form"
      :required="required"
      :disabled="props.disabled"
      :autocomplete="autocomplete"
      @change="change"
      @invalid="invalid"
    >
      <slot />
    </select>
  </span>
</template>
