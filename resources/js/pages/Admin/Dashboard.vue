<template>
  <div>
    <PageHeader title="Панель управления" icon="mdi-chart-areaspline" />

    <!-- KPI Cards -->
    <v-row class="mb-4" dense>
      <v-col v-for="card in kpiCards" :key="card.label" cols="6" sm="4" md="3" lg="2">
        <v-card class="pa-3 text-center stat-card" :color="card.color" variant="tonal">
          <v-icon :color="card.color" size="24" class="mb-1">{{ card.icon }}</v-icon>
          <div class="text-h5 font-weight-bold">{{ card.value }}</div>
          <div class="text-caption text-medium-emphasis">{{ card.label }}</div>
          <div v-if="card.delta !== null" class="text-caption" :class="deltaClass(card.delta)">
            <v-icon size="12">{{ card.delta > 0 ? 'mdi-arrow-up' : card.delta < 0 ? 'mdi-arrow-down' : 'mdi-minus' }}</v-icon>
            {{ fmtDelta(card.delta) }}
          </div>
        </v-card>
      </v-col>
    </v-row>

    <v-row class="mb-4">
      <!-- Revenue trend chart -->
      <v-col cols="12" md="8">
        <v-card class="pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">Выручка по месяцам (₽)</div>
          <div style="height: 280px">
            <Line v-if="revenueChart" :data="revenueChart.data" :options="revenueChart.options" />
          </div>
        </v-card>
      </v-col>

      <!-- Partners by status (doughnut) -->
      <v-col cols="12" md="4">
        <v-card class="pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">Партнёры по статусам</div>
          <div style="height: 280px">
            <Doughnut v-if="statusChart" :data="statusChart.data" :options="statusChart.options" />
          </div>
        </v-card>
      </v-col>
    </v-row>

    <v-row class="mb-4">
      <!-- New partners trend -->
      <v-col cols="12" md="6">
        <v-card class="pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">Новые партнёры по месяцам</div>
          <div style="height: 240px">
            <Bar v-if="partnersChart" :data="partnersChart.data" :options="partnersChart.options" />
          </div>
        </v-card>
      </v-col>

      <!-- Revenue by product -->
      <v-col cols="12" md="6">
        <v-card class="pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">Выручка по продуктам (этот месяц)</div>
          <div style="height: 240px">
            <Bar v-if="productChart" :data="productChart.data" :options="productChart.options" />
          </div>
        </v-card>
      </v-col>
    </v-row>

    <v-row class="mb-4">
      <!-- Conversion funnel -->
      <v-col cols="12" md="7">
        <v-card class="pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">Воронка партнёра</div>
          <div class="d-flex flex-column ga-3">
            <div v-for="(step, i) in funnelSteps" :key="step.stage">
              <div class="d-flex justify-space-between mb-1">
                <span class="text-body-2 font-weight-medium">
                  <span class="text-caption text-medium-emphasis mr-1">{{ i + 1 }}.</span>
                  {{ step.stage }}
                </span>
                <span class="text-body-2">
                  <strong>{{ fmtN(step.count) }}</strong>
                  <span v-if="step.conversion !== null" class="text-caption text-medium-emphasis ml-2">
                    {{ step.conversion }}% от регистраций
                  </span>
                </span>
              </div>
              <v-progress-linear :model-value="step.percent" height="20" rounded
                :color="funnelColor(i)">
                <template #default>
                  <span class="text-caption font-weight-bold text-white">{{ step.percent }}%</span>
                </template>
              </v-progress-linear>
            </div>
          </div>
        </v-card>
      </v-col>

      <!-- Qualification distribution -->
      <v-col cols="12" md="5">
        <v-card class="pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">Распределение по квалификациям</div>
          <div class="d-flex flex-column ga-2">
            <div v-for="q in data.charts?.qualDistribution || []" :key="q.level" class="d-flex align-center ga-2">
              <v-chip size="x-small" color="secondary" variant="flat" style="min-width: 28px">{{ q.level }}</v-chip>
              <span class="text-body-2 flex-grow-1 text-truncate">{{ q.title }}</span>
              <v-progress-linear :model-value="maxQual ? (q.count / maxQual) * 100 : 0"
                color="secondary" height="16" rounded style="max-width: 150px">
                <template #default>
                  <span class="text-caption" style="font-size: 0.65rem">{{ q.count }}</span>
                </template>
              </v-progress-linear>
            </div>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <v-row>
      <!-- Top 10 consultants -->
      <v-col cols="12" md="7">
        <v-card class="pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">ТОП-10 партнёров по НГП</div>
          <v-table density="compact">
            <thead>
              <tr><th>#</th><th>Партнёр</th><th class="text-right">НГП</th><th class="text-right">ЛП</th></tr>
            </thead>
            <tbody>
              <tr v-for="(c, i) in data.charts?.topConsultants || []" :key="i">
                <td>
                  <v-avatar size="24" :color="i < 3 ? 'warning' : 'grey-lighten-2'">
                    <span class="text-caption font-weight-bold">{{ i + 1 }}</span>
                  </v-avatar>
                </td>
                <td class="font-weight-medium">{{ c.name }}</td>
                <td class="text-right">{{ fmtN(c.ngp) }}</td>
                <td class="text-right">{{ fmtN(c.lp) }}</td>
              </tr>
            </tbody>
          </v-table>
        </v-card>
      </v-col>

      <!-- Recent activity -->
      <v-col cols="12" md="5">
        <v-card class="pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">Последние события</div>
          <v-list density="compact" class="pa-0">
            <v-list-item v-for="(a, i) in data.recentActivity || []" :key="i" class="px-0">
              <template #prepend>
                <v-avatar size="32" :color="a.color" variant="tonal">
                  <v-icon size="16">{{ a.icon }}</v-icon>
                </v-avatar>
              </template>
              <v-list-item-title class="text-body-2">{{ a.text }}</v-list-item-title>
              <v-list-item-subtitle class="text-caption">{{ fmtDate(a.date) }}</v-list-item-subtitle>
            </v-list-item>
            <div v-if="!(data.recentActivity?.length)" class="text-center text-medium-emphasis pa-4">
              Нет событий
            </div>
          </v-list>
        </v-card>
      </v-col>
    </v-row>

    <v-overlay v-model="loading" class="align-center justify-center" persistent>
      <v-progress-circular indeterminate size="64" />
    </v-overlay>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { Line, Bar, Doughnut } from 'vue-chartjs';
