<template>
  <div>
    <PageHeader title="Матрица продаж по продуктам" icon="mdi-table-large" />

    <!-- Filters -->
    <v-card class="ds-card mb-3 pa-3" elevation="0">
      <div class="d-flex ga-3 flex-wrap align-center">
        <v-select
          v-model="year"
          :items="yearOptions"
          label="Год"
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:110px"
          @update:model-value="loadData" />

        <v-autocomplete
          v-model="filterSuppliers"
          :items="supplierOptions"
          label="Поставщик"
          multiple
          chips
          closable-chips
          small-chips
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:320px"
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
          small-chips
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:320px"
          @update:model-value="loadData" />

        <v-btn v-if="filterSuppliers.length || filterProducts.length"
          size="small" variant="text" prepend-icon="mdi-filter-remove"
          @click="resetFilters">Сбросить</v-btn>

        <v-spacer />

        <!-- Metric visibility toggles -->
        <v-menu :close-on-content-click="false" location="bottom end">
          <template #activator="{ props }">
            <v-btn v-bind="props" size="small" variant="outlined" prepend-icon="mdi-view-column-outline">
              Колонки ({{ visibleMetrics.length }})
            </v-btn>
          </template>
          <v-card min-width="200">
            <v-list density="compact">
              <v-list-item v-for="m in allMetrics" :key="m.key"
                :title="m.label" @click="toggleMetric(m.key)">
                <template #prepend>
                  <v-checkbox-btn :model-value="visibleMetrics.includes(m.key)" />
                </template>
              </v-list-item>
            </v-list>
          </v-card>
        </v-menu>

        <v-btn-toggle v-model="viewMode" density="compact" variant="outlined" mandatory>
          <v-btn value="year" size="small">Год</v-btn>
          <v-btn value="monthly" size="small">Месяцы</v-btn>
        </v-btn-toggle>
      </div>
    </v-card>

    <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-2" />

    <!-- YEAR MODE: totals per product/program -->
    <v-card v-if="viewMode === 'year' && !loading" class="ds-card" elevation="0">
      <div style="overflow-x:auto">
        <v-table density="compact" hover>
          <thead>
            <tr>
              <th class="sticky-col" style="min-width:220px">Продукт / Программа</th>
              <th class="sticky-col2" style="min-width:160px">Поставщик</th>
              <th v-for="m in activeMetrics" :key="m.key" class="text-right" style="white-space:nowrap">
                {{ m.label }}
              </th>
            </tr>
          </thead>
          <tbody>
            <template v-for="prod in rows" :key="prod.productId">
              <!-- Product row -->
              <tr class="product-row" @click="toggleProduct(prod.productId)">
                <td class="sticky-col">
                  <div class="d-flex align-center ga-1">
                    <v-icon size="16">
                      {{ expandedProducts.has(prod.productId) ? 'mdi-chevron-down' : 'mdi-chevron-right' }}
                    </v-icon>
                    <span class="font-weight-semibold">{{ prod.productName }}</span>
                    <v-chip size="x-small" variant="tonal" class="ml-1">{{ prod.programs.length }}</v-chip>
                  </div>
                </td>
                <td class="sticky-col2 text-medium-emphasis text-caption">—</td>
                <td v-for="m in activeMetrics" :key="m.key" class="text-right"
                  style="font-variant-numeric:tabular-nums;white-space:nowrap">
                  {{ fmtMetric(prod[m.key], m) }}
                </td>
              </tr>
              <!-- Program sub-rows -->
              <template v-if="expandedProducts.has(prod.productId)">
                <tr v-for="pg in prod.programs" :key="pg.programId" class="program-row">
                  <td class="sticky-col">
                    <span class="program-indent text-caption">{{ pg.programName }}</span>
                  </td>
                  <td class="sticky-col2">
                    <v-chip size="x-small" variant="tonal" color="secondary" class="text-caption">
                      {{ pg.supplier }}
                    </v-chip>
                  </td>
                  <td v-for="m in activeMetrics" :key="m.key" class="text-right"
                    style="font-variant-numeric:tabular-nums;white-space:nowrap;font-size:12px">
                    {{ fmtMetric(pg[m.key], m) }}
                  </td>
                </tr>
              </template>
            </template>
            <!-- Grand totals -->
            <tr v-if="grandTotals" class="totals-row">
              <td class="sticky-col font-weight-bold">ИТОГО</td>
              <td class="sticky-col2">—</td>
              <td v-for="m in activeMetrics" :key="m.key" class="text-right font-weight-bold"
                style="font-variant-numeric:tabular-nums;white-space:nowrap">
                {{ fmtMetric(grandTotals[m.key], m) }}
              </td>
            </tr>
            <tr v-if="!rows.length">
              <td :colspan="2 + activeMetrics.length"><EmptyState /></td>
            </tr>
          </tbody>
        </v-table>
      </div>
    </v-card>

    <!-- MONTHLY MODE: pivot table -->
    <v-card v-if="viewMode === 'monthly' && !loading" class="ds-card" elevation="0">
      <!-- One metric selector for monthly view -->
      <div class="pa-3 d-flex align-center ga-3">
        <v-select v-model="monthlyMetric" :items="allMetrics" item-title="label" item-value="key"
          label="Метрика" density="compact" variant="outlined" hide-details style="max-width:200px" />
        <span class="text-caption text-medium-emphasis">
          Выберите метрику для отображения по месяцам
        </span>
      </div>
      <div style="overflow-x:auto">
        <v-table density="compact" hover>
          <thead>
            <tr>
              <th class="sticky-col" style="min-width:220px">Продукт / Программа</th>
              <th class="sticky-col2" style="min-width:140px">Поставщик</th>
              <th v-for="mo in months" :key="mo" class="text-right" style="white-space:nowrap;min-width:90px">
                {{ fmtMonthHeader(mo) }}
              </th>
              <th class="text-right" style="white-space:nowrap;min-width:90px;background:rgba(var(--v-theme-primary),0.06)">
                Итого
              </th>
            </tr>
          </thead>
          <tbody>
            <template v-for="prod in rows" :key="'m-' + prod.productId">
              <tr class="product-row" @click="toggleProduct(prod.productId)">
                <td class="sticky-col">
                  <div class="d-flex align-center ga-1">
                    <v-icon size="16">
                      {{ expandedProducts.has(prod.productId) ? 'mdi-chevron-down' : 'mdi-chevron-right' }}
                    </v-icon>
                    <span class="font-weight-semibold">{{ prod.productName }}</span>
                  </div>
                </td>
                <td class="sticky-col2 text-medium-emphasis text-caption">—</td>
                <td v-for="mo in months" :key="mo" class="text-right"
                  style="font-variant-numeric:tabular-nums;white-space:nowrap">
                  {{ fmtMetric(productMonthly(prod.productId, mo, monthlyMetric), activeMetricDef) }}
                </td>
                <td class="text-right font-weight-medium"
                  style="font-variant-numeric:tabular-nums;white-space:nowrap;background:rgba(var(--v-theme-primary),0.06)">
                  {{ fmtMetric(prod[monthlyMetric], activeMetricDef) }}
                </td>
              </tr>
              <template v-if="expandedProducts.has(prod.productId)">
                <tr v-for="pg in prod.programs" :key="'m-' + pg.programId" class="program-row">
                  <td class="sticky-col">
                    <span class="program-indent text-caption">{{ pg.programName }}</span>
                  </td>
                  <td class="sticky-col2">
                    <v-chip size="x-small" variant="tonal" color="secondary" class="text-caption">
                      {{ pg.supplier }}
                    </v-chip>
                  </td>
                  <td v-for="mo in months" :key="mo" class="text-right"
                    style="font-variant-numeric:tabular-nums;white-space:nowrap;font-size:12px">
                    {{ fmtMetric(programMonthly(prod.productId, pg.programId, mo, monthlyMetric), activeMetricDef) }}
                  </td>
                  <td class="text-right font-weight-medium"
                    style="font-variant-numeric:tabular-nums;white-space:nowrap;font-size:12px;background:rgba(var(--v-theme-primary),0.06)">
                    {{ fmtMetric(pg[monthlyMetric], activeMetricDef) }}
                  </td>
                </tr>
              </template>
            </template>
            <tr v-if="!rows.length">
              <td :colspan="2 + months.length + 1"><EmptyState /></td>
            </tr>
          </tbody>
        </v-table>
      </div>
    </v-card>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';

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

