<template>
  <div>
    <PageHeader title="Пользователи" icon="mdi-account-multiple">
      <template #actions>
        <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreate">Добавить</v-btn>
      </template>
    </PageHeader>

    <FilterBar
      :search="filters.search"
      search-placeholder="Поиск по ФИО или email"
      :search-cols="4"
      :show-reset="activeFilterCount > 0"
      @update:search="v => { filters.search = v ?? ''; }"
      @reset="resetFilters"
    >
      <v-col cols="12" md="3">
        <v-select v-model="filters.role" label="Роль" :items="roleOptions"
          variant="outlined" density="comfortable" clearable hide-details />
      </v-col>
      <v-col cols="12" md="3">
        <v-select v-model="filters.blocked" label="Заблокирован" :items="blockedOptions"
          variant="outlined" density="comfortable" clearable hide-details />
      </v-col>
      <v-col v-if="activeFilterCount > 0" cols="auto" class="d-flex align-center">
        <v-chip size="small" color="info" variant="tonal">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
        </v-chip>
      </v-col>
      <v-col cols="auto" class="d-flex align-center ms-auto">
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="users-cols" />
      </v-col>
    </FilterBar>

    <DataTableWrapper
      :items="items"
      :headers="visibleHeaders"
      :loading="loading"
      server-side
      :page="page"
      :items-per-page="perPage"
      :items-length="total"
      empty-icon="mdi-account-search-outline"
      empty-message="Пользователи не найдены"
      @update:options="onOptions"
    >
      <template #item.role="{ item }">
        <StatusChip v-for="r in (item.role || '').split(',')" :key="r" size="x-small" class="mr-1"
          :color="roleColor(r.trim())" :text="r.trim()" />
      </template>
      <template #item.isBlocked="{ item }">
        <BooleanCell :value="!!item.isBlocked"
          true-icon="mdi-lock" false-icon="mdi-lock-open"
          true-color="error" false-color="success"
          :tooltip="{ on: 'Заблокирован', off: 'Активен' }" />
      </template>
      <template #item.actions="{ item }">
        <ActionsCell @edit="openEdit(item)" @delete="confirmDelete(item)">
          <v-btn icon="mdi-history" size="x-small" variant="text" color="secondary"
            title="История входа" @click.stop="openLoginHistory(item)" />
          <v-btn icon="mdi-login" size="x-small" variant="text" color="secondary"
            title="Войти как" @click.stop="impersonate(item)" />
        </ActionsCell>
      </template>
    </DataTableWrapper>

    <DialogShell
      v-model="editDialog"
      :title="(editForm.id ? 'Редактировать' : 'Добавить') + ' пользователя'"
      :max-width="600"
      persistent
      :loading="saving"
      @confirm="save"
    >
      <FormErrors :errors="editErrors" :message="editMessage" />
      <v-row dense>
        <v-col cols="12" sm="4">
          <v-text-field v-model="editForm.lastName" label="Фамилия" />
        </v-col>
        <v-col cols="12" sm="4">
          <v-text-field v-model="editForm.firstName" label="Имя" />
        </v-col>
        <v-col cols="12" sm="4">
          <v-text-field v-model="editForm.patronymic" label="Отчество" />
        </v-col>
        <v-col cols="12" sm="6">
          <v-text-field v-model="editForm.email" label="Электронная почта" type="email" />
        </v-col>
        <v-col cols="12" sm="6">
          <v-text-field v-model="editForm.phone" label="Телефон" />
        </v-col>
        <v-col cols="12" sm="6">
          <v-select
            v-model="editFormRoles"
            :items="allRoleOptions"
            item-title="title" item-value="value"
            label="Роли"
            multiple chips closable-chips
            hint="Можно выбрать несколько"
            persistent-hint
          />
        </v-col>
        <v-col cols="12" sm="6">
          <v-text-field v-model="editForm.password" label="Новый пароль" type="password"
            :placeholder="editForm.id ? 'оставьте пустым' : ''" />
        </v-col>
        <v-col cols="12" sm="6">
          <v-select v-model="editForm.gender" label="Пол" :items="['Мужской', 'Женский']" clearable />
        </v-col>
        <v-col cols="12" sm="6">
          <v-text-field v-model="editForm.birthDate" label="Дата рождения" type="date" />
        </v-col>
        <v-col v-if="editForm.id" cols="12" sm="6">
          <v-text-field v-model="editForm.participantCode" label="Реферальный код"
            :hint="editForm.participantCode ? 'Изменение сломает существующие партнёрские ссылки' : 'Партнёр без кода — не сможет приглашать'"
            persistent-hint prepend-inner-icon="mdi-tag-outline" />
        </v-col>
        <v-col cols="12" sm="6">
          <v-checkbox v-model="editForm.isBlocked" label="Заблокирован" density="compact" />
        </v-col>
        <v-col cols="12" sm="6">
          <v-checkbox v-model="editForm.agreement" label="Согласие" density="compact" />
        </v-col>
      </v-row>
    </DialogShell>

    <DialogShell
      v-model="deleteDialog"
      title="Удалить пользователя?"
      :max-width="400"
      :loading="saving"
      confirm-text="Удалить"
      confirm-color="error"
      @confirm="remove"
    >
      {{ deleteTarget?.lastName }} {{ deleteTarget?.firstName }} ({{ deleteTarget?.email }})
    </DialogShell>

    <!-- История входов. Резолв страны/региона/города по IP — ip-api.com
         с локальным кэшем `ip_geo_cache`, ttl 30 дней. -->
    <v-dialog v-model="loginHistoryDialog" max-width="900" scrollable>
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="primary">mdi-history</v-icon>
          История входа
          <span v-if="loginHistoryUser" class="text-body-2 text-medium-emphasis">
            · {{ loginHistoryUser.lastName }} {{ loginHistoryUser.firstName }}
          </span>
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" size="small" @click="loginHistoryDialog = false" />
        </v-card-title>
        <v-divider />
        <v-card-text class="pa-0" style="max-height: 70vh; overflow-y: auto;">
          <div v-if="loginHistoryLoading" class="d-flex align-center justify-center pa-6">
            <v-progress-circular indeterminate size="32" />
          </div>
          <EmptyState v-else-if="!loginHistoryItems.length"
            message="Записей о входах не найдено" icon="mdi-history" class="pa-6" />
          <v-table v-else density="compact">
            <thead>
              <tr>
                <th>Дата / время</th>
                <th>IP</th>
                <th>Регион</th>
                <th>Провайдер</th>
                <th>Браузер</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in loginHistoryItems" :key="row.id">
                <td class="text-no-wrap">
                  <div>{{ fmtDateTime(row.createdAt) }}</div>
                  <v-chip v-if="row.action === 'login_2fa_challenge'"
                    size="x-small" color="warning" variant="tonal" class="mt-1">
                    2FA challenge
                  </v-chip>
                </td>
                <td class="text-no-wrap"><code>{{ row.ip || '—' }}</code></td>
                <td>
                  <div v-if="row.country">{{ row.country }}</div>
                  <div v-if="row.region || row.city" class="text-caption text-medium-emphasis">
                    {{ [row.region, row.city].filter(Boolean).join(', ') }}
                  </div>
                  <span v-if="!row.country" class="text-medium-emphasis">—</span>
                </td>
                <td class="text-caption">{{ row.isp || '—' }}</td>
                <td class="text-caption text-medium-emphasis"
                  :title="row.userAgent">{{ shortUA(row.userAgent) }}</td>
              </tr>
            </tbody>
          </v-table>
        </v-card-text>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import api from '../../api';
