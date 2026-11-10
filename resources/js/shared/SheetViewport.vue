<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Scan, ZoomIn } from '@lucide/vue';
const props = withDefaults(defineProps<{ width: number; height: number; label?: string }>(), {
  label: 'Лист практики',
});
const viewport = ref<HTMLElement | null>(null);
const available = ref(0);
const fit = ref(true);
const scale = computed(() => (fit.value && available.value ? Math.min(1, available.value / props.width) : 1));
let observer: ResizeObserver | undefined;
onMounted(() => {
  observer = new ResizeObserver(() => {
    available.value = viewport.value?.clientWidth ?? 0;
  });
  if (viewport.value) observer.observe(viewport.value);
});
onBeforeUnmount(() => observer?.disconnect());
</script>
<template>
  <div class="sheet-viewer">
    <div class="sheet-zoom" aria-label="Масштаб просмотра листа">
      <button type="button" class="btn" :aria-pressed="fit" @click="fit = true">
        <Scan :size="17" />Весь лист
      </button>
      <button type="button" class="btn" :aria-pressed="!fit" @click="fit = false">
        <ZoomIn :size="17" />100%
      </button>
      <span>{{ Math.round(scale * 100) }}%</span>
    </div>
    <p class="sheet-phone-hint">Для крупных рисунков выберите «100%» и сдвигайте лист пальцем.</p>
    <div
      ref="viewport"
      class="sheet-viewport"
      role="region"
      :aria-label="`${label}. При масштабе 100% лист можно прокручивать по горизонтали`"
      tabindex="0"
    >
      <div
        class="sheet-paper"
        :style="{ width: `${Math.ceil(width * scale)}px`, height: `${Math.ceil(height * scale)}px` }"
      >
        <div
          class="sheet-content"
          :style="{ width: `${width}px`, height: `${height}px`, transform: `scale(${scale})` }"
        >
          <slot />
        </div>
      </div>
    </div>
  </div>
</template>
<style scoped>
.sheet-phone-hint {
  display: none;
}
.sheet-viewer {
  min-width: 0;
  width: 100%;
}
.sheet-zoom {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}
.sheet-zoom .btn {
  font-size: 12px;
  min-height: 44px;
  padding: 8px 12px;
}
.sheet-zoom .btn[aria-pressed='true'] {
  background: var(--accent-soft);
  border-color: var(--accent-dark);
  color: var(--accent-dark);
}
.sheet-zoom > span {
  margin-left: auto;
  color: var(--muted);
  font-size: 12px;
  font-variant-numeric: tabular-nums;
}
.sheet-viewport {
  width: 100%;
  min-width: 0;
  overflow: auto;
  overscroll-behavior-x: contain;
  border-radius: 12px;
  background: white;
}
.sheet-paper {
  position: relative;
  margin-inline: auto;
  flex-shrink: 0;
  overflow: hidden;
}
.sheet-content {
  position: absolute;
  inset: 0 auto auto 0;
  transform-origin: top left;
}
.sheet-content :deep(iframe) {
  width: 100%;
  display: block;
  border: 0;
}
@media screen and (max-width: 760px) {
  .sheet-phone-hint {
    display: block;
    font-size: 14px;
    line-height: 1.5;
    color: var(--muted);
    margin-bottom: 12px;
  }
  .sheet-zoom .btn {
    font-size: 15px;
  }
}
@media print {
  .sheet-zoom {
    display: none;
  }
  .sheet-viewport,
  .sheet-paper {
    overflow: visible;
  }
}
</style>
