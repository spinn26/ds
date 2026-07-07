<template>
  <div>
    <PageHeader title="История перестановок" icon="mdi-history" :count="total">
      <template #actions>
        <v-btn color="primary" size="small" prepend-icon="mdi-account-switch"
          class="me-2" @click="openDialog">{{ reassignCta }}</v-btn>
        <ColumnVisibilityMenu
          :headers="headers"
          v-model:visible="columnVisible"
          storage-key="transfers-cols" />
      </template>
    </PageHeader>

    <v-dialog v-model="showDialog" max-width="520">
      <v-card>
        <v-card-title class="text-h6">{{ reassignCta }}</v-card-title>
        <v-card-text>
          <p class="text-body-2 text-medium-emphasis mb-4">{{ reassignHint }}</p>
          <v-autocomplete v-model="form.subject" :items="subjectItems" :loading="subjLoading"
            item-title="name" item-value="id" :label="subjectLabel" no-filter clearable
            density="comfortable" variant="outlined" :prepend-inner-icon="subjectIcon"
            :return-object="true" hide-details class="mb-4"
            @update:search="searchSubject" />
          <v-autocomplete v-model="form.newOwner" :items="ownerItems" :loading="ownerLoading"
            item-title="name" item-value="id" :label="newOwnerLabel" no-filter clearable
            density="comfortable" variant="outlined" prepend-inner-icon="mdi-account-supervisor"
            :return-object="true" hide-details
            @update:search="searchOwner" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="showDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving"
            :disabled="!form.subject || !form.newOwner || form.subject.id === form.newOwner.id"
            @click="saveTransfer">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-tabs v-model="tab" color="primary" class="mb-3" density="compact" @update:model-value="onTabChange">
      <v-tab value="partner" prepend-icon="mdi-account-supervisor">Партнёр</v-tab>
      <v-tab value="contract" prepend-icon="mdi-file-document">Контракт</v-tab>
      <v-tab value="client" prepend-icon="mdi-account">Клиент</v-tab>
    </v-tabs>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" :placeholder="searchPlaceholder"
          density="compact" variant="outlined" hide-details rounded clearable
          prepend-inner-icon="mdi-magnify"
          style="max-width: 280px; flex: 1 1 220px"
          @update:model-value="debouncedLoad" />
        <SmartRangeFilter label="Дата" kind="date"
          v-model:from="dateFrom" v-model:to="dateTo"
          @update:from="loadData" @update:to="loadData" />
        <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal">
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
      :headers="visibleHeaders" :items-per-page="perPage"
      :items-per-page-options="[25, 50, 100, 200]" density="compact"
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
import { useTableSort } from '../../composables/useTableSort';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import SmartRangeFilter from '../../components/SmartRangeFilter.vue';
import { useSnackbar } from '../../composables/useSnackbar';

const { showSuccess, showError } = useSnackbar();

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

const columnVisible = ref({});
const visibleHeaders = computed(() =>
  headers.value.filter(h => columnVisible.value[h.key] !== false)
);

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

const { applyOptions, applyParams } = useTableSort('dateCreated', 'desc');

function onOptions(opts) {
  page.value = opts.page;
  if (opts.itemsPerPage) perPage.value = opts.itemsPerPage;
  applyOptions(opts);
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value, tab: tab.value };
    applyParams(params);
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

// --- Перестановка (ручная смена владельца + запись в историю) ---
// Диалог контекстный по активной вкладке: партнёр → смена наставника ФК,
// клиент/контракт → перезакрепление на другого консультанта.
const showDialog = ref(false);
const saving = ref(false);
const form = ref({ subject: null, newOwner: null });
const subjectItems = ref([]);
const ownerItems = ref([]);
const subjLoading = ref(false);
const ownerLoading = ref(false);

const reassignCta = computed(() => ({
  partner: 'Внести перестановку',
  contract: 'Перезакрепить контракт',
  client: 'Перезакрепить клиента',
})[tab.value]);
const reassignHint = computed(() => ({
  partner: 'Выберите ФК и его нового наставника. Наставник партнёра будет изменён, а событие записано в историю перестановок.',
  contract: 'Выберите контракт и нового консультанта. Контракт будет перезакреплён, а событие записано в историю перестановок.',
  client: 'Выберите клиента и нового консультанта. Клиент будет перезакреплён, а событие записано в историю перестановок.',
})[tab.value]);
const subjectLabel = computed(() => ({
  partner: 'ФК (партнёр)', contract: 'Контракт', client: 'Клиент',
})[tab.value]);
const subjectIcon = computed(() => (tab.value === 'contract' ? 'mdi-file-document' : 'mdi-account'));
const newOwnerLabel = computed(() => (tab.value === 'partner' ? 'Новый наставник' : 'Новый консультант'));

function openDialog() {
  form.value = { subject: null, newOwner: null };
  subjectItems.value = [];
  ownerItems.value = [];
  showDialog.value = true;
}

// Субъект: партнёр — из /consultants, клиент/контракт — из /subjects.
async function doSubjectSearch(s) {
  subjLoading.value = true;
  try {
    const { data } = tab.value === 'partner'
      ? await api.get('/admin/transfers/consultants', { params: { search: s || undefined } })
      : await api.get('/admin/transfers/subjects', { params: { type: tab.value, search: s || undefined } });
    subjectItems.value = data.data || [];
  } catch { /* ignore */ } finally { subjLoading.value = false; }
}
async function doOwnerSearch(s) {
  ownerLoading.value = true;
  try {
    const { data } = await api.get('/admin/transfers/consultants', { params: { search: s || undefined } });
    ownerItems.value = data.data || [];
  } catch { /* ignore */ } finally { ownerLoading.value = false; }
}
const { debounced: debSubj } = useDebounce(doSubjectSearch, 300);
const { debounced: debOwner } = useDebounce(doOwnerSearch, 300);
function searchSubject(s) { debSubj(s || ''); }
function searchOwner(s) { debOwner(s || ''); }

async function saveTransfer() {
  saving.value = true;
  try {
    const payload = tab.value === 'partner'
      ? { consultant: form.value.subject?.id, newInviter: form.value.newOwner?.id }
      : { subject: tab.value, subject_id: form.value.subject?.id, new_consultant: form.value.newOwner?.id };
    const { data } = await api.post('/admin/transfers', payload);
    showSuccess(data.message || 'Перестановка внесена');
    showDialog.value = false;
    page.value = 1;
    loadData();
  } catch (e) {
    showError(e?.response?.data?.message || 'Не удалось внести перестановку');
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.filter-range {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 220px;
}
.filter-range :deep(.v-field) {
  min-width: 100px;
}
</style>
