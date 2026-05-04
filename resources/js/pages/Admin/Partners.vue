<template>
  <div>
    <PageHeader title="Партнёры" icon="mdi-account-search" :count="total">
      <template #actions>
        <v-btn color="success" prepend-icon="mdi-plus" @click="openAddPartner">
          Добавить партнёра
        </v-btn>
      </template>
    </PageHeader>

    <FilterBar
      :search="search"
      search-placeholder="ФИО партнёра"
      :search-cols="2"
      :show-reset="activeFilterCount > 0"
      @update:search="v => { search = v ?? ''; debouncedLoad(); }"
      @reset="resetFilters"
    >
      <v-col cols="12" md="2">
        <v-text-field v-model="filters.partnerId" placeholder="ИД партнёра"
          density="comfortable" variant="outlined" hide-details clearable
          @update:model-value="debouncedLoad" />
      </v-col>
      <v-col cols="12" md="2">
        <v-text-field v-model="filters.inviterName" placeholder="ФИО пригласителя"
          density="comfortable" variant="outlined" hide-details clearable
          @update:model-value="debouncedLoad" />
      </v-col>
      <v-col cols="12" md="2">
        <v-text-field v-model="filters.email" placeholder="Эл. почта"
          density="comfortable" variant="outlined" hide-details clearable
          @update:model-value="debouncedLoad" />
      </v-col>
      <v-col cols="12" md="2">
        <v-text-field v-model="filters.phone" placeholder="Телефон"
          density="comfortable" variant="outlined" hide-details clearable
          @update:model-value="debouncedLoad" />
      </v-col>
      <v-col cols="12" md="2">
        <v-select v-model="activityFilter" :items="activityOptions" label="Активность"
          variant="outlined" density="comfortable"
          clearable hide-details @update:model-value="loadData" />
      </v-col>
      <v-col cols="12" md="2">
        <v-select v-model="statusFilter" :items="statusOptions" label="Статус"
          variant="outlined" density="comfortable"
          clearable hide-details @update:model-value="loadData" />
      </v-col>
      <v-col v-if="activeFilterCount > 0" cols="auto" class="d-flex align-center">
        <v-chip size="small" color="info" variant="tonal">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
        </v-chip>
      </v-col>
      <template #actions>
        <v-menu :close-on-content-click="false">
          <template #activator="{ props: menuProps }">
            <v-btn size="small" variant="text" prepend-icon="mdi-view-column" v-bind="menuProps">Колонки</v-btn>
          </template>
          <v-list density="compact" style="min-width: 220px">
            <v-list-item v-for="col in toggleableColumns" :key="col.key">
              <template #prepend>
                <v-checkbox-btn :model-value="columnVisible[col.key]" @update:model-value="v => columnVisible[col.key] = v" />
              </template>
              <v-list-item-title>{{ col.title }}</v-list-item-title>
            </v-list-item>
          </v-list>
        </v-menu>
      </template>
    </FilterBar>

    <!-- Bulk action bar: two primary actions + destructive + overflow menu -->
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
          <v-menu>
            <template #activator="{ props: menuProps }">
              <v-btn size="small" variant="text" append-icon="mdi-chevron-down" v-bind="menuProps">Ещё</v-btn>
            </template>
            <v-list density="compact">
              <v-list-item prepend-icon="mdi-account-reactivate" title="Перерегистрировать" @click="bulkRun('re-register')" />
              <v-list-item prepend-icon="mdi-lock" title="Заблокировать" @click="bulkRun('block')" />
              <v-list-item prepend-icon="mdi-lock-open" title="Разблокировать" @click="bulkRun('unblock')" />
              <v-list-item prepend-icon="mdi-account-supervisor" title="Сменить наставника" @click="bulkSetInviter" />
            </v-list>
          </v-menu>
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
      :headers="visibleHeaders"
      :items-per-page="25"
      :row-props="rowProps"
      server-side
      empty-icon="mdi-account-search-outline"
      empty-message="Партнёры не найдены"
      class="partners-table"
      @update:options="onOptions"
    >
      <template #item.id="{ item }">
        <div class="d-flex align-center ga-1">
          <span>{{ item.id }}</span>
          <v-btn icon="mdi-content-copy" size="x-small" variant="text"
            title="Скопировать ID"
            @click.stop="copyToClipboard(item.id)" />
        </div>
      </template>
      <template #item.activityName="{ value }">
        <StatusChip v-if="value" :value="value" kind="activityName" size="x-small" :text="value" />
        <span v-else>—</span>
      </template>
      <template #item.isClient="{ item }">
        <v-icon :color="item.isClient ? 'success' : 'grey-lighten-1'" size="20"
          :title="item.isClient ? 'Партнёр является клиентом' : 'Не клиент'">
          {{ item.isClient ? 'mdi-check-circle' : 'mdi-minus-circle-outline' }}
        </v-icon>
      </template>
      <template #item.statusChangeDate="{ item }">
        <span v-if="item.statusChangeDate" :class="isStatusChangeSoon(item) ? 'text-error font-weight-bold' : ''">
          {{ fmtDate(item.statusChangeDate) }}
        </span>
        <span v-else class="text-medium-emphasis">—</span>
      </template>
      <template #item.active="{ value }">
        <v-icon :color="value ? 'success' : 'grey'" size="small">
          {{ value ? 'mdi-check-circle' : 'mdi-minus-circle' }}
        </v-icon>
      </template>
      <template #item.platformAccess="{ value }">
        <v-tooltip :text="value ? 'Доступ открыт' : 'Доступ заблокирован'" location="top">
          <template #activator="{ props: tipProps }">
            <v-icon v-bind="tipProps" :color="value ? 'success' : 'grey'" size="small">
              {{ value ? 'mdi-lock-open-variant' : 'mdi-lock' }}
            </v-icon>
          </template>
        </v-tooltip>
      </template>
      <template #item.birthDate="{ value }">{{ fmtDate(value) }}</template>
      <template #item.createdAt="{ value }">{{ fmtDate(value) }}</template>
      <template #item.actions="{ item }">
        <v-tooltip text="Редактировать" location="top">
          <template #activator="{ props: tipProps }">
            <v-btn v-bind="tipProps" icon="mdi-pencil" size="x-small" variant="text" @click="openEdit(item)" />
          </template>
        </v-tooltip>
        <v-tooltip text="Удалить" location="top">
          <template #activator="{ props: tipProps }">
            <v-btn v-bind="tipProps" icon="mdi-delete" size="x-small" variant="text" color="error"
              @click.stop="confirmDeletePartner(item)" />
          </template>
        </v-tooltip>
      </template>
    </DataTableWrapper>

    <!-- Delete dialog -->
    <DialogShell
      v-model="deleteDialogOpen"
      title="Удалить партнёра?"
      :max-width="500"
      :loading="deleting"
      confirm-text="Удалить"
      confirm-color="error"
      @confirm="performDeletePartner"
    >
      <p class="mb-2">
        <strong>{{ deleteTarget?.personName }}</strong>
        (ID {{ deleteTarget?.id }})
      </p>
      <p class="text-body-2 text-medium-emphasis mb-3">
        Удаление — soft-delete (выставит <code>dateDeleted</code>). FK из
        контрактов/комиссий/транзакций сохраняются. Если у партнёра есть
        активные дети в структуре — сервер отклонит запрос.
      </p>
      <v-textarea v-model="deleteReason" label="Причина (для аудита)"
        variant="outlined" density="comfortable" rows="2" />
    </DialogShell>

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

    <!-- Двухшаг «Добавить партнёра» per spec ✅Партнёры §2 -->
    <v-dialog v-model="addOpen" max-width="640" persistent>
      <v-card>
        <v-card-title class="d-flex align-center">
          <v-icon class="me-2">mdi-account-plus</v-icon>
          {{ addStep === 1 ? 'Шаг 1: проверка на дубли' : 'Шаг 2: новая персона' }}
          <v-spacer />
          <v-btn icon="mdi-close" size="small" variant="text" @click="addOpen = false" />
        </v-card-title>

        <v-card-text v-if="addStep === 1">
          <div class="text-body-2 mb-3">
            Выберите существующую персону или добавьте новую, если не удаётся найти её в списке.
          </div>
          <v-text-field v-model="addSearch" label="Начните вводить фамилию"
            variant="outlined" density="comfortable"
            prepend-inner-icon="mdi-magnify" autofocus
            @update:model-value="searchAddCandidates" />
          <v-progress-linear v-if="addSearching" indeterminate class="mt-2" />
          <v-list v-if="addCandidates.length" density="compact" class="mt-2">
            <v-list-item v-for="p in addCandidates" :key="p.id"
              :title="p.personName" :subtitle="`${p.email || '—'} · ID ${p.id}`"
              @click="pickExisting(p)">
              <template #prepend><v-icon>mdi-account</v-icon></template>
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
            <v-col cols="12" sm="6"><v-text-field v-model="addForm.participantCode"
              label="Партнёрский код" variant="outlined" density="comfortable"
              hint="Сгенерируется автоматически при активации" persistent-hint /></v-col>
            <v-col cols="12" sm="6">
              <v-select v-model="addForm.activity"
                :items="[{title:'Зарегистрирован',value:4},{title:'Активный',value:1},
                         {title:'Терминирован',value:3},{title:'Исключён',value:5}]"
                label="Статус активности *" variant="outlined" density="comfortable" />
            </v-col>
            <v-col cols="12" sm="6"><v-text-field v-model="addForm.inviter"
              label="Пригласитель (ID)" type="number" variant="outlined" density="comfortable"
              hint="ID существующего партнёра-наставника" persistent-hint /></v-col>
          </v-row>
          <v-alert v-if="addError" type="error" density="compact" class="mt-2">{{ addError }}</v-alert>
        </v-card-text>

        <v-card-actions>
          <v-btn v-if="addStep === 2" variant="text" prepend-icon="mdi-arrow-left"
            @click="addStep = 1">Назад</v-btn>
          <v-spacer />
          <v-btn v-if="addStep === 1" variant="text" @click="addOpen = false">Отмена</v-btn>
          <v-btn v-if="addStep === 1" color="success" prepend-icon="mdi-plus"
            :disabled="!addSearch || addSearch.length < 2" @click="gotoNewPersonStep">
            + Добавить новую персону
          </v-btn>
          <v-btn v-else color="success" prepend-icon="mdi-content-save"
            :loading="addSaving"
            :disabled="!addForm.firstName || !addForm.lastName"
            @click="saveNewPartner">
            Создать партнёра
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import PageHeader from '../../components/PageHeader.vue';
import DataTableWrapper from '../../components/DataTableWrapper.vue';
import StatusChip from '../../components/StatusChip.vue';
import FilterBar from '../../components/FilterBar.vue';
import DialogShell from '../../components/DialogShell.vue';
import { useSnackbar } from '../../composables/useSnackbar';
import { useConfirm } from '../../composables/useConfirm';
import { fmtDate, getActivityColorByName } from '../../composables/useDesign';

