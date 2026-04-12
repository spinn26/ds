<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-cash</v-icon>
      <h5 class="text-h5 font-weight-bold">Реестр выплат</h5>
      <v-chip size="small" color="primary">{{ total }}</v-chip>
    </div>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по партнёру..." density="compact" variant="outlined"
          prepend-inner-icon="mdi-magnify" hide-details style="max-width:300px" @update:model-value="debouncedLoad" />
        <v-text-field v-model="month" type="month" label="Месяц" density="compact" variant="outlined"
          hide-details style="max-width:200px" @update:model-value="loadData" />
        <v-select v-model="statusFilter" :items="statusOptions" label="Статус" density="compact" variant="outlined"
          clearable hide-details style="max-width:200px" @update:model-value="loadData" />
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" @update:options="onOptions"
      density="compact" hover no-data-text="Выплаты не найдены">
      <template #item.amount="{ value }">
        {{ fmt(value) }}
      </template>
      <template #item.status="{ value }">
        <v-chip size="x-small" :color="value === 'paid' ? 'success' : value === 'pending' ? 'warning' : 'grey'">
          {{ value === 'paid' ? 'Выплачено' : value === 'pending' ? 'В обработке' : value }}
        </v-chip>
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
const month = ref(new Date().toISOString().slice(0, 7));
const statusFilter = ref(null);
const page = ref(1);

const statusOptions = [
  { title: 'Выплачено', value: 'paid' },
  { title: 'В обработке', value: 'pending' },
];

const headers = [
  { title: 'ID', key: 'id', width: 60 },
  { title: 'Партнёр', key: 'partnerName' },
  { title: 'Сумма', key: 'amount', width: 140 },
  { title: 'Валюта', key: 'currency', width: 80 },
  { title: 'Статус', key: 'status', width: 130 },
  { title: 'Дата выплаты', key: 'paidAt', width: 140 },
  { title: 'Период', key: 'period', width: 120 },
];

const fmt = (n) => Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2 });

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
    if (month.value) params.month = month.value;
    if (statusFilter.value) params.status = statusFilter.value;
    const { data } = await api.get('/admin/payments', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
