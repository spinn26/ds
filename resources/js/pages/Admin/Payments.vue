<template>
  <div>
    <PageHeader title="Реестр выплат" icon="mdi-cash" :count="total" />

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по партнёру..."
          rounded prepend-inner-icon="mdi-magnify" clearable hide-details style="max-width:300px" @update:model-value="debouncedLoad" />
        <v-select v-model="statusFilter" :items="statusOptions" label="Статус"
          clearable hide-details style="max-width:200px" @update:model-value="loadData" />
        <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal" class="ml-1">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
        </v-chip>
        <v-btn v-if="activeFilterCount > 0" size="small" variant="text" color="secondary"
          prepend-icon="mdi-filter-remove" @click="resetFilters">Сбросить</v-btn>
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" @update:options="onOptions">
      <template #item.amount="{ value }">{{ fmt(value) }}</template>
      <template #item.status="{ value }">
        <v-chip size="x-small" :color="value == 2 ? 'success' : value == 1 ? 'warning' : 'grey'">
          {{ value == 2 ? 'Выплачено' : value == 1 ? 'В обработке' : value ?? '—' }}
        </v-chip>
      </template>
      <template #item.paymentDate="{ value }">
        {{ value ? new Date(value).toLocaleDateString('ru-RU') : '—' }}
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
import { fmt2 as fmt } from '../../composables/useDesign';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const statusFilter = ref(null);
const page = ref(1);

const activeFilterCount = computed(() => {
  let c = 0;
  if (search.value) c++;
  if (statusFilter.value) c++;
  return c;
});

function resetFilters() {
  search.value = '';
  statusFilter.value = null;
  loadData();
}

const statusOptions = [
  { title: 'Выплачено', value: '2' },
  { title: 'В обработке', value: '1' },
];

const headers = [
  { title: 'ID', key: 'id', width: 60 },
  { title: 'Партнёр', key: 'consultantName' },
  { title: 'Сумма', key: 'amount', align: 'end', width: 140 },
  { title: 'Статус', key: 'status', width: 130 },
  { title: 'Дата выплаты', key: 'paymentDate', width: 140 },
  { title: 'Комментарий', key: 'comment' },
];

const { debounced: debouncedLoad } = useDebounce(loadData, 400);
function onOptions(opts) { page.value = opts.page; loadData(); }

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value };
    if (search.value) params.search = search.value;
    if (statusFilter.value) params.status = statusFilter.value;
    const { data } = await api.get('/admin/payments', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
