<template>
  <div>
    <PageHeader title="Отчёты">
      <template #actions>
        <v-btn icon="mdi-refresh" size="small" variant="text" :loading="loading" @click="load" />
      </template>
    </PageHeader>

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>

    <v-card class="detail-card" elevation="0">
      <div class="section-title-row">
        <v-icon size="18" color="warning">mdi-chart-box-outline</v-icon>
        <span class="section-title">Доступные отчёты</span>
      </div>
      <div v-if="loading" class="text-center py-4">
        <v-progress-circular indeterminate color="warning" size="24" />
      </div>
      <div v-else-if="!reports.length" class="text-medium-emphasis text-caption text-center py-3">
        Нет доступных отчётов
      </div>
      <div v-else>
        <div v-for="r in reports" :key="r.id" class="report-row">
          <v-icon :color="r.color || 'warning'" size="22">{{ r.icon || 'mdi-file-chart-outline' }}</v-icon>
          <div class="r-body">
            <div class="r-title">{{ r.title || r.name }}</div>
            <div class="r-sub">{{ r.description || '' }}</div>
          </div>
          <v-btn icon="mdi-download" size="small" variant="text" />
        </div>
      </div>
    </v-card>

    <v-card class="detail-card mt-3" elevation="0">
      <div class="section-title-row">
        <v-icon size="18" color="warning">mdi-history</v-icon>
        <span class="section-title">Архив</span>
      </div>
      <div v-if="loadingArchive" class="text-center py-4">
        <v-progress-circular indeterminate color="warning" size="24" />
      </div>
      <div v-else-if="!archive.length" class="text-medium-emphasis text-caption text-center py-3">
        Архив пуст
      </div>
      <div v-else>
        <div v-for="h in archive" :key="h.id" class="history-row">
          <div>
            <div class="h-title">{{ h.title || h.name }}</div>
            <div class="h-sub">{{ formatDate(h.createdAt) }} {{ h.author ? '· ' + h.author : '' }}</div>
          </div>
          <v-btn icon="mdi-download" size="small" variant="text" />
        </div>
      </div>
    </v-card>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

interface ReportItem { id: number; title?: string; name?: string; description?: string; icon?: string; color?: string }
interface ArchiveItem { id: number; title?: string; name?: string; createdAt?: string; author?: string }

const reports = ref<ReportItem[]>([]);
const archive = ref<ArchiveItem[]>([]);
const loading = ref(true);
const loadingArchive = ref(true);
const error = ref<string | null>(null);

function formatDate(iso?: string) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return iso;
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

async function load() {
  loading.value = true;
  loadingArchive.value = true;
  error.value = null;
  try {
    const [r, a] = await Promise.all([
      api.get('/admin/reports').catch(() => ({ data: [] })),
      api.get('/admin/reports/archive').catch(() => ({ data: [] })),
    ]);
    reports.value = Array.isArray(r.data?.data) ? r.data.data : (Array.isArray(r.data) ? r.data : []);
    archive.value = Array.isArray(a.data?.data) ? a.data.data : (Array.isArray(a.data) ? a.data : []);
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить';
  } finally {
    loading.value = false;
    loadingArchive.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.detail-card { background: #fff; border-radius: 14px; padding: 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.section-title-row { display: flex; align-items: center; gap: 6px; margin-bottom: 10px; }
.section-title { font-size: 14px; font-weight: 600; }
.report-row, .history-row { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid rgba(0,0,0,0.04); }
.report-row:last-child, .history-row:last-child { border-bottom: 0; }
.r-body, .history-row > div:first-child { flex: 1; }
.r-title, .h-title { font-size: 13px; font-weight: 600; }
.r-sub, .h-sub { font-size: 11px; color: rgba(0,0,0,0.55); }
</style>
