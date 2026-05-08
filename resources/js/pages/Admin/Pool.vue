<template>
  <div>
    <PageHeader title="Комиссии пула" icon="mdi-cash-multiple" />

    <!-- 1.1 Фильтры -->
    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по ФИО"
          density="compact" variant="outlined" hide-details rounded clearable
          prepend-inner-icon="mdi-magnify" style="max-width:300px" />
        <v-text-field v-model="month" type="month" label="Отчётный месяц"
          density="compact" variant="outlined" hide-details style="max-width:200px"
          @update:model-value="loadParticipants" />
        <v-spacer />
        <v-btn variant="text" size="small" prepend-icon="mdi-filter-remove" @click="resetFilters">
          Очистить фильтры
        </v-btn>
      </div>
    </v-card>

    <!-- 1.2 Список модерации -->
    <v-card class="mb-3">
      <v-card-title class="text-subtitle-1 d-flex align-center ga-2">
        <v-icon size="20">mdi-account-multiple-check</v-icon>
        Участники пула на {{ monthLabel }}
        <v-chip v-if="filteredParticipants.length" size="x-small" color="primary" variant="tonal">
          {{ filteredParticipants.length }}
        </v-chip>
        <v-spacer />
        <ColumnVisibilityMenu
          :headers="participantHeaders"
          v-model:visible="participantColumnVisible"
          storage-key="pool-participants-cols" />
      </v-card-title>

      <v-data-table :items="filteredParticipants" :headers="visibleParticipantHeaders"
        :items-per-page="50" density="compact" hover :loading="loadingParticipants">
        <template #item.participates="{ item }">
          <v-checkbox :model-value="item.participates" hide-details density="compact" color="success"
            :loading="toggling[item.id]" :disabled="isFrozen"
            @update:model-value="v => toggleParticipates(item, v)" />
        </template>
        <template #item.level="{ item }">
          <v-chip size="x-small" :color="levelColor(item.level)" variant="tonal">
            {{ item.level }} {{ item.levelName }}
          </v-chip>
        </template>
        <template #item.eligibility="{ item }">
          <v-chip v-if="item.eligible !== false" size="x-small" color="success" variant="tonal" prepend-icon="mdi-check">
            ОК
          </v-chip>
          <v-tooltip v-else location="top" :text="item.disqualifyReason || 'Исключён'">
            <template #activator="{ props }">
              <v-chip v-bind="props" size="x-small" color="error" variant="tonal" prepend-icon="mdi-alert">
                {{ item.disqualifyReason || 'Исключён' }}
              </v-chip>
            </template>
          </v-tooltip>
        </template>
        <template #no-data>
          <EmptyState message="Нет партнёров уровня 6+ за этот месяц" />
        </template>
      </v-data-table>

      <v-card-actions>
        <!-- Disable только если период заморожен или уже идёт расчёт.
             Раньше кнопка дизаблилась когда `filteredParticipants` пуст —
             но это не понятно пользователю; теперь оператор всегда может
             нажать «Рассчитать», даже на месяце без партнёров (получит
             корректный 0). -->
        <v-btn color="success" prepend-icon="mdi-account-multiple-plus" size="large"
          :disabled="isFrozen" :loading="calcing"
          @click="calcPool">
          Рассчитать пул
        </v-btn>
        <v-btn v-if="result && !isFrozen" color="primary" prepend-icon="mdi-lock-check"
          size="large" variant="flat" :loading="applying" @click="applyPool">
          Зафиксировать пул
        </v-btn>
        <template v-else-if="isFrozen">
          <v-chip size="small" color="warning" variant="tonal" prepend-icon="mdi-lock">
            Зафиксировано{{ closureLabel }}
          </v-chip>
          <v-btn v-if="auth.isAdmin" size="small" variant="text" color="error"
            prepend-icon="mdi-lock-open-variant" :loading="reopening" class="ml-2"
            @click="reopenPool">
            Разморозить
          </v-btn>
        </template>
        <v-spacer />
        <!-- В исторических периодах revenue/fund/forfeited приходят null —
             эти цифры неконсистентны с poolLog (выручка/qLog могли
             измениться). Показываем «—» вместо подделки. -->
        <span v-if="result" class="text-caption text-medium-emphasis">
          Выручка ДС без НДС: <strong>{{ moneyOrDash(result.revenue) }}</strong>
          · Фонд на уровень: <strong>{{ moneyOrDash(result.fund) }}</strong>
          ·
          <!-- «К выплате» = totalRowSum (согласованно с табличным ИТОГО,
               пересчитывается при изменении галок), а не static totalPaid
               из snapshot, чтобы числа в шапке и таблице совпадали. -->
          К выплате: <strong class="text-success">{{ fmt2(totalRowSum) }} ₽</strong>
          · Остаётся ДС: <strong class="text-warning">{{ moneyOrDash(result.totalForfeited) }}</strong>
        </span>
      </v-card-actions>

      <!-- Прогресс async-расчёта (см. ApplyPoolJob). -->
      <div v-if="applyProgress" class="pa-3" style="border-top:1px solid rgba(var(--v-theme-on-surface),0.1);">
        <div class="d-flex align-center ga-2 mb-1">
          <v-icon size="16" :color="applyProgress.status === 'error' ? 'error' : 'primary'">
            {{ applyProgress.status === 'error' ? 'mdi-alert' : 'mdi-progress-clock' }}
          </v-icon>
          <span class="text-caption">{{ applyProgress.message || 'Расчёт пула…' }}</span>
        </div>
        <v-progress-linear :model-value="applyProgress.percent || 0"
          :color="applyProgress.status === 'error' ? 'error' : 'primary'"
          height="6" rounded />
      </div>
    </v-card>

    <!-- 1.4 Таблица начислений (всегда видна — auto-preview после загрузки) -->
    <v-card>
      <v-card-title class="text-subtitle-1">
        <v-icon size="20" class="mr-1">mdi-calculator</v-icon>
        Начисления пула
        <v-chip v-if="result && isHistoricalView" size="x-small" color="warning" variant="tonal" class="ml-2"
          title="Данные взяты из poolLog/CSV «как есть» — фонд и доли не пересчитываются по текущему qualificationLog">
          snapshot
        </v-chip>
        <v-chip v-else-if="result" size="x-small" color="info" variant="tonal" class="ml-2">
          preview
        </v-chip>
      </v-card-title>

      <div v-if="!result && !calcing" class="pa-6 text-center text-medium-emphasis">
        <v-icon size="48" color="grey">mdi-calculator-variant-outline</v-icon>
        <div class="mt-2">Нажмите «Рассчитать пул», чтобы увидеть детализацию.</div>
      </div>

      <div v-else-if="!result" class="pa-6 text-center">
        <v-progress-circular indeterminate color="primary" />
      </div>

      <v-table v-if="result" density="compact" class="pool-results">
        <thead>
          <tr>
            <th>Период</th>
            <th>Партнёр</th>
            <th>Квалификация</th>
            <th class="text-end">Групповой бонус</th>
            <th v-for="lvl in [6,7,8,9,10]" :key="lvl" class="text-end">{{ lvl }} кв.</th>
            <th class="text-end">Комиссия пула</th>
          </tr>
        </thead>
        <tbody>
          <!-- Итого:
               • Live-период (открытый): FUND × #активных уровней — теоретический
                 максимум распределения (как в эталоне старой платформы).
               • Исторический/закрытый: сумма реально выплаченного per level
                 + общий totalPaid. Так список ФК сходится с цифрой ИТОГО. -->
          <tr class="pool-total-row">
            <td colspan="3" class="font-weight-bold text-success">
              {{ isHistoricalView ? 'ИТОГО (выплачено по snapshot)' : 'ИТОГО (фонд × #активных уровней)' }}
            </td>
            <!-- В историческом режиме revenue из poolLog неизвестен —
                 показываем «—» вместо живого пересчёта. -->
            <td class="text-end font-weight-bold text-success">{{ moneyOrDash(result.revenue) }}</td>
            <td v-for="lvl in [6,7,8,9,10]" :key="'tot-'+lvl" class="text-end font-weight-bold text-success">
              {{ isHistoricalView && totalCellForLevel(lvl) === 0 ? '—' : fmt2(totalCellForLevel(lvl)) }}
            </td>
            <td class="text-end font-weight-bold text-success">{{ fmt2(totalRowSum) }} ₽</td>
          </tr>

          <tr v-for="p in payoutRows" :key="'pay-' + p.id">
            <td>{{ monthShort }}</td>
            <td>{{ p.personName }}</td>
            <td>
              <v-chip size="x-small" :color="levelColor(p.level)" variant="tonal">
                {{ p.level }} {{ p.levelName }}
              </v-chip>
            </td>
            <td class="text-end">{{ moneyOrDash(p.groupBonusRub) }}</td>
            <td v-for="lvl in [6,7,8,9,10]" :key="'r-' + p.id + '-' + lvl" class="text-end">
              <span :class="p.byLevel[lvl] > 0 ? 'text-success' : 'text-medium-emphasis'">
                {{ fmt2(p.byLevel[lvl] || 0) }}
              </span>
            </td>
            <td class="text-end font-weight-bold">{{ fmt2(p.payoutRub) }} ₽</td>
          </tr>

          <tr v-if="!payoutRows.length">
            <td :colspan="9" class="text-center text-medium-emphasis pa-4">
              Никому не начислено (никто не подтвердил квалификацию или все исключены оператором).
            </td>
          </tr>
        </tbody>
      </v-table>
    </v-card>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="4000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import { fmt2 } from '../../composables/useDesign';
