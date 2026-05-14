<template>
  <div class="page-header">
    <v-btn v-if="back" icon size="small" variant="text" @click="$router.back()">
      <v-icon>mdi-arrow-left</v-icon>
    </v-btn>
    <div class="ph-title">{{ title }}</div>
    <v-spacer />
    <slot name="actions" />
  </div>
</template>

<script setup lang="ts">
defineProps<{
  title: string;
  back?: boolean;
}>();
</script>

<style scoped>
.page-header {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 0 -2px 12px;
  min-height: 40px;
}
.ph-title {
  font-size: 22px;
  font-weight: 700;
  letter-spacing: -0.4px;
  color: rgb(var(--v-theme-on-surface));
}
</style>
