<template>
  <div>
    <!-- Кнопка перезакрепления + фильтры истории -->
    <div class="d-flex ga-2 flex-wrap align-center mb-3">
      <v-btn color="primary" size="small" prepend-icon="mdi-account-switch" @click="openDialog">
        {{ cfg.cta }}
      </v-btn>
      <v-text-field v-model="search" :placeholder="cfg.searchPlaceholder"
        density="compact" variant="outlined" hide-details clearable
        prepend-inner-icon="mdi-magnify" style="max-width: 280px; flex: 1 1 220px"
        @update:model-value="debouncedLoad" />
      <SmartRangeFilter label="Дата" kind="date"
        v-model:from="dateFrom" v-model:to="dateTo"
        @update:from="loadData" @update:to="loadData" />
      <v-spacer />
      <span class="text-caption text-medium-emphasis">Всего: {{ total }}</span>
    </div>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="perPage"
      :items-per-page-options="[25, 50, 100, 200]" density="compact"
      @update:options="onOptions">
      <template #item.dateCreated="{ value }">{{ fmtDateTime(value) }}</template>
      <template #item.subjectName="{ item }">
        <span class="text-no-wrap">{{ item.subjectName || '—' }}</span>
      </template>
      <template #item.author="{ item }">
        <span :class="item.author === 'Система' ? 'text-medium-emphasis' : ''">
          <v-icon v-if="item.author === 'Система'" size="14" class="mr-1">mdi-cog</v-icon>
          {{ item.author }}
        </span>
      </template>
      <template #no-data><EmptyState message="Записей не найдено" /></template>
    </v-data-table-server>

    <!-- Диалог: выбрать субъект (клиент/контракт) и нового консультанта -->
    <v-dialog v-model="showDialog" max-width="520">
      <v-card>
        <v-card-title class="text-h6">{{ cfg.cta }}</v-card-title>
        <v-card-text>
          <p class="text-body-2 text-medium-emphasis mb-4">{{ cfg.hint }}</p>
          <v-autocomplete v-model="form.subject" :items="subjectItems" :loading="subjLoading"
            item-title="name" item-value="id" :label="cfg.subjectLabel" no-filter clearable
            density="comfortable" variant="outlined" :prepend-inner-icon="cfg.subjectIcon"
            :return-object="true" hide-details class="mb-4"
            @update:search="searchSubjects" />
          <v-autocomplete v-model="form.newConsultant" :items="consultantItems" :loading="consLoading"
            item-title="name" item-value="id" label="Новый консультант" no-filter clearable
            density="comfortable" variant="outlined" prepend-inner-icon="mdi-account-supervisor"
            :return-object="true" hide-details
            @update:search="searchConsultants" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="showDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving"
            :disabled="!form.subject || !form.newConsultant"
            @click="save">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api';
import { useDebounce } from '../composables/useDebounce';
import { useTableSort } from '../composables/useTableSort';
import EmptyState from './EmptyState.vue';
import SmartRangeFilter from './SmartRangeFilter.vue';
import { useSnackbar } from '../composables/useSnackbar';

// subject: 'client' | 'contract' — переиспользуется общим бэкендом /admin/transfers.
const props = defineProps({
  subject: { type: String, required: true },
});

const { showSuccess, showError } = useSnackbar();

const CONFIG = {
  client: {
    cta: 'Перезакрепить клиента',
    hint: 'Выберите клиента и нового консультанта. Клиент будет перезакреплён, а событие записано в историю перестановок.',
    subjectLabel: 'Клиент',
    subjectIcon: 'mdi-account',
    subjectColTitle: 'Клиент',
    searchPlaceholder: 'ФИО клиента',
  },
  contract: {
    cta: 'Перезакрепить контракт',
    hint: 'Выберите контракт и нового консультанта. Контракт будет перезакреплён, а событие записано в историю перестановок.',
    subjectLabel: 'Контракт',
    subjectIcon: 'mdi-file-document',
    subjectColTitle: 'Контракт',
    searchPlaceholder: 'Номер контракта',
  },
};
const cfg = computed(() => CONFIG[props.subject]);

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const dateFrom = ref('');
const dateTo = ref('');
const page = ref(1);
const perPage = ref(25);

const headers = computed(() => [
  { title: 'Дата изменений', key: 'dateCreated', width: 170 },
  { title: cfg.value.subjectColTitle, key: 'subjectName' },
  { title: 'Прежний консультант', key: 'oldName' },
  { title: 'Новый консультант', key: 'newName' },
  { title: 'Автор изменений', key: 'author', width: 200, sortable: false },
]);

function fmtDateTime(d) {
  if (!d) return '—';
  return new Date(d).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'medium' });
}

const { applyOptions, applyParams } = useTableSort('dateCreated', 'desc');
const { debounced: debouncedLoad } = useDebounce(loadData, 400);

function onOptions(opts) {
  page.value = opts.page;
  if (opts.itemsPerPage) perPage.value = opts.itemsPerPage;
  applyOptions(opts);
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value, tab: props.subject };
    applyParams(params);
    if (search.value) params.search = search.value;
    if (dateFrom.value) params.date_from = dateFrom.value;
    if (dateTo.value) params.date_to = dateTo.value;
    const { data } = await api.get('/admin/transfers', { params });
    items.value = data.data;
    total.value = data.total;
  } catch { /* ignore */ }
  loading.value = false;
}

onMounted(loadData);

// --- Перезакрепление (ручная смена консультанта + запись в историю) ---
const showDialog = ref(false);
const saving = ref(false);
const form = ref({ subject: null, newConsultant: null });
const subjectItems = ref([]);
const consultantItems = ref([]);
const subjLoading = ref(false);
const consLoading = ref(false);

function openDialog() {
  form.value = { subject: null, newConsultant: null };
  subjectItems.value = [];
  consultantItems.value = [];
  showDialog.value = true;
}

async function doSubjectSearch(s) {
  subjLoading.value = true;
  try {
    const { data } = await api.get('/admin/transfers/subjects', {
      params: { type: props.subject, search: s || undefined },
    });
    subjectItems.value = data.data || [];
  } catch { /* ignore */ } finally { subjLoading.value = false; }
}
async function doConsultantSearch(s) {
  consLoading.value = true;
  try {
    const { data } = await api.get('/admin/transfers/consultants', { params: { search: s || undefined } });
    consultantItems.value = data.data || [];
  } catch { /* ignore */ } finally { consLoading.value = false; }
}
const { debounced: debSubj } = useDebounce(doSubjectSearch, 300);
const { debounced: debCons } = useDebounce(doConsultantSearch, 300);
// Оборачиваем в () => чтобы debounce не форвардил event-строку как позиционный аргумент.
function searchSubjects(s) { debSubj(s || ''); }
function searchConsultants(s) { debCons(s || ''); }

async function save() {
  saving.value = true;
  try {
    const { data } = await api.post('/admin/transfers', {
      subject: props.subject,
      subject_id: form.value.subject?.id,
      new_consultant: form.value.newConsultant?.id,
    });
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
