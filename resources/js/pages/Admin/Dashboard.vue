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
          <div v-if="card.change" class="text-caption" :class="card.changePositive ? 'text-success' : 'text-error'">
            {{ card.change }}
          </div>
        </v-card>
      </v-col>
    </v-row>

    <v-row class="mb-4">
      <!-- Revenue trend chart -->
      <v-col cols="12" md="8">
        <v-card class="pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">Выручка по месяцам (₽)</div>
          <div style="height: 280px; display: flex; align-items: end; gap: 4px; padding: 0 8px">
            <div v-for="bar in revenueBars" :key="bar.month" class="d-flex flex-column align-center flex-grow-1" style="min-width: 0">
              <div class="text-caption font-weight-bold mb-1">{{ fmtK(bar.total) }}</div>
              <div :style="{ height: bar.height + 'px', width: '100%', maxWidth: '48px', borderRadius: '8px 8px 4px 4px' }"
                :class="bar.isCurrent ? 'bg-primary' : 'bg-grey-lighten-2'" />
              <div class="text-caption text-medium-emphasis mt-1" style="font-size: 0.6rem">{{ bar.label }}</div>
            </div>
          </div>
        </v-card>
      </v-col>

      <!-- Partners by status (donut) -->
      <v-col cols="12" md="4">
        <v-card class="pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">Партнёры по статусам</div>
          <div class="d-flex flex-column ga-3">
            <div v-for="s in data.charts?.partnersByStatus || []" :key="s.name" class="d-flex align-center ga-2">
              <v-progress-linear :model-value="totalPartners ? (s.count / totalPartners) * 100 : 0"
                :color="statusBarColor(s.name)" height="24" rounded class="flex-grow-1">
                <template #default>
                  <span class="text-caption font-weight-medium">{{ s.name }}</span>
                </template>
              </v-progress-linear>
              <span class="text-body-2 font-weight-bold" style="min-width: 36px; text-align: right">{{ s.count }}</span>
            </div>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <v-row class="mb-4">
      <!-- New partners trend -->
      <v-col cols="12" md="6">
        <v-card class="pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">Новые партнёры по месяцам</div>
          <div style="height: 200px; display: flex; align-items: end; gap: 6px; padding: 0 8px">
            <div v-for="bar in partnerBars" :key="bar.month" class="d-flex flex-column align-center flex-grow-1" style="min-width: 0">
              <div class="text-caption font-weight-bold mb-1">{{ bar.count || '' }}</div>
              <div :style="{ height: bar.height + 'px', width: '100%', maxWidth: '36px', borderRadius: '6px 6px 3px 3px' }"
                class="bg-info" />
              <div class="text-caption text-medium-emphasis mt-1" style="font-size: 0.55rem">{{ bar.label }}</div>
            </div>
          </div>
        </v-card>
      </v-col>

      <!-- Qualification distribution -->
      <v-col cols="12" md="6">
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
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import { fmtDate } from '../../composables/useDesign';

const loading = ref(true);
const data = ref({});

const fmtN = (n) => Number(n || 0).toLocaleString('ru-RU');
const fmtK = (n) => {
  if (!n) return '';
  if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
  if (n >= 1000) return (n / 1000).toFixed(0) + 'K';
  return n.toString();
};

const kpiCards = computed(() => {
  const k = data.value.kpi || {};
  const revChange = k.revenuePrevMonth ? (((k.revenueMonth - k.revenuePrevMonth) / k.revenuePrevMonth) * 100).toFixed(0) : null;
  return [
    { label: 'Партнёров', value: fmtN(k.totalPartners), icon: 'mdi-account-group', color: 'primary' },
    { label: 'Активных', value: fmtN(k.activePartners), icon: 'mdi-account-check', color: 'success' },
    { label: 'Новых за месяц', value: fmtN(k.newPartnersMonth), icon: 'mdi-account-plus', color: 'info' },
    { label: 'Клиентов', value: fmtN(k.totalClients), icon: 'mdi-people', color: 'secondary' },
    { label: 'Контрактов', value: fmtN(k.totalContracts), icon: 'mdi-file-document', color: 'warning' },
    { label: 'Открытых тикетов', value: fmtN(k.openTickets), icon: 'mdi-ticket', color: k.openTickets > 5 ? 'error' : 'grey' },
    { label: 'Выручка (мес)', value: fmtK(k.revenueMonth) + ' ₽', icon: 'mdi-cash', color: 'success',
      change: revChange ? `${revChange > 0 ? '+' : ''}${revChange}%` : null, changePositive: revChange > 0 },
    { label: 'Выручка (пред.)', value: fmtK(k.revenuePrevMonth) + ' ₽', icon: 'mdi-cash-clock', color: 'grey' },
  ];
});

const totalPartners = computed(() => data.value.kpi?.totalPartners || 1);
const maxQual = computed(() => Math.max(...(data.value.charts?.qualDistribution || []).map(q => q.count), 1));

const revenueBars = computed(() => {
  const trend = data.value.charts?.revenueTrend || [];
  const maxVal = Math.max(...trend.map(r => r.total), 1);
  const currentMonth = new Date().toISOString().slice(0, 7);
  return trend.map(r => ({
    ...r,
    height: Math.max((r.total / maxVal) * 220, 4),
    label: r.month?.slice(5) || '',
    isCurrent: r.month === currentMonth,
  }));
});

const partnerBars = computed(() => {
  const trend = data.value.charts?.partnersTrend || [];
  const maxVal = Math.max(...trend.map(r => r.count), 1);
  return trend.map(r => ({
    ...r,
    height: Math.max((r.count / maxVal) * 160, 2),
    label: r.month?.slice(5) || '',
  }));
});

function statusBarColor(name) {
  if (name?.includes('Актив')) return 'success';
  if (name?.includes('Терм')) return 'error';
  if (name?.includes('Зарег')) return 'info';
  return 'grey';
}

onMounted(async () => {
  try {
    const { data: d } = await api.get('/admin/dashboard');
    data.value = d;
  } catch {}
  loading.value = false;
});
</script>
