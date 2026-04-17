<template>
  <div class="text-center pa-8 empty-state">
    <div class="empty-state-visual" :style="{ width: size + 'px', height: size + 'px' }">
      <BrandWaves
        v-if="brand"
        shape="circle" :width="size" :height="size"
        bg-color="#6EE87A" stroke-color="#ffffff"
        :rows="10" :columns="14" :amplitude="3" :frequency="1.0"
        :stroke-width="0.7" :stroke-opacity="0.6"
      />
      <v-icon
        :size="iconSize"
        :color="brand ? 'brand-ink' : 'grey-lighten-1'"
        class="empty-state-icon"
      >{{ icon }}</v-icon>
    </div>
    <div class="text-body-1 text-medium-emphasis mt-3">{{ message }}</div>
    <div v-if="hint" class="text-caption text-medium-emphasis mt-1">{{ hint }}</div>
    <slot name="action" />
  </div>
</template>

<script setup>
import { computed } from 'vue';
import BrandWaves from './BrandWaves.vue';

const props = defineProps({
  icon: { type: String, default: 'mdi-file-search-outline' },
  message: { type: String, default: 'Данные не найдены' },
  hint: { type: String, default: null },
  size: { type: Number, default: 88 },
  // When true (default), render brand wave disc behind the icon.
  // Set to false if used on a surface that already has brand styling.
  brand: { type: Boolean, default: true },
});

const iconSize = computed(() => Math.round(props.size * 0.42));
</script>

<style scoped>
.empty-state-visual {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  overflow: visible;
}
.empty-state-icon {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: auto;
  z-index: 1;
}
</style>
