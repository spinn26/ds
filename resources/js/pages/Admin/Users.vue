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
      @update:page="page = $event; load()"
      @update:options="onTableOptions"
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
          <v-text-field v-model="editForm.position" label="Должность"
            placeholder="напр. Генеральный директор" />
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

    <!-- История входов. Гео — ip-api.com с кэшем `ip_geo_cache` (ttl 30д).
         Флаги — emoji из ISO-2 (regional indicator symbols), иконки браузера
         и ОС берутся из user-agent через uaParse(). -->
    <v-dialog v-model="loginHistoryDialog" max-width="980" scrollable>
      <v-card>
        <v-card-title class="d-flex align-center ga-2 pa-4">
          <v-avatar color="primary" variant="tonal" size="40">
            <v-icon>mdi-history</v-icon>
          </v-avatar>
          <div class="d-flex flex-column">
            <span class="text-h6">История входа</span>
            <span v-if="loginHistoryUser" class="text-caption text-medium-emphasis">
              {{ loginHistoryUser.lastName }} {{ loginHistoryUser.firstName }}
              <template v-if="loginHistoryUser.email"> · {{ loginHistoryUser.email }}</template>
            </span>
          </div>
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
          <v-list v-else density="compact" class="login-history-list pa-0">
            <template v-for="(row, idx) in loginHistoryItems" :key="row.id">
              <v-list-item class="py-3">
                <!-- Флаг страны: SVG из flagcdn.com (бесплатный CDN, без
                     auth). Emoji-вариант на Windows не работает (нет
                     glyph'ов флагов в системном шрифте) — поэтому
                     рендерим через <img>. -->
                <template #prepend>
                  <div class="login-history-flag" :title="row.country || 'Регион неизвестен'">
                    <img v-if="row.countryCode" :src="flagUrl(row.countryCode)"
                      :alt="row.countryCode" class="flag-img" loading="lazy" />
                    <v-icon v-else color="grey">mdi-earth-off</v-icon>
                  </div>
                </template>

                <!-- Основная инфа: гео + дата. -->
                <div class="d-flex flex-column">
                  <div class="d-flex align-center ga-2">
                    <strong>{{ row.country || 'Неизвестно' }}</strong>
                    <span v-if="row.region || row.city" class="text-body-2 text-medium-emphasis">
                      {{ [row.region, row.city].filter(Boolean).join(', ') }}
                    </span>
                    <v-chip v-if="row.action === 'login_2fa_challenge'"
                      size="x-small" color="warning" variant="tonal">
                      2FA
                    </v-chip>
                  </div>
                  <div class="text-caption text-medium-emphasis mt-1 d-flex flex-wrap align-center ga-3">
                    <span class="d-inline-flex align-center ga-1">
                      <v-icon size="14">mdi-clock-outline</v-icon>
                      {{ fmtDateTime(row.createdAt) }}
                    </span>
                    <span class="d-inline-flex align-center ga-1" :title="'IP-адрес'">
                      <v-icon size="14">mdi-ip-network</v-icon>
                      <code>{{ row.ip || '—' }}</code>
                    </span>
                    <span v-if="row.isp" class="d-inline-flex align-center ga-1" :title="'Провайдер'">
                      <v-icon size="14">mdi-server-network</v-icon>
                      {{ row.isp }}
                    </span>
                  </div>
                </div>

                <!-- Устройство справа: иконка браузера + иконка ОС + подписи. -->
                <template #append>
                  <div class="d-flex align-center ga-2 login-history-device">
                    <v-tooltip :text="parseUA(row.userAgent).browser + ' · ' + parseUA(row.userAgent).os" location="top">
                      <template #activator="{ props }">
                        <div v-bind="props" class="d-flex align-center ga-1 pa-2 rounded-lg"
                          :style="{ background: 'rgba(var(--v-theme-surface-variant), 0.3)' }">
                          <v-icon :color="parseUA(row.userAgent).browserColor" size="22">
                            {{ parseUA(row.userAgent).browserIcon }}
                          </v-icon>
                          <v-icon :color="parseUA(row.userAgent).osColor" size="20">
                            {{ parseUA(row.userAgent).osIcon }}
                          </v-icon>
                        </div>
                      </template>
                    </v-tooltip>
                  </div>
                </template>
              </v-list-item>
              <v-divider v-if="idx < loginHistoryItems.length - 1" />
            </template>
          </v-list>
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
  items, loading, page, perPage, total, sortBy, filters, activeFilterCount,
  editDialog, editForm, editErrors, editMessage, saving,
  deleteDialog, deleteTarget,
  load, resetFilters,
  openCreate: _openCreate, openEdit: _openEdit, save, confirmDelete, remove,
} = useCrud('admin/users', {
  filters: { search: '', role: null, blocked: null },
  defaults: {
    firstName: '', lastName: '', patronymic: '', email: '', phone: '',
    role: 'registered', position: '', password: '', gender: '', birthDate: '',
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

// Пагинацией страницы управляет @update:page (см. шаблон): у
// v-data-table-server двусторонний v-model:page откатывал options.page
// назад, поэтому page здесь НЕ трогаем — иначе сбросили бы выбранную
// страницу обратно. Реагируем только на смену размера страницы.
function onTableOptions(opts) {
  let needLoad = false;
  if (opts?.itemsPerPage != null && opts.itemsPerPage !== perPage.value) {
    perPage.value = opts.itemsPerPage;
    page.value = 1;
    needLoad = true;
  }
  // Клик по заголовку колонки приходит сюда же. Раньше sortBy игнорировался —
  // у серверной таблицы это значит, что сортировка не работала вовсе.
  const next = Array.isArray(opts?.sortBy) ? opts.sortBy : [];
  if (JSON.stringify(next) !== JSON.stringify(sortBy.value)) {
    sortBy.value = next;
    page.value = 1;
    needLoad = true;
  }
  if (needLoad) load();
}

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

// ISO-2 country code → URL SVG-флага в flagcdn.com (бесплатный CDN,
// `https://flagcdn.com/{w}/{code}.png`). w80 = 80px ширина, чётко на
// retina, и тянется как тонкая PNG-картинка вместо emoji. Раньше
// был flagEmoji() через regional indicator symbols — на Windows
// без emoji-шрифта показывались просто буквы «UA».
function flagUrl(code) {
  if (!code || code.length !== 2) return '';
  return `https://flagcdn.com/w80/${code.toLowerCase()}.png`;
}

// Разбираем UA на (browser, os) с MDI-иконками. Полный UA в tooltip
// у самой плашки — здесь только короткие узнаваемые ярлыки.
function parseUA(ua) {
  if (!ua) return { browser: '—', browserIcon: 'mdi-help-circle-outline', browserColor: 'grey', os: '—', osIcon: 'mdi-help-circle-outline', osColor: 'grey' };
  const s = String(ua);

  let browser = 'Браузер', browserIcon = 'mdi-web', browserColor = 'grey';
  // Порядок проверок важен: Edg/OPR подделываются под Chrome/Safari в UA.
  if (/Edg\//.test(s))             { browser = 'Edge';    browserIcon = 'mdi-microsoft-edge';  browserColor = 'blue'; }
  else if (/OPR\/|Opera/.test(s))   { browser = 'Opera';   browserIcon = 'mdi-opera';            browserColor = 'red'; }
  else if (/YaBrowser/.test(s))     { browser = 'Yandex';  browserIcon = 'mdi-alpha-y-circle';   browserColor = 'red-darken-2'; }
  else if (/Firefox\//.test(s))     { browser = 'Firefox'; browserIcon = 'mdi-firefox';          browserColor = 'orange-darken-2'; }
  else if (/Chrome\//.test(s))      { browser = 'Chrome';  browserIcon = 'mdi-google-chrome';    browserColor = 'green'; }
  else if (/Safari\//.test(s))      { browser = 'Safari';  browserIcon = 'mdi-apple-safari';     browserColor = 'blue-darken-2'; }
  else if (/MSIE|Trident/.test(s))  { browser = 'IE';      browserIcon = 'mdi-microsoft-internet-explorer'; browserColor = 'blue-grey'; }

  let os = 'ОС', osIcon = 'mdi-monitor', osColor = 'grey';
  if (/iPhone|iPad|iPod/.test(s))            { os = /iPad/.test(s) ? 'iPad' : 'iPhone'; osIcon = 'mdi-cellphone-iphone'; osColor = 'blue-grey'; }
  else if (/Android/.test(s))                 { os = 'Android';     osIcon = 'mdi-android';            osColor = 'green-darken-1'; }
  else if (/Mac OS X|Macintosh/.test(s))      { os = 'macOS';       osIcon = 'mdi-apple';              osColor = 'grey-darken-2'; }
  else if (/Windows NT/.test(s))              { os = 'Windows';     osIcon = 'mdi-microsoft-windows';  osColor = 'blue'; }
  else if (/Linux/.test(s))                   { os = 'Linux';       osIcon = 'mdi-linux';              osColor = 'amber-darken-2'; }

  return { browser, browserIcon, browserColor, os, osIcon, osColor };
}

onMounted(load);
</script>

<style scoped>
.login-history-list :deep(.v-list-item) {
  padding-left: 16px;
  padding-right: 16px;
}
.login-history-flag {
  width: 44px;
  height: 44px;
  border-radius: 8px;
  background: rgba(var(--v-theme-surface-variant), 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: 12px;
}
.flag-img {
  /* SVG/PNG-флаг из flagcdn.com. 36×24 — естественные пропорции 3:2,
     лёгкая рамка чтобы белые флаги (например 🇯🇵) не сливались с фоном. */
  width: 36px;
  height: 24px;
  object-fit: cover;
  border-radius: 3px;
  box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.12);
}
.login-history-device code {
  font-size: 12px;
}
</style>
