<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-file-document</v-icon>
      <h5 class="text-h5 font-weight-bold">Контракты моих клиентов</h5>
      <v-chip size="small" color="primary">{{ total }}</v-chip>
    </div>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="filters.search" placeholder="Поиск по ФИО / номеру..." density="compact" variant="outlined"
          prepend-inner-icon="mdi-magnify" hide-details style="max-width:260px" @update:model-value="debouncedLoad" />
        <v-select v-model="filters.status" :items="statusOptions" label="Статус" density="compact" variant="outlined"
          clearable hide-details style="max-width:200px" @update:model-value="loadData" />
        <v-autocomplete v-model="filters.product" :items="productOptions" item-title="name" item-value="id"
          label="Продукт" density="compact" variant="outlined" clearable hide-details style="max-width:240px"
          :loading="loadingProducts" @update:search="searchProducts" @update:model-value="loadData" />
        <v-text-field v-model="filters.date_from" label="С" type="date" density="compact" variant="outlined"
          hide-details style="max-width:160px" @update:model-value="loadData" />
        <v-text-field v-model="filters.date_to" label="По" type="date" density="compact" variant="outlined"
          hide-details style="max-width:160px" @update:model-value="loadData" />
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" @update:options="onOptions"
      density="compact" hover no-data-text="Контракты не найдены" @click:row="onRowClick">
      <template #item.ammount="{ item }">
        {{ fmt(item.ammount) }} {{ item.currencySymbol }}
      </template>
      <template #item.statusName="{ value }">
        <v-chip size="x-small" :color="statusColor(value)">{{ value }}</v-chip>
      </template>
    </v-data-table-server>

    <!-- Detail dialog -->
    <v-dialog v-model="showDetail" max-width="500">
      <v-card v-if="selectedItem">
        <v-card-title>Контракт {{ selectedItem.number }}</v-card-title>
        <v-card-text>
          <v-table density="compact">
            <tbody>
              <tr><td class="text-medium-emphasis">Клиент</td><td>{{ selectedItem.clientName }}</td></tr>
              <tr><td class="text-medium-emphasis">Дата заключения</td><td>{{ selectedItem.createDate || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">Вендор</td><td>{{ selectedItem.vendorName || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">Провайдер</td><td>{{ selectedItem.providerName || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">ID контрагента</td><td>{{ selectedItem.counterpartyContractId || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">Статус баллов</td><td>{{ selectedItem.pointsStatus || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">Комментарий</td><td>{{ selectedItem.comment || '—' }}</td></tr>
            </tbody>
          </v-table>
        </v-card-text>
        <v-card-actions><v-btn @click="showDetail = false">Закрыть</v-btn></v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const page = ref(1);
const statusOptions = ref([]);
const productOptions = ref([]);
const loadingProducts = ref(false);
const filters = ref({ search: '', status: null, product: null, date_from: '', date_to: '' });

const selectedItem = ref(null);
const showDetail = ref(false);

function onRowClick(_e, { item }) {
  selectedItem.value = item;
  showDetail.value = true;
}

const headers = [
  { title: 'Номер', key: 'number', width: 120 },
  { title: 'Клиент', key: 'clientName' },
  { title: 'Дата заключения', key: 'createDate', width: 130 },
  { title: 'Дата открытия', key: 'openDate', width: 120 },
  { title: 'Продукт', key: 'productName' },
  { title: 'Программа', key: 'programName' },
  { title: 'Срок', key: 'term', width: 80 },
  { title: 'Сумма', key: 'ammount', width: 140 },
  { title: 'Статус', key: 'statusName', width: 130 },
];

const fmt = (n) => Number(n || 0).toLocaleString('ru-RU');

function statusColor(s) {
  if (!s) return 'grey';
  const l = s.toLowerCase();
  if (l.includes('актив') || l.includes('действ')) return 'success';
  if (l.includes('закр') || l.includes('заверш')) return 'error';
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
    if (filters.value.status) params.status = filters.value.status;
    if (filters.value.product) params.product = filters.value.product;
    if (filters.value.date_from) params.date_from = filters.value.date_from;
    if (filters.value.date_to) params.date_to = filters.value.date_to;
    const { data } = await api.get('/contracts/my', { params });
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
