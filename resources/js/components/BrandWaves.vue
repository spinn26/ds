<template>
  <svg
    :viewBox="`0 0 ${width} ${height}`"
    :preserveAspectRatio="preserveAspectRatio"
    class="brand-waves"
    :class="[shape, { clip: shape === 'circle' }]"
    aria-hidden="true"
  >
    <!-- Background fill (mint / primary / custom) -->
    <rect
      v-if="shape === 'sheet'"
      x="0" y="0"
      :width="width" :height="height"
      :fill="bgColor"
    />
    <circle
      v-if="shape === 'circle'"
      :cx="width / 2" :cy="height / 2"
      :r="Math.min(width, height) / 2 - 2"
      :fill="bgColor"
    />

    <!-- Optional clip for circle shape so lines stay inside -->
    <defs v-if="shape === 'circle'">
      <clipPath :id="`clip-${uid}`">
        <circle :cx="width / 2" :cy="height / 2" :r="Math.min(width, height) / 2 - 2" />
      </clipPath>
    </defs>

    <g :clip-path="shape === 'circle' ? `url(#clip-${uid})` : null">
      <!-- Horizontal family -->
      <path
        v-for="(d, i) in horizontalPaths"
        :key="'h' + i"
        :d="d"
        fill="none"
        :stroke="strokeColor"
        :stroke-width="strokeWidth"
        :opacity="strokeOpacity"
        stroke-linecap="round"
      />
      <!-- Diagonal family (perspective-warped) -->
      <path
        v-for="(d, i) in diagonalPaths"
        :key="'d' + i"
        :d="d"
        fill="none"
        :stroke="strokeColor"
        :stroke-width="strokeWidth"
        :opacity="strokeOpacity"
        stroke-linecap="round"
      />
    </g>
  </svg>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  width: { type: Number, default: 1200 },
  height: { type: Number, default: 600 },
  // shape: 'sheet' (rect) or 'circle'
  shape: { type: String, default: 'sheet' },
  // Palette
  bgColor: { type: String, default: '#6EE87A' },       // mint
  strokeColor: { type: String, default: '#ffffff' },   // white lines on mint (flip to #000 for alt)
  strokeWidth: { type: Number, default: 1.2 },
  strokeOpacity: { type: Number, default: 0.85 },
  // Density
  rows: { type: Number, default: 28 },     // horizontal curves count
  columns: { type: Number, default: 36 },  // diagonal curves count
  // Wave shape
  amplitude: { type: Number, default: 24 },
  frequency: { type: Number, default: 1.25 },
  preserveAspectRatio: { type: String, default: 'xMidYMid slice' },
});

// Stable id per instance (for clipPath)
const uid = Math.random().toString(36).slice(2, 9);

// Build a sinusoidal horizontal curve using cubic bezier segments
function horizontalCurve(y, { offset, amp, freq, w }) {
  const steps = 12;
  const dx = w / steps;
  let d = `M 0 ${y + amp * Math.sin(offset)}`;
  for (let i = 1; i <= steps; i++) {
    const x = i * dx;
    const yy = y + amp * Math.sin((i / steps) * Math.PI * 2 * freq + offset);
    d += ` L ${x.toFixed(2)} ${yy.toFixed(2)}`;
  }
  return d;
}

// Build a diagonal sweep (a curve tilted + sinusoidal)
function diagonalCurve(xStart, { amp, freq, h, w }) {
  const steps = 14;
  const dy = h / steps;
  const slope = 0.22; // diagonal tilt
  let d = `M ${xStart.toFixed(2)} 0`;
  for (let i = 1; i <= steps; i++) {
    const y = i * dy;
    const base = xStart + slope * y;
    const x = base + amp * Math.sin((i / steps) * Math.PI * 2 * freq);
    d += ` L ${x.toFixed(2)} ${y.toFixed(2)}`;
  }
  return d;
}

const horizontalPaths = computed(() => {
  const w = props.width;
  const h = props.height;
  const out = [];
  for (let i = 0; i < props.rows; i++) {
    const y = (i / (props.rows - 1)) * h;
    out.push(horizontalCurve(y, {
      offset: (i / props.rows) * Math.PI * 2,
      amp: props.amplitude * (0.7 + (i % 3) * 0.12),
      freq: props.frequency,
      w,
    }));
  }
  return out;
});

const diagonalPaths = computed(() => {
  const w = props.width;
  const h = props.height;
  const out = [];
  for (let i = 0; i < props.columns; i++) {
    const x = (i / (props.columns - 1)) * w - w * 0.1;
    out.push(diagonalCurve(x, {
      amp: props.amplitude * 0.6,
      freq: props.frequency * 0.9,
      h,
      w,
    }));
  }
  return out;
});
</script>

<style scoped>
.brand-waves {
  display: block;
  width: 100%;
  height: 100%;
}
.brand-waves.circle { border-radius: 50%; }
</style>
