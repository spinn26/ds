<template>
  <div>
    <PageHeader title="Контракты моих клиентов" icon="mdi-file-document" :count="total" />

    <FilterBar
      :search="filters.search"
      search-placeholder="Поиск по ФИО, номеру, продукту..."
      :search-cols="3"
      :show-reset="activeFilterCount > 0"
      @update:search="v => { filters.search = v ?? ''; debouncedLoad(); }"
      @reset="resetFilters"
    >
      <v-col cols="12" md="2">
        <v-text-field v-model="filters.openedFrom" type="date" label="Открыт с"
          density="comfortable" variant="outlined" hide-details
          @update:model-value="loadData" />
      </v-col>
      <v-col cols="12" md="2">
        <v-text-field v-model="filters.openedTo" type="date" label="Открыт по"
          density="comfortable" variant="outlined" hide-details
          @update:model-value="loadData" />
      </v-col>
      <v-col cols="12" md="2">
        <v-text-field v-model.number="filters.amountMin" type="number" label="Сумма от"
          density="comfortable" variant="outlined" hide-details clearable
          @update:model-value="debouncedLoad" />
      </v-col>
      <v-col cols="12" md="2">
        <v-text-field v-model.number="filters.amountMax" type="number" label="Сумма до"
          density="comfortable" variant="outlined" hide-details clearable
          @update:model-value="debouncedLoad" />
      </v-col>
      <v-col cols="12" md="2">
        <v-text-field v-model.number="filters.termMin" type="number" label="Срок ≥, лет"
          density="comfortable" variant="outlined" hide-details clearable
          @update:model-value="debouncedLoad" />
      </v-col>
      <v-col cols="12" md="2">
        <v-text-field v-model.number="filters.termMax" type="number" label="Срок ≤, лет"
          density="comfortable" variant="outlined" hide-details clearable
          @update:model-value="debouncedLoad" />
      </v-col>
      <v-col v-if="activeFilterCount > 0" cols="auto" class="d-flex align-center">
        <v-chip size="small" color="info" variant="tonal">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
        </v-chip>
      </v-col>
      <v-col cols="auto" class="d-flex align-center ms-auto">
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="my-contracts-cols" />
      </v-col>
    </FilterBar>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="visibleHeaders" :items-per-page="25" @update:options="onOptions">
      <template #item.ammount="{ item }">
        {{ fmt(item.ammount) }} {{ item.currencySymbol }}
      </template>
      <template #item.openDate="{ value }">
        {{ fmtDate(value) }}
      </template>
      <template #item.statusName="{ value }">
        <StatusChip :value="value" kind="contract" size="x-small" :text="value" />
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
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import { fmt, fmtDate, getContractStatusColor } from '../../composables/useDesign';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const page = ref(1);
const perPage = ref(25);
const filters = ref({
  search: '',
  openedFrom: '', openedTo: '',
  amountMin: null, amountMax: null,
  termMin: null, termMax: null,
});

const activeFilterCount = computed(() => {
  let c = 0;
  Object.values(filters.value).forEach(v => {
    if (v !== '' && v !== null && v !== undefined) c++;
  });
  return c;
});

function resetFilters() {
  filters.value = {
    search: '', openedFrom: '', openedTo: '',
    amountMin: null, amountMax: null,
    termMin: null, termMax: null,
  };
  loadData();
}

const nowrap = { style: 'white-space:nowrap' };
const headers = [
  { title: 'Номер контракта', key: 'number', width: 140, cellProps: nowrap },
  { title: 'ФИО клиента', key: 'clientName', cellProps: nowrap },
  { title: 'Дата добавления', key: 'createDate', width: 130, cellProps: nowrap },
  { title: 'Дата открытия', key: 'openDate', width: 130, cellProps: nowrap },
  { title: 'Продукт', key: 'productName', cellProps: nowrap },
  { title: 'Программа', key: 'programName', cellProps: nowrap },
  { title: 'Срок контракта', key: 'term', width: 120, cellProps: nowrap },
  { title: 'Сумма', key: 'ammount', width: 160, align: 'end', cellProps: nowrap },
  { title: 'Статус контракта', key: 'statusName', width: 170, cellProps: nowrap },
];

const columnVisible = ref({});
const visibleHeaders = computed(() => headers.filter(h => columnVisible.value[h.key] !== false));

const { debounced: debouncedLoad } = useDebounce(loadData, 400);

let productTimer;
function searchProducts(q) {
  clearTimeout(productTimer);
  productTimer = setTimeout(async () => {
    loadingProducts.value = true;
    try {
      const { data } = await api.get('/contracts/products', { params: { q } });
      productOptions.value = data;
    } catch {}
    loadingProducts.value = false;
  }, 300);
}

function onOptions(opts) {
  page.value = opts.page;
  if (opts.itemsPerPage) perPage.value = opts.itemsPerPage;
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value };
    if (filters.value.search) params.search = filters.value.search;
    if (filters.value.openedFrom) params.opened_from = filters.value.openedFrom;
    if (filters.value.openedTo) params.opened_to = filters.value.openedTo;
    if (filters.value.amountMin != null && filters.value.amountMin !== '') params.amount_min = filters.value.amountMin;
    if (filters.value.amountMax != null && filters.value.amountMax !== '') params.amount_max = filters.value.amountMax;
    if (filters.value.termMin != null && filters.value.termMin !== '') params.term_min = filters.value.termMin;
    if (filters.value.termMax != null && filters.value.termMax !== '') params.term_max = filters.value.termMax;
    const { data } = await api.get('/contracts/my', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
