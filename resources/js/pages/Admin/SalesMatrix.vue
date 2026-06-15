<template>
  <div>
    <div class="d-flex align-center mb-3 ga-2">
      <PageHeader title="Матрица продаж" icon="mdi-table-large" class="flex-grow-1 mb-0" />
      <v-btn-toggle v-model="reportMode" density="compact" variant="outlined" mandatory color="primary">
        <v-btn value="actual"   size="small" prepend-icon="mdi-check-circle-outline">Фактический</v-btn>
        <v-btn value="forecast" size="small" prepend-icon="mdi-chart-timeline-variant">Прогнозный</v-btn>
      </v-btn-toggle>
    </div>

    <!-- Forecast placeholder -->
    <v-card v-if="reportMode === 'forecast'" class="pa-8 text-center">
      <v-icon size="48" color="primary" class="mb-3">mdi-chart-timeline-variant</v-icon>
      <div class="text-h6 mb-2">Прогнозный отчёт</div>
      <div class="text-body-2 text-medium-emphasis">В разработке. Будет показывать ожидаемый объём по контрактам с прогнозными датами активации.</div>
    </v-card>

    <template v-if="reportMode === 'actual'">
      <!-- Filters -->
      <v-card class="ds-card mb-3 pa-3" elevation="0">
        <div class="d-flex ga-2 flex-wrap align-center">
          <!-- Period: year + quarter -->
          <v-select v-model="periodYear" :items="yearOptions" label="Год"
            density="compact" variant="outlined" hide-details style="max-width:90px"
            @update:model-value="reload" />

          <v-btn-toggle v-model="periodQuarter" density="compact" variant="outlined" mandatory
            @update:model-value="reload">
            <v-btn v-for="q in quarterOptions" :key="q" :value="q" size="small">{{ q }}</v-btn>
          </v-btn-toggle>

          <!-- Product filter -->
          <v-autocomplete v-model="filterProducts" :items="productOptions"
            item-title="name" item-value="id" label="Продукт"
            multiple chips closable-chips density="compact" variant="outlined"
            hide-details style="max-width:300px"
            @update:model-value="loadData" />

          <v-btn v-if="filterProducts.length" size="small" variant="text"
            prepend-icon="mdi-filter-remove" @click="resetFilters">Сбросить</v-btn>

          <v-spacer />

          <v-btn size="small" variant="text" prepend-icon="mdi-expand-all-outline" @click="expandAll">Развернуть</v-btn>
          <v-btn size="small" variant="text" prepend-icon="mdi-collapse-all-outline" @click="collapseAll">Свернуть</v-btn>

          <!-- Metrics selector (max 2) -->
          <v-menu :close-on-content-click="false" location="bottom end">
            <template #activator="{ props }">
              <v-btn v-bind="props" size="small" variant="outlined" prepend-icon="mdi-view-column-outline">
                Метрики ({{ selectedMetricKeys.length }}/2)
              </v-btn>
            </template>
            <v-card min-width="210">
              <v-list density="compact" class="pa-1">
                <v-list-item v-for="m in allMetrics" :key="m.key" :title="m.label"
                  style="cursor:pointer" @click="toggleMetric(m.key)">
                  <template #prepend>
                    <v-checkbox-btn :model-value="selectedMetricKeys.includes(m.key)"
                      :disabled="selectedMetricKeys.length >= 2 && !selectedMetricKeys.includes(m.key)"
                      color="primary" />
                  </template>
                </v-list-item>
              </v-list>
              <div class="text-caption text-medium-emphasis px-3 pb-2">Максимум 2 метрики</div>
            </v-card>
          </v-menu>
        </div>
      </v-card>

      <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-1" />

      <!-- Summary chips -->
      <div v-if="grandTotals && !loading" class="d-flex ga-2 flex-wrap mb-3">
        <v-chip size="small" variant="tonal" color="primary">
          <v-icon start size="14">mdi-account-group-outline</v-icon>
          {{ rows.length }} ФК
        </v-chip>
        <v-chip size="small" variant="tonal" color="secondary">
          <v-icon start size="14">mdi-file-document-outline</v-icon>
          {{ (grandTotals.count || 0).toLocaleString('ru-RU') }} контрактов
        </v-chip>
        <v-chip size="small" variant="tonal">
          <v-icon start size="14">mdi-cash</v-icon>
          {{ fmtRub(grandTotals.volume) }} объём
        </v-chip>
        <v-chip size="small" variant="tonal" color="success">
          <v-icon start size="14">mdi-trending-up</v-icon>
          {{ fmtRub(grandTotals.revenue) }} выручка
        </v-chip>
      </div>

      <!-- Matrix table -->
      <v-card v-if="!loading" class="ds-card" elevation="0">
        <div style="overflow-x:auto">
          <table class="matrix-table">
            <thead>
              <!-- Row 1: month groups + total group -->
              <tr>
                <th class="col-name" rowspan="2">ФК / Продукт / Программа</th>
                <th v-for="mo in months" :key="mo"
                  :colspan="activeMetrics.length" class="month-group">
                  {{ fmtMonthHdr(mo) }}
                </th>
                <th :colspan="activeMetrics.length" class="month-group total-group">
                  Итого {{ periodLabel }}
                </th>
              </tr>
              <!-- Row 2: metric sub-headers -->
              <tr>
                <template v-for="mo in months" :key="`sh-${mo}`">
                  <th v-for="m in activeMetrics" :key="`${mo}-${m.key}`" class="col-num-sub">
                    {{ m.shortLabel }}
                  </th>
                </template>
                <th v-for="m in activeMetrics" :key="`tot-${m.key}`" class="col-num-sub total-sub">
                  {{ m.shortLabel }}
                </th>
              </tr>
            </thead>
            <tbody>
              <template v-for="fc in rows" :key="fc.fcId">
                <!-- FC row -->
                <tr class="row-fc" @click="toggleFc(fc.fcId)">
                  <td class="col-name">
                    <div class="cell-name">
                      <v-icon size="13" class="mr-1">
                        {{ expandedFcs.has(fc.fcId) ? 'mdi-chevron-down' : 'mdi-chevron-right' }}
                      </v-icon>
                      <span class="fc-name">{{ fc.fcName }}</span>
                    </div>
                  </td>
                  <template v-for="mo in months" :key="`fc-${fc.fcId}-${mo}`">
                    <td v-for="m in activeMetrics" :key="`${mo}-${m.key}`" class="col-num">
                      {{ fmtCell(fc.monthly[mo]?.[m.key], m) }}
                    </td>
                  </template>
                  <td v-for="m in activeMetrics" :key="`fc-tot-${m.key}`" class="col-num total-cell">
                    {{ fmtCell(fc[m.key], m) }}
                  </td>
                </tr>

                <!-- Product rows -->
                <template v-if="expandedFcs.has(fc.fcId)">
                  <template v-for="prod in fc.products" :key="`${fc.fcId}-${prod.productId}`">
                    <tr class="row-product" @click="toggleProduct(`${fc.fcId}-${prod.productId}`)">
                      <td class="col-name">
                        <div class="cell-name cell-level1">
                          <v-icon size="12" class="mr-1">
                            {{ expandedProducts.has(`${fc.fcId}-${prod.productId}`) ? 'mdi-chevron-down' : 'mdi-chevron-right' }}
                          </v-icon>
                          {{ prod.productName }}
                        </div>
                      </td>
                      <template v-for="mo in months" :key="`pr-${prod.productId}-${mo}`">
                        <td v-for="m in activeMetrics" :key="`${mo}-${m.key}`" class="col-num">
                          {{ fmtCell(prod.monthly[mo]?.[m.key], m) }}
                        </td>
                      </template>
                      <td v-for="m in activeMetrics" :key="`pr-tot-${m.key}`" class="col-num total-cell">
                        {{ fmtCell(prod[m.key], m) }}
                      </td>
                    </tr>

                    <!-- Program rows -->
                    <template v-if="expandedProducts.has(`${fc.fcId}-${prod.productId}`)">
                      <tr v-for="pg in prod.programs" :key="pg.programId" class="row-program">
                        <td class="col-name">
                          <div class="cell-name cell-level2">{{ pg.programName }}</div>
                        </td>
                        <template v-for="mo in months" :key="`pg-${pg.programId}-${mo}`">
                          <td v-for="m in activeMetrics" :key="`${mo}-${m.key}`" class="col-num num-dim">
                            {{ fmtCell(pg.monthly[mo]?.[m.key], m) }}
                          </td>
                        </template>
                        <td v-for="m in activeMetrics" :key="`pg-tot-${m.key}`" class="col-num total-cell num-dim">
                          {{ fmtCell(pg[m.key], m) }}
                        </td>
                      </tr>
                    </template>
                  </template>
                </template>
              </template>

              <!-- Grand totals -->
              <tr v-if="grandTotals && rows.length" class="row-totals">
                <td class="col-name"><strong>ИТОГО</strong></td>
                <template v-for="mo in months" :key="`g-${mo}`">
                  <td v-for="m in activeMetrics" :key="`${mo}-${m.key}`" class="col-num">
                    <strong>{{ fmtCell(grandTotals.monthly[mo]?.[m.key], m) }}</strong>
                  </td>
                </template>
                <td v-for="m in activeMetrics" :key="`g-tot-${m.key}`" class="col-num total-cell">
                  <strong>{{ fmtCell(grandTotals[m.key], m) }}</strong>
                </td>
              </tr>

              <tr v-if="!rows.length && !loading">
                <td :colspan="1 + months.length * activeMetrics.length + activeMetrics.length"
                  class="text-center pa-6 text-medium-emphasis">
                  Нет данных за {{ periodLabel }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </v-card>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';

// ─── Report mode ──────────────────────────────────────────────
const reportMode = ref('actual');

// ─── Period ───────────────────────────────────────────────────
const quarterOptions = ['Q1', 'Q2', 'Q3', 'Q4'];
const quarterRanges  = { Q1: [1, 3], Q2: [4, 6], Q3: [7, 9], Q4: [10, 12] };

const now = new Date();
const currentQ = `Q${Math.ceil((now.getMonth() + 1) / 3)}`;
const periodYear    = ref(now.getFullYear());
const periodQuarter = ref(currentQ);

const periodFrom  = computed(() => `${periodYear.value}-${String(quarterRanges[periodQuarter.value][0]).padStart(2, '0')}`);
const periodTo    = computed(() => `${periodYear.value}-${String(quarterRanges[periodQuarter.value][1]).padStart(2, '0')}`);
const periodLabel = computed(() => `${periodQuarter.value} ${periodYear.value}`);
const yearOptions = Array.from({ length: 7 }, (_, i) => now.getFullYear() - i);

// ─── Metrics ──────────────────────────────────────────────────
const allMetrics = [
  { key: 'volume',      shortLabel: 'Объём',    label: 'Объём (₽)',       fmt: 'rub' },
  { key: 'count',       shortLabel: 'Кол-во',   label: 'Кол-во',          fmt: 'int' },
  { key: 'revenue',     shortLabel: 'Выручка',  label: 'Выручка (₽)',     fmt: 'rub' },
  { key: 'points',      shortLabel: 'Баллы',    label: 'Баллы',           fmt: 'num' },
  { key: 'clientCount', shortLabel: 'Клиенты',  label: 'Кол-во клиентов', fmt: 'int' },
];
const selectedMetricKeys = ref(['volume', 'revenue']);
const activeMetrics = computed(() => allMetrics.filter(m => selectedMetricKeys.value.includes(m.key)));

function toggleMetric(key) {
  const idx = selectedMetricKeys.value.indexOf(key);
  if (idx !== -1) {
    if (selectedMetricKeys.value.length > 1) selectedMetricKeys.value.splice(idx, 1);
  } else if (selectedMetricKeys.value.length < 2) {
    selectedMetricKeys.value.push(key);
  }
}

// ─── Data ─────────────────────────────────────────────────────
const loading        = ref(false);
const rows           = ref([]);
const grandTotals    = ref(null);
const months         = ref([]);
const productOptions = ref([]);
const filterProducts = ref([]);

const expandedFcs      = ref(new Set());
const expandedProducts = ref(new Set());

function toggleFc(fcId) {
  const s = new Set(expandedFcs.value);
  if (s.has(fcId)) s.delete(fcId); else s.add(fcId);
  expandedFcs.value = s;
}

function toggleProduct(key) {
  const s = new Set(expandedProducts.value);
  if (s.has(key)) s.delete(key); else s.add(key);
  expandedProducts.value = s;
}

function expandAll() {
  expandedFcs.value = new Set(rows.value.map(r => r.fcId));
  const keys = new Set();
  for (const fc of rows.value) {
    for (const p of fc.products) keys.add(`${fc.fcId}-${p.productId}`);
  }
  expandedProducts.value = keys;
}

function collapseAll() {
  expandedFcs.value      = new Set();
  expandedProducts.value = new Set();
}

function resetFilters() {
  filterProducts.value = [];
  loadData();
}

function reload() {
  productOptions.value = [];
  filterProducts.value = [];
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = new URLSearchParams();
    params.set('from', periodFrom.value);
    params.set('to',   periodTo.value);
    filterProducts.value.forEach(p => params.append('products[]', p));

    const { data } = await api.get(`/admin/reports/sales-matrix/fc?${params}`);
    rows.value        = data.rows        ?? [];
    months.value      = data.period?.months ?? [];
    grandTotals.value = data.grandTotals ?? null;
    if (!productOptions.value.length) productOptions.value = data.products ?? [];
  } catch (e) {
    console.error('fc-matrix load failed', e);
  }
  loading.value = false;
}

