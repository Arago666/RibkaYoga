<script setup lang="ts">
import PageHeading from '@/shared/PageHeading.vue';
import { computed, onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
  Bell,
  HardDrive,
  SlidersHorizontal,
  UserRound,
  BookOpen,
  Volume2,
  Accessibility,
  CircleHelp,
  ChevronRight,
  ArrowLeft,
  Search,
  Timer,
  AudioLines,
  History,
  Shield,
} from '@lucide/vue';
import AppShell from '@/shared/AppShell.vue';
import { uiPreferencesKey, readUiPreferences } from '@/shared/uiPreferences';
const page = usePage(),
  key = uiPreferencesKey(page.props.auth);
const values = ref(readUiPreferences(key)),
  section = ref(''),
  search = ref(''),
  notice = ref(''),
  error = ref('');
const sections = [
  {
    id: 'account',
    title: 'Аккаунт и тариф',
    hint: 'Профиль, доступ и безопасность',
    icon: UserRound,
    href: '/account',
  },
  { id: 'appearance', title: 'Оформление', hint: 'Вид и запомненные подборки', icon: SlidersHorizontal },
  { id: 'practice', title: 'Практика', hint: 'Шаблоны, занятия и история', icon: BookOpen },
  { id: 'sound', title: 'Звук', hint: 'Метроном, гонги и фоновое сопровождение', icon: Volume2 },
  { id: 'notifications', title: 'Уведомления', hint: 'Сигнал о завершении медитации', icon: Bell },
  { id: 'storage', title: 'Память и копии', hint: 'Память браузера и экспорт практик', icon: HardDrive },
  {
    id: 'accessibility',
    title: 'Доступ и движение',
    hint: 'Доступность и уменьшение анимаций',
    icon: Accessibility,
  },
  { id: 'help', title: 'Помощь и сведения', hint: 'Как устроены доступ и разборы', icon: CircleHelp },
];
const visibleSections = computed(() =>
  sections.filter((item) => `${item.title} ${item.hint}`.toLowerCase().includes(search.value.toLowerCase())),
);
const activeSection = computed(() => sections.find((item) => item.id === section.value));
const isAdmin = computed(() => (page.props.auth as { user?: { is_admin?: boolean } })?.user?.is_admin);
const notificationSupported = 'Notification' in window && window.isSecureContext;
const permission = ref(notificationSupported ? Notification.permission : 'unavailable');
const estimate = ref<{ usage?: number; quota?: number }>({});
const bytes = (value?: number) =>
  value === undefined ? 'недоступно' : `${(value / 1024 / 1024).toFixed(1)} МБ`;
