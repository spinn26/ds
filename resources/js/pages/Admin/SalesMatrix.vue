<template>
  <div>
    <!-- Header + mode toggle -->
    <div class="d-flex align-center mb-4 ga-3">
      <PageHeader title="Матрица продаж по продуктам" icon="mdi-table-large" class="flex-grow-1 mb-0" />
      <v-btn-toggle v-model="reportMode" density="compact" variant="outlined" mandatory color="primary">
        <v-btn value="actual"   size="small" prepend-icon="mdi-check-circle-outline">Фактический</v-btn>
        <v-btn value="forecast" size="small" prepend-icon="mdi-chart-timeline-variant">Прогнозный</v-btn>
      </v-btn-toggle>
    </div>

    <!-- Forecast placeholder -->
    <v-card v-if="reportMode === 'forecast'" class="pa-10 text-center" elevation="0">
      <v-icon size="56" color="primary" class="mb-4 d-block">mdi-chart-timeline-variant</v-icon>
      <div class="text-h6 mb-2">Прогнозный отчёт</div>
      <div class="text-body-2 text-medium-emphasis">В разработке — появится после заполнения прогнозных дат активации контрактов.</div>
    </v-card>

    <template v-if="reportMode === 'actual'">
      <!-- Filter bar -->
      <v-card class="ds-card mb-3" elevation="0">
        <v-card-text class="pa-2">
          <div class="d-flex ga-1 flex-wrap align-center">

            <!-- Period mode selector -->
            <v-btn-toggle v-model="periodMode" mandatory density="compact" variant="outlined" color="primary"
              @update:model-value="onPeriodModeChange">
              <v-btn value="year"    size="x-small">Год</v-btn>
              <v-btn value="quarter" size="x-small">Квартал</v-btn>
              <v-btn value="month"   size="x-small">Месяц</v-btn>
              <v-btn value="range"   size="x-small">Диапазон</v-btn>
            </v-btn-toggle>

            <!-- Year (all modes except range) -->
            <v-select v-if="periodMode !== 'range'" v-model="periodYear" :items="yearOptions"
              density="compact" variant="outlined" hide-details style="width:92px; flex:0 0 92px"
              @update:model-value="reload" />

            <!-- Q1–Q4 -->
            <v-btn-toggle v-if="periodMode === 'quarter'" v-model="periodQuarter" mandatory
              density="compact" variant="outlined" @update:model-value="reload">
              <v-btn v-for="q in ['Q1','Q2','Q3','Q4']" :key="q" :value="q" size="x-small">{{ q }}</v-btn>
            </v-btn-toggle>

            <!-- Single month -->
            <v-select v-if="periodMode === 'month'" v-model="periodMonth" :items="monthOpts"
              item-title="t" item-value="v" density="compact" variant="outlined"
              hide-details style="width:128px; flex:0 0 128px" @update:model-value="reload" />

            <!-- Custom range -->
            <template v-if="periodMode === 'range'">
              <v-text-field v-model="rangeFrom" type="month" density="compact" variant="outlined"
                hide-details label="С" style="width:148px; flex:0 0 148px" @update:model-value="reload" />
              <span class="text-medium-emphasis mx-1">—</span>
              <v-text-field v-model="rangeTo" type="month" density="compact" variant="outlined"
                hide-details label="По" style="width:148px; flex:0 0 148px" @update:model-value="reload" />
            </template>

            <v-divider vertical class="mx-1" style="height:24px;align-self:center" />

            <!-- Supplier filter -->
            <v-autocomplete v-model="filterSuppliers" :items="supplierOptions"
              placeholder="Поставщик" prepend-inner-icon="mdi-domain"
              multiple chips closable-chips density="compact" variant="outlined"
              hide-details style="width:190px; flex:0 0 190px" @update:model-value="loadData" />

            <!-- Product filter -->
            <v-autocomplete v-model="filterProducts" :items="productOptions"
              item-title="name" item-value="id" placeholder="Продукт"
              prepend-inner-icon="mdi-magnify"
              multiple chips closable-chips density="compact" variant="outlined"
              hide-details style="width:220px; flex:0 0 220px" @update:model-value="loadData" />

            <v-btn v-if="filterProducts.length || filterSuppliers.length"
              icon="mdi-filter-remove" size="x-small" variant="text" title="Сбросить" @click="resetFilters" />

            <v-spacer />

            <v-btn size="x-small" variant="text" prepend-icon="mdi-expand-all-outline" @click="expandAll">Все</v-btn>
            <v-btn size="x-small" variant="text" prepend-icon="mdi-collapse-all-outline" @click="collapseAll">Свернуть</v-btn>

            <!-- Metrics selector -->
            <v-menu :close-on-content-click="false" location="bottom end">
              <template #activator="{ props }">
                <v-btn v-bind="props" size="x-small" variant="tonal" color="primary"
                  prepend-icon="mdi-tune">
                  Метрики · {{ selectedMetricKeys.length }}
                </v-btn>
              </template>
              <v-card min-width="210" elevation="4">
                <v-card-title class="text-body-2 pa-3 pb-1 font-weight-medium">Метрики</v-card-title>
                <v-divider />
                <v-list density="compact" class="pa-1">
                  <v-list-item v-for="m in allMetrics" :key="m.key" :title="m.label"
                    rounded="lg" style="cursor:pointer" @click="toggleMetric(m.key)">
                    <template #prepend>
                      <v-checkbox-btn :model-value="selectedMetricKeys.includes(m.key)"
                        color="primary" density="compact"
                        @click.stop="toggleMetric(m.key)" />
                    </template>
                  </v-list-item>
                </v-list>
              </v-card>
            </v-menu>
          </div>
        </v-card-text>
      </v-card>

      <v-progress-linear v-if="loading" indeterminate color="primary" rounded class="mb-3" />

      <!-- Summary chips -->
      <div v-if="grandTotals && !loading" class="d-flex ga-2 flex-wrap mb-3">
        <v-chip size="small" variant="tonal" color="primary"   prepend-icon="mdi-package-variant">{{ rows.length }} продуктов</v-chip>
        <v-chip size="small" variant="tonal" color="secondary" prepend-icon="mdi-file-document-outline">{{ fmt0(grandTotals.count) }} контрактов</v-chip>
        <v-chip size="small" variant="tonal"                   prepend-icon="mdi-cash">{{ fmtRub(grandTotals.volume) }} объём</v-chip>
        <v-chip size="small" variant="tonal" color="success"   prepend-icon="mdi-trending-up">{{ fmtRub(grandTotals.revenue) }} выручка</v-chip>
        <v-chip size="small" variant="tonal" color="info"      prepend-icon="mdi-account-group-outline">{{ fmt0(grandTotals.fcCount) }} ФК</v-chip>
      </div>

      <!-- Matrix table -->
      <v-card v-if="!loading" class="ds-card mx-card-wrap" elevation="0">
        <div class="mx-scroll">
          <table class="mx-tbl">
            <thead>
              <!-- Row 1: month groups -->
              <tr>
                <th class="th-name" rowspan="2">Продукт / Программа</th>
                <th v-for="mo in months" :key="mo"
                  :colspan="activeMetrics.length" class="th-mgroup">
                  {{ fmtMonthHdr(mo) }}
                </th>
                <th :colspan="activeMetrics.length" class="th-mgroup th-total-hd">
                  Итого {{ periodLabel }}
                </th>
              </tr>
              <!-- Row 2: metric sub-labels -->
              <tr>
                <template v-for="mo in months" :key="`sh-${mo}`">
                  <th v-for="(m, mi) in activeMetrics" :key="m.key"
                    class="th-sub" :class="{ 'th-sub-last': mi === activeMetrics.length - 1 }">
                    {{ m.short }}
                  </th>
                </template>
                <th v-for="m in activeMetrics" :key="`tot-${m.key}`" class="th-sub th-sub-total">
                  {{ m.short }}
                </th>
              </tr>
            </thead>
            <tbody>
              <template v-for="prod in rows" :key="prod.productId">
                <!-- Product -->
                <tr class="tr-prod" @click="toggleProduct(prod.productId)">
                  <td class="td-name">
                    <div class="cell-row">
                      <v-icon size="14" class="ico-expand">
                        {{ expandedProducts.has(prod.productId) ? 'mdi-chevron-down' : 'mdi-chevron-right' }}
                      </v-icon>
                      <span class="label-prod">{{ prod.productName }}</span>
                      <span class="prog-pill">{{ prod.programs.length }}</span>
                    </div>
                  </td>
                  <template v-for="mo in months" :key="`p${prod.productId}-${mo}`">
                    <td v-for="(m, mi) in activeMetrics" :key="m.key"
                      class="td-num" :class="{ 'td-sep': mi === activeMetrics.length - 1 }">
                      <span :class="fmtClass(prod.monthly[mo]?.[m.key])">
                        {{ fmtCell(prod.monthly[mo]?.[m.key], m) }}
                      </span>
                    </td>
                  </template>
                  <td v-for="m in activeMetrics" :key="`pt-${m.key}`" class="td-num td-total">
                    {{ fmtCell(prod[m.key], m) }}
                  </td>
                </tr>

                <!-- Programs -->
                <template v-if="expandedProducts.has(prod.productId)">
                  <tr v-for="pg in prod.programs" :key="pg.programId" class="tr-prog">
                    <td class="td-name">
                      <div class="cell-row cell-l2">
                        <span class="tree-arm"></span>
                        <span class="label-prog">{{ pg.programName }}</span>
                      </div>
                    </td>
                    <template v-for="mo in months" :key="`pg${pg.programId}-${mo}`">
                      <td v-for="(m, mi) in activeMetrics" :key="m.key"
                        class="td-num td-dim" :class="{ 'td-sep': mi === activeMetrics.length - 1 }">
                        <span :class="fmtClass(pg.monthly[mo]?.[m.key])">
                          {{ fmtCell(pg.monthly[mo]?.[m.key], m) }}
                        </span>
                      </td>
                    </template>
                    <td v-for="m in activeMetrics" :key="`pgt-${m.key}`" class="td-num td-total td-dim">
                      {{ fmtCell(pg[m.key], m) }}
                    </td>
                  </tr>
                </template>
              </template>

              <!-- Grand totals -->
              <tr v-if="grandTotals && rows.length" class="tr-grand">
                <td class="td-name"><strong>ИТОГО</strong></td>
                <template v-for="mo in months" :key="`g-${mo}`">
                  <td v-for="(m, mi) in activeMetrics" :key="m.key"
                    class="td-num" :class="{ 'td-sep': mi === activeMetrics.length - 1 }">
                    <strong>{{ fmtCell(grandTotals.monthly[mo]?.[m.key], m) }}</strong>
                  </td>
                </template>
                <td v-for="m in activeMetrics" :key="`gt-${m.key}`" class="td-num td-total">
                  <strong>{{ fmtCell(grandTotals[m.key], m) }}</strong>
                </td>
              </tr>

              <tr v-if="!rows.length && !loading">
                <td :colspan="1 + months.length * activeMetrics.length + activeMetrics.length" class="td-empty">
                  <v-icon class="mb-2 d-block mx-auto" size="36" color="grey-lighten-1">mdi-table-off</v-icon>
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
const now = new Date();
const currentQ = `Q${Math.ceil((now.getMonth() + 1) / 3)}`;

