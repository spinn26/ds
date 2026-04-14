<template>
  <div>
    <PageHeader title="Квалификации" icon="mdi-chart-bar" :count="total" />

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по партнёру..."
          rounded prepend-inner-icon="mdi-magnify" clearable hide-details style="max-width:300px" @update:model-value="debouncedLoad" />
        <v-text-field v-model="month" type="month" label="Месяц"
          hide-details style="max-width:200px" @update:model-value="loadData" />
        <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal" class="ml-1">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
        </v-chip>
        <v-btn v-if="activeFilterCount > 0" size="small" variant="text" color="secondary"
          prepend-icon="mdi-filter-remove" @click="resetFilters">Сбросить</v-btn>
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" @update:options="onOptions">
      <template #item.personalVolume="{ value }">{{ fmt(value) }}</template>
      <template #item.groupVolume="{ value }">{{ fmt(value) }}</template>
      <template #item.groupVolumeCumulative="{ value }">{{ fmt(value) }}</template>
      <template #item.date="{ value }">{{ fmtDate(value) }}</template>
      <template #item.result="{ value }">
        <v-chip size="x-small" :color="value === 'levelIncreased' ? 'success' : value === 'idle' ? 'grey' : 'warning'">
          {{ resultLabel(value) }}
        </v-chip>
      </template>
      <template #no-data><EmptyState /></template>
    </v-data-table-server>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import { fmt, fmtDate } from '../../composables/useDesign';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const month = ref(new Date().toISOString().slice(0, 7));
const page = ref(1);
const defaultMonth = new Date().toISOString().slice(0, 7);

const activeFilterCount = computed(() => {
  let c = 0;
  if (search.value) c++;
  if (month.value && month.value !== defaultMonth) c++;
  return c;
});

function resetFilters() {
  search.value = '';
  month.value = defaultMonth;
  loadData();
}

const headers = [
  { title: 'ID', key: 'id', width: 70 },
  { title: 'Партнёр', key: 'consultantName' },
  { title: 'Ном. ур.', key: 'nominalLevel', width: 90 },
  { title: 'Расч. ур.', key: 'calculationLevel', width: 90 },
  { title: 'Результат', key: 'result', width: 140 },
  { title: 'ЛП', key: 'personalVolume', align: 'end', width: 110 },
  { title: 'ГП', key: 'groupVolume', align: 'end', width: 110 },
  { title: 'НГП', key: 'groupVolumeCumulative', align: 'end', width: 130 },
  { title: 'Дата', key: 'date', width: 120 },
];

function resultLabel(r) {
  const map = { levelIncreased: 'Повышение', levelDecreased: 'Понижение', idle: 'Без изменений' };
  return map[r] || r || '—';
}

let debounceTimer;
function debouncedLoad() { clearTimeout(debounceTimer); debounceTimer = setTimeout(loadData, 400); }
function onOptions(opts) { page.value = opts.page; loadData(); }

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value };
    if (search.value) params.search = search.value;
    if (month.value) params.month = month.value;
    const { data } = await api.get('/admin/qualifications', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
