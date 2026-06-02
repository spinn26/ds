<template>
  <div>
    <PageHeader title="Активность" icon="mdi-account-multiple-check">
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
          <v-btn color="primary" prepend-icon="mdi-refresh" size="small" :loading="loading"
            variant="tonal" @click="load">Обновить</v-btn>
        </div>
      </template>
    </PageHeader>

    <!-- USERS KPIs -->
    <v-row dense>
      <v-col cols="12" sm="6" md="3">
        <v-card class="pa-4 h-100 kpi-card kpi-online">
          <div class="d-flex align-center ga-2 mb-1">
            <v-icon color="success" size="22">mdi-circle</v-icon>
            <div class="text-subtitle-2 font-weight-bold">Онлайн сейчас</div>
          </div>
          <div class="kpi-num">{{ data.online ?? 0 }}</div>
          <div class="text-caption text-medium-emphasis">активны за последние 5 мин</div>
        </v-card>
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <v-card class="pa-4 h-100 kpi-card">
          <div class="d-flex align-center ga-2 mb-1">
            <v-icon color="info" size="22">mdi-account-clock</v-icon>
            <div class="text-subtitle-2 font-weight-bold">Активны (15 мин)</div>
          </div>
          <div class="kpi-num">{{ data.online15 ?? 0 }}</div>
          <div class="text-caption text-medium-emphasis">уникальных пользователей</div>
        </v-card>
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <v-card class="pa-4 h-100 kpi-card">
          <div class="d-flex align-center ga-2 mb-1">
            <v-icon color="primary" size="22">mdi-login</v-icon>
            <div class="text-subtitle-2 font-weight-bold">Зашло сегодня</div>
          </div>
          <div class="kpi-num">{{ data.uniqueLoginsToday ?? 0 }}</div>
          <div class="text-caption text-medium-emphasis">
            уникальных · всего входов: {{ data.loginsToday ?? 0 }}
          </div>
        </v-card>
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <v-card class="pa-4 h-100 kpi-card">
          <div class="d-flex align-center ga-2 mb-1">
            <v-icon color="secondary" size="22">mdi-history</v-icon>
            <div class="text-subtitle-2 font-weight-bold">Входы за 24ч</div>
          </div>
          <div class="kpi-num">{{ data.logins24h ?? 0 }}</div>
          <div class="text-caption text-medium-emphasis">всего за сутки</div>
        </v-card>
      </v-col>
    </v-row>

    <!-- LOGINS BY HOUR -->
    <v-card class="mt-4 pa-4">
      <div class="text-subtitle-1 font-weight-bold mb-3">Входы по часам (сегодня)</div>
      <div v-if="maxHourly > 0" class="hourly-chart">
        <div v-for="b in (data.hourly || [])" :key="b.hour" class="hourly-col" :title="`${b.hour}:00 — ${b.count}`">
          <div class="hourly-bar" :style="{ height: barHeight(b.count) }"></div>
          <div class="hourly-label">{{ b.hour }}</div>
        </div>
      </div>
      <div v-else class="text-caption text-medium-emphasis py-4 text-center">
        Сегодня входов ещё не было.
      </div>
    </v-card>

    <!-- DEVICES -->
    <v-card class="mt-4 pa-4">
      <div class="d-flex align-center ga-2 mb-3">
        <v-icon size="20">mdi-devices</v-icon>
        <div class="text-subtitle-1 font-weight-bold">Устройства (сегодня)</div>
        <v-spacer />
        <span class="text-caption text-medium-emphasis">уникальных: {{ deviceTotal }}</span>
      </div>
      <div v-if="deviceTotal > 0">
        <div class="device-bar mb-3">
          <div class="device-seg device-desktop" :style="{ width: devicePct('desktop') + '%' }"></div>
          <div class="device-seg device-mobile" :style="{ width: devicePct('mobile') + '%' }"></div>
          <div class="device-seg device-unknown" :style="{ width: devicePct('unknown') + '%' }"></div>
        </div>
        <v-row dense>
          <v-col cols="6" sm="4">
            <div class="d-flex align-center ga-2">
              <v-icon color="info" size="26">mdi-monitor</v-icon>
              <div>
                <div class="text-h6 font-weight-bold">{{ devices.desktop ?? 0 }}</div>
                <div class="text-caption text-medium-emphasis">ПК · {{ devicePct('desktop') }}%</div>
              </div>
            </div>
          </v-col>
          <v-col cols="6" sm="4">
            <div class="d-flex align-center ga-2">
              <v-icon color="success" size="26">mdi-cellphone</v-icon>
              <div>
                <div class="text-h6 font-weight-bold">{{ devices.mobile ?? 0 }}</div>
                <div class="text-caption text-medium-emphasis">Мобильные · {{ devicePct('mobile') }}%</div>
              </div>
            </div>
          </v-col>
          <v-col v-if="devices.unknown" cols="6" sm="4">
            <div class="d-flex align-center ga-2">
              <v-icon color="grey" size="26">mdi-help-circle-outline</v-icon>
              <div>
                <div class="text-h6 font-weight-bold">{{ devices.unknown ?? 0 }}</div>
                <div class="text-caption text-medium-emphasis">Неизвестно · {{ devicePct('unknown') }}%</div>
              </div>
            </div>
          </v-col>
        </v-row>
      </div>
      <div v-else class="text-caption text-medium-emphasis py-4 text-center">
        Сегодня входов ещё не было.
      </div>
    </v-card>

    <!-- SERVER LOAD -->
    <v-card class="mt-4 pa-4">
      <div class="d-flex align-center ga-2 mb-3">
        <v-icon size="20">mdi-server</v-icon>
        <div class="text-subtitle-1 font-weight-bold">Нагрузка на сервер</div>
        <v-chip v-if="server.platform" size="x-small" variant="tonal">{{ server.platform }}</v-chip>
        <v-spacer />
        <span v-if="server.uptime" class="text-caption text-medium-emphasis">
          <v-icon size="14" class="mr-1">mdi-timer-outline</v-icon>uptime: {{ server.uptime }}
        </span>
      </div>

      <v-row dense>
        <!-- CPU -->
        <v-col cols="12" md="4">
          <div class="metric-block">
            <div class="d-flex align-center justify-space-between mb-1">
              <div class="text-body-2 font-weight-medium">
                <v-icon size="16" class="mr-1">mdi-cpu-64-bit</v-icon>Процессор (CPU)
              </div>
              <div class="text-body-2 font-weight-bold" :class="loadClass(server.loadPercent)">
                {{ server.loadPercent != null ? server.loadPercent + '%' : '—' }}
              </div>
            </div>
            <v-progress-linear :model-value="server.loadPercent ?? 0" :color="loadColor(server.loadPercent)"
              height="10" rounded />
            <div class="text-caption text-medium-emphasis mt-1">
              <template v-if="server.load">
                load avg: {{ server.load['1m'] }} / {{ server.load['5m'] }} / {{ server.load['15m'] }}
                · ядер: {{ server.cores }}
              </template>
              <template v-else>load average недоступен на этой платформе</template>
            </div>
          </div>
        </v-col>

        <!-- RAM -->
        <v-col cols="12" md="4">
          <div class="metric-block">
            <div class="d-flex align-center justify-space-between mb-1">
              <div class="text-body-2 font-weight-medium">
                <v-icon size="16" class="mr-1">mdi-memory</v-icon>Память (RAM)
              </div>
              <div class="text-body-2 font-weight-bold" :class="loadClass(server.memory?.usedPercent)">
                {{ server.memory ? server.memory.usedPercent + '%' : '—' }}
              </div>
            </div>
            <v-progress-linear :model-value="server.memory?.usedPercent ?? 0"
              :color="loadColor(server.memory?.usedPercent)" height="10" rounded />
            <div class="text-caption text-medium-emphasis mt-1">
              <template v-if="server.memory">
                {{ fmtGb(server.memory.usedMb) }} / {{ fmtGb(server.memory.totalMb) }}
                · свободно {{ fmtGb(server.memory.availableMb) }}
              </template>
              <template v-else>метрика доступна только на Linux-сервере</template>
            </div>
          </div>
        </v-col>

        <!-- DISK -->
        <v-col cols="12" md="4">
          <div class="metric-block">
            <div class="d-flex align-center justify-space-between mb-1">
              <div class="text-body-2 font-weight-medium">
                <v-icon size="16" class="mr-1">mdi-harddisk</v-icon>Диск
              </div>
              <div class="text-body-2 font-weight-bold" :class="loadClass(server.disk?.usedPercent)">
                {{ server.disk ? server.disk.usedPercent + '%' : '—' }}
              </div>
            </div>
            <v-progress-linear :model-value="server.disk?.usedPercent ?? 0"
              :color="loadColor(server.disk?.usedPercent)" height="10" rounded />
            <div class="text-caption text-medium-emphasis mt-1">
              <template v-if="server.disk">
                {{ server.disk.usedGb }} GB / {{ server.disk.totalGb }} GB
                · свободно {{ server.disk.freeGb }} GB
              </template>
              <template v-else>метрика недоступна</template>
            </div>
          </div>
        </v-col>
      </v-row>

      <div class="text-caption text-medium-emphasis mt-3">
        <v-icon size="14" class="mr-1">mdi-language-php</v-icon>
        PHP {{ server.php?.version || '' }} · процесс: {{ server.php?.memoryUsageMb || 0 }} MB
        (пик {{ server.php?.peakMb || 0 }} MB)
      </div>
    </v-card>

    <!-- RECENT LOGINS -->
    <v-card class="mt-4">
      <div class="pa-4 d-flex align-center ga-2">
        <div class="text-subtitle-1 font-weight-bold">Последние входы</div>
        <v-chip size="small" variant="tonal">{{ (data.recentLogins || []).length }}</v-chip>
      </div>
      <v-divider />
      <v-data-table :items="data.recentLogins || []" :headers="loginHeaders" :loading="loading"
        density="compact" hover no-data-text="Входов пока нет" :items-per-page="15">
        <template #item.name="{ item }">
          <div class="d-flex flex-column py-1">
            <span class="text-body-2 font-weight-medium">{{ item.name || item.email || '—' }}</span>
            <span v-if="item.name && item.email" class="text-caption text-medium-emphasis">{{ item.email }}</span>
          </div>
        </template>
        <template #item.role="{ value }">
          <v-chip size="x-small" variant="tonal">{{ value || '—' }}</v-chip>
        </template>
        <template #item.at="{ value }">
          <span class="text-caption">{{ fmtDateTime(value) }}</span>
        </template>
        <template #item.ip="{ item }">
          <span class="text-caption d-inline-flex align-center ga-1">
            <span v-if="item.country" :title="item.countryName || item.country" class="flag-emoji">{{ countryFlag(item.country) }}</span>
            <span>{{ item.ip || '—' }}</span>
          </span>
        </template>
      </v-data-table>
    </v-card>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import api from '../../api';
