<template>
  <div>
    <PageHeader title="Партнёры" icon="mdi-account-search" :count="total" />

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по ФИО..."
          rounded prepend-inner-icon="mdi-magnify" clearable hide-details style="max-width:300px" @update:model-value="debouncedLoad" />
        <v-select v-model="activityFilter" :items="activityOptions" label="Активность"
          clearable hide-details style="max-width:200px" @update:model-value="loadData" />
        <v-select v-model="statusFilter" :items="statusOptions" label="Статус"
          clearable hide-details style="max-width:200px" @update:model-value="loadData" />
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
      empty-icon="mdi-account-search-outline"
      empty-message="Партнёры не найдены"
      @update:options="onOptions"
    >
      <template #item.activityName="{ value }">
        <v-chip v-if="value" size="x-small" :color="activityColor(value)">{{ value }}</v-chip>
        <span v-else>—</span>
      </template>
      <template #item.statusName="{ value }">
        <v-chip v-if="value" size="x-small" color="secondary">{{ value }}</v-chip>
      </template>
      <template #item.active="{ value }">
        <v-icon :color="value ? 'success' : 'grey'" size="small">
          {{ value ? 'mdi-check-circle' : 'mdi-minus-circle' }}
        </v-icon>
      </template>
      <template #item.isClient="{ value }">
        <v-icon v-if="value" color="success" size="small">mdi-check-circle</v-icon>
        <v-icon v-else color="grey" size="small">mdi-minus-circle</v-icon>
      </template>
      <template #item.platformAccess="{ value }">
        <v-icon v-if="value" color="success" size="small">mdi-lock-open-variant</v-icon>
        <v-icon v-else color="grey" size="small">mdi-lock</v-icon>
      </template>
      <template #item.birthDate="{ value }">{{ fmtDate(value) }}</template>
      <template #item.createdAt="{ value }">{{ fmtDate(value) }}</template>
      <template #item.actions="{ item }">
        <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openEdit(item)" />
      </template>
    </DataTableWrapper>

    <!-- Edit dialog -->
    <v-dialog v-model="editDialog" max-width="420" persistent>
      <v-card>
        <v-card-title>Редактировать партнёра</v-card-title>
        <v-card-text>
          <div class="text-body-2 text-medium-emphasis mb-3">
            {{ editForm.personName }} (ID {{ editForm.id }})
          </div>
          <v-text-field v-model="editForm.participantCode" label="Реф. код (participantCode)"
            variant="outlined" density="compact" class="mb-2"
            :error-messages="editErrors.participantCode" />
          <v-text-field v-model.number="editForm.inviter" type="number" label="Пригласивший (ID консультанта)"
            variant="outlined" density="compact"
            :error-messages="editErrors.inviter" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="editDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" @click="saveEdit">Сохранить</v-btn>
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
import { fmtDate } from '../../composables/useDesign';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const activityFilter = ref(null);
const statusFilter = ref(null);
const statusOptions = ref([]);
const page = ref(1);
const perPage = ref(25);

const activeFilterCount = computed(() => {
  let c = 0;
  if (search.value) c++;
  if (activityFilter.value) c++;
  if (statusFilter.value) c++;
  return c;
});

function resetFilters() {
  search.value = '';
  activityFilter.value = null;
  statusFilter.value = null;
  loadData();
}

const activityOptions = [
  { title: 'Активен', value: '1' },
  { title: 'Терминирован', value: '3' },
  { title: 'Зарегистрирован', value: '4' },
  { title: 'Исключён', value: '5' },
];

const headers = [
  { title: 'ID', key: 'id', width: 60 },
  { title: 'Person ID', key: 'personId', width: 90 },
  { title: 'ФИО', key: 'personName' },
  { title: 'Email', key: 'email' },
  { title: 'Телефон', key: 'phone', width: 140 },
  { title: 'Дата рождения', key: 'birthDate', width: 130 },
  { title: 'Статус', key: 'statusName', width: 140 },
  { title: 'Активность', key: 'activityName', width: 130 },
  { title: 'Активен', key: 'active', width: 80 },
  { title: 'Код', key: 'participantCode', width: 100 },
  { title: 'Пригласивший', key: 'inviterName' },
  { title: 'Куратор', key: 'curatorName' },
  { title: 'Клиент?', key: 'isClient', width: 80, sortable: false },
  { title: 'Доступ', key: 'platformAccess', width: 80, sortable: false },
  { title: 'Дата регистрации', key: 'createdAt', width: 140 },
  { title: '', key: 'actions', sortable: false, width: 60 },
];

function activityColor(name) {
  if (!name) return 'grey';
  const l = name.toLowerCase();
  if (l.includes('актив') && !l.includes('не')) return 'success';
  if (l.includes('терминир') || l.includes('исключ')) return 'error';
  if (l.includes('зарег')) return 'info';
  return 'warning';
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
    if (activityFilter.value) params.activity = activityFilter.value;
    if (statusFilter.value) params.status = statusFilter.value;
    const { data } = await api.get('/admin/partners', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

const editDialog = ref(false);
const editForm = ref({ id: null, personName: '', participantCode: '', inviter: null });
const editErrors = ref({});
const saving = ref(false);

function openEdit(item) {
  editForm.value = {
    id: item.id,
    personName: item.personName,
    participantCode: item.participantCode || '',
    inviter: item.inviterId ?? null,
  };
  editErrors.value = {};
  editDialog.value = true;
}

async function saveEdit() {
  saving.value = true;
  editErrors.value = {};
  try {
    await api.put(`/admin/partners/${editForm.value.id}`, {
      participantCode: editForm.value.participantCode || null,
      inviter: editForm.value.inviter || null,
    });
    editDialog.value = false;
    loadData();
  } catch (e) {
    if (e.response?.status === 422) {
      const raw = e.response.data?.errors || {};
      const mapped = {};
      for (const k of Object.keys(raw)) mapped[k] = raw[k][0];
      editErrors.value = mapped;
    }
  }
  saving.value = false;
}

onMounted(loadData);
</script>
