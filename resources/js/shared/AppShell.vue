<script setup lang="ts">
import { Link, usePage, router } from '@inertiajs/vue3';
import { Library, LayoutGrid, LogOut } from '@lucide/vue';
import { computed } from 'vue';
defineProps<{ section: 'practices' | 'library'; wide?: boolean }>();
const page = usePage();
const user = computed(
  () => (page.props.auth as { user?: { name: string; email: string } } | undefined)?.user,
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
      </nav>
      <div class="account">
        <span class="avatar">{{ user?.name?.charAt(0) || 'Р' }}</span
        ><span class="account-name">{{ user?.name }}</span
        ><button
          class="icon-btn"
          title="Выйти"
          aria-label="Выйти из аккаунта"
          @click="router.post('/logout')"
        >
          <LogOut :size="18" />
        </button>
      </div>
    </header>
    <main :class="['app-main', { wide }]"><slot /></main>
    <footer class="app-footer">Место для вашей практики <span>RibkaYoga</span></footer>
  </div>
</template>
