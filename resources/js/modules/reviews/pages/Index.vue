<script setup lang="ts">
import AppSelect from '@/shared/AppSelect.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Plus, Search, ScanText, ArrowUpRight } from '@lucide/vue';
import AppShell from '@/shared/AppShell.vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import { uid } from '@/shared/uuid';
import type { Review, ReviewSource, ReviewSummary } from '../types';

const items = ref<ReviewSummary[]>([]);
const listPage = ref(1),
  lastListPage = ref(1),
  total = ref(0);
const loading = ref(true),
  error = ref(''),
  query = ref('');
const picker = ref(false),
  sourceQuery = ref(''),
  scope = ref('all');
const sources = ref<ReviewSource[]>([]),
  sourcePage = ref(1),
  lastSourcePage = ref(1);
const sourceLoading = ref(false),
  sourceError = ref(''),
  creating = ref(false);
let sourceSerial = 0;
let listSerial = 0,
  searchTimer: ReturnType<typeof setTimeout> | undefined;
let pendingCreate: { source: string; revision: number; id: string } | null = null;
async function load() {
  const serial = ++listSerial;
  loading.value = true;
  error.value = '';
  try {
    const response = await api<{ data: ReviewSummary[]; last_page: number; total: number }>(
      `/api/practice-reviews?${new URLSearchParams({ q: query.value, page: String(listPage.value) })}`,
    );
    if (serial !== listSerial) return;
    items.value = response.data;
    lastListPage.value = response.last_page;
    total.value = response.total;
  } catch (failure) {
    if (serial === listSerial)
      error.value = failure instanceof Error ? failure.message : 'Не удалось открыть разборы.';
  } finally {
    if (serial === listSerial) loading.value = false;
  }
}
async function loadSources() {
  const serial = ++sourceSerial;
  sourceLoading.value = true;
  sourceError.value = '';
  try {
    const response = await api<{ data: ReviewSource[]; last_page: number }>(
      `/api/practice-reviews/sources?${new URLSearchParams({ q: sourceQuery.value, scope: scope.value, page: String(sourcePage.value) })}`,
    );
    if (serial !== sourceSerial) return;
    sources.value = response.data;
    lastSourcePage.value = response.last_page;
  } catch (failure) {
    if (serial === sourceSerial)
      sourceError.value = failure instanceof Error ? failure.message : 'Не удалось загрузить практики.';
  } finally {
    if (serial === sourceSerial) sourceLoading.value = false;
  }
}
async function create(source: ReviewSource) {
  if (creating.value) return;
  creating.value = true;
  sourceError.value = '';
  if (pendingCreate?.source !== source.id || pendingCreate.revision !== source.revision)
    pendingCreate = { source: source.id, revision: source.revision, id: uid() };
  try {
    const result = await api<{ data: Review }>('/api/practice-reviews', {
      method: 'POST',
      body: JSON.stringify({
        id: pendingCreate.id,
        document_id: source.id,
        expected_document_revision: source.revision,
      }),
    });
    router.visit(`/practice-reviews/${result.data.id}`);
  } catch (failure) {
    sourceError.value = failure instanceof Error ? failure.message : 'Не удалось создать разбор.';
  } finally {
    creating.value = false;
  }
}
watch(picker, (open) => {
  if (open) {
    sourceQuery.value = '';
    scope.value = 'all';
    sourcePage.value = 1;
    void loadSources();
  } else sourceSerial++;
});
onMounted(load);
watch(query, () => {
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    listPage.value = 1;
    void load();
  }, 300);
});
onBeforeUnmount(() => {
  listSerial++;
  sourceSerial++;
  if (searchTimer) clearTimeout(searchTimer);
});
</script>
<template>
  <Head title="Разбор практики" />
  <AppShell section="reviews">
    <header class="page-header">
      <div>
        <p class="eyebrow">Внимание к деталям</p>
        <h1 class="page-title">Разбор практики</h1>
        <p class="page-description">Комментарии, цветные группы и связи между асанами на отдельном листе.</p>
      </div>
      <button class="btn btn-primary" @click="picker = true"><Plus :size="18" />Открыть практику</button>
    </header>
    <p v-if="error" class="error-banner" role="alert">
      {{ error }} <button class="btn" @click="load">Повторить</button>
    </p>
    <div class="card review-search">
      <Search :size="19" /><input
        v-model="query"
        class="input"
        aria-label="Поиск разборов"
        placeholder="Найти разбор по названию"
      /><span class="muted">{{ total }} разборов</span>
    </div>
    <p v-if="loading" class="muted" role="status">Загружаем разборы…</p>
    <div v-else-if="items.length" class="review-list">
      <article v-for="item in items" :key="item.id" class="card review-card">
        <ScanText :size="28" />
        <h2>{{ item.title }}</h2>
        <p class="muted">
          {{ item.comments_count ?? 0 }} комментариев · {{ item.groups_count ?? 0 }} групп ·
          {{ item.arrows_count ?? 0 }} стрелок
        </p>
        <p v-if="item.current_source_revision !== item.source_revision" class="source-note">
          Исходная практика обновлена. Ваш лист сохранён.
        </p>
        <Link class="btn" :href="`/practice-reviews/${item.id}`"
          >Открыть разбор<ArrowUpRight :size="17"
        /></Link>
      </article>
    </div>
    <section v-else class="card empty-reviews">
      <ScanText :size="38" />
      <h2>{{ query ? 'Разборов по запросу нет' : 'Первый разбор начинается с практики' }}</h2>
      <p class="muted">
        Выберите свою практику или ту, к которой вам открыли доступ. Рисунки и последовательность останутся
        без изменений.
      </p>
      <button v-if="!query" class="btn" @click="picker = true">Выбрать практику</button>
    </section>
    <div v-if="lastListPage > 1" class="source-pagination">
      <button
        class="btn"
        :disabled="listPage === 1 || loading"
        @click="
          listPage--;
          load();
        "
      >
        Назад</button
      ><span>{{ listPage }} / {{ lastListPage }}</span
      ><button
        class="btn"
        :disabled="listPage === lastListPage || loading"
        @click="
          listPage++;
          load();
        "
      >
        Далее
      </button>
    </div>
    <Modal :open="picker" title="Выберите практику для разбора" @close="!creating && (picker = false)">
      <p class="muted">Пометки сохраняются в вашем личном разборе. Исходная практика не меняется.</p>
      <form
        class="source-search"
        @submit.prevent="
          sourcePage = 1;
          loadSources();
        "
      >
        <input
          v-model="sourceQuery"
          class="input"
          aria-label="Поиск доступных практик"
          placeholder="Название практики"
        />
        <AppSelect
          v-model="scope"
          class="select"
          aria-label="Источник практики для разбора"
          @change="
            sourcePage = 1;
            loadSources();
          "
        >
          <option value="all">Все доступные</option>
          <option value="mine">Мои практики</option>
          <option value="shared">Мне дали доступ</option>
        </AppSelect>
        <button class="btn" :disabled="sourceLoading || creating">Найти</button>
      </form>
      <p v-if="sourceError" class="error-banner" role="alert">{{ sourceError }}</p>
      <p v-if="sourceLoading" class="muted" role="status">Загружаем практики…</p>
      <div v-else class="source-list">
        <button v-for="source in sources" :key="source.id" :disabled="creating" @click="create(source)">
          <span
            ><strong>{{ source.title }}</strong
            ><small>{{ source.scope === 'mine' ? 'Моя практика' : `Доступ от ${source.owner}` }}</small></span
          ><ArrowUpRight :size="19" />
        </button>
        <p v-if="!sources.length" class="muted">Доступных практик по этому запросу нет.</p>
      </div>
      <div v-if="lastSourcePage > 1" class="source-pagination">
        <button
          class="btn"
          :disabled="sourcePage === 1 || sourceLoading || creating"
          @click="
            sourcePage--;
            loadSources();
          "
        >
          Назад</button
        ><span>{{ sourcePage }} / {{ lastSourcePage }}</span
        ><button
          class="btn"
          :disabled="sourcePage === lastSourcePage || sourceLoading || creating"
          @click="
            sourcePage++;
            loadSources();
          "
        >
          Далее
        </button>
      </div>
      <template #footer
        ><button class="btn" :disabled="creating" @click="picker = false">Закрыть</button></template
      >
    </Modal>
  </AppShell>
