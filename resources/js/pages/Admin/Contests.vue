<template>
  <div>
    <div class="d-flex justify-space-between align-center mb-4 flex-wrap ga-2">
      <div class="d-flex align-center ga-2">
        <v-icon size="32" color="primary">mdi-trophy</v-icon>
        <h5 class="text-h5 font-weight-bold">Конкурсы и события</h5>
        <v-chip size="small" color="primary">{{ total }}</v-chip>
      </div>
      <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreate">Добавить</v-btn>
    </div>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск..." density="compact" variant="outlined"
          prepend-inner-icon="mdi-magnify" hide-details style="max-width:300px" @update:model-value="debouncedLoad" />
        <v-select v-model="filters.status" :items="statusOptions" item-title="name" item-value="id"
          label="Статус" clearable hide-details density="compact" variant="outlined"
          style="max-width:200px" @update:model-value="loadData" />
        <v-select v-model="filters.type" :items="typeOptions" item-title="name" item-value="id"
          label="Тип" clearable hide-details density="compact" variant="outlined"
          style="max-width:200px" @update:model-value="loadData" />
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" @update:options="onOptions" density="compact" hover>
      <template #item.typeName="{ item }">
        <v-chip size="x-small" variant="outlined">{{ item.typeName || '—' }}</v-chip>
      </template>
      <template #item.statusName="{ item }">
        <v-chip size="x-small" :color="statusColor(item.status)">
          {{ item.statusName || '—' }}
        </v-chip>
      </template>
      <template #item.start="{ value }">{{ fmtDate(value) }}</template>
      <template #item.end="{ value }">{{ fmtDate(value) }}</template>
      <template #item.actions="{ item }">
        <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openEdit(item)" />
        <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="confirmDelete(item)" />
      </template>
    </v-data-table-server>

    <!-- Create/Edit dialog -->
    <v-dialog v-model="dialog" max-width="900" persistent scrollable>
      <v-card>
        <v-card-title>{{ form.id ? 'Редактировать' : 'Новый' }} конкурс / событие</v-card-title>
        <v-card-text style="max-height:70vh">
          <v-row dense>
            <v-col cols="12">
              <v-text-field v-model="form.name" label="Название *" variant="outlined" density="compact"
                :error-messages="errors.name" />
            </v-col>
            <v-col cols="12">
              <v-textarea v-model="form.description" label="Описание" variant="outlined" density="compact" rows="3"
                :error-messages="errors.description" />
            </v-col>

            <v-col cols="12" md="4">
              <v-select v-model="form.type" :items="typeOptions" item-title="name" item-value="id"
                label="Тип" variant="outlined" density="compact" clearable
                :error-messages="errors.type" />
            </v-col>
            <v-col cols="12" md="4">
              <v-select v-model="form.status" :items="statusOptions" item-title="name" item-value="id"
                label="Статус" variant="outlined" density="compact" clearable
                :error-messages="errors.status" />
            </v-col>
            <v-col cols="12" md="4">
              <v-select v-model="form.typeEvent" :items="typeEventOptions" item-title="title" item-value="value"
                label="Тип события" variant="outlined" density="compact" clearable
                :error-messages="errors.typeEvent" />
            </v-col>

            <v-col cols="12" md="4">
              <v-text-field v-model="form.start" type="datetime-local" label="Начало"
                variant="outlined" density="compact" :error-messages="errors.start" />
            </v-col>
            <v-col cols="12" md="4">
              <v-text-field v-model="form.end" type="datetime-local" label="Окончание"
                variant="outlined" density="compact" :error-messages="errors.end" />
            </v-col>
            <v-col cols="12" md="4">
              <v-text-field v-model="form.resultsPublicationDate" type="datetime-local"
                label="Дата публикации результатов" variant="outlined" density="compact"
                :error-messages="errors.resultsPublicationDate" />
            </v-col>

            <v-col cols="12" md="4">
              <v-text-field v-model.number="form.numberOfWinners" type="number" label="Количество победителей"
                variant="outlined" density="compact" min="0"
                :error-messages="errors.numberOfWinners" />
            </v-col>
            <v-col cols="12" md="4">
              <v-select v-model="form.criterion" :items="criterionOptions" item-title="name" item-value="id"
                label="Критерий" variant="outlined" density="compact" clearable
                :error-messages="errors.criterion" />
            </v-col>
            <v-col cols="12" md="4">
              <v-text-field v-model.number="form.numericValue" type="number" step="0.01"
                label="Числовое значение (план)" variant="outlined" density="compact"
                :error-messages="errors.numericValue" />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field v-model="form.nameNumericValue" label="Название показателя"
                variant="outlined" density="compact" :error-messages="errors.nameNumericValue" />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field v-model="form.presentation" label="Ссылка на презентацию"
                variant="outlined" density="compact" :error-messages="errors.presentation" />
            </v-col>

            <v-col cols="12" md="6">
              <v-autocomplete v-model="form.product" :items="productOptions" item-title="name" item-value="id"
                label="Продукт" variant="outlined" density="compact" clearable
                :error-messages="errors.product" />
            </v-col>
            <v-col cols="12" md="6">
              <v-autocomplete v-model="form.program" :items="programsForProduct" item-title="name" item-value="id"
                label="Программа" variant="outlined" density="compact" clearable
                :error-messages="errors.program" />
            </v-col>

            <v-col cols="12" md="6">
              <v-text-field v-model="form.visibility" label="Видимость (строка)"
                hint='Пример: "finСonsultant, resident"' persistent-hint
                variant="outlined" density="compact" :error-messages="errors.visibility" />
            </v-col>
            <v-col cols="12" md="3">
              <v-checkbox v-model="form.visibilityConsultants" label="Виден консультантам" density="compact" hide-details />
            </v-col>
            <v-col cols="12" md="3">
              <v-checkbox v-model="form.visibilityResidents" label="Виден резидентам" density="compact" hide-details />
            </v-col>

            <v-col cols="12" md="3">
              <v-checkbox v-model="form.conditionalTurnOn" label="Условное включение" density="compact" hide-details />
            </v-col>
            <v-col cols="12" md="4">
              <v-text-field v-model="form.archiveDate" type="date" label="Дата архивации"
                variant="outlined" density="compact" :error-messages="errors.archiveDate" />
            </v-col>

            <v-col cols="12" md="6">
              <v-text-field v-model="form.urlData" label="URL данных" variant="outlined" density="compact"
                :error-messages="errors.urlData" />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field v-model="form.headers" label="Заголовки HTTP" variant="outlined" density="compact"
                :error-messages="errors.headers" />
            </v-col>
            <v-col cols="12">
              <v-textarea v-model="form.techComment" label="Технический комментарий"
                variant="outlined" density="compact" rows="2"
                :error-messages="errors.techComment" />
            </v-col>
          </v-row>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="dialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" :disabled="!form.name" @click="save">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete dialog -->
    <v-dialog v-model="deleteDialog" max-width="400">
      <v-card>
        <v-card-title>Удалить?</v-card-title>
        <v-card-text>{{ deleteTarget?.name }}</v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="deleteDialog = false">Отмена</v-btn>
          <v-btn color="error" @click="doDelete" :loading="saving">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import { fmtDate } from '../../composables/useDesign';
