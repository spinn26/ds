<template>
  <div>
    <PageHeader title="Финансы">
      <template #actions>
        <v-btn icon="mdi-refresh" size="small" variant="text" :loading="loading" @click="load" />
      </template>
    </PageHeader>

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>

    <v-card class="balance-card" elevation="0">
      <div class="balance-label">К выплате</div>
      <div class="balance-value">
        <span v-if="loading" class="skeleton-line"></span>
        <span v-else>{{ fmt(totals.toPay) }} ₽</span>
      </div>
      <div class="balance-meta">{{ data?.period || currentPeriod }}</div>
    </v-card>

    <div class="mini-stats">
      <div class="mini-stat">
        <v-icon size="18" color="primary">mdi-arrow-up-bold</v-icon>
        <div>
          <div class="ms-value">{{ fmt(totals.accrued) }} ₽</div>
          <div class="ms-label">Начислено</div>
        </div>
      </div>
      <div class="mini-stat">
        <v-icon size="18" color="info">mdi-cash-multiple</v-icon>
        <div>
          <div class="ms-value">{{ fmt(totals.paid) }} ₽</div>
          <div class="ms-label">Выплачено</div>
        </div>
      </div>
      <div class="mini-stat">
        <v-icon size="18" color="warning">mdi-clock-outline</v-icon>
        <div>
          <div class="ms-value">{{ fmt(totals.saldo) }} ₽</div>
          <div class="ms-label">Сальдо</div>
        </div>
      </div>
    </div>

    <v-card class="detail-card mt-3" elevation="0">
      <div class="section-title-row">
        <v-icon size="18" color="primary">mdi-receipt-text-outline</v-icon>
        <span class="section-title">История начислений</span>
      </div>
      <div v-if="loading" class="text-center py-3">
        <v-progress-circular indeterminate size="24" color="primary" />
      </div>
      <div v-else-if="!rows.length" class="text-medium-emphasis text-caption text-center py-3">
        Начислений нет
      </div>
      <div v-else v-for="r in rows" :key="r.id" class="finance-row">
        <div>
          <div class="finance-title">{{ r.title || r.comment || '—' }}</div>
          <div class="finance-sub">{{ formatDate(r.accrualDate || r.date) }}</div>
        </div>
        <div class="finance-amount" :class="{ negative: (r.amount || 0) < 0 }">
          {{ (r.amount || 0) > 0 ? '+' : '' }}{{ fmt(r.amount) }} ₽
        </div>
      </div>
    </v-card>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

interface FinanceRow { id: number; title?: string; comment?: string; amount?: number; accrualDate?: string; date?: string }
interface FinanceReport {
  period?: string;
  toPay?: number;
  accrued?: number;
  paid?: number;
  saldo?: number;
  totalAccrued?: number;
  totalPaid?: number;
  rows?: FinanceRow[];
  items?: FinanceRow[];
}

const data = ref<FinanceReport | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

const currentPeriod = new Date().toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' });

const totals = computed(() => ({
  toPay: data.value?.toPay ?? 0,
  accrued: data.value?.accrued ?? data.value?.totalAccrued ?? 0,
  paid: data.value?.paid ?? data.value?.totalPaid ?? 0,
  saldo: data.value?.saldo ?? 0,
}));

const rows = computed(() => {
  const arr = data.value?.rows || data.value?.items || [];
  return Array.isArray(arr) ? arr : [];
});

function fmt(n?: number) { return (n ?? 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 }); }
function formatDate(iso?: string) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return iso;
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const { data: d } = await api.get('/finance/report');
    data.value = d;
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.balance-card { background: linear-gradient(135deg, #2E7D32 0%, #43A047 100%); color: #fff; border-radius: 16px; padding: 20px; text-align: center; box-shadow: 0 8px 24px rgba(46, 125, 50, 0.2); }
.balance-label { font-size: 12px; opacity: 0.85; text-transform: uppercase; letter-spacing: 0.6px; }
.balance-value { font-size: 32px; font-weight: 700; margin-top: 6px; font-variant-numeric: tabular-nums; min-height: 38px; }
.balance-meta { font-size: 11px; opacity: 0.7; margin-top: 4px; }
.skeleton-line { display: inline-block; width: 50%; height: 24px; background: rgba(255,255,255,0.2); border-radius: 4px; }

.mini-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 14px; }
.mini-stat { display: flex; align-items: center; gap: 8px; background: #fff; border-radius: 12px; padding: 10px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.ms-value { font-size: 13px; font-weight: 700; font-variant-numeric: tabular-nums; }
.ms-label { font-size: 10px; color: rgba(0,0,0,0.55); }

.detail-card { background: #fff; border-radius: 14px; padding: 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.section-title-row { display: flex; align-items: center; gap: 6px; margin-bottom: 10px; }
.section-title { font-size: 14px; font-weight: 600; }
.finance-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid rgba(0,0,0,0.04); }
.finance-row:last-child { border-bottom: 0; }
.finance-title { font-size: 13px; font-weight: 600; }
.finance-sub { font-size: 11px; color: rgba(0,0,0,0.5); }
.finance-amount { font-size: 14px; font-weight: 700; font-variant-numeric: tabular-nums; color: rgb(var(--v-theme-success)); }
.finance-amount.negative { color: rgb(var(--v-theme-error)); }
</style>
