<template>
  <div>
    <!-- Тариф %ДС не заведён → выручку по этим контрактам посчитать не из чего.
         Раньше подставлялось 100% и в «Выручку» уходила вся сумма контракта. -->
    <v-alert v-if="missingTariff?.contracts" type="warning" variant="tonal"
      density="compact" icon="mdi-alert-outline" class="mb-3">
      <div class="font-weight-medium">
        «Выручка» неполная: у {{ missingTariff.contracts }}
        {{ plural(missingTariff.contracts, 'контракта', 'контрактов', 'контрактов') }}
        не заведён тариф %ДС — они не учтены.
      </div>
      <div class="text-caption mt-1">
        Заведите тариф программы, чтобы выручка попала в отчёт:
        <span v-for="(p, i) in missingTariff.programs.slice(0, 5)" :key="p.id ?? i">
          <b>{{ p.name }}</b> ({{ p.contracts }}){{ i < Math.min(missingTariff.programs.length, 5) - 1 ? ', ' : '' }}
        </span>
        <span v-if="missingTariff.programs.length > 5">
          и ещё {{ missingTariff.programs.length - 5 }}
        </span>
      </div>
    </v-alert>

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
            <v-list-item-subtitle class="pm-metric-hint">{{ m.hint }}</v-list-item-subtitle>
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
        <v-autocomplete v-model="filterProducts" :items="productOptions" item-title="name" item-value="id"
          placeholder="Продукт" density="compact" variant="outlined" hide-details multiple chips closable-chips
          prepend-inner-icon="mdi-package-variant" style="min-width: 180px; max-width: 280px" />

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
          <thead v-if="showMonths">
            <tr>
              <th class="pm-name-col pm-sortable" rowspan="2" @click="setSort('name')">
                Иерархия (Команда ▸ ФК ▸ Продукт)
                <v-icon v-if="sortArrow('name')" size="13" class="pm-sort-i">{{ sortArrow('name') }}</v-icon>
              </th>
              <th v-for="mo in displayMonths" :key="mo" :colspan="activeMetrics.length" class="pm-mgroup">{{ fmtMonthHdr(mo) }}</th>
              <th :colspan="activeMetrics.length" class="pm-mgroup pm-total-hd">Итого</th>
            </tr>
            <tr>
              <template v-for="mo in displayMonths" :key="`h-${mo}`">
                <th v-for="(m, mi) in activeMetrics" :key="`h-${mo}-${m.key}`" class="text-end pm-sub-th"
                  :title="`${m.label} — ${m.hint}`"
                  :class="{ 'pm-sep': mi === activeMetrics.length - 1 }">{{ m.short }}</th>
              </template>
              <th v-for="m in activeMetrics" :key="`ht-${m.key}`" class="text-end pm-sub-th pm-total-th pm-sortable"
                :title="`${m.label} — ${m.hint}. Клик — сортировка`" @click="setSort(m.key)">
                <v-icon v-if="sortArrow(m.key)" size="12" class="pm-sort-i">{{ sortArrow(m.key) }}</v-icon>{{ m.short }}
              </th>
            </tr>
          </thead>
          <thead v-else>
            <tr>
              <th class="pm-name-col pm-sortable" @click="setSort('name')">
                Иерархия (Команда ▸ ФК ▸ Продукт)
                <v-icon v-if="sortArrow('name')" size="13" class="pm-sort-i">{{ sortArrow('name') }}</v-icon>
              </th>
              <th v-for="m in activeMetrics" :key="m.key" class="text-end pm-sortable" @click="setSort(m.key)">
                <span class="pm-th-lbl">
                  <v-icon v-if="sortArrow(m.key)" size="13" class="pm-sort-i">{{ sortArrow(m.key) }}</v-icon>
                  {{ m.short }}
                  <v-tooltip location="top" max-width="320" open-delay="100">
                    <template #activator="{ props }">
                      <v-icon v-bind="props" size="13" class="pm-th-i" @click.stop>mdi-information-outline</v-icon>
                    </template>
                    <span>{{ m.hint }}</span>
                  </v-tooltip>
                </span>
              </th>
            </tr>
          </thead>
          <tbody>
            <template v-if="!loading && data.structures.length">
              <template v-for="s in sortedStructures" :key="`s${s.structureId}`">
                <!-- Уровень 1: Структура -->
                <tr class="pm-row pm-l1" @click="toggleStruct(s.structureId)">
                  <td class="pm-name">
                    <span class="pm-name-inner">
                      <v-icon size="16">{{ expandedStructs.has(s.structureId) ? 'mdi-menu-down' : 'mdi-menu-right' }}</v-icon>
                      <span class="pm-team-tag">Команда</span>
                      <strong>{{ s.structureName }}</strong>
                    </span>
                  </td>
                  <template v-for="mo in displayMonths" v-if="showMonths" :key="`sm-${s.structureId}-${mo}`">
                    <td v-for="(m, mi) in activeMetrics" :key="m.key" class="text-end pm-num pm-month"
                      :class="{ 'pm-sep': mi === activeMetrics.length - 1 }">{{ monthCell(s, mo, m) }}</td>
                  </template>
                  <td v-for="m in activeMetrics" :key="`st-${m.key}`" class="text-end pm-num" :class="{ 'pm-total-cell': showMonths }">
                    <template v-if="m.key === 'revenue'">
                      <div>{{ fmtRub(s.revenue) }}</div>
                      <div class="pm-sub" :title="revenueSubTitle(s)">{{ revenueSub(s, null) }}</div>
                    </template>
                    <template v-else>{{ cellVal(s, m) }}</template>
                  </td>
                </tr>

                <template v-if="expandedStructs.has(s.structureId)">
                  <template v-for="f in s.fcs" :key="`f${s.structureId}-${f.fcId}`">
                    <!-- Уровень 2: ФК -->
                    <tr class="pm-row pm-l2" @click="toggleFc(s.structureId, f.fcId)">
                      <td class="pm-name" style="padding-left: 28px">
                        <span class="pm-name-inner">
                          <v-icon size="14">{{ isFcExpanded(s.structureId, f.fcId) ? 'mdi-menu-down' : 'mdi-menu-right' }}</v-icon>
                          {{ f.fcName }}
                        </span>
                      </td>
                      <template v-for="mo in displayMonths" v-if="showMonths" :key="`fm-${f.fcId}-${mo}`">
                        <td v-for="(m, mi) in activeMetrics" :key="m.key" class="text-end pm-num pm-month"
                          :class="{ 'pm-sep': mi === activeMetrics.length - 1 }">{{ monthCell(f, mo, m) }}</td>
                      </template>
                      <td v-for="m in activeMetrics" :key="`ft-${m.key}`" class="text-end pm-num" :class="{ 'pm-total-cell': showMonths }">
                        <template v-if="m.key === 'revenue'">
                          <div>{{ fmtRub(f.revenue) }}</div>
                          <div class="pm-sub" :title="revenueSubTitle(f)">{{ revenueSub(f, s) }}</div>
                        </template>
                        <template v-else>{{ cellVal(f, m) }}</template>
                      </td>
                    </tr>

                    <!-- Уровень 3: Продукты, либо (в «Итого») состояния В работе/Активировано/Факт -->
                    <template v-if="isFcExpanded(s.structureId, f.fcId)">
                      <tr v-for="p in f.products" :key="`p${f.fcId}-${p.productId}`" class="pm-row pm-l3"
                        :class="{ 'pm-state-row': p.state }">
                        <td class="pm-name" :class="p.state ? 'pm-state-name' : 'pm-prod'" style="padding-left: 52px">
                          <span v-if="p.state" class="pm-state-tag" :class="`pm-state-${p.state}`">{{ p.productName }}</span>
                          <template v-else>{{ p.productName }}</template>
                        </td>
                        <template v-for="mo in displayMonths" v-if="showMonths" :key="`pm-${p.productId}-${mo}`">
                          <td v-for="(m, mi) in activeMetrics" :key="m.key" class="text-end pm-num pm-month"
                            :class="[{ 'pm-sep': mi === activeMetrics.length - 1 }, p.state ? '' : 'pm-prod-num']">{{ monthCell(p, mo, m) }}</td>
                        </template>
                        <td v-for="m in activeMetrics" :key="`pt-${m.key}`" class="text-end pm-num"
                          :class="[{ 'pm-total-cell': showMonths }, p.state ? '' : 'pm-prod-num']">
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
                <template v-for="mo in displayMonths" v-if="showMonths" :key="`gm-${mo}`">
                  <td v-for="(m, mi) in activeMetrics" :key="m.key" class="text-end pm-num pm-month"
                    :class="{ 'pm-sep': mi === activeMetrics.length - 1 }"><strong>{{ monthCell(grand, mo, m) }}</strong></td>
                </template>
                <td v-for="m in activeMetrics" :key="`gt-${m.key}`" class="text-end pm-num" :class="{ 'pm-total-cell': showMonths }">
                  <template v-if="m.key === 'revenue'">
                    <div><strong>{{ fmtRub(grand.revenue) }}</strong></div>
                    <div class="pm-sub" :title="revenueSubTitle(grand)">{{ revenueSub(grand, null) }}</div>
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
  { key: 'volume', short: 'Объём', label: 'Объём (₽)', fmt: 'rub',
    hint: 'Сумма контрактов (в работе / активировано) или транзакций (факт) в рублях по курсу.' },
  { key: 'count', short: 'Кол-во', label: 'Кол-во (шт)', fmt: 'int',
    hint: 'Количество контрактов / транзакций, шт.' },
  { key: 'avgCheck', short: 'Ср.чек', label: 'Средний чек (₽)', fmt: 'rub',
    hint: 'Средний чек = Объём ÷ Кол-во.' },
  { key: 'revenue', short: 'Выручка', label: 'Выручка (₽)', fmt: 'rub',
    hint: 'Доход ДС без НДС. «В работе» / «Активировано» — прогноз: Сумма × %ДС ÷ 105 × 100 (× курс). «Факт» — из транзакции как есть (доход ДС без НДС). '
        + 'Под значением: КМ — доля выручки ФК в его команде; КЛ — доля выручки ФК во всей компании.' },
  { key: 'bally', short: 'Баллы', label: 'Баллы (Выручка÷100)', fmt: 'num',
    hint: 'Баллы = Выручка ÷ 100 на всех уровнях. В «Факт» совпадают с суммой personalVolume из транзакций (личные баллы ФК).' },
  { key: 'fcCount', short: 'ФК', label: 'Кол-во ФК', fmt: 'int',
    hint: 'Число уникальных ФК с продажами. На строке ФК/продукта = 1, на команде — количество разных ФК.' },
  { key: 'clientCount', short: 'Клиенты', label: 'Кол-во клиентов', fmt: 'int',
    hint: 'Число уникальных клиентов.' },
  // % выручки от команды/компании показываем НЕ отдельными колонками, а
  // подписью под значением «Выручка» (как в макете) — см. revenueSub().
];
const METRICS_KEY = 'partnerMatrix:metrics4';
const _saved = (() => { try { const s = JSON.parse(localStorage.getItem(METRICS_KEY)); return Array.isArray(s) && s.length ? s : null; } catch { return null; } })();
const selectedMetricKeys = ref(_saved ?? ['volume', 'count', 'avgCheck', 'revenue', 'bally', 'fcCount', 'clientCount']);
const activeMetrics = computed(() => allMetrics.filter(m => selectedMetricKeys.value.includes(m.key)));
function toggleMetric(key) {
  const i = selectedMetricKeys.value.indexOf(key);
  if (i !== -1) { if (selectedMetricKeys.value.length > 1) selectedMetricKeys.value.splice(i, 1); }
  else selectedMetricKeys.value.push(key);
  localStorage.setItem(METRICS_KEY, JSON.stringify(selectedMetricKeys.value));
}

