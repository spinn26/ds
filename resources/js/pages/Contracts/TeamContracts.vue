<template>
  <div>
    <PageHeader title="Контракты моей команды" icon="mdi-folder-account" :count="total" />

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 align-center">
        <v-text-field v-model="filters.search" placeholder="Поиск по ФИО, номеру, продукту, консультанту..."
          rounded prepend-inner-icon="mdi-magnify" clearable hide-details class="flex-grow-1" style="max-width:500px"
          @update:model-value="debouncedLoad" />
        <v-btn v-if="filters.search" size="small" variant="text" color="secondary"
          prepend-icon="mdi-filter-remove" @click="filters.search = ''; loadData()">Сбросить</v-btn>
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
import { useDebounce } from '../../composables/useDebounce';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import { fmt, fmtDate } from '../../composables/useDesign';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const page = ref(1);
const filters = ref({ search: '' });


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
    const { data } = await api.get('/contracts/team', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
