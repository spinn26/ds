<template>
  <div>
    <PageHeader title="Квалификации" />

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>

    <div v-if="loading" class="text-center py-8">
      <v-progress-circular indeterminate color="primary" size="32" />
    </div>

    <template v-else>
      <v-card class="current-card" elevation="0">
        <div class="badge-wrap">
          <v-icon size="44" color="warning">mdi-trophy</v-icon>
        </div>
        <div class="current-title">{{ current.title }}</div>
        <div class="current-rate">Ставка комиссии: <b>{{ current.percent }}%</b></div>
      </v-card>

      <v-card v-if="next" class="detail-card mt-3" elevation="0">
        <div class="section-title-row">
          <v-icon size="18" color="primary">mdi-progress-check</v-icon>
          <span class="section-title">Прогресс до {{ next.title }}</span>
        </div>
        <div class="progress-block">
          <div class="progress-row">
            <span class="progress-label">НГП</span>
            <span class="progress-value">{{ fmt(volumes.groupVolume) }} / {{ fmt(next.groupVolume) }}</span>
          </div>
          <v-progress-linear :model-value="gpPercent" color="primary" height="8" rounded />
        </div>
        <div v-if="next.mandatoryGP" class="progress-block">
          <div class="progress-row">
            <span class="progress-label">ОП ГП</span>
            <span class="progress-value">{{ fmt(volumes.personalVolume) }} / {{ fmt(next.mandatoryGP) }}</span>
          </div>
          <v-progress-linear :model-value="opPercent" color="info" height="8" rounded />
        </div>
      </v-card>

      <v-card class="detail-card mt-3" elevation="0">
        <div class="section-title-row">
          <v-icon size="18" color="primary">mdi-stairs-up</v-icon>
          <span class="section-title">Все уровни</span>
        </div>
        <div v-for="l in levels" :key="l.level" class="level-row"
          :class="{ current: l.level === current.level, passed: l.level < current.level }">
          <div class="level-id">{{ l.level }}</div>
          <div class="level-body">
            <div class="level-name">{{ l.title }}</div>
            <div class="level-thresh">НГП {{ fmt(l.groupVolume) }} · {{ l.percent }}%</div>
          </div>
          <v-icon v-if="l.level < current.level" size="18" color="success">mdi-check-circle</v-icon>
          <v-icon v-else-if="l.level === current.level" size="18" color="warning">mdi-star</v-icon>
        </div>
      </v-card>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

interface Level {
  id?: number;
  level: number;
  title: string;
  percent: number;
  groupVolume?: number;
  mandatoryGP?: number;
}
interface DashboardPayload {
  qualification?: { level?: Level; nextLevel?: Level };
  volumes?: { personalVolume?: number; groupVolume?: number };
}

const loading = ref(true);
const error = ref<string | null>(null);
const data = ref<DashboardPayload | null>(null);
const allLevels = ref<Level[]>([]);

const current = computed<Level>(() => data.value?.qualification?.level || { level: 1, title: 'Start', percent: 15 });
const next = computed<Level | null>(() => data.value?.qualification?.nextLevel || null);
const volumes = computed(() => data.value?.volumes || {});
const levels = computed(() => {
  if (allLevels.value.length) return allLevels.value;
  // Fallback на статичную таблицу из spec, если эндпоинт не доступен.
  return [
    { level: 1, title: 'Start', percent: 15, groupVolume: 0 },
    { level: 2, title: 'Pro', percent: 20, groupVolume: 2000 },
    { level: 3, title: 'Expert', percent: 25, groupVolume: 7000 },
    { level: 4, title: 'FC', percent: 30, groupVolume: 30000 },
    { level: 5, title: 'Master FC', percent: 35, groupVolume: 150000 },
    { level: 6, title: 'TOP FC', percent: 40, groupVolume: 350000 },
    { level: 7, title: 'Silver DS', percent: 45, groupVolume: 600000 },
    { level: 8, title: 'Gold DS', percent: 49, groupVolume: 1000000 },
    { level: 9, title: 'Platinum DS', percent: 52, groupVolume: 2000000 },
    { level: 10, title: 'Co-founder', percent: 55, groupVolume: 4000000 },
  ];
});

const gpPercent = computed(() => {
  if (!next.value?.groupVolume) return 0;
  return Math.min(100, Math.round(((volumes.value.groupVolume || 0) / next.value.groupVolume) * 100));
});
const opPercent = computed(() => {
  if (!next.value?.mandatoryGP) return 0;
  return Math.min(100, Math.round(((volumes.value.personalVolume || 0) / next.value.mandatoryGP) * 100));
});

function fmt(n?: number) { return (n ?? 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 }); }

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const [dash, lvls] = await Promise.all([
      api.get('/dashboard'),
      api.get('/structure/qualification-levels').catch(() => ({ data: [] })),
    ]);
    data.value = dash.data;
    const arr = Array.isArray(lvls.data?.data) ? lvls.data.data : (Array.isArray(lvls.data) ? lvls.data : []);
    if (arr.length) allLevels.value = arr.map((l: any) => ({
      level: l.level, title: l.title, percent: l.percent,
      groupVolume: l.groupVolume, mandatoryGP: l.mandatoryGP,
    })).sort((a: Level, b: Level) => a.level - b.level);
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.current-card { background: linear-gradient(135deg, rgba(46,125,50,0.95) 0%, rgba(110,232,122,0.85) 100%); color: #fff; text-align: center; padding: 22px 16px; border-radius: 16px; box-shadow: 0 8px 24px rgba(46,125,50,0.2); }
.badge-wrap { width: 76px; height: 76px; margin: 0 auto 10px; background: rgba(255,255,255,0.15); border-radius: 50%; display: flex; align-items: center; justify-content: center; }
.current-title { font-size: 22px; font-weight: 700; letter-spacing: -0.4px; }
.current-rate { font-size: 13px; opacity: 0.9; margin-top: 6px; }

.detail-card { background: #fff; border-radius: 14px; padding: 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.section-title-row { display: flex; align-items: center; gap: 6px; margin-bottom: 10px; }
.section-title { font-size: 14px; font-weight: 600; }

.progress-block { margin-bottom: 14px; }
.progress-row { display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 6px; }
.progress-label { font-weight: 600; color: rgba(0,0,0,0.7); }
.progress-value { font-variant-numeric: tabular-nums; color: rgba(0,0,0,0.6); }

.level-row { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid rgba(0,0,0,0.04); }
.level-row:last-child { border-bottom: 0; }
.level-row.passed { opacity: 0.5; }
.level-row.current { background: rgba(251,140,0,0.05); margin: 0 -8px; padding: 10px 8px; border-radius: 8px; }
.level-id { width: 24px; text-align: center; font-size: 12px; color: rgba(0,0,0,0.4); font-weight: 600; }
.level-body { flex: 1; }
.level-name { font-size: 13px; font-weight: 600; color: #1b1b1b; }
.level-thresh { font-size: 11px; color: rgba(0,0,0,0.5); }
</style>