// ─── Сортировка колонок ───
// Иерархию сортируем внутри каждого уровня (структуры → ФК → продукты) по
// выбранной метрике. 'name' — по алфавиту. По умолчанию — как с бэкенда
// (выручка убыв.), чтобы первый вид не менялся.
const sortKey = ref('revenue');
const sortDir = ref('desc'); // 'asc' | 'desc'
function setSort(key) {
  if (sortKey.value === key) sortDir.value = sortDir.value === 'desc' ? 'asc' : 'desc';
  else { sortKey.value = key; sortDir.value = key === 'name' ? 'asc' : 'desc'; }
}
function sortArrow(key) {
  if (sortKey.value !== key) return '';
  return sortDir.value === 'desc' ? 'mdi-arrow-down' : 'mdi-arrow-up';
}
function cmpBy(a, b) {
  const dir = sortDir.value === 'desc' ? -1 : 1;
  if (sortKey.value === 'name') return dir * String(a).localeCompare(String(b), 'ru');
  return dir * ((Number(a) || 0) - (Number(b) || 0));
}
const sortedStructures = computed(() => {
  const k = sortKey.value;
  const byName = k === 'name';
  const nodeName = (n) => n.structureName ?? n.fcName ?? n.productName ?? '';
  const sortNodes = (arr) => [...arr].sort((x, y) =>
    byName ? cmpBy(nodeName(x), nodeName(y)) : cmpBy(x[k], y[k]));
  return sortNodes(data.value.structures).map(s => ({
    ...s,
    fcs: sortNodes(s.fcs).map(f => ({
      ...f,
      // В «Итого» дети ФК — состояния (В работе/Активировано/Факт): сохраняем
      // их фиксированный порядок с бэкенда, а не сортируем по метрике.
      products: f.products[0]?.state ? f.products : sortNodes(f.products),
    })),
  }));
});

