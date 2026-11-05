<script setup lang="ts">
import PageHeading from '@/shared/PageHeading.vue';
import AppSelect from '@/shared/AppSelect.vue';
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
    <PageHeading
      eyebrow="Опыт вашей практики"
      title="Моя история практики"
      description="Регулярность, проведённые занятия и личная история."
    >
      <Link class="btn btn-secondary" href="/practices">К практикам</Link
      ><a class="btn btn-secondary" href="/api/executions/export">Скачать CSV</a>
    </PageHeading>
    <label class="period-field"
      >Период
      <AppSelect class="select" v-model="days" @change="load">
        <option value="1">День</option>
        <option value="7">Неделя</option>
        <option value="30">Месяц</option>
        <option value="">Всё время</option>
      </AppSelect></label
    >
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <p v-if="loading" class="muted" role="status">Загружаем…</p>
    <template v-if="summary"
      ><div class="card numbers">
        <strong>{{ Math.round(summary.seconds / 60) }} минут</strong
        ><span>{{ summary.sessions }} сессий · {{ summary.completed }} завершено</span
        ><span>Часовой пояс: {{ timezone }}</span>
      </div>
      <BodyCoverage
        class="card"
        :zones="summary.anatomy"
        :total="summary.seconds"
        :unclassified="summary.unclassified_seconds"
      />
      <section class="card history-section">
        <h2>Регулярность</h2>
        <div class="calendar">
          <div v-for="(seconds, date) in summary.calendar" :key="date">
            <time>{{ date }}</time
            ><strong>{{ Math.round(seconds / 60) }} мин</strong>
          </div>
          <p v-if="!Object.keys(summary.calendar).length">История появится после первой практики.</p>
        </div>
      </section>
      <section class="card history-section">
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
.history-actions,
.numbers {
  display: flex;
  gap: 16px;
  align-items: center;
  flex-wrap: wrap;
  border-radius: var(--panel-radius);
  padding: var(--panel-padding);
  box-shadow: var(--panel-shadow);
  margin: var(--section-gap) 0;
}
.history-heading .page-title {
  min-width: 0;
  overflow-wrap: anywhere;
}
.history-actions {
  flex: none;
  gap: 10px;
}
.period-field {
  display: grid;
  gap: 8px;
  max-width: 260px;
  font-size: 14px;
  font-weight: 600;
}
.numbers {
  background: linear-gradient(150deg, #f5f8ee, #fff 65%);
  border: 1px solid var(--line);
  padding: var(--panel-padding);
  border-radius: var(--panel-radius);
  margin: var(--section-gap) 0;
  box-shadow: var(--panel-shadow);
}
.numbers strong {
  font-size: 32px;
  color: var(--accent-dark);
  font-weight: 500;
  letter-spacing: -0.04em;
}
section {
  margin-top: var(--section-gap);
}
section h2 {
  font-size: 19px;
  margin-bottom: 16px;
  font-weight: 650;
  letter-spacing: -0.025em;
}
article {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: center;
  background: var(--surface-soft);
  border: 1px solid var(--line);
  border-radius: var(--field-radius);
  padding: 20px;
  margin-bottom: 12px;
  min-width: 0;
  overflow-wrap: anywhere;
}
article > div {
  min-width: 0;
}
article > span {
  flex: none;
  font-size: 14px;
}
article p {
  margin-top: 7px;
  line-height: 1.6;
}
article p,
.muted {
  font-size: 0.85rem;
  color: var(--muted);
}
.calendar {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
.calendar > div {
  display: grid;
  gap: 6px;
  background: var(--surface-soft);
  padding: 12px;
  border-radius: var(--field-radius);
  border: 1px solid var(--line);
}
.calendar time {
  font-size: 0.8rem;
}
@media (max-width: 550px) {
  .history-heading {
    align-items: flex-start;
    flex-direction: column;
  }
  .history-actions,
  .period-field {
    width: 100%;
    max-width: none;
  }
  .history-actions .btn {
    flex: 1;
  }
  article {
    display: block;
  }
  article > span {
    display: block;
    margin-top: 12px;
  }
  .numbers {
    align-items: flex-start;
    flex-direction: column;
    gap: 8px;
  }
}
</style>
