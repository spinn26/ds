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

    <!-- Bulk action bar -->
    <v-slide-y-transition>
      <v-card v-if="selected.length" class="mb-3 pa-3" color="primary" variant="tonal">
        <div class="d-flex align-center flex-wrap ga-2">
          <v-chip color="primary" variant="flat">
            <v-icon start size="16">mdi-checkbox-multiple-marked</v-icon>
            Выбрано: {{ selected.length }}
          </v-chip>
          <v-btn size="small" variant="tonal" color="success"
            prepend-icon="mdi-account-check" @click="bulkRun('activate')">Активировать</v-btn>
          <v-btn size="small" variant="tonal" color="warning"
            prepend-icon="mdi-account-cancel" @click="bulkRun('terminate')">Терминировать</v-btn>
          <v-btn size="small" variant="tonal" color="error"
            prepend-icon="mdi-account-remove" @click="bulkRun('exclude')">Исключить</v-btn>
          <v-btn size="small" variant="tonal" color="info"
            prepend-icon="mdi-account-reactivate" @click="bulkRun('re-register')">Перерегистрировать</v-btn>
          <v-btn size="small" variant="tonal" color="grey"
            prepend-icon="mdi-lock" @click="bulkRun('block')">Заблокировать</v-btn>
          <v-btn size="small" variant="tonal" color="grey"
            prepend-icon="mdi-lock-open" @click="bulkRun('unblock')">Разблокировать</v-btn>
          <v-btn size="small" variant="tonal" color="secondary"
            prepend-icon="mdi-account-supervisor" @click="bulkSetInviter">Сменить наставника</v-btn>
          <v-spacer />
          <v-btn size="small" variant="text" prepend-icon="mdi-close" @click="selected = []">Снять выбор</v-btn>
        </div>
        <v-alert v-if="bulkMsg" :type="bulkMsgType" density="compact" class="mt-2" closable @click:close="bulkMsg = ''">
          {{ bulkMsg }}
        </v-alert>
      </v-card>
    </v-slide-y-transition>

    <DataTableWrapper
      v-model:selected="selected"
      selectable
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
        <v-chip v-if="value" size="x-small" :color="getActivityColorByName(value)">{{ value }}</v-chip>
        <span v-else>—</span>
      </template>
      <template #item.statusName>
        <v-chip size="x-small" color="primary" variant="tonal">Партнёр</v-chip>
      </template>
      <template #item.active="{ value }">
        <v-icon :color="value ? 'success' : 'grey'" size="small">
          {{ value ? 'mdi-check-circle' : 'mdi-minus-circle' }}
        </v-icon>
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
    <v-dialog v-model="editDialog" max-width="880" persistent scrollable>
      <v-card v-if="editForm">
        <v-card-title class="d-flex align-center ga-2">
          <span class="text-truncate">
            Редактировать{{ editForm.personName ? ` «${editForm.personName}»` : ' партнёра' }}
          </span>
          <v-chip size="small" :color="editActivityColor">{{ editForm.activityName || '—' }}</v-chip>
          <v-spacer />
          <span class="text-caption text-medium-emphasis">ID {{ editForm.id }}</span>
        </v-card-title>
        <v-card-text style="max-height:70vh">
          <div v-if="editLoading" class="text-center pa-6">
            <v-progress-circular indeterminate />
          </div>
          <template v-else>
            <v-row dense>
              <v-col cols="12"><div class="text-subtitle-2 font-weight-bold mb-2">ФИО (WebUser)</div></v-col>
              <v-col cols="12" sm="4"><v-text-field v-model="editForm.lastName" label="Фамилия" variant="outlined" density="compact" :error-messages="editErrors.lastName" /></v-col>
              <v-col cols="12" sm="4"><v-text-field v-model="editForm.firstName" label="Имя" variant="outlined" density="compact" :error-messages="editErrors.firstName" /></v-col>
              <v-col cols="12" sm="4"><v-text-field v-model="editForm.patronymic" label="Отчество" variant="outlined" density="compact" :error-messages="editErrors.patronymic" /></v-col>

              <v-col cols="12" class="mt-2"><div class="text-subtitle-2 font-weight-bold mb-2">Контакты</div></v-col>
              <v-col cols="12" sm="6"><v-text-field v-model="editForm.email" label="Email" type="email" variant="outlined" density="compact" :error-messages="editErrors.email" /></v-col>
              <v-col cols="12" sm="3"><v-text-field v-model="editForm.phone" label="Телефон" variant="outlined" density="compact" :error-messages="editErrors.phone" /></v-col>
              <v-col cols="12" sm="3"><v-text-field v-model="editForm.nicTG" label="Telegram" variant="outlined" density="compact" :error-messages="editErrors.nicTG" /></v-col>

              <v-col cols="12" class="mt-2"><div class="text-subtitle-2 font-weight-bold mb-2">Персональные данные</div></v-col>
              <v-col cols="12" sm="4"><v-select v-model="editForm.gender" :items="genderOptions" label="Пол" variant="outlined" density="compact" clearable :error-messages="editErrors.gender" /></v-col>
              <v-col cols="12" sm="4"><v-text-field v-model="editBirthDate" type="date" label="Дата рождения" variant="outlined" density="compact" :error-messages="editErrors.birthDate" /></v-col>
              <v-col cols="12" sm="4">
                <v-text-field v-model="editForm.role" label="Роль(и)" hint="Через запятую: consultant, admin, support" persistent-hint variant="outlined" density="compact" :error-messages="editErrors.role" />
              </v-col>

              <v-col cols="12" class="mt-2"><div class="text-subtitle-2 font-weight-bold mb-2">Сеть</div></v-col>
              <v-col cols="12" sm="4">
                <v-text-field v-model="editForm.participantCode" label="Реф. код"
                  variant="outlined" density="compact" :error-messages="editErrors.participantCode" />
              </v-col>
              <v-col cols="12" sm="4">
                <v-text-field v-model.number="editForm.inviter" type="number" label="Пригласивший (ID)"
                  :hint="editForm.inviterName ? `Сейчас: ${editForm.inviterName}` : ''" persistent-hint
                  variant="outlined" density="compact" :error-messages="editErrors.inviter" />
              </v-col>
              <v-col cols="12" sm="4">
                <v-checkbox v-model="editForm.isBlocked" label="Заблокирован" density="compact" hide-details />
              </v-col>

              <v-col cols="12" class="mt-2"><div class="text-subtitle-2 font-weight-bold mb-2">Смена пароля</div></v-col>
              <v-col cols="12" sm="6">
                <v-text-field v-model="editForm.newPassword" type="password"
                  label="Новый пароль (пусто — не менять)"
                  variant="outlined" density="compact" :error-messages="editErrors.newPassword" />
              </v-col>
            </v-row>

            <v-divider class="my-4" />
            <div class="text-subtitle-2 font-weight-bold mb-2">Смена статуса</div>
            <div class="d-flex ga-2 flex-wrap">
              <v-btn size="small" variant="tonal" color="success" prepend-icon="mdi-account-check"
                :disabled="editForm.activityId === 1"
                @click="changeStatus('activate')">Активировать</v-btn>
              <v-btn size="small" variant="tonal" color="warning" prepend-icon="mdi-account-cancel"
                @click="changeStatus('terminate')">Терминировать</v-btn>
              <v-btn size="small" variant="tonal" color="error" prepend-icon="mdi-account-remove"
                @click="changeStatus('exclude')">Исключить</v-btn>
              <v-btn size="small" variant="tonal" color="info" prepend-icon="mdi-account-reactivate"
                @click="changeStatus('re-register')">Перерегистрировать</v-btn>
            </div>
            <v-alert v-if="statusMsg" :type="statusMsgType" density="compact" class="mt-3" closable @click:close="statusMsg = ''">
              {{ statusMsg }}
            </v-alert>
          </template>
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
import { fmtDate, getActivityColorByName } from '../../composables/useDesign';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const activityFilter = ref(null);
const statusFilter = ref(null);
const statusOptions = ref([]);
const page = ref(1);
const perPage = ref(25);

