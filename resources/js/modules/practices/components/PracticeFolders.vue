<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { api } from '@/shared/api';
interface Folder {
  id: string;
  name: string;
  revision: number;
  sort_order: number;
  archived_at: string | null;
  documents_count?: number;
}
const props = withDefaults(
  defineProps<{ documents?: { id: string; revision: number }[]; compact?: boolean }>(),
  { documents: () => [], compact: false },
);
const emit = defineEmits<{ changed: [] }>();
const items = ref<Folder[]>([]),
  name = ref(''),
  busy = ref(false),
  error = ref(''),
  archived = ref(false);
async function load() {
  try {
    items.value = (
      await api<{ data: Folder[] }>(`/api/practice-folders?archived=${archived.value ? 1 : 0}`)
    ).data;
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось загрузить категории.';
  }
}
async function create() {
  if (busy.value || !name.value.trim()) return;
  busy.value = true;
  error.value = '';
  try {
    await api('/api/practice-folders', { method: 'POST', body: JSON.stringify({ name: name.value.trim() }) });
    name.value = '';
    await load();
    emit('changed');
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось создать категорию.';
  } finally {
    busy.value = false;
  }
}
async function change(folder: Folder, values: Record<string, unknown>) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await api(`/api/practice-folders/${folder.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ expected_revision: folder.revision, ...values }),
    });
    await load();
    emit('changed');
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось изменить категорию.';
  } finally {
    busy.value = false;
  }
}
function rename(folder: Folder) {
  const value = window.prompt('Название категории', folder.name);
  if (value?.trim()) void change(folder, { name: value.trim() });
}
async function assign(folder: Folder, remove = false) {
  if (busy.value || !props.documents.length) return;
  busy.value = true;
  error.value = '';
  try {
    await api(`/api/practice-folders/${folder.id}/documents`, {
      method: 'POST',
      body: JSON.stringify({
        expected_revision: folder.revision,
        documents: props.documents.map((document) => ({
          id: document.id,
          expected_revision: document.revision,
        })),
        remove,
      }),
    });
    await load();
    emit('changed');
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось назначить категории.';
  } finally {
    busy.value = false;
  }
}
onMounted(load);
</script>
<template>
  <section aria-label="Личные категории практик">
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <form class="folder-create" @submit.prevent="create">
      <input
        v-model="name"
        class="input"
        required
        maxlength="160"
        placeholder="Новая личная категория"
        aria-label="Название категории"
      /><button class="btn btn-secondary" :disabled="busy || !name.trim()">Создать</button>
    </form>
    <label class="check-label"
      ><input v-model="archived" type="checkbox" @change="load" />Показывать архив категорий</label
    >
    <p v-if="documents.length" class="muted">
      Выбрано практик: {{ documents.length }}. Добавление в другую категорию не создаёт копию.
    </p>
    <div v-for="(folder, index) in items" :key="folder.id" class="folder-row">
      <Link :href="`/practices?folder=${folder.id}`"
        >{{ folder.name }} <small>({{ folder.documents_count || 0 }})</small></Link
      >
      <div class="folder-actions">
        <template v-if="documents.length && !folder.archived_at"
          ><button class="btn btn-primary" :disabled="busy" @click="assign(folder)">Добавить выбранные</button
          ><button class="btn btn-ghost" :disabled="busy" @click="assign(folder, true)">
            Убрать выбранные
          </button></template
        ><template v-if="!compact"
          ><button class="btn btn-ghost" :disabled="busy" @click="rename(folder)">Переименовать</button
          ><button
            class="btn btn-ghost"
            :disabled="busy || index === 0"
            @click="change(folder, { sort_order: Math.max(0, (items[index - 1]?.sort_order || 0) - 1) })"
            aria-label="Выше"
          >
            ↑</button
          ><button
            class="btn btn-ghost"
            :disabled="busy || index === items.length - 1"
            @click="change(folder, { sort_order: (items[index + 1]?.sort_order || 0) + 1 })"
            aria-label="Ниже"
          >
            ↓</button
          ><button
            class="btn btn-ghost"
            :disabled="busy"
            @click="change(folder, { archived: !folder.archived_at })"
          >
            {{ folder.archived_at ? 'Восстановить' : 'В архив' }}
          </button></template
        >
      </div>
    </div>
    <p v-if="!items.length" class="muted">Категорий пока нет. Например: «Для начинающих» или «Вечерние».</p>
  </section>
</template>
<style scoped>
.folder-create,
.folder-row,
.folder-actions {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
}
.folder-create {
  margin: 18px 0;
}
.folder-create .input {
  flex: 1;
  min-width: 180px;
}
.folder-row {
  padding: 14px 0;
  border-bottom: 1px solid #e7eee3;
  justify-content: space-between;
}
.folder-row > a {
  font-weight: 600;
}
.folder-row small {
  color: var(--muted);
}
</style>
