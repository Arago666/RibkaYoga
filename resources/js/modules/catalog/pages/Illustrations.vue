<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppShell from '@/shared/AppShell.vue';
import IllustrationSettings from '../components/IllustrationSettings.vue';
</script>
<template>
  <Head title="Стиль иллюстраций" /><AppShell section="library"
    ><div class="illustrations-page">
      <header class="page-header">
        <div>
          <h1 class="page-title">Стиль иллюстраций</h1>
          <p class="page-description">Собственные рисунки и проверенные представления той же позы.</p>
        </div>
        <Link href="/library" class="btn">В библиотеку</Link>
      </header>
      <IllustrationSettings class="card" /></div
  ></AppShell>
</template>
<style scoped>
.illustrations-page {
  width: 100%;
  display: grid;
  gap: var(--section-gap);
}
.illustrations-page .page-header {
  margin-bottom: 0;
}
</style>
