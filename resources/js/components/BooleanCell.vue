<template>
  <v-tooltip v-if="tooltip" :text="tooltipText" location="top">
    <template #activator="{ props: tipProps }">
      <v-icon v-bind="tipProps" :color="value ? trueColor : falseColor" :size="size">
        {{ value ? trueIcon : falseIcon }}
      </v-icon>
    </template>
  </v-tooltip>
  <v-icon v-else :color="value ? trueColor : falseColor" :size="size">
    {{ value ? trueIcon : falseIcon }}
  </v-icon>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  value: { type: Boolean, default: false },
  trueIcon: { type: String, default: 'mdi-check-circle' },
  falseIcon: { type: String, default: 'mdi-minus-circle' },
  trueColor: { type: String, default: 'success' },
  falseColor: { type: String, default: 'grey' },
  size: { type: String, default: 'small' },
  // Optional tooltip: pass a string (used as-is for both states)
  // or an object {on:'...', off:'...'}.
  tooltip: { type: [Boolean, String, Object], default: false },
});

const tooltipText = computed(() => {
  if (!props.tooltip) return '';
  if (typeof props.tooltip === 'string') return props.tooltip;
  return props.value ? props.tooltip.on : props.tooltip.off;
});
</script>
