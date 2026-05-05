<template>
  <div>
    <PageHeader title="Интеграции" icon="mdi-cloud-sync">
      <template #actions>
        <v-btn variant="tonal" prepend-icon="mdi-refresh" :loading="loadingServices" @click="loadServices">
          Обновить
        </v-btn>
      </template>
    </PageHeader>

    <v-tabs v-model="tab" density="compact" color="primary" class="mb-3">
      <v-tab value="services" prepend-icon="mdi-server">Сервисы</v-tab>
      <v-tab value="events" prepend-icon="mdi-history">Журнал событий</v-tab>
      <v-tab value="config" prepend-icon="mdi-cog">Настройки</v-tab>
    </v-tabs>

    <v-window v-model="tab">
      <!-- ───── Сервисы ───── -->
      <v-window-item value="services">
        <v-row dense>
          <v-col v-for="s in services" :key="s.key" cols="12" md="6" lg="4">
            <v-card variant="outlined" class="pa-3 h-100 d-flex flex-column">
              <div class="d-flex align-center ga-3">
                <v-avatar :color="serviceColor(s)" size="42" variant="tonal">
                  <v-icon size="24">{{ s.icon }}</v-icon>
                </v-avatar>
                <div class="flex-grow-1">
                  <div class="text-body-1 font-weight-bold">{{ s.label }}</div>
                  <div class="text-caption text-medium-emphasis">{{ categoryLabel(s.category) }}</div>
                </div>
                <v-chip :color="s.configured ? 'success' : 'warning'" size="x-small" variant="tonal">
                  {{ s.configured ? 'Настроен' : 'Не настроен' }}
                </v-chip>
              </div>

              <v-divider class="my-3" />

              <div class="text-caption text-medium-emphasis mb-1">Метрики за 24 часа</div>
              <div class="d-flex flex-wrap ga-2 mb-2">
                <v-chip size="x-small" variant="tonal" color="primary">
                  Всего: <strong class="ms-1">{{ s.metrics_24h.total }}</strong>
                </v-chip>
                <v-chip v-if="s.metrics_24h.success > 0" size="x-small" variant="tonal" color="success">
                  Успех: {{ s.metrics_24h.success }}
                </v-chip>
                <v-chip v-if="s.metrics_24h.errors > 0" size="x-small" variant="tonal" color="error">
                  Ошибок: {{ s.metrics_24h.errors }}
                </v-chip>
                <v-chip v-if="s.metrics_24h.success_rate != null" size="x-small" variant="tonal"
                  :color="rateColor(s.metrics_24h.success_rate)">
                  Success-rate: {{ s.metrics_24h.success_rate }}%
                </v-chip>
              </div>
              <div v-if="s.metrics_24h.avg_ms != null" class="text-caption text-medium-emphasis">
                Avg: {{ s.metrics_24h.avg_ms }} мс · p95: {{ s.metrics_24h.p95_ms || '—' }} мс
              </div>
              <div v-if="s.metrics_24h.last_at" class="text-caption text-medium-emphasis">
                Последнее: {{ formatDate(s.metrics_24h.last_at) }}
              </div>
              <div v-else class="text-caption text-disabled">Нет событий за 24 ч</div>

              <v-spacer />
              <div class="d-flex ga-2 mt-3">
                <v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-pulse"
                  :loading="testing[s.key]" @click="runTest(s)">
                  Тест соединения
                </v-btn>
                <v-btn size="small" variant="text" prepend-icon="mdi-cog" @click="openConfig(s)">
                  Настроить
                </v-btn>
              </div>
            </v-card>
          </v-col>
        </v-row>
      </v-window-item>

      <!-- ───── Журнал событий ───── -->
      <v-window-item value="events">
        <v-card class="mb-3 pa-3">
          <div class="d-flex flex-wrap ga-2 align-center">
            <v-text-field v-model="evFilters.q" placeholder="Поиск по summary / external_id / action"
              density="compact" variant="outlined" hide-details rounded clearable
              prepend-inner-icon="mdi-magnify" style="max-width:340px"
              @update:model-value="onEvFilterChange" />
            <v-select v-model="evFilters.service" :items="serviceOptions" placeholder="Сервис"
              clearable density="compact" variant="outlined" hide-details style="max-width:200px"
              @update:model-value="onEvFilterChange" />
            <v-select v-model="evFilters.direction" :items="['inbound','outbound']" placeholder="Направление"
              clearable density="compact" variant="outlined" hide-details style="max-width:160px"
              @update:model-value="onEvFilterChange" />
            <v-select v-model="evFilters.status" :items="['success','error','pending']" placeholder="Статус"
              clearable density="compact" variant="outlined" hide-details style="max-width:140px"
              @update:model-value="onEvFilterChange" />
            <v-spacer />
            <span v-if="evTotal" class="text-caption text-medium-emphasis">Найдено: <strong>{{ evTotal }}</strong></span>
          </div>
        </v-card>

        <v-card>
          <v-data-table-server v-model:items-per-page="evPerPage" v-model:page="evPage"
            :items="evRows" :headers="evHeaders" :items-length="evTotal" :loading="loadingEvents"
            density="comfortable" hover @update:options="loadEvents">
            <template #item.service="{ item }">
              <v-chip size="x-small" variant="tonal" color="primary">{{ item.service }}</v-chip>
            </template>
            <template #item.direction="{ item }">
              <v-icon size="16" :color="item.direction === 'inbound' ? 'info' : 'amber-darken-2'">
                {{ item.direction === 'inbound' ? 'mdi-arrow-down-bold' : 'mdi-arrow-up-bold' }}
              </v-icon>
              <span class="text-caption ms-1">{{ item.direction }}</span>
            </template>
            <template #item.status="{ item }">
              <v-chip size="x-small" :color="statusColor(item.status)" variant="tonal">{{ item.status }}</v-chip>
            </template>
            <template #item.duration_ms="{ item }">
              <span v-if="item.duration_ms != null" class="text-caption">{{ item.duration_ms }} мс</span>
              <span v-else class="text-disabled">—</span>
            </template>
            <template #item.created_at="{ item }">
              <span class="text-caption">{{ formatDate(item.created_at) }}</span>
            </template>
            <template #item.actions="{ item }">
              <v-btn size="x-small" variant="text" prepend-icon="mdi-eye" @click="openEvent(item)">
                Подробно
              </v-btn>
            </template>
            <template #no-data>
              <EmptyState message="Событий не найдено" />
            </template>
          </v-data-table-server>
        </v-card>
      </v-window-item>

      <!-- ───── Настройки ───── -->
      <v-window-item value="config">
        <v-row dense>
          <v-col v-for="s in services" :key="'cfg-'+s.key" cols="12" md="6">
            <v-card variant="outlined" class="pa-3">
              <div class="d-flex align-center ga-2 mb-3">
                <v-icon :color="serviceColor(s)">{{ s.icon }}</v-icon>
                <span class="text-body-1 font-weight-bold">{{ s.label }}</span>
                <v-spacer />
                <v-btn size="x-small" variant="text" prepend-icon="mdi-cog" @click="openConfig(s)">
                  Открыть
                </v-btn>
              </div>
              <div class="text-caption text-medium-emphasis">{{ configHint(s.key) }}</div>
            </v-card>
          </v-col>
        </v-row>
      </v-window-item>
    </v-window>

    <!-- ───── Диалог: Подробности события ───── -->
    <v-dialog v-model="eventDialog" max-width="900" scrollable>
      <v-card v-if="currentEvent">
        <v-card-title class="d-flex align-center ga-2">
          <v-chip size="small" color="primary" variant="tonal">{{ currentEvent.service }}</v-chip>
          <v-chip size="small" :color="statusColor(currentEvent.status)" variant="tonal">{{ currentEvent.status }}</v-chip>
          <span class="text-body-2 ms-2">{{ currentEvent.action }}</span>
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" size="small" @click="eventDialog = false" />
        </v-card-title>

        <v-card-text>
          <div class="text-caption text-medium-emphasis mb-2">
            {{ formatDate(currentEvent.created_at) }}
            <span v-if="currentEvent.duration_ms != null"> · {{ currentEvent.duration_ms }} мс</span>
            <span v-if="currentEvent.ip"> · {{ currentEvent.ip }}</span>
            <span v-if="currentEvent.external_id"> · external_id: {{ currentEvent.external_id }}</span>
          </div>
          <v-alert v-if="currentEvent.summary" :type="currentEvent.status === 'error' ? 'error' : 'info'"
            density="compact" variant="tonal" class="mb-3">
            {{ currentEvent.summary }}
          </v-alert>

          <div class="text-subtitle-2 mb-1">Request</div>
          <pre class="payload-block">{{ pretty(currentEvent.request) }}</pre>

          <div class="text-subtitle-2 mt-3 mb-1">Response</div>
          <pre class="payload-block">{{ pretty(currentEvent.response) }}</pre>
        </v-card-text>

        <v-card-actions>
          <v-btn v-if="currentEvent.direction === 'inbound' && currentEvent.service === 'insmart'"
            color="warning" variant="tonal" prepend-icon="mdi-replay" :loading="replaying"
            @click="replayEvent">
            Replay webhook
          </v-btn>
          <v-spacer />
          <v-btn variant="text" @click="eventDialog = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- ───── Диалог: Настройка сервиса ───── -->
    <v-dialog v-model="configDialog" max-width="700" scrollable>
      <v-card v-if="currentConfig">
        <v-card-title class="d-flex align-center ga-2">
          <v-icon>{{ currentConfig.service.icon }}</v-icon>
          {{ currentConfig.service.label }} — настройка
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" size="small" @click="configDialog = false" />
        </v-card-title>

        <v-card-text>
          <div v-if="!Object.keys(currentConfig.values).length" class="text-medium-emphasis pa-4 text-center">
            Настройки этого сервиса хранятся вне общего каталога
            (см. подсказку на карточке).
          </div>
          <div v-for="(meta, key) in currentConfig.values" :key="key" class="mb-3">
            <v-text-field v-model="currentConfig.draft[key]"
              :label="settingLabel(key)" :placeholder="meta.has_value ? meta.value : 'Не задано'"
              :type="isSecretKey(key) ? 'password' : 'text'"
              :hint="isSecretKey(key) && meta.has_value ? `Текущее: ${meta.value}` : ''"
              persistent-hint density="compact" variant="outlined" clearable />
          </div>
        </v-card-text>

        <v-card-actions>
          <v-btn variant="text" @click="configDialog = false">Отмена</v-btn>
          <v-spacer />
          <v-btn color="primary" variant="flat" prepend-icon="mdi-content-save"
            :loading="savingConfig" :disabled="!hasChanges" @click="saveConfig">
            Сохранить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="4000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import { useDebounce } from '../../composables/useDebounce';