const periodMode    = ref('quarter');
const periodYear    = ref(now.getFullYear());
const periodQuarter = ref(currentQ);
const periodMonth   = ref(String(now.getMonth() + 1).padStart(2, '0'));
const rangeFrom     = ref(`${now.getFullYear()}-01`);
const rangeTo       = ref(`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`);

const yearOptions   = Array.from({ length: 7 }, (_, i) => now.getFullYear() - i);
const quarterRanges = { Q1: [1,3], Q2: [4,6], Q3: [7,9], Q4: [10,12] };
const monthOpts = [
  { t: 'Январь', v: '01' }, { t: 'Февраль', v: '02' }, { t: 'Март', v: '03' },
  { t: 'Апрель', v: '04' }, { t: 'Май',     v: '05' }, { t: 'Июнь', v: '06' },
  { t: 'Июль',   v: '07' }, { t: 'Август',  v: '08' }, { t: 'Сентябрь', v: '09' },
  { t: 'Октябрь',v: '10' }, { t: 'Ноябрь', v: '11' }, { t: 'Декабрь',  v: '12' },
];

const periodFrom = computed(() => {
  const y = periodYear.value;
  if (periodMode.value === 'year')    return `${y}-01`;
  if (periodMode.value === 'quarter') return `${y}-${String(quarterRanges[periodQuarter.value][0]).padStart(2,'0')}`;
  if (periodMode.value === 'month')   return `${y}-${periodMonth.value}`;
  return rangeFrom.value;
});
const periodTo = computed(() => {
  const y = periodYear.value;
  if (periodMode.value === 'year')    return `${y}-12`;
  if (periodMode.value === 'quarter') return `${y}-${String(quarterRanges[periodQuarter.value][1]).padStart(2,'0')}`;
  if (periodMode.value === 'month')   return `${y}-${periodMonth.value}`;
  return rangeTo.value;
});
const periodLabel = computed(() => {
  if (periodMode.value === 'year')    return String(periodYear.value);
  if (periodMode.value === 'quarter') return `${periodQuarter.value} ${periodYear.value}`;
  if (periodMode.value === 'month')   return `${monthOpts.find(m => m.v === periodMonth.value)?.t} ${periodYear.value}`;
  return `${rangeFrom.value} — ${rangeTo.value}`;
});