import { useConfirm } from '../../composables/useConfirm';
import { useAuthStore } from '../../stores/auth';

const confirm = useConfirm();
const auth = useAuthStore();

const month = ref(new Date().toISOString().slice(0, 7));
const defaultMonth = month.value;
const search = ref('');
const participants = ref([]);
const result = ref(null);
const loadingParticipants = ref(false);
const calcing = ref(false);
const applying = ref(false);
const reopening = ref(false);
const toggling = ref({});

const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

// Формат «X ₽» или «—» для значений, которых нет в snapshot (revenue/fund
// для исторических периодов приходят null — показываем «—», а не «0,00 ₽»).
function moneyOrDash(v) {
  if (v === null || v === undefined) return '—';
  return `${fmt2(v)} ₽`;
}

// Развёрнутый формат "Февраль 2026" для заголовка карточки и для
// поля «Период» в нижней таблице. Соответствует эталону старой платформы.
const RU_MONTHS = ['Январь','Февраль','Март','Апрель','Май','Июнь',
  'Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
const monthLabel = computed(() => {
  if (!month.value) return '';
  const [y, m] = month.value.split('-');
  return `${RU_MONTHS[parseInt(m, 10) - 1]} ${y}`;
});
const monthShort = computed(() => {
  if (!month.value) return '';
  const [y, m] = month.value.split('-');
  return `${m}.${y}`;
});

const isFrozen = computed(() => !!result.value?.frozen);
const closureLabel = computed(() => {
  const c = result.value?.closure;
  if (!c) return '';
  const who = c.closed_by_name ? ` ${c.closed_by_name}` : '';
  const when = c.closed_at ? new Date(c.closed_at).toLocaleDateString('ru-RU') : '';
  return when ? `${who} ${when}` : who;
});

const participantHeaders = [
  { title: 'Участвует', key: 'participates', width: 110, sortable: false },
  { title: 'Партнёр', key: 'personName' },
  { title: 'Квалификация', key: 'level', width: 200 },
  { title: 'Условие выплаты', key: 'eligibility', width: 220, sortable: false },
];

const participantColumnVisible = ref({});
const visibleParticipantHeaders = computed(() =>
  participantHeaders.filter(h => participantColumnVisible.value[h.key] !== false)
);

function levelColor(lvl) {
  const map = { 6: 'amber-darken-1', 7: 'grey', 8: 'orange', 9: 'blue', 10: 'purple' };
  return map[lvl] || 'default';
}

const filteredParticipants = computed(() => {
  if (!search.value) return participants.value;
  const term = search.value.toLowerCase();
  return participants.value.filter(p =>
    (p.personName || '').toLowerCase().includes(term)
  );
});

// Историческая выгрузка vs live-расчёт. Backend ставит fromPoolLog=true
// (либо fromCsv=true) для закрытых периодов — там shareValues пустой,
// payoutRub каждого партнёра берётся из poolLog «как есть». Для новых
// периодов считаем по формуле: матрёшка share(6)..share(L), ИТОГО =
// fund × #активных уровней.
const isHistoricalView = computed(() => !!(result.value?.fromPoolLog || result.value?.fromCsv));

// Нерегулярный snapshot: на одном уровне партнёры получили разные суммы
// (legacy Directual-прорейт). Бэк не строит shareValues и ставит флаг
// `irregularPayouts`; фронт в этом режиме рисует payoutRub в колонке
// СВОЕГО уровня, без матрёшки. Дублируем проверку по shareValues —
// fallback если бэк-флага нет (старые ответы).
const irregularSnapshot = computed(() => {
  if (!result.value) return false;
  if (result.value.irregularPayouts) return true;
  if (isHistoricalView.value) {
    const shares = result.value.shareValues || {};
    return ![6, 7, 8, 9, 10].some(l => (shares[l] || 0) > 0);
  }
  return false;
});

// Helper: партнёр должен включаться в подсчёт ИТОГО, если оператор НЕ
// снял галку (participates !== false) и есть payoutRub. Без этого фильтра
// исключённые галкой (как Денис в Март-2026) попадали в ИТОГО, хотя в
// payoutRows их уже нет — UI противоречил сам себе.
function isCountable(p) {
  return p && p.participates !== false && Number(p.payoutRub || 0) > 0;
}

// Cumulative count активных партнёров уровня L+ (level >= L && participates).
// Используется и для перераспределения share[L], и для строки ИТОГО.
function activeCountAtLevel(lvl) {
  if (!result.value) return 0;
  let n = 0;
  for (const p of result.value.participants || []) {
    if (isCountable(p) && p.level >= lvl && p.level <= 10) n++;
  }
  return n;
}

// Динамические share[L] для регулярного snapshot. Snapshot хранит share как
// «делилось среди тех кто получил выплату на момент фиксации Directual», но
// если оператор снимает галку — фонд должен перераздаться на оставшихся
// (1% × выручка ÷ count(L+ active)). Не трогаем нерегулярные снапшоты —
// у них в каждой строке свой payoutRub без матрёшки.
//
// Возвращает null если перераздача невозможна (нет fund / нерегулярка) —
// тогда упадём на статический shareValues из бэка.
const effectiveShares = computed(() => {
  if (!result.value) return null;
  if (irregularSnapshot.value) return null;
  const fund = Number(result.value.fund || 0);
  if (fund <= 0) return null;
  const out = {};
  for (const lvl of [6, 7, 8, 9, 10]) {
    const cnt = activeCountAtLevel(lvl);
    out[lvl] = cnt > 0 ? fund / cnt : 0;
  }
  return out;
});

// Берём dynamic shares если рассчитались, иначе статика из бэка.
function shareForLevel(lvl) {
  if (effectiveShares.value) return Number(effectiveShares.value[lvl] || 0);
  return Number(result.value?.shareValues?.[lvl] || 0);
}

// Активные уровни — где share[L] > 0 (с учётом dynamic redistribution)
// либо есть выплата в payoutRub (нерегулярный snapshot).
const activeLevels = computed(() => {
  if (!result.value) return [];
  if (irregularSnapshot.value) {
    const set = new Set();
    for (const p of result.value.participants || []) {
      if (isCountable(p) && p.level >= 6 && p.level <= 10) set.add(p.level);
    }
    return [6, 7, 8, 9, 10].filter(l => set.has(l));
  }
  return [6, 7, 8, 9, 10].filter(lvl => shareForLevel(lvl) > 0);
});

// Ячейка уровня в строке ИТОГО.
//   Live: fund (если уровень активен).
//   Регулярный snapshot: share(L) × cumulativeCount(L+ активных) — это и
//     есть «1% × выручка», т.к. share = fund/count.
//   Нерегулярный snapshot: сумма payoutRub участвующих партнёров уровня L.
function totalCellForLevel(lvl) {
  if (!result.value) return 0;
  if (irregularSnapshot.value) {
    let s = 0;
    for (const p of result.value.participants || []) {
      if (p.level === lvl && isCountable(p)) s += Number(p.payoutRub);
    }
    return s;
  }
  if (isHistoricalView.value) {
    const share = shareForLevel(lvl);
    if (share <= 0) return 0;
    return share * activeCountAtLevel(lvl);
  }
  return activeLevels.value.includes(lvl) ? Number(result.value.fund || 0) : 0;
}

// Матрёшка для строк партнёров.
//   Live + регулярный snapshot: byLevel[L] = effectiveShare(L) для L≤partner.level.
//     payoutRub партнёра пересчитывается = sum byLevel[6..level].
//   Нерегулярный snapshot: byLevel[partner.level] = payoutRub целиком,
//     перераздачи нет (back-derive не сходится).
const payoutRows = computed(() => {
  if (!result.value) return [];
  const irregular = irregularSnapshot.value;
  const revenue = result.value.revenue === null || result.value.revenue === undefined
    ? null : Number(result.value.revenue);
  return (result.value.participants || [])
    .filter(p => p.participates && Number(p.payoutRub || 0) > 0)
    .map(p => {
      const byLevel = { 6: 0, 7: 0, 8: 0, 9: 0, 10: 0 };
      let payout = 0;
      if (irregular) {
        if (p.level >= 6 && p.level <= 10) {
          byLevel[p.level] = Number(p.payoutRub);
          payout = Number(p.payoutRub);
        }
      } else {
        for (let lvl = 6; lvl <= p.level; lvl++) {
          const s = shareForLevel(lvl);
          byLevel[lvl] = s;
          payout += s;
        }
      }
      return {
        id: p.id,
        personName: p.personName,
        level: p.level,
        levelName: p.levelName,
        byLevel,
        payoutRub: payout,
        groupBonusRub: revenue,
      };
    });
});

// Сумма строки ИТОГО — total row sum, согласованный с payoutRows.
const totalRowSum = computed(() => {
  if (!result.value) return 0;
  if (isHistoricalView.value) {
    return payoutRows.value.reduce((s, p) => s + Number(p.payoutRub || 0), 0);
  }
  return Number(result.value.fund || 0) * activeLevels.value.length;
});

function resetFilters() {
  search.value = '';
  month.value = defaultMonth;
  loadParticipants();
}

// Guard от race condition: при быстрой смене месяца ответы могут вернуться
// в обратном порядке и затереть актуальные данные более старыми.
// Вместо AbortController — простой счётчик: применяем результат только если
// мы всё ещё ждём именно его (tag не сбит другим вызовом).
let loadTag = 0;

async function loadParticipants() {
  if (!month.value) return;
  const myTag = ++loadTag;
  const requestedMonth = month.value;
  loadingParticipants.value = true;
  try {
    const [y, m] = requestedMonth.split('-');
    const { data } = await api.get('/admin/pool/participants', {
      params: { year: Number(y), month: Number(m) },
    });
    if (myTag !== loadTag || month.value !== requestedMonth) return; // устаревший ответ
    participants.value = data.participants || [];
    // Тот же ответ — уже полноценный preview (live для открытого периода,
    // snapshot для заморожённого), сразу кладём в result чтобы UI не ждал
    // повторного запроса.
    result.value = data;
  } catch (e) {
    if (myTag !== loadTag) return;
    notify(e.response?.data?.message || 'Ошибка загрузки участников', 'error');
  } finally {
    if (myTag === loadTag) loadingParticipants.value = false;
  }
}

async function toggleParticipates(item, value) {
  toggling.value[item.id] = true;
  try {
    const [y, m] = month.value.split('-');
    await api.put('/admin/pool/participants', {
      year: Number(y), month: Number(m),
      consultant: item.id, participates: !!value,
    });
    item.participates = !!value;
    // Авто-пересчёт превью: оператор сразу видит как изменение галочки
    // влияет на распределение (не нужно нажимать «Рассчитать пул» вручную).
    // calcPool сам выставит loading-индикатор и дернёт /admin/pool/preview.
    await calcPool();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  toggling.value[item.id] = false;
}

async function calcPool() {
  if (!month.value) return;
  calcing.value = true;
  try {
    const [y, m] = month.value.split('-');
    const { data } = await api.post('/admin/pool/preview', {
      year: Number(y), month: Number(m),
    });
    result.value = data;
    notify('Пул рассчитан (preview, без записи)');
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка расчёта', 'error');
  }
  calcing.value = false;
}

// Прогресс async-расчёта (см. ApplyPoolJob).
const applyProgress = ref(null);
let applyPollTimer = null;

async function applyPool() {
  if (!month.value || !result.value || isFrozen.value) return;
  if (!await confirm.ask({
    title: 'Зафиксировать пул?',
    message: 'Рассчитанные суммы будут записаны в poolLog, а период '
           + 'закрыт от изменений. Удалить или пересчитать его потом '
           + 'будет нельзя — только через ручную разморозку админом.',
    confirmText: 'Зафиксировать', confirmColor: 'primary', icon: 'mdi-lock-check',
  })) return;
  applying.value = true;
  applyProgress.value = { status: 'queued', percent: 0, message: 'Постановка в очередь…' };
  try {
    const [y, m] = month.value.split('-');
    const { data } = await api.post('/admin/pool/apply', {
      year: Number(y), month: Number(m),
    });
    if (data.batch_id) {
      pollApplyProgress(data.batch_id);
    } else {
      // Старый sync-ответ (бэк ещё не обновлён) — обработаем как раньше.
      notify(`Пул зафиксирован: ${(data.result || data).written} записей.`);
      applying.value = false;
      applyProgress.value = null;
      await loadParticipants();
    }
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка фиксации', 'error');
    applying.value = false;
    applyProgress.value = null;
  }
}

async function reopenPool() {
  if (!month.value || !isFrozen.value || !auth.isAdmin) return;
  if (!await confirm.ask({
    title: 'Разморозить период?',
    message: 'Период будет открыт для повторного расчёта пула. '
           + 'Текущие записи в poolLog останутся до повторной фиксации, '
           + 'после которой они будут перезаписаны.\n\nДействие записывается '
           + 'в audit-log.',
    confirmText: 'Разморозить', confirmColor: 'error', icon: 'mdi-lock-open-variant',
  })) return;
  reopening.value = true;
  try {
    const [y, m] = month.value.split('-');
    await api.post('/admin/pool/reopen', {
      year: Number(y), month: Number(m),
    });
    notify('Период разморожен. Можно пересчитать и зафиксировать заново.');
    await loadParticipants();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка разморозки', 'error');
  }
  reopening.value = false;
}

function pollApplyProgress(batchId) {
  clearInterval(applyPollTimer);
  applyPollTimer = setInterval(async () => {
    try {
      const { data } = await api.get('/admin/pool/progress', {
        params: { batch_id: batchId },
      });
      applyProgress.value = data;
      if (data.status === 'done') {
        clearInterval(applyPollTimer);
        applyPollTimer = null;
        notify(data.message || 'Пул зафиксирован', 'success');
        applying.value = false;
        await loadParticipants();
        applyProgress.value = null;
      } else if (data.status === 'error') {
        clearInterval(applyPollTimer);
        applyPollTimer = null;
        notify(data.message || 'Ошибка фиксации', 'error');
        applying.value = false;
        applyProgress.value = null;
      }
    } catch (e) {
      // 404 на progress = задача истекла или ещё не дошла до cache. Тихо ждём.
      if (e.response?.status !== 404) {
        clearInterval(applyPollTimer);
        applyPollTimer = null;
        applying.value = false;
        notify('Не удалось получить прогресс', 'error');
        applyProgress.value = null;
      }
    }
  }, 1500);
}

onMounted(loadParticipants);
</script>

<style scoped>
.pool-results :deep(td) { vertical-align: middle; }
.pool-total-row td {
  background: rgba(76, 175, 80, 0.12) !important;
  border-top: 2px solid rgba(76, 175, 80, 0.4) !important;
  border-bottom: 2px solid rgba(76, 175, 80, 0.4) !important;
}
</style>
