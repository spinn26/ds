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
        rounded
      >
        <span class="funnel-pct text-caption">
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

<style scoped>
/* Подпись поверх полосы: тёмная полупрозрачная подложка делает текст
   читаемым на любом фоне (зелёная/красная заливка, светлый трек в светлой
   теме, тёмный трек в тёмной) — раньше голый text-white терялся на светлом. */
.funnel-pct {
  color: #fff;
  background: rgba(0, 0, 0, 0.5);
  border-radius: 6px;
  padding: 1px 8px;
  font-weight: 500;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
</style>
