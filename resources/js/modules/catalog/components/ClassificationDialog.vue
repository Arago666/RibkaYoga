<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import { errorText, type Asana } from '../types';
import {
  dimensions,
  dimensionLabels,
  emptyAssignments,
  type FacetAssignments,
  type FacetDictionary,
} from '../facets';
import FacetChoices from './FacetChoices.vue';
const props = defineProps<{ open: boolean; cards: Asana[] }>();
const emit = defineEmits<{ close: []; saved: [] }>();
const page = usePage();
const values = ref<FacetAssignments>(emptyAssignments()),
  included = ref<string[]>([]),
  mode = ref<'append' | 'replace'>('append'),
  status = ref<'partial' | 'reviewed'>('partial'),
  verified = ref(false),
  busy = ref(false),
  error = ref('');
const dictionary = ref<FacetDictionary>({ effect: [], anatomy: [], position: [], difficulty: [] });
interface Result {
  items: { id: string; name: string; before: FacetAssignments; after: FacetAssignments; changed: boolean }[];
  changed_count: number;
}
const preview = ref<Result | null>(null);
let manifest: unknown = null;
const names = computed(() =>
  Object.fromEntries(dimensions.flatMap((d) => dictionary.value[d].map((v) => [v.id, v.name]))),
);
watch(
  () => props.open,
  async (open) => {
    if (!open) return;
    values.value = emptyAssignments();
    included.value = [];
    preview.value = null;
    verified.value = false;
    error.value = '';
    try {
      dictionary.value = (await api<{ facets: FacetDictionary }>('/api/categories')).facets;
    } catch (failure) {
      error.value = errorText(failure);
    }
  },
);
watch(
  [values, included, mode, status],
  () => {
    preview.value = null;
    verified.value = false;
  },
  { deep: true },
);
async function review() {
  busy.value = true;
  error.value = '';
  const auth = page.props.auth as { workspace?: { id: string } };
  manifest = {
    format: 'ribka-classification-v1',
    workspace_id: auth.workspace?.id,
    mode: mode.value,
    items: props.cards.map((card) => ({
      asana_id: card.id,
      expected_revision: card.revision,
      expected_image_asset_id: card.image_asset_id,
      manual_verified: true,
      classification_status: status.value,
      facet_assignments: Object.fromEntries(
        included.value.map((d) => [d, values.value[d as keyof FacetAssignments]]),
      ),
    })),
  };
  try {
    preview.value = await api<Result>('/api/asanas/classify', {
      method: 'POST',
      body: JSON.stringify({ manifest, apply: false }),
    });
  } catch (failure) {
    error.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}
async function apply() {
  if (!verified.value || !preview.value || !manifest) return;
  busy.value = true;
  error.value = '';
  try {
    await api('/api/asanas/classify', { method: 'POST', body: JSON.stringify({ manifest, apply: true }) });
    emit('saved');
    emit('close');
  } catch (failure) {
    error.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <Modal :open="open" title="Разметить выбранные асаны" @close="!busy && emit('close')">
    <p>Выбрано: {{ cards.length }}. Изображения, имена и прежние листы сохранятся.</p>
    <div class="classification-controls">
      <label
        >Как менять<select v-model="mode" class="select" :disabled="busy">
          <option value="append">Добавить к имеющимся признакам</option>
          <option value="replace">Заменить выбранные группы</option>
        </select></label
      ><label
        >Проверка<select v-model="status" class="select" :disabled="busy">
          <option value="partial">Частичная разметка</option>
          <option value="reviewed">Все четыре группы проверены</option>
        </select></label
      >
    </div>
    <fieldset class="included">
      <legend>Какие группы изменятся</legend>
      <label v-for="d in dimensions" :key="d"
        ><input v-model="included" type="checkbox" :value="d" :disabled="busy" />{{
          dimensionLabels[d]
        }}</label
      >
    </fieldset>
    <FacetChoices v-model="values" :dictionary="dictionary" :disabled="busy" collapsible />
    <p class="muted">
      Группы без галочки сохранятся. В режиме замены пустой выбранный список очищает эту группу. При
      добавлении новый уровень заменяет прежний.
    </p>
    <section v-if="preview" class="classification-preview">
      <h3>Предпросмотр: изменится {{ preview.changed_count }}</h3>
      <article v-for="card in preview.items" :key="card.id">
        <strong>{{ card.name }}</strong>
        <div v-for="d in included" :key="d">
          <small
            >{{ dimensionLabels[d as keyof FacetAssignments] }}:
            {{ card.before[d as keyof FacetAssignments].map((id) => names[id]).join(', ') || 'не указано' }} →
            {{
              card.after[d as keyof FacetAssignments].map((id) => names[id]).join(', ') || 'не указано'
            }}</small
          >
        </div>
      </article>
      <label
        ><input v-model="verified" type="checkbox" :disabled="busy" />Я проверил(а) рисунки этих поз и
        показанную разметку</label
      >
    </section>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <template #footer
      ><button class="btn btn-secondary" :disabled="busy" @click="emit('close')">Отмена</button
      ><button
        v-if="!preview"
        class="btn btn-primary"
        :disabled="busy || !included.length || !cards.length"
        @click="review"
      >
        Предпросмотр</button
      ><button
        v-else
        class="btn btn-primary"
        :disabled="busy || !verified || !preview.changed_count"
        @click="apply"
      >
        {{ busy ? 'Сохраняем…' : 'Применить разметку' }}
      </button></template
    >
  </Modal>
</template>
<style scoped>
.classification-controls,
.included {
  display: grid;
  gap: 10px;
  margin: 16px 0;
}
.classification-controls label {
  display: grid;
  gap: 6px;
}
.included {
  padding: 12px;
  border: 1px solid #dce5d6;
  border-radius: 10px;
}
.included label {
  display: flex;
  gap: 7px;
  align-items: center;
  font-size: 0.85rem;
}
.classification-preview {
  margin-top: 20px;
}
.classification-preview article {
  border-bottom: 1px solid #e5eadd;
  padding: 10px 0;
}
.classification-preview small {
  display: block;
  line-height: 1.5;
}
.classification-preview > label {
  display: flex;
  gap: 8px;
  align-items: start;
  margin-top: 16px;
}
</style>
