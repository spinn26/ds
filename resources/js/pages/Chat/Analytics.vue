<template>
  <div class="analytics-wrap">
    <div class="analytics-head">
      <h2><v-icon color="primary">mdi-chart-box-outline</v-icon> Аналитика чата</h2>
      <div class="period-row">
        <button v-for="p in periodOptions" :key="p.value"
          class="period-chip" :class="{ active: period === p.value }"
          @click="period = p.value; load()">{{ p.label }}</button>
        <v-btn size="small" variant="tonal" prepend-icon="mdi-refresh" @click="load">Обновить</v-btn>
        <v-btn size="small" variant="flat" color="secondary" prepend-icon="mdi-clipboard-text-outline" @click="openHandover">
          Отчёт смены
        </v-btn>
      </div>
    </div>

    <div v-if="loading" class="loading-state">
      <v-progress-circular indeterminate size="28" />
      <span>Загрузка…</span>
    </div>

    <template v-else-if="data">
      <!-- Summary cards -->
      <div class="cards-row">
        <div class="stat-card">
          <div class="stat-label">Всего тикетов</div>
          <div class="stat-value">{{ data.counters.total }}</div>
          <div class="stat-sub">за период</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Ср. время ответа</div>
          <div class="stat-value" :class="responseClass">{{ fmtMinutes(data.avgResponseMinutes) }}</div>
          <div class="stat-sub">по {{ data.responseTimeSamples }} тикетам</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Ср. время закрытия</div>
          <div class="stat-value">{{ fmtMinutes(data.avgResolutionMinutes) }}</div>
          <div class="stat-sub">из открытия в закрытие</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">SLA нарушено</div>
          <div class="stat-value" :class="{ danger: data.slaBreachedCount > 0 }">{{ data.slaBreachedCount }}</div>
          <div class="stat-sub">ожидание &gt; 30 мин</div>
        </div>
      </div>

      <!-- Counters by status -->
      <div class="status-counters">
        <div v-for="s in statusCells" :key="s.key" class="status-cell" :style="{ borderTopColor: s.color }">
          <div class="status-cell-label">
            <v-icon size="14" :color="s.color">{{ s.icon }}</v-icon>
            {{ s.label }}
          </div>
          <div class="status-cell-value">{{ data.counters[s.key] || 0 }}</div>
        </div>
      </div>

      <!-- Grid: categories + priority -->
      <div class="grid-2">
        <div class="panel">
          <div class="panel-head"><v-icon size="14">mdi-shape-outline</v-icon> Категории</div>
          <div v-if="!data.byCategory.length" class="panel-empty">Нет данных</div>
          <div v-else class="bar-list">
            <div v-for="c in data.byCategory" :key="c.category" class="bar-row">
              <span class="bar-label">{{ catLabel(c.category) }}</span>
              <div class="bar-track">
                <div class="bar-fill" :style="{ width: pct(c.count, catMax) + '%', background: catColor(c.category) }"></div>
              </div>
              <span class="bar-count">{{ c.count }}</span>
            </div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head"><v-icon size="14">mdi-flag-outline</v-icon> Приоритет</div>
          <div v-if="!data.byPriority.length" class="panel-empty">Нет данных</div>
          <div v-else class="bar-list">
            <div v-for="p in data.byPriority" :key="p.priority" class="bar-row">
              <span class="bar-label">{{ prioLabel(p.priority) }}</span>
              <div class="bar-track">
                <div class="bar-fill" :style="{ width: pct(p.count, prioMax) + '%', background: prioColor(p.priority) }"></div>
              </div>
              <span class="bar-count">{{ p.count }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Daily trend -->
      <div class="panel">
        <div class="panel-head"><v-icon size="14">mdi-chart-timeline-variant</v-icon> Динамика по дням</div>
        <div class="trend-chart" v-if="data.dailyTrend.length">
          <svg :viewBox="`0 0 ${trendViewW} ${trendViewH}`" preserveAspectRatio="none" width="100%" :height="trendViewH">
            <!-- Axis baseline -->
            <line :x1="0" :y1="trendViewH - 20" :x2="trendViewW" :y2="trendViewH - 20" stroke="rgba(127,127,127,0.3)" />

            <!-- Resolved polyline -->
            <polyline :points="trendPoints('resolved')" fill="none" stroke="#34d399" stroke-width="2" />
            <!-- New polyline -->
            <polyline :points="trendPoints('new')" fill="none" stroke="#60a5fa" stroke-width="2" />
          </svg>
          <div class="trend-axis">
            <span v-for="d in thinnedLabels" :key="d.day">{{ shortDate(d.day) }}</span>
          </div>
          <div class="trend-legend">
            <span class="legend-dot" style="background:#60a5fa"></span> Новые
            <span class="legend-dot" style="background:#34d399"></span> Закрытые
          </div>
        </div>
      </div>

      <!-- Staff load -->
      <div class="panel">
        <div class="panel-head"><v-icon size="14">mdi-account-group-outline</v-icon> Нагрузка по сотрудникам</div>
        <div v-if="!data.staffLoad.length" class="panel-empty">Нет данных</div>
        <table v-else class="staff-table">
          <thead>
            <tr>
              <th>Сотрудник</th>
              <th class="num">Всего</th>
              <th class="num">Закрыто</th>
              <th class="num">Успех</th>
              <th>График</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in data.staffLoad" :key="s.userId">
              <td>{{ s.name }}</td>
              <td class="num">{{ s.total }}</td>
              <td class="num">{{ s.resolved }}</td>
              <td class="num">
                <span :class="successRateClass(s)">{{ successRate(s) }}%</span>
              </td>
              <td>
                <div class="bar-track">
                  <div class="bar-fill" :style="{ width: pct(s.total, staffMax) + '%', background: 'rgb(var(--v-theme-primary))' }"></div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Shift handover dialog -->
    <v-dialog v-model="handoverOpen" max-width="700">
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon>mdi-clipboard-text-outline</v-icon>
          Отчёт смены — мои открытые тикеты
        </v-card-title>
        <v-card-text>
          <div v-if="handoverLoading" class="loading-state">
            <v-progress-circular indeterminate size="24" />
          </div>
          <div v-else>
            <div class="text-body-2 mb-3">
              Всего открытых: <strong>{{ myOpenTickets.length }}</strong>
            </div>
            <div v-if="!myOpenTickets.length" class="panel-empty">
              <v-icon size="40" color="grey">mdi-check-all</v-icon>
              <p>Открытых тикетов нет</p>
            </div>
            <div v-else class="handover-list">
              <div v-for="t in myOpenTickets" :key="t.id" class="handover-item">
                <div class="handover-head">
                  <span class="handover-subject">#{{ t.id }} · {{ t.subject }}</span>
                  <span class="meta-status-chip handover-status" :style="{ background: statusClr(t.status) + '22', color: statusClr(t.status) }">
                    {{ statusTxt(t.status) }}
                  </span>
                </div>
                <div class="handover-meta">
                  <span><v-icon size="11">mdi-account</v-icon> {{ t.customerName }}</span>
                  <span><v-icon size="11">mdi-clock-outline</v-icon> {{ relTime(t.lastMessageAt) }}</span>
                  <span v-if="t.priority && t.priority !== 'medium'" :style="{ color: prioColor(t.priority) }">
                    <v-icon size="11">mdi-flag</v-icon> {{ prioLabel(t.priority) }}
                  </span>
                </div>
              </div>
            </div>
            <v-divider class="my-3" />
            <v-btn size="small" variant="tonal" prepend-icon="mdi-content-copy" @click="copyHandover">
              Скопировать в буфер
            </v-btn>
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="handoverOpen = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';

const data = ref(null);
const loading = ref(false);
const period = ref('week');

const periodOptions = [
  { value: 'day', label: 'Сегодня' },
  { value: 'week', label: '7 дней' },
  { value: 'month', label: '30 дней' },
];

const statusCells = [
  { key: 'new', label: 'Новые', color: '#60a5fa', icon: 'mdi-circle-outline' },
  { key: 'open', label: 'В работе', color: '#fbbf24', icon: 'mdi-progress-clock' },
  { key: 'pending', label: 'Ожидание', color: '#f97316', icon: 'mdi-pause-circle' },
  { key: 'resolved', label: 'Решены', color: '#34d399', icon: 'mdi-check-circle' },
  { key: 'closed', label: 'Закрыты', color: '#6b7280', icon: 'mdi-lock' },
];

function catColor(c) { return { support: '#3b82f6', backoffice: '#f97316', billing: '#22c55e', legal: '#a855f7', general: '#6b7280', technical: '#3b82f6', sales: '#f97316' }[c] || '#6b7280'; }
function catLabel(c) { return { support: 'Техподдержка', backoffice: 'Бэк-офис', billing: 'Начисления', legal: 'Юридический', general: 'Общий', technical: 'Технический', sales: 'Продажи' }[c] || c; }
function prioColor(p) { return { critical: '#ef4444', high: '#f97316', medium: '#fbbf24', low: '#34d399' }[p] || '#888'; }
function prioLabel(p) { return { critical: 'Критический', high: 'Высокий', medium: 'Средний', low: 'Низкий' }[p] || p; }
function statusClr(s) { return { new: '#60a5fa', open: '#fbbf24', pending: '#f97316', resolved: '#34d399', closed: '#6b7280' }[s] || '#888'; }
function statusTxt(s) { return { new: 'Новый', open: 'В работе', pending: 'Ожидание', resolved: 'Решён', closed: 'Закрыт' }[s] || s; }

function fmtMinutes(m) {
  m = Number(m || 0);
  if (m < 1) return '< 1 мин';
  if (m < 60) return Math.round(m) + ' мин';
  const h = Math.floor(m / 60);
  const rem = Math.round(m % 60);
  if (h < 24) return `${h} ч${rem ? ` ${rem} мин` : ''}`;
  const d = Math.floor(h / 24);
  return `${d} дн ${h % 24} ч`;
}
function pct(v, max) {
  if (!max) return 0;
  return Math.max(2, Math.round((v / max) * 100));
}
function shortDate(d) {
  const dt = new Date(d);
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
}
function relTime(d) {
  if (!d) return '—';
  const s = Math.floor((Date.now() - new Date(d).getTime()) / 1000);
  if (s < 60) return 'только что';
  if (s < 3600) return Math.floor(s / 60) + ' мин назад';
  if (s < 86400) return Math.floor(s / 3600) + ' ч назад';
  return Math.floor(s / 86400) + ' дн назад';
}

const catMax = computed(() => Math.max(1, ...(data.value?.byCategory || []).map(c => c.count)));
const prioMax = computed(() => Math.max(1, ...(data.value?.byPriority || []).map(p => p.count)));
const staffMax = computed(() => Math.max(1, ...(data.value?.staffLoad || []).map(s => s.total)));

const responseClass = computed(() => {
  const m = data.value?.avgResponseMinutes || 0;
  if (m === 0) return 'muted';
  if (m > 60) return 'danger';
  if (m > 30) return 'warn';
  return 'good';
});

function successRate(s) {
  if (!s.total) return 0;
  return Math.round((s.resolved / s.total) * 100);
}
function successRateClass(s) {
  const r = successRate(s);
  if (r >= 80) return 'good';
  if (r >= 50) return 'warn';
  return 'danger';
}

// Trend chart math
const trendViewW = 800;
const trendViewH = 180;
function trendPoints(key) {
  const days = data.value?.dailyTrend || [];
  if (!days.length) return '';
  const max = Math.max(1, ...days.map(d => Math.max(d.new, d.resolved)));
  const stepX = trendViewW / Math.max(1, days.length - 1);
  const top = 10;
  const bottom = trendViewH - 20;
  const chartH = bottom - top;
  return days
    .map((d, i) => {
      const x = days.length === 1 ? trendViewW / 2 : i * stepX;
      const y = bottom - (d[key] / max) * chartH;
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    })
    .join(' ');
}
const thinnedLabels = computed(() => {
  const days = data.value?.dailyTrend || [];
  if (days.length <= 7) return days;
  const keep = Math.ceil(days.length / 7);
  return days.filter((_, i) => i % keep === 0 || i === days.length - 1);
});

async function load() {
  loading.value = true;
  try {
    const { data: res } = await api.get('/chat/analytics', { params: { period: period.value } });
    data.value = res;
  } catch (e) {
    data.value = null;
  }
  loading.value = false;
}

// Shift handover
const handoverOpen = ref(false);
const handoverLoading = ref(false);
const myOpenTickets = ref([]);
async function openHandover() {
  handoverOpen.value = true;
  handoverLoading.value = true;
  try {
    const { data: res } = await api.get('/chat/my-open');
    myOpenTickets.value = res.data || [];
  } catch {
    myOpenTickets.value = [];
  }
  handoverLoading.value = false;
}
function copyHandover() {
  const lines = [
    `Отчёт смены — ${new Date().toLocaleString('ru-RU')}`,
    `Открытых тикетов: ${myOpenTickets.value.length}`,
    '',
    ...myOpenTickets.value.map(t =>
      `• #${t.id} [${statusTxt(t.status)}${t.priority && t.priority !== 'medium' ? ', ' + prioLabel(t.priority) : ''}] ${t.subject} — ${t.customerName} — ${relTime(t.lastMessageAt)}`
    ),
  ].join('\n');
  navigator.clipboard.writeText(lines).catch(() => {});
  alert('Скопировано в буфер обмена');
}

onMounted(load);
</script>

<style scoped>
.analytics-wrap { padding: 20px; max-width: 1280px; margin: 0 auto; }

.analytics-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
.analytics-head h2 { display: flex; align-items: center; gap: 8px; font-size: 20px; font-weight: 700; margin: 0; }
.period-row { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.period-chip { padding: 4px 12px; border-radius: 14px; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); background: transparent; color: rgba(var(--v-theme-on-surface), 0.7); font-size: 12px; cursor: pointer; font-weight: 600; transition: all 0.15s; }
.period-chip.active { background: rgb(var(--v-theme-primary)); color: #fff; border-color: rgb(var(--v-theme-primary)); }

.loading-state { display: flex; align-items: center; gap: 10px; padding: 40px; justify-content: center; color: rgba(var(--v-theme-on-surface), 0.5); }

.cards-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 16px; }
.stat-card { padding: 16px 18px; border-radius: 14px; background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.stat-label { font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.5); text-transform: uppercase; letter-spacing: 0.5px; }
.stat-value { font-size: 28px; font-weight: 800; margin-top: 6px; color: rgba(var(--v-theme-on-surface), 0.9); }
.stat-value.muted { color: rgba(var(--v-theme-on-surface), 0.4); }
.stat-value.good { color: #059669; }
.stat-value.warn { color: #c27803; }
.stat-value.danger { color: #dc2626; }
.stat-sub { font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.45); margin-top: 2px; }

.status-counters { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; margin-bottom: 16px; }
.status-cell { padding: 10px 14px; border-radius: 10px; background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-top-width: 3px; }
.status-cell-label { font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.6); display: flex; align-items: center; gap: 4px; }
.status-cell-value { font-size: 22px; font-weight: 700; margin-top: 4px; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }

.panel { padding: 16px; border-radius: 14px; background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); margin-bottom: 16px; }
.panel-head { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700; margin-bottom: 12px; color: rgba(var(--v-theme-on-surface), 0.7); }
.panel-empty { padding: 32px; text-align: center; color: rgba(var(--v-theme-on-surface), 0.4); }

.bar-list { display: flex; flex-direction: column; gap: 6px; }
.bar-row { display: grid; grid-template-columns: 140px 1fr 40px; align-items: center; gap: 8px; font-size: 12px; }
.bar-label { color: rgba(var(--v-theme-on-surface), 0.7); }
.bar-track { height: 10px; background: rgba(var(--v-theme-surface-variant), 0.4); border-radius: 5px; overflow: hidden; }
.bar-fill { height: 100%; border-radius: 5px; transition: width 0.3s ease; }
.bar-count { text-align: right; font-weight: 700; color: rgba(var(--v-theme-on-surface), 0.8); }

.trend-chart { background: rgba(var(--v-theme-surface-variant), 0.2); padding: 10px; border-radius: 10px; }
.trend-axis { display: flex; justify-content: space-between; padding: 4px 2px 0; font-size: 10px; color: rgba(var(--v-theme-on-surface), 0.5); }
.trend-legend { display: flex; gap: 10px; margin-top: 6px; font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.6); align-items: center; }
.legend-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 2px; margin-left: 6px; }

