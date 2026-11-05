<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import BrandMotif from './BrandMotif.vue';
import '../../css/brand-app.css';
withDefaults(defineProps<{ title: string; eyebrow?: string }>(), { eyebrow: 'Ваше пространство' });
</script>
<template>
  <main class="brand-login-page">
    <header class="brand-login-header">
      <Link href="/" class="brand" aria-label="RibkaYoga — главная страница">
        <img src="/favicon.svg" alt="" /><span>ribka<span class="brand-light">yoga</span></span>
      </Link>
      <Link href="/" class="brand-login-back"><ArrowLeft :size="16" />На главную</Link>
    </header>
    <div class="brand-login-layout">
      <aside class="brand-login-visual" aria-label="Пространство для вашей практики">
        <img
          src="/images/brand/glass-garden-hero.webp"
          alt="Стеклянная форма и зелёный островок мха"
          class="brand-login-art"
          width="1536"
          height="1024"
        />
        <BrandMotif class="brand-login-motif" />
        <div class="brand-login-caption">
          <span class="brand-login-kicker"
            ><span class="brand-seed" aria-hidden="true"></span>Меньше суеты. Больше практики.</span
          >
          <h2>Всё начинается<br />с движения.</h2>
          <p>
            Собирайте последовательности, сохраняйте идеи<br class="brand-login-desktop-break" />и оставляйте
            больше времени для йоги.
          </p>
        </div>
        <span class="brand-login-art-note" aria-hidden="true">RibkaYoga · пространство для практики</span>
      </aside>
      <section class="brand-login-form-panel">
        <div class="brand-login-form-inner">
          <span class="eyebrow">{{ eyebrow }}</span>
          <h1>{{ title }}</h1>
          <div v-if="$slots.intro" class="brand-login-intro"><slot name="intro" /></div>
          <slot />
          <div v-if="$slots.links" class="brand-login-register"><slot name="links" /></div>
        </div>
        <p class="brand-login-footnote">Ваша практика. В вашем ритме.</p>
      </section>
    </div>
  </main>
</template>
