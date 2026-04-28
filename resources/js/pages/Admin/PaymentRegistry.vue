<template>
  <div>
    <PageHeader title="Реестр выплат" icon="mdi-cash-multiple">
      <template #actions>
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="payment-registry-cols" />
        <v-btn variant="text" prepend-icon="mdi-refresh" :loading="loading" @click="load">Пересчитать</v-btn>
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
      >
        <template #item.personName="{ item }">
          <div class="d-flex align-center ga-2">
            <v-btn :icon="item.verifiedRequisites ? 'mdi-checkbox-marked' : 'mdi-close-octagon'"
              size="x-small" variant="text"
              :color="item.verifiedRequisites ? 'success' : 'error'"
              :title="item.verifiedRequisites ? 'Реквизиты верифицированы — открыть' : 'Реквизиты не верифицированы — выплата невозможна'"
              @click="openRequisitesPopup(item)" />
            <v-btn icon="mdi-file-document-outline" size="x-small" variant="text" color="primary"
              title="Открыть отчёт начислений и выплат"
              :href="`/finance/report?consultant=${item.consultantId}&year=${filters.year}&month=${filters.month}`"
              target="_blank" />
            <span>{{ item.personName }}</span>
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
          <v-btn icon="mdi-plus" size="x-small" variant="text" color="primary"
            :disabled="!canAddPayment(item)" :title="paymentBlockedReason(item) || 'Добавить платёж'"
            @click="openPayment(item)" />
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
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, FilterBar, DialogShell, MoneyCell, ColumnVisibilityMenu } from '../../components';
import { useDebounce } from '../../composables/useDebounce';
import { useSnackbar } from '../../composables/useSnackbar';
import { useAuthStore } from '../../stores/auth';

const { showSuccess, showError } = useSnackbar();
const authStore = useAuthStore();

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
  if (id === 1) return 'success';
  if (id === 3) return 'error';
  if (id === 5) return 'error';
  return 'warning';
}

function canAddPayment(item) {
  if (canOverridePaymentBlock.value) return true;
  if (!item.verifiedRequisites) return false;
  if (!ACTIVE_ACTIVITY_IDS.has(item.activityId)) return false;
  return true;
}

function paymentBlockedReason(item) {
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

async function openRequisitesPopup(item) {
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

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/payment-registry', { params: { ...filters } });
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