.staff-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.staff-table th { text-align: left; padding: 6px 10px; border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: rgba(var(--v-theme-on-surface), 0.5); }
.staff-table td { padding: 8px 10px; border-bottom: 1px solid rgba(var(--v-border-color), 0.15); }
.staff-table td.num, .staff-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
.staff-table .good { color: #059669; font-weight: 700; }
.staff-table .warn { color: #c27803; font-weight: 700; }
.staff-table .danger { color: #dc2626; font-weight: 700; }
.staff-table tr:hover td { background: rgba(var(--v-theme-primary), 0.04); }

/* Handover */
.handover-list { display: flex; flex-direction: column; gap: 6px; }
.handover-item { padding: 10px; border-radius: 8px; background: rgba(var(--v-theme-surface-variant), 0.3); }
.handover-head { display: flex; justify-content: space-between; gap: 8px; font-size: 13px; font-weight: 600; }
.handover-status { padding: 1px 6px; border-radius: 10px; font-size: 10px; font-weight: 700; }
.handover-meta { display: flex; gap: 10px; margin-top: 4px; font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.6); flex-wrap: wrap; }
.handover-meta > span { display: inline-flex; align-items: center; gap: 3px; }

@media (max-width: 768px) {
  .status-counters { grid-template-columns: repeat(2, 1fr); }
  .grid-2 { grid-template-columns: 1fr; }
  .bar-row { grid-template-columns: 90px 1fr 36px; font-size: 11px; }
}
</style>
