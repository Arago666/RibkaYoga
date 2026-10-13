<script setup lang="ts">
import { Link, usePage, router } from '@inertiajs/vue3';
import { Library, LayoutGrid, LogOut, UserRound, Shield } from '@lucide/vue';
import { computed } from 'vue';
defineProps<{ section: 'practices' | 'library' | 'account'; wide?: boolean }>();
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
      <nav class="main-nav" aria-label="Основная навигация">
        <Link href="/practices" :class="['nav-link', { active: section === 'practices' }]"
          ><LayoutGrid :size="18" />Практики</Link
        >
        <Link href="/library" :class="['nav-link', { active: section === 'library' }]"
          ><Library :size="18" />Библиотека</Link
        >
        <Link href="/account" :class="['nav-link', { active: section === 'account' }]"
          ><UserRound :size="18" />Кабинет</Link
        >
      </nav>
      <div class="account">
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
    </header>
    <main :class="['app-main', { wide }]"><slot /></main>
    <footer class="app-footer">Место для вашей практики <span>RibkaYoga</span></footer>
  </div>
</template>
<style scoped>
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
