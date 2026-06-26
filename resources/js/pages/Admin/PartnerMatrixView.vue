<template>
  <div>
    <!-- Состояния (как в продуктовом отчёте) -->
    <div class="d-flex flex-wrap align-center ga-2 mb-3">
      <v-btn-toggle v-model="reportMode" density="compact" variant="outlined" mandatory color="primary">
        <v-btn value="inwork" size="small" prepend-icon="mdi-progress-clock">В работе</v-btn>
        <v-btn value="forecast" size="small" prepend-icon="mdi-chart-timeline-variant">Активировано</v-btn>
        <v-btn value="fact" size="small" prepend-icon="mdi-check-circle-outline">Факт</v-btn>
        <v-btn value="total" size="small" prepend-icon="mdi-sigma">Итого</v-btn>
      </v-btn-toggle>
      <v-spacer />
      <v-menu :close-on-content-click="false">
        <template #activator="{ props }">
          <v-btn v-bind="props" size="small" variant="outlined" prepend-icon="mdi-tune">
            Метрики · {{ selectedMetricKeys.length }}
          </v-btn>
        </template>
        <v-list density="compact">
          <v-list-item v-for="m in allMetrics" :key="m.key" @click="toggleMetric(m.key)">
            <template #prepend>
              <v-icon size="18" :color="selectedMetricKeys.includes(m.key) ? 'primary' : ''">
                {{ selectedMetricKeys.includes(m.key) ? 'mdi-checkbox-marked' : 'mdi-checkbox-blank-outline' }}
              </v-icon>
            </template>
            <v-list-item-title>{{ m.label }}</v-list-item-title>
          </v-list-item>
        </v-list>
      </v-menu>
    </div>

    <!-- Фильтры: период + поставщик/продукт + структура/ФК -->
    <v-card class="mb-3 pa-3" variant="outlined">
      <div class="d-flex flex-wrap ga-2 align-center">
        <v-btn-toggle v-model="periodMode" density="compact" variant="outlined" mandatory color="secondary">
          <v-btn value="year" size="small">Год</v-btn>
          <v-btn value="quarter" size="small">Квартал</v-btn>
          <v-btn value="month" size="small">Месяц</v-btn>
          <v-btn value="range" size="small">Диапазон</v-btn>
        </v-btn-toggle>

        <v-select v-model="periodYear" :items="yearOptions" density="compact" variant="outlined"
          hide-details style="max-width: 110px" />
        <v-select v-if="periodMode === 'quarter'" v-model="periodQuarter" :items="['Q1','Q2','Q3','Q4']"
          density="compact" variant="outlined" hide-details style="max-width: 100px" />
        <v-select v-if="periodMode === 'month'" v-model="periodMonth" :items="monthOpts" item-title="t" item-value="v"
          density="compact" variant="outlined" hide-details style="max-width: 140px" />
        <template v-if="periodMode === 'range'">
          <v-select v-model="rangeFromYear" :items="yearOptions" density="compact" variant="outlined" hide-details style="max-width: 100px" />
          <v-select v-model="rangeFromMonth" :items="monthOpts" item-title="t" item-value="v" density="compact" variant="outlined" hide-details style="max-width: 130px" />
          <span class="text-medium-emphasis">—</span>
          <v-select v-model="rangeToYear" :items="yearOptions" density="compact" variant="outlined" hide-details style="max-width: 100px" />
          <v-select v-model="rangeToMonth" :items="monthOpts" item-title="t" item-value="v" density="compact" variant="outlined" hide-details style="max-width: 130px" />
        </template>

        <v-divider vertical class="mx-1" />

        <v-autocomplete v-model="filterStructures" :items="structureOptions" item-title="name" item-value="id"
          placeholder="Структура" density="compact" variant="outlined" hide-details multiple chips closable-chips
          prepend-inner-icon="mdi-account-supervisor" style="min-width: 220px; max-width: 320px" />
        <v-autocomplete v-model="filterFcs" :items="fcOptions" item-title="name" item-value="id"
          placeholder="ФК" density="compact" variant="outlined" hide-details multiple chips closable-chips
          prepend-inner-icon="mdi-account" style="min-width: 200px; max-width: 320px" />
        <v-autocomplete v-model="filterSuppliers" :items="supplierOptions"
          placeholder="Поставщик" density="compact" variant="outlined" hide-details multiple chips closable-chips
          prepend-inner-icon="mdi-domain" style="min-width: 180px; max-width: 280px" />

        <v-spacer />
        <v-btn size="small" variant="text" prepend-icon="mdi-unfold-more-horizontal" @click="expandAll">Развернуть</v-btn>
        <v-btn size="small" variant="text" prepend-icon="mdi-unfold-less-horizontal" @click="collapseAll">Свернуть</v-btn>
      </div>
    </v-card>

    <!-- Сводные бейджи -->
    <div v-if="grand" class="d-flex flex-wrap ga-2 mb-3">
      <v-chip color="primary" variant="tonal" prepend-icon="mdi-account-supervisor">{{ data.structures.length }} структур</v-chip>
      <v-chip color="primary" variant="tonal" prepend-icon="mdi-account-group">{{ grand.fcCount }} ФК</v-chip>
      <v-chip color="success" variant="tonal" prepend-icon="mdi-chart-bar">{{ fmtRub(grand.volume) }} объём</v-chip>
      <v-chip color="success" variant="tonal" prepend-icon="mdi-cash">{{ fmtRub(grand.revenue) }} выручка</v-chip>
    </div>

    <v-card>
      <div class="pm-wrap">
        <table class="pm-grid">
          <thead>
            <tr>
              <th class="pm-name-col">Иерархия (Команда ▸ ФК ▸ Продукт)</th>
              <th v-for="m in activeMetrics" :key="m.key" class="text-end">{{ m.short }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-if="!loading && data.structures.length">
              <template v-for="s in data.structures" :key="`s${s.structureId}`">
                <!-- Уровень 1: Структура -->
                <tr class="pm-row pm-l1" @click="toggleStruct(s.structureId)">
                  <td class="pm-name">
                    <v-icon size="16">{{ expandedStructs.has(s.structureId) ? 'mdi-menu-down' : 'mdi-menu-right' }}</v-icon>
                    <strong>{{ s.structureName }}</strong>
                  </td>
                  <td v-for="m in activeMetrics" :key="m.key" class="text-end pm-num">
                    <template v-if="m.key === 'revenue'">
                      <div>{{ fmtRub(s.revenue) }}</div>
                      <div class="pm-sub">{{ revenueSub(s, null) }}</div>
                    </template>
                    <template v-else>{{ cellVal(s, m) }}</template>
                  </td>
                </tr>

                <template v-if="expandedStructs.has(s.structureId)">
                  <template v-for="f in s.fcs" :key="`f${s.structureId}-${f.fcId}`">
                    <!-- Уровень 2: ФК -->
                    <tr class="pm-row pm-l2" @click="toggleFc(s.structureId, f.fcId)">
                      <td class="pm-name" style="padding-left: 28px">
                        <v-icon size="14">{{ isFcExpanded(s.structureId, f.fcId) ? 'mdi-menu-down' : 'mdi-menu-right' }}</v-icon>
                        {{ f.fcName }}
                      </td>
                      <td v-for="m in activeMetrics" :key="m.key" class="text-end pm-num">
                        <template v-if="m.key === 'revenue'">
                          <div>{{ fmtRub(f.revenue) }}</div>
                          <div class="pm-sub">{{ revenueSub(f, s) }}</div>
                        </template>
                        <template v-else>{{ cellVal(f, m) }}</template>
                      </td>
                    </tr>

                    <!-- Уровень 3: Продукты -->
                    <template v-if="isFcExpanded(s.structureId, f.fcId)">
                      <tr v-for="p in f.products" :key="`p${f.fcId}-${p.productId}`" class="pm-row pm-l3">
                        <td class="pm-name pm-prod" style="padding-left: 52px">{{ p.productName }}</td>
                        <td v-for="m in activeMetrics" :key="m.key" class="text-end pm-num pm-prod-num">
                          {{ cellVal(p, m) }}
                        </td>
                      </tr>
                    </template>
                  </template>
                </template>
              </template>

              <!-- Итого по сети -->
              <tr class="pm-row pm-total">
                <td class="pm-name"><strong>ИТОГО ПО СЕТИ</strong></td>
                <td v-for="m in activeMetrics" :key="m.key" class="text-end pm-num">
                  <template v-if="m.key === 'revenue'">
                    <strong>{{ fmtRub(grand.revenue) }}</strong>
                    <div class="pm-sub">{{ revenueSub(grand, null) }}</div>
                  </template>
                  <strong v-else>{{ cellVal(grand, m) }}</strong>
                </td>
              </tr>
            </template>
          </tbody>
        </table>

        <div v-if="loading" class="d-flex justify-center pa-12">
          <v-progress-circular indeterminate color="primary" />
        </div>
        <div v-else-if="!data.structures.length" class="text-center pa-12 text-medium-emphasis">
          Нет данных за выбранный период
        </div>
      </div>
    </v-card>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import api from '../../api';

// ─── Период ───
const now = new Date();
const periodMode = ref('month');
const periodYear = ref(now.getFullYear());
const periodQuarter = ref('Q' + (Math.floor(now.getMonth() / 3) + 1));
const periodMonth = ref(String(now.getMonth() + 1).padStart(2, '0'));
const rangeFromYear = ref(now.getFullYear());
const rangeFromMonth = ref('01');
const rangeToYear = ref(now.getFullYear());
const rangeToMonth = ref(String(now.getMonth() + 1).padStart(2, '0'));

const yearOptions = Array.from({ length: 7 }, (_, i) => now.getFullYear() - i);
const quarterRanges = { Q1: [1, 3], Q2: [4, 6], Q3: [7, 9], Q4: [10, 12] };
const monthOpts = [
  { t: 'Январь', v: '01' }, { t: 'Февраль', v: '02' }, { t: 'Март', v: '03' },
  { t: 'Апрель', v: '04' }, { t: 'Май', v: '05' }, { t: 'Июнь', v: '06' },
  { t: 'Июль', v: '07' }, { t: 'Август', v: '08' }, { t: 'Сентябрь', v: '09' },
  { t: 'Октябрь', v: '10' }, { t: 'Ноябрь', v: '11' }, { t: 'Декабрь', v: '12' },
];

const periodFrom = computed(() => {
  const y = periodYear.value;
  if (periodMode.value === 'year') return `${y}-01`;
  if (periodMode.value === 'quarter') return `${y}-${String(quarterRanges[periodQuarter.value][0]).padStart(2, '0')}`;
  if (periodMode.value === 'month') return `${y}-${periodMonth.value}`;
  return `${rangeFromYear.value}-${rangeFromMonth.value}`;
});
const periodTo = computed(() => {
  const y = periodYear.value;
  if (periodMode.value === 'year') return `${y}-12`;
  if (periodMode.value === 'quarter') return `${y}-${String(quarterRanges[periodQuarter.value][1]).padStart(2, '0')}`;
  if (periodMode.value === 'month') return `${y}-${periodMonth.value}`;
  return `${rangeToYear.value}-${rangeToMonth.value}`;
});

// ─── Состояние ───
const reportMode = ref('fact');

// ─── Метрики ───
const allMetrics = [
  { key: 'volume', short: 'Объём', label: 'Объём (₽)', fmt: 'rub' },
  { key: 'count', short: 'Кол-во', label: 'Кол-во (шт)', fmt: 'int' },
  { key: 'avgCheck', short: 'Ср.чек', label: 'Средний чек (₽)', fmt: 'rub' },
  { key: 'revenue', short: 'Выручка', label: 'Выручка (₽)', fmt: 'rub' },
  { key: 'bally', short: 'Баллы', label: 'Баллы', fmt: 'num' },
  { key: 'ballyLP', short: 'Баллы ЛП', label: 'Баллы ЛП', fmt: 'num' },
  { key: 'fcCount', short: 'ФК', label: 'Кол-во ФК', fmt: 'int' },
  { key: 'clientCount', short: 'Клиенты', label: 'Кол-во клиентов', fmt: 'int' },
  // % выручки от команды/компании показываем НЕ отдельными колонками, а
  // подписью под значением «Выручка» (как в макете) — см. revenueSub().
];
const METRICS_KEY = 'partnerMatrix:metrics2';
const _saved = (() => { try { const s = JSON.parse(localStorage.getItem(METRICS_KEY)); return Array.isArray(s) && s.length ? s : null; } catch { return null; } })();
const selectedMetricKeys = ref(_saved ?? ['volume', 'count', 'avgCheck', 'revenue', 'bally', 'ballyLP', 'fcCount', 'clientCount']);
const activeMetrics = computed(() => allMetrics.filter(m => selectedMetricKeys.value.includes(m.key)));
function toggleMetric(key) {
  const i = selectedMetricKeys.value.indexOf(key);
  if (i !== -1) { if (selectedMetricKeys.value.length > 1) selectedMetricKeys.value.splice(i, 1); }
  else selectedMetricKeys.value.push(key);
  localStorage.setItem(METRICS_KEY, JSON.stringify(selectedMetricKeys.value));
}

// ─── Фильтры ───
const filterStructures = ref([]);
const filterFcs = ref([]);
const filterSuppliers = ref([]);
const structureOptions = ref([]);
const fcOptions = ref([]);
const supplierOptions = ref([]);

// ─── Данные ───
const loading = ref(false);
const data = ref({ months: [], structures: [], grand: null });
const grand = computed(() => data.value.grand);

// ─── Раскрытие ───
const expandedStructs = ref(new Set());
const expandedFcs = ref(new Set());
function toggleStruct(id) {
  const s = new Set(expandedStructs.value);
  s.has(id) ? s.delete(id) : s.add(id);
  expandedStructs.value = s;
}
function fcKey(sid, fid) { return `${sid}:${fid}`; }
function isFcExpanded(sid, fid) { return expandedFcs.value.has(fcKey(sid, fid)); }
function toggleFc(sid, fid) {
  const s = new Set(expandedFcs.value);
  const k = fcKey(sid, fid);
  s.has(k) ? s.delete(k) : s.add(k);
  expandedFcs.value = s;
}
function expandAll() {
  expandedStructs.value = new Set(data.value.structures.map(s => s.structureId));
  const fset = new Set();
  for (const s of data.value.structures) for (const f of s.fcs) fset.add(fcKey(s.structureId, f.fcId));
  expandedFcs.value = fset;
}
function collapseAll() { expandedStructs.value = new Set(); expandedFcs.value = new Set(); }

// ─── Форматирование ячейки ───
function fmtRub(v) { return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(Math.round(v || 0)) + ' ₽'; }
function fmtInt(v) { return new Intl.NumberFormat('ru-RU').format(Math.round(v || 0)); }
function fmtNum(v) { return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(Math.round(v || 0)); }

/**
 * Значение ячейки. parent — родительский узел (структура для ФК, ФК для продукта);
 * для %команды нужна выручка команды, для %компании — grand.
 * level === 'team' (parent null & не grand) — % команды показываем прочерком.
 */
function cellVal(node, m) {
  // ЛП на уровне команды — прочерк (считается снизу вверх, как в ТЗ).
  if (m.key === 'ballyLP' && node.structureId !== undefined) return '—';
  const v = node[m.key] ?? 0;
  if (m.fmt === 'rub') return fmtRub(v);
  if (m.fmt === 'int') return fmtInt(v);
  return fmtNum(v);
}

/**
 * Подпись под значением «Выручка» (как в макете):
 *  - продукт — пусто;
 *  - ФК — «Км: <%команды> / Кл: <%компании>»;
 *  - команда/итого — «Доля компании: <%компании>».
 */
function revenueSub(node, parent) {
  if (node.productId !== undefined) return '';
  const comp = grand.value?.revenue ? (node.revenue / grand.value.revenue * 100) : 0;
  if (node.fcId !== undefined) {
    const team = parent?.revenue ? (node.revenue / parent.revenue * 100) : 0;
    return `Км: ${team.toFixed(1)}% / Кл: ${comp.toFixed(1)}%`;
  }
  return `Доля компании: ${comp.toFixed(1)}%`;
}

// ─── Загрузка ───
async function loadData() {
  loading.value = true;
  try {
    const params = { from: periodFrom.value, to: periodTo.value };
    if (filterStructures.value.length) params.structures = filterStructures.value;
    if (filterFcs.value.length) params.fcs = filterFcs.value;
    if (filterSuppliers.value.length) params.suppliers = filterSuppliers.value;
    const { data: res } = await api.get(`/admin/reports/partner-matrix/${reportMode.value}`, { params });
    data.value = { months: res.months || [], structures: res.structures || [], grand: res.grand || null };
    // авто-раскрытие первого уровня
    expandedStructs.value = new Set(data.value.structures.map(s => s.structureId));
  } catch {
    data.value = { months: [], structures: [], grand: null };
  }
  loading.value = false;
}

async function loadLookups() {
  try {
    const params = filterStructures.value.length ? { structures: filterStructures.value } : {};
    const { data: res } = await api.get('/admin/reports/partner-matrix/lookups', { params });
    structureOptions.value = res.structures || [];
    fcOptions.value = res.fcs || [];
  } catch {}
}
async function loadSuppliers() {
  try {
    const { data: res } = await api.get('/admin/manual-tx/lookups');
    supplierOptions.value = res.suppliers || [];
  } catch {}
}

// Каскад: смена структуры → перезагрузка списка ФК.
watch(filterStructures, () => { loadLookups(); });
// Перезагрузка данных на смену состояния/периода/фильтров.
watch([reportMode, periodMode, periodYear, periodQuarter, periodMonth,
  rangeFromYear, rangeFromMonth, rangeToYear, rangeToMonth,
  filterStructures, filterFcs, filterSuppliers], () => loadData());

onMounted(() => { loadLookups(); loadSuppliers(); loadData(); });
</script>

<style scoped>
.pm-wrap { overflow-x: auto; }
.pm-grid { width: 100%; border-collapse: separate; border-spacing: 0; }
.pm-grid thead th {
  position: sticky; top: 0; z-index: 2;
  background: rgb(var(--v-theme-surface));
  font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px;
  color: rgba(var(--v-theme-on-surface), 0.7);
  padding: 8px 12px; border-bottom: 2px solid rgba(var(--v-theme-on-surface), 0.08);
  white-space: nowrap; text-align: left;
}
.pm-grid thead th.text-end { text-align: right; }
.pm-name-col { min-width: 320px; }
.pm-grid td { padding: 7px 12px; border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.05); font-size: 13px; }
.pm-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
.pm-sub { font-size: 10.5px; color: rgba(var(--v-theme-on-surface), 0.55); line-height: 1.1; margin-top: 1px; }
.pm-row.pm-l1 { cursor: pointer; background: rgba(var(--v-theme-primary), 0.06); }
.pm-row.pm-l1:hover { background: rgba(var(--v-theme-primary), 0.1); }
.pm-row.pm-l2 { cursor: pointer; }
.pm-row.pm-l2:hover { background: rgba(var(--v-theme-on-surface), 0.03); }
.pm-prod, .pm-prod-num { color: rgba(var(--v-theme-on-surface), 0.65); font-style: italic; }
.pm-name { display: flex; align-items: center; gap: 4px; }
.pm-l3 .pm-name { display: block; }
.pm-total { background: rgba(var(--v-theme-on-surface), 0.04); border-top: 2px solid rgba(var(--v-theme-on-surface), 0.12); }
.pm-total td { border-bottom: none; }
</style>