// ─── Formatting ───────────────────────────────────────────────
function fmtRub(val) {
  if (!val) return '0 ₽';
  const n = Number(val);
  if (n >= 1_000_000_000) return (n / 1_000_000_000).toLocaleString('ru-RU', { maximumFractionDigits: 1 }) + ' млрд ₽';
  if (n >= 1_000_000)     return (n / 1_000_000).toLocaleString('ru-RU', { maximumFractionDigits: 1 }) + ' млн ₽';
  if (n >= 1_000)         return (n / 1_000).toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' тыс ₽';
  return n.toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' ₽';
}

function fmtCell(val, m) {
  if (val == null || val === '') return '—';
  const n = Number(val);
  if (isNaN(n) || n === 0) return '—';
  if (m.fmt === 'int') return n.toLocaleString('ru-RU');
  if (m.fmt === 'rub') {
    if (n >= 1_000_000) return (n / 1_000_000).toLocaleString('ru-RU', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' M';
    if (n >= 1_000)     return n.toLocaleString('ru-RU', { maximumFractionDigits: 0 });
    return n.toLocaleString('ru-RU', { maximumFractionDigits: 2 });
  }
  return n.toLocaleString('ru-RU', { maximumFractionDigits: 2 });
}

const MONTHS_SHORT = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
function fmtMonthHdr(dm) {
  const [, m] = (dm || '').split('-');
  return MONTHS_SHORT[parseInt(m, 10) - 1] ?? dm;
}

onMounted(loadData);
</script>

<style scoped>
.matrix-table {
  border-collapse: collapse;
  width: 100%;
  font-size: 13px;
}
.matrix-table th,
.matrix-table td {
  padding: 6px 10px;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.07);
  white-space: nowrap;
}
.matrix-table th {
  background: rgba(var(--v-theme-surface-variant), 0.5);
  font-weight: 600;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: rgba(var(--v-theme-on-surface), 0.6);
}

