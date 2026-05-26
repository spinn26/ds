<template>
  <div class="page-header">
    <v-btn v-if="back" icon size="small" variant="text" class="ph-back" @click="$router.back()">
      <v-icon>mdi-arrow-left</v-icon>
    </v-btn>
    <div class="ph-title" :title="title">{{ title }}</div>
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
  min-width: 0;
}
.ph-back { flex-shrink: 0; }
.ph-title {
  flex: 1 1 auto;
  min-width: 0;
  display: block;
  font-size: 22px;
  font-weight: 700;
  letter-spacing: -0.4px;
  color: rgb(var(--v-theme-on-surface));
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
