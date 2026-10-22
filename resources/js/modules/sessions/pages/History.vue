<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppShell from '@/shared/AppShell.vue';
import { api } from '@/shared/api';
import BodyCoverage from '../BodyCoverage.vue';
import type { ExecutionSession } from '../types';
interface Summary {
  sessions: number;
  completed: number;
  seconds: number;
  calendar: Record<string, number>;
  anatomy: { name: string; seconds: number }[];
  unclassified_seconds: number;
  limit: number;
}
const rows = ref<ExecutionSession[]>([]),
  summary = ref<Summary | null>(null),
  days = ref('30'),
  error = ref(''),
  loading = ref(false);
const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
async function load() {
  loading.value = true;
  error.value = '';
  try {
    const data = await api<{ data: ExecutionSession[]; summary: Summary }>(
      `/api/executions?timezone=${encodeURIComponent(timezone)}${days.value ? `&days=${days.value}` : ''}`,
    );
    rows.value = data.data;
    summary.value = data.summary;
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось загрузить историю.';
  } finally {
    loading.value = false;
  }
}
onMounted(load);
</script>
<template>
  <AppShell section="practices"
    ><Head title="Моя история практики" />
    <div class="top">
      <h1>Моя история практики</h1>
      <Link class="btn" href="/practices">К практикам</Link
      ><a class="btn" href="/api/executions/export">Скачать CSV</a>
    </div>
    <label
      >Период
      <select v-model="days" @change="load">
        <option value="1">День</option>
        <option value="7">Неделя</option>
        <option value="30">Месяц</option>
        <option value="">Всё время</option>
      </select></label
    >
    <p v-if="error" role="alert">{{ error }}</p>
    <p v-if="loading">Загружаем…</p>
    <template v-if="summary"
      ><div class="numbers">
        <strong>{{ Math.round(summary.seconds / 60) }} минут</strong
        ><span>{{ summary.sessions }} сессий · {{ summary.completed }} завершено</span
        ><span>Часовой пояс: {{ timezone }}</span>
      </div>
      <BodyCoverage
        :zones="summary.anatomy"
        :total="summary.seconds"
        :unclassified="summary.unclassified_seconds"
      />
      <section>
        <h2>Регулярность</h2>
        <div class="calendar">
          <div v-for="(seconds, date) in summary.calendar" :key="date">
            <time>{{ date }}</time
            ><strong>{{ Math.round(seconds / 60) }} мин</strong>
          </div>
          <p v-if="!Object.keys(summary.calendar).length">История появится после первой практики.</p>
        </div>
      </section>
      <section>
        <h2>Сессии</h2>
        <article v-for="row in rows" :key="row.id">
          <div>
            <strong>{{ row.title }}</strong>
            <p>
              {{
                new Date(
                  /(?:Z|[+-]\d\d:\d\d)$/.test(row.started_at)
                    ? row.started_at
                    : row.started_at.replace(' ', 'T') + 'Z',
                ).toLocaleString()
              }}
              · {{ row.mode === 'teach' ? 'Ведение занятия' : 'Самостоятельная практика' }}
            </p>
          </div>
          <span
            >{{ Math.round(row.active_seconds / 60) }} мин ·
            {{
              row.status === 'completed'
                ? 'Завершено'
                : row.status === 'interrupted'
                  ? 'Досрочно'
                  : row.status === 'paused'
                    ? 'На паузе'
                    : 'В процессе'
            }}</span
          >
        </article>
        <p class="muted">
          В интерфейсе до {{ summary.limit }} последних сессий. CSV содержит всю личную историю.
        </p>
      </section></template
    ></AppShell
  >
</template>
<style scoped>
.top,
.numbers {
  display: flex;
  gap: 16px;
  align-items: center;
  flex-wrap: wrap;
}
.top h1 {
  margin-right: auto;
}
.numbers {
  background: white;
  border: 1px solid #e0e7dc;
  padding: 20px;
  border-radius: 15px;
  margin: 20px 0;
}
.numbers strong {
  font-size: 1.6rem;
  color: #396e28;
}
section {
  margin-top: 28px;
}
article {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: center;
  background: white;
  border-bottom: 1px solid #e0e7dc;
  padding: 16px;
}
article p,
.muted {
  font-size: 0.85rem;
  color: #68725f;
}
.calendar {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
.calendar > div {
  display: grid;
  gap: 6px;
  background: #edf5e8;
  padding: 12px;
  border-radius: 10px;
}
.calendar time {
  font-size: 0.8rem;
}
@media (max-width: 550px) {
  article {
    display: block;
  }
}
</style>