function save() {
  try {
    localStorage.setItem(key, JSON.stringify(values.value));
    document.documentElement.dataset.reducedMotion = String(values.value.reduceMotion);
    notice.value = 'Настройки сохранены в этом браузере для вашего аккаунта.';
    error.value = '';
  } catch {
    error.value = 'Браузер не разрешил сохранить настройки.';
  }
}
async function notifications(event: Event) {
  const enabled = (event.target as HTMLInputElement).checked;
  if (!enabled) {
    values.value.meditationNotifications = false;
    save();
    return;
  }
  if (!notificationSupported) return;
  permission.value = await Notification.requestPermission();
  values.value.meditationNotifications = permission.value === 'granted';
  save();
  if (permission.value !== 'granted')
    error.value = 'Уведомления заблокированы. Разрешение можно изменить в настройках браузера.';
}
function clearViewPreferences() {
  if (
    !window.confirm(
      'Сбросить настройки вида и запомненные подборки этого аккаунта в этом браузере? Рисунки, черновики и документы сохранятся.',
    )
  )
    return;
  try {
    const auth = page.props.auth as { user?: { id: number } };
    const palettePrefix = `ribka:palette:${auth.user?.id}:`;
    for (const item of Object.keys(localStorage))
      if (item === key || item.startsWith(palettePrefix)) localStorage.removeItem(item);
    values.value = readUiPreferences(key);
    document.documentElement.dataset.reducedMotion = String(values.value.reduceMotion);
    notice.value = 'Настройки вида сброшены. Рисунки и документы сохранены.';
  } catch {
    error.value = 'Не удалось сбросить настройки браузера.';
  }
}
onMounted(async () => {
  try {
    estimate.value = (await navigator.storage?.estimate?.()) || {};
  } catch {
    /* Browser storage estimates are optional. */
  }
});
</script>
<template>
  <Head title="Настройки" />
  <AppShell
    section="account"
    :breadcrumbs="
      activeSection ? [{ label: 'Настройки', href: '/settings' }, { label: activeSection.title }] : undefined
    "
  >
    <PageHeading
      eyebrow="Всё по-вашему"
      :title="activeSection?.title || 'Настройки'"
      :description="activeSection?.hint"
    >
      <button
        v-if="section"
        class="btn btn-secondary"
        @click="
          section = '';
          notice = '';
          error = '';
        "
      >
        <ArrowLeft :size="18" />Все настройки
      </button>
    </PageHeading>
    <p v-if="notice" class="success-banner" role="status">{{ notice }}</p>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <div v-if="!section" class="settings-home">
      <label class="settings-search"
        ><Search :size="20" /><input
          v-model="search"
          type="search"
          placeholder="Поиск настроек"
          aria-label="Поиск настроек"
      /></label>
      <div class="settings-list card">
        <template v-for="item in visibleSections" :key="item.id">
          <Link v-if="item.href" :href="item.href" class="settings-row">
            <component :is="item.icon" :size="24" /><span
              ><strong>{{ item.title }}</strong
              ><small>{{ item.hint }}</small></span
            ><ChevronRight :size="20" />
          </Link>
          <button
            v-else
            class="settings-row"
            @click="
              section = item.id;
              notice = '';
              error = '';
            "
          >
            <component :is="item.icon" :size="24" /><span
              ><strong>{{ item.title }}</strong
              ><small>{{ item.hint }}</small></span
            ><ChevronRight :size="20" />
          </button>
        </template>
        <p v-if="!visibleSections.length" class="muted">Ничего не найдено. Попробуйте другое слово.</p>
      </div>
    </div>
    <section v-else class="card settings-section">
      <template v-if="section === 'appearance'">
        <h2><SlidersHorizontal :size="22" />Отображение</h2>
        <label class="settings-check"
          ><input v-model="values.rememberFilters" type="checkbox" @change="save" />Запоминать подборку при
          заполнении ячеек</label
        >
      </template>
      <template v-if="section === 'practice'">
        <h2><BookOpen :size="22" />Создание и проведение занятий</h2>
        <Link class="settings-row" href="/templates"
          ><BookOpen :size="23" /><span>Шаблоны</span><ChevronRight :size="20"
        /></Link>
        <Link class="settings-row" href="/practice-history"
          ><History :size="23" /><span>История практики</span><ChevronRight :size="20"
        /></Link>
        <p class="muted">
          Время удержания и отдыха задаётся внутри каждой практики. Это сохраняет индивидуальные настройки
          занятия.
        </p>
      </template>
      <template v-if="section === 'sound'">
        <h2><Volume2 :size="22" />Сопровождение практики</h2>
        <Link class="settings-row" href="/meditation"
          ><Timer :size="23" /><span>Гонги, фон и личное аудио</span><ChevronRight :size="20"
        /></Link>
        <Link class="settings-row" href="/metronome"
          ><AudioLines :size="23" /><span>Метроном и сохранённые ритмы</span><ChevronRight :size="20"
        /></Link>
      </template>
      <template v-if="section === 'notifications'">
        <h2><Bell :size="22" />Завершение медитации</h2>
        <label class="settings-check"
          ><input
            :checked="values.meditationNotifications"
            type="checkbox"
            :disabled="!notificationSupported"
            @change="notifications"
          />Уведомлять о завершении медитации</label
        >
        <p v-if="!notificationSupported" class="muted">
          В этом браузере системные уведомления недоступны. Сигнал завершения остаётся внутри приложения.
        </p>
        <p v-else class="muted">
          Разрешение браузера:
          {{
            permission === 'granted'
              ? 'разрешены'
              : permission === 'denied'
                ? 'заблокированы'
                : 'ещё не запрошено'
          }}.
        </p>
      </template>
      <template v-if="section === 'storage'">
        <h2><HardDrive :size="22" />Память браузера</h2>
        <p class="muted">
          Использовано: {{ bytes(estimate.usage) }}. Доступная квота: {{ bytes(estimate.quota) }}.
        </p>
        <button class="btn btn-secondary" @click="clearViewPreferences">
          <SlidersHorizontal :size="18" />Сбросить настройки вида
        </button>
        <p class="muted">
          Черновики, рисунки и документы сохранятся. Файлы PDF и экспорта доступны в меню каждой практики.
        </p>
      </template>
      <template v-if="section === 'accessibility'">
        <h2><Accessibility :size="22" />Движение и доступ</h2>
        <label class="settings-check"
          ><input v-model="values.reduceMotion" type="checkbox" @change="save" />Уменьшить анимации</label
        >
        <p class="muted">
          Клавиатура: Tab — переход, Enter — действие. В конструкторе нажатие выбирает заполненную ячейку;
          повторное снимает выделение.
        </p>
      </template>
      <template v-if="section === 'help'">
        <h2><CircleHelp :size="22" />RibkaYoga</h2>
        <p class="muted">
          Библиотека асан, конструктор занятий, медитация и личные разборы в одном пространстве.
        </p>
        <p class="muted">
          Доступ к практикам задаётся отдельно. Личные пометки разбора не отправляются автору исходной
          практики.
        </p>
        <Link v-if="isAdmin" class="settings-row" href="/admin"
          ><Shield :size="23" /><span>Администрирование</span><ChevronRight :size="20"
        /></Link>
      </template>
    </section>
  </AppShell>
