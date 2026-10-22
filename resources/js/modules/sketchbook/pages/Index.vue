<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
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
    <h1>Скетчбук</h1>
    <p>Рисуйте свои асаны и целые строки на свободном листе. Исходники остаются редактируемыми.</p>
    <form class="toolbar" @submit.prevent="create">
      <input v-model="title" placeholder="Название нового листа" required maxlength="160" /><select
        v-model="format"
        aria-label="Формат"
      >
        <option value="square">Свободный квадратный лист</option>
        <option value="a4-portrait">A4 — книжная печать</option>
        <option value="a4-landscape">A4 — альбомная печать</option></select
      ><button class="btn primary" :disabled="busy">Создать лист</button>
    </form>
    <form
      class="toolbar"
      @submit.prevent="
        page = 1;
        load();
      "
    >
      <input v-model="q" placeholder="Поиск листов" /><select
        v-model="archive"
        aria-label="Архив"
        @change="
          page = 1;
          load();
        "
      >
        <option value="active">Активные</option>
        <option value="archived">В архиве</option></select
      ><button class="btn">Найти</button>
    </form>
    <p v-if="error" role="alert">{{ error }}</p>
    <div class="sketch-grid">
      <article v-for="row in rows" :key="row.id">
        <Link :href="`/sketchbook/${row.id}`"
          ><img v-if="row.image_url" :src="row.image_url" :alt="row.title" />
          <div v-else class="blank">Пустой лист</div>
          <h2>{{ row.title }}</h2></Link
        >
        <div class="toolbar">
          <button class="btn" @click="act(row, 'copy')">Копия</button
          ><button class="btn" @click="act(row, 'archive')">
            {{ row.archived_at ? 'Восстановить' : 'В архив' }}
          </button>
        </div>
      </article>
    </div>
    <p v-if="!rows.length">Листов пока нет. Создайте первый или измените поиск.</p>
    <div class="toolbar">
      <button
        class="btn"
        :disabled="page <= 1"
        @click="
          page--;
          load();
        "
      >
        Назад</button
      ><span>{{ page }} / {{ last }}</span
      ><button
        class="btn"
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
  min-width: 180px;
}
.sketch-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 20px;
}
.sketch-grid article {
  background: white;
  padding: 16px;
  border: 1px solid #e2e8dd;
  border-radius: 18px;
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
  place-items: center;
  color: #788371;
}
.sketch-grid a {
  color: inherit;
  text-decoration: none;
}
.sketch-grid h2 {
  font-size: 1.1rem;
}
</style>