const tab = ref('services');

// ───── Сервисы ─────
const services = ref([]);
const loadingServices = ref(false);
const testing = ref({});

const serviceOptions = computed(() => services.value.map(s => ({ title: s.label, value: s.key })));

async function loadServices() {
  loadingServices.value = true;
  try {
    const { data } = await api.get('/admin/integrations');
    services.value = data.services || [];
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка загрузки', 'error');
  }
  loadingServices.value = false;
}

async function runTest(s) {
  testing.value[s.key] = true;
  try {
    const { data } = await api.post(`/admin/integrations/${s.key}/test`);
    notify(`${s.label}: ${data.summary || (data.ok ? 'OK' : 'fail')}`, data.ok ? 'success' : 'error');
    await loadServices();
  } catch (e) {
    notify(e.response?.data?.summary || e.response?.data?.message || 'Ошибка теста', 'error');
  }
  testing.value[s.key] = false;
}

function serviceColor(s) {
  if (!s.configured) return 'warning';
  const r = s.metrics_24h.success_rate;
  if (r == null) return 'primary';
  if (r >= 95) return 'success';
  if (r >= 80) return 'warning';
  return 'error';
}
function categoryLabel(c) {
  return { incoming: 'Входящий webhook', data: 'Источник данных', notify: 'Оповещения', realtime: 'Real-time' }[c] || c;
}
function rateColor(r) {
  if (r >= 95) return 'success';
  if (r >= 80) return 'warning';
  return 'error';
}

