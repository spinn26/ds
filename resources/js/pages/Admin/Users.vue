<template>
  <div>
    <div class="d-flex justify-space-between align-center mb-4 flex-wrap ga-2">
      <h5 class="text-h5 font-weight-bold">Пользователи</h5>
      <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreate">Добавить</v-btn>
    </div>

    <!-- Filters -->
    <v-card class="mb-4 pa-3">
      <v-row dense>
        <v-col cols="12" sm="4">
          <v-text-field v-model="filters.search" label="Поиск по ФИО или email" prepend-inner-icon="mdi-magnify"
            density="compact" variant="outlined" rounded clearable hide-details @update:model-value="debouncedLoad" />
        </v-col>
        <v-col cols="12" sm="3">
          <v-select v-model="filters.role" label="Роль" :items="roleOptions" density="compact"
            clearable hide-details @update:model-value="loadUsers" />
        </v-col>
        <v-col cols="12" sm="3">
          <v-select v-model="filters.blocked" label="Заблокирован" :items="blockedOptions" density="compact"
            clearable hide-details @update:model-value="loadUsers" />
        </v-col>
        <v-col cols="12" sm="2" class="d-flex align-center ga-1">
          <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal">
            {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
          </v-chip>
          <v-btn v-if="activeFilterCount > 0" size="small" variant="text" color="secondary"
            prepend-icon="mdi-filter-remove" @click="resetFilters">Сбросить</v-btn>
        </v-col>
      </v-row>
    </v-card>

    <!-- Table -->
    <v-card>
      <v-data-table-server
        :headers="headers"
        :items="users"
        :items-length="total"
        :loading="loading"
        :items-per-page="25"
        @update:page="page = $event; loadUsers()"
        density="compact"
        hover
      >
        <template #item.role="{ item }">
          <v-chip v-for="r in (item.role || '').split(',')" :key="r" size="x-small" class="mr-1"
            :color="r.trim() === 'admin' ? 'red' : r.trim() === 'backoffice' ? 'orange' : r.trim() === 'consultant' ? 'green' : 'grey'">
            {{ r.trim() }}
          </v-chip>
        </template>
        <template #item.isBlocked="{ item }">
          <v-icon :color="item.isBlocked ? 'error' : 'success'" size="small">
            {{ item.isBlocked ? 'mdi-lock' : 'mdi-lock-open' }}
          </v-icon>
        </template>
        <template #item.actions="{ item }">
          <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openEdit(item)" />
          <v-btn icon="mdi-login" size="x-small" variant="text" color="secondary" title="Войти как"
            @click="impersonate(item)" />
          <v-btn icon="mdi-delete" size="x-small" variant="text" color="error"
            @click="confirmDelete(item)" />
        </template>
        <template #no-data>
          <div class="text-center pa-4">
            <v-icon size="48" color="grey-lighten-1" class="mb-2">mdi-file-search-outline</v-icon>
            <div class="text-medium-emphasis">Данные не найдены</div>
          </div>
        </template>
      </v-data-table-server>
    </v-card>

    <!-- Edit/Create Dialog -->
    <v-dialog v-model="dialog" max-width="600" persistent>
      <v-card>
        <v-card-title>{{ editUser?.id ? 'Редактировать' : 'Добавить' }} пользователя</v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col cols="12" sm="4">
              <v-text-field v-model="editUser.lastName" label="Фамилия" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="editUser.firstName" label="Имя" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="editUser.patronymic" label="Отчество" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="editUser.email" label="Электронная почта" type="email" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="editUser.phone" label="Телефон" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="editUser.role" label="Роли" hint="admin, backoffice, consultant, registered" persistent-hint />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="editUser.password" label="Новый пароль" type="password"
                :placeholder="editUser.id ? 'оставьте пустым' : ''" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-select v-model="editUser.gender" label="Пол" :items="['Мужской', 'Женский']" clearable />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="editUser.birthDate" label="Дата рождения" type="date" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-checkbox v-model="editUser.isBlocked" label="Заблокирован" density="compact" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-checkbox v-model="editUser.agreement" label="Согласие" density="compact" />
            </v-col>
          </v-row>
          <v-alert v-if="editError" type="error" density="compact" class="mt-2">{{ editError }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="dialog = false">Отмена</v-btn>
          <v-btn color="primary" @click="saveUser" :loading="saving">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete confirm -->
    <v-dialog v-model="deleteDialog" max-width="400">
      <v-card>
        <v-card-title>Удалить пользователя?</v-card-title>
        <v-card-text>{{ deleteTarget?.lastName }} {{ deleteTarget?.firstName }} ({{ deleteTarget?.email }})</v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="deleteDialog = false">Отмена</v-btn>
          <v-btn color="error" @click="deleteUser" :loading="saving">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';

const auth = useAuthStore();
const router = useRouter();
const users = ref([]);
const total = ref(0);
const page = ref(1);
const loading = ref(false);
const dialog = ref(false);
const deleteDialog = ref(false);
const deleteTarget = ref(null);
const saving = ref(false);
const editError = ref('');
const editUser = ref({});

const filters = ref({ search: '', role: null, blocked: null });

const activeFilterCount = computed(() => {
  let c = 0;
  if (filters.value.search) c++;
  if (filters.value.role) c++;
  if (filters.value.blocked) c++;
  return c;
});

function resetFilters() {
  filters.value = { search: '', role: null, blocked: null };
  loadUsers();
}

const roleOptions = [
  { title: 'Администратор', value: 'admin' },
  { title: 'Бэкофис', value: 'backoffice' },
  { title: 'Консультант', value: 'consultant' },
  { title: 'Зарегистрирован', value: 'registered' },
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

const { debounced: debouncedLoad } = useDebounce(loadData, 400);

async function loadUsers() {
  loading.value = true;
  try {
    const params = { page: page.value };
    if (filters.value.search) params.search = filters.value.search;
    if (filters.value.role) params.role = filters.value.role;
    if (filters.value.blocked) params.blocked = filters.value.blocked;
    const { data } = await api.get('/admin/users', { params });
    users.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

function openCreate() {
  editUser.value = { firstName: '', lastName: '', patronymic: '', email: '', phone: '', role: 'registered', password: '', gender: '', birthDate: '', isBlocked: false, agreement: false };
  editError.value = '';
  dialog.value = true;
}

function openEdit(user) {
  editUser.value = { ...user, password: '', birthDate: user.birthDate ? user.birthDate.split('T')[0] : '' };
  editError.value = '';
  dialog.value = true;
}

async function saveUser() {
  saving.value = true;
  editError.value = '';
  try {
    if (editUser.value.id) {
      await api.put(`/admin/users/${editUser.value.id}`, editUser.value);
    } else {
      await api.post('/admin/users', editUser.value);
    }
    dialog.value = false;
    loadUsers();
  } catch (e) {
    editError.value = e.response?.data?.message || 'Ошибка сохранения';
  }
  saving.value = false;
}

function confirmDelete(user) {
  deleteTarget.value = user;
  deleteDialog.value = true;
}

async function deleteUser() {
  saving.value = true;
  try {
    await api.delete(`/admin/users/${deleteTarget.value.id}`);
    deleteDialog.value = false;
    loadUsers();
  } catch {}
  saving.value = false;
}

async function impersonate(user) {
  try {
    const { data } = await api.post(`/impersonate/${user.id}`);
    localStorage.setItem('auth_token', data.token);
    localStorage.setItem('impersonator_id', data.impersonator_id);
    auth.token = data.token;
    auth.user = data.user;
    router.push('/');
  } catch {}
}

onMounted(loadUsers);
</script>
