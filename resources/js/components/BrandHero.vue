<template>
  <v-card flat rounded="xl" class="brand-hero mb-4 overflow-hidden position-relative">
    <div class="brand-hero-waves">
      <BrandWaves
        :width="1400" :height="waveHeight" shape="sheet"
        :bg-color="bgColor" :stroke-color="strokeColor"
        :rows="rows" :columns="columns"
        :amplitude="amplitude" :frequency="frequency"
        :stroke-opacity="strokeOpacity" :stroke-width="strokeWidth"
      />
    </div>
    <div class="brand-hero-content d-flex justify-space-between align-center flex-wrap ga-3 pa-5">
      <div class="d-flex align-center ga-3">
        <v-icon v-if="icon" size="28" color="brand-ink">{{ icon }}</v-icon>
        <div>
          <div class="text-h5 font-weight-bold brand-hero-title">{{ title }}</div>
          <div v-if="subtitle" class="text-body-2 brand-hero-subtitle">{{ subtitle }}</div>
        </div>
      </div>
      <div v-if="$slots.actions" class="brand-hero-actions">
        <slot name="actions" />
      </div>
    </div>
  </v-card>
</template>

<script setup>
import BrandWaves from './BrandWaves.vue';

defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: null },
  icon: { type: String, default: null },
  // Brand palette knobs, sensible defaults match identity 2023
  bgColor: { type: String, default: '#6EE87A' },
  strokeColor: { type: String, default: '#ffffff' },
  waveHeight: { type: Number, default: 180 },
  rows: { type: Number, default: 18 },
  columns: { type: Number, default: 36 },
  amplitude: { type: Number, default: 16 },
  frequency: { type: Number, default: 1.2 },
  strokeOpacity: { type: Number, default: 0.75 },
  strokeWidth: { type: Number, default: 1 },
});
</script>

<style scoped>
.brand-hero { border: 1px solid rgba(var(--v-theme-brand), 0.35); }
.brand-hero-waves { position: absolute; inset: 0; z-index: 0; opacity: 0.95; }
.brand-hero-content { position: relative; z-index: 1; }
.brand-hero-title { color: rgb(var(--v-theme-brand-ink)); }
.brand-hero-subtitle { color: rgba(10, 43, 16, 0.72); }
</style>
