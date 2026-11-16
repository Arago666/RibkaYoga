<script setup lang="ts">
import { Link, usePage, router } from '@inertiajs/vue3';
import {
  Library,
  LayoutGrid,
  LogOut,
  UserRound,
  History,
  BookOpen,
  Pencil,
  Timer,
  Menu,
  Settings,
  ScanText,
  AudioLines,
  House,
} from '@lucide/vue';
import { computed, ref, onMounted } from 'vue';
import { readUiPreferences, uiPreferencesKey } from './uiPreferences';
import Modal from './Modal.vue';
import BrandMotif from './BrandMotif.vue';
import AppBreadcrumbs, { type BreadcrumbItem } from './AppBreadcrumbs.vue';
import '../../css/brand-app.css';
defineProps<{
  section:
    | 'home'
    | 'practices'
    | 'library'
    | 'account'
    | 'templates'
    | 'sketchbook'
    | 'meditation'
    | 'reviews'
    | 'metronome';
  wide?: boolean;
  breadcrumbs?: BreadcrumbItem[];
}>();
const moreOpen = ref(false);
const moreItems = [
  { href: '/sketchbook', label: 'Скетчбук', icon: Pencil },
  { href: '/metronome', label: 'Метроном', icon: AudioLines },
  { href: '/practice-reviews', label: 'Разбор практики', icon: ScanText },
  { href: '/practice-history', label: 'История практики', icon: History },
  { href: '/templates', label: 'Шаблоны', icon: BookOpen },
  { href: '/account', label: 'Кабинет', icon: UserRound },
  { href: '/settings', label: 'Настройки', icon: Settings },
];
const page = usePage();
const miniApp = computed(() => Boolean(page.props.miniApp));
onMounted(() => {
  document.documentElement.dataset.reducedMotion = String(
    readUiPreferences(uiPreferencesKey(page.props.auth)).reduceMotion,
  );
});
const user = computed(
  () => (page.props.auth as { user?: { name: string; email: string; is_admin?: boolean } } | undefined)?.user,
);
</script>
<template>
  <div :class="['app-shell', 'app-shell-branded', { 'app-shell-wide': wide }]">
    <div class="app-ambient" aria-hidden="true"><BrandMotif /></div>
    <header :class="['app-header', { 'with-admin': user?.is_admin }]">
      <Link :href="user ? '/dashboard' : '/'" class="brand" aria-label="RibkaYoga — главная страница"
        ><img src="/favicon.svg" alt="" /><span>ribka<span class="brand-light">yoga</span></span></Link
      >
      <nav v-if="user" class="main-nav" aria-label="Основная навигация">
        <Link
          href="/dashboard"
          :class="['nav-link', 'mobile-home-nav', { active: section === 'home' }]"
          :aria-current="section === 'home' ? 'page' : undefined"
          ><House :size="18" /><span>Главная</span></Link
        >
        <Link
          href="/practices"
          :class="['nav-link', { active: section === 'practices' }]"
          :aria-current="section === 'practices' ? 'page' : undefined"
          ><LayoutGrid :size="18" /><span>Практики</span></Link
        >
        <Link
          href="/library"
          :class="['nav-link', { active: section === 'library' }]"
          :aria-current="section === 'library' ? 'page' : undefined"
          ><Library :size="18" /><span>Асаны</span></Link
        >
        <Link
          href="/templates"
          :class="['nav-link', 'desktop-nav', { active: section === 'templates' }]"
          :aria-current="section === 'templates' ? 'page' : undefined"
          ><BookOpen :size="18" /><span>Шаблоны</span></Link
        >
        <Link
          href="/meditation"
          :class="['nav-link', { active: section === 'meditation' }]"
          :aria-current="section === 'meditation' ? 'page' : undefined"
          ><Timer :size="18" /><span>Медитация</span></Link
        >
        <Link
          href="/sketchbook"
          :class="['nav-link', 'desktop-nav', 'mobile-nav', { active: section === 'sketchbook' }]"
          :aria-current="section === 'sketchbook' ? 'page' : undefined"
          ><Pencil :size="18" /><span>Скетчбук</span></Link
        >
        <Link
          href="/practice-reviews"
          :class="['nav-link', 'desktop-nav', 'review-nav', { active: section === 'reviews' }]"
          :aria-current="section === 'reviews' ? 'page' : undefined"
          ><ScanText :size="18" /><span>Разбор</span></Link
        >
        <button
          :class="['nav-link', 'more-nav', { active: section === 'account' || section === 'metronome' }]"
          aria-label="Ещё"
          aria-haspopup="dialog"
          :aria-expanded="moreOpen"
          @click="moreOpen = true"
        >
          <Menu :size="18" /><span>Ещё</span>
        </button>
      </nav>
      <div v-if="user" class="account">
        <Link href="/account" class="account-profile" aria-label="Открыть личный кабинет"
          ><span class="avatar">{{ user?.name?.charAt(0) || 'Р' }}</span
          ><span class="account-name">{{ user?.name }}</span></Link
        >
        <Link href="/settings" class="icon-btn settings-link" aria-label="Настройки приложения"
          ><Settings :size="18"
        /></Link>
        <button
          v-if="!miniApp"
          class="icon-btn"
          title="Выйти"
          aria-label="Выйти из аккаунта"
          @click="router.post(miniApp ? '/logout?mini_app=1' : '/logout')"
        >
          <LogOut :size="18" />
        </button>
      </div>
      <Link v-else href="/login" class="btn guest-login">Войти</Link>
    </header>
    <Modal :open="moreOpen" title="Ещё" @close="moreOpen = false">
      <nav class="workspace-menu" aria-label="Другие разделы">
        <Link v-for="item in moreItems" :key="item.href" :href="item.href" @click="moreOpen = false">
          <span class="workspace-menu-icon"><component :is="item.icon" :size="23" /></span>
          <span>{{ item.label }}</span
          ><span class="workspace-menu-arrow" aria-hidden="true">↗</span>
        </Link>
      </nav>
    </Modal>
    <main :class="['app-main', { wide }]"><AppBreadcrumbs :items="breadcrumbs" /><slot /></main>
    <footer class="app-footer">
      <span class="footer-signature"
        ><span class="brand-seed" aria-hidden="true"></span>Место для вашей практики</span
      >
      <Link :href="user ? '/dashboard' : '/'" class="footer-home"
        >{{ user ? 'Главная' : 'О RibkaYoga' }} <span aria-hidden="true">↗</span></Link
      >
    </footer>
  </div>