import {
  PageHeader, FilterBar, DataTableWrapper, StatusChip, BooleanCell, ActionsCell,
  DialogShell, FormErrors, ColumnVisibilityMenu, EmptyState,
} from '../../components';
import { useCrud } from '../../composables/useCrud';
import { ref, computed } from 'vue';

function roleColor(r) {
  return ({
    admin: 'red',
    backoffice: 'orange',
    support: 'blue',
    head: 'purple',
    finance: 'teal',
    calculations: 'brown',
    corrections: 'amber',
    consultant: 'green',
  }[r]) || 'grey';
}

const auth = useAuthStore();
const router = useRouter();

// Единый список ролей — используется и в фильтре, и в форме редактирования.
// Источник истины — config/cabinetPermissions.js + партнёрские роли.
const allRoleOptions = [
  { title: 'Администратор', value: 'admin' },
  { title: 'Бэкофис (БЭК)', value: 'backoffice' },
  { title: 'Техподдержка', value: 'support' },
  { title: 'Руководитель', value: 'head' },
  { title: 'Фин. менеджер', value: 'finance' },
  { title: 'Расчёты (Богданова)', value: 'calculations' },
  { title: 'Правки', value: 'corrections' },
  { title: 'Отдел обучения', value: 'education' },
  { title: 'Консультант', value: 'consultant' },
  { title: 'Зарегистрирован-Партнёр', value: 'registered' },
];
const roleOptions = allRoleOptions;