// ─── Фильтры ───
const filterStructures = ref([]);
const filterFcs = ref([]);
const filterSuppliers = ref([]);
const filterProducts = ref([]);
const structureOptions = ref([]);
const fcOptions = ref([]);
const supplierOptions = ref([]);
const productOptions = ref([]);

// ─── Данные ───
const loading = ref(false);
const data = ref({ months: [], structures: [], grand: null });
const grand = computed(() => data.value.grand);
const missingTariff = ref(null);

function plural(n, one, few, many) {
  if (n % 10 === 1 && n % 100 !== 11) return one;
  if ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100)) return few;
  return many;
}

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
  const v = node[m.key] ?? 0;
  if (m.fmt === 'rub') return fmtRub(v);
  if (m.fmt === 'int') return fmtInt(v);
  return fmtNum(v);
}

// ─── Помесячная разбивка ───
const displayMonths = computed(() => data.value.months || []);
const showMonths = computed(() => displayMonths.value.length > 1);
const monthShort = ['', 'Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];
function fmtMonthHdr(mo) {
  const [y, m] = String(mo).split('-');
  return `${monthShort[parseInt(m, 10)] || mo} '${String(y).slice(2)}`;
}
function monthCell(node, mo, m) {
  const v = node.monthly?.[mo]?.[m.key] ?? 0;
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
  // Знаменатели долей — из denominators (полная компания/команда без фильтра по
  // ФК/структуре), иначе фолбэк на grand/parent. Иначе при фильтре по 1 ФК/
  // структуре доля выходила 100%.
  const d = data.value.denominators;
  const companyRev = d?.company || grand.value?.revenue || 0;
  const comp = companyRev ? (node.revenue / companyRev * 100) : 0;
  if (node.fcId !== undefined) {
    const teamRev = d?.teams?.[parent?.structureId] || parent?.revenue || 0;
    const team = teamRev ? (node.revenue / teamRev * 100) : 0;
    return `Км: ${team.toFixed(1)}% / Кл: ${comp.toFixed(1)}%`;
  }
  return `Доля компании: ${comp.toFixed(1)}%`;
}

/** Расшифровка КМ/КЛ при наведении на подпись под «Выручкой». */
function revenueSubTitle(node) {
  if (node.productId !== undefined) return '';
  if (node.fcId !== undefined) {
    return 'КМ — доля выручки ФК в его команде (выручка ФК ÷ выручка команды). '
      + 'КЛ — доля выручки ФК во всей компании (выручка ФК ÷ выручка компании).';
  }
  return 'Доля компании — % выручки этой строки от выручки всей компании.';
}

// ─── Загрузка ───
async function loadData() {
  loading.value = true;
  try {
    const params = { from: periodFrom.value, to: periodTo.value };
    if (filterStructures.value.length) params.structures = filterStructures.value;
    if (filterFcs.value.length) params.fcs = filterFcs.value;
    if (filterSuppliers.value.length) params.suppliers = filterSuppliers.value;
    if (filterProducts.value.length) params.products = filterProducts.value;
    const { data: res } = await api.get(`/admin/reports/partner-matrix/${reportMode.value}`, { params });
    data.value = { months: res.months || [], structures: res.structures || [], grand: res.grand || null, denominators: res.denominators || null };
    // Контракты без тарифа %ДС: их выручка НЕ учтена (фолбэк 100% убран — он
    // приравнивал выручку ко всей сумме контракта). Показываем явно.
    missingTariff.value = res.missingTariff || null;
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
    productOptions.value = res.products || [];
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
  filterStructures, filterFcs, filterSuppliers, filterProducts], () => loadData());

onMounted(() => { loadLookups(); loadSuppliers(); loadData(); });
</script>

<style scoped>
/* Внутренний скролл включает заморозку: шапка липнет к верху, первый
   столбец (имя) — к левому краю при прокрутке. */
.pm-wrap { overflow: auto; max-height: calc(100vh - 300px); }
.pm-grid { width: 100%; border-collapse: separate; border-spacing: 0; }
.pm-grid thead th {
  position: sticky; top: 0; z-index: 2;
  background: rgb(var(--v-theme-surface));
  font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px;
  color: rgba(var(--v-theme-on-surface), 0.7);
  padding: 7px 7px; border-bottom: 2px solid rgba(var(--v-theme-on-surface), 0.08);
  white-space: nowrap; text-align: left;
}
.pm-grid thead th.text-end { text-align: right; }
.pm-name-col { min-width: 240px; }

/* Липкий первый столбец (имя). Угол (th.pm-name-col) поверх всех. */
.pm-grid thead th.pm-name-col { left: 0; z-index: 4; }
.pm-name {
  position: sticky; left: 0; z-index: 1;
  background: rgb(var(--v-theme-surface));
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.09);
}
.pm-grid thead th.pm-name-col { border-right: 1px solid rgba(var(--v-theme-on-surface), 0.09); }
/* Тонированные строки: непрозрачный фон под липкой ячейкой имени. */
.pm-row.pm-l1 .pm-name {
  background: linear-gradient(rgba(var(--v-theme-primary), 0.06), rgba(var(--v-theme-primary), 0.06)), rgb(var(--v-theme-surface));
}
.pm-row.pm-total .pm-name {
  background: linear-gradient(rgba(var(--v-theme-on-surface), 0.04), rgba(var(--v-theme-on-surface), 0.04)), rgb(var(--v-theme-surface));
}
.pm-grid td { padding: 6px 7px; border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.05); font-size: 13px; vertical-align: middle; }
.pm-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
.pm-sub { font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.55); line-height: 1.1; margin-top: 1px;
  cursor: help; border-bottom: 1px dotted rgba(var(--v-theme-on-surface), 0.35); display: inline-block; }
