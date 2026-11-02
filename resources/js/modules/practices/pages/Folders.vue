<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppShell from '@/shared/AppShell.vue';
import PracticeFolders from '../components/PracticeFolders.vue';
import PracticeNavigation from '../components/PracticeNavigation.vue';
</script>
<template>
  <Head title="Категории практик" /><AppShell section="practices"
    ><header class="page-header">
      <div>
        <h1 class="page-title">Категории практик</h1>
        <p class="page-description">
          Личная организация занятий. Одна практика может находиться в нескольких категориях.
        </p>
      </div>
    </header>
    <PracticeNavigation active="categories" />
    <PracticeFolders
  /></AppShell>
</template>
