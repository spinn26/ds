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

    <FilterBar
      :search="filters.search"
      search-placeholder="Поиск..."
      :search-cols="3"
      @update:search="v => { filters.search = v ?? ''; }"
    >
      <v-col cols="12" md="3">
        <v-select v-model="filters.status" :items="statusOptions" item-title="name" item-value="id"
          label="Статус" clearable hide-details density="comfortable" variant="outlined" />
      </v-col>
      <v-col cols="12" md="3">
        <v-select v-model="filters.type" :items="typeOptions" item-title="name" item-value="id"
          label="Тип" clearable hide-details density="comfortable" variant="outlined" />
      </v-col>
    </FilterBar>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="perPage" @update:options="onOptions" density="compact" hover>
      <template #item.typeName="{ item }">
        <v-chip size="x-small" variant="outlined">{{ item.typeName || '—' }}</v-chip>
      </template>
      <template #item.statusName="{ item }">
        <StatusChip :value="item.status" kind="contest" size="x-small" :text="item.statusName || '—'" />
      </template>
      <template #item.start="{ value }">{{ fmtDate(value) }}</template>
      <template #item.end="{ value }">{{ fmtDate(value) }}</template>
      <template #item.actions="{ item }">
        <ActionsCell @edit="openEdit(item)" @delete="confirmDelete(item)" />
      </template>
    </v-data-table-server>

    <DialogShell
      v-model="editDialog"
      :title="editForm.id ? `Редактировать${editForm.name ? ` «${editForm.name}»` : ' конкурс / событие'}` : 'Новый конкурс / событие'"
      :max-width="900"
      persistent
      :loading="saving"
      :confirm-disabled="!editForm.name"
      @confirm="save"
    >
      <v-row dense>
        <v-col cols="12">
          <v-text-field v-model="editForm.name" label="Название *" variant="outlined" density="compact"
            :error-messages="fieldErr('name')" />
        </v-col>
        <v-col cols="12">
          <v-textarea v-model="editForm.description" label="Описание" variant="outlined" density="compact" rows="3"
            :error-messages="fieldErr('description')" />
        </v-col>

        <v-col cols="12" md="4">
          <v-select v-model="editForm.type" :items="typeOptions" item-title="name" item-value="id"
            label="Тип" variant="outlined" density="compact" clearable :error-messages="fieldErr('type')" />
        </v-col>
        <v-col cols="12" md="4">
          <v-select v-model="editForm.status" :items="statusOptions" item-title="name" item-value="id"
            label="Статус" variant="outlined" density="compact" clearable :error-messages="fieldErr('status')" />
        </v-col>
        <v-col cols="12" md="4">
          <v-select v-model="editForm.typeEvent" :items="typeEventOptions" item-title="title" item-value="value"
            label="Тип события" variant="outlined" density="compact" clearable :error-messages="fieldErr('typeEvent')" />
        </v-col>

        <v-col cols="12" md="4">
          <v-text-field v-model="editForm.start" type="datetime-local" label="Начало"
            variant="outlined" density="compact" :error-messages="fieldErr('start')" />
        </v-col>
        <v-col cols="12" md="4">
          <v-text-field v-model="editForm.end" type="datetime-local" label="Окончание"
            variant="outlined" density="compact" :error-messages="fieldErr('end')" />
        </v-col>
        <v-col cols="12" md="4">
          <v-text-field v-model="editForm.resultsPublicationDate" type="datetime-local"
            label="Дата публикации результатов" variant="outlined" density="compact"
            :error-messages="fieldErr('resultsPublicationDate')" />
        </v-col>

        <v-col cols="12" md="4">
          <v-text-field v-model.number="editForm.numberOfWinners" type="number" label="Количество победителей"
            variant="outlined" density="compact" min="0" :error-messages="fieldErr('numberOfWinners')" />
        </v-col>
        <v-col cols="12" md="4">
          <v-select v-model="editForm.criterion" :items="criterionOptions" item-title="name" item-value="id"
            label="Критерий" variant="outlined" density="compact" clearable :error-messages="fieldErr('criterion')" />
        </v-col>
        <v-col cols="12" md="4">
          <v-text-field v-model.number="editForm.numericValue" type="number" step="0.01"
            label="Числовое значение (план)" variant="outlined" density="compact"
            :error-messages="fieldErr('numericValue')" />
        </v-col>
        <v-col cols="12" md="6">
          <v-text-field v-model="editForm.nameNumericValue" label="Название показателя"
            variant="outlined" density="compact" :error-messages="fieldErr('nameNumericValue')" />
        </v-col>
        <v-col cols="12" md="6">
          <v-text-field v-model="editForm.presentation" label="Ссылка на презентацию"
            variant="outlined" density="compact" :error-messages="fieldErr('presentation')" />
        </v-col>

        <v-col cols="12" md="6">
          <v-autocomplete v-model="editForm.product" :items="productOptions" item-title="name" item-value="id"
            label="Продукт" variant="outlined" density="compact" clearable :error-messages="fieldErr('product')" />
        </v-col>
        <v-col cols="12" md="6">
          <v-autocomplete v-model="editForm.program" :items="programsForProduct" item-title="name" item-value="id"
            label="Программа" variant="outlined" density="compact" clearable :error-messages="fieldErr('program')" />
        </v-col>

        <v-col cols="12" md="6">
          <v-text-field v-model="editForm.visibility" label="Видимость (строка)"
            hint='Пример: "finСonsultant, resident"' persistent-hint
            variant="outlined" density="compact" :error-messages="fieldErr('visibility')" />
        </v-col>
        <v-col cols="12" md="3">
          <v-checkbox v-model="editForm.visibilityConsultants" label="Виден консультантам" density="compact" hide-details />
        </v-col>
        <v-col cols="12" md="3">
          <v-checkbox v-model="editForm.visibilityResidents" label="Виден резидентам" density="compact" hide-details />
        </v-col>

        <v-col cols="12" md="3">
          <v-checkbox v-model="editForm.conditionalTurnOn" label="Условное включение" density="compact" hide-details />
        </v-col>
        <v-col cols="12" md="4">
          <v-text-field v-model="editForm.archiveDate" type="date" label="Дата архивации"
            variant="outlined" density="compact" :error-messages="fieldErr('archiveDate')" />
        </v-col>

        <v-col cols="12" md="6">
          <v-text-field v-model="editForm.urlData" label="URL данных" variant="outlined" density="compact"
            :error-messages="fieldErr('urlData')" />
        </v-col>
        <v-col cols="12" md="6">
          <v-text-field v-model="editForm.headers" label="Заголовки HTTP" variant="outlined" density="compact"
            :error-messages="fieldErr('headers')" />
        </v-col>
        <v-col cols="12">
          <v-textarea v-model="editForm.techComment" label="Технический комментарий"
            variant="outlined" density="compact" rows="2" :error-messages="fieldErr('techComment')" />
        </v-col>
      </v-row>
    </DialogShell>

    <DialogShell
      v-model="deleteDialog"
      title="Удалить?"
      :max-width="400"
      :loading="saving"
      confirm-text="Удалить"
      confirm-color="error"
      @confirm="remove"
    >
      {{ deleteTarget?.name }}
    </DialogShell>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { fmtDate } from '../../composables/useDesign';
