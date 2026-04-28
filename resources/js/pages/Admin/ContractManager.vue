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
      <template #item.actions="{ item }">
        <v-btn icon="mdi-history" size="x-small" variant="text" title="История изменений"
          @click="openHistory(item)" />
      </template>
      <template #no-data><EmptyState /></template>
    </v-data-table-server>

    <!-- Модалка «История контракта» (per spec ✅Менеджер контрактов §4) -->
    <v-dialog v-model="historyOpen" max-width="900">
      <v-card v-if="historyContext">
        <v-card-title>
          История изменений контракта {{ historyContext.number || ('#' + historyContext.id) }}
        </v-card-title>
        <v-card-text>
          <v-alert v-if="!historyRows.length && !historyLoading" type="info" variant="tonal" density="compact">
            Изменений не найдено (или контракт не редактировался после установки логирования).
          </v-alert>
          <v-table v-else density="compact">
            <thead>
              <tr>
                <th style="width:170px">Дата и время</th>
                <th>Что изменено</th>
                <th style="width:200px">Автор</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in historyRows" :key="row.id">
                <td class="text-no-wrap">{{ fmtDateTime(row.createdAt) }}</td>
                <td>
                  <div v-if="!row.changes.length" class="text-medium-emphasis">
                    {{ row.description || row.event }}
                  </div>
                  <div v-for="ch in row.changes" :key="ch.field" class="mb-1">
                    <span class="font-weight-medium">{{ ch.fieldLabel }}:</span>
                    <span class="text-medium-emphasis">{{ formatVal(ch.old) }}</span>
                    <v-icon size="14" class="mx-1">mdi-arrow-right</v-icon>
                    <span class="text-success">{{ formatVal(ch.new) }}</span>
                  </div>
                </td>
                <td class="text-no-wrap">{{ row.author }}</td>
              </tr>
            </tbody>
          </v-table>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="historyOpen = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
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
  { title: '', key: 'actions', sortable: false, width: 50 },
];

const historyOpen = ref(false);
const historyContext = ref(null);
const historyRows = ref([]);
const historyLoading = ref(false);

async function openHistory(item) {
  historyContext.value = item;
  historyRows.value = [];
  historyLoading.value = true;
  historyOpen.value = true;
  try {
    const { data } = await api.get(`/admin/contracts/${item.id}/history`);
    historyRows.value = data.data || [];
  } catch {}
  historyLoading.value = false;
}

function fmtDateTime(d) {
  if (!d) return '—';
  return new Date(d).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}

function formatVal(v) {
  if (v === null || v === undefined) return '—';
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
}

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
