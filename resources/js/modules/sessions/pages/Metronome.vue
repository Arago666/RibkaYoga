<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppShell from '@/shared/AppShell.vue';
import PageHeading from '@/shared/PageHeading.vue';
import { uiPreferencesKey } from '@/shared/uiPreferences';
import MetronomeSettings from '../components/MetronomeSettings.vue';
import { defaultMetronome, normalizeMetronome } from '../metronome';

const key = `${uiPreferencesKey(usePage().props.auth)}:standalone-metronome:v1`;
const initial = { ...defaultMetronome(), enabled: true, bpm: 60 };
const settings = ref(initial);
try {
  const saved = localStorage.getItem(key);
  if (saved) settings.value = { ...normalizeMetronome(JSON.parse(saved)), enabled: true };
} catch {
  // Storage is optional; opening this page never starts sound.
}
watch(
  settings,
  (value) => {
    try {
      localStorage.setItem(key, JSON.stringify(value));
    } catch {
      // Playback remains available when browser storage is unavailable.
    }
  },
  { deep: true },
);
</script>

<template>
  <Head title="Метроном" />
  <AppShell section="metronome">
    <PageHeading
      eyebrow="ВАШ РИТМ"
      title="Метроном"
      description="Задавайте темп и рисунок такта. Запускайте ритм отдельно от практики или медитации."
    />
    <div class="standalone-metronome">
      <MetronomeSettings v-model="settings" :open="true" standalone authenticated />
      <p class="muted metronome-pause-note">
        При сворачивании приложения или уходе со страницы метроном ставится на паузу.
      </p>
    </div>
  </AppShell>
</template>

<style scoped>
.standalone-metronome {
  width: 100%;
  max-width: 760px;
  margin: 0 auto;
  padding: 28px;
  border: 1px solid var(--line);
  border-radius: var(--panel-radius);
  background: var(--surface);
}
.metronome-pause-note {
  margin: 24px 0 0;
  font-size: 12px;
  line-height: 1.6;
}
@media (max-width: 600px) {
  .standalone-metronome {
    padding: 16px;
  }
}
</style>
