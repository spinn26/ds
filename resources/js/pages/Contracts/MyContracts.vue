<template>
  <div>
    <PageHeader title="Контракты моих клиентов" icon="mdi-file-document" :count="total" />

    <!-- Per spec ✅Контакты моих клиентов §1: фильтры по номеру, ФИО клиента,
         датам (открытия/добавления), продукту, программе, сроку, сумме, статусу. -->
    <v-card class="mb-3 pa-3">
      <v-row dense>
        <v-col cols="12" md="2">
          <v-text-field v-model="filters.number" placeholder="№ контракта"
            density="comfortable" variant="outlined" hide-details clearable
            prepend-inner-icon="mdi-file-document"
            @update:model-value="debouncedLoad" />
        </v-col>
        <v-col cols="12" md="3">
          <v-text-field v-model="filters.clientName" placeholder="ФИО клиента"
            density="comfortable" variant="outlined" hide-details clearable
            prepend-inner-icon="mdi-account"
            @update:model-value="debouncedLoad" />
        </v-col>
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
          <v-text-field v-model="filters.createdFrom" type="date" label="Заведён с"
            density="comfortable" variant="outlined" hide-details
            @update:model-value="loadData" />
        </v-col>
        <v-col cols="12" md="2">
          <v-text-field v-model="filters.createdTo" type="date" label="Заведён по"
            density="comfortable" variant="outlined" hide-details
            @update:model-value="loadData" />
        </v-col>
        <v-col cols="12" md="3">
          <v-autocomplete v-model="filters.product" :items="productOptions"
            item-title="name" item-value="id" label="Продукт"
            density="comfortable" variant="outlined" hide-details clearable
            @update:model-value="onProductChange" />
        </v-col>
        <v-col cols="12" md="3">
          <v-autocomplete v-model="filters.program" :items="filteredPrograms"
            item-title="name" item-value="id" label="Программа"
            density="comfortable" variant="outlined" hide-details clearable
            :disabled="!filters.product"
            @update:model-value="loadData" />
        </v-col>
        <v-col cols="6" md="2">
          <v-text-field v-model.number="filters.amountMin" type="number" label="Сумма от"
            density="comfortable" variant="outlined" hide-details clearable
            @update:model-value="debouncedLoad" />
        </v-col>
        <v-col cols="6" md="2">
          <v-text-field v-model.number="filters.amountMax" type="number" label="Сумма до"
            density="comfortable" variant="outlined" hide-details clearable
            @update:model-value="debouncedLoad" />
        </v-col>
        <v-col cols="6" md="2">
          <v-text-field v-model.number="filters.termMin" type="number" label="Срок ≥, лет"
            density="comfortable" variant="outlined" hide-details clearable
            @update:model-value="debouncedLoad" />
        </v-col>
        <v-col cols="6" md="2">
          <v-text-field v-model.number="filters.termMax" type="number" label="Срок ≤, лет"
            density="comfortable" variant="outlined" hide-details clearable
            @update:model-value="debouncedLoad" />
        </v-col>
        <v-col cols="12" md="3">
          <v-select v-model="filters.status" :items="statusOptions" item-title="name" item-value="id"
            label="Статус контракта"
            density="comfortable" variant="outlined" hide-details clearable
            @update:model-value="loadData" />
        </v-col>
        <v-col cols="auto" class="d-flex align-center">
          <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal" class="ms-1">
            {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
          </v-chip>
          <v-btn v-if="activeFilterCount > 0" size="small" variant="text" color="secondary"
            prepend-icon="mdi-filter-remove" @click="resetFilters">Сбросить</v-btn>
        </v-col>
        <v-col cols="auto" class="d-flex align-center ms-auto">
          <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="my-contracts-cols" />
        </v-col>
      </v-row>
    </v-card>

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
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import { fmt, fmtDate, getContractStatusColor } from '../../composables/useDesign';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const page = ref(1);
const perPage = ref(25);
const filters = ref({
  number: '',
  clientName: '',
  product: null,
  program: null,
  status: null,
  openedFrom: '', openedTo: '',
  createdFrom: '', createdTo: '',
  amountMin: null, amountMax: null,
  termMin: null, termMax: null,
});

const productOptions = ref([]);
const programOptions = ref([]);
const statusOptions = ref([]);

const filteredPrograms = computed(() => {
  if (!filters.value.product) return programOptions.value;
  return programOptions.value.filter(p => p.productId == filters.value.product);
});

function onProductChange() {
  filters.value.program = null;
  loadData();
}

const activeFilterCount = computed(() => {
  let c = 0;
  Object.values(filters.value).forEach(v => {
    if (v !== '' && v !== null && v !== undefined) c++;
  });
  return c;
});

function resetFilters() {
  filters.value = {
    number: '', clientName: '',
    product: null, program: null, status: null,
    openedFrom: '', openedTo: '',
    createdFrom: '', createdTo: '',
    amountMin: null, amountMax: null,
    termMin: null, termMax: null,
  };
  loadData();
}

async function loadFilterOptions() {
  try {
    const [{ data: products }, { data: programs }, { data: statuses }] = await Promise.all([
      api.get('/contracts/products').catch(() => ({ data: [] })),
      api.get('/contracts/programs').catch(() => ({ data: [] })),
      api.get('/contracts/statuses').catch(() => ({ data: [] })),
    ]);
    productOptions.value = Array.isArray(products) ? products : (products?.data || []);
    programOptions.value = Array.isArray(programs) ? programs : (programs?.data || []);
    statusOptions.value = Array.isArray(statuses) ? statuses : (statuses?.data || []);
  } catch {}
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
    if (filters.value.number) params.number = filters.value.number;
    if (filters.value.clientName) params.client_name = filters.value.clientName;
    if (filters.value.product) params.product = filters.value.product;
    if (filters.value.program) params.program = filters.value.program;
    if (filters.value.status) params.status = filters.value.status;
    if (filters.value.openedFrom) params.opened_from = filters.value.openedFrom;
    if (filters.value.openedTo) params.opened_to = filters.value.openedTo;
    if (filters.value.createdFrom) params.created_from = filters.value.createdFrom;
    if (filters.value.createdTo) params.created_to = filters.value.createdTo;
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

onMounted(() => {
  loadData();
  loadFilterOptions();
});
</script>
