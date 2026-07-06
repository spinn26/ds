<template>
  <div>
    <PageHeader title="Пользователи" icon="mdi-account-multiple">
      <template #actions>
        <v-btn variant="tonal" prepend-icon="mdi-microsoft-excel" :loading="exporting"
          :disabled="loading" class="me-2" @click="exportXlsx">
          Excel{{ activeFilterCount > 0 ? ' (по фильтру)' : '' }}
        </v-btn>
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
      <v-col cols="12" md="2" class="d-flex align-center">
        <!-- true-value/false-value=null: выкл → фильтр отбрасывается в useCrud
             (buildFilterParams), не считается активным и не шлётся на бэк. -->
        <v-switch v-model="filters.with_deleted" :true-value="true" :false-value="null"
          color="error" density="compact" hide-details label="Показать удалённых" />
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
      <template #item.lastName="{ item }">
        <span :class="{ 'text-medium-emphasis text-decoration-line-through': item.dateDeleted }">
          {{ item.lastName }}
        </span>
        <v-chip v-if="item.dateDeleted" size="x-small" color="error" variant="tonal" class="ms-1"
          :title="`Мягко удалён: ${item.dateDeleted}`">Удалён</v-chip>
      </template>
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
          <v-btn v-if="item.dateDeleted" icon="mdi-backup-restore" size="x-small" variant="text" color="success"
            title="Восстановить аккаунт" :loading="restoringId === item.id" @click.stop="restoreUser(item)" />
          <v-btn v-if="item.dateDeleted" icon="mdi-delete-forever" size="x-small" variant="text" color="error"
            title="Удалить физически (с переносом сущностей)" @click.stop="openForceDelete(item)" />
          <v-btn v-if="item.twoFactorEnabled && auth.isAdmin" icon="mdi-lock-reset" size="x-small" variant="text" color="warning"
            title="Отключить 2ФА" :loading="disabling2faId === item.id" @click.stop="disable2fa(item)" />
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

        <!-- Управление доступом партнёра (ручные переопределения гейтов).
             Показываем только для существующего партнёрского профиля. -->
        <template v-if="editForm.id && editForm.hasConsultant">
          <v-col cols="12" class="pb-0">
            <v-divider class="mb-2" />
            <div class="text-subtitle-2 font-weight-bold mb-1">Управление доступом партнёра</div>
            <div class="text-caption text-medium-emphasis mb-2">
              Ручные переопределения. Обычно ставятся автоматически (верификация / акцепт /
              смена реквизитов) — здесь можно проставить вручную.
            </div>
          </v-col>
          <v-col cols="12" sm="6" class="py-0">
            <v-switch v-model="editForm.productsAccessNoVerify" color="primary" density="compact" hide-details
              label="Доступ к продуктам без верификации" />
          </v-col>
          <v-col cols="12" sm="6" class="py-0">
            <v-switch v-model="editForm.requisitesVerified" color="success" density="compact" hide-details
              label="Реквизиты верифицированы" />
          </v-col>
          <v-col cols="12" sm="6" class="py-0">
            <v-switch v-model="editForm.offerAccepted" color="success" density="compact" hide-details
              label="Оферта принята" />
          </v-col>
          <v-col cols="12" sm="6" class="py-0">
            <v-switch v-model="editForm.paymentsSuspended" color="warning" density="compact" hide-details
              label="Выплаты приостановлены" />
          </v-col>
        </template>
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
    <!-- Физическое удаление аккаунта с переносом связанных сущностей -->
    <v-dialog v-model="forceDialog" max-width="620" scrollable>
      <v-card v-if="forceTarget">
        <v-card-title class="d-flex align-center ga-2 pa-4">
          <v-avatar color="error" variant="tonal" size="40"><v-icon>mdi-delete-forever</v-icon></v-avatar>
          <div class="d-flex flex-column">
            <span class="text-h6">Физическое удаление</span>
            <span class="text-caption text-medium-emphasis">
              {{ forceTarget.lastName }} {{ forceTarget.firstName }}
              <template v-if="forceTarget.email"> · {{ forceTarget.email }}</template>
              · id {{ forceTarget.id }}
            </span>
          </div>
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" size="small" @click="forceDialog = false" />
        </v-card-title>
        <v-divider />
        <v-card-text class="pa-4">
          <div v-if="forceLoading" class="d-flex justify-center pa-6">
            <v-progress-circular indeterminate size="32" />
          </div>
          <template v-else>
            <v-alert type="error" variant="tonal" density="compact" class="mb-3" icon="mdi-alert">
              Действие необратимо: строка WebUser удаляется полностью.
            </v-alert>

            <div v-if="forceRefs.length" class="mb-3">
              <div class="text-body-2 mb-2">
                На аккаунте есть связанные сущности — выберите аккаунт, <b>на который их перенести</b>:
              </div>
              <v-table density="compact" class="mb-3 force-refs">
                <thead><tr><th>Сущность</th><th class="text-end">Кол-во</th></tr></thead>
                <tbody>
                  <tr v-for="r in forceRefs" :key="r.table + r.column">
                    <td>{{ entityLabel(r.table) }} <span class="text-caption text-medium-emphasis">({{ r.table }}.{{ r.column }})</span></td>
                    <td class="text-end font-weight-medium">{{ r.count }}</td>
                  </tr>
                  <tr v-if="forceTokens"><td>Активные сессии (токены)</td><td class="text-end">{{ forceTokens }} · будут удалены</td></tr>
                </tbody>
              </v-table>
              <UserPicker v-model="forceReassignTo" label="Перенести сущности на аккаунт"
                placeholder="Имя или email живого аккаунта" />
            </div>

            <v-alert v-else type="success" variant="tonal" density="compact" icon="mdi-check">
              Связанных сущностей нет — аккаунт можно удалить без переноса.
            </v-alert>
          </template>
        </v-card-text>
        <v-divider />
        <v-card-actions class="pa-3">
          <v-spacer />
          <v-btn variant="text" @click="forceDialog = false">Отмена</v-btn>
          <v-btn color="error" variant="flat" :loading="forceDeleting"
            :disabled="forceLoading || (forceRefs.length > 0 && !forceReassignTo)"
            @click="doForceDelete">Удалить физически</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

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
import UserPicker from '../../components/UserPicker.vue';
import { useCrud } from '../../composables/useCrud';
import { exportToXlsx } from '../../composables/useExport';
import { useSnackbar } from '../../composables/useSnackbar';
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
    invest: 'cyan',
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
  { title: 'Инвест департамент', value: 'invest' },
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
  filters: { search: '', role: null, blocked: null, with_deleted: null },
  defaults: {
    firstName: '', lastName: '', patronymic: '', email: '', phone: '',
    role: 'registered', position: '', password: '', gender: '', birthDate: '',
    isBlocked: false, agreement: false,
    hasConsultant: false, productsAccessNoVerify: false,
    requisitesVerified: false, offerAccepted: false, paymentsSuspended: false,
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

// === Восстановление мягко-удалённого аккаунта ===
const restoringId = ref(null);
async function restoreUser(user) {
  restoringId.value = user.id;
  try {
    await api.post(`/admin/users/${user.id}/restore`);
    showSuccess('Аккаунт восстановлен');
    await load();
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось восстановить');
  } finally {
    restoringId.value = null;
  }
}

// === Сброс 2FA админом ===
const disabling2faId = ref(null);
async function disable2fa(user) {
  const fio = `${user.lastName || ''} ${user.firstName || ''}`.trim() || user.email;
  if (!window.confirm(`Отключить двухфакторную аутентификацию у «${fio}»? Пользователь сможет войти без кода и заново настроить 2FA.`)) return;
  disabling2faId.value = user.id;
  try {
    await api.post(`/admin/users/${user.id}/disable-2fa`);
    showSuccess('2FA отключена');
    await load();
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось отключить 2FA');
  } finally {
    disabling2faId.value = null;
  }
}

// === Физическое удаление с переносом сущностей ===
const forceDialog = ref(false);
const forceTarget = ref(null);
const forceLoading = ref(false);
const forceDeleting = ref(false);
const forceRefs = ref([]);
const forceTokens = ref(0);
const forceReassignTo = ref(null);

// Человекочитаемые ярлыки для самых частых legacy-таблиц; остальное —
// сырое имя таблицы (в скобках показываем table.column в любом случае).
const ENTITY_LABELS = {
  client: 'Клиенты', consultant: 'Партнёрские профили', contract: 'Контракты',
  commission: 'Комиссии', consultantBalance: 'Балансы', consultantPayment: 'Выплаты',
  requisites: 'Реквизиты', bankrequisites: 'Банк. реквизиты', criterion: 'Критерии',
  clientGoal: 'Цели клиентов', documentlogs: 'Логи документов', WebUser: 'Связь WebUser',
};
function entityLabel(t) { return ENTITY_LABELS[t] || t; }

async function openForceDelete(user) {
  forceTarget.value = user;
  forceReassignTo.value = null;
  forceRefs.value = [];
  forceTokens.value = 0;
  forceDialog.value = true;
  forceLoading.value = true;
  try {
    const { data } = await api.get(`/admin/users/${user.id}/references`);
    forceRefs.value = data.entities || [];
    forceTokens.value = data.tokens || 0;
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось получить связи аккаунта');
    forceDialog.value = false;
  } finally {
    forceLoading.value = false;
  }
}

async function doForceDelete() {
  if (!forceTarget.value) return;
  forceDeleting.value = true;
  try {
    await api.delete(`/admin/users/${forceTarget.value.id}/force`, {
      data: forceReassignTo.value ? { reassign_to: forceReassignTo.value } : {},
    });
    showSuccess('Аккаунт удалён физически');
    forceDialog.value = false;
    await load();
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось удалить');
  } finally {
    forceDeleting.value = false;
  }
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

// === Выгрузка в Excel (только строки по текущему фильтру) ===
const { showError, showSuccess } = useSnackbar();
const exporting = ref(false);

const exportColumns = [
  { title: 'ID', key: 'id' },
  { title: 'Фамилия', key: 'lastName' },
  { title: 'Имя', key: 'firstName' },
  { title: 'Отчество', key: 'patronymic' },
  { title: 'Email', key: 'email' },
  { title: 'Телефон', key: 'phone' },
  { title: 'Роли', key: 'role' },
  { title: 'Должность', key: 'position' },
  { title: 'Пол', key: 'gender' },
  { title: 'Дата рождения', key: 'birthDate' },
  { title: 'Заблокирован', key: 'isBlocked' },
  { title: 'Согласие', key: 'agreement' },
  { title: 'Реферальный код', key: 'participantCode' },
];

async function exportXlsx() {
  exporting.value = true;
  try {
    // Те же фильтры/сортировка, что в таблице — но без пагинации (бэкенд
    // отдаёт все подходящие строки на /admin/users/export).
    const params = {};
    for (const k of Object.keys(filters)) {
      const v = filters[k];
      if (v === '' || v === null || v === undefined) continue;
      params[k] = v;
    }
    if (sortBy.value.length) {
      params.sort_by = sortBy.value[0]?.key;
      params.sort_dir = sortBy.value[0]?.order;
    }
    const { data } = await api.get('/admin/users/export', { params });
    const rows = data.data || [];
    if (!rows.length) { showError('Нет строк для выгрузки'); return; }
    await exportToXlsx(rows, exportColumns, 'users');
    showSuccess(`Выгружено строк: ${rows.length}`);
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось выгрузить');
  } finally {
    exporting.value = false;
  }
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
