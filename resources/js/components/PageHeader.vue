<template>
  <div class="d-flex justify-space-between align-center mb-4 flex-wrap ga-2">
    <div class="d-flex align-center ga-2">
      <v-icon v-if="icon" :size="28" color="primary">{{ icon }}</v-icon>
      <h5 class="text-h5 font-weight-bold">{{ title }}</h5>
      <v-chip v-if="count != null" size="small" color="primary" variant="tonal">{{ count }}</v-chip>
    </div>
    <slot name="actions" />
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  icon: { type: String, default: null },
  count: { type: [Number, String], default: null },
});
</script>
