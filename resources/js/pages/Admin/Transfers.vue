<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-history</v-icon>
      <h5 class="text-h5 font-weight-bold">История перестановок</h5>
      <v-chip size="small" color="primary">{{ total }}</v-chip>
    </div>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по партнёру..." density="compact" variant="outlined"
          prepend-inner-icon="mdi-magnify" hide-details style="max-width:300px" @update:model-value="debouncedLoad" />
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" @update:options="onOptions"
      density="compact" hover no-data-text="Перестановки не найдены">
      <template #item.status="{ value }">
        <v-chip size="x-small" :color="value === 'completed' ? 'success' : value === 'pending' ? 'warning' : 'grey'">
          {{ value === 'completed' ? 'Выполнено' : value === 'pending' ? 'В ожидании' : value }}
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
const page = ref(1);

const headers = [
  { title: 'ID', key: 'id', width: 60 },
  { title: 'Партнёр', key: 'partnerName' },
  { title: 'Откуда (куратор)', key: 'fromCuratorName' },
  { title: 'Куда (куратор)', key: 'toCuratorName' },
  { title: 'Дата', key: 'createdAt', width: 150 },
  { title: 'Статус', key: 'status', width: 120 },
  { title: 'Комментарий', key: 'comment' },
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
    const { data } = await api.get('/admin/transfers', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