// ─── Metrics ──────────────────────────────────────────────────
const allMetrics = [
  { key: 'volume',      short: 'Объём',    label: 'Объём ($)',         fmt: 'rub' },
  { key: 'count',       short: 'Кол-во',   label: 'Кол-во (шт)',       fmt: 'int' },
  { key: 'avgCheck',    short: 'Ср.чек',   label: 'Средний чек ($)',   fmt: 'rub' },
  { key: 'revenue',     short: 'Выручка',  label: 'Выручка ($)',       fmt: 'rub' },
  { key: 'points',      short: 'Баллы',    label: 'Баллы',             fmt: 'num' },
  { key: 'fcCount',     short: 'Кол-во ФК', label: 'Кол-во ФК',       fmt: 'int' },
  { key: 'clientCount', short: 'Клиенты',  label: 'Кол-во клиентов',  fmt: 'int' },
];
const selectedMetricKeys = ref(['volume', 'revenue']);
const activeMetrics = computed(() => allMetrics.filter(m => selectedMetricKeys.value.includes(m.key)));

function toggleMetric(key) {
  const idx = selectedMetricKeys.value.indexOf(key);
  if (idx !== -1) { if (selectedMetricKeys.value.length > 1) selectedMetricKeys.value.splice(idx, 1); }
  else selectedMetricKeys.value.push(key);
}

