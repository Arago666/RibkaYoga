<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, CalendarDays, Plus, Archive, RotateCcw } from '@lucide/vue';
import AppShell from '@/shared/AppShell.vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import type { Page } from '@/modules/catalog/types';
interface Cycle {
  id: string;
  title: string;
  theme: string | null;
  revision: number;
  archived_at: string | null;
  cloud_asana_ids: string[];
}
interface Source {
  id: string;
  title: string;
  kind: string;
}
const result = ref<Page<Cycle> | null>(null),
  loading = ref(false),
  error = ref(''),
  query = ref(''),
  archive = ref('active'),
  page = ref(1);
const createOpen = ref(false),
  title = ref(''),
  theme = ref(''),
  base = ref(''),
  sources = ref<Source[]>([]),
  busy = ref(false);
async function load() {
  loading.value = true;
  error.value = '';
  try {
    result.value = await api<Page<Cycle>>(
      `/api/mesocycles?${new URLSearchParams({ q: query.value, archive: archive.value, page: String(page.value) })}`,
    );
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось открыть мезоциклы.';
  } finally {
    loading.value = false;
  }
}
async function openCreate() {
  title.value = '';
  theme.value = '';
  base.value = '';
  createOpen.value = true;
  busy.value = true;
  error.value = '';
  try {
    const loaded: Source[] = [];
    for (const kind of ['practice', 'template']) {
      let p = 1,
        last = 1;
      do {
        const response = await api<Page<Source>>(`/api/practices?kind=${kind}&per_page=100&page=${p}`);
        loaded.push(...response.data);
        last = response.last_page;
        p++;
      } while (p <= last);
    }
    sources.value = loaded;
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось загрузить основу.';
  } finally {
    busy.value = false;
  }
}
async function create() {
  if (busy.value || !title.value.trim()) return;
  busy.value = true;
  error.value = '';
  try {
    const response = await api<{ data: Cycle }>('/api/mesocycles', {
      method: 'POST',
      body: JSON.stringify({
        title: title.value.trim(),
        theme: theme.value.trim() || null,
        base_document_id: base.value || null,
      }),
    });
    router.visit(`/mesocycles/${response.data.id}/edit`);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось создать мезоцикл.';
  } finally {
    busy.value = false;
  }
}
async function changeArchive(item: Cycle) {
  if (
    busy.value ||
    (!item.archived_at &&
      !window.confirm(`Переместить «${item.title}» в архив? Пять практик останутся в списке практик.`))
  )
    return;
  busy.value = true;
  try {
    await api(`/api/mesocycles/${item.id}/${item.archived_at ? 'restore' : 'archive'}`, {
      method: 'POST',
      body: JSON.stringify({ expected_revision: item.revision }),
    });
    await load();
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось выполнить действие.';
  } finally {
    busy.value = false;
  }
}
onMounted(load);
</script>
<template>
  <Head title="Мезоциклы" />
  <AppShell section="practices">
    <Link class="btn btn-ghost" href="/practices"><ArrowLeft :size="16" />Все практики</Link>
    <header class="page-header">
      <div>
        <h1 class="page-title">Мезоциклы</h1>
        <p class="muted">Тема месяца, общий набор асан и пять независимых практик по неделям.</p>
      </div>
      <button class="btn btn-primary" @click="openCreate"><Plus :size="18" />Создать мезоцикл</button>
    </header>
    <form
      class="cycle-filters"
      @submit.prevent="
        page = 1;
        load();
      "
    >
      <input
        v-model="query"
        class="input"
        placeholder="Найти по названию или теме"
        aria-label="Поиск мезоциклов"
      /><select
        v-model="archive"
        class="select"
        @change="
          page = 1;
          load();
        "
      >
        <option value="active">Активные</option>
        <option value="archived">В архиве</option></select
      ><button class="btn btn-secondary">Найти</button>
    </form>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <p v-if="loading" class="muted" role="status">Загружаем мезоциклы…</p>
    <div v-else-if="result?.data.length" class="cycle-grid">
      <article v-for="item in result.data" :key="item.id" class="card cycle-card">
        <CalendarDays :size="28" />
        <h2>{{ item.title }}</h2>
        <p>{{ item.theme || 'Без темы' }}</p>
        <small>5 недель · {{ item.cloud_asana_ids.length }} асан в облаке</small
        ><Link v-if="!item.archived_at" class="btn btn-primary" :href="`/mesocycles/${item.id}/edit`"
          >Открыть пять практик</Link
        ><button class="btn btn-ghost" :disabled="busy" @click="changeArchive(item)">
          <RotateCcw v-if="item.archived_at" :size="16" /><Archive v-else :size="16" />{{
            item.archived_at ? 'Восстановить' : 'В архив'
          }}
        </button>
      </article>
    </div>
    <div v-else class="empty-state">
      <h2>{{ archive === 'archived' ? 'Нет удаленных элементов.' : 'Мезоциклов пока нет' }}</h2>
      <button v-if="archive === 'active'" class="btn btn-primary" @click="openCreate">
        Создать первый мезоцикл
      </button>
    </div>
    <div v-if="result && result.last_page > 1" class="cycle-filters">
      <button
        class="btn btn-secondary"
        :disabled="page <= 1 || loading"
        @click="
          page--;
          load();
        "
      >
        Назад</button
      ><span>{{ page }} / {{ result.last_page }}</span
      ><button
        class="btn btn-secondary"
        :disabled="page >= result.last_page || loading"
        @click="
          page++;
          load();
        "
      >
        Далее
      </button>
    </div>
    <Modal :open="createOpen" title="Новый мезоцикл" @close="!busy && (createOpen = false)"
      ><p class="muted">
        Создадим пять самостоятельных практик. Изменение основы после создания не изменит недели.
      </p>
      <p v-if="error" class="error-banner">{{ error }}</p>
      <label class="field"
        ><span class="label">Название</span
        ><input v-model="title" class="input" maxlength="160" placeholder="Мой месяц практики" /></label
      ><label class="field"
        ><span class="label">Тема месяца</span><input v-model="theme" class="input" maxlength="240" /></label
      ><label class="field"
        ><span class="label">Базовая последовательность для всех пяти недель</span
        ><select v-model="base" class="select" :disabled="busy">
          <option value="">Пустые практики</option>
          <option v-for="source in sources" :key="source.id" :value="source.id">
            {{ source.kind === 'template' ? 'Шаблон' : 'Практика' }}: {{ source.title }}
          </option>
        </select></label
      ><template #footer
        ><button class="btn btn-secondary" :disabled="busy" @click="createOpen = false">Отмена</button
        ><button class="btn btn-primary" :disabled="busy || !title.trim()" @click="create">
          {{ busy ? 'Создаём…' : 'Создать пять недель' }}
        </button></template
      ></Modal
    >
  </AppShell>
</template>
<style scoped>
.cycle-filters {
  display: flex;
  gap: 12px;
  align-items: center;
  margin: 24px 0;
}
.cycle-filters .input {
  max-width: 500px;
}
.cycle-filters .select {
  max-width: 180px;
}
.cycle-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(270px, 1fr));
  gap: 20px;
}
.cycle-card {
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.cycle-card h2 {
  font-size: 20px;
}
.cycle-card p,
.cycle-card small {
  color: var(--muted);
}
@media (max-width: 600px) {
  .cycle-filters {
    flex-wrap: wrap;
  }
}
</style>
