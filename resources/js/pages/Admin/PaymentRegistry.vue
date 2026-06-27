<template>
  <div>
    <PageHeader title="Реестр выплат" icon="mdi-cash-multiple">
      <template #actions>
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="payment-registry-cols" />
        <v-btn v-if="canFull('payments')" variant="text" prepend-icon="mdi-refresh" :loading="loading" @click="load">Пересчитать</v-btn>
      </template>
    </PageHeader>

    <!-- Filters -->
    <FilterBar
      :search="filters.search"
      search-placeholder="ФИО партнёра"
      :search-cols="3"
      :show-reset="hasActiveFilters"
      @update:search="v => { filters.search = v ?? ''; debouncedLoad(); }"
      @reset="resetFilters"
    >
      <v-col cols="6" sm="3" md="2">
        <v-text-field v-model="filters.year" label="Год" type="number" variant="outlined"
          density="comfortable" hide-details @change="load" />
      </v-col>
      <v-col cols="6" sm="3" md="2">
        <v-select v-model="filters.month" :items="monthOptions" label="Месяц" variant="outlined"
          density="comfortable" hide-details @update:model-value="load" />
      </v-col>
      <v-col cols="12" sm="4" md="3">
        <v-select v-model="filters.status" :items="statusOptions" label="Статус выплаты"
          variant="outlined" density="comfortable" hide-details clearable
          @update:model-value="load" />
      </v-col>
      <v-col cols="12" sm="4" md="3">
        <v-select v-model="filters.activity" :items="activityOptions" label="Активность"
          item-title="title" item-value="value"
          variant="outlined" density="comfortable" hide-details clearable
          @update:model-value="load" />
      </v-col>
      <v-col cols="12">
        <div class="d-flex ga-3 flex-wrap">
          <v-checkbox v-model="filters.nonZero" density="compact" hide-details
            label="Только ненулевые" @update:model-value="load" />
          <v-checkbox v-model="filters.withDetachment" density="compact" hide-details
            label="ФК с отрывом" @update:model-value="load" />
          <v-checkbox v-model="filters.withOp" density="compact" hide-details
            label="ФК с не выполненным ОП" @update:model-value="load" />
        </div>
      </v-col>
    </FilterBar>

    <!-- Dashboard + selection calculator -->
    <v-card class="mb-3">
      <v-card-text class="pa-3">
        <v-row dense>
          <v-col cols="12" md="4">
            <div class="text-caption text-medium-emphasis">Итого к перечислению (выбрано)</div>
            <div class="text-h4 font-weight-bold" :class="selectedTotal > 0 ? 'text-success' : ''">
              <MoneyCell :value="selectedTotal" currency="₽" :decimals="true" />
            </div>
            <div class="text-caption text-medium-emphasis">{{ selectedIds.length }} строк выбрано</div>
          </v-col>
          <v-col cols="12" md="8">
            <v-row dense>
              <v-col v-for="t in dashboardTiles" :key="t.key" cols="6" sm="4" md="3">
                <div class="text-caption text-medium-emphasis">{{ t.label }}</div>
                <div :class="['text-body-1', 'font-weight-medium', t.cls]">
                  <span v-if="t.isCount">{{ t.value }}</span>
                  <MoneyCell v-else :value="t.value" currency="₽" />
                </div>
              </v-col>
            </v-row>
          </v-col>
        </v-row>
      </v-card-text>
    </v-card>

    <!-- Registry table -->
    <v-card>
      <v-data-table
        v-model="selectedIds"
        :items="items"
        :headers="visibleHeaders"
        :loading="loading"
        density="compact"
        item-value="id"
        show-select
        :items-per-page="50"
        :row-props="rowProps"
      >
        <template #item.personName="{ item }">
          <div class="d-flex align-center ga-2">
            <v-btn v-if="item.paymentsSuspended"
              icon="mdi-cancel" size="x-small" variant="text" color="warning"
              title="Выплаты приостановлены — реквизиты скрыты, выплата заблокирована"
              @click="notifySuspended()" />
            <v-btn v-else :icon="item.verifiedRequisites ? 'mdi-checkbox-marked' : 'mdi-close-octagon'"
              size="x-small" variant="text"
              :color="item.verifiedRequisites ? 'success' : 'error'"
              :title="item.verifiedRequisites ? 'Реквизиты верифицированы — открыть' : 'Реквизиты не верифицированы — выплата невозможна'"
              @click="openRequisitesPopup(item)" />
            <v-btn icon="mdi-file-document-outline" size="x-small" variant="text" color="primary"
              title="Открыть отчёт начислений и выплат"
              :href="`/finance/report?consultant=${item.consultantId}&month=${String(filters.year).padStart(4,'0')}-${String(filters.month).padStart(2,'0')}`"
              target="_blank" />
            <span :class="{ 'text-error font-weight-medium': !item.verifiedRequisites && !item.paymentsSuspended }">{{ item.personName }}</span>
            <v-chip v-if="item.activityName" size="x-small" variant="tonal"
              :color="activityColor(item.activityId)">{{ item.activityName }}</v-chip>
          </div>
        </template>

        <template #item.balance="{ value }">
          <MoneyCell :value="value" currency="₽" :colored="true" />
        </template>
        <template #item.accrued="{ value }"><MoneyCell :value="value" currency="₽" /></template>
        <template #item.other="{ value }"><MoneyCell :value="value" currency="₽" /></template>
        <template #item.pool="{ value }"><MoneyCell :value="value" currency="₽" /></template>
        <template #item.accruedTotal="{ value }"><MoneyCell :value="value" currency="₽" /></template>
        <template #item.totalPayable="{ value }">
          <span class="font-weight-medium"><MoneyCell :value="value" currency="₽" /></span>
        </template>
        <template #item.payed="{ value }"><MoneyCell :value="value" currency="₽" /></template>
        <template #item.remaining="{ value }"><MoneyCell :value="value" currency="₽" :colored="true" /></template>

        <template #item.status="{ value }">
          <v-chip size="x-small" :color="statusColor(value)">{{ value || '—' }}</v-chip>
        </template>

        <template #item.actions="{ item }">
          <v-btn v-if="canFull('payments')" icon="mdi-plus" size="x-small" variant="text" color="primary"
            :disabled="!canAddPayment(item)" :title="paymentBlockedReason(item) || 'Добавить платёж'"
            @click="openPayment(item)" />
          <v-btn icon="mdi-history" size="x-small" variant="text" color="secondary"
            title="История платежей (изменить статус / удалить)"
            @click="openHistory(item)" />
        </template>
      </v-data-table>
    </v-card>

    <!-- Реквизиты для выплат (per spec ✅Реестр выплат §1.4) -->
    <v-dialog v-model="reqDialog" max-width="560">
      <v-card v-if="reqData">
        <v-card-title class="d-flex align-center">
          Реквизиты для выплат
          <v-chip size="x-small" :color="reqData.verified ? 'success' : 'error'" class="ms-2">
            {{ reqData.verified ? 'Верифицировано' : 'Не верифицировано' }}
          </v-chip>
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" size="small" @click="reqDialog = false" />
        </v-card-title>
        <v-card-text>
          <v-table density="compact">
            <tbody>
              <tr v-for="row in reqRows" :key="row.label">
                <td class="text-medium-emphasis" style="width:42%">{{ row.label }}</td>
                <td>
                  <span v-if="row.value">{{ row.value }}</span>
                  <span v-else class="text-medium-emphasis">—</span>
                </td>
                <td style="width:48px" class="text-end">
                  <v-btn v-if="row.value" icon="mdi-content-copy" size="x-small" variant="text"
                    title="Скопировать" @click="copyToClipboard(row.value, row.label)" />
                </td>
              </tr>
            </tbody>
          </v-table>
        </v-card-text>
      </v-card>
    </v-dialog>

    <!-- Add payment dialog -->
    <DialogShell
      v-model="paymentDialog"
      :title="`Платёж: ${paymentTarget?.personName}`"
      :max-width="500"
      :loading="savingPayment"
      confirm-text="Сохранить платёж"
      @confirm="savePayment"
    >
      <div class="text-body-2 mb-3">
        К оплате: <MoneyCell :value="paymentTarget?.totalPayable" currency="₽" />.
        Уже оплачено: <MoneyCell :value="paymentTarget?.payed" currency="₽" />.
      </div>
      <v-text-field v-model.number="paymentForm.amount" label="Сумма выплаты, ₽" type="number"
        step="0.01" variant="outlined" density="comfortable" autofocus />
      <v-textarea v-model="paymentForm.comment" label="Комментарий" variant="outlined"
        density="comfortable" rows="2" />
    </DialogShell>

    <!-- История платежей по строке: изменение статуса / удаление -->
    <v-dialog v-model="historyDialog" max-width="780" scrollable>
      <v-card v-if="historyTarget">
        <v-card-title class="d-flex align-center">
          История платежей: {{ historyTarget.personName }}
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" size="small" @click="historyDialog = false" />
        </v-card-title>
        <v-card-text style="max-height: 70vh">
          <div v-if="historyLoading" class="d-flex justify-center pa-4">
            <v-progress-circular indeterminate color="primary" />
          </div>
          <div v-else-if="!historyPayments.length" class="text-body-2 text-medium-emphasis pa-2">
            Платежей по этой строке нет.
          </div>
          <v-table v-else density="compact">
            <thead>
              <tr>
                <th>Дата</th>
                <th class="text-end">Сумма, ₽</th>
                <th>Статус</th>
                <th>Комментарий</th>
                <th>Кем создано</th>
                <th class="text-end" style="width:120px"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in historyPayments" :key="p.id">
                <td class="text-caption">{{ fmtPayDate(p.paymentDate) }}</td>
                <td class="text-end font-weight-medium">
                  <MoneyCell :value="p.amount" currency="₽" :decimals="true" />
                </td>
                <td>
                  <v-chip size="x-small" :color="payStatusColor(p.status)" class="me-1">
                    {{ p.statusName || '—' }}
                  </v-chip>
                </td>
                <td class="text-body-2">{{ p.comment || '—' }}</td>
                <td class="text-caption text-medium-emphasis">{{ p.createdBy || '—' }}</td>
                <td class="text-end">
                  <v-menu>
                    <template #activator="{ props: a }">
                      <v-btn icon="mdi-dots-vertical" size="x-small" variant="text" v-bind="a" />
                    </template>
                    <v-list density="compact">
                      <v-list-item v-for="opt in historyStatuses" :key="opt.value"
                        :disabled="opt.value === p.status"
                        @click="changePaymentStatus(p, opt.value)">
                        <template #prepend>
                          <v-icon size="18" :color="payStatusColor(opt.value)">mdi-circle-medium</v-icon>
                        </template>
                        <v-list-item-title>Сменить на «{{ opt.title }}»</v-list-item-title>
                      </v-list-item>
                      <v-divider class="my-1" />
                      <v-list-item @click="deletePaymentConfirm(p)">
                        <template #prepend>
                          <v-icon size="18" color="error">mdi-delete</v-icon>
                        </template>
                        <v-list-item-title class="text-error">Удалить платёж</v-list-item-title>
                      </v-list-item>
                    </v-list>
                  </v-menu>
                </td>
              </tr>
            </tbody>
          </v-table>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="historyDialog = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="deleteDialog" max-width="420">
      <v-card>
        <v-card-title>Удалить платёж?</v-card-title>
        <v-card-text>
          Будет удалён платёж на сумму
          <strong><MoneyCell :value="deleteTarget?.amount" currency="₽" /></strong>
          от {{ fmtPayDate(deleteTarget?.paymentDate) }}.
          Баланс пересчитается автоматически. Действие необратимо.
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="deleteDialog = false">Отмена</v-btn>
          <v-btn color="error" :loading="deleting" @click="doDeletePayment">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, FilterBar, DialogShell, MoneyCell, ColumnVisibilityMenu } from '../../components';