// ───── Журнал событий ─────
const evRows = ref([]);
const evTotal = ref(0);
const evPage = ref(1);
const evPerPage = ref(25);
const loadingEvents = ref(false);
const evFilters = ref({ q: '', service: null, direction: null, status: null });

const evHeaders = [
  { title: 'Время', key: 'created_at', sortable: false, width: 170 },
  { title: 'Сервис', key: 'service', sortable: false, width: 130 },
  { title: '', key: 'direction', sortable: false, width: 100 },
  { title: 'Действие', key: 'action', sortable: false, width: 180 },
  { title: 'Статус', key: 'status', sortable: false, width: 100 },
  { title: 'Описание', key: 'summary', sortable: false },
  { title: 'Длительность', key: 'duration_ms', sortable: false, width: 130, align: 'end' },
  { title: '', key: 'actions', sortable: false, width: 110, align: 'end' },
];

async function loadEvents() {
  loadingEvents.value = true;
  try {
    const { data } = await api.get('/admin/integrations/events', {
      params: {
        q: evFilters.value.q || undefined,
        service: evFilters.value.service || undefined,
        direction: evFilters.value.direction || undefined,
        status: evFilters.value.status || undefined,
        page: evPage.value,
        per: evPerPage.value,
      },
    });
    evRows.value = data.data || [];
    evTotal.value = data.total || 0;
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка загрузки событий', 'error');
  }
  loadingEvents.value = false;
}

