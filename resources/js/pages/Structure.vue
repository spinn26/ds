<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-sitemap</v-icon>
      <h5 class="text-h5 font-weight-bold">Структура моей команды</h5>
    </div>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="filters.search" placeholder="ФИО партнёра..." density="compact" variant="outlined"
          prepend-inner-icon="mdi-magnify" hide-details style="max-width:240px" @update:model-value="debouncedLoad" />
        <v-select v-model="filters.qualification" :items="qualificationOptions" label="Квалификация" density="compact" variant="outlined"
          multiple clearable hide-details style="max-width:240px" @update:model-value="loadData" />
        <v-select v-model="filters.levels" :items="qualificationOptions" label="Уровни" density="compact" variant="outlined"
          multiple clearable hide-details style="max-width:240px" @update:model-value="loadData" />
        <v-select v-model="filters.status" :items="statusOptions" label="Статус" density="compact" variant="outlined"
          multiple clearable hide-details style="max-width:240px" @update:model-value="loadData" />
        <v-btn variant="text" size="small" :prepend-icon="showAdvanced ? 'mdi-chevron-up' : 'mdi-chevron-down'"
          @click="showAdvanced = !showAdvanced">Доп. фильтры</v-btn>
      </div>
      <v-expand-transition>
        <div v-if="showAdvanced" class="d-flex ga-2 flex-wrap align-center mt-3">
          <v-text-field v-model="filters.birth_date_from" label="Дата рождения с" type="date" density="compact" variant="outlined"
            hide-details style="max-width:170px" @update:model-value="debouncedLoad" />
          <v-text-field v-model="filters.birth_date_to" label="Дата рождения по" type="date" density="compact" variant="outlined"
            hide-details style="max-width:170px" @update:model-value="debouncedLoad" />
          <v-text-field v-model="filters.city" placeholder="Город" density="compact" variant="outlined"
            hide-details style="max-width:180px" @update:model-value="debouncedLoad" />
          <v-text-field v-model="filters.lp_min" placeholder="ЛП от" type="number" density="compact" variant="outlined"
            hide-details style="max-width:110px" @update:model-value="debouncedLoad" />
          <v-text-field v-model="filters.lp_max" placeholder="ЛП до" type="number" density="compact" variant="outlined"
            hide-details style="max-width:110px" @update:model-value="debouncedLoad" />
          <v-text-field v-model="filters.gp_min" placeholder="ГП от" type="number" density="compact" variant="outlined"
            hide-details style="max-width:110px" @update:model-value="debouncedLoad" />
          <v-text-field v-model="filters.gp_max" placeholder="ГП до" type="number" density="compact" variant="outlined"
            hide-details style="max-width:110px" @update:model-value="debouncedLoad" />
          <v-text-field v-model="filters.ngp_min" placeholder="НГП от" type="number" density="compact" variant="outlined"
            hide-details style="max-width:110px" @update:model-value="debouncedLoad" />
          <v-text-field v-model="filters.ngp_max" placeholder="НГП до" type="number" density="compact" variant="outlined"
            hide-details style="max-width:110px" @update:model-value="debouncedLoad" />
          <v-text-field v-model="filters.termination_from" label="Терминация с" type="date" density="compact" variant="outlined"
            hide-details style="max-width:170px" @update:model-value="debouncedLoad" />
          <v-text-field v-model="filters.termination_to" label="Терминация по" type="date" density="compact" variant="outlined"
            hide-details style="max-width:170px" @update:model-value="debouncedLoad" />
        </div>
      </v-expand-transition>
    </v-card>

    <v-card :loading="loading">
      <v-table density="compact" hover>
        <thead>
          <tr>
            <th style="width:40px"></th>
            <th>ФИО</th>
            <th>Квалификация</th>
            <th>Статус</th>
            <th>Дата смены статуса</th>
            <th class="text-right">ЛП</th>
            <th class="text-right">ГП</th>
            <th class="text-right">НГП</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="row in flatRows" :key="row._uid">
            <tr>
              <td>
                <v-btn v-if="row.hasChildren !== false" icon size="x-small" variant="text"
                  :loading="row._loadingChildren" @click="toggleExpand(row)">
                  <v-icon>{{ row._expanded ? 'mdi-chevron-down' : 'mdi-chevron-right' }}</v-icon>
                </v-btn>
              </td>
              <td :style="{ paddingLeft: (row._depth * 20 + 8) + 'px' }">{{ row.personName }}</td>
              <td>
                <v-chip v-if="row.qualification" size="x-small" color="secondary">{{ row.qualification }}</v-chip>
                <span v-else>—</span>
              </td>
              <td>
                <v-chip v-if="row.activityName" size="x-small" :color="activityColor(row.activityName)">{{ row.activityName }}</v-chip>
                <span v-else>—</span>
              </td>
              <td>{{ row.dateActivity || '—' }}</td>
              <td class="text-right">{{ fmt(row.personalVolume) }}</td>
              <td class="text-right">{{ fmt(row.groupVolume) }}</td>
              <td class="text-right">{{ fmt(row.groupVolumeCumulative) }}</td>
            </tr>
          </template>
          <tr v-if="!flatRows.length && !loading">
            <td colspan="8" class="text-center text-medium-emphasis pa-4">Данные не найдены</td>
          </tr>
        </tbody>
      </v-table>
    </v-card>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api';

