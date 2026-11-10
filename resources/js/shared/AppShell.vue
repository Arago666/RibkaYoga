<script setup lang="ts">
import { Link, usePage, router } from '@inertiajs/vue3';
import {
  Library,
  LayoutGrid,
  LogOut,
  UserRound,
  Shield,
  BookOpen,
  Pencil,
  Timer,
  Menu,
  Settings,
  ScanText,
  AudioLines,
} from '@lucide/vue';
import { computed } from 'vue';
import { dismissMenu as vDismissMenu } from './dismissMenu';
import BrandMotif from './BrandMotif.vue';
import '../../css/brand-app.css';
defineProps<{
  section:
    'practices' | 'library' | 'account' | 'templates' | 'sketchbook' | 'meditation' | 'reviews' | 'metronome';
  wide?: boolean;
}>();
const page = usePage();
const user = computed(
  () => (page.props.auth as { user?: { name: string; email: string; is_admin?: boolean } } | undefined)?.user,
);
</script>
<template>
  <div :class="['app-shell', 'app-shell-branded', { 'app-shell-wide': wide }]">
    <div class="app-ambient" aria-hidden="true"><BrandMotif /></div>
    <header :class="['app-header', { 'with-admin': user?.is_admin }]">
      <Link href="/" class="brand" aria-label="RibkaYoga — главная страница"
        ><img src="/favicon.svg" alt="" /><span>ribka<span class="brand-light">yoga</span></span></Link
      >
      <nav v-if="user" class="main-nav" aria-label="Основная навигация">
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
          ><Library :size="18" /><span>Библиотека</span></Link
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
        <details
          v-dismiss-menu
          :class="[
            'more-nav',
            {
              'contains-active': section === 'templates' || section === 'reviews' || section === 'metronome',
            },
          ]"
        >
          <summary :class="['nav-link', { active: section === 'account' || section === 'metronome' }]">
            <Menu :size="18" /><span>Ещё</span>
          </summary>
          <div id="more-navigation" class="more-links">
            <Link href="/metronome" :aria-current="section === 'metronome' ? 'page' : undefined"
              ><AudioLines :size="17" />Метроном</Link
            >
            <Link href="/templates"><BookOpen :size="17" />Шаблоны</Link>
            <Link href="/practice-reviews"><ScanText :size="17" />Разбор практики</Link>
            <Link href="/mesocycles">Программы и циклы</Link>
            <Link href="/practice-folders">Категории практик</Link>
            <Link href="/shared-practices">Мне дали доступ</Link>
            <Link href="/sketchbook"><Pencil :size="17" />Скетчбук</Link>
            <Link href="/practice-history">История практики</Link>
            <Link href="/account"><UserRound :size="17" />Кабинет</Link>
            <Link href="/settings"><Settings :size="17" />Настройки</Link>
            <Link v-if="user?.is_admin" href="/admin"><Shield :size="17" />Администрирование</Link>
          </div>
        </details>
      </nav>
      <div v-if="user" class="account">
        <Link href="/account" class="account-profile" aria-label="Открыть личный кабинет"
          ><span class="avatar">{{ user?.name?.charAt(0) || 'Р' }}</span
          ><span class="account-name">{{ user?.name }}</span></Link
        >
        <Link href="/settings" class="icon-btn settings-link" aria-label="Настройки приложения"
          ><Settings :size="18"
        /></Link>
        <Link
          v-if="user?.is_admin"
          href="/admin"
          class="icon-btn admin-link"
          title="Администрирование"
          aria-label="Администрирование"
          ><Shield :size="18"
        /></Link>
        <button class="icon-btn" title="Выйти" aria-label="Выйти из аккаунта" @click="router.post('/logout')">
          <LogOut :size="18" />
        </button>
      </div>
      <Link v-else href="/login" class="btn guest-login">Войти</Link>
    </header>
    <main :class="['app-main', { wide }]"><slot /></main>
    <footer class="app-footer">
      <span class="footer-signature"
        ><span class="brand-seed" aria-hidden="true"></span>Место для вашей практики</span
      >
      <Link href="/" class="footer-home">О RibkaYoga <span aria-hidden="true">↗</span></Link>
    </footer>
  </div>
</template>
<style scoped>
.guest-login {
  margin-left: auto;
}
.more-nav {
  position: relative;
}
.more-nav summary {
  list-style: none;
  cursor: pointer;
}
.more-nav summary::-webkit-details-marker {
  display: none;
}
.more-links {
  position: absolute;
  right: 0;
  top: calc(100% + 12px);
  display: grid;
  gap: 3px;
  min-width: 245px;
  max-height: min(560px, 75dvh);
  overflow-y: auto;
  padding: 9px;
  background: var(--popup-surface);
  border: 1px solid var(--line);
  border-radius: var(--popup-radius);
  box-shadow: var(--popup-shadow);
  backdrop-filter: blur(20px);
  z-index: 75;
}
.more-links a {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 12px 14px;
  min-height: 44px;
  border-radius: 12px;
  white-space: nowrap;
  text-decoration: none;
  color: var(--text);
  font-size: 14px;
}
.more-links a:hover,
.more-links a[aria-current='page'] {
  background: var(--accent-soft);
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
</style>
