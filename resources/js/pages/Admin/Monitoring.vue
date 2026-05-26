<template>
  <div>
    <PageHeader title="Мониторинг системы" icon="mdi-pulse">
      <template #actions>
        <div class="d-flex align-center ga-2 flex-wrap">
          <span class="text-caption text-medium-emphasis">
            <v-icon size="14" class="mr-1">mdi-clock-outline</v-icon>
            Обновлено: {{ lastUpdatedLabel }}
          </span>
          <v-btn-toggle v-model="autoRefresh" mandatory density="comfortable" color="primary" variant="outlined">
            <v-btn :value="false" size="small">Вручную</v-btn>
            <v-btn :value="true" size="small">Авто 30с</v-btn>
          </v-btn-toggle>
          <v-btn color="primary" prepend-icon="mdi-refresh" size="small" :loading="loadingStatus"
            variant="tonal" @click="refreshAll">Обновить</v-btn>
        </div>
      </template>
    </PageHeader>

    <!-- SERVICE TILES -->
    <v-row dense>
      <v-col v-for="s in serviceTiles" :key="s.key" cols="12" sm="6" md="3">
        <v-card class="pa-4 h-100" :class="s.ok ? 'tile-ok' : 'tile-fail'">
          <div class="d-flex align-center ga-2 mb-2">
            <v-icon :color="s.ok ? 'success' : 'error'" size="22">{{ s.icon }}</v-icon>
            <div class="text-subtitle-2 font-weight-bold">{{ s.label }}</div>
            <v-spacer />
            <v-chip size="x-small" :color="s.ok ? 'success' : 'error'" variant="tonal">
              {{ s.ok ? 'OK' : 'FAIL' }}
            </v-chip>
          </div>
          <div class="text-caption text-medium-emphasis">{{ s.detail }}</div>
        </v-card>
      </v-col>
    </v-row>

    <!-- COUNTERS -->
    <div class="ds-kpi-row mt-1">
      <div class="ds-kpi">
        <div class="ds-kpi__label">Ошибки (24ч)</div>
        <div class="ds-kpi__value" :class="(status.errors24h?.total || 0) > 0 ? 'text-error' : 'text-success'">
          {{ status.errors24h?.total ?? 0 }}
        </div>
      </div>
      <div class="ds-kpi">
        <div class="ds-kpi__label">Очередь: ожидание</div>
        <div class="ds-kpi__value">{{ status.services?.queue?.pending ?? 0 }}</div>
      </div>
      <div class="ds-kpi">
        <div class="ds-kpi__label">Очередь: неудачи</div>
        <div class="ds-kpi__value" :class="(status.services?.queue?.failed || 0) > 0 ? 'text-warning' : ''">
          {{ status.services?.queue?.failed ?? 0 }}
        </div>
      </div>
      <div class="ds-kpi">
        <div class="ds-kpi__label">Активные сессии (15мин)</div>
        <div class="ds-kpi__value">{{ status.activeSessions ?? 0 }}</div>
      </div>
    </div>

    <!-- ERROR FEED -->
    <v-card class="mt-4">
      <div class="pa-4 d-flex align-center ga-2 flex-wrap">
        <div class="text-subtitle-1 font-weight-bold">Лента ошибок</div>
        <v-chip size="small" variant="tonal">{{ errors.length }}</v-chip>
        <v-spacer />
        <v-btn-toggle v-model="errorSource" mandatory density="comfortable" color="primary" variant="outlined">
          <v-btn value="all" size="small">Все</v-btn>
          <v-btn value="failed_jobs" size="small">Очередь</v-btn>
          <v-btn value="mail" size="small">Почта</v-btn>
          <v-btn value="system" size="small">Система</v-btn>
          <v-btn value="n8n" size="small">n8n</v-btn>
        </v-btn-toggle>
        <v-btn v-if="status.services?.queue?.failed > 0" size="small" color="error"
          variant="tonal" prepend-icon="mdi-broom" @click="confirmFlush">
          Очистить failed_jobs
        </v-btn>
        <ColumnVisibilityMenu
          :headers="errorHeaders"
          v-model:visible="errorColumnVisible"
          storage-key="monitoring-errors-cols" />
      </div>
      <v-divider />
      <v-data-table :items="errors" :headers="visibleErrorHeaders" :loading="loadingErrors"
        density="compact" hover no-data-text="Ошибок не зафиксировано" :items-per-page="50">
        <template #item.source="{ value }">
          <v-chip size="x-small" :color="sourceColor(value)">{{ sourceLabel(value) }}</v-chip>
        </template>
        <template #item.at="{ value }">
          <span class="text-caption">{{ fmtDateTime(value) }}</span>
        </template>
        <template #item.message="{ value }">
          <span class="text-body-2">{{ value }}</span>
        </template>
        <template #item.actions="{ item }">
          <v-btn icon="mdi-eye" size="x-small" variant="text" title="Подробности" @click="openDetail(item)" />
          <template v-if="item.source === 'queue'">
            <v-btn icon="mdi-refresh" size="x-small" variant="text" color="primary"
              title="Повторить" @click="retryJob(item.raw_id)" />
            <v-btn icon="mdi-close" size="x-small" variant="text" color="error"
              title="Удалить" @click="forgetJob(item.raw_id)" />
          </template>
        </template>
      </v-data-table>
    </v-card>

    <!-- LOG FILE (laravel.log) -->
    <v-card class="mt-4">
      <div class="pa-4 d-flex align-center ga-2 flex-wrap">
        <div class="text-subtitle-1 font-weight-bold">Журнал приложения</div>
        <v-chip size="small" variant="tonal">{{ logErrors.length }}</v-chip>
        <span v-if="logMeta.fileSizeHuman" class="text-caption text-medium-emphasis">
          · файл: {{ logMeta.fileSizeHuman }}
          <span v-if="logMeta.truncated">(показан последний 1 MB)</span>
        </span>
        <v-spacer />
        <v-btn-toggle v-model="logLevel" mandatory density="comfortable" color="primary" variant="outlined">
          <v-btn value="ALL_ERRORS" size="small">Ошибки + варнинги</v-btn>
          <v-btn value="ERROR" size="small">Только ERROR</v-btn>
          <v-btn value="WARNING" size="small">Только WARNING</v-btn>
          <v-btn value="ALL" size="small">Все записи</v-btn>
        </v-btn-toggle>
        <v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-refresh"
          :loading="loadingLogErrors" @click="loadLogErrors">Обновить</v-btn>
        <v-btn size="small" variant="flat" color="primary" prepend-icon="mdi-download"
          :loading="downloadingLog" @click="downloadLog">Скачать .log</v-btn>
        <v-btn size="small" variant="tonal" color="error" prepend-icon="mdi-broom"
          @click="clearLogDialog = true">Очистить</v-btn>
      </div>
      <v-divider />
      <v-data-table :items="logErrors" :headers="logHeaders" :loading="loadingLogErrors"
        density="compact" hover no-data-text="В логе нет записей по выбранному фильтру"
        :items-per-page="50">
        <template #item.level="{ value }">
          <v-chip size="x-small" :color="levelColor(value)">{{ value }}</v-chip>
        </template>
        <template #item.timestamp="{ value }">
          <span class="text-caption">{{ value }}</span>
        </template>
        <template #item.message="{ value }">
          <span class="text-body-2">{{ value }}</span>
        </template>
        <template #item.actions="{ item }">
          <v-btn icon="mdi-eye" size="x-small" variant="text" title="Подробности"
            @click="openLogDetail(item)" />
        </template>
      </v-data-table>
    </v-card>

    <!-- Log detail dialog -->
    <v-dialog v-model="logDetailDialog" max-width="900" scrollable>
      <v-card v-if="logDetailItem">
        <v-card-title class="d-flex align-center ga-2">
          <v-chip size="small" :color="levelColor(logDetailItem.level)">{{ logDetailItem.level }}</v-chip>
          <span class="text-body-2 text-medium-emphasis">{{ logDetailItem.timestamp }}</span>
        </v-card-title>
        <v-card-text style="max-height: 70vh">
          <pre class="detail-pre">{{ logDetailItem.detail }}</pre>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="logDetailDialog = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Clear log confirm -->
    <v-dialog v-model="clearLogDialog" max-width="420">
      <v-card>
        <v-card-title>Очистить laravel.log?</v-card-title>
        <v-card-text>
          Файл будет усечён до 0 байт. Перед этим рекомендуется скачать копию.
          Действие необратимо.
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="clearLogDialog = false">Отмена</v-btn>
          <v-btn color="error" :loading="clearingLog" @click="doClearLog">Очистить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- META FOOTER -->
    <div class="text-caption text-medium-emphasis mt-3">
      <v-icon size="14" class="mr-1">mdi-database</v-icon>
      БД: {{ status.dbSize?.formatted || '—' }} ·
      <v-icon size="14" class="mx-1">mdi-language-php</v-icon>
      PHP {{ status.php?.version || '' }} ·
      <v-icon size="14" class="mx-1">mdi-memory</v-icon>
      {{ status.php?.memoryUsageMb || 0 }} MB ·
      <v-icon size="14" class="mx-1">mdi-cog-outline</v-icon>
      ENV: {{ status.laravel?.env || '' }}<span v-if="status.laravel?.debug"> (debug ON)</span>
    </div>

    <!-- Detail dialog -->
    <v-dialog v-model="detailDialog" max-width="720" scrollable>
      <v-card v-if="detailItem">
        <v-card-title class="d-flex align-center ga-2">
          <v-chip size="small" :color="sourceColor(detailItem.source)">{{ sourceLabel(detailItem.source) }}</v-chip>
          {{ detailItem.title }}
        </v-card-title>
        <v-card-text style="max-height: 70vh">
          <div class="text-caption text-medium-emphasis mb-2">{{ fmtDateTime(detailItem.at) }}</div>
          <div class="text-body-2 mb-3"><strong>{{ detailItem.message }}</strong></div>
          <pre class="detail-pre">{{ detailItem.detail }}</pre>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="detailDialog = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Flush confirm -->
    <v-dialog v-model="flushDialog" max-width="420">
      <v-card>
        <v-card-title>Очистить failed_jobs?</v-card-title>
        <v-card-text>
          Будут удалены все записи из таблицы failed_jobs
          ({{ status.services?.queue?.failed || 0 }} шт.). Действие необратимо.
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="flushDialog = false">Отмена</v-btn>
          <v-btn color="error" :loading="flushing" @click="doFlush">Очистить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import api from '../../api';