import {
  Chart as ChartJS,
  Title, Tooltip, Legend,
  LineElement, PointElement,
  BarElement, CategoryScale, LinearScale,
  ArcElement,
} from 'chart.js';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import { fmtDate } from '../../composables/useDesign';

ChartJS.register(
  Title, Tooltip, Legend,
  LineElement, PointElement,
  BarElement, CategoryScale, LinearScale,
  ArcElement,
);

const loading = ref(true);
const data = ref({});

const fmtN = (n) => Number(n || 0).toLocaleString('ru-RU');
const fmtK = (n) => {
  if (!n) return '0';
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M';
  if (n >= 1_000) return (n / 1_000).toFixed(0) + 'K';
  return String(Math.round(n));
};

// ---- KPI with deltas ----
function delta(curr, prev) {
  const a = Number(curr || 0);
  const b = Number(prev || 0);
  if (!b) return null;
  return Math.round(((a - b) / b) * 100);
}
function fmtDelta(d) {
  if (d === null || Number.isNaN(d)) return '';
  return (d > 0 ? '+' : '') + d + '%';
}
function deltaClass(d) {
  if (d > 0) return 'text-success';
  if (d < 0) return 'text-error';
  return 'text-medium-emphasis';
}

const kpiCards = computed(() => {
  const k = data.value.kpi || {};
  return [
    { label: 'Партнёров', value: fmtN(k.totalPartners), icon: 'mdi-account-group', color: 'primary',
      delta: delta(k.totalPartners, k.totalPartnersPrev) },
    { label: 'Активных', value: fmtN(k.activePartners), icon: 'mdi-account-check', color: 'success',
      delta: delta(k.activePartners, k.activePartnersPrev) },
    { label: 'Новых за месяц', value: fmtN(k.newPartnersMonth), icon: 'mdi-account-plus', color: 'info',
      delta: delta(k.newPartnersMonth, k.newPartnersPrevMonth) },
    { label: 'Клиентов', value: fmtN(k.totalClients), icon: 'mdi-people', color: 'secondary', delta: null },
    { label: 'Контрактов', value: fmtN(k.totalContracts), icon: 'mdi-file-document', color: 'warning',
      delta: delta(k.totalContracts, k.totalContractsPrev) },
    { label: 'Открытых тикетов', value: fmtN(k.openTickets), icon: 'mdi-ticket',
      color: k.openTickets > 5 ? 'error' : 'grey', delta: null },
    { label: 'Выручка (мес)', value: fmtK(k.revenueMonth) + ' ₽', icon: 'mdi-cash', color: 'success',
      delta: delta(k.revenueMonth, k.revenuePrevMonth) },
    { label: 'Выручка (пред.)', value: fmtK(k.revenuePrevMonth) + ' ₽', icon: 'mdi-cash-clock', color: 'grey',
      delta: null },
  ];
});

