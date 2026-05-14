<template>
  <div>
    <PageHeader title="Тех. поддержка">
      <template #actions>
        <v-btn icon="mdi-refresh" size="small" variant="text" :loading="loading" @click="load" />
      </template>
    </PageHeader>

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>

    <div v-if="loading && !data" class="text-center py-8">
      <v-progress-circular indeterminate color="warning" size="32" />
    </div>

    <template v-if="data">
      <div class="kpi-row">
        <div class="kpi-tile">
          <div class="kt-num text-error">{{ data.openIncidents ?? data.incidents?.length ?? 0 }}</div>
          <div class="kt-lbl">Инцидентов</div>
        </div>
        <div class="kpi-tile">
          <div class="kt-num text-warning">{{ data.openTickets ?? 0 }}</div>
          <div class="kt-lbl">Открытых</div>
        </div>
        <div class="kpi-tile">
          <div class="kt-num text-info">{{ data.avgFirstResponse || '—' }}</div>
          <div class="kt-lbl">FRT</div>
        </div>
        <div class="kpi-tile">
          <div class="kt-num text-success">{{ data.resolvedToday ?? 0 }}</div>
          <div class="kt-lbl">Решено</div>
        </div>
      </div>

      <v-card class="detail-card mt-3" elevation="0">
        <div class="section-title-row">
          <v-icon size="18" color="error">mdi-alert-circle</v-icon>
          <span class="section-title">Активные инциденты</span>
        </div>
        <div v-if="!incidents.length" class="text-medium-emphasis text-caption text-center py-3">
          Нет активных инцидентов
        </div>
        <div v-for="i in incidents" :key="i.id" class="incident-row">
          <v-icon :color="severityColor(i.severity)" size="18">mdi-alert</v-icon>
          <div class="i-body">
            <div class="i-title">{{ i.title || i.subject }}</div>
            <div class="i-meta">{{ i.component || '' }} · {{ i.startedAt || formatDate(i.startedAt) }}</div>
          </div>
          <v-chip :color="severityColor(i.severity)" size="x-small" variant="tonal">{{ i.severity }}</v-chip>
        </div>
      </v-card>

      <v-card class="detail-card mt-3" elevation="0">
        <div class="section-title-row">
          <v-icon size="18" color="warning">mdi-format-list-bulleted</v-icon>
          <span class="section-title">Очередь тикетов</span>
        </div>
        <div v-if="!queue.length" class="text-medium-emphasis text-caption text-center py-3">
          Тикетов в очереди нет
        </div>
        <div v-for="t in queue" :key="t.id" class="queue-row" @click="$router.push(`/app/chat/${t.id}`)">
          <v-chip :color="priorityColor(t.priority)" size="x-small" variant="tonal">{{ t.priority }}</v-chip>
          <div class="q-title">{{ t.title || t.subject }}</div>
          <span class="q-meta">{{ t.customer || t.customer_name }}</span>
        </div>
      </v-card>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

interface Incident { id: number; title?: string; subject?: string; component?: string; severity?: string; startedAt?: string }
interface QueueTicket { id: number; title?: string; subject?: string; customer?: string; customer_name?: string; priority?: string }
interface SupportData {
  openIncidents?: number; openTickets?: number; resolvedToday?: number; avgFirstResponse?: string;
  incidents?: Incident[]; queue?: QueueTicket[];
}

const data = ref<SupportData | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

const incidents = computed(() => Array.isArray(data.value?.incidents) ? data.value!.incidents! : []);
const queue = computed(() => Array.isArray(data.value?.queue) ? data.value!.queue! : []);

function severityColor(s?: string) {
  return ({ critical: 'error', high: 'warning', medium: 'info', low: 'grey' } as Record<string, string>)[s || ''] || 'grey';
}
function priorityColor(p?: string) { return severityColor(p); }
function formatDate(iso?: string) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return iso;
  return d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const { data: d } = await api.get('/support/desk');
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
.kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.kpi-tile { background: #fff; border-radius: 12px; padding: 10px 6px; text-align: center; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.kt-num { font-size: 18px; font-weight: 700; font-variant-numeric: tabular-nums; }
.kt-lbl { font-size: 10px; color: rgba(0,0,0,0.55); }
.detail-card { background: #fff; border-radius: 14px; padding: 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.section-title-row { display: flex; align-items: center; gap: 6px; margin-bottom: 10px; }
.section-title { font-size: 14px; font-weight: 600; }
.incident-row { display: flex; align-items: center; gap: 8px; padding: 10px 0; border-top: 1px solid rgba(0,0,0,0.04); }
.incident-row:first-of-type { border-top: 0; }
.i-body { flex: 1; min-width: 0; }
.i-title { font-size: 13px; font-weight: 600; }
.i-meta { font-size: 11px; color: rgba(0,0,0,0.55); }
.queue-row { display: flex; align-items: center; gap: 8px; padding: 8px 0; border-top: 1px solid rgba(0,0,0,0.04); cursor: pointer; }
.queue-row:first-of-type { border-top: 0; }
.q-title { flex: 1; font-size: 13px; }
.q-meta { font-size: 11px; color: rgba(0,0,0,0.55); }
</style>
