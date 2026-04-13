<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-account-search</v-icon>
      <h5 class="text-h5 font-weight-bold">Партнёры</h5>
      <v-chip size="small" color="primary">{{ total }}</v-chip>
    </div>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по ФИО..." density="compact" variant="outlined"
          prepend-inner-icon="mdi-magnify" hide-details style="max-width:300px" @update:model-value="debouncedLoad" />
        <v-select v-model="activityFilter" :items="activityOptions" label="Активность" density="compact" variant="outlined"
          clearable hide-details style="max-width:200px" @update:model-value="loadData" />
        <v-select v-model="statusFilter" :items="statusOptions" label="Статус" density="compact" variant="outlined"
          clearable hide-details style="max-width:200px" @update:model-value="loadData" />
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" @update:options="onOptions"
      density="compact" hover no-data-text="Партнёры не найдены">
      <template #item.activityName="{ value }">
        <v-chip v-if="value" size="x-small" :color="activityColor(value)">{{ value }}</v-chip>
        <span v-else>—</span>
      </template>
      <template #item.statusName="{ value }">
        <v-chip v-if="value" size="x-small" color="secondary">{{ value }}</v-chip>
      </template>
      <template #item.active="{ value }">
        <v-icon :color="value ? 'success' : 'grey'" size="small">
          {{ value ? 'mdi-check-circle' : 'mdi-minus-circle' }}
        </v-icon>
      </template>
      <template #item.isClient="{ value }">
        <v-icon v-if="value" color="success" size="small">mdi-check-circle</v-icon>
        <v-icon v-else color="grey" size="small">mdi-minus-circle</v-icon>
      </template>
      <template #item.platformAccess="{ value }">
        <v-icon v-if="value" color="success" size="small">mdi-lock-open-variant</v-icon>
        <v-icon v-else color="grey" size="small">mdi-lock</v-icon>
      </template>
    </v-data-table-server>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const activityFilter = ref(null);
const statusFilter = ref(null);
const page = ref(1);

const activityOptions = [
  { title: 'Активен', value: '1' },
  { title: 'Терминирован', value: '3' },
  { title: 'Зарегистрирован', value: '4' },
  { title: 'Исключён', value: '5' },
];

const headers = [
  { title: 'ID', key: 'id', width: 60 },
  { title: 'Person ID', key: 'personId', width: 90 },
  { title: 'ФИО', key: 'personName' },
  { title: 'Email', key: 'email' },
  { title: 'Телефон', key: 'phone', width: 140 },
  { title: 'Дата рождения', key: 'birthDate', width: 130 },
  { title: 'Статус', key: 'statusName', width: 140 },
  { title: 'Активность', key: 'activityName', width: 130 },
  { title: 'Активен', key: 'active', width: 80 },
  { title: 'Код', key: 'participantCode', width: 100 },
  { title: 'Пригласивший', key: 'inviterName' },
  { title: 'Куратор', key: 'curatorName' },
  { title: 'Клиент?', key: 'isClient', width: 80, sortable: false },
  { title: 'Доступ', key: 'platformAccess', width: 80, sortable: false },
  { title: 'Дата регистрации', key: 'createdAt', width: 140 },
];

function activityColor(name) {
  if (!name) return 'grey';
  const l = name.toLowerCase();
  if (l.includes('актив') && !l.includes('не')) return 'success';
  if (l.includes('терминир')) return 'error';
  return 'warning';
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
    if (statusFilter.value) params.status = statusFilter.value;
    const { data } = await api.get('/admin/partners', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