import { fmtDateTime } from '../../composables/useDesign';
import PageHeader from '../../components/PageHeader.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';

const status = ref({});
const errors = ref([]);
const loadingStatus = ref(false);
const loadingErrors = ref(false);
const errorSource = ref('all');
const autoRefresh = ref(true);
const lastUpdated = ref(null);

const detailDialog = ref(false);
const detailItem = ref(null);

const flushDialog = ref(false);
const flushing = ref(false);

// Журнал приложения (laravel.log)
const logErrors = ref([]);
const logMeta = ref({});
const logLevel = ref('ALL_ERRORS');
const loadingLogErrors = ref(false);
const downloadingLog = ref(false);
const logDetailDialog = ref(false);
const logDetailItem = ref(null);
const clearLogDialog = ref(false);
const clearingLog = ref(false);

const logHeaders = [
  { title: 'Уровень', key: 'level', width: 110 },
  { title: 'Когда', key: 'timestamp', width: 170 },
  { title: 'Сообщение', key: 'message' },
  { title: '', key: 'actions', sortable: false, width: 60 },
];

let refreshTimer = null;

const errorHeaders = [
  { title: 'Источник', key: 'source', width: 120 },
  { title: 'Когда', key: 'at', width: 180 },
  { title: 'Заголовок', key: 'title', width: 280 },
  { title: 'Сообщение', key: 'message' },
  { title: '', key: 'actions', sortable: false, width: 130 },
];

