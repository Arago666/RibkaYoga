<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import {
  ArrowRight,
  ArrowUpRight,
  CalendarDays,
  FolderOpen,
  BookOpen,
  ScanText,
  History,
  AudioLines,
  Play,
  Plus,
  Settings,
  Users,
} from '@lucide/vue';
import AppShell from '@/shared/AppShell.vue';
import PageHeading from '@/shared/PageHeading.vue';
import { api } from '@/shared/api';

const recent = ref<{ id: string; title: string } | null>(null);
const empty = ref(false);
const creatingExample = ref(false);
const exampleError = ref('');

onMounted(async () => {
  try {
    const { data } = await api<{ data: { id: string; title: string }[] }>(
      '/api/practices?kind=practice&archive=active&sort=updated_desc&include_cycle_documents=0&per_page=1',
    );
    recent.value = data[0] ?? null;
    empty.value = data.length === 0;
  } catch {
    // The direct creation and section links remain available.
  }
});

async function createExample() {
  if (creatingExample.value) return;
  creatingExample.value = true;
  exampleError.value = '';
  try {
    const { data } = await api<{ data: { id: string; title: string } }>('/api/practices', {
      method: 'POST',
      body: JSON.stringify({ example: 'first-practice' }),
    });
    recent.value = data;
    empty.value = false;
    router.visit(`/practices/${data.id}/preview`);
  } catch (error) {
    exampleError.value = error instanceof Error ? error.message : 'Не удалось создать пример.';
  } finally {
    creatingExample.value = false;
  }
}

const primary = [
  { title: 'Практики', description: 'Собрать и провести занятие', href: '/practices', image: 'practices' },
  { title: 'Асаны', description: 'Библиотека и подборки', href: '/library', image: 'asanas' },
  { title: 'Медитация', description: 'Время для себя', href: '/meditation', image: 'meditation' },
  {
    title: 'Скетчбук',
    description: 'Рисунки и идеи на свободном листе',
    href: '/sketchbook',
    image: 'sketchbook',
  },
];
const tools = [
  { title: 'Программы и циклы', href: '/mesocycles', icon: CalendarDays },
  { title: 'Категории практик', href: '/practice-folders', icon: FolderOpen },
  { title: 'Шаблоны', href: '/templates', icon: BookOpen },
  { title: 'Разбор практики', href: '/practice-reviews', icon: ScanText },
  { title: 'Метроном', href: '/metronome', icon: AudioLines },
  { title: 'История практики', href: '/practice-history', icon: History },
  { title: 'Мне дали доступ', href: '/shared-practices', icon: Users },
  { title: 'Настройки', href: '/settings', icon: Settings },
];
</script>

<template>
  <Head title="Главная" />
  <AppShell section="home">
    <PageHeading
      class="home-heading"
      eyebrow="Ваша практика"
      title="С чего начнём?"
      description="Всё нужное для занятий — под рукой."
    />
    <section class="home-practice-actions" aria-label="Быстрые действия с практиками">
      <Link href="/practices?create=1" class="btn btn-primary"><Plus :size="17" /> Создать практику</Link>
      <Link v-if="recent" :href="`/practices/${recent.id}/edit`" class="btn btn-secondary home-continue">
        <ArrowRight :size="17" />
        <span
          >Продолжить редактирование <span class="home-recent-title">{{ recent.title }}</span></span
        >
      </Link>
      <button v-else-if="empty" class="btn btn-secondary" :disabled="creatingExample" @click="createExample">
        <Play :size="16" /> {{ creatingExample ? 'Создаём…' : 'Создать пример' }}
      </button>
    </section>
    <p v-if="exampleError" class="error-banner" role="alert">{{ exampleError }}</p>
    <nav class="home-primary" aria-label="Основные разделы">
      <Link v-for="item in primary" :key="item.href" :href="item.href" class="home-tile">
        <img
          :src="`/images/app-menu/${item.image}-20261116.jpg`"
          alt=""
          width="88"
          height="88"
          decoding="async"
        />
        <span class="home-tile-copy"
          ><strong>{{ item.title }}</strong
          ><span>{{ item.description }}</span></span
        >
        <ArrowUpRight class="home-tile-arrow" :size="19" aria-hidden="true" />
      </Link>
    </nav>
    <section class="home-tools" aria-labelledby="home-tools-title">
      <h2 id="home-tools-title">Моё пространство</h2>
      <nav class="home-tools-grid" aria-label="Организация и инструменты">
        <Link v-for="item in tools" :key="item.href" :href="item.href" class="home-tool">
          <span class="home-tool-icon"><component :is="item.icon" :size="22" /></span>
          <span>{{ item.title }}</span>
          <ArrowUpRight :size="15" aria-hidden="true" />
        </Link>
      </nav>
    </section>
  </AppShell>
