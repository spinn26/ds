<template>
  <span class="ring" :style="{ width: size + 'px', height: size + 'px' }">
    <svg :viewBox="`0 0 ${size} ${size}`" aria-hidden="true">
      <circle class="track" :cx="half" :cy="half" :r="radius" :stroke-width="width" />
      <circle class="value" :cx="half" :cy="half" :r="radius" :stroke-width="width"
        :stroke-dasharray="circumference" :stroke-dashoffset="offset" />
    </svg>
    <span class="ring-label"><slot>{{ Math.round(percent) }}%</slot></span>
  </span>
</template>

<script setup>
import { computed } from 'vue';

/**
 * Кольцо прогресса: квалификация в hero дашборда и активационный период.
 * Цвет — токеном через проп, чтобы кольцо жило и на светлой карточке, и на
 * brand-deep.
 */
const props = defineProps({
  percent: { type: Number, default: 0 },
  size: { type: Number, default: 96 },
  width: { type: Number, default: 8 },
});

const half = computed(() => props.size / 2);
const radius = computed(() => (props.size - props.width) / 2);
const circumference = computed(() => 2 * Math.PI * radius.value);
const offset = computed(() => {
  const p = Math.max(0, Math.min(100, Number(props.percent) || 0));
  return circumference.value * (1 - p / 100);
});
</script>

<style scoped>
.ring {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
}
.ring svg {
  width: 100%;
  height: 100%;
  /* Старт с двенадцати часов: иначе прогресс растёт от правого края. */
  transform: rotate(-90deg);
}
.track { fill: none; stroke: var(--ring-track, var(--surface-2)); }
.value {
  fill: none;
  stroke: var(--ring-color, var(--brand));
  stroke-linecap: round;
  transition: stroke-dashoffset 300ms ease;
}
.ring-label {
  position: absolute;
  font: 700 22px/1 var(--font-sans);
  letter-spacing: -0.02em;
  font-variant-numeric: tabular-nums;
  color: var(--ring-label-color, var(--ink));
}
</style>
