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
          <div class="text-body-2"><span class="font-weight-medium">Прочие начисления:</span> {{ fmt2(summary.monthEnd?.otherAccrualsRub) }} ₽</div>
          <div v-if="summary.monthEnd?.otherAccrualsPoints" class="text-body-2 text-medium-emphasis text-caption">
            <span>в т.ч. в баллах:</span> {{ fmt(summary.monthEnd?.otherAccrualsPoints) }}
          </div>
          <div class="text-body-2"><span class="font-weight-medium">Итого начислено:</span> {{ fmt2(summary.monthEnd?.totalAccrued) }}</div>
          <div class="text-body-2 font-weight-bold"><span class="font-weight-medium">К выплате:</span> {{ fmt2(summary.monthEnd?.totalPayable) }}</div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Breakaway card — всегда показывает топ-ветку и её % от моего ГП.
         Цвет/иконка отражают пройденные пороги: 70% (gpHeld) и 90% (poolBlocked). -->
    <v-card v-if="summary.breakaway" class="mb-4 pa-4"
      :color="summary.breakaway.poolBlocked ? 'amber-lighten-5'
            : summary.breakaway.gpHeld ? 'orange-lighten-5'
            : 'green-lighten-5'" variant="tonal">
      <div class="d-flex align-center ga-2 mb-2">
        <v-icon :color="summary.breakaway.poolBlocked ? 'amber-darken-2'
                       : summary.breakaway.gpHeld ? 'orange-darken-2'
                       : 'success'">
          {{ summary.breakaway.poolBlocked ? 'mdi-alert-decagram'
           : summary.breakaway.gpHeld ? 'mdi-alert-circle-outline'
           : 'mdi-check-decagram' }}
        </v-icon>
        <span class="font-weight-bold">
          {{ summary.breakaway.poolBlocked ? 'Отрыв ≥ 90% — пул не выплачивается'
           : summary.breakaway.gpHeld ? 'Отрыв ≥ 70% — ветка не учитывается в ГП'
           : 'Отрыва нет' }}
        </span>
      </div>
      <v-row>
        <v-col cols="6" md="3">
          <div class="text-body-2 text-medium-emphasis">Топ ветка</div>
          <div class="font-weight-medium">{{ summary.breakaway.partnerName || '—' }}</div>
        </v-col>
        <v-col cols="6" md="3">
          <div class="text-body-2 text-medium-emphasis">ГП ветки</div>
          <div class="font-weight-medium">{{ fmt(summary.breakaway.groupVolume) }}</div>
        </v-col>
        <v-col cols="6" md="3">
          <div class="text-body-2 text-medium-emphasis">Доля от моего ГП</div>
          <div class="font-weight-medium" :class="summary.breakaway.poolBlocked ? 'text-amber-darken-3'
                                                : summary.breakaway.gpHeld ? 'text-orange-darken-2' : 'text-success'">
            {{ summary.breakaway.gapPercentage ?? 0 }}%
          </div>
        </v-col>
        <v-col cols="6" md="3">
          <div class="text-body-2 text-medium-emphasis">Превышение</div>
          <div class="font-weight-medium">{{ fmt(summary.breakaway.gapValue) }}</div>
        </v-col>
      </v-row>
      <!-- Пороговая шкала: 70% / 90% -->
      <div class="mt-3">
        <v-progress-linear
          :model-value="Math.min(summary.breakaway.gapPercentage || 0, 100)"
          height="8" rounded
          :color="summary.breakaway.poolBlocked ? 'amber-darken-2'
                : summary.breakaway.gpHeld ? 'orange-darken-2'
                : 'success'" />
        <div class="d-flex justify-space-between text-caption text-medium-emphasis mt-1">
          <span>0%</span>
          <span>70% — удержание ГП</span>
          <span>90% — блокировка пула</span>
          <span>100%</span>
        </div>
      </div>
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
            <template #item.date="{ value }">{{ fmtShortDate(value) }}</template>
            <template #item.paymentAmount="{ value }">{{ fmt2(value) }}</template>
            <template #item.amountNoVat="{ value }">{{ fmt2(value) }}</template>
            <template #item.personalVolume="{ value }">{{ fmt(value) }}</template>
            <template #item.bonus="{ value }">{{ fmt(value) }}</template>
            <template #item.bonusRub="{ value }">{{ fmt2(value) }}</template>
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
            <template #item.date="{ value }">{{ fmtShortDate(value) }}</template>
            <template #item.paymentAmount="{ value }">{{ fmt2(value) }}</template>
            <template #item.amountNoVat="{ value }">{{ fmt2(value) }}</template>
            <template #item.personalVolume="{ value }">{{ fmt(value) }}</template>
            <template #item.bonus="{ value }">{{ fmt(value) }}</template>
            <template #item.bonusRub="{ value }">{{ fmt2(value) }}</template>
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
            <template #item.date="{ value }">{{ fmtShortDate(value) }}</template>
            <template #item.amount="{ value }">{{ fmt(value) }}</template>
            <template #item.amountRUB="{ value }">{{ fmt2(value) }}</template>
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
            <template #item.date="{ value }">{{ fmtShortDate(value) }}</template>
            <template #item.amount="{ value }">{{ fmt2(value) }}</template>
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
import { fmt, fmt2, fmtDate as fmtShortDate } from '../../composables/useDesign';

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

