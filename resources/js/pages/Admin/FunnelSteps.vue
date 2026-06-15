<template>
  <div>
    <div v-if="!steps.length" class="text-medium-emphasis text-body-2">Загрузка...</div>
    <div v-for="(s, i) in steps" :key="s.key" class="mb-4">
      <div class="d-flex align-center mb-1">
        <span class="text-body-1 font-weight-medium">{{ s.label }}</span>
        <v-spacer />
        <span class="text-body-1 font-weight-bold">
          {{ s.count.toLocaleString('ru-RU') }}
        </span>
        <span v-if="i > 0" class="text-caption text-medium-emphasis ms-3" style="min-width: 80px; text-align:right">
          {{ s.rate }}% от пред.
        </span>
      </div>
      <v-progress-linear
        :model-value="widthOf(s)"
        :color="s.negative ? 'error' : 'primary'"
        height="28"
      >
        <span class="text-caption text-white px-2">
          {{ widthOf(s).toFixed(1) }}% от {{ totalEver.toLocaleString('ru-RU') }} зарег.
        </span>
      </v-progress-linear>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  steps:     { type: Array, default: () => [] },
  totalEver: { type: Number, default: 0 },
});

function widthOf(s) {
  if (!props.totalEver) return 0;
  return (s.count / props.totalEver) * 100;
}
</script>
