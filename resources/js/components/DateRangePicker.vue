<template>
  <div class="d-flex ga-2 align-center flex-wrap">
    <v-select
      v-if="showPresets"
      :model-value="preset"
      :items="presets"
      :label="presetLabel"
      variant="outlined"
      density="comfortable"
      hide-details
      style="min-width: 160px"
      @update:model-value="applyPreset"
    />
    <v-text-field
      :model-value="localFrom"
      type="date"
      :label="fromLabel"
      variant="outlined"
      density="comfortable"
      hide-details
      style="min-width: 160px"
      @update:model-value="v => emitRange(v, localTo)"
    />
    <span class="text-medium-emphasis">—</span>
    <v-text-field
      :model-value="localTo"
      type="date"
      :label="toLabel"
      variant="outlined"
      density="comfortable"
      hide-details
      style="min-width: 160px"
      @update:model-value="v => emitRange(localFrom, v)"
    />
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  // v-model:from / v-model:to so the caller decides how to store it.
  from: { type: String, default: null },
  to: { type: String, default: null },

  fromLabel: { type: String, default: 'С' },
  toLabel: { type: String, default: 'По' },
  presetLabel: { type: String, default: 'Период' },

  showPresets: { type: Boolean, default: true },
});

const emit = defineEmits(['update:from', 'update:to', 'change']);

const localFrom = computed(() => props.from ?? '');
const localTo = computed(() => props.to ?? '');

// YYYY-MM-DD for <input type=date>
const ymd = (d) => {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
};

const presets = [
  { title: 'Сегодня', value: 'today' },
  { title: 'Неделя', value: 'week' },
  { title: 'Месяц', value: 'month' },
  { title: 'Прошлый месяц', value: 'prev_month' },
  { title: 'Квартал', value: 'quarter' },
  { title: 'Год', value: 'year' },
];

const preset = computed(() => null);

function applyPreset(value) {
  const now = new Date();
  let from, to;
  switch (value) {
    case 'today':
      from = to = ymd(now);
      break;
    case 'week': {
      const start = new Date(now); start.setDate(now.getDate() - 6);
      from = ymd(start); to = ymd(now);
      break;
    }
    case 'month':
      from = ymd(new Date(now.getFullYear(), now.getMonth(), 1));
      to = ymd(now);
      break;
    case 'prev_month':
      from = ymd(new Date(now.getFullYear(), now.getMonth() - 1, 1));
      to = ymd(new Date(now.getFullYear(), now.getMonth(), 0));
      break;
    case 'quarter': {
      const q = Math.floor(now.getMonth() / 3);
      from = ymd(new Date(now.getFullYear(), q * 3, 1));
      to = ymd(now);
      break;
    }
    case 'year':
      from = ymd(new Date(now.getFullYear(), 0, 1));
      to = ymd(now);
      break;
    default: return;
  }
  emitRange(from, to);
}

function emitRange(f, t) {
  emit('update:from', f || null);
  emit('update:to', t || null);
  emit('change', { from: f || null, to: t || null });
}
</script>