// Bulk selection
const selected = ref([]);
const bulkMsg = ref('');
const bulkMsgType = ref('success');

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
  { title: 'Доступ', key: 'platformAccess', width: 80, sortable: false },
  { title: 'Дата регистрации', key: 'createdAt', width: 140 },
  { title: '', key: 'actions', sortable: false, width: 60 },
];

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
const editLoading = ref(false);
const editForm = ref(null);
const editErrors = ref({});
const saving = ref(false);
const statusMsg = ref('');
const statusMsgType = ref('success');

const genderOptions = [
  { title: 'Мужской', value: 'male' },
  { title: 'Женский', value: 'female' },
];

const editBirthDate = computed({
  get: () => editForm.value?.birthDate ? editForm.value.birthDate.split('T')[0] : '',
  set: (v) => { if (editForm.value) editForm.value.birthDate = v || null; },
});

const editActivityColor = computed(() => {
  const id = editForm.value?.activityId;
  if (id === 1) return 'success';
  if (id === 4) return 'info';
  if (id === 3) return 'warning';
  if (id === 5) return 'error';
  return 'grey';
});

async function openEdit(item) {
  editDialog.value = true;
  editLoading.value = true;
  editErrors.value = {};
  statusMsg.value = '';
  editForm.value = { id: item.id, personName: item.personName };
  try {
    const { data } = await api.get(`/admin/partners/${item.id}`);
    const c = data.consultant || {};
    const u = data.webUser || {};
    editForm.value = {
      id: c.id,
      personName: c.personName,
      participantCode: c.participantCode || '',
      inviter: c.inviter ?? null,
      inviterName: c.inviterName,
      activityId: c.activityId,
      activityName: c.activityName,
      firstName: u.firstName || '',
      lastName: u.lastName || '',
      patronymic: u.patronymic || '',
      email: u.email || '',
      phone: u.phone || '',
      nicTG: u.nicTG || '',
      gender: u.gender || null,
      birthDate: u.birthDate || null,
      role: u.role || '',
      isBlocked: !!u.isBlocked,
      newPassword: '',
    };
  } catch {}
  editLoading.value = false;
}