const loading = ref(false);
const showAdvanced = ref(false);
const items = ref([]);
const qualificationOptions = ref([]);
const statusOptions = [
  { title: 'Зарегистрирован', value: 'registered' },
  { title: 'Активен', value: 'active' },
  { title: 'Терминирован', value: 'terminated' },
  { title: 'Исключён', value: 'excluded' },
];
const filters = ref({
  search: '', qualification: [], levels: [], status: [],
  birth_date_from: '', birth_date_to: '',
  city: '',
  lp_min: '', lp_max: '', gp_min: '', gp_max: '', ngp_min: '', ngp_max: '',
  termination_from: '', termination_to: '',
});

const fmt = (n) => Number(n || 0).toLocaleString('ru-RU');

let uidCounter = 0;
function enrichRows(rows, depth = 0) {
  return (rows || []).map(r => ({
    ...r,
    _uid: ++uidCounter,
    _depth: depth,
    _expanded: false,
    _loadingChildren: false,
    _children: [],
  }));
}

const flatRows = computed(() => {
  const result = [];
  function walk(rows) {
    for (const r of rows) {
      result.push(r);
      if (r._expanded && r._children.length) walk(r._children);
    }
  }
  walk(items.value);
  return result;
});

function activityColor(name) {
  if (!name) return 'grey';
  const l = name.toLowerCase();
  if (l.includes('актив') && !l.includes('неактив')) return 'success';
  if (l.includes('терминир') || l.includes('исключ')) return 'error';
  if (l.includes('зарег')) return 'info';
  return 'warning';
}

async function toggleExpand(row) {
  if (row._expanded) {
    row._expanded = false;
    return;
  }
  if (row._children.length) {
    row._expanded = true;
    return;
  }
  row._loadingChildren = true;
  try {
    const { data } = await api.get(`/structure/${row.id}/children`);
    row._children = enrichRows(data, row._depth + 1);
    row._expanded = true;
  } catch {}
  row._loadingChildren = false;
}

let debounceTimer;
function debouncedLoad() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(loadData, 400);
}

async function loadData() {
  loading.value = true;
  try {
    const params = {};
    if (filters.value.search) params.search = filters.value.search;
    if (filters.value.qualification?.length) params.qualification = filters.value.qualification.join(',');
    if (filters.value.levels?.length) params.levels = filters.value.levels.join(',');
    if (filters.value.status?.length) params.status = filters.value.status.join(',');
    if (filters.value.birth_date_from) params.birth_date_from = filters.value.birth_date_from;
    if (filters.value.birth_date_to) params.birth_date_to = filters.value.birth_date_to;
    if (filters.value.city) params.city = filters.value.city;
    if (filters.value.lp_min) params.lp_min = filters.value.lp_min;
    if (filters.value.lp_max) params.lp_max = filters.value.lp_max;
    if (filters.value.gp_min) params.gp_min = filters.value.gp_min;
    if (filters.value.gp_max) params.gp_max = filters.value.gp_max;
    if (filters.value.ngp_min) params.ngp_min = filters.value.ngp_min;
    if (filters.value.ngp_max) params.ngp_max = filters.value.ngp_max;
    if (filters.value.termination_from) params.termination_from = filters.value.termination_from;
    if (filters.value.termination_to) params.termination_to = filters.value.termination_to;
    const { data } = await api.get('/structure', { params });
    uidCounter = 0;
    items.value = enrichRows(data);
  } catch {}
  loading.value = false;
}

async function loadFilterOptions() {
  try {
    const [act, qual] = await Promise.all([
      api.get('/structure/activity-statuses'),
      api.get('/structure/qualification-levels'),
    ]);
    // activity-statuses can override statusOptions if backend provides them
    qualificationOptions.value = qual.data.map(q => ({ title: q.name, value: q.id }));
  } catch {}
}

onMounted(() => {
  loadData();
  loadFilterOptions();
});
</script>