// ─── Data ─────────────────────────────────────────────────────
const loading          = ref(false);
const rows             = ref([]);
const grandTotals      = ref(null);
const months           = ref([]);
const supplierOptions  = ref([]);
const filterSuppliers  = ref([]);
const productOptions   = ref([]);
const filterProducts   = ref([]);
const expandedProducts = ref(new Set());

function toggleProduct(pid) {
  const s = new Set(expandedProducts.value);
  if (s.has(pid)) s.delete(pid); else s.add(pid);
  expandedProducts.value = s;
}
function expandAll()   { expandedProducts.value = new Set(rows.value.map(r => r.productId)); }
function collapseAll() { expandedProducts.value = new Set(); }
function resetFilters() { filterProducts.value = []; filterSuppliers.value = []; loadData(); }
function reload() { productOptions.value = []; supplierOptions.value = []; loadData(); }
function onPeriodModeChange() { reload(); }

async function loadData() {
  loading.value = true;
  try {
    const p = new URLSearchParams();
    p.set('from', periodFrom.value);
    p.set('to',   periodTo.value);
    filterSuppliers.value.forEach(s => p.append('suppliers[]', s));
    filterProducts.value.forEach(id => p.append('products[]', id));
    const { data } = await api.get(`/admin/reports/sales-matrix/period?${p}`);
    rows.value        = data.rows           ?? [];
    months.value      = data.period?.months ?? [];
    grandTotals.value = data.grandTotals    ?? null;
    if (!supplierOptions.value.length) supplierOptions.value = data.suppliers ?? [];
    if (!productOptions.value.length)  productOptions.value  = data.products  ?? [];
  } catch (e) { console.error('matrix load failed', e); }
  loading.value = false;
}