/* Month group header */
.month-group {
  text-align: center;
  border-right: 2px solid rgba(var(--v-theme-on-surface), 0.1);
  padding: 8px 10px;
}
.total-group {
  background: rgba(var(--v-theme-primary), 0.06);
}

/* Sub-metric headers */
.col-num-sub {
  text-align: right;
  font-variant-numeric: tabular-nums;
  min-width: 80px;
  font-size: 10px;
}
.total-sub {
  background: rgba(var(--v-theme-primary), 0.04);
}

/* Name column */
.col-name { min-width: 200px; max-width: 280px; }
.cell-name {
  display: flex;
  align-items: center;
}
.cell-level1 { padding-left: 20px; }
.cell-level2 { padding-left: 40px; font-size: 12px; }
.fc-name { font-weight: 600; }

/* Numeric cells */
.col-num {
  text-align: right;
  font-variant-numeric: tabular-nums;
  min-width: 75px;
}
.total-cell {
  background: rgba(var(--v-theme-primary), 0.04);
  font-weight: 600;
  border-left: 1px solid rgba(var(--v-theme-primary), 0.15);
}
.num-dim { color: rgba(var(--v-theme-on-surface), 0.7); font-weight: 400; }

/* Row types */
.row-fc {
  cursor: pointer;
  background: rgba(var(--v-theme-surface), 1);
}
.row-fc:hover td { background: rgba(var(--v-theme-primary), 0.05) !important; }
.row-fc td { font-weight: 500; border-top: 1px solid rgba(var(--v-theme-on-surface), 0.1); }

.row-product { cursor: pointer; }
.row-product:hover td { background: rgba(var(--v-theme-primary), 0.03) !important; }

.row-program:hover td { background: rgba(var(--v-theme-on-surface), 0.02) !important; }

.row-totals td {
  background: rgba(var(--v-theme-primary), 0.08) !important;
  border-top: 2px solid rgba(var(--v-theme-primary), 0.25);
}
</style>