</template>

<style scoped>
.home-heading {
  min-height: 0;
  margin-bottom: 22px;
}
.home-practice-actions {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  max-width: 540px;
  margin-bottom: 22px;
}
.home-practice-actions .btn {
  min-height: 55px;
  max-width: 100%;
  min-width: 0;
}
.home-continue > span {
  display: grid;
  min-width: 0;
}
.home-recent-title {
  max-width: 28ch;
  overflow: hidden;
  color: var(--muted);
  font-size: 11px;
  font-weight: 400;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.home-primary {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}
.home-tile {
  display: flex;
  align-items: center;
  gap: 20px;
  min-width: 0;
  padding: 14px 20px 14px 14px;
  min-height: 122px;
  border: 1px solid var(--line);
  border-radius: 26px;
  background: #fff;
  color: var(--text);
  text-decoration: none;
  box-shadow: 0 5px 25px #173c2405;
}
.home-tile:hover,
.home-tool:hover {
  border-color: var(--accent);
  background: #fafcf7;
}
.home-tile img {
  width: 88px;
  height: 88px;
  border-radius: 18px;
  object-fit: cover;
  flex-shrink: 0;
}
.home-tile-copy {
  display: grid;
  gap: 7px;
  min-width: 0;
}
.home-tile strong {
  font-size: 20px;
  font-weight: 600;
  letter-spacing: -0.03em;
}
.home-tile-copy > span {
  font-size: 13px;
  line-height: 1.5;
  color: var(--muted);
}
.home-tile-arrow {
  color: var(--accent-dark);
  margin-left: auto;
  flex-shrink: 0;
}
.home-tools {
  margin-top: 32px;
}
.home-tools h2 {
  font-size: 17px;
  font-weight: 550;
  margin: 0 0 16px;
}
.home-tools-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.home-tool {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
  min-height: 76px;
  padding: 12px 16px;
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 22px;
  text-decoration: none;
  color: var(--text);
  font-size: 14px;
  line-height: 1.4;
}
.home-tool > svg {
  color: var(--muted);
  margin-left: auto;
  flex-shrink: 0;
}
.home-tool-icon {
  display: grid;
  place-items: center;
  width: 42px;
  height: 42px;
  flex-shrink: 0;
  color: var(--accent-dark);
  background: var(--accent-soft);
  border-radius: 14px;
}
@media (max-width: 600px) {
  .home-practice-actions .btn {
    padding-inline: 10px;
    font-size: 13px;
    white-space: normal;
  }
  .home-practice-actions .btn svg {
    flex-shrink: 0;
  }
  .home-primary {
    grid-template-columns: 1fr;
    gap: 12px;
  }
  .home-tile {
    min-height: 96px;
    padding: 12px 16px 12px 12px;
    gap: 16px;
    border-radius: 23px;
  }
  .home-tile img {
    width: 68px;
    height: 68px;
    border-radius: 15px;
  }
  .home-tile strong {
    font-size: 18px;
  }
  .home-tile-copy {
    gap: 4px;
  }
  .home-tile-copy > span {
    font-size: 12px;
  }
  .home-tools-grid {
    gap: 10px;
  }
  .home-tool {
    flex-wrap: wrap;
    align-content: start;
    padding: 14px;
    gap: 10px;
    font-size: 13px;
  }
  .home-tool > span:not(.home-tool-icon) {
    width: 100%;
    order: 2;
  }
  .home-tool-icon {
    width: 36px;
    height: 36px;
    border-radius: 12px;
  }
}
</style>
