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
          prepend-inner-icon="mdi-magnify" style="max-width:220px"
          @update:model-value="debouncedLoad" />
        <v-text-field v-model="commentFilter" placeholder="Поиск по комментарию"
          density="compact" variant="outlined" hide-details rounded clearable
          style="max-width:220px" @update:model-value="debouncedLoad" />
        <v-select v-model="typeFilter" :items="typeOptions" label="Тип"
          density="compact" variant="outlined" clearable hide-details
          style="max-width:140px" @update:model-value="loadData" />
        <!-- Год + Месяц (как в Реестре выплат) -->
        <v-text-field v-model.number="yearFilter" label="Год" type="number"
          density="compact" variant="outlined" hide-details clearable
          style="max-width:110px" @update:model-value="loadData" />
        <v-select v-model="monthFilter" :items="monthOptions" label="Месяц"
          density="compact" variant="outlined" clearable hide-details
          style="max-width:160px" @update:model-value="loadData" />
        <v-btn variant="text" size="small"
          :prepend-icon="showAdvancedDates ? 'mdi-chevron-up' : 'mdi-chevron-down'"
          @click="showAdvancedDates = !showAdvancedDates">
          Дата с/по
        </v-btn>
        <template v-if="showAdvancedDates">
          <v-text-field v-model="dateFrom" label="Дата с" type="date"
            density="compact" variant="outlined" hide-details
            style="max-width:160px" @update:model-value="loadData" />
          <v-text-field v-model="dateTo" label="Дата по" type="date"
            density="compact" variant="outlined" hide-details
            style="max-width:160px" @update:model-value="loadData" />
        </template>
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
      <template #item.amount="{ item }">
        <!-- Per spec ✅Прочие начисления §2: единая колонка «Сумма» —
             знак ± + единица (руб/баллы), цвет по знаку. -->
        <span v-if="isPointsRow(item)" :class="item.points < 0 ? 'text-error' : 'text-success'">
          {{ fmt(item.points) }} баллов
        </span>
        <span v-else :class="item.amount < 0 ? 'text-error' : 'text-success'">
          {{ fmt(item.amount) }} ₽
        </span>
      </template>
      <template #item.accrualDate="{ value }">{{ fmtDate(value) }}</template>
      <template #item.actions="{ item }">
        <!-- Edit — только для manual (legacy commission имеет другую структуру).
             Delete — для всех (legacy soft-deletes commission row). -->
        <v-btn v-if="item.editable" icon="mdi-pencil" size="x-small" variant="text"
          title="Редактировать" @click="openEdit(item)" />
        <v-btn icon="mdi-delete" size="x-small" variant="text" color="error"
          :title="item.editable ? 'Удалить' : 'Удалить (legacy — soft-delete)'"
          @click="confirmDelete(item)" />
        <v-chip v-if="!item.editable" size="x-small" color="grey" variant="tonal"
          class="ms-1" title="Запись из legacy-истории">
          legacy
        </v-chip>
      </template>
    </DataTableWrapper>

    <!-- Create dialog (per spec ✅Прочие начисления §3) -->
    <v-dialog v-model="createDialog" max-width="500" persistent>
      <v-card>
        <v-card-title>{{ editingId ? 'Редактировать начисление' : 'Новое начисление' }}</v-card-title>
        <v-card-text>
          <div class="text-caption text-medium-emphasis mb-1">Тип операции *</div>
          <v-radio-group v-model="form.type" inline density="compact" hide-details class="mb-3">
            <v-radio label="Рубли" value="rub" />
            <v-radio label="Баллы" value="points" />
          </v-radio-group>
          <v-autocomplete v-model="form.consultant" :items="consultantOptions" item-title="personName" item-value="id"
            label="Партнёр *" :loading="searchingConsultants"
            @update:search="searchConsultants" no-data-text="Начните вводить ФИО" class="mb-3" />
          <v-text-field v-model.number="form.amount"
            :label="form.type === 'points' ? 'Сумма (баллы) — для списания используйте −' : 'Сумма (₽) — для списания используйте −'"
            type="number" :hint="form.type === 'points' ? 'Баллы влияют на ЛП и НГП. В деньги не конвертируются.' : 'Влияет только на финансовый баланс к выплате. ЛП/НГП не трогает.'"
            persistent-hint class="mb-3" />
          <v-text-field v-model="form.accrual_date" label="Дата начисления *" type="date" class="mb-3"
            hint="Влияет на расчётный период (можно задним числом)" persistent-hint />
          <v-textarea v-model="form.comment" label="Комментарий *" rows="2"
            hint="Обязательно для аудита (например, «За билет на съезд»)" persistent-hint />
          <v-alert v-if="formError" type="error" density="compact" class="mt-2">{{ formError }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="createDialog = false">Отмена</v-btn>
          <v-btn color="primary" @click="saveCharge" :loading="saving"
            :disabled="!form.consultant || !form.type || !form.amount || !form.comment">Сохранить</v-btn>
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
const yearFilter = ref(null);
const monthFilter = ref(null);
const showAdvancedDates = ref(false);
const editingId = ref(null);
const page = ref(1);
const perPage = ref(25);

const monthOptions = Array.from({ length: 12 }, (_, i) => ({
  title: new Date(2000, i, 1).toLocaleDateString('ru-RU', { month: 'long' }),
  value: i + 1,
}));

const createDialog = ref(false);
const deleteDialog = ref(false);
const deleteTarget = ref(null);
const formError = ref('');
const form = ref({});
const consultantOptions = ref([]);
const searchingConsultants = ref(false);

const typeOptions = [
  { title: 'Рубли', value: 'rub' },
  { title: 'Баллы', value: 'points' },
];

const headers = [
  { title: 'Дата', key: 'accrualDate', width: 120 },
  { title: 'Партнёр', key: 'consultantName' },
  { title: 'Сумма', key: 'amount', align: 'end', width: 160 },
  { title: 'Комментарий', key: 'comment' },
  { title: '', key: 'actions', sortable: false, width: 90 },
];

const columnVisible = ref({});
const visibleHeaders = computed(() => headers.filter(h => columnVisible.value[h.key] !== false));

function typeLabel(t) {
  return { rub: 'Рубли', points: 'Баллы',
    bonus: 'Рубли', penalty: 'Рубли', compensation: 'Рубли' }[t] || t;
}
function typeColor(t) { return t === 'points' ? 'info' : 'success'; }
function isPointsRow(item) {
  // Новые записи: type='points'. Старые (bonus/penalty/compensation) —
  // всегда рубли. Дополнительная защита: если points != 0, считаем баллами.
  if (item.type === 'points') return true;
  return Number(item.points) !== 0 && Number(item.amount) === 0;
}

const activeFilterCount = computed(() => {
  let c = 0;
  if (search.value) c++;
  if (commentFilter.value) c++;
  if (typeFilter.value) c++;
  if (yearFilter.value) c++;
  if (monthFilter.value) c++;
  if (dateFrom.value) c++;
  if (dateTo.value) c++;
  return c;
});

function resetFilters() {
  search.value = ''; commentFilter.value = ''; typeFilter.value = null;
  yearFilter.value = null; monthFilter.value = null;
  dateFrom.value = ''; dateTo.value = '';
  loadData();
}

function openEdit(item) {
  editingId.value = item.id;
  // Маппинг старых типов в новую семантику (rub/points).
  const isPoints = isPointsRow(item);
  form.value = {
    consultant: item.consultant,
    type: isPoints ? 'points' : 'rub',
    amount: isPoints ? Number(item.points || 0) : Number(item.amount || 0),
    accrual_date: item.accrualDate,
    comment: item.comment,
  };
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
    if (yearFilter.value) params.year = yearFilter.value;
    if (monthFilter.value) params.month = monthFilter.value;
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
  form.value = {
    consultant: null,
    type: 'rub',
    amount: 0,
    comment: '',
    accrual_date: new Date().toISOString().slice(0, 10),
  };
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
    // source=legacy для строк из commission.type='nonTransactional' —
    // backend делает soft-delete (deletedAt = now()).
    const params = { source: deleteTarget.value.source || 'manual' };
    await api.delete(`/admin/charges/${deleteTarget.value.id}`, { params });
    deleteDialog.value = false;
    loadData();
  } catch {}
  saving.value = false;
}

onMounted(loadData);
</script>