const confirm = useConfirm();

const { showSuccess, showError } = useSnackbar();
const deleteDialogOpen = ref(false);
const deleteTarget = ref(null);
const deleteReason = ref('');
const deleting = ref(false);

function confirmDeletePartner(item) {
  deleteTarget.value = item;
  deleteReason.value = '';
  deleteDialogOpen.value = true;
}

async function performDeletePartner() {
  if (!deleteTarget.value?.id) return;
  deleting.value = true;
  try {
    await api.delete(`/admin/partners/${deleteTarget.value.id}`, {
      data: { reason: deleteReason.value },
    });
    showSuccess('Партнёр удалён');
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
const activityFilter = ref(null);
const statusFilter = ref(null);
const statusOptions = ref([]);
const page = ref(1);
const perPage = ref(25);
const filters = ref({
  partnerId: '', inviterName: '', email: '', phone: '',
});

// Bulk selection
const selected = ref([]);
const bulkMsg = ref('');
const bulkMsgType = ref('success');

const activeFilterCount = computed(() => {
  let c = 0;
  if (search.value) c++;
  if (activityFilter.value) c++;
  if (statusFilter.value) c++;
  Object.values(filters.value).forEach(v => { if (v) c++; });
  return c;
});

function resetFilters() {
  search.value = '';
  activityFilter.value = null;
  statusFilter.value = null;
  filters.value = { partnerId: '', inviterName: '', email: '', phone: '' };
  loadData();
}

const activityOptions = [
  { title: 'Активен', value: '1' },
  { title: 'Терминирован', value: '3' },
  { title: 'Зарегистрирован-Партнёр', value: '4' },
  { title: 'Исключён', value: '5' },
];

// Column metadata: `always` = never hideable (ФИО / Активность / Действия);
// `default` = shown out of the box; others are opt-in via the «Колонки» menu.
const allColumns = [
  { title: 'ID',               key: 'id',             width: 80, default: true },
  { title: 'ФИО',              key: 'personName',     always: true },
  { title: 'Активность',       key: 'activityName',   width: 130, always: true },
  { title: 'Код',              key: 'participantCode', width: 100, default: true },
  { title: 'Пригласивший',     key: 'inviterName',    default: true },
  { title: 'Клиент',           key: 'isClient',       width: 80, default: true,
    title2: 'Партнёр является клиентом (есть запись в client с тем же email)' },
  { title: 'Доступ',           key: 'platformAccess', width: 80, sortable: false },
  { title: 'Email',            key: 'email' },
  { title: 'Телефон',          key: 'phone',          width: 140 },
  { title: 'Дата рождения',    key: 'birthDate',      width: 130 },
  { title: 'Активен',          key: 'active',         width: 80 },
  { title: 'Куратор',          key: 'curatorName' },
  { title: 'Дата регистрации', key: 'createdAt',      width: 140 },
  { title: 'Смена статуса',    key: 'statusChangeDate', width: 140, default: true },
  { title: '',                 key: 'actions',        sortable: false, width: 60, always: true },
];

// Which columns show in the menu (everything except always-on).
const toggleableColumns = computed(() => allColumns.filter(c => !c.always && c.title));

// Reactive visibility state, persisted per-user in localStorage so their
// column choice survives refreshes.
const COL_STORAGE_KEY = 'admin.partners.visibleColumns';
const columnVisible = ref((() => {
  try {
    const saved = JSON.parse(localStorage.getItem(COL_STORAGE_KEY) || 'null');
    if (saved) return saved;
  } catch {}
  const initial = {};
  for (const c of allColumns) if (!c.always) initial[c.key] = !!c.default;
  return initial;
})());

// Persist on change.
watch(columnVisible, v => localStorage.setItem(COL_STORAGE_KEY, JSON.stringify(v)), { deep: true });

const visibleHeaders = computed(() =>
  allColumns.filter(c => c.always || columnVisible.value[c.key])
);

/**
 * Per-row accent: left border tinted by activity. Keeps the table
 * scannable even in dense views and works with Vuetify hover.
 */
function rowProps({ item }) {
  const activityId = item?.activityId;
  const cls = activityId ? `row-activity-${activityId}` : '';
  return { class: cls };
}

const { debounced: debouncedLoad } = useDebounce(loadData, 400);

function onOptions(opts) {
  page.value = opts.page;
  if (opts.itemsPerPage) perPage.value = opts.itemsPerPage;
  loadData();
}

// === per spec ✅Партнеры.md §1.2 helpers ===
function copyToClipboard(text) {
  if (!text) return;
  navigator.clipboard?.writeText(String(text));
}

function isStatusChangeSoon(item) {
  if (!item.statusChangeDate) return false;
  const days = (new Date(item.statusChangeDate) - new Date()) / 86400000;
  return days >= 0 && days <= 30;
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value };
    if (search.value) params.search = search.value;
    if (activityFilter.value) params.activity = activityFilter.value;
    if (statusFilter.value) params.status = statusFilter.value;
    if (filters.value.partnerId) params.partner_id = filters.value.partnerId;
    if (filters.value.inviterName) params.inviter_name = filters.value.inviterName;
    if (filters.value.email) params.email = filters.value.email;
    if (filters.value.phone) params.phone = filters.value.phone;
    const { data } = await api.get('/admin/partners', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

// Двухшаг «Добавить партнёра» per spec ✅Партнёры §2:
// шаг 1 — поиск по существующим (антидубль), шаг 2 — заполнение профиля
// если совпадений нет.
const addOpen = ref(false);
const addStep = ref(1);
const addSearch = ref('');
const addCandidates = ref([]);
const addSearching = ref(false);
const addSaving = ref(false);
const addError = ref('');
const addForm = ref({
  email: '', phone: '', firstName: '', lastName: '', patronymic: '',
  birthDate: '', activity: 1, inviter: null, participantCode: '',
});
let addSearchTimer;

function openAddPartner() {
  addOpen.value = true;
  addStep.value = 1;
  addSearch.value = '';
  addCandidates.value = [];
  addError.value = '';
  addForm.value = {
    email: '', phone: '', firstName: '', lastName: '', patronymic: '',
    birthDate: '', activity: 1, inviter: null, participantCode: '',
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
      const { data } = await api.get('/admin/partners', { params: { search: q, per_page: 10 } });
      addCandidates.value = data.data || [];
    } catch {}
    addSearching.value = false;
  }, 300);
}

function pickExisting(person) {
  addOpen.value = false;
  // Открываем существующий профиль для редактирования.
  openEdit?.(person);
}

function gotoNewPersonStep() {
  // Заполняем фамилию из последнего поиска, чтобы не вводить дважды.
  const parts = addSearch.value.trim().split(/\s+/);
  if (parts[0]) addForm.value.lastName = parts[0];
  if (parts[1]) addForm.value.firstName = parts[1];
  if (parts[2]) addForm.value.patronymic = parts[2];
  addStep.value = 2;
  addError.value = '';
}

async function saveNewPartner() {
  addSaving.value = true;
  addError.value = '';
  try {
    await api.post('/admin/partners', addForm.value);
    addOpen.value = false;
    await loadData();
  } catch (e) {
    addError.value = e.response?.data?.message || 'Ошибка сохранения';
  }
  addSaving.value = false;
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
  if (id === 1) return 'success';   // Активен
  if (id === 4) return 'info';      // Зарегистрирован
  if (id === 3) return 'error';     // Терминирован — per spec ✅Статусы партнеров §2 col.2
  if (id === 5) return 'error';     // Исключен
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
  const colors = { activate: 'success', terminate: 'warning', exclude: 'error',
    're-register': 'primary', block: 'error', unblock: 'success' };
  if (!await confirm.ask({
    title: `Массовое действие: ${labels[action]}`,
    message: `${ids.length} партнёр(ов) будут переведены в статус "${labels[action]}". Действие применится сразу.`,
    confirmText: labels[action], confirmColor: colors[action] || 'primary',
  })) return;

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
  if (!await confirm.ask({
    title: 'Сменить наставника?',
    message: `${ids.length} партнёр(ов) будут перепривязаны к наставнику с ID ${n}.`,
    confirmText: 'Сменить', confirmColor: 'warning',
  })) return;
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

<style scoped>
/* Row accent: a 3px left border tinted by activity. Keeps wide tables
   scannable without adding a whole colored cell. */
.partners-table :deep(tr.row-activity-1 > td:first-child) { box-shadow: inset 3px 0 0 rgb(var(--v-theme-success)); }
.partners-table :deep(tr.row-activity-3 > td:first-child) { box-shadow: inset 3px 0 0 rgb(var(--v-theme-error)); }
.partners-table :deep(tr.row-activity-4 > td:first-child) { box-shadow: inset 3px 0 0 rgb(var(--v-theme-info)); }
.partners-table :deep(tr.row-activity-5 > td:first-child) { box-shadow: inset 3px 0 0 rgb(var(--v-theme-error)); }
</style>
