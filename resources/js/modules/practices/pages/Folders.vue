<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppShell from '@/shared/AppShell.vue';
import PracticeFolders from '../components/PracticeFolders.vue';
</script>
<template>
  <Head title="Категории практик" /><AppShell section="practices"
    ><header class="page-header">
      <div>
        <h1 class="page-title">Категории практик</h1>
        <p class="muted">
          Личная организация занятий. Одна практика может находиться в нескольких категориях.
        </p>
      </div>
      <Link href="/practices" class="btn btn-secondary">Все практики</Link>
    </header>
    <PracticeFolders
  /></AppShell>
</template>
