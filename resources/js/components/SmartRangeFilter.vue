<template>
  <v-menu v-model="open" :close-on-content-click="false" location="bottom start" offset="4">
    <template #activator="{ props: act }">
      <v-btn v-bind="act" variant="outlined" density="comfortable"
        :color="hasValue ? 'primary' : 'default'"
        class="srf-trigger text-none px-3"
        :append-icon="hasValue ? null : 'mdi-chevron-down'">
        <div class="d-flex flex-column align-start text-left">
          <span class="text-caption text-medium-emphasis srf-trigger-label">{{ label }}</span>
          <span class="text-body-2 font-weight-medium srf-trigger-value">{{ summary }}</span>
        </div>
        <template v-if="hasValue" #append>
          <v-icon size="16" class="ml-1" @click.stop="reset">mdi-close-circle</v-icon>
        </template>
      </v-btn>
    </template>

    <v-card min-width="320" max-width="360" class="pa-3 srf-panel">
      <v-select :model-value="mode" :items="modeItems" label="Способ фильтрации"
        density="compact" variant="outlined" hide-details
        @update:model-value="onModeChange" />

      <div v-if="mode" class="mt-3">
        <!-- DATE inputs -->
        <template v-if="kind === 'date'">
          <div v-if="mode === 'range'" class="d-flex ga-2">
            <v-text-field :model-value="from" type="date" label="С"
              density="compact" variant="outlined" hide-details
              @update:model-value="v => emitRange(v, to)" />
            <v-text-field :model-value="to" type="date" label="По"
              density="compact" variant="outlined" hide-details
              @update:model-value="v => emitRange(from, v)" />
          </div>
          <v-text-field v-else-if="mode === 'exact'"
            :model-value="from" type="date" label="Дата"
            density="compact" variant="outlined" hide-details
            @update:model-value="v => emitRange(v, v)" />
          <v-select v-else-if="mode === 'year'"
            :model-value="pickerYear" :items="yearItems" label="Год"
            density="compact" variant="outlined" hide-details
            @update:model-value="onYearChange" />
          <div v-else-if="mode === 'month'" class="d-flex ga-2">
            <v-select :model-value="pickerYear" :items="yearItems" label="Год"
              density="compact" variant="outlined" hide-details
              style="max-width: 110px"
              @update:model-value="v => onMonthYearChange(v, pickerMonth)" />
            <v-select :model-value="pickerMonth" :items="monthItems" label="Месяц"
              density="compact" variant="outlined" hide-details
              @update:model-value="v => onMonthYearChange(pickerYear, v)" />
          </div>
          <div v-else-if="mode === 'quarter'" class="d-flex ga-2">
            <v-select :model-value="pickerYear" :items="yearItems" label="Год"
              density="compact" variant="outlined" hide-details
              style="max-width: 110px"
              @update:model-value="v => onQuarterChange(v, pickerQuarter)" />
            <v-select :model-value="pickerQuarter" :items="quarterItems" label="Квартал"
              density="compact" variant="outlined" hide-details
              @update:model-value="v => onQuarterChange(pickerYear, v)" />
          </div>

          <div v-if="mode === 'range'" class="d-flex flex-wrap ga-1 mt-2">
            <v-chip size="x-small" variant="tonal" @click="applyPreset('thisMonth')">Этот месяц</v-chip>
            <v-chip size="x-small" variant="tonal" @click="applyPreset('lastMonth')">Прошлый месяц</v-chip>
            <v-chip size="x-small" variant="tonal" @click="applyPreset('thisYear')">Этот год</v-chip>
            <v-chip size="x-small" variant="tonal" @click="applyPreset('lastYear')">Прошлый год</v-chip>
          </div>
        </template>

        <!-- NUMBER inputs -->
        <template v-else-if="kind === 'number'">
          <div v-if="mode === 'range'" class="d-flex ga-2">
            <v-text-field :model-value="from" type="number" label="От"
              density="compact" variant="outlined" hide-details
              @update:model-value="v => emitRange(v, to)" />
            <v-text-field :model-value="to" type="number" label="До"
              density="compact" variant="outlined" hide-details
              @update:model-value="v => emitRange(from, v)" />
          </div>
          <v-text-field v-else-if="mode === 'exact'"
            :model-value="from" type="number" label="Точное значение"
            density="compact" variant="outlined" hide-details
            @update:model-value="v => emitRange(v, v)" />
          <v-text-field v-else-if="mode === 'gte'"
            :model-value="from" type="number" label="Не меньше"
            density="compact" variant="outlined" hide-details
            @update:model-value="v => emitRange(v, '')" />
          <v-text-field v-else-if="mode === 'lte'"
            :model-value="to" type="number" label="Не больше"
            density="compact" variant="outlined" hide-details
            @update:model-value="v => emitRange('', v)" />
        </template>
      </div>

      <v-divider v-if="mode" class="my-3" />
      <div class="d-flex justify-space-between align-center">
        <v-btn size="small" variant="text" color="secondary"
          :disabled="!hasValue && !mode" @click="reset">Сбросить</v-btn>
        <v-btn size="small" variant="flat" color="primary" @click="open = false">Готово</v-btn>
      </div>
    </v-card>
  </v-menu>
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
  { title: 'Диапазон', value: 'range' },
  { title: 'Конкретная дата', value: 'exact' },
  { title: 'Месяц', value: 'month' },
  { title: 'Квартал', value: 'quarter' },
  { title: 'Год', value: 'year' },
];
const NUM_MODES = [
  { title: 'Диапазон', value: 'range' },
  { title: 'Точное значение', value: 'exact' },
  { title: 'От', value: 'gte' },
  { title: 'До', value: 'lte' },
];
const modeItems = computed(() => props.kind === 'date' ? DATE_MODES : NUM_MODES);

