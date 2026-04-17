<template>
  <div>
    <PageHeader title="Транзакции" icon="mdi-swap-horizontal" :count="total" />

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по ID..."
          rounded prepend-inner-icon="mdi-magnify" clearable hide-details style="max-width:300px" @update:model-value="debouncedLoad" />
        <v-text-field v-model="month" type="month" label="Месяц"
          hide-details style="max-width:200px" @update:model-value="loadData" />
        <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal" class="ml-1">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
        </v-chip>
        <v-btn v-if="activeFilterCount > 0" size="small" variant="text" color="secondary"
          prepend-icon="mdi-filter-remove" @click="resetFilters">Сбросить</v-btn>
      </div>
    </v-card>

    <DataTableWrapper
      :items="items"
      :items-length="total"
      :loading="loading"
      :headers="headers"
      :items-per-page="25"
      server-side
      empty-icon="mdi-swap-horizontal-variant"
      empty-message="Транзакции не найдены"
      @update:options="onOptions"
    >
      <template #item.amount="{ item }">{{ fmt(item.amount) }} {{ item.currencySymbol || '' }}</template>
      <template #item.amountRUB="{ value }">{{ fmt(value) }}</template>
      <template #item.amountUSD="{ value }">{{ fmt(value) }}</template>
      <template #item.date="{ value }">{{ fmtDate(value) }}</template>
      <template #item.chat="{ item }">
        <StartChatButton :partner-id="item.consultantId || item.consultant" :partner-name="item.consultantName"
          context-type="Транзакция" :context-id="item.id" :context-label="'#' + item.id" />
      </template>
    </DataTableWrapper>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import PageHeader from '../../components/PageHeader.vue';
import DataTableWrapper from '../../components/DataTableWrapper.vue';
import StartChatButton from '../../components/StartChatButton.vue';
import { fmt2 as fmt, fmtDate } from '../../composables/useDesign';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const month = ref(new Date().toISOString().slice(0, 7));
const page = ref(1);
const defaultMonth = new Date().toISOString().slice(0, 7);

const headers = [
  { title: 'ID', key: 'id', width: 60 },
  { title: 'Контракт', key: 'contract', width: 130 },
  { title: 'Сумма', key: 'amount', width: 140 },
  { title: 'Сумма (руб)', key: 'amountRUB', align: 'end', width: 140 },
  { title: 'Сумма (USD)', key: 'amountUSD', align: 'end', width: 140 },
  { title: 'Дата', key: 'date', width: 120 },
  { title: '', key: 'chat', sortable: false, width: 50 },
];

const activeFilterCount = computed(() => {
  let c = 0;
  if (search.value) c++;
  if (month.value && month.value !== defaultMonth) c++;
  return c;
});

function resetFilters() {
  search.value = '';
  month.value = defaultMonth;
  loadData();
}

const { debounced: debouncedLoad } = useDebounce(loadData, 400);

function onOptions(opts) {
  page.value = opts.page;
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value };
    if (search.value) params.search = search.value;
    if (month.value) params.month = month.value;
    const { data } = await api.get('/admin/transactions', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
