<script setup lang="ts">
import PageHeading from '@/shared/PageHeading.vue';
import AppSelect from '@/shared/AppSelect.vue';
import { onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import AppShell from '@/shared/AppShell.vue';
import { api } from '@/shared/api';
interface Sketch {
  id: string;
  title: string;
  revision: number;
  image_url: string | null;
  archived_at: string | null;
}
const rows = ref<Sketch[]>([]),
  q = ref(''),
  archive = ref('active'),
  error = ref(''),
  busy = ref(false),
  title = ref(''),
  format = ref('square'),
  page = ref(1),
  last = ref(1);
async function load() {
  error.value = '';
  try {
    const result = await api<{ data: Sketch[]; last_page: number }>(
      `/api/sketchbook?q=${encodeURIComponent(q.value)}&archive=${archive.value}&page=${page.value}`,
    );
    rows.value = result.data;
    last.value = result.last_page;
  } catch (f) {
    error.value = f instanceof Error ? f.message : 'Ошибка загрузки';
  }
}
async function create() {
  if (!title.value.trim() || busy.value) return;
  busy.value = true;
  try {
    const result = await api<{ data: Sketch }>('/api/sketchbook', {
      method: 'POST',
      body: JSON.stringify({ title: title.value, page_format: format.value }),
    });
    router.visit(`/sketchbook/${result.data.id}`);
  } catch (f) {
    error.value = f instanceof Error ? f.message : 'Ошибка создания';
  } finally {
    busy.value = false;
  }
}
async function act(row: Sketch, action: 'copy' | 'archive') {
  try {
    await api(`/api/sketchbook/${row.id}/${action}`, {
      method: 'POST',
      body: JSON.stringify({ expected_revision: row.revision, restore: !!row.archived_at }),
    });
    await load();
  } catch (f) {
    error.value = f instanceof Error ? f.message : 'Ошибка';
  }
}
onMounted(load);
</script>
<template>
  <AppShell section="sketchbook"
    ><Head title="Скетчбук" />
    <PageHeading
      eyebrow="Идеи в движении"
      title="Скетчбук"
      description="Рисуйте свои асаны и целые строки на свободном листе. Исходники остаются редактируемыми."
    />
    <form class="toolbar sketch-create card" @submit.prevent="create">
      <input
        class="input"
        v-model="title"
        placeholder="Название нового листа"
        aria-label="Название нового листа"
        required
        maxlength="160"
      /><AppSelect class="select" v-model="format" aria-label="Формат">
        <option value="square">Свободный квадратный лист</option>
        <option value="a4-portrait">A4 — книжная печать</option>
        <option value="a4-landscape">A4 — альбомная печать</option></AppSelect
      ><button class="btn btn-primary" :disabled="busy">Создать лист</button>
    </form>
    <form
      class="toolbar sketch-search"
      @submit.prevent="
        page = 1;
        load();
      "
    >
      <input class="input" v-model="q" placeholder="Поиск листов" aria-label="Поиск листов" /><AppSelect
        class="select"
        v-model="archive"
        aria-label="Архив"
        @change="
          page = 1;
          load();
        "
      >
        <option value="active">Активные</option>
        <option value="archived">В архиве</option></AppSelect
      ><button class="btn btn-secondary">Найти</button>
    </form>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <div class="sketch-grid">
      <article v-for="row in rows" :key="row.id" class="card">
        <Link :href="`/sketchbook/${row.id}`"
          ><img v-if="row.image_url" :src="row.image_url" :alt="row.title" />
          <div v-else class="blank">
            <Pencil :size="28" :stroke-width="1.4" aria-hidden="true" /><span>Пустой лист</span>
          </div>
          <h2>{{ row.title }}</h2></Link
        >
        <div class="toolbar">
          <button class="btn btn-secondary" @click="act(row, 'copy')">Копия</button
          ><button class="btn btn-ghost" @click="act(row, 'archive')">
            {{ row.archived_at ? 'Восстановить' : 'В архив' }}
          </button>
        </div>
      </article>
    </div>
    <p v-if="!rows.length" class="empty-state card">Листов пока нет. Создайте первый или измените поиск.</p>
    <div class="toolbar sketch-pagination">
      <button
        class="btn btn-secondary"
        :disabled="page <= 1"
        @click="
          page--;
          load();
        "
      >
        Назад</button
      ><span>{{ page }} / {{ last }}</span
      ><button
        class="btn btn-secondary"
        :disabled="page >= last"
        @click="
          page++;
          load();
        "
      >
        Далее
      </button>
    </div></AppShell
  >
</template>
<style scoped>
.toolbar {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  align-items: center;
  margin: 16px 0;
}
.toolbar input {
  flex: 1;
  min-width: 0;
}
.sketch-create,
.sketch-search {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(180px, 280px) auto;
  gap: 12px;
}
.sketch-create {
  padding: var(--panel-padding);
  margin-bottom: 24px;
  background: linear-gradient(150deg, #f5f8ee, #fff 65%);
}
.sketch-search {
  grid-template-columns: minmax(0, 1fr) 180px auto;
  margin-bottom: 24px;
}
.sketch-pagination {
  justify-content: center;
  color: var(--muted);
  margin-top: 28px;
}
.sketch-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 240px), 1fr));
  gap: var(--section-gap);
}
.sketch-grid article {
  background: white;
  padding: var(--panel-padding);
  border: 1px solid var(--line);
  border-radius: var(--panel-radius);
  min-width: 0;
  box-shadow: var(--panel-shadow);
}
.sketch-grid img,
.blank {
  width: 100%;
  height: 210px;
  object-fit: contain;
  background: white;
}
.blank {
  display: grid;
  place-content: center;
  justify-items: center;
  gap: 12px;
  background: linear-gradient(155deg, #f3f7eb, #fcfdf9);
  border: 1px solid #e5eadf;
  border-radius: 17px;
  color: #718366;
  font-size: 12px;
}
.sketch-grid a {
  color: inherit;
  text-decoration: none;
}
.sketch-grid h2 {
  font-size: 19px;
  margin-top: 16px;
  overflow-wrap: anywhere;
  font-weight: 550;
  color: #29492f;
  letter-spacing: -0.025em;
}
.sketch-grid .toolbar {
  margin-bottom: 0;
}
@media (max-width: 700px) {
  .sketch-create,
  .sketch-search {
    grid-template-columns: minmax(0, 1fr);
  }
  .sketch-create {
    padding: var(--panel-padding);
  }
  .sketch-create > .btn,
  .sketch-search > .btn {
    width: 100%;
  }
}
</style>