// Per spec ✅Отчет начислений и выплат партнера §4.1:
// Дата, Контракт, Клиент, Продукт, Программа, Сумма оплаты,
// Параметр (Свойство продукта), Сумма без НДС, ЛП, Бонус, Бонус ₽, Комментарий.
const personalSalesHeaders = [
  { title: 'Дата', key: 'date', width: 110 },
  { title: 'Контракт', key: 'contractNumber', width: 130 },
  { title: 'Клиент', key: 'clientName' },
  { title: 'Продукт', key: 'productName' },
  { title: 'Программа', key: 'programName' },
  { title: 'Сумма оплаты', key: 'paymentAmount', align: 'end', width: 130 },
  { title: 'Параметр', key: 'parameter', width: 110 },
  { title: 'Сумма без НДС', key: 'amountNoVat', align: 'end', width: 130 },
  { title: 'ЛП', key: 'personalVolume', align: 'end', width: 90 },
  { title: 'Бонус', key: 'bonus', align: 'end', width: 90 },
  { title: 'Бонус, ₽', key: 'bonusRub', align: 'end', width: 110 },
  { title: 'Комментарий', key: 'comment' },
];

// Per spec §4.2: Дата, Контракт, Партнёр сделки, Клиент, Продукт, Программа,
// Сумма оплаты, Параметр, Сумма без НДС, ГП, Бонус, Бонус ₽, Комментарий.
const groupSalesHeaders = [
  { title: 'Дата', key: 'date', width: 110 },
  { title: 'Контракт', key: 'contractNumber', width: 130 },
  { title: 'Партнёр сделки', key: 'partnerName' },
  { title: 'Клиент', key: 'clientName' },
  { title: 'Продукт', key: 'productName' },
  { title: 'Программа', key: 'programName' },
  { title: 'Сумма оплаты', key: 'paymentAmount', align: 'end', width: 130 },
  { title: 'Параметр', key: 'parameter', width: 110 },
  { title: 'Сумма без НДС', key: 'amountNoVat', align: 'end', width: 130 },
  { title: 'ГП', key: 'personalVolume', align: 'end', width: 90 },
  { title: 'Бонус', key: 'bonus', align: 'end', width: 90 },
  { title: 'Бонус, ₽', key: 'bonusRub', align: 'end', width: 110 },
  { title: 'Комментарий', key: 'comment' },
];

// Per spec §4.3: Дата, Сумма (₽), Комментарий.
const otherAccrualsHeaders = [
  { title: 'Дата', key: 'date', width: 130 },
  { title: 'Сумма, ₽', key: 'amountRUB', align: 'end', width: 140 },
  { title: 'Баллы', key: 'amount', align: 'end', width: 110 },
  { title: 'Комментарий', key: 'comment' },
];

// Per spec §4.5: Дата, Сумма, Комментарий.
const paymentsHeaders = [
  { title: 'Дата', key: 'date', width: 130 },
  { title: 'Сумма, ₽', key: 'amount', align: 'end', width: 160 },
  { title: 'Комментарий', key: 'comment' },
];

const locked = ref(false);
const lockedMessage = ref('');

// Guard от race-condition при быстрой смене месяца в MonthPicker:
// если пользователь переключается «март→апрель→март», ответы могут
// прийти в обратном порядке и затереть актуальные данные. Применяем
// результат только если tag не сбит более новым запросом.
let loadDataTag = 0;

async function loadData() {
  const myTag = ++loadDataTag;
  const requestedMonth = month.value;
  loading.value = true;
  locked.value = false;
  lockedMessage.value = '';
  try {
    const { data: d } = await api.get('/finance/report', { params: { month: requestedMonth } });
    if (myTag !== loadDataTag || month.value !== requestedMonth) return;
    data.value = d;
  } catch (e) {
    if (myTag !== loadDataTag) return;
    if (e?.response?.status === 423) {
      locked.value = true;
      lockedMessage.value = e.response.data?.message || 'Отчёт за этот период ещё не опубликован';
      data.value = {};
    } else {
      data.value = {};
    }
  } finally {
    if (myTag === loadDataTag) loading.value = false;
  }
}

onMounted(loadData);
</script>
