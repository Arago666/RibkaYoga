<script setup lang="ts">
import { ref, watch } from 'vue';
import { Plus, Pencil, Trash2, X, Check } from '@lucide/vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import { errorText, type Category } from '../types';

const props = defineProps<{ open: boolean; categories: Category[] }>();
const emit = defineEmits<{ close: []; changed: [] }>();
const name = ref('');
const error = ref('');
const editing = ref<string | null>(null);
const editName = ref('');
const busy = ref(false);

watch(
  () => props.open,
  (open) => {
    if (!open) return;
    name.value = '';
    error.value = '';
    editing.value = null;
  },
);

async function create() {
  if (busy.value || !name.value.trim()) return;
  busy.value = true;
  error.value = '';
  try {
    await api('/api/categories', { method: 'POST', body: JSON.stringify({ name: name.value.trim() }) });
    name.value = '';
    emit('changed');
  } catch (failure) {
    error.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}

function startEdit(item: Category) {
  editing.value = item.id;
  editName.value = item.name;
  error.value = '';
}

async function save(item: Category) {
  if (busy.value || !editName.value.trim()) return;
  busy.value = true;
  error.value = '';
  try {
    await api(`/api/categories/${item.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ name: editName.value.trim(), expected_revision: item.revision }),
    });
    editing.value = null;
    emit('changed');
  } catch (failure) {
    error.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}

async function remove(item: Category) {
  if (
    busy.value ||
    !window.confirm(
      `Удалить категорию «${item.name}»? Если она назначена карточкам, сначала снимите её с них. Сами карточки сохранятся.`,
    )
  )
    return;
  busy.value = true;
  error.value = '';
  try {
    await api(`/api/categories/${item.id}`, {
      method: 'DELETE',
      body: JSON.stringify({ expected_revision: item.revision, confirm: true }),
    });
    if (editing.value === item.id) editing.value = null;
    emit('changed');
  } catch (failure) {
    error.value = errorText(failure);
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <Modal :open="open" title="Категории" @close="!busy && $emit('close')">
    <p class="muted">Объединяйте похожие асаны, чтобы быстрее находить нужные карточки.</p>
    <div v-if="error" class="error-banner" role="alert">{{ error }}</div>
    <form class="taxonomy-create" @submit.prevent="create">
      <label class="sr-only" for="taxonomy-name">Название категории</label>
      <input
        id="taxonomy-name"
        v-model="name"
        class="input"
        required
        maxlength="100"
        placeholder="Название категории"
        :disabled="busy"
      />
      <button class="btn btn-primary" :disabled="busy || !name.trim()" type="submit">
        <Plus :size="17" /> Добавить
      </button>
    </form>
    <div class="taxonomy-list">
      <div v-for="item in categories" :key="item.id" class="taxonomy-item">
        <form v-if="editing === item.id" class="taxonomy-edit" @submit.prevent="save(item)">
          <input
            v-model="editName"
            class="input"
            required
            maxlength="100"
            aria-label="Новое название категории"
            :disabled="busy"
          />
          <button
            class="icon-btn"
            type="submit"
            :disabled="busy || !editName.trim()"
            aria-label="Сохранить название"
          >
            <Check :size="18" />
          </button>
          <button class="icon-btn" type="button" :disabled="busy" aria-label="Отмена" @click="editing = null">
            <X :size="18" />
          </button>
        </form>
        <template v-else>
          <span class="category-name">{{ item.name }}</span>
          <div class="taxonomy-actions">
            <button
              class="icon-btn"
              :disabled="busy"
              type="button"
              :aria-label="`Изменить ${item.name}`"
              @click="startEdit(item)"
            >
              <Pencil :size="17" />
            </button>
            <button
              class="icon-btn"
              :disabled="busy"
              type="button"
              :aria-label="`Удалить ${item.name}`"
              @click="remove(item)"
            >
              <Trash2 :size="17" />
            </button>
          </div>
        </template>
      </div>
      <p v-if="!categories.length" class="empty-state">Категорий пока нет. Добавьте первую выше.</p>
    </div>
    <template #footer
      ><button class="btn btn-secondary" type="button" :disabled="busy" @click="$emit('close')">
        Готово
      </button></template
    >
  </Modal>
</template>

<style scoped>
.taxonomy-create {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin: 18px 0;
}
.taxonomy-create .input {
  flex: 1;
  min-width: 170px;
}
.taxonomy-list {
  display: grid;
  gap: 6px;
  max-height: 42vh;
  overflow: auto;
}
.taxonomy-item {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: center;
  padding: 9px 8px;
  border-bottom: 1px solid #edf0eb;
}
.category-name {
  min-width: 0;
  overflow-wrap: anywhere;
}
.taxonomy-actions {
  display: flex;
  flex-shrink: 0;
  gap: 3px;
}
.taxonomy-edit {
  display: flex;
  gap: 5px;
  align-items: center;
  width: 100%;
  flex-wrap: wrap;
}
.taxonomy-edit .input {
  flex: 1;
  min-width: 140px;
}
</style>