const errorColumnVisible = ref({});
const visibleErrorHeaders = computed(() =>
  errorHeaders.filter(h => errorColumnVisible.value[h.key] !== false)
);

const serviceTiles = computed(() => {
  const s = status.value?.services || {};
  return [
    {
      key: 'db',
      icon: 'mdi-database',
      label: 'База данных',
      ok: s.database?.ok,
      detail: s.database?.error
        ? s.database.error
        : `${s.database?.latencyMs ?? 0} ms`,
    },
    {
      key: 'cache',
      icon: 'mdi-speedometer',
      label: 'Кеш',
      ok: s.cache?.ok,
      detail: s.cache?.error
        ? s.cache.error
        : `${s.cache?.latencyMs ?? 0} ms`,
    },
    {
      key: 'queue',
      icon: 'mdi-format-list-checks',
      label: 'Очередь',
      ok: (s.queue?.failed ?? 0) < 100,
      detail: `ожидание: ${s.queue?.pending ?? 0} · ошибок всего: ${s.queue?.failed ?? 0}`,
    },
    {
      key: 'mail',
      icon: 'mdi-email-outline',
      label: 'Почта',
      ok: s.mail?.ok,
      detail: s.mail?.configured
        ? `24ч: ${s.mail?.sent24h ?? 0} отправлено, ${s.mail?.failed24h ?? 0} ошибок`
        : 'SMTP не настроен — /admin/mail',
    },
  ];
});

