<template>
  <div>
    <PageHeader title="Контракты моей команды" icon="mdi-folder-account" :count="total" />

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="filters.search" placeholder="ФИО / номер контракта..."
          rounded prepend-inner-icon="mdi-magnify" clearable hide-details style="max-width:240px" @update:model-value="debouncedLoad" />
        <v-text-field v-model="filters.consultant_search" placeholder="ФИО ФК..."
          rounded prepend-inner-icon="mdi-account-search" clearable hide-details style="max-width:220px" @update:model-value="debouncedLoad" />
        <v-select v-model="filters.status" :items="statusOptions" label="Статус контракта"
          clearable hide-details style="max-width:220px" @update:model-value="loadData" />
        <v-text-field v-model="filters.date_from" label="Дата открытия с" type="date"
          hide-details style="max-width:170px" @update:model-value="loadData" />
        <v-text-field v-model="filters.date_to" label="Дата открытия по" type="date"
          hide-details style="max-width:170px" @update:model-value="loadData" />
        <v-autocomplete v-model="filters.product" :items="productOptions" item-title="name" item-value="id"
          label="Продукт" clearable hide-details style="max-width:240px"
          :loading="loadingProducts" @update:search="searchProducts" @update:model-value="loadData" />
        <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal" class="ml-1">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : activeFilterCount < 5 ? 'фильтра' : 'фильтров' }}
        </v-chip>
        <v-btn v-if="activeFilterCount > 0" size="small" variant="text" color="secondary"
          prepend-icon="mdi-filter-remove" @click="resetFilters">Сбросить</v-btn>
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" @update:options="onOptions">
      <template #item.ammount="{ item }">
        {{ fmt(item.ammount) }} {{ item.currencySymbol }}
      </template>
      <template #item.openDate="{ value }">
        {{ fmtDate(value) }}
      </template>
      <template #item.statusName="{ value }">
        <v-chip size="x-small" :color="statusColor(value)">{{ value }}</v-chip>
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
const page = ref(1);
const statusOptions = ref([]);
const productOptions = ref([]);
const loadingProducts = ref(false);
const filters = ref({ search: '', consultant_search: '', status: null, product: null, date_from: '', date_to: '' });

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
  { title: 'ФИО клиента', key: 'clientName', cellProps: { style: 'white-space:nowrap' } },
  { title: 'ФИО ФК', key: 'consultantName', cellProps: { style: 'white-space:nowrap' } },
  { title: 'Дата открытия', key: 'openDate', width: 130 },
  { title: 'Продукт', key: 'productName' },
  { title: 'Программа', key: 'programName' },
  { title: 'Срок контракта', key: 'term', width: 120 },
  { title: 'Сумма', key: 'ammount', width: 150 },
  { title: 'Статус контракта', key: 'statusName', width: 150 },
];

function statusColor(s) {
  if (!s) return 'grey';
  const l = s.toLowerCase();
  if (l.includes('актив') || l.includes('действ')) return 'success';
  if (l.includes('закр') || l.includes('заверш') || l.includes('терминир') || l.includes('исключ')) return 'error';
  if (l.includes('зарег')) return 'info';
  return 'warning';
}

const { debounced: debouncedLoad } = useDebounce(loadData, 400);

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
  searchProducts('');
});
</script>