import { useDebounce } from '../../composables/useDebounce';
import { useSnackbar } from '../../composables/useSnackbar';
import { useAuthStore } from '../../stores/auth';
import { usePermissions } from '../../composables/usePermissions';

const { showSuccess, showError } = useSnackbar();
const authStore = useAuthStore();
const { canFull } = usePermissions();

// Override-роль (Богданова) — может вносить выплаты в обход блокировок
// (per spec ✅Реестр выплат §2 «Шаг 4»). Роль 'calculations' маркируется
// в Users.vue как «Расчёты (Богданова)».
const canOverridePaymentBlock = computed(() => {
  const roles = (authStore.user?.role || '').split(',').map(r => r.trim());
  return roles.includes('calculations') || roles.includes('admin');
});

const now = new Date();
const filters = reactive({
  year: now.getFullYear(),
  month: now.getMonth() + 1,
  search: '',
  status: null,
  activity: null,
  nonZero: true,
  withDetachment: false,
  withOp: false,
});

const activityOptions = ref([]);

// activityId: 1 Активный, 2 Зарегистрирован, 3 Терминирован, 4 ?, 5 Исключён.
// Спец §2 шаг 4: блокируем выплату для Зарегистрирован/Терминирован/Исключён.
const ACTIVE_ACTIVITY_IDS = new Set([1]);