const { debounced: debouncedReloadEvents } = useDebounce(() => {
  evPage.value = 1;
  loadEvents();
}, 300);
function onEvFilterChange() { debouncedReloadEvents(); }

const eventDialog = ref(false);
const currentEvent = ref(null);
const replaying = ref(false);

async function openEvent(item) {
  try {
    const { data } = await api.get(`/admin/integrations/events/${item.id}`);
    currentEvent.value = data;
    eventDialog.value = true;
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
}

async function replayEvent() {
  if (!currentEvent.value) return;
  replaying.value = true;
  try {
    const { data } = await api.post(`/admin/integrations/events/${currentEvent.value.id}/replay`);
    notify(`Replay выполнен: ${data.message || 'OK'}`);
    eventDialog.value = false;
    await loadEvents();
    await loadServices();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка replay', 'error');
  }
  replaying.value = false;
}

// ───── Настройки сервиса ─────
const configDialog = ref(false);
const currentConfig = ref(null);
const savingConfig = ref(false);

const SECRET_HINTS = {
  insmart: 'Webhook secret и API key — для проверки подписи входящих и исходящих вызовов.',
  google_sheets: 'API key (Google Cloud Console → Credentials) + ID нужных таблиц.',
  telegram: 'Token бота от @BotFather + chat_id (group/channel) для алертов.',
  smtp: 'Хранится отдельно в /admin/mail — там можно отправить тест и редактировать шаблоны.',
  socket_io: 'Адрес сервера и emit-secret задаются через .env (SOCKET_HOST, SOCKET_API_PORT, SOCKET_EMIT_SECRET).',
};
function configHint(key) {
  return SECRET_HINTS[key] || '';
}
function isSecretKey(key) {
  return /(secret|token|api_key|password)/.test(key);
}
function settingLabel(key) {
  return key
    .split('.')
    .map(p => p.charAt(0).toUpperCase() + p.slice(1).replace(/_/g, ' '))
    .join(' / ');
}

async function openConfig(s) {
  if (s.key === 'smtp') {
    notify('SMTP настраивается в разделе Mail / Шаблоны', 'info');
    return;
  }
  if (s.key === 'socket_io') {
    notify('Socket.IO — параметры в .env (SOCKET_HOST, SOCKET_API_PORT)', 'info');
    return;
  }
  try {
    const { data } = await api.get(`/admin/integrations/${s.key}/config`);
    const draft = {};
    Object.keys(data.settings).forEach(k => { draft[k] = ''; });
    currentConfig.value = { service: s, values: data.settings, draft };
    configDialog.value = true;
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
}

const hasChanges = computed(() => {
  if (!currentConfig.value) return false;
  return Object.values(currentConfig.value.draft).some(v => v != null && v !== '');
});

async function saveConfig() {
  if (!currentConfig.value || !hasChanges.value) return;
  savingConfig.value = true;
  try {
    // Отправляем только то, что заполнено — пустые поля не трогают текущие значения.
    const payload = {};
    Object.entries(currentConfig.value.draft).forEach(([k, v]) => {
      if (v != null && v !== '') payload[k] = v;
    });
    await api.put(`/admin/integrations/${currentConfig.value.service.key}/config`, { settings: payload });
    notify('Настройки сохранены');
    configDialog.value = false;
    await loadServices();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка сохранения', 'error');
  }
  savingConfig.value = false;
}

// ───── Утилиты ─────
function statusColor(s) {
  return { success: 'success', error: 'error', pending: 'warning' }[s] || 'grey';
}
function formatDate(s) {
  if (!s) return '—';
  const d = new Date(s);
  if (isNaN(d.getTime())) return s;
  return d.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'medium' });
}
function pretty(v) {
  if (v == null) return '—';
  try { return JSON.stringify(v, null, 2); } catch { return String(v); }
}

// Snackbar
const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

onMounted(() => {
  loadServices();
  loadEvents();
});
</script>

<style scoped>
.payload-block {
  background: rgba(var(--v-theme-on-surface), 0.04);
  border: 1px solid rgba(var(--v-theme-on-surface), 0.1);
  border-radius: 6px;
  padding: 10px;
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  font-size: 12px;
  max-height: 320px;
  overflow: auto;
  white-space: pre-wrap;
  word-break: break-all;
}
</style>
