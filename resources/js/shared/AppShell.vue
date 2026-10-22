<script setup lang="ts">
import { Link, usePage, router } from '@inertiajs/vue3';
import { Library, LayoutGrid, LogOut, UserRound, Shield, BookOpen, Pencil, Timer, Menu } from '@lucide/vue';
import { computed, ref } from 'vue';
defineProps<{
  section: 'practices' | 'library' | 'account' | 'templates' | 'sketchbook' | 'meditation';
  wide?: boolean;
}>();
const more = ref(false);
const page = usePage();
const user = computed(
  () => (page.props.auth as { user?: { name: string; email: string; is_admin?: boolean } } | undefined)?.user,
);
</script>
<template>
  <div class="app-shell">
    <header class="app-header">
      <Link href="/practices" class="brand" aria-label="RibkaYoga — практики"
        ><img src="/favicon.svg" alt="" /><span>ribka<span class="brand-light">yoga</span></span></Link
      >
      <nav v-if="user" class="main-nav" aria-label="Основная навигация">
        <Link href="/practices" :class="['nav-link', { active: section === 'practices' }]"
          ><LayoutGrid :size="18" />Практики</Link
        >
        <Link href="/library" :class="['nav-link', { active: section === 'library' }]"
          ><Library :size="18" />Библиотека</Link
        >
        <Link href="/templates" :class="['nav-link', 'desktop-nav', { active: section === 'templates' }]"
          ><BookOpen :size="18" />Шаблоны</Link
        >
        <Link href="/sketchbook" :class="['nav-link', 'desktop-nav', { active: section === 'sketchbook' }]"
          ><Pencil :size="18" />Скетчбук</Link
        >
        <Link href="/meditation" :class="['nav-link', 'desktop-nav', { active: section === 'meditation' }]"
          ><Timer :size="18" />Медитация</Link
        >
        <Link href="/account" :class="['nav-link', 'desktop-nav', { active: section === 'account' }]"
          ><UserRound :size="18" />Кабинет</Link
        >
        <div class="more-nav">
          <button
            class="nav-link"
            :aria-expanded="more"
            aria-controls="more-navigation"
            @click="more = !more"
          >
            <Menu :size="18" />Ещё
          </button>
          <div v-if="more" id="more-navigation" class="more-links">
            <Link href="/templates">Шаблоны</Link><Link href="/mesocycles">Программы и циклы</Link
            ><Link href="/practice-folders">Категории практик</Link
            ><Link href="/shared-practices">Мне дали доступ</Link><Link href="/sketchbook">Скетчбук</Link
            ><Link href="/meditation">Медитация</Link><Link href="/practice-history">История практики</Link
            ><Link href="/account">Кабинет</Link>
          </div>
        </div>
      </nav>
      <div v-if="user" class="account">
        <Link href="/account" class="account-profile" aria-label="Открыть личный кабинет"
          ><span class="avatar">{{ user?.name?.charAt(0) || 'Р' }}</span
          ><span class="account-name">{{ user?.name }}</span></Link
        >
        <Link
          v-if="user?.is_admin"
          href="/admin"
          class="icon-btn"
          title="Администрирование"
          aria-label="Администрирование"
          ><Shield :size="18"
        /></Link>
        <button class="icon-btn" title="Выйти" aria-label="Выйти из аккаунта" @click="router.post('/logout')">
          <LogOut :size="18" />
        </button>
      </div>
      <Link v-else href="/login" class="btn">Войти</Link>
    </header>
    <main :class="['app-main', { wide }]"><slot /></main>
    <footer class="app-footer">Место для вашей практики <span>RibkaYoga</span></footer>
  </div>
</template>
<style scoped>
.more-nav {
  display: none;
  position: relative;
}
.more-links {
  position: absolute;
  right: 0;
  top: calc(100% + 10px);
  display: grid;
  gap: 4px;
  min-width: 230px;
  padding: 10px;
  background: white;
  border: 1px solid #dce5d6;
  border-radius: 14px;
  box-shadow: 0 8px 32px #153a1320;
  z-index: 50;
}
.more-links a {
  padding: 9px 12px;
  border-radius: 8px;
  white-space: nowrap;
  text-decoration: none;
  color: #31442a;
}
.more-links a:hover {
  background: #eff6eb;
}
@media (max-width: 1120px) {
  .desktop-nav {
    display: none;
  }
  .more-nav {
    display: block;
  }
}
.account-profile {
  display: flex;
  align-items: center;
  gap: 9px;
  color: inherit;
  text-decoration: none;
}
@media (max-width: 650px) {
  .main-nav {
    gap: 2px;
  }
  .nav-link {
    font-size: 0.75rem;
    padding: 0 7px;
    gap: 5px;
  }
  .nav-link svg {
    width: 15px;
  }
  .account-profile .account-name {
    display: none;
  }
}
</style>