</template>
<style scoped>
.settings-home {
  max-width: 780px;
  margin-inline: auto;
}
.settings-search {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 20px;
  border: 1px solid var(--line);
  border-radius: 999px;
  background: var(--control-surface);
  margin-bottom: 24px;
  color: var(--muted);
}
.settings-search input {
  width: 100%;
  min-height: 28px;
  border: 0;
  background: transparent;
  outline: none;
  color: var(--text);
  font: inherit;
}
.settings-search:focus-within {
  outline: 2px solid var(--accent);
}
.settings-list {
  padding: 8px 24px;
}
.settings-row {
  display: flex;
  align-items: center;
  gap: 18px;
  min-height: 86px;
  width: 100%;
  padding: 16px 2px;
  border: 0;
  border-bottom: 1px solid var(--line);
  background: transparent;
  text-align: left;
  text-decoration: none;
  color: var(--text);
  font: inherit;
  cursor: pointer;
}
.settings-row:last-child {
  border-bottom: 0;
}
.settings-row > svg:first-child {
  flex: 0 0 auto;
  color: var(--accent);
}
.settings-row > svg:last-child {
  margin-left: auto;
  flex: 0 0 auto;
  color: var(--muted);
}
.settings-row > span {
  display: grid;
  gap: 5px;
  min-width: 0;
}
.settings-row strong {
  font-weight: 550;
  font-size: 18px;
}
.settings-row small {
  color: var(--muted);
  line-height: 1.4;
  font-size: 13px;
}
.settings-row:hover {
  color: var(--accent-dark);
}
.settings-section {
  display: grid;
  align-content: start;
  gap: 18px;
  max-width: 780px;
  margin-inline: auto;
  padding: var(--panel-padding);
}
.settings-section h2 {
  display: flex;
  gap: 12px;
  align-items: center;
  font-size: 21px;
}
.settings-section .btn {
  width: fit-content;
}
.settings-check {
  display: flex;
  align-items: center;
  gap: 12px;
  line-height: 1.5;
  min-height: 48px;
}
.settings-check input {
  accent-color: var(--accent);
  width: 21px;
  height: 21px;
  flex-shrink: 0;
}
@media (max-width: 760px) {
  .settings-list {
    padding-inline: 18px;
  }
  .settings-row {
    gap: 13px;
    min-height: 84px;
  }
  .settings-row strong {
    font-size: 17px;
  }
}
</style>
