<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-sitemap</v-icon>
      <h5 class="text-h5 font-weight-bold">Структура моей команды</h5>
    </div>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="filters.search" placeholder="Поиск по ФИО..." density="compact" variant="outlined"
          prepend-inner-icon="mdi-magnify" hide-details style="max-width:260px" @update:model-value="debouncedLoad" />
        <v-select v-model="filters.activity" :items="activityOptions" label="Активность" density="compact" variant="outlined"
          multiple clearable hide-details style="max-width:240px" @update:model-value="loadData" />
        <v-select v-model="filters.qualification" :items="qualificationOptions" label="Квалификация" density="compact" variant="outlined"
          multiple clearable hide-details style="max-width:240px" @update:model-value="loadData" />
        <v-btn variant="text" size="small" :prepend-icon="showAdvanced ? 'mdi-chevron-up' : 'mdi-chevron-down'"
          @click="showAdvanced = !showAdvanced">Доп. фильтры</v-btn>
      </div>
      <v-expand-transition>
        <div v-if="showAdvanced" class="d-flex ga-2 flex-wrap align-center mt-3">
          <v-text-field v-model="filters.city" placeholder="Город" density="compact" variant="outlined"
            hide-details style="max-width:200px" @update:model-value="debouncedLoad" />
          <v-text-field v-model="filters.lp_min" placeholder="ЛП от" type="number" density="compact" variant="outlined"
            hide-details style="max-width:120px" @update:model-value="debouncedLoad" />
          <v-text-field v-model="filters.lp_max" placeholder="ЛП до" type="number" density="compact" variant="outlined"
            hide-details style="max-width:120px" @update:model-value="debouncedLoad" />
          <v-text-field v-model="filters.gp_min" placeholder="ГП от" type="number" density="compact" variant="outlined"
            hide-details style="max-width:120px" @update:model-value="debouncedLoad" />
          <v-text-field v-model="filters.gp_max" placeholder="ГП до" type="number" density="compact" variant="outlined"
            hide-details style="max-width:120px" @update:model-value="debouncedLoad" />
          <v-text-field v-model="filters.ngp_min" placeholder="НГП от" type="number" density="compact" variant="outlined"
            hide-details style="max-width:120px" @update:model-value="debouncedLoad" />
          <v-text-field v-model="filters.ngp_max" placeholder="НГП до" type="number" density="compact" variant="outlined"
            hide-details style="max-width:120px" @update:model-value="debouncedLoad" />
        </div>
      </v-expand-transition>
    </v-card>

    <v-card :loading="loading">
      <v-table density="compact" hover>
        <thead>
          <tr>
            <th style="width:40px"></th>
            <th>ФИО</th>
            <th>Уровень</th>
            <th>Квалификация</th>
            <th>Активность</th>
            <th>Дата активности</th>
            <th class="text-right">ЛП</th>
            <th class="text-right">ГП</th>
            <th class="text-right">НГП</th>
            <th class="text-right">Резиденты</th>
            <th class="text-right">ФК</th>
            <th class="text-right">Клиенты</th>
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
              <td>{{ row.level ?? '—' }}</td>
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
              <td class="text-right">{{ row.residentCount ?? 0 }}</td>
              <td class="text-right">{{ row.fcCount ?? 0 }}</td>
              <td class="text-right">{{ row.clientCount ?? 0 }}</td>
            </tr>
          </template>
          <tr v-if="!flatRows.length && !loading">
            <td colspan="12" class="text-center text-medium-emphasis pa-4">Данные не найдены</td>
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
const activityOptions = ref([]);
const qualificationOptions = ref([]);
const filters = ref({ search: '', activity: [], qualification: [], city: '', lp_min: '', lp_max: '', gp_min: '', gp_max: '', ngp_min: '', ngp_max: '' });

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
  if (l.includes('актив')) return 'success';
  if (l.includes('неактив') || l.includes('потер')) return 'error';
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
    if (filters.value.activity?.length) params.activity = filters.value.activity.join(',');
    if (filters.value.qualification?.length) params.qualification = filters.value.qualification.join(',');
    if (filters.value.city) params.city = filters.value.city;
    if (filters.value.lp_min) params.lp_min = filters.value.lp_min;
    if (filters.value.lp_max) params.lp_max = filters.value.lp_max;
    if (filters.value.gp_min) params.gp_min = filters.value.gp_min;
    if (filters.value.gp_max) params.gp_max = filters.value.gp_max;
    if (filters.value.ngp_min) params.ngp_min = filters.value.ngp_min;
    if (filters.value.ngp_max) params.ngp_max = filters.value.ngp_max;
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
    activityOptions.value = act.data.map(a => ({ title: a.name, value: a.id }));
    qualificationOptions.value = qual.data.map(q => ({ title: q.name, value: q.id }));
  } catch {}
}

onMounted(() => {
  loadData();
  loadFilterOptions();
});
</script>
