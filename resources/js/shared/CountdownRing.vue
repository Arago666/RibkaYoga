<script setup lang="ts">
import { computed } from 'vue';
const props = defineProps<{ seconds: number; total: number | null; label: string; paused?: boolean }>();
const fraction = computed(() =>
  props.total && props.total > 0 ? Math.max(0, Math.min(1, props.seconds / props.total)) : 1,
);
const clock = computed(() => {
  const seconds = Math.max(0, Math.ceil(props.seconds));
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
});
</script>
<template>
  <div
    class="countdown-ring"
    :class="{ 'is-paused': paused }"
    role="timer"
    aria-live="off"
    :aria-label="`${label}: ${clock}`"
  >
    <svg viewBox="0 0 180 180" aria-hidden="true">
      <circle class="countdown-track" cx="90" cy="90" r="80" />
      <circle
        class="countdown-progress"
        cx="90"
        cy="90"
        r="80"
        pathLength="1"
        :stroke-dasharray="`${fraction} 1`"
      />
    </svg>
    <div class="countdown-copy">
      <strong :class="{ 'long-clock': clock.length > 5 }">{{ clock }}</strong
      ><span>{{ label }}</span>
    </div>
  </div>
</template>
<style scoped>
.countdown-ring {
  width: var(--countdown-size, 176px);
  aspect-ratio: 1;
  position: relative;
  margin-inline: auto;
  flex-shrink: 0;
}
svg {
  width: 100%;
  height: 100%;
  transform: rotate(-90deg);
}
circle {
  fill: none;
  stroke-width: 7;
}
.countdown-track {
  stroke: #e0e9dc;
}
.countdown-progress {
  stroke: var(--accent-dark);
  stroke-linecap: round;
  transition: stroke-dasharray 0.15s linear;
}
.countdown-copy {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: 5px;
  padding: 20px;
}
strong {
  font-size: clamp(28px, calc(var(--countdown-size, 176px) * 0.27), 48px);
  font-weight: 550;
  letter-spacing: -0.05em;
  font-variant-numeric: tabular-nums;
  line-height: 1;
  white-space: nowrap;
}
.long-clock {
  font-size: clamp(20px, calc(var(--countdown-size, 176px) * 0.2), 32px);
}
span {
  font-size: 11px;
  color: var(--muted);
  line-height: 1.3;
  text-align: center;
}
.is-paused .countdown-progress {
  opacity: 0.6;
}
@media (prefers-reduced-motion: reduce) {
  .countdown-progress {
    transition: none;
  }
}
</style>
