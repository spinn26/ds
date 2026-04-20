<template>
  <v-card :loading="loading" class="h-100">
    <v-card-title class="d-flex align-center pa-3">
      <v-icon :color="iconColor" class="me-2">{{ icon }}</v-icon>
      <span class="text-subtitle-1 font-weight-medium">{{ title }}</span>
      <v-spacer />
      <v-chip v-if="count != null" :color="count > 0 ? 'warning' : 'success'" size="small" variant="flat">
        {{ count }}
      </v-chip>
      <v-btn v-if="to" icon="mdi-arrow-right" size="x-small" variant="text"
        class="ms-2" @click="$router.push(to)" />
    </v-card-title>
    <v-divider />
    <slot />
  </v-card>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  icon: { type: String, default: 'mdi-clipboard-list' },
  iconColor: { type: String, default: 'primary' },
  count: { type: Number, default: null },
  to: { type: [String, Object], default: null },
  loading: { type: Boolean, default: false },
});
</script>