import { fmtDateTime } from '../../composables/useDesign';
import PageHeader from '../../components/PageHeader.vue';

const data = ref({});
const loading = ref(false);
const autoRefresh = ref(true);
const lastUpdated = ref(null);
let timer = null;

const server = computed(() => data.value.server || {});

const loginHeaders = [
  { title: 'ФИО', key: 'name' },
  { title: 'Роль', key: 'role', width: 130 },
  { title: 'IP', key: 'ip', width: 200 },
  { title: 'Когда', key: 'at', width: 180 },
];

/** ISO2 country code → flag emoji (regional indicator symbols). */
function countryFlag(code) {
  if (!code || code.length !== 2) return '';
  const cc = code.toUpperCase();
  if (!/^[A-Z]{2}$/.test(cc)) return '';
  return String.fromCodePoint(...[...cc].map(c => 0x1f1e6 + c.charCodeAt(0) - 65));
}

const lastUpdatedLabel = computed(() => {
  if (!lastUpdated.value) return '—';
  return new Date(lastUpdated.value).toLocaleTimeString('ru-RU');
});

const maxHourly = computed(() =>
  Math.max(0, ...((data.value.hourly || []).map(b => b.count)))
);

const devices = computed(() => data.value.devices || { desktop: 0, mobile: 0, unknown: 0 });
const deviceTotal = computed(() =>
  (devices.value.desktop || 0) + (devices.value.mobile || 0) + (devices.value.unknown || 0)
);
function devicePct(key) {
  if (deviceTotal.value <= 0) return 0;
  return Math.round(((devices.value[key] || 0) / deviceTotal.value) * 100);
}

