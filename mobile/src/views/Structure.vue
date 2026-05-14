<template>
  <div>
    <PageHeader title="Моя структура" />

    <v-card class="detail-card mb-3" elevation="0">
      <div class="stat-grid">
        <div class="stat-item">
          <div class="stat-value">{{ stats.totalPartners }}</div>
          <div class="stat-label">партнёров</div>
        </div>
        <div class="stat-item">
          <div class="stat-value">{{ stats.activeMonth }}</div>
          <div class="stat-label">активных</div>
        </div>
        <div class="stat-item">
          <div class="stat-value">{{ stats.gp }}</div>
          <div class="stat-label">ГП баллов</div>
        </div>
      </div>
    </v-card>

    <div class="search-bar">
      <v-text-field v-model="search" placeholder="Поиск по ФИО"
        density="compact" variant="outlined" hide-details rounded clearable
        prepend-inner-icon="mdi-magnify" />
    </div>

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>

    <div v-if="loading" class="text-center py-8">
      <v-progress-circular indeterminate color="primary" size="32" />
    </div>

    <div v-else-if="!filtered.length" class="empty-state">
      <v-icon size="48">mdi-account-tree-outline</v-icon>
      <div class="empty-state-text">Партнёры не найдены</div>
    </div>

    <template v-else>
      <div v-for="row in filtered" :key="row.id" class="tree-row" :style="{ paddingLeft: (row.depth || 0) * 16 + 'px' }">
        <div class="tree-line">
          <v-icon v-if="(row.depth || 0) > 0" size="14" color="grey-lighten-1">mdi-subdirectory-arrow-right</v-icon>
          <v-avatar size="32" color="primary" variant="tonal">
            <span class="text-caption font-weight-bold">{{ initials(row.personName || row.name) }}</span>
          </v-avatar>
          <div class="tree-body">
            <div class="tree-name">{{ row.personName || row.name }}</div>
            <div class="tree-sub">{{ row.qualName || row.statusName || row.qual || '—' }} · ЛП {{ fmtNum(row.personalVolume || row.lp) }}</div>
          </div>
          <v-chip v-if="row.detached" size="x-small" color="warning" variant="tonal">отрыв</v-chip>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

interface StructureRow {
  id: number;
  personName?: string;
  name?: string;
  qualName?: string;
  statusName?: string;
  qual?: string;
  personalVolume?: number;
  lp?: number;
  depth?: number;
  detached?: boolean;
}

const search = ref('');
const items = ref<StructureRow[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const stats = computed(() => ({
  totalPartners: items.value.length,
  activeMonth: items.value.filter((r: any) => r.active !== false).length,
  gp: items.value.reduce((s, r) => s + (r.personalVolume || r.lp || 0), 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 }),
}));

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  if (!q) return items.value;
  return items.value.filter((r) => (r.personName || r.name || '').toLowerCase().includes(q));
});

function initials(name?: string) {
  return (name || '?').split(' ').slice(0, 2).map((s) => s[0] || '').join('').toUpperCase();
}
function fmtNum(n?: number) { return (n ?? 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 }); }

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const { data } = await api.get('/structure');
    items.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить структуру';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.stat-item { text-align: center; }
.stat-value { font-size: 22px; font-weight: 700; color: rgb(var(--v-theme-primary)); font-variant-numeric: tabular-nums; }
.stat-label { font-size: 11px; color: rgba(0,0,0,0.55); margin-top: 2px; }
.tree-row { margin-bottom: 6px; }
.tree-line { display: flex; align-items: center; gap: 8px; background: #fff; padding: 10px 12px; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.tree-body { flex: 1; min-width: 0; }
.tree-name { font-size: 13px; font-weight: 600; color: #1b1b1b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tree-sub { font-size: 11px; color: rgba(0,0,0,0.55); }
.empty-state { padding: 60px 24px; text-align: center; }
.empty-state .v-icon { color: rgba(0,0,0,0.2); margin-bottom: 12px; }
.empty-state-text { font-size: 14px; color: rgba(0,0,0,0.5); }
</style>
