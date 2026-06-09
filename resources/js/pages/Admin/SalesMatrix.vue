<template>
  <div>
    <PageHeader title="Матрица продаж по продуктам" icon="mdi-table-large" />

    <!-- Filters -->
    <v-card class="ds-card mb-3 pa-3" elevation="0">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-select
          v-model="year"
          :items="yearOptions"
          label="Год"
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:100px"
          @update:model-value="onYearChange" />

        <v-autocomplete
          v-model="filterSuppliers"
          :items="supplierOptions"
          label="Поставщик"
          multiple
          chips
          closable-chips
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:280px"
          @update:model-value="loadData" />

        <v-autocomplete
          v-model="filterProducts"
          :items="productOptions"
          item-title="name"
          item-value="id"
          label="Продукт"
          multiple
          chips
          closable-chips
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:280px"
          @update:model-value="loadData" />

        <v-btn v-if="filterSuppliers.length || filterProducts.length"
          size="small" variant="text" prepend-icon="mdi-filter-remove"
          @click="resetFilters">Сбросить</v-btn>

        <v-spacer />

        <!-- Expand/collapse all -->
        <v-btn size="small" variant="text" prepend-icon="mdi-expand-all-outline"
          @click="expandAll">Развернуть</v-btn>
        <v-btn size="small" variant="text" prepend-icon="mdi-collapse-all-outline"
          @click="collapseAll">Свернуть</v-btn>

        <!-- Column toggles -->
        <v-menu :close-on-content-click="false" location="bottom end">
          <template #activator="{ props }">
            <v-btn v-bind="props" size="small" variant="outlined" prepend-icon="mdi-view-column-outline">
              Метрики ({{ visibleMetrics.length }}/{{ allMetrics.length }})
            </v-btn>
          </template>
          <v-card min-width="210">
            <v-list density="compact" class="pa-1">
              <v-list-item v-for="m in allMetrics" :key="m.key"
                :title="m.label" style="cursor:pointer" @click="toggleMetric(m.key)">
                <template #prepend>
                  <v-checkbox-btn :model-value="visibleMetrics.includes(m.key)" color="primary" />
                </template>
              </v-list-item>
            </v-list>
          </v-card>
        </v-menu>

        <!-- View mode toggle -->
        <v-btn-toggle v-model="viewMode" density="compact" variant="outlined" mandatory>
          <v-btn value="year" size="small" prepend-icon="mdi-calendar-text">Год</v-btn>
          <v-btn value="monthly" size="small" prepend-icon="mdi-calendar-month">Месяцы</v-btn>
        </v-btn-toggle>
      </div>
    </v-card>

    <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-1" />

    <!-- Summary chips -->
    <div v-if="grandTotals && !loading" class="d-flex ga-2 flex-wrap mb-3">
      <v-chip size="small" variant="tonal" color="primary">
        <v-icon start size="14">mdi-package-variant</v-icon>
        {{ rows.length }} продуктов
      </v-chip>
      <v-chip size="small" variant="tonal" color="secondary">
        <v-icon start size="14">mdi-file-document-outline</v-icon>
        {{ grandTotals.count.toLocaleString('ru-RU') }} контрактов
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

    <!-- ─── YEAR VIEW ──────────────────────────────────────────── -->
    <v-card v-if="viewMode === 'year' && !loading" class="ds-card" elevation="0">
      <div style="overflow-x:auto">
        <table class="matrix-table">
          <thead>
            <tr>
              <th class="col-product">Продукт / Программа</th>
              <th class="col-supplier">Поставщик</th>
              <th v-for="m in activeMetrics" :key="m.key" class="col-num">{{ m.label }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="prod in rows" :key="prod.productId">
              <!-- Product row -->
              <tr class="row-product" @click="toggleProduct(prod.productId)">
                <td class="col-product">
                  <div class="cell-name">
                    <v-icon size="15" class="mr-1 text-primary">
                      {{ expandedProducts.has(prod.productId) ? 'mdi-chevron-down' : 'mdi-chevron-right' }}
                    </v-icon>
                    {{ prod.productName }}
                    <span class="prog-count">{{ prod.programs.length }}</span>
                  </div>
                </td>
                <td class="col-supplier">
                  <span v-if="prod.suppliers?.length" class="supplier-list">
                    {{ prod.suppliers.join(', ') }}
                  </span>
                  <span v-else class="text-disabled">—</span>
                </td>
                <td v-for="m in activeMetrics" :key="m.key" class="col-num num-product">
                  {{ fmtCell(prod[m.key], m) }}
                </td>
              </tr>
              <!-- Program sub-rows -->
              <template v-if="expandedProducts.has(prod.productId)">
                <tr v-for="pg in prod.programs" :key="pg.programId" class="row-program">
                  <td class="col-product">
                    <div class="cell-name cell-sub">
                      <span class="sub-connector"></span>
                      {{ pg.programName }}
                    </div>
                  </td>
                  <td class="col-supplier">
                    <v-chip v-if="pg.supplier && pg.supplier !== '—'"
                      size="x-small" variant="tonal" color="secondary">
                      {{ pg.supplier }}
                    </v-chip>
                    <span v-else class="text-disabled">—</span>
                  </td>
                  <td v-for="m in activeMetrics" :key="m.key" class="col-num num-program">
                    {{ fmtCell(pg[m.key], m) }}
                  </td>
                </tr>
              </template>
            </template>

            <!-- Grand totals row -->
            <tr v-if="grandTotals" class="row-totals">
              <td class="col-product"><strong>ИТОГО</strong></td>
              <td class="col-supplier"></td>
              <td v-for="m in activeMetrics" :key="m.key" class="col-num num-product">
                <strong>{{ fmtCell(grandTotals[m.key], m) }}</strong>
              </td>
            </tr>

            <tr v-if="!rows.length && !loading">
              <td :colspan="2 + activeMetrics.length" class="text-center pa-6 text-medium-emphasis">
                Нет данных за {{ year }} год
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </v-card>

    <!-- ─── MONTHLY VIEW ───────────────────────────────────────── -->
    <v-card v-if="viewMode === 'monthly' && !loading" class="ds-card" elevation="0">
      <div class="pa-3 pb-1 d-flex align-center ga-3">
        <v-select v-model="monthlyMetric" :items="allMetrics" item-title="label" item-value="key"
          label="Метрика" density="compact" variant="outlined" hide-details style="max-width:200px" />
        <span class="text-caption text-medium-emphasis">
          {{ activeMetricDef?.label }} по месяцам {{ year }}
        </span>
      </div>
      <div style="overflow-x:auto">
        <table class="matrix-table">
          <thead>
            <tr>
              <th class="col-product">Продукт / Программа</th>
              <th class="col-supplier">Поставщик</th>
              <th v-for="mo in months" :key="mo" class="col-num">{{ fmtMonthHdr(mo) }}</th>
              <th class="col-num col-total">Итого</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="prod in rows" :key="'m-' + prod.productId">
              <tr class="row-product" @click="toggleProduct(prod.productId)">
                <td class="col-product">
                  <div class="cell-name">
                    <v-icon size="15" class="mr-1 text-primary">
                      {{ expandedProducts.has(prod.productId) ? 'mdi-chevron-down' : 'mdi-chevron-right' }}
                    </v-icon>
                    {{ prod.productName }}
                  </div>
                </td>
                <td class="col-supplier">
                  <span v-if="prod.suppliers?.length" class="supplier-list">{{ prod.suppliers.join(', ') }}</span>
                  <span v-else class="text-disabled">—</span>
                </td>
                <td v-for="mo in months" :key="mo" class="col-num num-product">
                  {{ fmtCell(productMonthly(prod.productId, mo), activeMetricDef) }}
                </td>
                <td class="col-num col-total num-product">
                  {{ fmtCell(prod[monthlyMetric], activeMetricDef) }}
                </td>
              </tr>
              <template v-if="expandedProducts.has(prod.productId)">
                <tr v-for="pg in prod.programs" :key="'m-' + pg.programId" class="row-program">
                  <td class="col-product">
                    <div class="cell-name cell-sub">
                      <span class="sub-connector"></span>
                      {{ pg.programName }}
                    </div>
                  </td>
                  <td class="col-supplier">
                    <v-chip v-if="pg.supplier && pg.supplier !== '—'"
                      size="x-small" variant="tonal" color="secondary">{{ pg.supplier }}</v-chip>
                    <span v-else class="text-disabled">—</span>
                  </td>
                  <td v-for="mo in months" :key="mo" class="col-num num-program">
                    {{ fmtCell(programMonthly(prod.productId, pg.programId, mo), activeMetricDef) }}
                  </td>
                  <td class="col-num col-total num-program">
                    {{ fmtCell(pg[monthlyMetric], activeMetricDef) }}
                  </td>
                </tr>
              </template>
            </template>
            <tr v-if="!rows.length && !loading">
              <td :colspan="2 + months.length + 1" class="text-center pa-6 text-medium-emphasis">
                Нет данных за {{ year }} год
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </v-card>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';

const loading = ref(false);
const rows = ref([]);
const grandTotals = ref(null);
const supplierOptions = ref([]);
const productOptions = ref([]);

const year = ref(new Date().getFullYear());
const filterSuppliers = ref([]);
const filterProducts = ref([]);
const viewMode = ref('year');
const monthlyMetric = ref('volume');
const monthlyData = ref({});
const months = ref([]);

const yearOptions = Array.from({ length: 7 }, (_, i) => new Date().getFullYear() - i);

const allMetrics = [
  { key: 'volume',      label: 'Объём (₽)',       fmt: 'rub'  },
  { key: 'count',       label: 'Кол-во (шт)',      fmt: 'int'  },
  { key: 'avgCheck',    label: 'Средний чек (₽)',  fmt: 'rub'  },
  { key: 'revenue',     label: 'Выручка (₽)',      fmt: 'rub'  },
  { key: 'points',      label: 'Баллы',            fmt: 'num'  },
  { key: 'fcCount',     label: 'Кол-во ФК',        fmt: 'int'  },
  { key: 'clientCount', label: 'Кол-во клиентов',  fmt: 'int'  },
];

const visibleMetrics = ref(['volume', 'count', 'avgCheck', 'revenue', 'points', 'fcCount', 'clientCount']);
const expandedProducts = ref(new Set());

const activeMetrics = computed(() => allMetrics.filter(m => visibleMetrics.value.includes(m.key)));
const activeMetricDef = computed(() => allMetrics.find(m => m.key === monthlyMetric.value) ?? allMetrics[0]);

function toggleMetric(key) {
  const idx = visibleMetrics.value.indexOf(key);
  if (idx === -1) visibleMetrics.value.push(key);
  else if (visibleMetrics.value.length > 1) visibleMetrics.value.splice(idx, 1);
}

function toggleProduct(id) {
  const s = new Set(expandedProducts.value);
  if (s.has(id)) s.delete(id); else s.add(id);
  expandedProducts.value = s;
}

function expandAll() {
  expandedProducts.value = new Set(rows.value.map(r => r.productId));
}

function collapseAll() {
  expandedProducts.value = new Set();
}

function resetFilters() {
  filterSuppliers.value = [];
  filterProducts.value = [];
  loadData();
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

function fmtCell(val, metricDef) {
  if (val == null || val === '') return '—';
  const m = metricDef ?? { fmt: 'num' };
  const n = Number(val);
  if (isNaN(n)) return '—';
  if (n === 0) return '—';
  if (m.fmt === 'int') return n.toLocaleString('ru-RU');
  if (m.fmt === 'rub') {
    // Крупные суммы — сокращённо
    if (n >= 1_000_000) return (n / 1_000_000).toLocaleString('ru-RU', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' M';
    if (n >= 1_000)     return n.toLocaleString('ru-RU', { maximumFractionDigits: 0 });
    return n.toLocaleString('ru-RU', { maximumFractionDigits: 2 });
  }
  return n.toLocaleString('ru-RU', { maximumFractionDigits: 2 });
}

const MONTHS_SHORT = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн',
  'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];

function fmtMonthHdr(dm) {
  const [, m] = (dm || '').split('-');
  return MONTHS_SHORT[parseInt(m, 10) - 1] ?? dm;
}

function productMonthly(productId, mo) {
  let total = 0;
  for (const pgData of Object.values(monthlyData.value[productId] ?? {})) {
    total += Number(pgData[mo]?.[monthlyMetric.value] ?? 0);
  }
  return total || null;
}

function programMonthly(productId, programId, mo) {
  return monthlyData.value[productId]?.[programId]?.[mo]?.[monthlyMetric.value] || null;
}

// ─── Data loading ─────────────────────────────────────────────
async function loadData() {
  loading.value = true;
  try {
    const params = new URLSearchParams();
    params.set('year', year.value);
    filterSuppliers.value.forEach(s => params.append('suppliers[]', s));
    filterProducts.value.forEach(p => params.append('products[]', p));

    const qs = params.toString();
    const [main, mon] = await Promise.all([
      api.get(`/admin/reports/sales-matrix?${qs}`),
      api.get(`/admin/reports/sales-matrix/monthly?${qs}`),
    ]);

    rows.value        = main.data.rows ?? [];
    grandTotals.value = main.data.grandTotals ?? null;

    if (!supplierOptions.value.length) supplierOptions.value = main.data.suppliers ?? [];
    if (!productOptions.value.length)  productOptions.value  = main.data.products  ?? [];

    months.value      = mon.data.months ?? [];
    monthlyData.value = mon.data.data   ?? {};

  } catch (e) {
    console.error('sales-matrix load failed', e);
  }
  loading.value = false;
}

function onYearChange() {
  supplierOptions.value = [];
  productOptions.value  = [];
  filterSuppliers.value = [];
  filterProducts.value  = [];
  loadData();
}

onMounted(loadData);
</script>

<style scoped>
/* ─── Matrix table base ─── */
.matrix-table {
  border-collapse: collapse;
  width: 100%;
  font-size: 13px;
}

.matrix-table th,
.matrix-table td {
  padding: 7px 10px;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.07);
  white-space: nowrap;
}

.matrix-table th {
  background: rgba(var(--v-theme-surface-variant), 0.5);
  font-weight: 600;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: rgba(var(--v-theme-on-surface), 0.65);
}

/* ─── Column widths ─── */
.col-product { min-width: 220px; max-width: 280px; }
.col-supplier { min-width: 120px; max-width: 160px; }
.col-num {
  min-width: 90px;
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.col-total {
  background: rgba(var(--v-theme-primary), 0.05);
  font-weight: 600;
}

/* ─── Row types ─── */
.row-product {
  cursor: pointer;
  background: rgba(var(--v-theme-surface), 1);
}
.row-product:hover { background: rgba(var(--v-theme-primary), 0.05) !important; }
.row-product td { font-weight: 500; }

.row-program td { background: transparent; }
.row-program:hover td { background: rgba(var(--v-theme-primary), 0.03) !important; }

.row-totals td {
  background: rgba(var(--v-theme-primary), 0.07) !important;
  border-top: 2px solid rgba(var(--v-theme-primary), 0.2);
}

/* ─── Cell content ─── */
.cell-name {
  display: flex;
  align-items: center;
  gap: 4px;
}
.cell-sub {
  padding-left: 24px;
  font-size: 12px;
  font-weight: 400;
}
.sub-connector {
  display: inline-block;
  width: 14px;
  height: 14px;
  border-left: 1px solid rgba(var(--v-theme-on-surface), 0.2);
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.2);
  margin-right: 4px;
  flex-shrink: 0;
  position: relative;
  top: -4px;
}

.prog-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 4px;
  border-radius: 9px;
  background: rgba(var(--v-theme-primary), 0.12);
  color: rgb(var(--v-theme-primary));
  font-size: 11px;
  font-weight: 700;
  margin-left: 4px;
}

.num-product { color: rgb(var(--v-theme-on-surface)); }
.num-program { color: rgba(var(--v-theme-on-surface), 0.75); }

.supplier-list {
  font-size: 11px;
  color: rgba(var(--v-theme-on-surface), 0.65);
}
</style>
