<template>
  <div>
    <PageHeader title="Отчёт начислений и выплат" icon="mdi-bank">
      <template #actions>
        <div class="d-flex align-center ga-2">
          <v-btn variant="flat" color="primary" size="small" prepend-icon="mdi-download"
            :loading="exporting" :disabled="locked" @click="downloadXlsx">
            Скачать XLSX
          </v-btn>
          <MonthPicker v-model="month" @update:model-value="loadData" />
        </div>
      </template>
    </PageHeader>

    <!-- Per spec ✅Доступность отчётов: пока админ не открыл период,
         партнёр видит заглушку вместо детализации. -->
    <v-alert v-if="locked" type="info" variant="tonal" class="mb-4" icon="mdi-lock-clock">
      <div class="text-subtitle-1 font-weight-bold mb-1">Отчёт ещё не опубликован</div>
      <div>{{ lockedMessage }}. Дождитесь окончания сверки за этот месяц — администратор откроет данные после внесения всех транзакций.</div>
    </v-alert>

    <template v-if="!locked">
    <!-- Row 1: Qualification + Volumes per spec ✅Отчет начислений и выплат §1
         Удалены: «Плашка курсов валют» и «Уровень расчета комиссионных».
         «ГП» переименован в «ОП по ГП». -->
    <v-row class="mb-4">
      <v-col cols="12" sm="6" md="4">
        <v-card class="pa-4 h-100">
          <div class="text-body-2 text-medium-emphasis">Текущая квалификация</div>
          <div class="d-flex align-center ga-2 mt-1 flex-wrap">
            <v-chip size="small" color="primary" class="text-truncate">
              {{ summary.qualificationCurrent?.title || '—' }}
            </v-chip>
            <v-chip v-if="summary.qualificationCurrent?.percent != null" size="x-small" variant="tonal">
              {{ summary.qualificationCurrent.percent }}%
            </v-chip>
            <v-icon v-if="qualTrend === 'up'" size="18" color="success" title="Рост">mdi-trending-up</v-icon>
            <v-icon v-else-if="qualTrend === 'down'" size="18" color="error" title="Снижение">mdi-trending-down</v-icon>
            <v-icon v-else size="18" color="grey" title="Без изменений">mdi-trending-neutral</v-icon>
          </div>
          <div v-if="summary.qualificationPrev?.title" class="text-caption text-medium-emphasis mt-1">
            Прошлый месяц: {{ summary.qualificationPrev.title }}
          </div>
        </v-card>
      </v-col>
      <v-col cols="12" sm="6" md="2">
        <v-card class="pa-4 h-100">
          <div class="text-body-2 text-medium-emphasis">ЛП</div>
          <div class="text-h6 font-weight-bold text-success mt-1" style="white-space:nowrap">{{ fmt(summary.volumes?.lp) }}</div>
        </v-card>
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <v-card class="pa-4 h-100">
          <div class="text-body-2 text-medium-emphasis">ОП по ГП</div>
          <div class="text-h6 font-weight-bold text-info mt-1" style="white-space:nowrap">{{ fmt(summary.volumes?.gp) }}</div>
        </v-card>
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <v-card class="pa-4 h-100">
          <div class="text-body-2 text-medium-emphasis">НГП</div>
          <div class="text-h6 font-weight-bold text-warning mt-1" style="white-space:nowrap">{{ fmt(summary.volumes?.ngp) }}</div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Row 2: Sales totals -->
    <v-row class="mb-4">
      <v-col cols="12" sm="6" md="3">
        <v-card class="pa-4 h-100">
          <div class="d-flex align-center ga-1 mb-2">
            <v-icon size="18" color="green">mdi-account</v-icon>
            <span class="text-body-2 text-medium-emphasis">Личные продажи</span>
          </div>
          <div class="text-body-2"><span class="font-weight-medium">Баллы:</span> {{ fmt(summary.personalSales?.points) }}</div>
          <div class="text-body-2"><span class="font-weight-medium">Бонус в баллах ЛП:</span> {{ fmt(summary.personalSales?.bonus) }}</div>
          <div class="text-body-2"><span class="font-weight-medium">Бонус, ₽:</span> {{ fmt2(summary.personalSales?.bonusRub) }}</div>
        </v-card>
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <v-card class="pa-4 h-100">
          <div class="d-flex align-center ga-1 mb-2">
            <v-icon size="18" color="blue">mdi-account-group</v-icon>
            <span class="text-body-2 text-medium-emphasis">Групповые продажи</span>
          </div>
          <div class="text-body-2"><span class="font-weight-medium">Баллы (ОП по ГП):</span> {{ fmt(summary.groupSales?.points) }}</div>
          <div class="text-body-2"><span class="font-weight-medium">Бонус баллы по разнице %:</span> {{ fmt(summary.groupSales?.bonus) }}</div>
          <div class="text-body-2"><span class="font-weight-medium">Бонус, ₽:</span> {{ fmt2(summary.groupSales?.bonusRub) }}</div>
        </v-card>
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <v-card class="pa-4 h-100">
          <div class="d-flex align-center ga-1 mb-2">
            <v-icon size="18" color="orange">mdi-sigma</v-icon>
            <span class="text-body-2 text-medium-emphasis">Итого продажи</span>
          </div>
          <div class="text-body-2"><span class="font-weight-medium">Бонус:</span> {{ fmt(summary.totalSales?.bonus) }}</div>
          <div class="text-body-2"><span class="font-weight-medium">Бонус (руб):</span> {{ fmt2(summary.totalSales?.bonusRub) }}</div>
          <div class="text-body-2"><span class="font-weight-medium">Пул (руб):</span> {{ fmt2(summary.totalSales?.poolRub) }}</div>
          <div class="text-body-2 font-weight-bold"><span class="font-weight-medium">Всего (руб):</span> {{ fmt2(summary.totalSales?.totalRub) }}</div>
        </v-card>
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <v-card class="pa-4 h-100">
          <div class="d-flex align-center ga-1 mb-2">
            <v-icon size="18" color="purple">mdi-calendar-check</v-icon>
            <span class="text-body-2 text-medium-emphasis">Итог за месяц</span>
          </div>
          <div class="text-body-2"><span class="font-weight-medium">Баланс на начало:</span> {{ fmt2(summary.monthEnd?.balanceStart) }}</div>
          <div class="text-body-2"><span class="font-weight-medium">Прочие (баллы):</span> {{ fmt(summary.monthEnd?.otherAccrualsPoints) }}</div>
          <div class="text-body-2"><span class="font-weight-medium">Прочие (руб):</span> {{ fmt2(summary.monthEnd?.otherAccrualsRub) }}</div>
          <div class="text-body-2"><span class="font-weight-medium">Итого начислено:</span> {{ fmt2(summary.monthEnd?.totalAccrued) }}</div>
          <div class="text-body-2 font-weight-bold"><span class="font-weight-medium">К выплате:</span> {{ fmt2(summary.monthEnd?.totalPayable) }}</div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Breakaway card -->
    <v-card v-if="summary.breakaway" class="mb-4 pa-4" color="amber-lighten-5" variant="tonal">
      <div class="d-flex align-center ga-2 mb-2">
        <v-icon color="amber-darken-2">mdi-alert-decagram</v-icon>
        <span class="font-weight-bold text-amber-darken-3">Отрыв</span>
      </div>
      <v-row>
        <v-col cols="6" md="3">
          <div class="text-body-2 text-medium-emphasis">Разница</div>
          <div class="font-weight-medium">{{ fmt(summary.breakaway.gapValue) }} ({{ summary.breakaway.gapValuePercentage ?? 0 }}%)</div>
        </v-col>
        <v-col cols="6" md="3">
          <div class="text-body-2 text-medium-emphasis">Ветка</div>
          <div class="font-weight-medium">{{ summary.breakaway.branchWithGapName || '—' }}</div>
        </v-col>
      </v-row>
    </v-card>

    <!-- Expandable Tables -->
    <v-expansion-panels variant="accordion" class="mb-4">
      <!-- Personal Sales -->
      <v-expansion-panel>
        <v-expansion-panel-title>
          <v-icon class="mr-2" size="20">mdi-account</v-icon>
          Личные продажи
          <v-chip v-if="tables.personalSales?.length" size="x-small" class="ml-2" color="primary">{{ tables.personalSales.length }}</v-chip>
        </v-expansion-panel-title>
        <v-expansion-panel-text>
          <div style="overflow-x: auto">
          <v-data-table :items="tables.personalSales || []" :headers="personalSalesHeaders" density="compact"
            hover no-data-text="Нет данных">
            <template #item.points="{ value }">{{ fmt(value) }}</template>
            <template #item.bonus="{ value }">{{ fmt(value) }}</template>
            <template #item.bonusRub="{ value }">{{ fmt2(value) }}</template>
            <template #item.clientPaymentsRub="{ value }">{{ fmt2(value) }}</template>
          </v-data-table>
          </div>
        </v-expansion-panel-text>
      </v-expansion-panel>

      <!-- Group Sales -->
      <v-expansion-panel>
        <v-expansion-panel-title>
          <v-icon class="mr-2" size="20">mdi-account-group</v-icon>
          Групповые продажи
          <v-chip v-if="tables.groupSales?.length" size="x-small" class="ml-2" color="primary">{{ tables.groupSales.length }}</v-chip>
        </v-expansion-panel-title>
        <v-expansion-panel-text>
          <div style="overflow-x: auto">
          <v-data-table :items="tables.groupSales || []" :headers="groupSalesHeaders" density="compact"
            hover no-data-text="Нет данных">
            <template #item.points="{ value }">{{ fmt(value) }}</template>
            <template #item.bonus="{ value }">{{ fmt(value) }}</template>
            <template #item.bonusRub="{ value }">{{ fmt2(value) }}</template>
            <template #item.clientPaymentsRub="{ value }">{{ fmt2(value) }}</template>
          </v-data-table>
          </div>
        </v-expansion-panel-text>
      </v-expansion-panel>

      <!-- Other Accruals -->
      <v-expansion-panel>
        <v-expansion-panel-title>
          <v-icon class="mr-2" size="20">mdi-plus-circle-outline</v-icon>
          Прочие начисления
          <v-chip v-if="tables.otherAccruals?.length" size="x-small" class="ml-2" color="primary">{{ tables.otherAccruals.length }}</v-chip>
        </v-expansion-panel-title>
        <v-expansion-panel-text>
          <div style="overflow-x: auto">
          <v-data-table :items="tables.otherAccruals || []" :headers="otherAccrualsHeaders" density="compact"
            hover no-data-text="Нет данных">
            <template #item.points="{ value }">{{ fmt(value) }}</template>
            <template #item.amountRub="{ value }">{{ fmt2(value) }}</template>
          </v-data-table>
          </div>
        </v-expansion-panel-text>
      </v-expansion-panel>

      <!-- Breakaway Detail -->
      <v-expansion-panel v-if="tables.breakaway">
        <v-expansion-panel-title>
          <v-icon class="mr-2" size="20">mdi-alert-decagram</v-icon>
          Детали отрыва
        </v-expansion-panel-title>
        <v-expansion-panel-text>
          <v-table density="compact">
            <tbody>
              <tr><td class="text-medium-emphasis">Разница (gap)</td><td>{{ tables.breakaway.gap ?? '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">Значение</td><td>{{ fmt(tables.breakaway.gapValue) }}</td></tr>
              <tr><td class="text-medium-emphasis">Процент</td><td>{{ tables.breakaway.gapValuePercentage ?? 0 }}%</td></tr>
              <tr><td class="text-medium-emphasis">Ветка</td><td>{{ tables.breakaway.branchWithGapName || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">ГП ветки</td><td>{{ fmt(tables.breakaway.branchWithGapGroupVolume) }}</td></tr>
            </tbody>
          </v-table>
        </v-expansion-panel-text>
      </v-expansion-panel>

      <!-- Payments -->
      <v-expansion-panel>
        <v-expansion-panel-title>
          <v-icon class="mr-2" size="20">mdi-cash-multiple</v-icon>
          Выплаты
          <v-chip v-if="tables.payments?.length" size="x-small" class="ml-2" color="primary">{{ tables.payments.length }}</v-chip>
        </v-expansion-panel-title>
        <v-expansion-panel-text>
          <div style="overflow-x: auto">
          <v-data-table :items="tables.payments || []" :headers="paymentsHeaders" density="compact"
            hover no-data-text="Нет данных">
            <template #item.amount="{ value }">{{ fmt2(value) }}</template>
            <template #item.status="{ value }">
              <v-chip size="x-small" :color="value === 'paid' ? 'success' : 'warning'">
                {{ value === 'paid' ? 'Выплачено' : value }}
              </v-chip>
            </template>
          </v-data-table>
          </div>
        </v-expansion-panel-text>
      </v-expansion-panel>
    </v-expansion-panels>
    </template>

    <v-progress-linear v-if="loading" indeterminate color="primary"
      style="position:fixed;top:0;left:0;right:0;z-index:2000" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import MonthPicker from '../../components/MonthPicker.vue';
import { exportFinanceReport } from '../../composables/useExport';
import PageHeader from '../../components/PageHeader.vue';
import { fmt, fmt2 } from '../../composables/useDesign';

const loading = ref(true);
const exporting = ref(false);
const month = ref(new Date().toISOString().slice(0, 7));

async function downloadXlsx() {
  exporting.value = true;
  try {
    await exportFinanceReport(data.value, month.value);
  } catch {}
  exporting.value = false;
}
const data = ref({});

const fmtRate = (n) => Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 4 });

const summary = computed(() => data.value.summary || {});
const qualTrend = computed(() => {
  const cur = summary.value.qualificationCurrent?.percent ?? 0;
  const prev = summary.value.qualificationPrev?.percent ?? 0;
  if (cur > prev) return 'up';
  if (cur < prev) return 'down';
  return 'flat';
});
const tables = computed(() => data.value.tables || {});

const personalSalesHeaders = [
  { title: 'Контракт', key: 'contractNumber' },
  { title: 'Клиент', key: 'clientName' },
  { title: 'Продукт', key: 'productName' },
  { title: 'Баллы', key: 'points', align: 'end', width: 100 },
  { title: 'Бонус', key: 'bonus', align: 'end', width: 100 },
  { title: 'Бонус (руб)', key: 'bonusRub', align: 'end', width: 120 },
  { title: 'Клиентские платежи (руб)', key: 'clientPaymentsRub', align: 'end', width: 180 },
];

const groupSalesHeaders = [
  { title: 'Партнёр', key: 'partnerName' },
  { title: 'Контракт', key: 'contractNumber' },
  { title: 'Клиент', key: 'clientName' },
  { title: 'Продукт', key: 'productName' },
  { title: 'Баллы', key: 'points', align: 'end', width: 100 },
  { title: 'Бонус', key: 'bonus', align: 'end', width: 100 },
  { title: 'Бонус (руб)', key: 'bonusRub', align: 'end', width: 120 },
  { title: 'Клиентские платежи (руб)', key: 'clientPaymentsRub', align: 'end', width: 180 },
];

const otherAccrualsHeaders = [
  { title: 'Описание', key: 'description' },
  { title: 'Тип', key: 'type' },
  { title: 'Баллы', key: 'points', align: 'end', width: 100 },
  { title: 'Сумма (руб)', key: 'amountRub', align: 'end', width: 140 },
];

const paymentsHeaders = [
  { title: 'Дата', key: 'date', width: 120 },
  { title: 'Описание', key: 'description' },
  { title: 'Сумма', key: 'amount', align: 'end', width: 140 },
  { title: 'Валюта', key: 'currency', width: 80 },
  { title: 'Статус', key: 'status', width: 120 },
];

const locked = ref(false);
const lockedMessage = ref('');

async function loadData() {
  loading.value = true;
  locked.value = false;
  lockedMessage.value = '';
  try {
    const { data: d } = await api.get('/finance/report', { params: { month: month.value } });
    data.value = d;
  } catch (e) {
    if (e?.response?.status === 423) {
      locked.value = true;
      lockedMessage.value = e.response.data?.message || 'Отчёт за этот период ещё не опубликован';
      data.value = {};
    } else {
      data.value = {};
    }
  }
  loading.value = false;
}

onMounted(loadData);
</script>
