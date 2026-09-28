<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Plus, ChevronUp, ChevronDown, X } from '@lucide/vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import CatalogSelectDialog from '../../catalog/components/CatalogSelectDialog.vue';
import { errorText, type Asana, type Collection } from '../../catalog/types';
const props = defineProps<{ open: boolean; collection: Collection | null }>();
const emit = defineEmits<{ close: []; saved: [] }>();
const name = ref(''),
  description = ref(''),
  error = ref(''),
  busy = ref(false),
  picker = ref(false);
const cards = ref<Asana[]>([]);
const ids = computed(() => cards.value.map((card) => card.id));
watch(
  () => props.open,
  (open) => {
    if (!open) return;
    name.value = props.collection?.name || '';
    description.value = props.collection?.description || '';
    cards.value = [...(props.collection?.items || [])];
    error.value = '';
  },
);
function move(index: number, offset: number) {
  const next = [...cards.value];
  const item = next.splice(index, 1)[0];
  if (item) next.splice(index + offset, 0, item);
  cards.value = next;
}
function add(selected: Asana[]) {
  const current = new Set(ids.value);
  cards.value.push(...selected.filter((card) => !current.has(card.id)));
}
async function save() {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await api(props.collection ? `/api/collections/${props.collection.id}` : '/api/collections', {
      method: props.collection ? 'PATCH' : 'POST',
      body: JSON.stringify({
        name: name.value.trim(),
        description: description.value || null,
        asana_ids: ids.value,
        ...(props.collection ? { expected_revision: props.collection.revision } : {}),
      }),
    });
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
  <Modal
    :open="open"
    :title="collection ? 'Изменить подборку' : 'Новая подборка'"
    @close="!busy && !picker && $emit('close')"
  >
    <form id="collection-editor" class="collection-editor" @submit.prevent="save">
      <div v-if="error" class="error-banner" role="alert">{{ error }}</div>
      <div class="field">
        <label class="label" for="collection-name">Название</label
        ><input
          id="collection-name"
          v-model="name"
          class="input"
          required
          maxlength="160"
          :disabled="busy"
          placeholder="Например, практика на баланс"
        />
      </div>
      <div class="field">
        <label class="label" for="collection-note">Описание · необязательно</label
        ><textarea
          id="collection-note"
          v-model="description"
          class="textarea"
          rows="2"
          maxlength="3000"
          :disabled="busy"
        />
      </div>
      <div class="collection-cards-heading">
        <span class="label">Карточки · {{ cards.length }}</span
        ><button class="btn btn-secondary" type="button" :disabled="busy" @click="picker = true">
          <Plus :size="16" /> Добавить
        </button>
      </div>
      <div v-if="cards.length" class="collection-cards">
        <div v-for="(card, index) in cards" :key="card.id" class="collection-row">
          <span class="row-number">{{ index + 1 }}</span
          ><img :src="card.thumbnail_url || card.image_url" :alt="card.name" loading="lazy" />
          <div class="collection-card-name">
            <strong>{{ card.name }}</strong
            ><span v-if="card.archived_at" class="badge">В архиве</span>
          </div>
          <div class="row-buttons">
            <button
              class="icon-btn"
              type="button"
              :disabled="busy || index === 0"
              :aria-label="`Переместить выше: ${card.name}`"
              @click="move(index, -1)"
            >
              <ChevronUp :size="17" /></button
            ><button
              class="icon-btn"
              type="button"
              :disabled="busy || index === cards.length - 1"
              :aria-label="`Переместить ниже: ${card.name}`"
              @click="move(index, 1)"
            >
              <ChevronDown :size="17" /></button
            ><button
              class="icon-btn"
              type="button"
              :disabled="busy"
              :aria-label="`Убрать из подборки: ${card.name}`"
              @click="cards.splice(index, 1)"
            >
              <X :size="17" />
            </button>
          </div>
        </div>
      </div>
      <div v-else class="empty-state">
        Добавьте асаны из библиотеки. Карточки будут доступны рядом с листом практики.
      </div>
    </form>
    <template #footer
      ><button class="btn btn-secondary" :disabled="busy" @click="$emit('close')">Отмена</button
      ><button
        class="btn btn-primary"
        form="collection-editor"
        type="submit"
        :disabled="busy || !cards.length"
      >
        {{ busy ? 'Сохраняем…' : 'Сохранить подборку' }}
      </button></template
    >
  </Modal>
  <CatalogSelectDialog :open="picker" :existing-ids="ids" @close="picker = false" @selected="add" />
</template>
<style scoped>
.collection-editor {
  display: grid;
  gap: 17px;
}
.collection-cards-heading {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.collection-cards {
  display: grid;
  gap: 6px;
  max-height: 38vh;
  overflow: auto;
}
.collection-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px;
  border: 1px solid #e5ebe1;
  border-radius: 12px;
}
.row-number {
  font-size: 0.75rem;
  color: #81907c;
  min-width: 17px;
}
.collection-row > img {
  width: 48px;
  height: 46px;
  object-fit: contain;
  flex: none;
}
.collection-card-name {
  display: flex;
  flex-direction: column;
  gap: 4px;
  flex: 1;
  min-width: 0;
}
.collection-card-name strong {
  font-size: 0.83rem;
  font-weight: 550;
  overflow-wrap: anywhere;
}
.collection-card-name .badge {
  font-size: 0.68rem;
  align-self: flex-start;
}
.row-buttons {
  display: flex;
  gap: 0;
}
.row-buttons .icon-btn {
  width: 29px;
  height: 34px;
}
@media (max-width: 430px) {
  .collection-row {
    gap: 6px;
    padding: 6px;
  }
  .collection-row > img {
    width: 33px;
  }
  .row-number {
    display: none;
  }
  .row-buttons .icon-btn {
    width: 26px;
  }
}
</style>
