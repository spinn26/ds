<template>
  <div>
    <PageHeader title="Контракты моих клиентов" icon="mdi-file-document" :count="total" />

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 align-center">
        <v-text-field v-model="filters.search" placeholder="Поиск по ФИО, номеру, продукту..."
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
        <v-chip size="x-small" :color="getContractStatusColor(value)">{{ value }}</v-chip>
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
import { fmt, fmtDate, getContractStatusColor } from '../../composables/useDesign';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const page = ref(1);
const perPage = ref(25);
const filters = ref({ search: '' });

const nowrap = { style: 'white-space:nowrap' };
const headers = [
  { title: 'Номер контракта', key: 'number', width: 140, cellProps: nowrap },
  { title: 'ФИО клиента', key: 'clientName', cellProps: nowrap },
  { title: 'Дата открытия', key: 'openDate', width: 130, cellProps: nowrap },
  { title: 'Продукт', key: 'productName', cellProps: nowrap },
  { title: 'Программа', key: 'programName', cellProps: nowrap },
  { title: 'Срок контракта', key: 'term', width: 120, cellProps: nowrap },
  { title: 'Сумма', key: 'ammount', width: 160, align: 'end', cellProps: nowrap },
  { title: 'Статус контракта', key: 'statusName', width: 170, cellProps: nowrap },
];

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
  if (opts.itemsPerPage) perPage.value = opts.itemsPerPage;
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value };
    if (filters.value.search) params.search = filters.value.search;
    const { data } = await api.get('/contracts/my', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