import {
  StatusChip, FilterBar, DialogShell, ActionsCell,
} from '../../components';
import { useCrud } from '../../composables/useCrud';

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

const defaults = {
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

const {
  items, total, loading, page, perPage, filters,
  editDialog, editForm, editErrors, saving,
  deleteDialog, deleteTarget,
  load, onOptions,
  openCreate, openEdit: _openEdit, save, confirmDelete, remove,
} = useCrud('admin/contests', {
  filters: { search: '', status: null, type: null },
  defaults,
  normalise: (d) => ({ items: d.contests ?? [], total: d.total ?? 0 }),
  beforeSave: (payload) => {
    // Empty strings → null for optional nullable scalars.
    for (const k of Object.keys(payload)) {
      if (payload[k] === '') payload[k] = null;
    }
    return payload;
  },
  labels: {
    created: 'Конкурс создан',
    updated: 'Конкурс обновлён',
    deleted: 'Конкурс удалён',
    error: 'Не удалось сохранить',
  },
});

// Server's 422 errors come as { field: [msg, msg] }. We unwrap to first msg for v-text-field.
function fieldErr(name) {
  const v = editErrors.value?.[name];
  return Array.isArray(v) ? v[0] : (v || '');
}

// Date formatting for datetime-local and type=date inputs.
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

function openEdit(item) {
  _openEdit({
    ...item,
    start: toDtLocal(item.start),
    end: toDtLocal(item.end),
    resultsPublicationDate: toDtLocal(item.resultsPublicationDate),
    archiveDate: toDateInput(item.archiveDate),
  });
}

const programsForProduct = computed(() => {
  if (!editForm.value.product) return programOptions.value;
  return programOptions.value.filter(p => String(p.product) === String(editForm.value.product));
});

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

onMounted(() => { loadReferences(); load(); });
</script>
