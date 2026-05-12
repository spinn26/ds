<template>
  <div class="smart-range-filter">
    <div class="text-caption text-medium-emphasis mb-1">{{ label }}</div>
    <div class="d-flex ga-1 align-center flex-wrap">
      <v-select
        :model-value="mode"
        :items="modeItems"
        density="compact"
        variant="outlined"
        hide-details
        style="max-width: 130px; min-width: 110px"
        @update:model-value="onModeChange"
      />

      <!-- DATE / DATETIME inputs -->
      <template v-if="kind === 'date'">
        <template v-if="mode === 'range'">
          <v-text-field :model-value="from" type="date" placeholder="с"
            density="compact" variant="outlined" hide-details
            style="max-width: 150px"
            @update:model-value="v => emitRange(v, to)" />
          <v-text-field :model-value="to" type="date" placeholder="по"
            density="compact" variant="outlined" hide-details
            style="max-width: 150px"
            @update:model-value="v => emitRange(from, v)" />
        </template>
        <template v-else-if="mode === 'exact'">
          <v-text-field :model-value="from" type="date" placeholder="дата"
            density="compact" variant="outlined" hide-details
            style="max-width: 160px"
            @update:model-value="v => emitRange(v, v)" />
        </template>
        <template v-else-if="mode === 'year'">
          <v-select :model-value="pickerYear" :items="yearItems" placeholder="год"
            density="compact" variant="outlined" hide-details
            style="max-width: 110px"
            @update:model-value="onYearChange" />
        </template>
        <template v-else-if="mode === 'month'">
          <v-select :model-value="pickerYear" :items="yearItems" placeholder="год"
            density="compact" variant="outlined" hide-details
            style="max-width: 100px"
            @update:model-value="v => onMonthYearChange(v, pickerMonth)" />
          <v-select :model-value="pickerMonth" :items="monthItems" placeholder="месяц"
            density="compact" variant="outlined" hide-details
            style="max-width: 140px"
            @update:model-value="v => onMonthYearChange(pickerYear, v)" />
        </template>
        <template v-else-if="mode === 'quarter'">
          <v-select :model-value="pickerYear" :items="yearItems" placeholder="год"
            density="compact" variant="outlined" hide-details
            style="max-width: 100px"
            @update:model-value="v => onQuarterChange(v, pickerQuarter)" />
          <v-select :model-value="pickerQuarter" :items="quarterItems" placeholder="квартал"
            density="compact" variant="outlined" hide-details
            style="max-width: 130px"
            @update:model-value="v => onQuarterChange(pickerYear, v)" />
        </template>
      </template>

      <!-- NUMBER inputs -->
      <template v-else-if="kind === 'number'">
        <template v-if="mode === 'range'">
          <v-text-field :model-value="from" type="number" placeholder="от"
            density="compact" variant="outlined" hide-details
            style="max-width: 110px"
            @update:model-value="v => emitRange(v, to)" />
          <v-text-field :model-value="to" type="number" placeholder="до"
            density="compact" variant="outlined" hide-details
            style="max-width: 110px"
            @update:model-value="v => emitRange(from, v)" />
        </template>
        <template v-else-if="mode === 'exact'">
          <v-text-field :model-value="from" type="number" placeholder="точно"
            density="compact" variant="outlined" hide-details
            style="max-width: 130px"
            @update:model-value="v => emitRange(v, v)" />
        </template>
        <template v-else-if="mode === 'gte'">
          <v-text-field :model-value="from" type="number" placeholder="от"
            density="compact" variant="outlined" hide-details
            style="max-width: 130px"
            @update:model-value="v => emitRange(v, '')" />
        </template>
        <template v-else-if="mode === 'lte'">
          <v-text-field :model-value="to" type="number" placeholder="до"
            density="compact" variant="outlined" hide-details
            style="max-width: 130px"
            @update:model-value="v => emitRange('', v)" />
        </template>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
  label: { type: String, required: true },
  kind: { type: String, default: 'date' },
  from: { type: [String, Number], default: '' },
  to:   { type: [String, Number], default: '' },
});
const emit = defineEmits(['update:from', 'update:to']);

const DATE_MODES = [
  { title: 'Диапазон',  value: 'range' },
  { title: 'Дата',       value: 'exact' },
  { title: 'Месяц',     value: 'month' },
  { title: 'Квартал',   value: 'quarter' },
  { title: 'Год',         value: 'year' },
];
const NUM_MODES = [
  { title: 'Диапазон',  value: 'range' },
  { title: 'Точно',      value: 'exact' },
  { title: 'От',           value: 'gte' },
  { title: 'До',           value: 'lte' },
];
const modeItems = computed(() => props.kind === 'date' ? DATE_MODES : NUM_MODES);

const mode = ref('range');

const monthItems = [
  { title: 'январь',     value: 1 },
  { title: 'февраль',  value: 2 },
  { title: 'март',         value: 3 },
  { title: 'апрель',     value: 4 },
  { title: 'май',           value: 5 },
  { title: 'июнь',        value: 6 },
  { title: 'июль',         value: 7 },
  { title: 'август',     value: 8 },
  { title: 'сентябрь', value: 9 },
  { title: 'октябрь',  value: 10 },
  { title: 'ноябрь',    value: 11 },
  { title: 'декабрь',  value: 12 },
];
const quarterItems = [
  { title: 'I кв.',  value: 1 },
  { title: 'II кв.', value: 2 },
  { title: 'III кв.', value: 3 },
  { title: 'IV кв.', value: 4 },
];
const yearItems = (() => {
  const cur = new Date().getFullYear();
  const arr = [];
  for (let y = cur + 1; y >= cur - 6; y--) arr.push(y);
  return arr;
})();

const pickerYear = ref(new Date().getFullYear());
const pickerMonth = ref(null);
const pickerQuarter = ref(null);

function pad(n) { return String(n).padStart(2, '0'); }
function fmt(d) { return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; }

function emitRange(f, t) {
  emit('update:from', f ?? '');
  emit('update:to', t ?? '');
}

function onModeChange(v) {
  mode.value = v;
  // Стираем значения при переключении — пользователь сразу видит чистый ввод.
  emitRange('', '');
  pickerMonth.value = null;
  pickerQuarter.value = null;
}

function onYearChange(y) {
  pickerYear.value = y;
  if (!y) { emitRange('', ''); return; }
  emitRange(`${y}-01-01`, `${y}-12-31`);
}

function onMonthYearChange(y, m) {
  pickerYear.value = y;
  pickerMonth.value = m;
  if (!y || !m) { emitRange('', ''); return; }
  const first = new Date(y, m - 1, 1);
  const last  = new Date(y, m, 0);
  emitRange(fmt(first), fmt(last));
}

function onQuarterChange(y, q) {
  pickerYear.value = y;
  pickerQuarter.value = q;
  if (!y || !q) { emitRange('', ''); return; }
  const startMonth = (q - 1) * 3;
  const first = new Date(y, startMonth, 1);
  const last  = new Date(y, startMonth + 3, 0);
  emitRange(fmt(first), fmt(last));
}

// При внешнем сбросе фильтров — сброс внутренних picker-значений.
watch(() => [props.from, props.to], ([f, t]) => {
  if (!f && !t) {
    pickerMonth.value = null;
    pickerQuarter.value = null;
  }
});
</script>

<style scoped>
.smart-range-filter {
  display: inline-flex;
  flex-direction: column;
  flex: 0 0 auto;
}
</style>