async function saveEdit() {
  saving.value = true;
  editErrors.value = {};
  try {
    const f = editForm.value;
    await api.put(`/admin/partners/${f.id}`, {
      participantCode: f.participantCode || null,
      inviter: f.inviter || null,
      firstName: f.firstName || null,
      lastName: f.lastName || null,
      patronymic: f.patronymic || null,
      email: f.email || null,
      phone: f.phone || null,
      nicTG: f.nicTG || null,
      gender: f.gender || null,
      birthDate: f.birthDate || null,
      role: f.role || null,
      isBlocked: !!f.isBlocked,
      newPassword: f.newPassword || null,
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

// ============ BULK ACTIONS ============
function selectedIds() {
  return selected.value.map(x => (typeof x === 'object' ? x.id : x));
}

async function bulkRun(action) {
  const ids = selectedIds();
  if (!ids.length) return;
  const labels = {
    activate: 'активировать', terminate: 'терминировать', exclude: 'исключить',
    're-register': 'перерегистрировать', block: 'заблокировать', unblock: 'разблокировать',
  };
  if (!confirm(`${ids.length} партнёр(ов) — ${labels[action]}. Продолжить?`)) return;

  let reason = '';
  if (action === 'terminate' || action === 'exclude') {
    reason = window.prompt('Причина (необязательно):', '') || '';
  }

  try {
    const { data } = await api.post('/admin/partners/bulk', { ids, action, reason });
    bulkMsg.value = data.message;
    bulkMsgType.value = data.fail > 0 ? 'warning' : 'success';
    selected.value = [];
    loadData();
  } catch (e) {
    bulkMsg.value = e.response?.data?.message || 'Ошибка массового действия';
    bulkMsgType.value = 'error';
  }
}

async function bulkSetInviter() {
  const ids = selectedIds();
  if (!ids.length) return;
  const inviterId = window.prompt('Введите ID нового наставника:', '');
  if (!inviterId) return;
  const n = parseInt(inviterId, 10);
  if (!Number.isFinite(n) || n <= 0) {
    bulkMsg.value = 'Некорректный ID';
    bulkMsgType.value = 'error';
    return;
  }
  if (!confirm(`${ids.length} партнёр(ов) → новый наставник ID ${n}. Продолжить?`)) return;
  try {
    const { data } = await api.post('/admin/partners/bulk', {
      ids, action: 'set-inviter', inviter: n,
    });
    bulkMsg.value = data.message;
    bulkMsgType.value = data.fail > 0 ? 'warning' : 'success';
    selected.value = [];
    loadData();
  } catch (e) {
    bulkMsg.value = e.response?.data?.message || 'Ошибка массового действия';
    bulkMsgType.value = 'error';
  }
}

async function changeStatus(action) {
  if (!editForm.value) return;
  let reason = '';
  if (action === 'terminate' || action === 'exclude') {
    reason = window.prompt('Причина (необязательно):', '') || '';
  }
  try {
    const { data } = await api.post(`/admin/partners/${editForm.value.id}/status`, { action, reason });
    statusMsg.value = data.message || 'Статус обновлён';
    statusMsgType.value = 'success';
    // Reload partner + list
    const { data: fresh } = await api.get(`/admin/partners/${editForm.value.id}`);
    editForm.value.activityId = fresh.consultant.activityId;
    editForm.value.activityName = fresh.consultant.activityName;
    loadData();
  } catch (e) {
    statusMsg.value = e.response?.data?.message || 'Ошибка смены статуса';
    statusMsgType.value = 'error';
  }
}

onMounted(loadData);
</script>
