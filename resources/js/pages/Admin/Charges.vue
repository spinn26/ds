<template>
  <div>
    <PageHeader title="Прочие начисления" icon="mdi-cash-plus" :count="total">
      <template #actions>
        <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreate">Добавить начисление</v-btn>
      </template>
    </PageHeader>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="ФИО консультанта"
          density="compact" variant="outlined" hide-details rounded clearable
          prepend-inner-icon="mdi-magnify" style="max-width:240px"
          @update:model-value="debouncedLoad" />
        <v-text-field v-model="commentFilter" placeholder="Поиск по комментарию"
          density="compact" variant="outlined" hide-details rounded clearable
          style="max-width:240px" @update:model-value="debouncedLoad" />
        <v-select v-model="typeFilter" :items="typeOptions" label="Тип"
          density="compact" variant="outlined" clearable hide-details
          style="max-width:160px" @update:model-value="loadData" />
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
        <v-btn variant="text" size="small" prepend-icon="mdi-download" @click="exportCsv">
          Экспорт CSV
        </v-btn>
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="charges-cols" />
      </div>
    </v-card>

    <DataTableWrapper
      :items="items"
      :items-length="total"
      :loading="loading"
      :headers="visibleHeaders"
      :items-per-page="25"
      server-side
      empty-icon="mdi-cash-remove"
      empty-message="Начисления не найдены"
      @update:options="onOptions"
    >
      <template #item.type="{ value }">
        <v-chip size="x-small" :color="typeColor(value)">{{ typeLabel(value) }}</v-chip>
      </template>
      <template #item.amount="{ value }">
        <span :class="value < 0 ? 'text-error' : 'text-success'">{{ fmt(value) }} ₽</span>
      </template>
      <template #item.points="{ value }">{{ value ? fmt(value) : '—' }}</template>
      <template #item.accrualDate="{ value }">{{ fmtDate(value) }}</template>
      <template #item.actions="{ item }">
        <v-btn icon="mdi-pencil" size="x-small" variant="text" title="Редактировать" @click="openEdit(item)" />
        <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" title="Удалить" @click="confirmDelete(item)" />
      </template>
    </DataTableWrapper>

    <!-- Create dialog -->
    <v-dialog v-model="createDialog" max-width="500" persistent>
      <v-card>
        <v-card-title>{{ editingId ? 'Редактировать начисление' : 'Новое начисление' }}</v-card-title>
        <v-card-text>
          <v-autocomplete v-model="form.consultant" :items="consultantOptions" item-title="personName" item-value="id"
            label="Партнёр *" :loading="searchingConsultants"
            @update:search="searchConsultants" no-data-text="Начните вводить ФИО" class="mb-3" />
          <v-select v-model="form.type" :items="typeOptions" label="Тип *" class="mb-3" />
          <v-text-field v-model.number="form.amount" label="Сумма (₽) *" type="number" class="mb-3" />
          <v-text-field v-model.number="form.points" label="Баллы" type="number" class="mb-3" />
          <v-text-field v-model="form.accrual_date" label="Дата начисления" type="date" class="mb-3" />
          <v-textarea v-model="form.comment" label="Комментарий" rows="2" />
          <v-alert v-if="formError" type="error" density="compact" class="mt-2">{{ formError }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="createDialog = false">Отмена</v-btn>
          <v-btn color="primary" @click="saveCharge" :loading="saving"
            :disabled="!form.consultant || !form.type || !form.amount">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete confirm -->
    <v-dialog v-model="deleteDialog" max-width="400">
      <v-card>
        <v-card-title>Удалить начисление?</v-card-title>
        <v-card-text>
          {{ deleteTarget?.consultantName }} — {{ fmt(deleteTarget?.amount) }} ₽
          <div v-if="deleteTarget?.comment" class="text-caption text-medium-emphasis mt-1">{{ deleteTarget.comment }}</div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="deleteDialog = false">Отмена</v-btn>
          <v-btn color="error" @click="deleteCharge" :loading="saving">Удалить</v-btn>
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
import DataTableWrapper from '../../components/DataTableWrapper.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import { fmt2 as fmt, fmtDate } from '../../composables/useDesign';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const saving = ref(false);
const search = ref('');
const commentFilter = ref('');
const typeFilter = ref(null);
const dateFrom = ref('');
const dateTo = ref('');
const editingId = ref(null);
const page = ref(1);
const perPage = ref(25);

const createDialog = ref(false);
const deleteDialog = ref(false);
const deleteTarget = ref(null);
const formError = ref('');
const form = ref({});
const consultantOptions = ref([]);
const searchingConsultants = ref(false);

const typeOptions = [
  { title: 'Бонус', value: 'bonus' },
  { title: 'Штраф', value: 'penalty' },
  { title: 'Компенсация', value: 'compensation' },
];

const headers = [
  { title: 'ID', key: 'id', width: 60 },
  { title: 'Партнёр', key: 'consultantName' },
  { title: 'Тип', key: 'type', width: 130 },
  { title: 'Сумма', key: 'amount', align: 'end', width: 130 },
  { title: 'Баллы', key: 'points', align: 'end', width: 100 },
  { title: 'Дата', key: 'accrualDate', width: 120 },
  { title: 'Комментарий', key: 'comment' },
  { title: '', key: 'actions', sortable: false, width: 50 },
];

const columnVisible = ref({});
const visibleHeaders = computed(() => headers.filter(h => columnVisible.value[h.key] !== false));

function typeLabel(t) { return { bonus: 'Бонус', penalty: 'Штраф', compensation: 'Компенсация' }[t] || t; }
function typeColor(t) { return { bonus: 'success', penalty: 'error', compensation: 'info' }[t] || 'grey'; }

const activeFilterCount = computed(() => {
  let c = 0;
  if (search.value) c++;
  if (commentFilter.value) c++;
  if (typeFilter.value) c++;
  if (dateFrom.value) c++;
  if (dateTo.value) c++;
  return c;
});

function resetFilters() {
  search.value = ''; commentFilter.value = ''; typeFilter.value = null;
  dateFrom.value = ''; dateTo.value = '';
  loadData();
}

function openEdit(item) {
  editingId.value = item.id;
  form.value = {
    consultant: item.consultant,
    type: item.type,
    amount: item.amount,
    points: item.points,
    accrual_date: item.accrualDate,
    comment: item.comment,
  };
  // pre-populate consultant options so v-autocomplete shows current selection
  consultantOptions.value = [{ id: item.consultant, personName: item.consultantName }];
  formError.value = '';
  createDialog.value = true;
}

function exportCsv() {
  const rows = [
    ['Дата', 'Партнёр', 'Тип', 'Сумма (₽)', 'Баллы', 'Комментарий'],
    ...items.value.map(i => [
      i.accrualDate || '',
      i.consultantName || '',
      typeLabel(i.type),
      i.amount,
      i.points || 0,
      (i.comment || '').replace(/"/g, '""'),
    ]),
  ];
  const csv = rows.map(r => r.map(c => `"${c}"`).join(',')).join('\n');
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = `charges-${new Date().toISOString().slice(0, 10)}.csv`;
  link.click();
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
    if (commentFilter.value) params.comment = commentFilter.value;
    if (typeFilter.value) params.type = typeFilter.value;
    if (dateFrom.value) params.date_from = dateFrom.value;
    if (dateTo.value) params.date_to = dateTo.value;
    const { data } = await api.get('/admin/charges', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

let consultantTimer;
async function searchConsultants(q) {
  clearTimeout(consultantTimer);
  if (!q || q.length < 2) return;
  consultantTimer = setTimeout(async () => {
    searchingConsultants.value = true;
    try {
      const { data } = await api.get('/admin/partners', { params: { search: q, page: 1 } });
      consultantOptions.value = data.data || [];
    } catch {}
    searchingConsultants.value = false;
  }, 300);
}

function openCreate() {
  editingId.value = null;
  form.value = { consultant: null, type: 'bonus', amount: 0, points: 0, comment: '', accrual_date: new Date().toISOString().slice(0, 10) };
  formError.value = '';
  createDialog.value = true;
}

async function saveCharge() {
  saving.value = true;
  formError.value = '';
  try {
    if (editingId.value) {
      await api.put('/admin/charges/' + editingId.value, form.value);
    } else {
      await api.post('/admin/charges', form.value);
    }
    createDialog.value = false;
    loadData();
  } catch (e) {
    formError.value = e.response?.data?.message || 'Ошибка сохранения';
  }
  saving.value = false;
}

function confirmDelete(item) { deleteTarget.value = item; deleteDialog.value = true; }

async function deleteCharge() {
  saving.value = true;
  try {
    await api.delete(`/admin/charges/${deleteTarget.value.id}`);
    deleteDialog.value = false;
    loadData();
  } catch {}
  saving.value = false;
}

onMounted(loadData);
</script>