import { useSnackbar } from '../../composables/useSnackbar';

const { showError, showSuccess } = useSnackbar();

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const saving = ref(false);
const search = ref('');
const page = ref(1);
const perPage = ref(25);
const filters = ref({ status: null, type: null });

const dialog = ref(false);
const deleteDialog = ref(false);
const deleteTarget = ref(null);
const form = ref({});
const errors = ref({});

const typeOptions = ref([]);
const statusOptions = ref([]);
const criterionOptions = ref([]);
const productOptions = ref([]);
const programOptions = ref([]);

const typeEventOptions = [
  { title: 'Расчётный (calculative)', value: 'calculative' },
  { title: 'Нерасчётный (uncalculative)', value: 'uncalculative' },
];

const headers = [
  { title: 'Название', key: 'name' },
  { title: 'Тип', key: 'typeName', width: 130 },
  { title: 'Статус', key: 'statusName', width: 130 },
  { title: 'Начало', key: 'start', width: 110 },
  { title: 'Окончание', key: 'end', width: 110 },
  { title: 'Победителей', key: 'numberOfWinners', width: 120, align: 'end' },
  { title: '', key: 'actions', sortable: false, width: 90 },
];

const programsForProduct = computed(() => {
  if (!form.value.product) return programOptions.value;
  return programOptions.value.filter(p => String(p.product) === String(form.value.product));
});