const blockedOptions = [
  { title: 'Да', value: 'true' },
  { title: 'Нет', value: 'false' },
];

const headers = [
  { title: 'ID', key: 'id', width: 60 },
  { title: 'Фамилия', key: 'lastName' },
  { title: 'Имя', key: 'firstName' },
  { title: 'Email', key: 'email' },
  { title: 'Телефон', key: 'phone' },
  { title: 'Роли', key: 'role', width: 200 },
  { title: 'Блок', key: 'isBlocked', width: 60 },
  { title: 'Действия', key: 'actions', sortable: false, width: 120 },
];

const columnVisible = ref({});
const visibleHeaders = computed(() => headers.filter(h => columnVisible.value[h.key] !== false));

const {
  items, loading, page, perPage, total, filters, activeFilterCount,
  editDialog, editForm, editErrors, editMessage, saving,
  deleteDialog, deleteTarget,
  load, onOptions, resetFilters,
  openCreate: _openCreate, openEdit: _openEdit, save, confirmDelete, remove,
} = useCrud('admin/users', {
  filters: { search: '', role: null, blocked: null },
  defaults: {
    firstName: '', lastName: '', patronymic: '', email: '', phone: '',
    role: 'registered', password: '', gender: '', birthDate: '',
    isBlocked: false, agreement: false,
  },
  normalise: (d) => ({
    items: d.data ?? d.items ?? [],
    total: d.total ?? d.meta?.total ?? 0,
  }),
  labels: {
    created: 'Пользователь создан',
    updated: 'Пользователь обновлён',
    deleted: 'Пользователь удалён',
    error: 'Ошибка',
  },
});

// Роли в БД — CSV-строка; в UI — массив. Прокси через computed.
const editFormRoles = computed({
  get: () => {
    const raw = editForm.value?.role;
    if (!raw) return [];
    return String(raw).split(',').map(s => s.trim()).filter(Boolean);
  },
  set: (arr) => {
    editForm.value.role = (arr || []).join(',');
  },
});

// Override openCreate/openEdit to normalise birthDate to yyyy-MM-dd for <input type=date>.
function openCreate() { _openCreate(); }
function openEdit(user) {
  _openEdit({ ...user, password: '', birthDate: user.birthDate ? user.birthDate.split('T')[0] : '' });
}

async function impersonate(user) {
  try {
    if (auth.token) sessionStorage.setItem('impersonator_token', auth.token);
    const { data } = await api.post(`/impersonate/${user.id}`);
    auth.token = data.token;
    auth.user = data.user;
    router.push('/');
  } catch {}
}

// === История входа ===
const loginHistoryDialog = ref(false);
const loginHistoryLoading = ref(false);
const loginHistoryItems = ref([]);
const loginHistoryUser = ref(null);

async function openLoginHistory(user) {
  loginHistoryUser.value = user;
  loginHistoryDialog.value = true;
  loginHistoryLoading.value = true;
  loginHistoryItems.value = [];
  try {
    const { data } = await api.get(`/admin/users/${user.id}/login-history`);
    loginHistoryItems.value = data.data || [];
  } catch {} finally {
    loginHistoryLoading.value = false;
  }
}

function fmtDateTime(d) {
  if (!d) return '';
  const dt = new Date(d);
  if (isNaN(dt)) return d;
  return dt.toLocaleString('ru-RU', {
    day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}

// Из user-agent оставляем «Chrome/Firefox/Safari + ОС» — полный UA в title.
function shortUA(ua) {
  if (!ua) return '—';
  const s = String(ua);
  const browser = s.match(/(Edg|OPR|Chrome|Firefox|Safari)\/[\d.]+/i)?.[0]
    || s.match(/MSIE [\d.]+|Trident/i)?.[0]
    || 'Браузер';
  const os = s.match(/Windows NT [\d.]+|Mac OS X [\d_]+|Android [\d.]+|iPhone OS [\d_]+|Linux/i)?.[0]
    || '';
  return [browser.replace(/\//, ' '), os].filter(Boolean).join(' · ');
}

onMounted(load);
</script>