// ─── Formatting ───────────────────────────────────────────────
const MONTHS_SHORT = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
function fmtMonthHdr(dm) {
  const [, m] = (dm || '').split('-');
  return MONTHS_SHORT[parseInt(m, 10) - 1] ?? dm;
}

function fmt0(val) { return Number(val || 0).toLocaleString('ru-RU'); }

function fmtRub(val) {
  const n = Number(val || 0);
  if (n >= 1e9) return (n/1e9).toLocaleString('ru-RU', { maximumFractionDigits: 1 }) + ' млрд ₽';
  if (n >= 1e6) return (n/1e6).toLocaleString('ru-RU', { maximumFractionDigits: 1 }) + ' млн ₽';
  if (n >= 1e3) return (n/1e3).toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' тыс ₽';
  return n.toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' ₽';
}

function fmtCell(val, m) {
  if (val == null) return '—';
  const n = Number(val);
  if (isNaN(n) || n === 0) return '—';
  if (m.fmt === 'int') return n.toLocaleString('ru-RU');
  if (m.fmt === 'rub') {
    if (n >= 1e6) return (n/1e6).toLocaleString('ru-RU', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' M';
    if (n >= 1e3) return n.toLocaleString('ru-RU', { maximumFractionDigits: 0 });
    return n.toLocaleString('ru-RU', { maximumFractionDigits: 2 });
  }
  return n.toLocaleString('ru-RU', { maximumFractionDigits: 2 });
}

function fmtClass(val) {
  const n = Number(val ?? 0);
  return (!val || isNaN(n) || n === 0) ? 'val-empty' : '';
}

onMounted(loadData);
</script>

<style scoped>
/* ─── Scroll wrapper ─── */
.mx-scroll { overflow-x: auto; }

/* ─── Table base ─── */
.mx-tbl {
  border-collapse: separate;
  border-spacing: 0;
  width: 100%;
  font-size: 13px;
}
.mx-tbl th,
.mx-tbl td {
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.07);
}

/* ─── HEADER ─── */

/* Sticky header rows */
.mx-tbl thead th {
  position: sticky;
  top: 0;
  z-index: 2;
}

/* Name column header (spans 2 rows) */
.th-name {
  position: sticky;
  left: 0;
  z-index: 3;
  text-align: left;
  padding: 10px 14px;
  min-width: 230px;
  max-width: 300px;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: rgba(var(--v-theme-on-surface), 0.45);
  background: rgba(var(--v-theme-surface-variant), 0.9);
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.1);
  border-bottom: 2px solid rgba(var(--v-theme-on-surface), 0.12) !important;
  vertical-align: middle;
}

/* Month group header (level 1) */
.th-mgroup {
  text-align: center;
  padding: 7px 8px 5px;
  font-size: 11px;
  font-weight: 600;
  color: rgba(var(--v-theme-on-surface), 0.75);
  background: rgba(var(--v-theme-surface-variant), 0.9);
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.06) !important;
}

/* Total column group header */
.th-total-hd {
  background: rgba(var(--v-theme-primary), 0.07) !important;
  color: rgb(var(--v-theme-primary)) !important;
  border-left: 2px solid rgba(var(--v-theme-primary), 0.2);
}