function statusColor(s) {
  if (s === 1) return 'grey';         // Черновик
  if (s === 2) return 'success';      // Опубликован
  if (s === 3) return 'warning';      // Завершён
  return 'grey';
}

function onOptions(opts) {
  page.value = opts.page;
  if (opts.itemsPerPage) perPage.value = opts.itemsPerPage;
  loadData();
}

const { debounced: debouncedLoad } = useDebounce(loadData, 400);

async function loadReferences() {
  try {
    const { data } = await api.get('/admin/contests/references');
    typeOptions.value = data.types || [];
    statusOptions.value = data.statuses || [];
    criterionOptions.value = data.criteria || [];
    productOptions.value = data.products || [];
    programOptions.value = data.programs || [];
  } catch {}
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value };
    if (search.value) params.search = search.value;
    if (filters.value.status) params.status = filters.value.status;
    if (filters.value.type) params.type = filters.value.type;
    const { data } = await api.get('/admin/contests', { params });
    items.value = data.contests || [];
    total.value = data.total || 0;
  } catch {}
  loading.value = false;
}

function openCreate() {
  form.value = {
    name: '', description: '',
    type: null, status: 1, typeEvent: null,
    start: null, end: null, resultsPublicationDate: null,
    numberOfWinners: null, criterion: null,
    product: null, program: null,
    numericValue: null, nameNumericValue: '',
    presentation: '', visibility: '',
    visibilityConsultants: false, visibilityResidents: false,
    conditionalTurnOn: false,
    urlData: '', headers: '', techComment: '',
    archiveDate: null,
  };
  errors.value = {};
  dialog.value = true;
}

function openEdit(item) {
  form.value = {
    ...item,
    start: toDtLocal(item.start),
    end: toDtLocal(item.end),
    resultsPublicationDate: toDtLocal(item.resultsPublicationDate),
    archiveDate: toDateInput(item.archiveDate),
  };
  errors.value = {};
  dialog.value = true;
}

function toDtLocal(d) {
  if (!d) return null;
  const date = new Date(d);
  if (isNaN(date.getTime())) return null;
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function toDateInput(d) {
  if (!d) return null;
  const date = new Date(d);
  if (isNaN(date.getTime())) return null;
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

async function save() {
  saving.value = true;
  errors.value = {};
  try {
    const payload = { ...form.value };
    // Empty strings -> null for optional scalars the backend expects nullable
    for (const k of Object.keys(payload)) {
      if (payload[k] === '') payload[k] = null;
    }
    if (payload.id) {
      await api.put(`/admin/contests/${payload.id}`, payload);
    } else {
      await api.post('/admin/contests', payload);
    }
    dialog.value = false;
    loadData();
  } catch (e) {
    if (e.response?.status === 422) {
      const raw = e.response.data?.errors || {};
      const mapped = {};
      for (const k of Object.keys(raw)) mapped[k] = raw[k][0];
      errors.value = mapped;
    }
  }
  saving.value = false;
}

function confirmDelete(item) {
  deleteTarget.value = item;
  deleteDialog.value = true;
}

async function doDelete() {
  saving.value = true;
  try {
    await api.delete(`/admin/contests/${deleteTarget.value.id}`);
    deleteDialog.value = false;
    showSuccess('Конкурс удалён');
    loadData();
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось удалить конкурс');
  }
  saving.value = false;
}

onMounted(() => {
  loadReferences();
  loadData();
});
</script>
