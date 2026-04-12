<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-credit-card</v-icon>
      <h5 class="text-h5 font-weight-bold">Реквизиты партнёров</h5>
      <v-chip size="small" color="primary">{{ total }}</v-chip>
    </div>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по ФИО, ИНН..." density="compact" variant="outlined"
          prepend-inner-icon="mdi-magnify" hide-details style="max-width:300px" @update:model-value="debouncedLoad" />
        <v-select v-model="statusFilter" :items="verifyOptions" label="Статус верификации" density="compact" variant="outlined"
          clearable hide-details style="max-width:220px" @update:model-value="loadData" />
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" @update:options="onOptions"
      density="compact" hover no-data-text="Реквизиты не найдены">
      <template #item.verificationStatus="{ item }">
        <v-chip size="x-small" :color="verifyColor(item.verificationStatus)">
          {{ verifyLabel(item.verificationStatus) }}
        </v-chip>
      </template>
      <template #item.actions="{ item }">
        <v-btn v-if="item.verificationStatus !== 'verified'" icon="mdi-check" size="x-small" variant="text" color="success"
          title="Подтвердить" :loading="item._verifying" @click="verify(item)" />
        <v-btn v-if="item.verificationStatus !== 'rejected'" icon="mdi-close" size="x-small" variant="text" color="error"
          title="Отклонить" :loading="item._rejecting" @click="reject(item)" />
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
const statusFilter = ref(null);
const page = ref(1);

const verifyOptions = [
  { title: 'На проверке', value: 'pending' },
  { title: 'Подтверждено', value: 'verified' },
  { title: 'Отклонено', value: 'rejected' },
];

const headers = [
  { title: 'ID', key: 'id', width: 60 },
  { title: 'Партнёр', key: 'partnerName' },
  { title: 'ИП', key: 'individualEntrepreneur' },
  { title: 'ИНН', key: 'inn', width: 130 },
  { title: 'Банк', key: 'bankName' },
  { title: 'Счёт', key: 'accountNumber', width: 200 },
  { title: 'Статус', key: 'verificationStatus', width: 130 },
  { title: 'Действия', key: 'actions', sortable: false, width: 100 },
];

function verifyColor(s) {
  if (s === 'verified') return 'success';
  if (s === 'rejected') return 'error';
  return 'warning';
}

function verifyLabel(s) {
  if (s === 'verified') return 'Подтверждено';
  if (s === 'rejected') return 'Отклонено';
  return 'На проверке';
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
    if (statusFilter.value) params.status = statusFilter.value;
    const { data } = await api.get('/admin/requisites', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

async function verify(item) {
  item._verifying = true;
  try {
    await api.post(`/admin/requisites/${item.id}/verify`);
    loadData();
  } catch {}
  item._verifying = false;
}

async function reject(item) {
  item._rejecting = true;
  try {
    await api.post(`/admin/requisites/${item.id}/reject`);
    loadData();
  } catch {}
  item._rejecting = false;
}

onMounted(loadData);
</script>