.pm-th-lbl { display: inline-flex; align-items: center; gap: 3px; }
.pm-th-i { color: rgba(var(--v-theme-on-surface), 0.4); cursor: help; }
.pm-th-i:hover { color: rgb(var(--v-theme-primary)); }
.pm-sub-th[title] { cursor: help; }
.pm-sortable { cursor: pointer; user-select: none; }
.pm-sortable:hover { color: rgb(var(--v-theme-primary)); }
.pm-sort-i { color: rgb(var(--v-theme-primary)); margin-right: 2px; vertical-align: middle; }
.pm-metric-hint { font-size: 11px; white-space: normal; max-width: 340px; line-height: 1.25; }
.pm-row.pm-l1 { cursor: pointer; background: rgba(var(--v-theme-primary), 0.06); }
.pm-row.pm-l1:hover { background: rgba(var(--v-theme-primary), 0.1); }
.pm-row.pm-l2 { cursor: pointer; }
.pm-row.pm-l2:hover { background: rgba(var(--v-theme-on-surface), 0.03); }
.pm-prod, .pm-prod-num { color: rgba(var(--v-theme-on-surface), 0.65); font-style: italic; }
/* ⚠ НЕ ставить display:flex на сам <td> — в Safari/Chrome на mac flex-ячейка
   не тянется на высоту строки, и при двухстрочной «Выручке» имя уезжает вверх
   («едут строки»). Ячейка — обычная (vertical-align:middle), flex — во
   внутреннем .pm-name-inner. */
