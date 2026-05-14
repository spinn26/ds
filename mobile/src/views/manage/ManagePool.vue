<template>
  <div>
    <PageHeader title="Лидерский пул">
      <template #actions>
        <v-btn icon="mdi-refresh" size="small" variant="text" :loading="loading" @click="load" />
      </template>
    </PageHeader>

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>

    <v-card v-if="data" class="period-card" elevation="0">
      <div class="period-head">
        <div>
          <div class="text-caption text-medium-emphasis">{{ data.periodLabel || data.period || '—' }}</div>
          <div class="period-amount">{{ formatMoney(data.totalFund) }} ₽</div>
        </div>
        <v-chip color="warning" size="small" variant="flat">фонд</v-chip>
      </div>
    </v-card>

    <div v-if="loading" class="text-center py-8">
      <v-progress-circular indeterminate color="warning" size="32" />
    </div>

    <div v-else-if="!levels.length" class="empty-state">
      <v-icon size="48">mdi-trophy-outline</v-icon>
      <div class="empty-state-text">Уровни не настроены</div>
    </div>

    <div v-for="lvl in levels" :key="lvl.id" class="pool-level">
      <div class="pl-head">
        <v-icon size="16" color="warning">mdi-trophy-outline</v-icon>
        <span class="pl-name">{{ lvl.name }}</span>
        <v-spacer />
        <span class="pl-share">{{ formatMoney(lvl.share) }} ₽</span>
      </div>
      <div v-if="!lvl.participants || !lvl.participants.length" class="text-medium-emphasis text-caption text-center py-2">
        Никто не претендует
      </div>
      <div v-for="p in lvl.participants || []" :key="p.id" class="participant-row">
        <v-icon :color="p.included ? 'success' : 'grey'" size="16">
          {{ p.included ? 'mdi-check-circle' : 'mdi-circle-outline' }}
        </v-icon>
        <div class="p-name">{{ p.name }}</div>
        <span v-if="p.note" class="p-note">{{ p.note }}</span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

interface PoolLevel { id: number; name: string; share?: number; participants?: { id: number; name: string; included?: boolean; note?: string }[] }
interface PoolData { totalFund?: number; period?: string; periodLabel?: string; levels?: PoolLevel[] }

const data = ref<PoolData | null>(null);
const levels = ref<PoolLevel[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

function formatMoney(n?: number) { return (n ?? 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 }); }

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const { data: d } = await api.get('/admin/pool');
    data.value = d;
    levels.value = Array.isArray(d?.levels) ? d.levels : [];
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.period-card { background: #fff; border-radius: 14px; padding: 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); margin-bottom: 10px; }
.period-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.period-amount { font-size: 24px; font-weight: 700; color: rgb(var(--v-theme-warning)); font-variant-numeric: tabular-nums; margin-top: 4px; }
.pool-level { background: #fff; border-radius: 14px; margin-top: 10px; padding: 12px 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.pl-head { display: flex; align-items: center; gap: 6px; margin-bottom: 8px; }
.pl-name { font-size: 13px; font-weight: 600; }
.pl-share { font-size: 11px; color: rgba(0,0,0,0.55); font-variant-numeric: tabular-nums; }
.participant-row { display: flex; align-items: center; gap: 8px; padding: 6px 0; border-top: 1px solid rgba(0,0,0,0.04); }
.p-name { flex: 1; font-size: 13px; }
.p-note { font-size: 10px; color: rgb(var(--v-theme-error)); }
.empty-state { padding: 60px 24px; text-align: center; }
.empty-state .v-icon { color: rgba(0,0,0,0.2); margin-bottom: 12px; }
.empty-state-text { font-size: 14px; color: rgba(0,0,0,0.5); }
</style>
