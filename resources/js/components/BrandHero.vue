<template>
  <v-card flat rounded="xl" class="brand-hero mb-4 overflow-hidden position-relative">
    <!-- Subtle mint gradient background -->
    <div class="brand-hero-bg"></div>

    <!-- Corner accent: small brand sphere, softly blended, decorative only -->
    <div class="brand-hero-accent" aria-hidden="true">
      <BrandWaves
        shape="circle" :width="140" :height="140"
        bg-color="#6EE87A" stroke-color="#ffffff"
        :rows="14" :columns="18" :amplitude="3.5" :frequency="1.0"
        :stroke-width="0.9" :stroke-opacity="0.85"
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
});
</script>

<style scoped>
.brand-hero {
  border: 1px solid rgba(var(--v-theme-brand), 0.35);
  min-height: 90px;
}
.brand-hero-bg {
  position: absolute;
  inset: 0;
  z-index: 0;
  background:
    radial-gradient(at 0% 0%, rgb(183 231 110) 0%, transparent 60%),
    linear-gradient(120deg, #C1E66B, #83ee92 60%, #a8f4b4);
}
.brand-hero-accent {
  position: absolute;
  right: -28px;
  top: 50%;
  transform: translateY(-50%);
  width: 140px;
  height: 140px;
  opacity: 0.75;
  pointer-events: none;
  filter: drop-shadow(0 4px 12px rgba(10, 43, 16, 0.12));
  z-index: 0;
}
.brand-hero-content { position: relative; z-index: 1; }
.brand-hero-title { color: rgb(var(--v-theme-brand-ink)); letter-spacing: 0.2px; }
.brand-hero-subtitle { color: rgba(10, 43, 16, 0.72); }

@media (max-width: 600px) {
  .brand-hero-accent { display: none; }
}
</style>
