<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-account-group</v-icon>
      <h5 class="text-h5 font-weight-bold">Клиенты</h5>
      <v-chip size="small" color="primary">{{ total }}</v-chip>
    </div>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по ФИО, телефону, email..." density="compact" variant="outlined"
          prepend-inner-icon="mdi-magnify" hide-details style="max-width:300px" @update:model-value="debouncedLoad" />
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" @update:options="onOptions"
      density="compact" hover no-data-text="Клиенты не найдены">
      <template #item.isPartner="{ value }">
        <v-icon v-if="value" color="success" size="small">mdi-check-circle</v-icon>
      </template>
      <template #item.products="{ value }">
        <v-chip v-for="p in (value || [])" :key="p" size="x-small" class="mr-1" color="primary" variant="outlined">{{ p }}</v-chip>
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
const page = ref(1);

const headers = [
  { title: 'ID', key: 'id', width: 60 },
  { title: 'DS ID', key: 'dsId', width: 80 },
  { title: 'Person ID', key: 'personId', width: 90 },
  { title: 'ФИО', key: 'personName' },
  { title: 'Email', key: 'email' },
  { title: 'Телефон', key: 'phone' },
  { title: 'Дата рождения', key: 'birthDate', width: 130 },
  { title: 'Город', key: 'city' },
  { title: 'Работаем с', key: 'workSince', width: 130 },
  { title: 'Контракты', key: 'contractCount', width: 110, align: 'end' },
  { title: 'Партнёр?', key: 'isPartner', width: 90, sortable: false },
  { title: 'Консультант', key: 'consultantName' },
  { title: 'Комментарий', key: 'comment' },
  { title: 'Продукты', key: 'products', sortable: false },
];

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
    const { data } = await api.get('/admin/clients', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
