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
  </div>
</template>

<script setup>
import { onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import api from '../../api';
import {
  PageHeader, FilterBar, DataTableWrapper, StatusChip, BooleanCell, ActionsCell,
  DialogShell, FormErrors, ColumnVisibilityMenu,
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

// Short list — для фильтра справа (оставляем основные)
const roleOptions = [
  { title: 'Администратор', value: 'admin' },
  { title: 'Бэкофис', value: 'backoffice' },
  { title: 'Консультант', value: 'consultant' },
  { title: 'Зарегистрирован-Партнёр', value: 'registered' },
];

// Full list — все 8 ролей для формы редактирования
const allRoleOptions = [
  { title: 'Администратор', value: 'admin' },
  { title: 'Бэкофис (БЭК)', value: 'backoffice' },
  { title: 'Техподдержка', value: 'support' },
  { title: 'Руководитель', value: 'head' },
  { title: 'Фин. менеджер', value: 'finance' },
  { title: 'Расчёты (Богданова)', value: 'calculations' },
  { title: 'Правки', value: 'corrections' },
  { title: 'Консультант', value: 'consultant' },
  { title: 'Зарегистрирован-Партнёр', value: 'registered' },
];

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

onMounted(load);
</script>
