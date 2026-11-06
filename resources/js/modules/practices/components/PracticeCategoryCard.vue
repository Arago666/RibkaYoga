<script lang="ts">
export interface CategoryPractice {
  id: string;
  title: string;
  theme: string | null;
  revision: number;
  duration_seconds?: number | null;
  difficulty?: string | null;
  filled_cell_count?: number;
  folders?: { id: string; name: string }[];
}
</script>

<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
  Archive,
  ArrowUpRight,
  Clock3,
  Copy,
  FolderMinus,
  FolderPlus,
  Leaf,
  MoreVertical,
  Pencil,
} from '@lucide/vue';
import {
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuPortal,
  DropdownMenuRoot,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from 'reka-ui';

const props = defineProps<{ item: CategoryPractice; readonly?: boolean; busy?: boolean }>();
const emit = defineEmits<{ categories: []; duplicate: []; remove: []; archive: [] }>();
const editUrl = computed(() => `/practices/${props.item.id}/edit`);
const showTheme = computed(() => {
  const theme = props.item.theme?.trim();
  return !!theme && !props.item.title.toLocaleLowerCase('ru').includes(theme.toLocaleLowerCase('ru'));
});
const duration = computed(() => {
  const seconds = props.item.duration_seconds;
  if (!seconds) return '';
  const minutes = Math.floor(seconds / 60);
  const remainder = seconds % 60;
  return [minutes ? `${minutes} мин` : '', remainder ? `${remainder} с` : ''].filter(Boolean).join(' ');
});
</script>

<template>
  <article class="category-practice">
    <Link :href="editUrl" class="category-practice-link">
      <div class="category-practice-top">
        <span class="category-practice-icon"><Leaf :size="18" :stroke-width="1.6" aria-hidden="true" /></span>
        <span v-if="duration" class="category-duration"
          ><Clock3 :size="13" aria-hidden="true" />{{ duration }}</span
        >
      </div>
      <h3 :title="item.title">{{ item.title }}</h3>
      <p v-if="showTheme" :title="item.theme ?? undefined">{{ item.theme }}</p>
      <div v-if="item.difficulty || item.filled_cell_count" class="category-practice-meta">
        <span v-if="item.difficulty" class="category-level">{{ item.difficulty }}</span>
        <span v-else>Поз: {{ item.filled_cell_count }}</span>
      </div>
    </Link>
    <span v-if="readonly" class="category-open-arrow" aria-hidden="true"><ArrowUpRight :size="17" /></span>
    <DropdownMenuRoot v-else :modal="false">
      <DropdownMenuTrigger as-child>
        <button
          type="button"
          class="icon-btn category-card-actions"
          :aria-label="`Действия: ${item.title}`"
          :disabled="busy"
        >
          <MoreVertical :size="19" />
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuPortal>
        <DropdownMenuContent
          class="category-action-popover"
          align="end"
          :side-offset="5"
          :collision-padding="12"
        >
          <DropdownMenuItem class="category-action" @select="emit('categories')">
            <FolderPlus :size="16" /> Категории практики
          </DropdownMenuItem>
          <DropdownMenuItem as-child class="category-action">
            <Link :href="editUrl"><Pencil :size="16" /> Редактировать</Link>
          </DropdownMenuItem>
          <DropdownMenuItem class="category-action" @select="emit('duplicate')">
            <Copy :size="16" /> Дублировать
          </DropdownMenuItem>
          <DropdownMenuSeparator class="category-action-separator" />
          <DropdownMenuItem class="category-action" @select="emit('remove')">
            <FolderMinus :size="16" /> Убрать из этой категории
          </DropdownMenuItem>
          <DropdownMenuItem class="category-action" @select="emit('archive')">
            <Archive :size="16" /> В архив
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenuPortal>
    </DropdownMenuRoot>
  </article>
</template>

<style scoped>
.category-practice {
  min-width: 0;
  min-height: 164px;
  position: relative;
  display: flex;
  flex-direction: column;
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 21px;
  box-shadow: 0 5px 16px rgba(29, 55, 42, 0.035);
  transition:
    border-color 160ms,
    box-shadow 160ms;
  scroll-snap-align: start;
}
.category-practice:hover,
.category-practice:focus-within {
  border-color: var(--accent-border, #bed8c1);
  box-shadow: 0 7px 20px rgba(29, 55, 42, 0.07);
}
.category-practice-link {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
  padding: 15px 16px 16px;
  text-decoration: none;
  color: var(--text);
  border-radius: inherit;
}
.category-practice-link:focus-visible {
  outline-offset: -3px;
}
.category-practice-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  padding-right: 34px;
  min-height: 34px;
}
.category-practice-icon {
  display: grid;
  flex: none;
  place-items: center;
  width: 33px;
  height: 33px;
  border-radius: 12px;
  color: var(--accent-dark);
  background: var(--accent-soft);
}
.category-duration,
.category-practice-meta {
  color: var(--muted);
  font-size: 11px;
  line-height: 1.4;
}
.category-duration {
  display: inline-flex;
  align-items: center;
  justify-content: flex-end;
  text-align: right;
  gap: 4px;
}
.category-duration svg {
  flex: none;
}
.category-practice h3,
.category-practice p {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  overflow-wrap: anywhere;
}
.category-practice h3 {
  font-size: 15px;
  line-height: 1.35;
  margin: 12px 0 0;
  font-weight: 650;
}
.category-practice p {
  font-size: 12px;
  line-height: 1.45;
  color: var(--muted);
  margin: 5px 0 0;
}
.category-practice-meta {
  margin-top: auto;
  padding-top: 13px;
}
.category-level {
  color: var(--accent-dark);
}
.category-card-actions,
.category-open-arrow {
  position: absolute;
  top: 9px;
  right: 8px;
  color: var(--muted);
}
.category-open-arrow {
  top: 22px;
  right: 16px;
  pointer-events: none;
}
</style>

<style>
/* The menu is portalled outside the horizontally scrolling shelf. */
.category-action-popover {
  z-index: 65;
  display: grid;
  gap: 3px;
  min-width: 220px;
  max-width: min(300px, 88vw);
  max-height: var(--reka-dropdown-menu-content-available-height);
  overflow: auto;
  padding: 7px;
  border: 1px solid var(--line);
  border-radius: var(--popup-radius);
  background: var(--surface);
  box-shadow: var(--popup-shadow);
}
.category-action-popover .category-action {
  display: flex;
  align-items: center;
  gap: 9px;
  min-height: 44px;
  border-radius: 12px;
  padding: 9px 12px;
  color: var(--text);
  font-size: 13px;
  text-decoration: none;
  cursor: pointer;
  outline: none;
}
.category-action-popover .category-action svg {
  flex: none;
  color: var(--accent-dark);
}
.category-action-popover .category-action[data-highlighted] {
  background: var(--accent-soft);
}
.category-action-popover .category-action-separator {
  margin: 2px 8px;
  border-top: 1px solid var(--line);
}
</style>