/* Metric sub-header (level 2) */
.th-sub {
  text-align: right;
  padding: 4px 8px 6px;
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: rgba(var(--v-theme-on-surface), 0.45);
  background: rgba(var(--v-theme-surface-variant), 0.9);
  min-width: 72px;
  border-bottom: 2px solid rgba(var(--v-theme-on-surface), 0.12) !important;
}
.th-sub-last { border-right: 1px solid rgba(var(--v-theme-on-surface), 0.08); }
.th-sub-total {
  background: rgba(var(--v-theme-primary), 0.06) !important;
  color: rgb(var(--v-theme-primary)) !important;
  border-left: 2px solid rgba(var(--v-theme-primary), 0.18);
}

/* ─── ROWS ─── */

/* Sticky name column (body) */
.td-name {
  position: sticky;
  left: 0;
  z-index: 1;
  background: rgb(var(--v-theme-surface));
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  min-width: 230px;
  max-width: 300px;
  padding: 0;
}

.cell-row {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 6px 10px;
}
.cell-l2 { padding-left: 14px; }

/* Product row */
.tr-prod { cursor: pointer; }
.tr-prod:hover .td-name    { background: rgba(var(--v-theme-primary), 0.04); }
.tr-prod:hover td          { background: rgba(var(--v-theme-primary), 0.03); }
.tr-prod td                { background: rgb(var(--v-theme-surface)); }

.ico-expand { flex-shrink: 0; opacity: 0.55; }
.label-prod { font-weight: 600; color: rgb(var(--v-theme-on-surface)); line-height: 1.3; }
.prog-pill {
  font-size: 10px; font-weight: 700;
  padding: 1px 5px; border-radius: 8px;
  background: rgba(var(--v-theme-primary), 0.12);
  color: rgb(var(--v-theme-primary));
  flex-shrink: 0;
}

/* Program row */
.tr-prog td                { background: rgba(var(--v-theme-surface), 1); }
.tr-prog:hover .td-name   { background: rgba(var(--v-theme-on-surface), 0.015); }
.tr-prog:hover td          { background: rgba(var(--v-theme-on-surface), 0.015); }
.tr-prog .td-name          { background: rgba(var(--v-theme-surface), 1); }

.label-prog {
  font-size: 12px;
  color: rgba(var(--v-theme-on-surface), 0.7);
  font-weight: 400;
}
.tree-arm {
  display: inline-block;
  width: 13px; height: 15px;
  border-left: 1px solid rgba(var(--v-theme-on-surface), 0.2);
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.2);
  margin-right: 5px;
  flex-shrink: 0;
  position: relative;
  top: -3px;
}

/* Numeric cells */
.td-num {
  text-align: right;
  padding: 6px 8px;
  font-variant-numeric: tabular-nums;
  font-size: 12px;
  min-width: 72px;
  color: rgb(var(--v-theme-on-surface));
}
/* Month right separator */
.td-sep { border-right: 1px solid rgba(var(--v-theme-on-surface), 0.07); }

/* Total column */
.td-total {
  background: rgba(var(--v-theme-primary), 0.05);
  border-left: 2px solid rgba(var(--v-theme-primary), 0.15);
  color: rgb(var(--v-theme-primary));
  font-weight: 600;
}

/* Dim (program level) */
.td-dim { color: rgba(var(--v-theme-on-surface), 0.6); font-weight: 400; }
.td-dim.td-total { color: rgba(var(--v-theme-primary), 0.65); font-weight: 500; }

/* Empty/zero value style */
.val-empty { color: rgba(var(--v-theme-on-surface), 0.22); }

/* Grand totals row */
.tr-grand td {
  background: rgba(var(--v-theme-surface-variant), 0.5) !important;
  border-top: 2px solid rgba(var(--v-theme-on-surface), 0.12);
  font-size: 13px;
  padding: 8px 10px;
}
.tr-grand .td-name { background: rgba(var(--v-theme-surface-variant), 0.5) !important; }
.tr-grand .td-total { background: rgba(var(--v-theme-primary), 0.1) !important; }

/* Empty state */
.td-empty {
  text-align: center;
  padding: 48px 20px;
  color: rgba(var(--v-theme-on-surface), 0.35);
  font-size: 14px;
}
</style>