function activityColor(id) {
  if (id === 1) return 'success';   // Активен
  if (id === 4) return 'info';      // Зарегистрирован
  if (id === 3) return 'error';     // Терминирован
  if (id === 5) return 'error';     // Исключен
  return 'grey';
}

function canAddPayment(item) {
  // Suspension is a hard block for everyone — to pay, the flag must be removed first.
  if (item.paymentsSuspended) return false;
  if (canOverridePaymentBlock.value) return true;
  if (!item.verifiedRequisites) return false;
  if (!ACTIVE_ACTIVITY_IDS.has(item.activityId)) return false;
  return true;
}

function paymentBlockedReason(item) {
  if (item.paymentsSuspended) return 'Выплаты приостановлены';
  if (canOverridePaymentBlock.value) return null;
  if (!item.verifiedRequisites) return 'Реквизиты не верифицированы';
  if (!ACTIVE_ACTIVITY_IDS.has(item.activityId)) {
    return `Статус «${item.activityName || '—'}» — выплата невозможна`;
  }
  return null;
}

const reqDialog = ref(false);
const reqData = ref(null);
const reqRows = computed(() => {
  if (!reqData.value) return [];
  return [
    { label: 'Расчётный счёт', value: reqData.value.accountNumber },
    { label: 'Корр. счёт', value: reqData.value.correspondentAccount },
    { label: 'БИК', value: reqData.value.bankBik },
    { label: 'Название банка', value: reqData.value.bankName },
    { label: 'Наименование ИП', value: reqData.value.individualEntrepreneur },
    { label: 'ИНН', value: reqData.value.inn },
    { label: 'ОГРНИП', value: reqData.value.ogrn },
    { label: 'Юр. адрес', value: reqData.value.address },
  ];
});

