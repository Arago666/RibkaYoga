<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Check, Folder } from '@lucide/vue';
import Modal from '@/shared/Modal.vue';
import { ApiError, api } from '@/shared/api';

interface Category {
  id: string;
  name: string;
  revision: number;
}

const props = defineProps<{
  practice: {
    id: string;
    title: string;
    revision: number;
    folders?: { id: string; name: string }[];
  };
}>();
const emit = defineEmits<{ close: []; changed: [] }>();

const categories = ref<Category[] | null>(null);
const assigned = ref(new Set(props.practice.folders?.map(({ id }) => id)));
const loading = ref(true);
const savingId = ref<string | null>(null);
const stale = ref(false);
const error = ref('');
const notice = ref('');

async function load() {
  loading.value = true;
  error.value = '';
  try {
    categories.value = (await api<{ data: Category[] }>('/api/practice-folders')).data;
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Не удалось загрузить категории.';
  } finally {
    loading.value = false;
  }
}

async function changeCategory(category: Category) {
  if (savingId.value || stale.value) return;
  const remove = assigned.value.has(category.id);
  savingId.value = category.id;
  error.value = '';
  notice.value = '';
  try {
    const { data } = await api<{ data: Category }>(`/api/practice-folders/${category.id}/documents`, {
      method: 'POST',
      body: JSON.stringify({
        expected_revision: category.revision,
        documents: [{ id: props.practice.id, expected_revision: props.practice.revision }],
        remove,
      }),
    });
    categories.value = categories.value?.map((item) => (item.id === data.id ? data : item)) ?? null;
    if (remove) assigned.value.delete(category.id);
    else assigned.value.add(category.id);
    notice.value = remove
      ? `Практика убрана из категории «${category.name}».`
      : `Практика добавлена в категорию «${category.name}».`;
    emit('changed');
  } catch (exception) {
    if (exception instanceof ApiError && [404, 409].includes(exception.status)) {
      stale.value = true;
      error.value = 'Категория или практика изменилась. Откройте окно заново.';
      emit('changed');
    } else {
      error.value = exception instanceof Error ? exception.message : 'Не удалось изменить категорию.';
    }
  } finally {
    savingId.value = null;
  }
}

onMounted(load);
</script>

<template>
  <Modal :open="true" title="Категории практики" @close="!savingId && emit('close')">
    <div class="assignment">
      <p class="assignment-title">{{ practice.title }}</p>
      <p v-if="loading" class="muted" role="status">Загружаем категории…</p>
      <template v-else-if="categories?.length">
        <p class="muted">Одну практику можно добавить в несколько категорий. Изменения сохраняются сразу.</p>
        <ul class="assignment-list" aria-label="Личные категории">
          <li v-for="category in categories" :key="category.id">
            <button
              type="button"
              class="assignment-row"
              :class="{ assigned: assigned.has(category.id) }"
              :aria-label="`${assigned.has(category.id) ? 'Убрать из категории' : 'Добавить в категорию'} «${category.name}»`"
              :aria-disabled="!!savingId || stale"
              @click="changeCategory(category)"
            >
              <span class="section-icon assignment-icon" aria-hidden="true">
                <Check v-if="assigned.has(category.id)" :size="18" />
                <Folder v-else :size="18" />
              </span>
              <span class="assignment-name">{{ category.name }}</span>
              <span class="assignment-action" aria-hidden="true">
                {{
                  savingId === category.id ? 'Сохраняем…' : assigned.has(category.id) ? 'Убрать' : 'Добавить'
                }}
              </span>
            </button>
          </li>
        </ul>
      </template>
      <template v-else-if="categories">
        <p class="muted">Пока нет активных категорий.</p>
        <Link href="/practice-folders" class="btn btn-secondary assignment-link">Открыть категории</Link>
      </template>
      <div v-if="error" class="error-banner" role="alert">
        {{ error }}
        <button v-if="!categories && !loading" type="button" class="btn btn-ghost" @click="load">
          Повторить
        </button>
      </div>
      <div v-if="notice" class="success-banner" role="status">{{ notice }}</div>
    </div>
    <template #footer>
      <button type="button" class="btn btn-primary" :disabled="!!savingId" @click="emit('close')">
        Готово
      </button>
    </template>
  </Modal>
</template>

<style scoped>
.assignment {
  display: grid;
  gap: 12px;
}
.assignment p {
  margin: 0;
  overflow-wrap: anywhere;
}
.assignment-title {
  font-size: 1.05rem;
  font-weight: 650;
}
.assignment-list {
  display: grid;
  gap: 8px;
  list-style: none;
  margin: 4px 0 0;
  padding: 0;
}
.assignment-row {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
  width: 100%;
  min-height: 52px;
  padding: 7px 12px;
  border: 1px solid var(--line);
  border-radius: var(--control-radius);
  background: var(--surface);
  color: var(--text);
  text-align: start;
}
.assignment-row.assigned,
.assignment-row:hover:not([aria-disabled='true']) {
  background: var(--accent-soft);
  border-color: color-mix(in srgb, var(--accent) 22%, var(--line));
}
.assignment-row[aria-disabled='true'] {
  cursor: default;
}
.assignment-icon {
  width: 34px;
  height: 34px;
  border-radius: 50%;
}
.assignment-name {
  font-weight: 600;
  overflow-wrap: anywhere;
}
.assignment-action {
  color: var(--accent-dark);
  font-size: 0.875rem;
}
.assignment-link {
  justify-self: start;
}
</style>
