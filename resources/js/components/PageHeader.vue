<template>
  <!-- DS page header: ds-headline-s + опциональный subtitle ниже,
       соответствует desing/ds-layouts.jsx::AppBar (title + subtitle). -->
  <div class="d-flex justify-space-between align-start mb-4 flex-wrap ga-2 page-header">
    <div class="d-flex align-center ga-3">
      <div v-if="icon" class="page-header__icon">
        <v-icon :size="22" color="primary">{{ icon }}</v-icon>
      </div>
      <div class="page-header__titles">
        <h5 class="page-header__title">{{ title }}</h5>
        <div v-if="subtitle || $slots.subtitle" class="page-header__subtitle">
          <slot name="subtitle">{{ subtitle }}</slot>
        </div>
      </div>
      <v-chip v-if="count != null" size="small" color="primary" variant="tonal" class="ml-1">{{ count }}</v-chip>
    </div>
    <slot name="actions" />
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: null },
  icon: { type: String, default: null },
  count: { type: [Number, String], default: null },
});
</script>

<style scoped>
.page-header__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: var(--ds-radius-md, 8px);
  background: var(--ds-primary-soft, rgba(var(--v-theme-primary), 0.10));
}
.page-header__titles {
  min-width: 0;
}
.page-header__title {
  font: var(--ds-type-headline-s, 700 20px/1.3 inherit);
  letter-spacing: -0.01em;
  margin: 0;
}
.page-header__subtitle {
  font-size: 13px;
  color: var(--ds-on-surface-muted, rgba(var(--v-theme-on-surface), 0.55));
  margin-top: 2px;
}
</style>