function notifySuspended() {
  showError('Выплаты по партнёру приостановлены — реквизиты скрыты');
}

async function openRequisitesPopup(item) {
  if (item.paymentsSuspended) { notifySuspended(); return; }
  reqData.value = null;
  reqDialog.value = true;
  try {
    const { data } = await api.get(`/admin/payment-registry/${item.id}/requisites`);
    reqData.value = data;
  } catch (e) {
    showError(e.response?.data?.message || 'Реквизиты не найдены');
    reqDialog.value = false;
  }
}

function copyToClipboard(text, label) {
  if (!navigator?.clipboard) return;
  navigator.clipboard.writeText(String(text)).then(
    () => showSuccess(`${label} скопировано`),
    () => showError('Не удалось скопировать'),
  );
}

const loading = ref(false);
const items = ref([]);
const totals = ref({});
const selectedIds = ref([]);

const paymentDialog = ref(false);
const paymentTarget = ref(null);
const paymentForm = reactive({ amount: 0, comment: '' });
const savingPayment = ref(false);

// История платежей: показ + смена статуса / удаление.
const historyDialog = ref(false);
const historyTarget = ref(null);
const historyLoading = ref(false);
const historyPayments = ref([]);
const historyStatuses = ref([
  { value: 1, title: 'Платёж отправлен' },
  { value: 2, title: 'Оплачено' },
  { value: 3, title: 'Отказ' },
]);

