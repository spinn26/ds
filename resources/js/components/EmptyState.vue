<template>
  <div class="text-center pa-8">
    <v-icon :size="size" color="grey-lighten-1" class="mb-3">{{ icon }}</v-icon>
    <div class="text-body-1 text-medium-emphasis">{{ message }}</div>
    <div v-if="hint" class="text-caption text-medium-emphasis mt-1">{{ hint }}</div>
    <slot name="action" />
  </div>
</template>

<script setup>
defineProps({
  icon: { type: String, default: 'mdi-file-search-outline' },
  message: { type: String, default: 'Данные не найдены' },
  hint: { type: String, default: null },
  size: { type: Number, default: 56 },
});
</script>
