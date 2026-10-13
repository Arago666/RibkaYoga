<script setup lang="ts">
import { ref } from 'vue';
import CatalogSelectDialog from './CatalogSelectDialog.vue';
import { relationLabels, type RelationKind, type AsanaRelation, type Asana } from '../types';
const props = defineProps<{ asanaId: string; disabled?: boolean }>();
const values = defineModel<AsanaRelation[]>({ required: true });
const kind = ref<RelationKind>('simpler'),
  open = ref(false);
function add(cards: Asana[]) {
  for (const card of cards)
    if (
      card.id !== props.asanaId &&
      !values.value.some((v) => v.asana_id === card.id && v.kind === kind.value)
    )
      values.value.push({
        asana_id: card.id,
        kind: kind.value,
        name: card.name,
        image_url: card.thumbnail_url,
      });
}
</script>
<template>
  <section class="relations-editor">
    <h3 class="label">Связанные варианты</h3>
    <p class="muted">Привяжите готовые карточки с другой сложностью или подводящим упражнением.</p>
    <div v-for="(value, index) in values" :key="value.asana_id + value.kind" class="relation-item">
      <span>{{ relationLabels[value.kind] }}: {{ value.name }}</span
      ><button type="button" class="btn btn-ghost" :disabled="disabled" @click="values.splice(index, 1)">
        Убрать
      </button>
    </div>
    <div class="relations-add">
      <select v-model="kind" class="select" aria-label="Тип связанного варианта" :disabled="disabled">
        <option v-for="(label, key) in relationLabels" :key="key" :value="key">{{ label }}</option></select
      ><button type="button" class="btn btn-secondary" :disabled="disabled" @click="open = true">
        Выбрать карточку
      </button>
    </div>
    <CatalogSelectDialog
      :open="open"
      existing-label="Недоступна для выбора"
      :existing-ids="[asanaId, ...values.filter((v) => v.kind === kind).map((v) => v.asana_id)]"
      @close="open = false"
      @selected="add"
    />
  </section>
</template>
<style scoped>
.relations-editor {
  margin: 20px 0;
}
.relations-editor p {
  font-size: 13px;
}
.relation-item,
.relations-add {
  display: flex;
  align-items: center;
  gap: 10px;
  justify-content: space-between;
  margin: 10px 0;
}
.relation-item {
  font-size: 13px;
}
.relations-add select {
  flex: 1;
  min-width: 0;
}
</style>
