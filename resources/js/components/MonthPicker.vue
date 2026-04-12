<template>
  <div class="d-flex align-center ga-1">
    <v-btn icon="mdi-chevron-left" size="x-small" variant="text" @click="prev" />
    <v-btn variant="outlined" size="small" min-width="160" @click="showMenu = true">
      {{ displayLabel }}
    </v-btn>
    <v-btn icon="mdi-chevron-right" size="x-small" variant="text" @click="next" :disabled="isCurrentMonth" />

    <v-menu v-model="showMenu" :close-on-content-click="false" location="bottom">
      <template #activator="{ props }">
        <span v-bind="props" />
      </template>
      <v-card min-width="280" class="pa-3">
        <!-- Year selector -->
        <div class="d-flex justify-space-between align-center mb-2">
          <v-btn icon="mdi-chevron-left" size="x-small" variant="text" @click="menuYear--" />
          <span class="text-subtitle-1 font-weight-bold">{{ menuYear }}</span>
          <v-btn icon="mdi-chevron-right" size="x-small" variant="text" @click="menuYear++"
            :disabled="menuYear >= currentYear" />
        </div>
        <!-- Month grid -->
        <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap: 4px;">
          <v-btn v-for="(label, idx) in monthLabels" :key="idx"
            :variant="isSelected(idx) ? 'flat' : 'text'"
            :color="isSelected(idx) ? 'primary' : ''"
            :disabled="isFuture(idx)"
            size="small"
            @click="selectMonth(idx)">
            {{ label }}
          </v-btn>
        </div>
      </v-card>
    </v-menu>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
  modelValue: { type: String, required: true }, // "2025-03"
});
const emit = defineEmits(['update:modelValue']);

const showMenu = ref(false);
const now = new Date();
const currentYear = now.getFullYear();
const currentMonthIdx = now.getMonth();

const monthLabels = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];
const monthLabelsFull = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

const selectedYear = computed(() => parseInt(props.modelValue?.split('-')[0]) || currentYear);
const selectedMonth = computed(() => parseInt(props.modelValue?.split('-')[1]) - 1 || 0);

const menuYear = ref(selectedYear.value);
watch(() => props.modelValue, () => { menuYear.value = selectedYear.value; });

const displayLabel = computed(() =>
  `${monthLabelsFull[selectedMonth.value]} ${selectedYear.value}`
);

const isCurrentMonth = computed(() =>
  selectedYear.value === currentYear && selectedMonth.value === currentMonthIdx
);

function isSelected(monthIdx) {
  return menuYear.value === selectedYear.value && monthIdx === selectedMonth.value;
}

function isFuture(monthIdx) {
  return menuYear.value > currentYear || (menuYear.value === currentYear && monthIdx > currentMonthIdx);
}

function pad(n) { return String(n).padStart(2, '0'); }

function selectMonth(monthIdx) {
  emit('update:modelValue', `${menuYear.value}-${pad(monthIdx + 1)}`);
  showMenu.value = false;
}

function prev() {
  let y = selectedYear.value;
  let m = selectedMonth.value - 1;
  if (m < 0) { m = 11; y--; }
  emit('update:modelValue', `${y}-${pad(m + 1)}`);
}

function next() {
  let y = selectedYear.value;
  let m = selectedMonth.value + 1;
  if (m > 11) { m = 0; y++; }
  if (y > currentYear || (y === currentYear && m > currentMonthIdx)) return;
  emit('update:modelValue', `${y}-${pad(m + 1)}`);
}
</script>