const open = ref(false);
const mode = ref(null);

const monthItems = [
  { title: 'январь', value: 1 }, { title: 'февраль', value: 2 },
  { title: 'март', value: 3 }, { title: 'апрель', value: 4 },
  { title: 'май', value: 5 }, { title: 'июнь', value: 6 },
  { title: 'июль', value: 7 }, { title: 'август', value: 8 },
  { title: 'сентябрь', value: 9 }, { title: 'октябрь', value: 10 },
  { title: 'ноябрь', value: 11 }, { title: 'декабрь', value: 12 },
];
const quarterItems = [
  { title: 'I квартал', value: 1 }, { title: 'II квартал', value: 2 },
  { title: 'III квартал', value: 3 }, { title: 'IV квартал', value: 4 },
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
function fmtRu(s) {
  if (!s) return '';
  const [y, m, d] = String(s).split('-');
  if (!y || !m || !d) return s;
  return `${d}.${m}.${y}`;
}

const hasValue = computed(() => !!(props.from || props.to));

const summary = computed(() => {
  if (!hasValue.value) return 'не выбрано';
  if (props.kind === 'date') {
    if (mode.value === 'year' && pickerYear.value) return `${pickerYear.value} год`;
    if (mode.value === 'month' && pickerYear.value && pickerMonth.value) {
      const m = monthItems.find(x => x.value === pickerMonth.value);
      return `${m?.title || ''} ${pickerYear.value}`;
    }
    if (mode.value === 'quarter' && pickerYear.value && pickerQuarter.value) {
      const q = quarterItems.find(x => x.value === pickerQuarter.value);
      return `${q?.title || ''} ${pickerYear.value}`;
    }
    if (mode.value === 'exact' || props.from === props.to) return fmtRu(props.from);
    if (props.from && props.to) return `${fmtRu(props.from)} — ${fmtRu(props.to)}`;
    if (props.from) return `от ${fmtRu(props.from)}`;
    if (props.to) return `до ${fmtRu(props.to)}`;
  } else {
    if (mode.value === 'exact' || props.from === props.to) return String(props.from);
    if (props.from && props.to) return `${props.from} — ${props.to}`;
    if (props.from) return `≥ ${props.from}`;
    if (props.to) return `≤ ${props.to}`;
  }
  return 'не выбрано';
});

function emitRange(f, t) {
  emit('update:from', f ?? '');
  emit('update:to', t ?? '');
}

function onModeChange(v) {
  mode.value = v;
  emitRange('', '');
  pickerMonth.value = null;
  pickerQuarter.value = null;
}

function reset() {
  mode.value = null;
  pickerMonth.value = null;
  pickerQuarter.value = null;
  emitRange('', '');
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

function applyPreset(name) {
  const now = new Date();
  let first, last;
  if (name === 'thisMonth') {
    first = new Date(now.getFullYear(), now.getMonth(), 1);
    last = new Date(now.getFullYear(), now.getMonth() + 1, 0);
  } else if (name === 'lastMonth') {
    first = new Date(now.getFullYear(), now.getMonth() - 1, 1);
    last = new Date(now.getFullYear(), now.getMonth(), 0);
  } else if (name === 'thisYear') {
    first = new Date(now.getFullYear(), 0, 1);
    last = new Date(now.getFullYear(), 11, 31);
  } else if (name === 'lastYear') {
    first = new Date(now.getFullYear() - 1, 0, 1);
    last = new Date(now.getFullYear() - 1, 11, 31);
  }
  emitRange(fmt(first), fmt(last));
}

watch(() => [props.from, props.to], ([f, t]) => {
  if (!f && !t && mode.value !== null) {
    pickerMonth.value = null;
    pickerQuarter.value = null;
  }
});
</script>

<style scoped>
.srf-trigger {
  min-width: 140px;
  height: auto !important;
  min-height: 44px;
  padding-top: 4px !important;
  padding-bottom: 4px !important;
}
.srf-trigger :deep(.v-btn__content) {
  width: 100%;
  justify-content: flex-start;
}
.srf-trigger-label {
  font-size: 11px;
  line-height: 14px;
}
.srf-trigger-value {
  font-size: 13px;
  line-height: 16px;
  max-width: 180px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.srf-panel :deep(.v-field) {
  font-size: 13px;
}
</style>