const deleteDialog = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

function payStatusColor(s) {
  if (s === 2) return 'success';
  if (s === 3) return 'error';
  return 'warning';
}

function fmtPayDate(d) {
  if (!d) return '—';
  try {
    const dt = new Date(d);
    return dt.toLocaleDateString('ru-RU') + ' ' +
      dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  } catch { return d; }
}

async function openHistory(item) {
  historyTarget.value = item;
  historyDialog.value = true;
  historyLoading.value = true;
  historyPayments.value = [];
  try {
    const { data } = await api.get(`/admin/payment-registry/${item.id}/payments`);
    historyPayments.value = data.items || [];
    if (Array.isArray(data.statuses) && data.statuses.length) {
      historyStatuses.value = data.statuses;
    }
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось загрузить историю');
  }
  historyLoading.value = false;
}

async function changePaymentStatus(payment, newStatus) {
  try {
    await api.patch(`/admin/payment-registry/payments/${payment.id}`, { status: newStatus });
    showSuccess('Статус обновлён');
    // Перезагружаем список и реестр (баланс/итоги пересчитываются на бэке).
    if (historyTarget.value) await openHistory(historyTarget.value);
    await load();
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось сменить статус');
  }
}

function deletePaymentConfirm(payment) {
  deleteTarget.value = payment;
  deleteDialog.value = true;
}

async function doDeletePayment() {
  if (!deleteTarget.value) return;
  deleting.value = true;
  try {
    await api.delete(`/admin/payment-registry/payments/${deleteTarget.value.id}`);
    showSuccess('Платёж удалён');
    deleteDialog.value = false;
    if (historyTarget.value) await openHistory(historyTarget.value);
    await load();
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось удалить платёж');
  }
  deleting.value = false;
}

const monthOptions = Array.from({ length: 12 }, (_, i) => ({
  title: new Date(2000, i, 1).toLocaleDateString('ru-RU', { month: 'long' }),
  value: i + 1,
}));

const statusOptions = [
  'В обработке', 'Частично оплачено', 'Оплачено полностью', 'Оплачено', 'Отказ', 'Возврат',
];

const headers = [
  { title: 'Партнёр', key: 'personName', sortable: true },
  { title: 'Сальдо', key: 'balance', align: 'end', width: 110 },
  { title: 'Начислено', key: 'accrued', align: 'end', width: 120 },
  { title: 'Прочее', key: 'other', align: 'end', width: 100 },
  { title: 'Пул', key: 'pool', align: 'end', width: 100 },
  { title: 'Итого начислено', key: 'accruedTotal', align: 'end', width: 140 },
  { title: 'К оплате', key: 'totalPayable', align: 'end', width: 120 },
  { title: 'Оплачено', key: 'payed', align: 'end', width: 110 },
  { title: 'Остаток', key: 'remaining', align: 'end', width: 110 },
  { title: 'Статус', key: 'status', width: 170 },
  { title: '', key: 'actions', sortable: false, width: 60 },
];

const columnVisible = ref({});
const visibleHeaders = computed(() => headers.filter(h => columnVisible.value[h.key] !== false));

const dashboardTiles = computed(() => [
  { key: 'rows', label: 'Кол-во реестров', value: totals.value.rows ?? 0, cls: '', isCount: true },
  { key: 'balance', label: 'Сальдо прошлых', value: totals.value.balance ?? 0, cls: '' },
  { key: 'beforeGap', label: 'Начислено до отрыва', value: totals.value.accruedBeforeGap ?? 0, cls: '' },
  { key: 'accrued', label: 'Начислено за транзакции', value: totals.value.accruedTransactional ?? 0, cls: '' },
  { key: 'other', label: 'Прочее', value: totals.value.accruedNonTransactional ?? 0, cls: '' },
  { key: 'pool', label: 'Пул', value: totals.value.accruedPool ?? 0, cls: '' },
  { key: 'accruedTotal', label: 'Итого начислено', value: totals.value.accruedTotal ?? 0, cls: '' },
  { key: 'payable', label: 'Итого к оплате', value: totals.value.totalPayable ?? 0, cls: 'text-primary' },
  { key: 'paid', label: 'Оплачено', value: totals.value.payed ?? 0, cls: 'text-success' },
]);

