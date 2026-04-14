<template>
  <div>
    <PageHeader title="Статусы партнёров" icon="mdi-calendar-clock" />

    <!-- Summary cards -->
    <v-row class="mb-4">
      <v-col v-for="s in summary" :key="s.id" cols="12" sm="6" :md="12 / Math.max(summary.length, 1)">
        <v-card class="pa-4 text-center" :color="statusColor(s.id)" variant="tonal"
          style="cursor:pointer" @click="filterByActivity(s.id)">
          <div class="text-body-2 text-medium-emphasis">{{ s.name }}</div>
          <div class="text-h3 font-weight-bold">{{ s.count }}</div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Filters -->
    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="ФИО партнёра..."
          rounded prepend-inner-icon="mdi-magnify" clearable hide-details style="max-width:280px" @update:model-value="debouncedLoad" />
        <v-select v-model="activityFilter" :items="activityOptions" label="Статус"
          clearable hide-details style="max-width:220px" @update:model-value="loadData" />
        <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal" class="ml-1">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
        </v-chip>
        <v-btn v-if="activeFilterCount > 0" size="small" variant="text" color="secondary"
          prepend-icon="mdi-filter-remove" @click="resetFilters">Сбросить</v-btn>
      </div>
    </v-card>

    <!-- Detail table -->
    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" @update:options="onOptions">
      <template #item.activityName="{ item }">
        <v-chip size="x-small" :color="statusColor(item.activityId)">{{ item.activityName }}</v-chip>
      </template>
      <template #item.dateActivity="{ value }">
        {{ fmtDate(value) }}
      </template>
      <template #item.willTerminate="{ value }">
        <span v-if="value" :class="isExpiringSoon(value) ? 'text-error font-weight-bold' : ''">
          {{ fmtDate(value) }}
        </span>
        <span v-else>—</span>
      </template>
      <template #item.dateDeterministic="{ value }">
        {{ value ? fmtDate(value) : '—' }}
      </template>
      <template #item.personalVolume="{ value }">
        {{ fmt(value) }}
      </template>
      <template #item.terminationCount="{ value }">
        <v-chip v-if="value > 0" size="x-small" :color="value >= 3 ? 'error' : 'warning'">{{ value }}/3</v-chip>
        <span v-else>—</span>
      </template>
      <template #no-data><EmptyState /></template>
    </v-data-table-server>

    <v-overlay v-model="loading" class="align-center justify-center" persistent>
      <v-progress-circular indeterminate size="64" />
    </v-overlay>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import { fmt, fmtDate } from '../../composables/useDesign';

const loading = ref(true);
const summary = ref([]);
const items = ref([]);
const total = ref(0);
const page = ref(1);
const search = ref('');
const activityFilter = ref(null);

const activeFilterCount = computed(() => {
  let c = 0;
  if (search.value) c++;
  if (activityFilter.value) c++;
  return c;
});

function resetFilters() {
  search.value = '';
  activityFilter.value = null;
  loadData();
}

const activityOptions = computed(() =>
  summary.value.map(s => ({ title: `${s.name} (${s.count})`, value: s.id }))
);

const headers = [
  { title: 'Партнёр', key: 'personName' },
  { title: 'Статус', key: 'activityName', width: 150 },
  { title: 'Активен с', key: 'dateActivity', width: 130 },
  { title: 'Будет терминирован', key: 'willTerminate', width: 170 },
  { title: 'Терминирован', key: 'dateDeterministic', width: 140 },
  { title: 'ЛП', key: 'personalVolume', align: 'end', width: 100 },
  { title: 'Терминаций', key: 'terminationCount', width: 110 },
];

function isExpiringSoon(d) {
  if (!d) return false;
  const diff = (new Date(d) - new Date()) / (1000 * 60 * 60 * 24);
  return diff <= 60 && diff > 0;
}

function statusColor(id) {
  return { 1: 'success', 2: 'warning', 3: 'error', 4: 'info', 5: 'error' }[id] || 'grey';
}

function filterByActivity(id) {
  activityFilter.value = id;
  loadData();
}

let debounceTimer;
function debouncedLoad() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(loadData, 400);
}

function onOptions(opts) {
  page.value = opts.page;
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value };
    if (search.value) params.search = search.value;
    if (activityFilter.value) params.activity = activityFilter.value;
    const { data } = await api.get('/admin/partner-statuses', { params });
    summary.value = data.summary || [];
    items.value = data.data || [];
    total.value = data.total || 0;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