function barHeight(count) {
  if (maxHourly.value <= 0) return '2px';
  return Math.max(2, Math.round((count / maxHourly.value) * 100)) + '%';
}

function fmtGb(mb) {
  if (mb == null) return '—';
  return mb >= 1024 ? (mb / 1024).toFixed(1) + ' GB' : Math.round(mb) + ' MB';
}

function loadColor(pct) {
  if (pct == null) return 'grey';
  if (pct >= 90) return 'error';
  if (pct >= 70) return 'warning';
  return 'success';
}
function loadClass(pct) {
  if (pct == null) return 'text-medium-emphasis';
  if (pct >= 90) return 'text-error';
  if (pct >= 70) return 'text-warning';
  return 'text-success';
}

async function load() {
  loading.value = true;
  try {
    const { data: resp } = await api.get('/admin/monitoring/activity');
    data.value = resp;
    lastUpdated.value = resp.generatedAt;
  } catch {}
  loading.value = false;
}

watch(autoRefresh, (v) => {
  if (timer) clearInterval(timer);
  if (v) timer = setInterval(load, 30000);
});

onMounted(() => {
  load();
  if (autoRefresh.value) timer = setInterval(load, 30000);
});

onUnmounted(() => {
  if (timer) clearInterval(timer);
});
</script>

