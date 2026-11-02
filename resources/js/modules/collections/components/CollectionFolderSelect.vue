<script setup lang="ts">
import AppSelect from '@/shared/AppSelect.vue';
import { computed } from 'vue';
import { folderOptions, type CollectionFolder } from '../types';
const props = withDefaults(
  defineProps<{
    modelValue: string;
    folders: CollectionFolder[];
    disabled?: boolean;
    id?: string;
    omitSubtreeOf?: string;
    rootLabel?: string;
  }>(),
  { rootLabel: 'Без папки', disabled: false, id: undefined, omitSubtreeOf: undefined },
);
defineEmits<{ 'update:modelValue': [value: string] }>();
const options = computed(() => folderOptions(props.folders, props.omitSubtreeOf));
</script>
<template>
  <AppSelect
    :id="id"
    class="select"
    :value="modelValue"
    :disabled="disabled"
    @change="$emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
  >
    <option value="">{{ rootLabel }}</option>
    <option v-for="folder in options" :key="folder.id" :value="folder.id">
      {{ '— '.repeat(folder.depth) }}{{ folder.name }}
    </option>
  </AppSelect>
</template>
