<template>
  <div>
    <PageHeader title="Статус системы" icon="mdi-monitor-dashboard" />

    <!-- Overall banner -->
    <v-card :class="['pa-4 mb-4 d-flex align-center ga-3 overall-banner', `overall-${overall.status}`]"
      variant="flat">
      <v-icon size="32" color="white">{{ overallIcon }}</v-icon>
      <div class="text-white">
        <div class="text-h6 font-weight-bold">{{ overall.label || 'Загрузка...' }}</div>
        <div class="text-caption" style="opacity: 0.9">Обновлено {{ updatedAt }}</div>
      </div>
      <v-spacer />
      <v-btn v-if="auth.isAdmin" color="white" variant="outlined" size="small"
        prepend-icon="mdi-cog" to="/manage/system-status">Управление</v-btn>
    </v-card>

    <!-- Components grid -->
    <v-card class="mb-4 pa-3">
      <div class="text-subtitle-1 font-weight-bold mb-2">Компоненты</div>
      <div v-if="!components.length" class="text-medium-emphasis py-3">
        Компоненты не настроены.
      </div>
      <v-list density="comfortable" v-else>
        <v-list-item v-for="c in components" :key="c.id"
          class="component-row mb-1 rounded">
          <div class="d-flex align-center ga-3 w-100 py-1">
            <v-icon :color="statusColor(c.status)" size="20">{{ statusIcon(c.status) }}</v-icon>
            <div class="flex-grow-1 min-w-0">
              <div class="font-weight-medium">{{ c.name }}</div>
              <div v-if="c.description" class="text-caption text-medium-emphasis">{{ c.description }}</div>
            </div>
            <v-chip :color="statusColor(c.status)" size="small" variant="tonal">
              {{ statusLabel(c.status) }}
            </v-chip>
          </div>
        </v-list-item>
      </v-list>
    </v-card>

    <!-- Active incidents -->
    <v-card v-if="active.length" class="mb-4 pa-3">
      <div class="text-subtitle-1 font-weight-bold mb-2 d-flex align-center ga-2">
        <v-icon color="warning">mdi-alert-circle</v-icon>
        Активные инциденты
      </div>
      <v-list density="comfortable">
        <v-list-item v-for="i in active" :key="i.id" class="incident-row mb-1 rounded pa-3">
          <div class="d-flex align-start ga-3">
            <v-chip :color="severityColor(i.severity)" size="x-small" variant="flat" class="mt-1">
              {{ severityLabel(i.severity) }}
            </v-chip>
            <div class="flex-grow-1">
              <div class="font-weight-medium">{{ i.title }}</div>
              <div v-if="i.description" class="text-body-2 text-medium-emphasis mt-1">{{ i.description }}</div>
              <div class="text-caption text-medium-emphasis mt-1">
                Начало: {{ fmtDateTime(i.started_at) }} · Статус: {{ incidentStatusLabel(i.status) }}
              </div>
              <!-- Timeline апдейтов: новые сверху. Каждый — статус + сообщение + время. -->
              <div v-if="i.updates?.length" class="incident-timeline mt-3">
                <div v-for="u in [...i.updates].reverse()" :key="u.id" class="timeline-entry">
                  <div class="d-flex align-center ga-2">
                    <v-chip size="x-small" variant="tonal">{{ incidentStatusLabel(u.status) }}</v-chip>
                    <span class="text-caption text-medium-emphasis">{{ fmtDateTime(u.created_at) }}</span>
                  </div>
                  <div class="text-body-2 mt-1">{{ u.message }}</div>
                </div>
              </div>
            </div>
          </div>
        </v-list-item>
      </v-list>
    </v-card>

    <!-- History -->
    <v-card class="pa-3">
      <div class="text-subtitle-1 font-weight-bold mb-2">История</div>
      <div v-if="!history.length" class="text-medium-emphasis py-3">
        История пуста.
      </div>
      <v-list density="comfortable" v-else>
        <v-list-item v-for="h in history" :key="h.id" class="incident-row mb-1 rounded pa-3">
          <div class="d-flex align-start ga-3">
            <v-icon color="success" size="20" class="mt-1">mdi-check-circle</v-icon>
            <div class="flex-grow-1">
              <div class="font-weight-medium">{{ h.title }}</div>
              <div v-if="h.description" class="text-body-2 text-medium-emphasis mt-1">{{ h.description }}</div>
              <div class="text-caption text-medium-emphasis mt-1">
                {{ fmtDateTime(h.started_at) }} → {{ fmtDateTime(h.resolved_at) }}
              </div>
            </div>
          </div>
        </v-list-item>
      </v-list>
    </v-card>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api';