<style scoped>
.kpi-card {
  border-left: 3px solid rgba(var(--v-theme-on-surface), 0.12);
}
.kpi-online {
  border-left-color: rgb(var(--v-theme-success));
}
.kpi-num {
  font-size: 2rem;
  font-weight: 700;
  line-height: 1.1;
  font-variant-numeric: tabular-nums;
}
.hourly-chart {
  display: flex;
  align-items: flex-end;
  gap: 4px;
  height: 140px;
}
.hourly-col {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  height: 100%;
  justify-content: flex-end;
}
.hourly-bar {
  width: 100%;
  min-height: 2px;
  background: rgb(var(--v-theme-primary));
  border-radius: 3px 3px 0 0;
  transition: height 0.3s ease;
}
.hourly-label {
  font-size: 9px;
  color: rgba(var(--v-theme-on-surface), 0.5);
  margin-top: 4px;
  font-variant-numeric: tabular-nums;
}
.metric-block {
  padding: 8px 0;
}
.device-bar {
  display: flex;
  height: 14px;
  border-radius: 7px;
  overflow: hidden;
  background: rgba(var(--v-theme-on-surface), 0.08);
}
.device-seg {
  height: 100%;
  transition: width 0.3s ease;
}
.device-desktop {
  background: rgb(var(--v-theme-info));
}
.device-mobile {
  background: rgb(var(--v-theme-success));
}
.device-unknown {
  background: rgba(var(--v-theme-on-surface), 0.25);
}
.flag-emoji {
  font-size: 14px;
  line-height: 1;
}
</style>
