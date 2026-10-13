<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, MessageSquare, Eye, RefreshCw, ChevronLeft, ChevronRight } from '@lucide/vue';
import AppShell from '@/shared/AppShell.vue';
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
    <Link href="/practices" class="btn btn-ghost"><ArrowLeft :size="16" />Мои практики</Link>
    <header class="page-header">
      <h1 class="page-title">Мне дали доступ</h1>
      <button class="btn btn-secondary" :disabled="loading" @click="load">
        <RefreshCw :size="16" />Обновить
      </button>
    </header>
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
        <Link :href="`/shared-practices/${document.id}`" class="btn btn-primary"
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
  gap: 20px;
}
.shared-pagination {
  display: flex;
  gap: 18px;
  align-items: center;
  justify-content: center;
  margin-top: 24px;
  font-size: 0.85rem;
}
.shared-card {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.shared-card h2 {
  font-size: 1.14rem;
  margin: 0;
}
.shared-card p {
  margin: 0;
  font-size: 0.86rem;
}
.shared-permission {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.82rem;
  color: #687960;
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
