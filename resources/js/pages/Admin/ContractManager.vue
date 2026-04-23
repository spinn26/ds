<template>
  <div>
    <PageHeader title="Менеджер контрактов" icon="mdi-file-document-edit" :count="total" />

    <FilterBar
      :search="search"
      search-placeholder="Поиск по номеру, клиенту..."
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
      <v-col cols="auto" class="d-flex align-center ms-auto">
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="contract-manager-cols" />
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
      <template #item.chat="{ item }">
        <StartChatButton :partner-id="item.consultantId || item.consultant" :partner-name="item.consultantName"
          context-type="Контракт" :context-id="item.id" :context-label="'#' + (item.number || item.id)" />
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
import StartChatButton from '../../components/StartChatButton.vue';
import StatusChip from '../../components/StatusChip.vue';
import FilterBar from '../../components/FilterBar.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import { fmt, fmtDate, getContractStatusColor } from '../../composables/useDesign';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const statusFilter = ref(null);
const statusOptions = ref([]);
const page = ref(1);
const perPage = ref(25);

const headers = [
  { title: 'ID', key: 'id', width: 60 },
  { title: 'Номер', key: 'number', width: 120 },
  { title: 'Клиент', key: 'clientName' },
  { title: 'Консультант', key: 'consultantName' },
  { title: 'Продукт', key: 'productName' },
  { title: 'Дата открытия', key: 'openDate', width: 120 },
  { title: 'Сумма', key: 'ammount', width: 140 },
  { title: 'Статус', key: 'statusName', width: 130 },
  { title: '', key: 'chat', sortable: false, width: 50 },
];

const columnVisible = ref({});
const visibleHeaders = computed(() => headers.filter(h => columnVisible.value[h.key] !== false));

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
    const { data } = await api.get('/admin/contracts', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

async function loadStatuses() {
  try {
    const { data } = await api.get('/contracts/statuses');
    statusOptions.value = data.map(s => ({ title: s.name, value: s.id }));
  } catch {}
}

onMounted(() => {
  loadData();
  loadStatuses();
});
</script>