</template>
<style scoped>
.main-nav .mobile-home-nav {
  display: none;
}
.guest-login {
  margin-left: auto;
}
.more-nav {
  border: 0;
  background: none;
  font-family: inherit;
  cursor: pointer;
}
.workspace-menu {
  display: grid;
  gap: 10px;
}
.workspace-menu a {
  display: flex;
  align-items: center;
  gap: 14px;
  min-height: 62px;
  padding: 8px 18px 8px 9px;
  border: 1px solid var(--line);
  border-radius: 999px;
  background: var(--control-surface);
  box-shadow: var(--control-shadow);
  color: var(--text);
  font-size: 16px;
  font-weight: 550;
  text-decoration: none;
}
.workspace-menu a:hover {
  border-color: var(--accent);
}
.workspace-menu-icon {
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  flex: 0 0 auto;
  border-radius: 50%;
  background: var(--accent-soft);
  color: var(--accent-dark);
}
.workspace-menu-arrow {
  margin-left: auto;
  color: var(--muted);
}
.account-profile {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 4px;
  border-radius: 999px;
  color: inherit;
  text-decoration: none;
}
@media (min-width: 1121px) {
  .main-nav .nav-link {
    padding-inline: 8px;
  }
  .with-admin .main-nav .nav-link {
    padding-inline: 6px;
    gap: 5px;
    font-size: 12px;
  }
}
@media (max-width: 1120px) {
  .contains-active summary {
    color: var(--accent-dark);
    border-color: #d1eed9;
    background: var(--accent-soft);
    box-shadow: inset 0 1px 0 #fff;
  }
}
@media (max-width: 760px) {
  .main-nav {
    grid-template-columns: repeat(5, minmax(0, 1fr));
  }
  .main-nav .nav-link {
    min-width: 0;
  }
  .main-nav .mobile-home-nav,
  .main-nav .mobile-nav {
    display: flex;
  }
  .main-nav .more-nav {
    display: none;
  }
  .admin-link {
    display: none;
  }
  .more-links {
    top: auto;
    bottom: calc(100% + 16px);
    right: -4px;
    max-height: calc(100dvh - 170px);
  }
  .more-nav {
    min-width: 0;
  }
  .account-profile .account-name {
    display: none;
  }
}
@media (max-width: 350px) {
  .settings-link {
    display: none;
  }
}
@media (max-width: 350px) {
  .main-nav .nav-link {
    font-size: 9px;
    letter-spacing: -0.02em;
  }
}
</style>
