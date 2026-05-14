<template>
  <div>
    <section class="kpi-grid">
      <v-card class="kpi-card" v-for="k in kpis" :key="k.key" elevation="0" @click="k.to && $router.push(k.to)">
        <div class="kpi-icon" :style="{ background: k.bg }">
          <v-icon :color="k.color" size="22">{{ k.icon }}</v-icon>
        </div>
        <div class="kpi-label">{{ k.label }}</div>
        <div class="kpi-value">
          <span v-if="loading" class="skeleton-line"></span>
          <span v-else>{{ k.value }}</span>
        </div>
        <div class="kpi-hint">{{ k.hint }}</div>
      </v-card>
    </section>

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mt-3">
      {{ error }}
    </v-alert>

    <v-card class="section-card mt-4" elevation="0">
      <div class="section-head">
        <v-icon size="18" color="warning">mdi-chart-bar</v-icon>
        <span class="section-title">Партнёры за 12 мес.</span>
      </div>
      <div class="bar-chart">
        <div v-for="(b, i) in partnersBars" :key="i" class="bar" :style="{ height: b.h + '%' }" :title="b.label" />
      </div>
      <div class="bar-labels">
        <span v-for="(l, i) in partnersBarLabels" :key="i">{{ l }}</span>
      </div>
    </v-card>

    <v-card class="section-card mt-4" elevation="0">
      <div class="section-head">
        <v-icon size="18" color="warning">mdi-rocket-launch-outline</v-icon>
        <span class="section-title">Быстрые действия</span>
      </div>
      <div class="quick-grid">
        <button v-for="q in quickActions" :key="q.label" class="quick-btn" @click="$router.push(q.to)">
          <v-icon :color="q.color" size="24">{{ q.icon }}</v-icon>
          <span>{{ q.label }}</span>
        </button>
      </div>
    </v-card>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import api from '@/api';

interface AdminDashboard {
  totalPartners?: number;
  activePartners?: number;
  newPartnersMonth?: number;
  totalClients?: number;
  totalContracts?: number;
  openTickets?: number;
  revenueMonth?: number;
  revenuePrevMonth?: number;
  partnersTrend?: { month: string; count: number }[];
}

const data = ref<AdminDashboard | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

const fmtNum = (n?: number) => (n ?? 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 });
const fmtMoney = (n?: number) => {
  if (n == null) return '—';
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1).replace('.', ',') + ' М ₽';
  if (n >= 1_000) return (n / 1_000).toFixed(0) + ' тыс. ₽';
  return Math.round(n).toLocaleString('ru-RU') + ' ₽';
};

const kpis = computed(() => {
  const d = data.value || {};
  return [
    { key: 'partners', label: 'Партнёры', value: fmtNum(d.totalPartners), hint: `+${d.newPartnersMonth ?? 0} за месяц`, icon: 'mdi-account-group', color: 'warning', bg: 'rgba(251,140,0,0.10)', to: '/manage/partners' },
    { key: 'clients', label: 'Клиенты', value: fmtNum(d.totalClients), hint: 'всего', icon: 'mdi-account-multiple', color: 'info', bg: 'rgba(30,136,229,0.10)', to: '/manage/clients' },
    { key: 'contracts', label: 'Контракты', value: fmtNum(d.totalContracts), hint: 'действующих', icon: 'mdi-file-document-multiple', color: 'primary', bg: 'rgba(46,125,50,0.10)', to: '/manage/contracts' },
    { key: 'revenue', label: 'Revenue', value: fmtMoney(d.revenueMonth), hint: 'текущий месяц', icon: 'mdi-cash-multiple', color: 'success', bg: 'rgba(67,160,71,0.10)', to: '/manage/transactions' },
  ];
});

const partnersBars = computed(() => {
  const trend = data.value?.partnersTrend || [];
  if (!trend.length) return Array(7).fill(0).map(() => ({ h: 0, label: '' }));
  const max = Math.max(1, ...trend.map((t) => t.count));
  return trend.map((t) => ({ h: Math.round((t.count / max) * 100), label: `${t.month}: ${t.count}` }));
});
const partnersBarLabels = computed(() => {
  const trend = data.value?.partnersTrend || [];
  return trend.map((t) => t.month.slice(5));
});

const quickActions = [
  { label: 'Тех. поддержка', icon: 'mdi-lifebuoy', color: 'error', to: '/manage/support' },
  { label: 'Тикеты', icon: 'mdi-message-outline', color: 'info', to: '/manage/chat' },
  { label: 'Выплаты', icon: 'mdi-cash', color: 'success', to: '/manage/payments' },
  { label: 'Отчёты', icon: 'mdi-chart-box-outline', color: 'warning', to: '/manage/reports' },
];

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const { data: d } = await api.get('/admin/dashboard');
    data.value = d;
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить дашборд';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.kpi-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.kpi-card { padding: 14px; background: #fff; border-radius: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); cursor: pointer; }
.kpi-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-bottom: 8px; }
.kpi-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.6px; color: rgba(0,0,0,0.5); }
.kpi-value { font-size: 18px; font-weight: 700; font-variant-numeric: tabular-nums; min-height: 24px; }
.kpi-hint { font-size: 11px; color: rgba(0,0,0,0.5); margin-top: 2px; }

.skeleton-line {
  display: inline-block; width: 60%; height: 14px;
  background: linear-gradient(90deg, rgba(0,0,0,0.06) 0%, rgba(0,0,0,0.12) 50%, rgba(0,0,0,0.06) 100%);
  background-size: 200% 100%; border-radius: 4px;
  animation: skel 1.4s ease-in-out infinite;
}
@keyframes skel { 0%,100% { background-position: 0 0 } 50% { background-position: 100% 0 } }

.section-card { background: #fff; border-radius: 14px; padding: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
.section-head { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
.section-title { font-size: 14px; font-weight: 600; }

.bar-chart { display: flex; align-items: flex-end; gap: 4px; height: 100px; margin: 8px 0 4px; }
.bar { flex: 1; background: linear-gradient(180deg, rgb(var(--v-theme-warning)) 0%, rgba(251,140,0,0.6) 100%); border-radius: 4px 4px 0 0; min-height: 4px; }
.bar-labels { display: flex; gap: 4px; font-size: 9px; color: rgba(0,0,0,0.5); }
.bar-labels span { flex: 1; text-align: center; overflow: hidden; }

.quick-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.quick-btn { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; padding: 12px 4px; background: rgba(251,140,0,0.06); border: 0; border-radius: 12px; font-size: 11px; cursor: pointer; }
</style>
