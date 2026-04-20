<template>
  <v-breadcrumbs
    v-if="items.length"
    :items="items"
    density="compact"
    class="pa-0 mb-2 text-body-2"
  >
    <template #prepend>
      <v-icon size="x-small" class="mr-1">mdi-chevron-right</v-icon>
    </template>
  </v-breadcrumbs>
</template>

<script setup>
defineProps({
  items: {
    type: Array,
    default: () => [],
  },
});
</script>