const maxQual = computed(() => Math.max(...(data.value.charts?.qualDistribution || []).map(q => q.count), 1));

// ---- Chart colors ----
const palette = {
  primary: 'rgb(46, 125, 50)',
  primaryLight: 'rgba(46, 125, 50, 0.15)',
  info: 'rgb(33, 150, 243)',
  infoLight: 'rgba(33, 150, 243, 0.7)',
  success: 'rgb(76, 175, 80)',
  warning: 'rgb(255, 152, 0)',
  error: 'rgb(244, 67, 54)',
  grey: 'rgb(158, 158, 158)',
};

const baseAxis = {
  grid: { color: 'rgba(128,128,128,0.08)' },
  ticks: { font: { size: 10 } },
};
const commonOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: { callbacks: { label: (ctx) => ` ${fmtN(ctx.parsed.y ?? ctx.parsed)}` } },
  },
  scales: {
    x: baseAxis,
    y: { ...baseAxis, beginAtZero: true, ticks: { ...baseAxis.ticks, callback: (v) => fmtK(v) } },
  },
};

// ---- Revenue trend (line) ----
const revenueChart = computed(() => {
  const trend = data.value.charts?.revenueTrend || [];
  if (!trend.length) return null;
  return {
    data: {
      labels: trend.map(r => r.month),
      datasets: [{
        label: 'Выручка (₽)',
        data: trend.map(r => r.total),
        borderColor: palette.primary,
        backgroundColor: palette.primaryLight,
        fill: true,
        tension: 0.3,
        pointRadius: 3,
        pointHoverRadius: 5,
      }],
    },
    options: commonOptions,
  };
});

// ---- New partners trend (bar) ----
const partnersChart = computed(() => {
  const trend = data.value.charts?.partnersTrend || [];
  if (!trend.length) return null;
  return {
    data: {
      labels: trend.map(r => r.month?.slice(5) || r.month),
      datasets: [{
        label: 'Новые партнёры',
        data: trend.map(r => r.count),
        backgroundColor: palette.infoLight,
        borderColor: palette.info,
        borderWidth: 1,
        borderRadius: 6,
      }],
    },
    options: commonOptions,
  };
});

// ---- Revenue by product (horizontal bar) ----
const productChart = computed(() => {
  const items = data.value.charts?.revenueByProduct || [];
  if (!items.length) return null;
  return {
    data: {
      labels: items.map(i => i.name),
      datasets: [{
        label: 'Выручка (₽)',
        data: items.map(i => i.total),
        backgroundColor: palette.primaryLight,
        borderColor: palette.primary,
        borderWidth: 1,
        borderRadius: 6,
      }],
    },
    options: {
      ...commonOptions,
      indexAxis: 'y',
      scales: {
        x: { ...baseAxis, beginAtZero: true, ticks: { ...baseAxis.ticks, callback: (v) => fmtK(v) } },
        y: baseAxis,
      },
    },
  };
});

// ---- Partners by status (doughnut) ----
const statusChart = computed(() => {
  const rows = data.value.charts?.partnersByStatus || [];
  if (!rows.length) return null;
  const colorFor = (name) => {
    if (!name) return palette.grey;
    const l = name.toLowerCase();
    if (l.includes('актив')) return palette.success;
    if (l.includes('терминир') || l.includes('исключ')) return palette.error;
    if (l.includes('зарег')) return palette.info;
    return palette.warning;
  };
  return {
    data: {
      labels: rows.map(r => r.name),
      datasets: [{
        data: rows.map(r => r.count),
        backgroundColor: rows.map(r => colorFor(r.name)),
        borderWidth: 0,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom', labels: { font: { size: 11 }, boxWidth: 12 } },
        tooltip: { callbacks: { label: (ctx) => ` ${ctx.label}: ${fmtN(ctx.parsed)}` } },
      },
      cutout: '62%',
    },
  };
});

// ---- Conversion funnel ----
const funnelSteps = computed(() => {
  const items = data.value.charts?.funnel || [];
  const base = items[0]?.count || 0;
  return items.map((s, i) => ({
    ...s,
    percent: base ? Math.round((s.count / base) * 100) : 0,
    conversion: base && i > 0 ? Math.round((s.count / base) * 100) : null,
  }));
});

function funnelColor(i) {
  return ['primary', 'success', 'info', 'warning'][i] || 'grey';
}

onMounted(async () => {
  try {
    const { data: d } = await api.get('/admin/dashboard');
    data.value = d;
  } catch {}
  loading.value = false;
});
</script>
