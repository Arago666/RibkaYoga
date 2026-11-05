<script setup lang="ts">
import PageHeading from '@/shared/PageHeading.vue';
import { onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, MessageSquare, Eye, RefreshCw, ChevronLeft, ChevronRight } from '@lucide/vue';
import AppShell from '@/shared/AppShell.vue';
import PracticeNavigation from '../components/PracticeNavigation.vue';
import { api } from '@/shared/api';
import type { SharedPracticeItem } from '../sharing-types';
import type { Page } from '@/modules/catalog/types';
const documents = ref<SharedPracticeItem[]>([]),
  loading = ref(false),
  error = ref('');
const page = ref(1),
  lastPage = ref(1);
async function load() {
  loading.value = true;
  error.value = '';
  try {
    const response = await api<Page<SharedPracticeItem>>(`/api/shared-practices?page=${page.value}`);
    documents.value = response.data;
    lastPage.value = response.last_page;
    if (page.value > response.last_page) {
      page.value = Math.max(1, response.last_page);
      await load();
    }
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось загрузить практики.';
  } finally {
    loading.value = false;
  }
}
onMounted(load);
function go(next: number) {
  page.value = next;
  void load();
}
</script>
<template>
  <Head title="Мне дали доступ" />
  <AppShell section="practices">
    <PageHeading
      eyebrow="Практика объединяет"
      title="Мне дали доступ"
      description="Практики, которыми с вами поделились."
    >
      <button class="btn btn-secondary" :disabled="loading" @click="load">
        <RefreshCw :size="16" />Обновить
      </button>
    </PageHeading>
    <PracticeNavigation active="shared" />
    <div v-if="error" class="error-banner" role="alert">{{ error }}</div>
    <p v-if="loading" class="muted" role="status">Загружаем практики…</p>
    <div v-else-if="documents.length" class="shared-grid">
      <article v-for="document in documents" :key="document.id" class="card shared-card">
        <p class="muted">{{ document.owner }}</p>
        <h2>{{ document.title }}</h2>
        <p v-if="document.theme">{{ document.theme }}</p>
        <span class="shared-permission"
          ><MessageSquare v-if="document.permission === 'comment'" :size="16" /><Eye v-else :size="16" />{{
            document.permission === 'comment' ? 'Просмотр и комментарии' : 'Только просмотр'
          }}</span
        >
        <Link :href="`/shared-practices/${document.id}`" class="btn btn-secondary"
          >Открыть лист<ArrowRight :size="17"
        /></Link>
      </article>
    </div>
    <div v-else-if="!error" class="empty-state">
      <h2>Пока нет открытых для вас практик</h2>
      <p>Владелец может дать вам доступ, указав ваш логин.</p>
    </div>
    <div v-if="lastPage > 1" class="shared-pagination">
      <button class="btn btn-secondary" :disabled="loading || page <= 1" @click="go(page - 1)">
        <ChevronLeft :size="16" />Назад
      </button>
      <span>{{ page }} из {{ lastPage }}</span
      ><button class="btn btn-secondary" :disabled="loading || page >= lastPage" @click="go(page + 1)">
        Дальше<ChevronRight :size="16" />
      </button>
    </div>
  </AppShell>
</template>
<style scoped>
.shared-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: var(--section-gap);
}
.shared-pagination {
  display: flex;
  gap: 18px;
  align-items: center;
  justify-content: center;
  margin-top: var(--section-gap);
  font-size: 0.85rem;
}
.shared-card {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: var(--panel-padding);
  min-width: 0;
  background: linear-gradient(155deg, #f6f9f1, #fff 45%);
}
.shared-card h2 {
  font-size: 19px;
  font-weight: 550;
  color: #29492f;
  letter-spacing: -0.025em;
  overflow-wrap: anywhere;
  margin: 0;
}
.shared-card p {
  margin: 0;
  font-size: 14px;
  line-height: 1.6;
}
.shared-permission {
  display: inline-flex;
  align-self: flex-start;
  align-items: center;
  gap: 8px;
  font-size: 0.82rem;
  color: var(--accent-dark);
  padding: 7px 10px;
  border-radius: 12px;
  border: 1px solid var(--line);
  background: var(--surface-soft);
  margin-top: auto;
}
.shared-card .btn {
  justify-content: space-between;
}
@media (max-width: 950px) {
  .shared-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 600px) {
  .shared-grid {
    grid-template-columns: 1fr;
  }
}
</style>
