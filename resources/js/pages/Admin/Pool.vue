<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-cash-multiple</v-icon>
      <h5 class="text-h5 font-weight-bold">Комиссии пула</h5>
      <v-chip size="small" color="primary">{{ total }}</v-chip>
    </div>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="month" type="month" label="Месяц" density="compact" variant="outlined"
          hide-details style="max-width:200px" @update:model-value="loadData" />
        <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal" class="ml-1">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
        </v-chip>
        <v-btn v-if="activeFilterCount > 0" size="small" variant="text" color="secondary"
          prepend-icon="mdi-filter-remove" @click="resetFilters">Сбросить</v-btn>
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="computedHeaders" :items-per-page="25" @update:options="onOptions"
      density="compact" hover>
      <template #no-data>
        <div class="text-center pa-4">
          <v-icon size="48" color="grey-lighten-1" class="mb-2">mdi-file-search-outline</v-icon>
          <div class="text-medium-emphasis">Данные не найдены</div>
        </div>
      </template>
    </v-data-table-server>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const month = ref(new Date().toISOString().slice(0, 7));
const page = ref(1);
const defaultMonth = new Date().toISOString().slice(0, 7);

// Build headers dynamically from first response row since API returns raw DB columns
const detectedKeys = ref([]);
const computedHeaders = computed(() => {
  if (detectedKeys.value.length) {
    return detectedKeys.value.map(k => ({ title: k, key: k }));
  }
  // Fallback headers
  return [
    { title: 'ID', key: 'id', width: 60 },
    { title: 'Дата', key: 'date', width: 120 },
  ];
});

const activeFilterCount = computed(() => {
  let c = 0;
  if (month.value && month.value !== defaultMonth) c++;
  return c;
});

function resetFilters() {
  month.value = defaultMonth;
  loadData();
}

function onOptions(opts) {
  page.value = opts.page;
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value };
    if (month.value) params.month = month.value;
    const { data } = await api.get('/admin/pool', { params });
    items.value = data.data;
    total.value = data.total;
    // Auto-detect columns from first row
    if (data.data?.length && !detectedKeys.value.length) {
      detectedKeys.value = Object.keys(data.data[0]);
    }
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