// Monthly data: { productId: { programId: { 'YYYY-MM': metrics } } }
const monthlyData = ref({});
const months = ref([]);

const yearOptions = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i);

const allMetrics = [
  { key: 'volume',      label: 'Объём ($)',    fmt: 'money' },
  { key: 'count',       label: 'Кол-во (шт)',  fmt: 'int'   },
  { key: 'avgCheck',    label: 'Средний чек',  fmt: 'money' },
  { key: 'revenue',     label: 'Выручка ($)',  fmt: 'money' },
  { key: 'points',      label: 'Баллы',        fmt: 'num'   },
  { key: 'fcCount',     label: 'Кол-во ФК',   fmt: 'int'   },
  { key: 'clientCount', label: 'Кол-во клиентов', fmt: 'int' },
];

const visibleMetrics = ref(['volume', 'count', 'avgCheck', 'revenue', 'points', 'fcCount', 'clientCount']);
const expandedProducts = ref(new Set());

const activeMetrics = computed(() => allMetrics.filter(m => visibleMetrics.value.includes(m.key)));
const activeMetricDef = computed(() => allMetrics.find(m => m.key === monthlyMetric.value) ?? allMetrics[0]);

function toggleMetric(key) {
  const idx = visibleMetrics.value.indexOf(key);
  if (idx === -1) visibleMetrics.value.push(key);
  else visibleMetrics.value.splice(idx, 1);
}

