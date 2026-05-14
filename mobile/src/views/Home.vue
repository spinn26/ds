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
        <v-icon size="18" color="primary">mdi-rocket-launch-outline</v-icon>
        <span class="section-title">Быстрые действия</span>
      </div>
      <div class="quick-grid">
        <button v-for="q in quickActions" :key="q.label" class="quick-btn" @click="$router.push(q.to)">
          <v-icon :color="q.color" size="24">{{ q.icon }}</v-icon>
          <span>{{ q.label }}</span>
        </button>
      </div>
    </v-card>

    <v-card class="section-card mt-4" elevation="0">
      <div class="section-head">
        <v-icon size="18" color="primary">mdi-pulse</v-icon>
        <span class="section-title">Последние события</span>
        <v-spacer />
        <v-btn variant="text" size="x-small" @click="$router.push('/app/notifications')">Все</v-btn>
      </div>
      <div v-if="loadingNotifs" class="text-center py-3">
        <v-progress-circular indeterminate size="20" color="primary" />
      </div>
      <div v-else-if="!notifications.length" class="text-center text-medium-emphasis py-3 text-caption">
        Нет новых уведомлений
      </div>
      <v-list v-else density="compact" class="bg-transparent pa-0">
        <v-list-item v-for="e in notifications.slice(0, 5)" :key="e.id" class="event-row">
          <template #prepend>
            <v-avatar :color="e.color || 'primary'" size="32" variant="tonal">
              <v-icon size="16">{{ e.icon || 'mdi-bell-outline' }}</v-icon>
            </v-avatar>
          </template>
          <v-list-item-title class="text-body-2">{{ e.title }}</v-list-item-title>
          <v-list-item-subtitle class="text-caption">{{ e.message || e.subtitle }}</v-list-item-subtitle>
          <template #append>
            <span class="text-caption text-medium-emphasis">{{ formatTime(e.createdAt) }}</span>
          </template>
        </v-list-item>
      </v-list>
    </v-card>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import api from '@/api';

interface DashboardData {
  consultant?: { id: number; personName?: string; statusName?: string };
  qualification?: { level?: { title?: string; percent?: number } };
  volumes?: { personalVolume?: number; groupVolume?: number };
}
interface Notif {
  id: number;
  title?: string;
  message?: string;
  subtitle?: string;
  icon?: string;
  color?: string;
  createdAt?: string;
}

const data = ref<DashboardData | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const notifications = ref<Notif[]>([]);
const loadingNotifs = ref(true);

const fmtNum = (n?: number) => (n ?? 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 });

const kpis = computed(() => {
  const lp = data.value?.volumes?.personalVolume ?? 0;
  const gp = data.value?.volumes?.groupVolume ?? 0;
  const qualTitle = data.value?.qualification?.level?.title ?? 'Start';
  const qualPct = data.value?.qualification?.level?.percent ?? 0;
  return [
    { key: 'lp', label: 'ЛП', value: fmtNum(lp), hint: 'баллов в этом месяце', icon: 'mdi-account-star', color: 'primary', bg: 'rgba(46,125,50,0.10)', to: '/app/qualifications' },
    { key: 'gp', label: 'ГП', value: fmtNum(gp), hint: 'баллов структуры', icon: 'mdi-account-group', color: 'info', bg: 'rgba(30,136,229,0.10)', to: '/app/structure' },
    { key: 'qual', label: 'Квалификация', value: qualTitle, hint: `ставка ${qualPct}%`, icon: 'mdi-trophy-outline', color: 'warning', bg: 'rgba(251,140,0,0.10)', to: '/app/qualifications' },
    { key: 'finance', label: 'Финансы', value: '→', hint: 'реестр выплат', icon: 'mdi-cash-multiple', color: 'success', bg: 'rgba(67,160,71,0.10)', to: '/app/finance' },
  ];
});

const quickActions = [
  { label: 'Контракты', icon: 'mdi-file-document-multiple-outline', color: 'primary', to: '/app/contracts' },
  { label: 'Клиенты', icon: 'mdi-account-multiple-outline', color: 'info', to: '/app/clients' },
  { label: 'Транзакции', icon: 'mdi-swap-horizontal', color: 'success', to: '/app/transactions' },
  { label: 'Обучение', icon: 'mdi-school-outline', color: 'warning', to: '/app/education' },
];

function formatTime(iso?: string) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';
  const today = new Date();
  if (d.toDateString() === today.toDateString()) {
    return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  }
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' });
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const { data: d } = await api.get('/dashboard');
    data.value = d;
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить дашборд';
  } finally {
    loading.value = false;
  }
}

async function loadNotifs() {
  loadingNotifs.value = true;
  try {
    const { data: d } = await api.get('/notifications', { params: { limit: 5 } });
    notifications.value = Array.isArray(d?.data) ? d.data : (Array.isArray(d) ? d : []);
  } catch {
    notifications.value = [];
  } finally {
    loadingNotifs.value = false;
  }
}

onMounted(() => {
  load();
  loadNotifs();
});
</script>

<style scoped>
.kpi-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.kpi-card { padding: 14px; background: rgb(var(--v-theme-surface)); border-radius: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); cursor: pointer; transition: transform 0.1s; }
.kpi-card:active { transform: scale(0.98); }
.kpi-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-bottom: 8px; }
.kpi-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.6px; color: rgba(var(--v-theme-on-surface), 0.5); }
.kpi-value { font-size: 18px; font-weight: 700; font-variant-numeric: tabular-nums; margin-top: 2px; min-height: 24px; }
.kpi-hint { font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.5); margin-top: 2px; }

.skeleton-line {
  display: inline-block; width: 60%; height: 14px;
  background: linear-gradient(90deg, rgba(0,0,0,0.06) 0%, rgba(0,0,0,0.12) 50%, rgba(0,0,0,0.06) 100%);
  background-size: 200% 100%;
  border-radius: 4px;
  animation: skel 1.4s ease-in-out infinite;
}
@keyframes skel { 0%,100% { background-position: 0 0 } 50% { background-position: 100% 0 } }

.section-card { background: rgb(var(--v-theme-surface)); border-radius: 14px; padding: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
.section-head { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
.section-title { font-size: 14px; font-weight: 600; }

.quick-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.quick-btn { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; padding: 12px 4px; background: rgba(var(--v-theme-primary), 0.06); border: 0; border-radius: 12px; font-size: 11px; color: rgb(var(--v-theme-on-surface)); cursor: pointer; }
.quick-btn:active { transform: scale(0.95); }

.event-row { padding: 8px 0 !important; min-height: 0 !important; }
</style>
