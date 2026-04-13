<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-check-circle</v-icon>
      <h5 class="text-h5 font-weight-bold">Акцепт документов</h5>
      <v-chip size="small" color="primary">{{ total }}</v-chip>
    </div>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по ФИО партнёра..." density="compact" variant="outlined"
          prepend-inner-icon="mdi-magnify" hide-details style="max-width:300px" @update:model-value="debouncedLoad" />
        <v-select v-model="acceptedFilter" :items="acceptedOptions" label="Акцепт" density="compact" variant="outlined"
          clearable hide-details style="max-width:200px" @update:model-value="loadData" />
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" @update:options="onOptions"
      density="compact" hover no-data-text="Записи не найдены">
      <template #item.accepted="{ value }">
        <v-icon :color="value ? 'success' : 'grey'" size="20">
          {{ value ? 'mdi-checkbox-marked-circle' : 'mdi-checkbox-blank-circle-outline' }}
        </v-icon>
      </template>
      <template #item.dateAccepted="{ value }">
        {{ value ? new Date(value).toLocaleDateString('ru-RU') + ' ' + new Date(value).toLocaleTimeString('ru-RU', {hour:'2-digit',minute:'2-digit'}) : '—' }}
      </template>
      <template #item.source="{ value }">
        <v-chip v-if="value" size="x-small" :color="value === 'platform' ? 'primary' : 'secondary'" variant="tonal">
          {{ value === 'platform' ? 'Платформа' : value === 'getcourse' ? 'GetCourse' : value }}
        </v-chip>
        <span v-else>—</span>
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
const acceptedFilter = ref(null);

const acceptedOptions = [
  { title: 'Акцептовано', value: 'true' },
  { title: 'Не акцептовано', value: 'false' },
];

const headers = [
  { title: 'Партнёр', key: 'personName' },
  { title: 'Документы акцептованы', key: 'accepted', width: 180, align: 'center' },
  { title: 'Дата акцепта', key: 'dateAccepted', width: 180 },
  { title: 'Источник', key: 'source', width: 140 },
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
    if (acceptedFilter.value) params.accepted = acceptedFilter.value;
    const { data } = await api.get('/admin/acceptance', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
