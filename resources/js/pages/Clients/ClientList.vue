<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-account-group</v-icon>
      <h5 class="text-h5 font-weight-bold">Мои клиенты</h5>
      <v-chip size="small" color="primary">{{ total }}</v-chip>
    </div>

    <v-card class="mb-3 pa-3">
      <v-text-field v-model="search" placeholder="Поиск по ФИО..." density="compact" variant="outlined"
        prepend-inner-icon="mdi-magnify" hide-details style="max-width:300px" @update:model-value="debouncedLoad" />
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" @update:options="onOptions"
      density="compact" hover no-data-text="Клиенты не найдены" />
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
  { title: 'ФИО клиента', key: 'personName' },
  { title: 'Дата рождения', key: 'birthDate', width: 140 },
  { title: 'Место жительства', key: 'city' },
  { title: 'Телефон', key: 'phone', width: 160 },
  { title: 'Email', key: 'email', width: 220 },
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
    const { data } = await api.get('/clients', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
