<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { House, ChevronRight } from '@lucide/vue';
export interface BreadcrumbItem {
  label: string;
  href?: string;
}
const props = defineProps<{ items?: BreadcrumbItem[] }>();
const page = usePage();
const path = computed(() => page.url.split('?')[0]);
const labels: Record<string, string> = {
  '/practices': 'Практики',
  '/library': 'Асаны',
  '/meditation': 'Медитация',
  '/sketchbook': 'Скетчбук',
  '/mesocycles': 'Программы и циклы',
  '/practice-folders': 'Категории практик',
  '/templates': 'Шаблоны',
  '/practice-reviews': 'Разбор практики',
  '/practice-history': 'История практики',
  '/shared-practices': 'Мне дали доступ',
  '/account': 'Кабинет',
  '/settings': 'Настройки',
  '/metronome': 'Метроном',
  '/admin': 'Администрирование',
};
const items = computed<BreadcrumbItem[]>(() => {
  if (props.items) return props.items;
  if (labels[path.value]) return [{ label: labels[path.value] }];
  const practice = path.value.match(/^\/practices\/([^/]+)\/(edit|preview|print|run)$/);
  if (practice) {
    const result: BreadcrumbItem[] = [{ label: 'Практики', href: '/practices' }];
    if (practice[2] !== 'edit') result.push({ label: 'Практика', href: `/practices/${practice[1]}/edit` });
    result.push({
      label:
        { edit: 'Конструктор', preview: 'Готовый лист', print: 'Печать', run: 'Занятие' }[practice[2]] ||
        'Практика',
    });
    return result;
  }
  const nested = [
    ['/sketchbook/', '/sketchbook', 'Скетчбук', 'Лист'],
    ['/practice-reviews/', '/practice-reviews', 'Разбор практики', 'Лист разбора'],
    ['/mesocycles/', '/mesocycles', 'Программы и циклы', 'Мезоцикл'],
    ['/shared-practices/', '/shared-practices', 'Мне дали доступ', 'Практика'],
    ['/row-templates/', '/templates', 'Шаблоны', 'Сохранённая строка'],
  ].find(([prefix]) => path.value.startsWith(prefix));
  return nested ? [{ label: nested[2], href: nested[1] }, { label: nested[3] }] : [];
});
</script>
<template>
  <nav v-if="path !== '/dashboard' && items.length" class="app-breadcrumbs" aria-label="Путь по разделам">
    <ol>
      <li>
        <Link href="/dashboard"><House :size="15" aria-hidden="true" /><span>Главная</span></Link>
      </li>
      <li v-for="(item, index) in items" :key="`${index}-${item.label}`">
        <ChevronRight :size="14" aria-hidden="true" />
        <Link v-if="item.href && index < items.length - 1" :href="item.href" :title="item.label">{{
          item.label
        }}</Link>
        <span v-else class="current" aria-current="page" :title="item.label">{{ item.label }}</span>
      </li>
    </ol>
  </nav>
</template>
<style scoped>
.app-breadcrumbs {
  min-width: 0;
  margin: 0 0 20px;
  overflow-x: auto;
  scrollbar-width: thin;
}
ol {
  display: flex;
  align-items: center;
  gap: 8px;
  list-style: none;
  margin: 0;
  padding: 0;
  min-height: 34px;
}
li {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
  min-width: 0;
  color: var(--muted);
  font-size: 12px;
}
a {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 34px;
  color: var(--accent-dark);
  text-decoration: none;
  white-space: nowrap;
}
a:hover {
  text-decoration: underline;
  text-underline-offset: 4px;
}
.current {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 38ch;
}
@media (max-width: 760px) {
  .app-breadcrumbs {
    margin-bottom: 14px;
  }
  .current {
    max-width: 24ch;
  }
}
@media print {
  .app-breadcrumbs {
    display: none;
  }
}
</style>
