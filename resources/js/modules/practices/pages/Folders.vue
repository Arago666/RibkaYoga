<script setup lang="ts">
import PageHeading from '@/shared/PageHeading.vue';
import { Head } from '@inertiajs/vue3';
import AppShell from '@/shared/AppShell.vue';
import PracticeFolders from '../components/PracticeFolders.vue';
import PracticeNavigation from '../components/PracticeNavigation.vue';
</script>
<template>
  <Head title="Категории практик" /><AppShell section="practices"
    ><PageHeading
      eyebrow="Порядок в практике"
      title="Категории практик"
      description="Личная организация занятий. Одна практика может находиться в нескольких категориях." />
    <PracticeNavigation active="categories" />
    <PracticeFolders
  /></AppShell>
</template>
