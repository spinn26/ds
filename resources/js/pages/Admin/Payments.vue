<template>
  <div>
    <PageHeader title="Реестр выплат" icon="mdi-cash" :count="total" />

    <FilterBar
      :search="search"
      search-placeholder="Поиск по партнёру..."
      :search-cols="3"
      :show-reset="activeFilterCount > 0"
      @update:search="v => { search = v ?? ''; debouncedLoad(); }"
      @reset="resetFilters"
    >
      <v-col cols="12" md="3">
        <v-select v-model="statusFilter" :items="statusOptions" label="Статус"
          variant="outlined" density="comfortable"
          clearable hide-details @update:model-value="loadData" />
      </v-col>
      <v-col v-if="activeFilterCount > 0" cols="auto" class="d-flex align-center">
        <v-chip size="small" color="info" variant="tonal">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
        </v-chip>
      </v-col>
    </FilterBar>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" @update:options="onOptions">
      <template #item.amount="{ value }">{{ fmt(value) }}</template>
      <template #item.status="{ value }">
        <StatusChip
          :value="value"
          kind="payment"
          size="x-small"
          :text="value == 2 ? 'Выплачено' : value == 1 ? 'В обработке' : (value ?? '—')"
        />
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
import StatusChip from '../../components/StatusChip.vue';
import FilterBar from '../../components/FilterBar.vue';
import { fmt2 as fmt, getPaymentStatusColor } from '../../composables/useDesign';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const statusFilter = ref(null);
const page = ref(1);
const perPage = ref(25);

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
function onOptions(opts) {
  page.value = opts.page;
  if (opts.itemsPerPage) perPage.value = opts.itemsPerPage;
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value };
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
