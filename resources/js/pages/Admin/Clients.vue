<template>
  <div>
    <PageHeader title="Клиенты" icon="mdi-account-group" :count="total">
      <template #actions>
        <v-btn color="success" prepend-icon="mdi-plus" @click="openAddClient">
          Добавить клиента
        </v-btn>
      </template>
    </PageHeader>

    <v-card class="mb-3 pa-3">
      <v-row dense align="center">
        <v-col cols="12" md="3">
          <v-text-field v-model="search" placeholder="ФИО / Email / Телефон"
            density="comfortable" variant="outlined" hide-details clearable
            prepend-inner-icon="mdi-magnify"
            @update:model-value="debouncedLoad" />
        </v-col>
        <v-col cols="12" md="2">
          <v-text-field v-model="filters.id" placeholder="ID клиента"
            density="comfortable" variant="outlined" hide-details clearable
            @update:model-value="debouncedLoad" />
        </v-col>
        <v-col cols="12" md="2">
          <v-text-field v-model="filters.consultantName" placeholder="ФИО консультанта"
            density="comfortable" variant="outlined" hide-details clearable
            @update:model-value="debouncedLoad" />
        </v-col>
        <v-col cols="12" md="2">
          <v-text-field v-model="filters.comment" placeholder="Комментарий"
            density="comfortable" variant="outlined" hide-details clearable
            @update:model-value="debouncedLoad" />
        </v-col>
        <v-col cols="12" md="3">
          <div class="d-flex ga-2">
            <v-text-field v-model="filters.created_from" label="Заведён с" type="date"
              density="comfortable" variant="outlined" hide-details
              @update:model-value="loadData" />
            <v-text-field v-model="filters.created_to" label="по" type="date"
              density="comfortable" variant="outlined" hide-details
              @update:model-value="loadData" />
          </div>
        </v-col>
        <v-col cols="auto" class="d-flex align-center">
          <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal" class="ml-1">
            {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
          </v-chip>
          <v-btn v-if="activeFilterCount > 0" size="small" variant="text" color="secondary"
            prepend-icon="mdi-filter-remove" @click="resetFilters">Сбросить</v-btn>
        </v-col>
        <v-col cols="auto" class="d-flex align-center ms-auto">
          <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="clients-cols" />
        </v-col>
      </v-row>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="visibleHeaders" :items-per-page="25" @update:options="onOptions">
      <template #item.isPartner="{ value }">
        <v-icon v-if="value" color="success" size="small">mdi-check-circle</v-icon>
      </template>
      <template #item.products="{ value }">
        <v-chip v-for="p in (value || [])" :key="p" size="x-small" class="mr-1" color="primary" variant="outlined">{{ p }}</v-chip>
      </template>
      <template #item.chat="{ item }">
        <StartChatButton :partner-id="item.consultantId || item.consultant" :partner-name="item.consultantName"
          context-type="Клиент" :context-id="item.id" :context-label="item.personName || '#' + item.id" />
      </template>
      <template #item.birthDate="{ value }">
        {{ fmtDate(value) }}
      </template>
      <template #item.actions="{ item }">
        <v-btn icon="mdi-delete" size="x-small" variant="text" color="error"
          title="Удалить" @click.stop="confirmDeleteClient(item)" />
      </template>
      <template #no-data><EmptyState /></template>
    </v-data-table-server>

    <DialogShell
      v-model="deleteDialogOpen"
      title="Удалить клиента?"
      :max-width="500"
      :loading="deleting"
      confirm-text="Удалить"
      confirm-color="error"
      @confirm="performDeleteClient"
    >
      <p class="mb-2">
        <strong>{{ deleteTarget?.personName }}</strong> (ID {{ deleteTarget?.id }})
      </p>
      <p class="text-body-2 text-medium-emphasis mb-3">
        Soft-delete (<code>dateDeleted</code>). Блокируется если у клиента
        есть активные контракты — сначала закрой их.
      </p>
      <v-textarea v-model="deleteReason" label="Причина (для аудита)"
        variant="outlined" density="comfortable" rows="2" />
    </DialogShell>

    <!-- Двухшаг «Добавить клиента» per spec ✅Клиенты §3 -->
    <v-dialog v-model="addOpen" max-width="640" persistent>
      <v-card>
        <v-card-title class="d-flex align-center">
          <v-icon class="me-2">mdi-account-plus</v-icon>
          {{ addStep === 1 ? 'Шаг 1: проверка на дубли' : 'Шаг 2: новый клиент' }}
          <v-spacer />
          <v-btn icon="mdi-close" size="small" variant="text" @click="addOpen = false" />
        </v-card-title>

        <v-card-text v-if="addStep === 1">
          <div class="text-body-2 mb-3">
            Введите фамилию или email — система найдёт совпадения, чтобы избежать дубля.
          </div>
          <v-text-field v-model="addSearch" label="Фамилия / email / телефон"
            variant="outlined" density="comfortable"
            prepend-inner-icon="mdi-magnify" autofocus
            @update:model-value="searchAddCandidates" />
          <v-progress-linear v-if="addSearching" indeterminate class="mt-2" />
          <v-list v-if="addCandidates.length" density="compact" class="mt-2">
            <v-list-item v-for="c in addCandidates" :key="c.id"
              :title="c.personName"
              :subtitle="`${c.email || c.phone || '—'} · ID ${c.id}${c.consultantName ? ' · ' + c.consultantName : ''}`">
              <template #prepend><v-icon>mdi-account-circle</v-icon></template>
            </v-list-item>
          </v-list>
          <v-alert v-else-if="addSearch.length >= 2 && !addSearching"
            type="info" variant="tonal" density="compact" class="mt-2">
            Совпадений не найдено.
          </v-alert>
        </v-card-text>

        <v-card-text v-else>
          <v-row dense>
            <v-col cols="12" sm="4"><v-text-field v-model="addForm.lastName"
              label="Фамилия *" variant="outlined" density="comfortable" /></v-col>
            <v-col cols="12" sm="4"><v-text-field v-model="addForm.firstName"
              label="Имя *" variant="outlined" density="comfortable" /></v-col>
            <v-col cols="12" sm="4"><v-text-field v-model="addForm.patronymic"
              label="Отчество" variant="outlined" density="comfortable" /></v-col>
            <v-col cols="12" sm="6"><v-text-field v-model="addForm.email"
              label="Email" type="email" variant="outlined" density="comfortable" /></v-col>
            <v-col cols="12" sm="6"><v-text-field v-model="addForm.phone"
              label="Телефон" variant="outlined" density="comfortable" /></v-col>
            <v-col cols="12" sm="6"><v-text-field v-model="addForm.birthDate"
              label="Дата рождения" type="date" variant="outlined" density="comfortable" /></v-col>
            <v-col cols="12" sm="6"><v-text-field v-model="addForm.city"
              label="Город" variant="outlined" density="comfortable" /></v-col>
            <v-col cols="12" sm="6"><v-text-field v-model="addForm.consultant"
              label="Консультант (ID)" type="number" variant="outlined" density="comfortable"
              hint="ID партнёра-наставника" persistent-hint /></v-col>
            <v-col cols="12"><v-textarea v-model="addForm.comment"
              label="Комментарий" variant="outlined" density="comfortable" rows="2" /></v-col>
          </v-row>
          <v-alert v-if="addError" type="error" density="compact" class="mt-2">{{ addError }}</v-alert>
        </v-card-text>

        <v-card-actions>
          <v-btn v-if="addStep === 2" variant="text" prepend-icon="mdi-arrow-left"
            @click="addStep = 1">Назад</v-btn>
          <v-spacer />
          <v-btn v-if="addStep === 1" variant="text" @click="addOpen = false">Отмена</v-btn>
          <v-btn v-if="addStep === 1" color="success" prepend-icon="mdi-plus"
            :disabled="!addSearch || addSearch.length < 2" @click="gotoNewClientStep">
            + Добавить нового клиента
          </v-btn>
          <v-btn v-else color="success" prepend-icon="mdi-content-save"
            :loading="addSaving"
            :disabled="!addForm.firstName || !addForm.lastName || !addForm.consultant"
            @click="saveNewClient">
            Создать клиента
          </v-btn>
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
import DialogShell from '../../components/DialogShell.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';

const columnVisible = ref({});
const visibleHeaders = computed(() => headers.filter(h => columnVisible.value[h.key] !== false));
import { useSnackbar } from '../../composables/useSnackbar';
import { fmtDate } from '../../composables/useDesign';

const { showSuccess, showError } = useSnackbar();
const deleteDialogOpen = ref(false);
const deleteTarget = ref(null);
const deleteReason = ref('');
const deleting = ref(false);

function confirmDeleteClient(item) {
  deleteTarget.value = item;
  deleteReason.value = '';
  deleteDialogOpen.value = true;
}

async function performDeleteClient() {
  if (!deleteTarget.value?.id) return;
  deleting.value = true;
  try {
    await api.delete(`/admin/clients/${deleteTarget.value.id}`, {
      data: { reason: deleteReason.value },
    });
    showSuccess('Клиент удалён');
    deleteDialogOpen.value = false;
    loadData();
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось удалить');
  }
  deleting.value = false;
}

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const page = ref(1);
const perPage = ref(25);
const sortBy = ref('');
const sortDir = ref('desc');
const filters = ref({ id: '', consultantName: '', comment: '', created_from: '', created_to: '' });

const activeFilterCount = computed(() => {
  let c = 0;
  if (search.value) c++;
  Object.values(filters.value).forEach(v => { if (v) c++; });
  return c;
});

function resetFilters() {
  search.value = '';
  filters.value = { id: '', consultantName: '', comment: '', created_from: '', created_to: '' };
  loadData();
}

const headers = [
  { title: 'ФИО', key: 'personName' },
  { title: 'Email', key: 'email' },
  { title: 'Телефон', key: 'phone' },
  { title: 'Дата рождения', key: 'birthDate', width: 130 },
  { title: 'Город', key: 'city' },
  { title: 'Работаем с', key: 'workSince', width: 130 },
  { title: 'Контракты', key: 'contractCount', width: 110, align: 'end' },
  { title: 'Партнёр?', key: 'isPartner', width: 90, sortable: false },
  { title: 'Консультант', key: 'consultantName' },
  { title: 'Статус партнёра', key: 'consultantStatus', width: 160 },
  { title: 'Комментарий', key: 'comment' },
  { title: 'Продукты', key: 'products', sortable: false },
  { title: '', key: 'chat', sortable: false, width: 50 },
  { title: '', key: 'actions', sortable: false, width: 50 },
];

const { debounced: debouncedLoad } = useDebounce(loadData, 400);

function onOptions(opts) {
  page.value = opts.page;
  if (opts.itemsPerPage) perPage.value = opts.itemsPerPage;
  if (Array.isArray(opts.sortBy) && opts.sortBy.length) {
    sortBy.value = opts.sortBy[0].key;
    sortDir.value = opts.sortBy[0].order || 'desc';
  } else {
    sortBy.value = '';
    sortDir.value = 'desc';
  }
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value };
    if (search.value) params.search = search.value;
    if (filters.value.id) params.id = filters.value.id;
    if (filters.value.consultantName) params.consultant_name = filters.value.consultantName;
    if (filters.value.comment) params.comment = filters.value.comment;
    if (filters.value.created_from) params.created_from = filters.value.created_from;
    if (filters.value.created_to) params.created_to = filters.value.created_to;
    if (sortBy.value) {
      params.sort_by = sortBy.value;
      params.sort_dir = sortDir.value;
    }
    const { data } = await api.get('/admin/clients', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

// Двухшаг «Добавить клиента» per spec ✅Клиенты §3.
const addOpen = ref(false);
const addStep = ref(1);
const addSearch = ref('');
const addCandidates = ref([]);
const addSearching = ref(false);
const addSaving = ref(false);
const addError = ref('');
const addForm = ref({
  firstName: '', lastName: '', patronymic: '',
  email: '', phone: '', birthDate: '',
  city: '', consultant: null, comment: '',
});
let addSearchTimer;

function openAddClient() {
  addOpen.value = true;
  addStep.value = 1;
  addSearch.value = '';
  addCandidates.value = [];
  addError.value = '';
  addForm.value = {
    firstName: '', lastName: '', patronymic: '',
    email: '', phone: '', birthDate: '',
    city: '', consultant: null, comment: '',
  };
}

function searchAddCandidates(q) {
  clearTimeout(addSearchTimer);
  if (!q || q.length < 2) {
    addCandidates.value = [];
    return;
  }
  addSearchTimer = setTimeout(async () => {
    addSearching.value = true;
    try {
      const { data } = await api.get('/admin/clients', { params: { search: q, per_page: 10 } });
      addCandidates.value = data.data || [];
    } catch {}
    addSearching.value = false;
  }, 300);
}

function gotoNewClientStep() {
  const parts = addSearch.value.trim().split(/\s+/);
  if (parts[0]) addForm.value.lastName = parts[0];
  if (parts[1]) addForm.value.firstName = parts[1];
  if (parts[2]) addForm.value.patronymic = parts[2];
  addStep.value = 2;
  addError.value = '';
}

async function saveNewClient() {
  addSaving.value = true;
  addError.value = '';
  try {
    await api.post('/admin/clients', addForm.value);
    addOpen.value = false;
    await loadData();
    showSuccess('Клиент создан');
  } catch (e) {
    addError.value = e.response?.data?.message || 'Ошибка сохранения';
  }
  addSaving.value = false;
}

onMounted(loadData);
</script>
