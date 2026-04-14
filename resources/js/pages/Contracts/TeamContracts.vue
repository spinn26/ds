<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-folder-account</v-icon>
      <h5 class="text-h5 font-weight-bold">Контракты моей команды</h5>
      <v-chip size="small" color="primary">{{ total }}</v-chip>
    </div>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="filters.search" placeholder="ФИО / номер контракта..." density="compact" variant="outlined"
          rounded prepend-inner-icon="mdi-magnify" clearable hide-details style="max-width:240px" @update:model-value="debouncedLoad" />
        <v-text-field v-model="filters.consultant_search" placeholder="ФИО ФК..." density="compact" variant="outlined"
          rounded prepend-inner-icon="mdi-account-search" clearable hide-details style="max-width:220px" @update:model-value="debouncedLoad" />
        <v-select v-model="filters.status" :items="statusOptions" label="Статус контракта" density="compact" variant="outlined"
          clearable hide-details style="max-width:220px" @update:model-value="loadData" />
        <v-text-field v-model="filters.date_from" label="Дата открытия с" type="date" density="compact" variant="outlined"
          hide-details style="max-width:170px" @update:model-value="loadData" />
        <v-text-field v-model="filters.date_to" label="Дата открытия по" type="date" density="compact" variant="outlined"
          hide-details style="max-width:170px" @update:model-value="loadData" />
        <v-autocomplete v-model="filters.product" :items="productOptions" item-title="name" item-value="id"
          label="Продукт" density="compact" variant="outlined" clearable hide-details style="max-width:240px"
          :loading="loadingProducts" @update:search="searchProducts" @update:model-value="loadData" />
        <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal" class="ml-1">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : activeFilterCount < 5 ? 'фильтра' : 'фильтров' }}
        </v-chip>
        <v-btn v-if="activeFilterCount > 0" size="small" variant="text" color="secondary"
          prepend-icon="mdi-filter-remove" @click="resetFilters">Сбросить</v-btn>
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" @update:options="onOptions"
      density="compact" hover>
      <template #item.ammount="{ item }">
        {{ fmt(item.ammount) }} {{ item.currencySymbol }}
      </template>
      <template #item.openDate="{ value }">
        {{ fmtDate(value) }}
      </template>
      <template #item.statusName="{ value }">
        <v-chip size="x-small" :color="statusColor(value)">{{ value }}</v-chip>
      </template>
      <template #no-data>
        <div class="text-center pa-4">
          <v-icon size="48" color="grey-lighten-1" class="mb-2">mdi-file-search-outline</v-icon>
          <div class="text-medium-emphasis">Данные не найдены</div>
        </div>
      </template>
    </v-data-table-server>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const page = ref(1);
const statusOptions = ref([]);
const productOptions = ref([]);
const loadingProducts = ref(false);
const filters = ref({ search: '', consultant_search: '', status: null, product: null, date_from: '', date_to: '' });

function fmtDate(d) { if (!d) return '—'; try { return new Date(d).toLocaleDateString('ru-RU'); } catch { return d; } }

const activeFilterCount = computed(() => {
  let c = 0;
  if (filters.value.search) c++;
  if (filters.value.consultant_search) c++;
  if (filters.value.status) c++;
  if (filters.value.product) c++;
  if (filters.value.date_from) c++;
  if (filters.value.date_to) c++;
  return c;
});

function resetFilters() {
  filters.value = { search: '', consultant_search: '', status: null, product: null, date_from: '', date_to: '' };
  loadData();
}

const headers = [
  { title: 'Номер контракта', key: 'number', width: 140 },
  { title: 'ФИО клиента', key: 'clientName' },
  { title: 'ФИО ФК', key: 'consultantName' },
  { title: 'Дата открытия', key: 'openDate', width: 130 },
  { title: 'Продукт', key: 'productName' },
  { title: 'Программа', key: 'programName' },
  { title: 'Срок контракта', key: 'term', width: 120 },
  { title: 'Сумма', key: 'ammount', width: 150 },
  { title: 'Статус контракта', key: 'statusName', width: 150 },
];

const fmt = (n) => Number(n || 0).toLocaleString('ru-RU');

function statusColor(s) {
  if (!s) return 'grey';
  const l = s.toLowerCase();
  if (l.includes('актив') || l.includes('действ')) return 'success';
  if (l.includes('закр') || l.includes('заверш') || l.includes('терминир') || l.includes('исключ')) return 'error';
  if (l.includes('зарег')) return 'info';
  return 'warning';
}

let debounceTimer;
function debouncedLoad() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(loadData, 400);
}

let productTimer;
function searchProducts(q) {
  clearTimeout(productTimer);
  productTimer = setTimeout(async () => {
    loadingProducts.value = true;
    try {
      const { data } = await api.get('/contracts/products', { params: { q } });
      productOptions.value = data;
    } catch {}
    loadingProducts.value = false;
  }, 300);
}

function onOptions(opts) {
  page.value = opts.page;
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value };
    if (filters.value.search) params.search = filters.value.search;
    if (filters.value.consultant_search) params.consultant_search = filters.value.consultant_search;
    if (filters.value.status) params.status = filters.value.status;
    if (filters.value.product) params.product = filters.value.product;
    if (filters.value.date_from) params.date_from = filters.value.date_from;
    if (filters.value.date_to) params.date_to = filters.value.date_to;
    const { data } = await api.get('/contracts/team', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

async function loadStatuses() {
  try {
    const { data } = await api.get('/contracts/statuses');
    statusOptions.value = data.map(s => ({ title: s.name, value: s.id }));
  } catch {}
}

onMounted(() => {
  loadData();
  loadStatuses();
});
</script>
