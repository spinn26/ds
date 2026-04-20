<template>
  <span :class="cls" style="font-variant-numeric: tabular-nums">
    {{ formatted }}<template v-if="suffix">&nbsp;<span class="text-medium-emphasis">{{ suffix }}</span></template>
  </span>
</template>

<script setup>
import { computed } from 'vue';
import { fmt, fmt2 } from '../composables/useDesign';

const props = defineProps({
  value: { type: [Number, String, null], default: 0 },
  // symbol rendered after the number ('₽', 'USD', '%', ...)
  currency: { type: String, default: '' },
  // show 2 decimals (true) or round to integer (false)
  decimals: { type: Boolean, default: false },
  // colorise negative values red, positive green — used in deltas
  colored: { type: Boolean, default: false },
  // force a '+' sign for positive values (deltas)
  signed: { type: Boolean, default: false },
  empty: { type: String, default: '—' },
});

const num = computed(() => {
  if (props.value === null || props.value === undefined || props.value === '') return null;
  const n = Number(props.value);
  return Number.isFinite(n) ? n : null;
});

const formatted = computed(() => {
  if (num.value === null) return props.empty;
  const abs = Math.abs(num.value);
  const s = props.decimals ? fmt2(abs) : fmt(abs);
  if (num.value < 0) return '−' + s;
  if (props.signed && num.value > 0) return '+' + s;
  return s;
});

const suffix = computed(() => props.currency);

const cls = computed(() => {
  if (!props.colored || num.value === null || num.value === 0) return '';
  return num.value > 0 ? 'text-success' : 'text-error';
});
</script>
