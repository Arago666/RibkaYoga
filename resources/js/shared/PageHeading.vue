<script setup lang="ts">
defineProps<{ eyebrow: string; title: string; description?: string }>();
</script>

<template>
  <header class="page-header workspace-heading">
    <div class="page-heading-copy">
      <p class="eyebrow"><span class="heading-seed" aria-hidden="true"></span>{{ eyebrow }}</p>
      <h1 class="page-title">{{ title }}</h1>
      <p
        class="page-description"
        :class="{ 'heading-description-empty': !description }"
        :aria-hidden="!description || undefined"
      >
        {{ description || '\u00a0' }}
      </p>
    </div>
    <div v-if="$slots.default" class="page-heading-actions"><slot /></div>
  </header>
</template>

<style scoped>
.workspace-heading {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: start;
  gap: 24px;
  min-height: 160px;
  padding: 0 0 24px;
  margin: 0 0 26px;
  border-bottom: 1px solid #e0e7da;
}
.page-heading-copy {
  min-width: 0;
}
.eyebrow {
  display: flex;
  gap: 10px;
  align-items: center;
  margin: 0 0 11px;
  color: #647a53;
  font-size: 10px;
  letter-spacing: 0.13em;
  line-height: 16px;
  font-weight: 600;
}
.heading-seed {
  display: block;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #8aa66b;
  box-shadow: 0 0 0 4px #8aa66b13;
  flex-shrink: 0;
}
.page-title {
  color: #203d2c;
  font-weight: 550;
  letter-spacing: -0.05em;
  margin: 0;
}
.page-description {
  font-size: 14px;
  line-height: 24px;
  min-height: 48px;
  color: #697761;
  max-width: 75ch;
  margin-top: 11px;
}
.heading-description-empty {
  visibility: hidden;
}
.page-heading-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 10px;
  min-width: 0;
  padding-top: 27px;
}
.page-heading-actions:empty {
  display: none;
}
@media (min-width: 761px) and (max-width: 1000px) {
  .workspace-heading {
    grid-template-columns: minmax(0, 1fr);
    gap: 16px;
  }
  .page-heading-actions {
    justify-content: flex-start;
    padding-top: 0;
  }
}
@media (max-width: 760px) {
  .workspace-heading {
    grid-template-columns: minmax(0, 1fr);
    gap: 18px;
    min-height: 0;
    padding-bottom: 22px;
    margin-bottom: 22px;
  }
  .page-description {
    font-size: 13px;
    line-height: 22px;
    min-height: 0;
    margin-top: 10px;
  }
  .page-heading-actions {
    justify-content: flex-start;
    gap: 8px;
    padding-top: 0;
  }
}
@media print {
  .workspace-heading {
    border: 0;
    min-height: 0;
  }
  .heading-seed {
    display: none;
  }
}
</style>