</template>
<style scoped>
.page-header > div {
  flex: 1 1 360px;
  min-width: 0;
}
.review-search {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: var(--section-gap);
}
.review-search .input {
  flex: 1;
  min-width: 0;
}
.review-search > span {
  font-size: 13px;
  white-space: nowrap;
}
.review-list {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: var(--section-gap);
}
.review-card {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}
.review-card > svg,
.empty-reviews > svg {
  color: var(--accent-dark);
}
.review-card h2,
.empty-reviews h2 {
  font-size: var(--panel-title-size);
  font-weight: 650;
  line-height: 1.35;
  overflow-wrap: anywhere;
}
.review-card p {
  font-size: 13px;
  line-height: 1.6;
}
.review-card > .btn {
  margin-top: auto;
}
.source-note {
  padding: 10px;
  border-radius: 12px;
  background: var(--accent-soft);
  color: var(--accent-dark);
}
.empty-reviews {
  display: grid;
  justify-items: center;
  text-align: center;
  gap: 18px;
  padding-block: 56px;
}
.empty-reviews > p {
  max-width: 55ch;
  line-height: 1.6;
}
.source-search {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin: 20px 0;
}
.source-search > .input {
  flex: 1 1 220px;
  min-width: 0;
}
.source-search > .select {
  flex: 1 1 180px;
}
.source-list {
  display: grid;
  gap: 10px;
}
.source-list > button {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  text-align: left;
  border: 1px solid var(--line);
  background: var(--surface);
  border-radius: 16px;
  padding: 16px;
  min-height: 76px;
}
.source-list > button:hover {
  background: var(--accent-soft);
}
.source-list strong {
  font-size: 15px;
  overflow-wrap: anywhere;
}
.source-list small {
  display: block;
  color: var(--muted);
  margin-top: 6px;
}
.source-pagination {
  display: flex;
  gap: 16px;
  align-items: center;
  justify-content: center;
  margin-top: 20px;
}
@media (max-width: 900px) {
  .review-list {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 560px) {
  .review-list {
    grid-template-columns: 1fr;
  }
  .review-search {
    flex-wrap: wrap;
  }
  .review-search .input {
    flex-basis: 80%;
  }
  .source-search > .btn {
    width: 100%;
  }
}
</style>
