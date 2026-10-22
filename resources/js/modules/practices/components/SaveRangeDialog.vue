<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import { clone, executionParameters, uid, type Row } from '../types';
const props = defineProps<{ open: boolean; row: Row | null; theme?: string | null }>();
const emit = defineEmits<{ close: []; saved: [] }>();
const first = ref(1),
  last = ref(1),
  title = ref(''),
  busy = ref(false),
  error = ref('');
watch(
  () => props.open,
  (open) => {
    if (open) {
      first.value = 1;
      last.value = props.row?.cells.length || 1;
      title.value = '';
      error.value = '';
    }
  },
);
const cells = computed(() => props.row?.cells.slice(first.value - 1, last.value) || []);
async function save() {
  if (busy.value || !props.row || !title.value.trim() || first.value > last.value) return;
  busy.value = true;
  error.value = '';
  try {
    const counts = new Map<string, number>();
    for (const cell of cells.value)
      if (cell.group_key) counts.set(cell.group_key, (counts.get(cell.group_key) || 0) + 1);
    const full = new Map<string, number>();
    for (const cell of props.row.cells)
      if (cell.group_key) full.set(cell.group_key, (full.get(cell.group_key) || 0) + 1);
    const groups = new Map<string, string>();
    const content = cells.value.map((cell) => {
      let group = cell.group_key;
      if (group && (counts.get(group) !== full.get(group) || (counts.get(group) || 0) < 2)) group = null;
      if (group && !groups.has(group)) groups.set(group, uid());
      return {
        ...clone(cell),
        ...executionParameters(cell),
        id: uid(),
        group_key: group ? groups.get(group) : null,
      };
    });
    await api('/api/row-templates', {
      method: 'POST',
      body: JSON.stringify({
        title: title.value.trim(),
        theme: props.theme || null,
        kind: 'row',
        note: null,
        cells: content,
      }),
    });
    emit('saved');
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось сохранить фрагмент.';
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <Modal :open="open" title="Сохранить выбранные позиции" @close="!busy && emit('close')"
    ><form id="save-range" class="range-fields" @submit.prevent="save">
      <label class="field"
        ><span class="label">Название фрагмента</span
        ><input v-model="title" class="input" required maxlength="160" /></label
      ><label class="field"
        ><span class="label">Первая позиция</span
        ><input
          v-model.number="first"
          class="input"
          type="number"
          min="1"
          :max="row?.cells.length || 1"
          required /></label
      ><label class="field"
        ><span class="label">Последняя позиция</span
        ><input
          v-model.number="last"
          class="input"
          type="number"
          :min="first"
          :max="row?.cells.length || 1"
          required
      /></label>
      <p class="muted">
        Пустые позиции сохраняются. Скобки, захваченные только частично, в копии снимаются; исходная строка не
        меняется.
      </p>
      <p>{{ cells.length }} позиций</p>
      <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    </form>
    <template #footer
      ><button class="btn btn-secondary" :disabled="busy" @click="emit('close')">Отмена</button
      ><button class="btn btn-primary" type="submit" form="save-range" :disabled="busy || first > last">
        Сохранить
      </button></template
    ></Modal
  >
</template>
<style scoped>
.range-fields {
  display: grid;
  gap: 14px;
}
</style>
