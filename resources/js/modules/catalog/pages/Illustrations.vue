<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppShell from '@/shared/AppShell.vue';
import IllustrationSettings from '../components/IllustrationSettings.vue';
</script>
<template>
  <Head title="Стиль иллюстраций" /><AppShell section="library"
    ><div class="illustrations-page">
      <header>
        <h1>Стиль иллюстраций</h1>
        <p>Собственные рисунки и проверенные представления той же позы.</p>
        <Link href="/library" class="btn">В библиотеку</Link>
      </header>
      <IllustrationSettings /></div
  ></AppShell>
</template>
<style scoped>
.illustrations-page {
  max-width: 1080px;
  margin: auto;
  display: grid;
  gap: 1.5rem;
}
.illustrations-page h1 {
  font-size: 1.6rem;
  font-weight: 650;
}
.illustrations-page header {
  display: grid;
  gap: 0.65rem;
}
</style>