const lastUpdatedLabel = computed(() => {
  if (!lastUpdated.value) return '—';
  const d = new Date(lastUpdated.value);
  return d.toLocaleTimeString('ru-RU');
});

function sourceLabel(s) {
  return { queue: 'Очередь', mail: 'Почта', system: 'Система', n8n: 'n8n' }[s] || s;
}
function sourceColor(s) {
  return { queue: 'warning', mail: 'info', system: 'error', n8n: 'secondary' }[s] || 'grey';
}

async function loadStatus() {
  loadingStatus.value = true;
  try {
    const { data } = await api.get('/admin/monitoring/status');
    status.value = data;
    lastUpdated.value = data.generatedAt;
  } catch {}
  loadingStatus.value = false;
}

async function loadErrors() {
  loadingErrors.value = true;
  try {
    const { data } = await api.get('/admin/monitoring/errors', {
      params: { source: errorSource.value, limit: 100 },
    });
    errors.value = data.items || [];
  } catch {}
  loadingErrors.value = false;
}

async function loadLogErrors() {
  loadingLogErrors.value = true;
  try {
    const { data } = await api.get('/admin/monitoring/log/errors', {
      params: { level: logLevel.value, limit: 300 },
    });
    logErrors.value = data.items || [];
    logMeta.value = {
      fileSize: data.fileSize,
      fileSizeHuman: data.fileSizeHuman,
      mtime: data.mtime,
      truncated: data.truncated,
    };
  } catch {}
  loadingLogErrors.value = false;
}

function levelColor(lvl) {
  return {
    ERROR: 'error', CRITICAL: 'error', EMERGENCY: 'error', ALERT: 'error',
    WARNING: 'warning', NOTICE: 'info', INFO: 'info', DEBUG: 'grey',
  }[lvl] || 'grey';
}

function openLogDetail(item) {
  logDetailItem.value = item;
  logDetailDialog.value = true;
}

async function downloadLog() {
  downloadingLog.value = true;
  try {
    // responseType: blob — иначе axios попытается JSON-парсить лог и упадёт.
    const resp = await api.get('/admin/monitoring/log/download', {
      responseType: 'blob',
    });
    const blob = new Blob([resp.data], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `laravel-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.log`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  } catch {}
  downloadingLog.value = false;
}

async function doClearLog() {
  clearingLog.value = true;
  try {
    await api.post('/admin/monitoring/log/clear');
    clearLogDialog.value = false;
    await loadLogErrors();
  } catch {}
  clearingLog.value = false;
}

async function refreshAll() {
  await Promise.all([loadStatus(), loadErrors(), loadLogErrors()]);
}

function openDetail(item) {
  detailItem.value = item;
  detailDialog.value = true;
}

async function retryJob(id) {
  try {
    await api.post(`/admin/monitoring/jobs/${id}/retry`);
    await refreshAll();
  } catch {}
}

async function forgetJob(id) {
  try {
    await api.delete(`/admin/monitoring/jobs/${id}`);
    await refreshAll();
  } catch {}
}

function confirmFlush() {
  flushDialog.value = true;
}

async function doFlush() {
  flushing.value = true;
  try {
    await api.post('/admin/monitoring/jobs/flush');
    flushDialog.value = false;
    await refreshAll();
  } catch {}
  flushing.value = false;
}

watch(errorSource, loadErrors);
watch(logLevel, loadLogErrors);

watch(autoRefresh, (v) => {
  if (refreshTimer) clearInterval(refreshTimer);
  if (v) refreshTimer = setInterval(refreshAll, 30000);
});

onMounted(() => {
  refreshAll();
  if (autoRefresh.value) refreshTimer = setInterval(refreshAll, 30000);
});

onUnmounted(() => {
  if (refreshTimer) clearInterval(refreshTimer);
});
</script>

<style scoped>
.tile-ok {
  border-left: 3px solid rgb(var(--v-theme-success));
}
.tile-fail {
  border-left: 3px solid rgb(var(--v-theme-error));
}
.detail-pre {
  white-space: pre-wrap;
  word-break: break-word;
  font-family: ui-monospace, Menlo, Consolas, monospace;
  font-size: 12px;
  background: rgba(var(--v-theme-on-surface), 0.04);
  padding: 12px;
  border-radius: var(--ds-radius-sm, 6px);
  max-height: 50vh;
  overflow: auto;
}
.ds-kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
@media (max-width: 960px) { .ds-kpi-row { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 700px) { .ds-kpi-row { grid-template-columns: repeat(2, 1fr); } }
</style>