import PageHeader from '../components/PageHeader.vue';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const overall = ref({ status: 'operational', label: '' });
const components = ref([]);
const active = ref([]);
const history = ref([]);
const updatedAt = ref('');

async function load() {
  try {
    const { data } = await api.get('/system-status');
    overall.value = data.overall || { status: 'operational', label: '' };
    components.value = data.components || [];
    active.value = data.active || [];
    history.value = data.history || [];
    updatedAt.value = new Date().toLocaleTimeString('ru-RU');
  } catch {}
}

const overallIcon = computed(() => statusIcon(overall.value.status));

function statusColor(s) {
  return {
    operational: 'success',
    maintenance: 'info',
    degraded: 'warning',
    partial_outage: 'orange',
    major_outage: 'error',
  }[s] || 'grey';
}
function statusIcon(s) {
  return {
    operational: 'mdi-check-circle',
    maintenance: 'mdi-tools',
    degraded: 'mdi-alert',
    partial_outage: 'mdi-alert-octagon',
    major_outage: 'mdi-close-octagon',
  }[s] || 'mdi-help-circle';
}
function statusLabel(s) {
  return {
    operational: 'Работает',
    maintenance: 'Тех. работы',
    degraded: 'Замедление',
    partial_outage: 'Частичный сбой',
    major_outage: 'Серьёзный сбой',
  }[s] || s;
}
function severityColor(s) {
  return { minor: 'warning', major: 'orange', critical: 'error', maintenance: 'info' }[s] || 'grey';
}
function severityLabel(s) {
  return { minor: 'Незначительно', major: 'Серьёзно', critical: 'Критично', maintenance: 'Тех. работы' }[s] || s;
}
function incidentStatusLabel(s) {
  return {
    investigating: 'Расследуется', identified: 'Причина найдена',
    monitoring: 'Мониторинг', resolved: 'Решено',
    scheduled: 'Запланировано', in_progress: 'В процессе', completed: 'Завершено',
  }[s] || s;
}
function fmtDateTime(v) {
  if (!v) return '—';
  try { return new Date(v).toLocaleString('ru-RU'); } catch { return v; }
}

onMounted(() => {
  load();
  // Автообновление раз в минуту — статус-страница должна показывать актуальное.
  setInterval(load, 60000);
});
</script>

<style scoped>
.component-row {
  background: rgba(var(--v-theme-surface-variant), 0.4);
}
.incident-row {
  background: rgba(var(--v-theme-surface-variant), 0.3);
}
.overall-banner {
  color: #fff !important;
  border-left: 8px solid rgba(255, 255, 255, 0.35);
}
.overall-operational    { background: linear-gradient(135deg, #43a047 0%, #1b5e20 100%) !important; }
.overall-maintenance    { background: linear-gradient(135deg, #1e88e5 0%, #1565c0 100%) !important; }
.overall-degraded       { background: linear-gradient(135deg, #f5a623 0%, #d77c00 100%) !important; }
.overall-partial_outage { background: linear-gradient(135deg, #fb8c00 0%, #e65100 100%) !important; }
.overall-major_outage   { background: linear-gradient(135deg, #e53935 0%, #b71c1c 100%) !important; }
.incident-timeline {
  border-left: 2px solid rgba(var(--v-theme-primary), 0.4);
  padding-left: 12px;
  margin-left: 4px;
}
.timeline-entry {
  padding: 6px 0;
}
.timeline-entry + .timeline-entry {
  border-top: 1px dashed rgba(var(--v-theme-on-surface), 0.1);
}
</style>