const selectedTotal = computed(() => {
  const ids = new Set(selectedIds.value);
  return items.value.reduce((s, r) => ids.has(r.id) ? s + (r.totalPayable - r.payed) : s, 0);
});

const hasActiveFilters = computed(() =>
  !!filters.search || !!filters.status || !!filters.activity || filters.withDetachment || filters.withOp
);

function resetFilters() {
  filters.search = '';
  filters.status = null;
  filters.activity = null;
  filters.nonZero = true;
  filters.withDetachment = false;
  filters.withOp = false;
  load();
}

function statusColor(s) {
  if (!s) return 'grey';
  const l = String(s).toLowerCase();
  if (l.includes('полност') || l.includes('оплачено')) return 'success';
  if (l.includes('частич')) return 'warning';
  if (l.includes('отказ') || l.includes('возврат')) return 'error';
  return 'info';
}

// Подсветка всей строки цветом значка статуса реквизитов:
//   приостановлено → warning, не верифицировано → error, верифицировано → success.
function rowProps({ item }) {
  if (item.paymentsSuspended) return { class: 'pr-row--suspended' };
  return { class: item.verifiedRequisites ? 'pr-row--verified' : 'pr-row--unverified' };
}

async function load() {
  loading.value = true;
  try {
    // Laravel boolean-валидатор не принимает строки 'true'/'false' из
    // query string — приводим к 1/0 явно.
    const params = {
      year: filters.year,
      month: filters.month,
      search: filters.search || undefined,
      status: filters.status || undefined,
      activity: filters.activity || undefined,
      nonZero: filters.nonZero ? 1 : 0,
      withDetachment: filters.withDetachment ? 1 : 0,
      withOp: filters.withOp ? 1 : 0,
    };
    const { data } = await api.get('/admin/payment-registry', { params });
    items.value = data.items || [];
    totals.value = data.totals || {};
    if (data.activityOptions?.length) activityOptions.value = data.activityOptions;
    selectedIds.value = [];
  } catch (e) { showError(e.response?.data?.message || 'Не удалось загрузить реестр'); }
  loading.value = false;
}

const { debounced: debouncedLoad } = useDebounce(load, 400);

function openPayment(item) {
  paymentTarget.value = item;
  paymentForm.amount = Math.max(0, item.remaining ?? (item.totalPayable - item.payed));
  paymentForm.comment = '';
  paymentDialog.value = true;
}

async function savePayment() {
  if (!paymentTarget.value) return;
  savingPayment.value = true;
  try {
    await api.post(`/admin/payment-registry/${paymentTarget.value.id}/payments`, {
      amount: paymentForm.amount,
      comment: paymentForm.comment,
    });
    showSuccess('Платёж зафиксирован');
    paymentDialog.value = false;
    await load();
  } catch (e) { showError(e.response?.data?.message || 'Не удалось сохранить платёж'); }
  savingPayment.value = false;
}

onMounted(load);
</script>

<style scoped>
/* Подсветка всей строки цветом значка статуса реквизитов. Тон мягкий, чтобы
   текст и цифры оставались читаемыми; hover чуть усиливает. */
.v-data-table :deep(tr.pr-row--unverified > td) { background: rgba(var(--v-theme-error), 0.09) !important; }
.v-data-table :deep(tr.pr-row--suspended > td)  { background: rgba(var(--v-theme-warning), 0.10) !important; }
.v-data-table :deep(tr.pr-row--verified > td)   { background: rgba(var(--v-theme-success), 0.06) !important; }
.v-data-table :deep(tr.pr-row--unverified:hover > td) { background: rgba(var(--v-theme-error), 0.14) !important; }
.v-data-table :deep(tr.pr-row--suspended:hover > td)  { background: rgba(var(--v-theme-warning), 0.16) !important; }
.v-data-table :deep(tr.pr-row--verified:hover > td)   { background: rgba(var(--v-theme-success), 0.10) !important; }
</style>
