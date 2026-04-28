<template>
  <div>
    <PageHeader title="История перестановок" icon="mdi-history" :count="total" />

    <v-tabs v-model="tab" color="primary" class="mb-3" density="compact" @update:model-value="onTabChange">
      <v-tab value="partner" prepend-icon="mdi-account-supervisor">Партнёр</v-tab>
      <v-tab value="contract" prepend-icon="mdi-file-document">Контракт</v-tab>
      <v-tab value="client" prepend-icon="mdi-account">Клиент</v-tab>
    </v-tabs>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" :placeholder="searchPlaceholder"
          density="compact" variant="outlined" hide-details rounded clearable
          prepend-inner-icon="mdi-magnify" style="max-width:280px"
          @update:model-value="debouncedLoad" />
        <v-text-field v-model="dateFrom" label="Дата с" type="date"
          density="compact" variant="outlined" hide-details
          style="max-width:160px" @update:model-value="loadData" />
        <v-text-field v-model="dateTo" label="Дата по" type="date"
          density="compact" variant="outlined" hide-details
          style="max-width:160px" @update:model-value="loadData" />
        <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal" class="ml-1">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
        </v-chip>
        <v-btn v-if="activeFilterCount > 0" size="small" variant="text" color="secondary"
          prepend-icon="mdi-filter-remove" @click="resetFilters">Сбросить</v-btn>
        <v-spacer />
        <span class="text-caption text-medium-emphasis">
          Стр. {{ page }} из {{ Math.max(1, Math.ceil(total / perPage)) }} · Всего: {{ total }}
        </span>
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" density="compact"
      @update:options="onOptions">
      <template #item.dateCreated="{ value }">{{ fmtDateTime(value) }}</template>
      <template #item.subjectName="{ item }">
        <span class="text-no-wrap">{{ item.subjectName || '—' }}</span>
        <v-icon v-if="isFioChange(item)" size="14" class="ms-1" color="info"
          title="Смена ФИО (не наставника)">mdi-card-account-details-outline</v-icon>
      </template>
      <template #item.author="{ item }">
        <span :class="item.author === 'Система' ? 'text-medium-emphasis' : ''">
          <v-icon v-if="item.author === 'Система'" size="14" class="mr-1">mdi-cog</v-icon>
          {{ item.author }}
        </span>
      </template>
      <template #no-data><EmptyState message="Записей не найдено" /></template>
    </v-data-table-server>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';

const tab = ref('partner');
const items = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const dateFrom = ref('');
const dateTo = ref('');
const page = ref(1);
const perPage = ref(25);

const searchPlaceholder = computed(() => ({
  partner: 'ФИО партнёра',
  contract: 'Номер контракта',
  client: 'ФИО клиента',
})[tab.value]);

const headers = computed(() => {
  const subject = {
    partner: 'Партнёр',
    contract: 'Контракт',
    client: 'Клиент',
  }[tab.value];
  const oldLabel = tab.value === 'partner' ? 'Прежний наставник' : 'Прежний консультант';
  const newLabel = tab.value === 'partner' ? 'Новый наставник' : 'Новый консультант';
  return [
    { title: 'Дата изменений', key: 'dateCreated', width: 170 },
    { title: subject, key: 'subjectName' },
    { title: oldLabel, key: 'oldName' },
    { title: newLabel, key: 'newName' },
    { title: 'Автор изменений', key: 'author', width: 200, sortable: false },
  ];
});

const activeFilterCount = computed(() => {
  let c = 0;
  if (search.value) c++;
  if (dateFrom.value) c++;
  if (dateTo.value) c++;
  return c;
});

function resetFilters() {
  search.value = ''; dateFrom.value = ''; dateTo.value = '';
  loadData();
}

function isFioChange(item) {
  // Per spec §3 п.3: смена ФИО логируется в том же таблице с
  // triggeredBy = 'fio-change' или подобным маркером.
  return item.triggeredBy === 'fio-change' || item.triggeredBy === 'rename';
}

function fmtDateTime(d) {
  if (!d) return '—';
  return new Date(d).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'medium' });
}

const { debounced: debouncedLoad } = useDebounce(loadData, 400);

function onTabChange() {
  page.value = 1;
  search.value = '';
  loadData();
}

function onOptions(opts) {
  page.value = opts.page;
  if (opts.itemsPerPage) perPage.value = opts.itemsPerPage;
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value, tab: tab.value };
    if (search.value) params.search = search.value;
    if (dateFrom.value) params.date_from = dateFrom.value;
    if (dateTo.value) params.date_to = dateTo.value;
    const { data } = await api.get('/admin/transfers', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