.pm-name { vertical-align: middle; }
.pm-name-inner { display: inline-flex; align-items: center; gap: 4px; vertical-align: middle; }
/* Тег «Команда» перед именем структуры — отличает агрегат команды от строки
   самого ФК (у главы совпадает ФИО). */
.pm-team-tag {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  padding: 1px 6px;
  border-radius: 6px;
  background: rgba(var(--v-theme-primary), 0.16);
  color: rgb(var(--v-theme-primary));
  flex-shrink: 0;
}
.pm-l3 .pm-name { display: block; }
/* Строки-состояния в «Итого» (В работе / Активировано / Факт) */
.pm-state-name { font-style: normal; }
.pm-state-tag {
  display: inline-block; font-size: 11px; font-weight: 600;
  padding: 1px 8px; border-radius: 6px; letter-spacing: 0.02em;
}
.pm-state-inwork   { background: rgba(var(--v-theme-warning), 0.16);   color: rgb(var(--v-theme-warning)); }
.pm-state-forecast { background: rgba(var(--v-theme-info), 0.16);      color: rgb(var(--v-theme-info)); }
.pm-state-fact     { background: rgba(var(--v-theme-success), 0.18);   color: rgb(var(--v-theme-success)); }
.pm-total { background: rgba(var(--v-theme-on-surface), 0.04); border-top: 2px solid rgba(var(--v-theme-on-surface), 0.12); }
.pm-total td { border-bottom: none; }

/* Помесячные группы колонок */
.pm-grid thead th.pm-mgroup {
  text-align: center; font-size: 11px;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  border-left: 1px solid rgba(var(--v-theme-on-surface), 0.06);
}
.pm-grid thead th.pm-total-hd { color: rgb(var(--v-theme-primary)); }
.pm-grid thead th.pm-sub-th {
  font-size: 10px; padding: 4px 8px; text-align: right;
}
.pm-grid thead th.pm-total-th { color: rgb(var(--v-theme-primary)); }
.pm-sep { border-right: 1px solid rgba(var(--v-theme-on-surface), 0.1); }
.pm-month { font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.78); }
.pm-total-cell { background: rgba(var(--v-theme-primary), 0.04); font-weight: 500; }
</style>
