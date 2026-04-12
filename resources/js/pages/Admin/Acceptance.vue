<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-check-circle</v-icon>
      <h5 class="text-h5 font-weight-bold">Акцепт документов</h5>
      <v-chip size="small" color="primary">{{ total }}</v-chip>
    </div>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по партнёру, документу..." density="compact" variant="outlined"
          prepend-inner-icon="mdi-magnify" hide-details style="max-width:300px" @update:model-value="debouncedLoad" />
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" @update:options="onOptions"
      density="compact" hover no-data-text="Записи не найдены">
      <template #item.accepted="{ value }">
        <v-icon :color="value ? 'success' : 'grey'" size="small">
          {{ value ? 'mdi-check-circle' : 'mdi-minus-circle' }}
        </v-icon>
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
  { title: 'Партнёр', key: 'partnerName' },
  { title: 'Документ', key: 'documentName' },
  { title: 'Акцептован', key: 'accepted', width: 110 },
  { title: 'Дата акцепта', key: 'acceptedAt', width: 150 },
  { title: 'IP', key: 'ipAddress', width: 140 },
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
    const { data } = await api.get('/admin/acceptance', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