function toggleProduct(id) {
  if (expandedProducts.value.has(id)) expandedProducts.value.delete(id);
  else expandedProducts.value.add(id);
  // trigger reactivity
  expandedProducts.value = new Set(expandedProducts.value);
}

function resetFilters() {
  filterSuppliers.value = [];
  filterProducts.value = [];
  loadData();
}

function fmtMetric(val, metricDef) {
  if (val == null) return '—';
  const m = typeof metricDef === 'object' ? metricDef : (allMetrics.find(x => x.key === metricDef) ?? { fmt: 'num' });
  if (m.fmt === 'int') return Number(val).toLocaleString('ru-RU');
  if (m.fmt === 'money') return Number(val).toLocaleString('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  return Number(val).toLocaleString('ru-RU', { maximumFractionDigits: 2 });
}

const monthNames = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн',
  'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];

function fmtMonthHeader(dm) {
  if (!dm) return '';
  const [, m] = dm.split('-');
  return monthNames[parseInt(m, 10) - 1] ?? dm;
}

function productMonthly(productId, mo, metric) {
  const programs = (monthlyData.value[productId] ?? {});
  let total = 0;
  for (const pgData of Object.values(programs)) {
    total += (pgData[mo]?.[metric] ?? 0);
  }
  return total;
}

function programMonthly(productId, programId, mo, metric) {
  return monthlyData.value[productId]?.[programId]?.[mo]?.[metric] ?? 0;
}

async function loadData() {
  loading.value = true;
  try {
    const params = { year: year.value };
    if (filterSuppliers.value.length) params['suppliers[]'] = filterSuppliers.value;
    if (filterProducts.value.length)  params['products[]']  = filterProducts.value;

    const [main, mon] = await Promise.all([
      api.get('/admin/reports/sales-matrix', { params }),
      api.get('/admin/reports/sales-matrix/monthly', { params }),
    ]);

    rows.value        = main.data.rows ?? [];
    grandTotals.value = main.data.grandTotals ?? null;

    // Populate filter dropdowns on first load
    if (!supplierOptions.value.length) supplierOptions.value = main.data.suppliers ?? [];
    if (!productOptions.value.length)  productOptions.value  = main.data.products  ?? [];

    // Monthly data
    months.value      = mon.data.months ?? [];
    monthlyData.value = mon.data.data   ?? {};

  } catch (e) {
    console.error('sales-matrix load failed', e);
  }
  loading.value = false;
}

// Reload reference dropdowns when year changes
watch(year, () => {
  supplierOptions.value = [];
  productOptions.value  = [];
  filterSuppliers.value = [];
  filterProducts.value  = [];
});

onMounted(loadData);
</script>

<style scoped>
.product-row {
  cursor: pointer;
  background: rgba(var(--v-theme-primary), 0.03);
}
.product-row:hover {
  background: rgba(var(--v-theme-primary), 0.07) !important;
}
.program-row td {
  background: transparent;
}
.program-indent {
  padding-left: 28px;
  display: inline-block;
  color: rgb(var(--v-theme-on-surface));
}
.totals-row td {
  background: rgba(var(--v-theme-primary), 0.06);
  border-top: 2px solid rgba(var(--v-theme-primary), 0.2);
}
.sticky-col {
  position: sticky;
  left: 0;
  background: rgb(var(--v-theme-surface));
  z-index: 2;
}
.sticky-col2 {
  position: sticky;
  left: 220px;
  background: rgb(var(--v-theme-surface));
  z-index: 2;
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.1);
}
</style>
