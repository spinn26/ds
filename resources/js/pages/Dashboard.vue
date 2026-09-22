<template>
  <div class="dash">
    <header class="dash-head">
      <div>
        <h1>Дашборд партнёра</h1>
        <p>Квалификация, объёмы и команда за выбранный месяц</p>
      </div>
      <MonthPicker v-model="period" @update:model-value="loadData" />
    </header>

    <div class="dash-top">
      <!-- Квалификация: кольцо с уровнем и путь до следующего.
           Блок на brand-deep — самый важный на странице. -->
      <section class="hero">
        <span class="hero-mesh" aria-hidden="true"></span>

        <div class="hero-qual">
          <ProgressRing class="hero-ring" :percent="nqpProgress" :size="116" :width="10">
            {{ currentLevel?.level ?? '—' }}
          </ProgressRing>
          <div class="hero-qual-text">
            <div class="hero-eyebrow">Текущая квалификация</div>
            <div class="hero-title">{{ currentLevel?.title ?? 'Start' }}</div>
            <div class="hero-chips">
              <span v-if="data.consultant.activityName" class="hero-chip" :class="{ on: data.consultant.active }">
                <span class="dot"></span>{{ data.consultant.activityName }}
              </span>
              <span class="hero-chip">Комиссия {{ currentLevel?.percent ?? 15 }}%</span>
            </div>
          </div>
        </div>

        <div class="hero-panel">
          <template v-if="data.qualification.nextLevel">
            <div class="hp-row">
              <span class="hp-label">НГП до «{{ data.qualification.nextLevel.title }}»</span>
              <span class="hp-value">
                <b>{{ fmt(data.volumes.groupVolumeCumulative) }}</b>
                / {{ fmt(data.qualification.nextLevel.groupVolumeCumulative) }}
              </span>
            </div>
            <div class="hp-bar"><span :style="{ width: nqpProgress + '%' }"></span></div>
            <div class="hp-row hp-scale">
              <span>{{ currentLevel?.level ?? '—' }} · {{ currentLevel?.title ?? 'Start' }}</span>
              <span>{{ data.qualification.nextLevel.level }} · {{ data.qualification.nextLevel.title }}</span>
            </div>
            <p class="hp-note">
              Осталось <b>{{ fmt(Math.max(0, (data.qualification.nextLevel.groupVolumeCumulative || 0) - data.volumes.groupVolumeCumulative)) }}</b>
              баллов НГП — это {{ Math.round(nqpProgress) }}% пути
            </p>
          </template>
          <p v-else class="hp-note hp-note--max">Максимальная квалификация достигнута</p>

          <!-- Снимок обновляется пересчётом: пока он не собран, свежие продажи
               в шкалу не попадают. Говорим прямо — иначе партнёр считает, что
               сделку потеряли. -->
          <p v-if="pending" class="hp-pending">
            +{{ fmt(pending.groupVolume) }} в этом месяце ещё не в снимке →
            <b>{{ fmt(pending.projectedGroupVolumeCumulative) }}</b> после закрытия месяца<template v-if="pending.snapshotAt">, снимок от {{ pending.snapshotAt }}</template>
          </p>

          <!-- ОП по ГП: обязательный групповой план текущего уровня. -->
          <div v-if="data.mandatoryPlan" class="hp-mandatory">
            <div class="hp-row">
              <span class="hp-label">ОП по ГП</span>
              <span class="hp-value">
                <b>{{ fmt(data.mandatoryPlan.currentGP) }}</b> / {{ fmt(data.mandatoryPlan.mandatoryGP) }}
              </span>
            </div>
            <div class="hp-bar">
              <span :class="{ warn: !data.mandatoryPlan.fulfilled }"
                :style="{ width: Math.min(100, data.mandatoryPlan.fulfillment) + '%' }"></span>
            </div>
          </div>

          <button type="button" class="hp-btn" @click="showLevels = true">
            <Table2 :size="16" :stroke-width="1.8" />
            Условия квалификаций
          </button>
        </div>
      </section>

      <!-- Срок по баллам. У «Зарегистрирован» это активационное окно, у
           «Активен» — годовой период удержания: не набрал 500 ЛП за год —
           договор расторгается. Называть второе «активацией» нельзя, партнёр
           уже активен и читает это как «ещё не начал».
           На последнем месяце текст МЕНЯЕТСЯ и карточка перестаёт
           закрываться: на кону расторжение договора, а не просто счётчик. -->
      <section v-if="data.statusInfo && data.statusInfo.daysRemaining != null && !activationHidden"
        class="card activation" :class="{ 'activation--danger': deadlineSoon }">
        <header class="act-head">
          <span class="act-ic"><Flag :size="18" :stroke-width="1.8" /></span>
          <div class="act-title">
            <b>{{ deadlineSoon
              ? 'До терминации ' + data.statusInfo.daysRemaining + ' ' + plural(data.statusInfo.daysRemaining, 'день', 'дня', 'дней')
              : (isYearPeriod ? 'Годовой период' : 'Активационный период') }}</b>
            <span v-if="deadlineSoon">
              Если к {{ deadlineDate }} не набрать {{ fmt(data.statusInfo.requiredPoints) }} ЛП, агентский
              договор будет расторгнут: баллы обнулятся, клиенты и контракты перейдут наставнику.
              <template v-if="data.statusInfo.reinstate?.limit">
                Восстановить участие можно будет самостоятельно — доступно
                {{ data.statusInfo.reinstate.limit }}
                {{ plural(data.statusInfo.reinstate.limit, 'попытка', 'попытки', 'попыток') }}.
              </template>
            </span>
            <span v-else-if="isYearPeriod">
              До {{ deadlineDate }} нужно набрать {{ fmt(data.statusInfo.requiredPoints) }} ЛП,
              чтобы сохранить участие. Баллы периода считаются по вашим личным продажам.
            </span>
            <span v-else>Наберите {{ fmt(data.statusInfo.requiredPoints) }} баллов, чтобы активироваться</span>
          </div>
          <button v-if="!deadlineSoon" type="button" class="act-close" aria-label="Скрыть"
            @click="activationHidden = true">
            <X :size="16" :stroke-width="1.8" />
          </button>
        </header>

        <div class="act-body">
          <ProgressRing class="act-ring" :percent="statusProgress" :size="88" :width="8" />
          <div class="act-facts">
            <div>
              <span class="act-cap">Набрано</span>
              <span class="act-num"><b>{{ fmt(data.statusInfo.currentPoints) }}</b> / {{ fmt(data.statusInfo.requiredPoints) }}</span>
            </div>
            <div>
              <span class="act-cap">Осталось дней</span>
              <span class="act-num"><b>{{ data.statusInfo.daysRemaining }}</b></span>
            </div>
          </div>
        </div>
      </section>
    </div>

    <!-- Объёмы. Карточка ведёт в отчёт начислений за период, иконка — в
         динамику по времени. -->
    <h2 class="sec"><BarChart3 :size="18" :stroke-width="1.8" />Объёмы</h2>
    <div class="vol-grid">
      <router-link v-for="card in volumeCards" :key="card.title" :to="card.link" class="vol">
        <div class="vol-head">
          <span class="vol-title">{{ card.title }}</span>
          <button v-if="card.dynamics" type="button" class="vol-ic" title="Динамика по времени"
            :aria-label="'Динамика: ' + card.title"
            @click.prevent.stop="openDynamics(card.dynamics)">
            <component :is="volIcon(card.lucide)" :size="18" :stroke-width="1.8" />
          </button>
          <span v-else class="vol-ic vol-ic--static">
            <component :is="volIcon(card.lucide)" :size="18" :stroke-width="1.8" />
          </span>
        </div>

        <div class="vol-value" :class="card.tone">{{ fmt(card.value) }}</div>
        <div v-if="card.subValue" class="vol-sub">{{ card.subValue }}</div>
        <div v-if="card.pending > 0" class="vol-pending">
          +{{ fmt(card.pending) }} → {{ fmt(card.projected) }} после закрытия месяца
        </div>

        <div class="vol-delta" :class="card.changeType">
          <component :is="card.changeType === 'up' ? TrendingUp : card.changeType === 'down' ? TrendingDown : Minus"
            :size="14" :stroke-width="1.8" />
          {{ card.change }} <span>к прошлому месяцу</span>
        </div>
      </router-link>
    </div>

    <!-- Отрыв: те же пороги 70/90, что в финрезе. -->
    <section v-if="data.breakaway" class="card breakaway">
      <header class="bw-head">
        <h2 class="card-h"><Network :size="18" :stroke-width="1.8" />Отрыв</h2>
        <span class="bw-chip" :class="breakawayTone">
          <component :is="data.breakaway.poolBlocked ? AlertOctagon : data.breakaway.gpHeld ? AlertCircle : CheckCircle2"
            :size="14" :stroke-width="1.8" />
          {{ data.breakaway.poolBlocked ? 'Отрыв ≥ 90% — пул не выплачивается'
           : data.breakaway.gpHeld ? 'Отрыв ≥ 70% — ветка не учитывается в ГП'
           : 'Отрыва нет' }}
        </span>
        <InfoHint :text="glossary.breakaway" class="bw-hint" />
      </header>

      <div class="bw-grid">
        <div class="bw-tile">
          <span class="bw-cap">Топ-ветка</span>
          <span class="bw-val">{{ data.breakaway.partnerName || '—' }}</span>
        </div>
        <div class="bw-tile">
          <span class="bw-cap">ГП ветки</span>
          <span class="bw-val num">{{ fmt(data.breakaway.groupVolume) }}</span>
        </div>
        <div class="bw-tile">
          <span class="bw-cap">Доля от моего ГП</span>
          <span class="bw-val num" :class="breakawayTone">{{ data.breakaway.gapPercentage ?? 0 }}%</span>
        </div>
        <div class="bw-tile">
          <span class="bw-cap">Превышение</span>
          <span class="bw-val num">{{ fmt(data.breakaway.gapValue) }}</span>
        </div>
      </div>

      <div class="bw-scale">
        <div class="bw-track">
          <span class="bw-zone bw-zone--ok"></span>
          <span class="bw-zone bw-zone--warn"></span>
          <span class="bw-zone bw-zone--bad"></span>
          <span class="bw-marker" :class="breakawayTone"
            :style="{ left: Math.min(100, data.breakaway.gapPercentage || 0) + '%' }">
            {{ data.breakaway.gapPercentage ?? 0 }}%
          </span>
        </div>
        <div class="bw-legend">
          <span>0–70%<b>норма</b></span>
          <span>70–90%<b>удержание ГП</b></span>
          <span>90–100%<b>блокировка пула</b></span>
        </div>
      </div>
    </section>

    <div class="two-col">
      <!-- Команда: каждая плитка → структура с готовым фильтром. -->
      <section class="card">
        <h2 class="card-h"><Users :size="18" :stroke-width="1.8" />Команда</h2>
        <div class="team-grid">
          <router-link v-for="kpi in teamKpis" :key="kpi.label" :to="kpi.link" class="team-tile">
            <span class="team-ic"><UserRound :size="16" :stroke-width="1.8" /></span>
            <span class="team-label">{{ kpi.label }}</span>
            <span class="team-value num">{{ kpi.value }}</span>
          </router-link>
        </div>
      </section>

      <!-- Клиенты: двумя строками, каждая → список с нужным охватом. -->
      <section class="card">
        <h2 class="card-h"><UserRound :size="18" :stroke-width="1.8" />Клиенты</h2>
        <router-link to="/clients?scope=team" class="cl-row">
          <span class="cl-ic"><Users :size="16" :stroke-width="1.8" /></span>
          <span class="cl-label">Клиенты команды</span>
          <span class="cl-value num">{{ data.team?.teamClients ?? 0 }}</span>
          <ChevronRight :size="18" :stroke-width="1.8" />
        </router-link>
        <router-link to="/clients?scope=mine" class="cl-row">
          <span class="cl-ic"><UserRound :size="16" :stroke-width="1.8" /></span>
          <span class="cl-label">Мои клиенты</span>
          <span class="cl-value num">{{ data.team?.myClients ?? 0 }}</span>
          <ChevronRight :size="18" :stroke-width="1.8" />
        </router-link>
      </section>
    </div>

    <!-- Партнёры по статусу: всего слева, состав — полосой и легендой. -->
    <section class="card partners">
      <h2 class="card-h"><Users :size="18" :stroke-width="1.8" />Партнёры по статусу</h2>

      <div class="pt-body">
        <router-link class="pt-total" :to="partnerCards[0].link">
          <span class="pt-cap">{{ partnerCards[0].label }}</span>
          <span class="pt-num num">{{ partnerCards[0].value }}</span>
          <span class="pt-delta" :class="deltaTone(partnerCards[0].diff)">
            <component :is="partnerCards[0].diff > 0 ? TrendingUp : partnerCards[0].diff < 0 ? TrendingDown : Minus"
              :size="14" :stroke-width="1.8" />
            {{ partnerCards[0].diff > 0 ? '+' : '' }}{{ partnerCards[0].diff }} <span>к прошлому месяцу</span>
          </span>
        </router-link>

        <div class="pt-right">
          <div class="pt-bar">
            <span v-for="s in statusShares" :key="s.label" :class="['pt-seg', s.tone]"
              :style="{ width: s.share + '%' }" :title="`${s.label}: ${s.value}`"></span>
          </div>
          <div class="pt-legend">
            <router-link v-for="card in partnerCards.slice(1)" :key="card.label" :to="card.link" class="pt-item">
              <span class="pt-dot" :class="toneOf(card.color)"></span>
              <span class="pt-label">{{ card.label }}</span>
              <span class="pt-value num">{{ card.value }}</span>
              <span v-if="card.diff != null" class="pt-delta" :class="deltaTone(card.diff)">
                <component :is="card.diff > 0 ? TrendingUp : card.diff < 0 ? TrendingDown : Minus"
                  :size="13" :stroke-width="1.8" />
                {{ card.diff > 0 ? '+' : '' }}{{ card.diff }}
              </span>
            </router-link>
          </div>
        </div>
      </div>
    </section>

    <!-- Conditions dialog (opened by button) -->
    <v-dialog v-model="showLevels" max-width="1000">
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="secondary">mdi-table</v-icon>
          Полная таблица условий квалификаций
        </v-card-title>
        <v-card-text>
          <div style="overflow-x: auto">
            <v-table density="compact">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Квалификация</th>
                  <th class="text-right">%</th>
                  <th class="text-right">НГП</th>
                  <th class="text-right">ОП по ГП</th>
                  <th class="text-right">Отрыв</th>
                  <th class="text-right">Пул</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="lv in levels" :key="lv.id"
                  :class="lv.level === currentLevel?.level ? 'bg-green-lighten-5' : ''">
<td>{{ lv.level }}</td>
                  <td class="font-weight-medium">
                    {{ lv.title }}
                    <v-chip v-if="lv.level === currentLevel?.level" size="x-small" color="success" class="ml-1">Текущий</v-chip>
                    <v-chip v-if="lv.level === data.qualification.nextLevel?.level" size="x-small" color="info" class="ml-1">Следующий</v-chip>
                  </td>
                  <td class="text-right">{{ lv.percent }}%</td>
                  <td class="text-right">{{ fmt(lv.groupVolumeCumulative) }}</td>
                  <td class="text-right">{{ lv.mandatoryGP > 0 ? fmt(lv.mandatoryGP) : '—' }}</td>
                  <td class="text-right">{{ lv.otrif > 0 ? lv.otrif + '%' : '—' }}</td>
                  <td class="text-right">{{ lv.pool > 0 ? lv.pool + '%' : '—' }}</td>
                </tr>
              </tbody>
            </v-table>
          </div>

        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="showLevels = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Динамика личных продаж по времени. Открывается кликом по иконке
         карточки ЛП. Два разреза: месяцы года и дни месяца. -->
    <v-dialog v-model="showDynamics" max-width="980" scrollable>
      <v-card>
        <v-card-title class="d-flex align-center ga-2 flex-wrap">
          <v-icon color="green">mdi-chart-line</v-icon>
          <span>Динамика · {{ dynTitle }}</span>
          <v-spacer />
          <v-btn-toggle v-model="dynScope" mandatory density="compact" color="primary" @update:model-value="loadDynamics">
            <v-btn value="year" size="small">За год</v-btn>
            <v-btn value="month" size="small">За месяц</v-btn>
          </v-btn-toggle>
        </v-card-title>

        <v-card-text>
          <div class="d-flex align-center ga-3 flex-wrap mb-4">
            <v-text-field v-if="dynScope === 'year'" v-model="dynYear" label="Год" type="number"
              density="compact" variant="outlined" hide-details style="max-width:140px"
              @change="loadDynamics" />
            <v-text-field v-else v-model="dynMonth" label="Месяц" type="month"
              density="compact" variant="outlined" hide-details style="max-width:200px"
              @change="loadDynamics" />
            <v-spacer />
            <div class="text-body-2 text-medium-emphasis">
              Поступило: <strong class="text-high-emphasis tabular-nums">{{ fmtMoney(dynTotals.amountRub) }}</strong>
              · ЛП: <strong class="text-high-emphasis tabular-nums">{{ fmt(dynTotals.points) }}</strong>
              · Сделок: <strong class="text-high-emphasis tabular-nums">{{ dynTotals.deals }}</strong>
            </div>
          </div>

          <v-progress-linear v-if="dynLoading" indeterminate color="primary" class="mb-3" />

          <div v-if="dynChart" style="height: 340px">
            <Line :data="dynChart.data" :options="dynChart.options" />
          </div>
          <div v-else-if="!dynLoading" class="text-medium-emphasis text-body-2 py-8 text-center">
            За выбранный период продаж не было.
          </div>
        </v-card-text>

        <v-card-actions>
          <v-spacer />
          <v-btn @click="showDynamics = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Loading: top progress bar instead of full-page overlay so the page skeleton stays visible -->
    <v-progress-linear v-if="loading" indeterminate color="primary"
      style="position: fixed; top: 0; left: 0; right: 0; z-index: 9; height: 3px;" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { Line } from 'vue-chartjs';
import {
  Chart as ChartJS,
  Title, Tooltip, Legend, Filler,
  LineElement, PointElement, CategoryScale, LinearScale,
} from 'chart.js';
import {
  Table2, Flag, X, BarChart3, TrendingUp, TrendingDown, Minus, Wallet, Landmark,
  Network, AlertOctagon, AlertCircle, CheckCircle2, Users, UserRound, ChevronRight,
} from 'lucide-vue-next';
import api from '../api';
import MonthPicker from '../components/MonthPicker.vue';
import InfoHint from '../components/InfoHint.vue';
import ProgressRing from '../components/workspace/ProgressRing.vue';
import { fmt } from '../composables/useDesign';
import { glossary } from '../composables/useGlossary';

ChartJS.register(
  Title, Tooltip, Legend, Filler,
  LineElement, PointElement, CategoryScale, LinearScale,
);

// Деньги для подписей: «1 234 ₽» (разряды по-русски, без копеек).
function fmtMoney(v) {
  return (Number(v) || 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' ₽';
}

// Карточку активационного периода можно скрыть на сессию. Кроме последнего
// месяца: там на кону расторжение договора, и прятать предупреждение нельзя.
const activationHidden = ref(false);

// Цвет блока «Отрыв» несут три состояния, дальше он раскрашивает и чип,
// и долю, и маркер на шкале — считаем один раз.
const breakawayTone = computed(() => {
  const b = data.value.breakaway;
  if (! b) return 'ok';
  return b.poolBlocked ? 'bad' : b.gpHeld ? 'warn' : 'ok';
});

function deltaTone(diff) {
  return diff > 0 ? 'up' : diff < 0 ? 'down' : 'flat';
}

// Иконка карточки объёма по её смыслу: деньги, рост, люди.
function volIcon(name) {
  return { bank: Landmark, trend: TrendingUp, users: Users }[name] || Wallet;
}

// Цвета статусов партнёров живут в Vuetify-палитре (info/success/error),
// а новая вёрстка красит токенами — переводим на свои имена.
function toneOf(color) {
  return { info: 'info', success: 'ok', error: 'bad', primary: 'brand' }[color] || 'brand';
}

// Доли для полосы состава команды. Считаем от суммы статусов, а не от
// «всего»: в total входят и те, кого в легенде нет, и полоса не сходилась.
const statusShares = computed(() => {
  const items = partnerCards.value.slice(1).map((c) => ({
    label: c.label,
    value: Number(c.value) || 0,
    tone: toneOf(c.color),
  }));
  const sum = items.reduce((acc, i) => acc + i.value, 0);
  return items.map((i) => ({ ...i, share: sum > 0 ? (i.value / sum) * 100 : 0 }));
});

// ── Динамика личных продаж (диалог по клику на иконку карточки ЛП) ──
const showDynamics = ref(false);
const dynLoading = ref(false);
const dynScope = ref('year');
const dynYear = ref(String(new Date().getFullYear()));
const dynMonth = ref(new Date().toISOString().slice(0, 7));
const dynSeries = ref([]);
const dynTotals = ref({ amountRub: 0, points: 0, deals: 0 });

const dynMetric = ref('lp');
const dynTitle = ref('Личные продажи');

function openDynamics(cfg) {
  dynMetric.value = cfg?.metric || 'lp';
  dynTitle.value = cfg?.title || 'Личные продажи';
  showDynamics.value = true;
  loadDynamics();
}

async function loadDynamics() {
  dynLoading.value = true;
  try {
    const { data: res } = await api.get('/dashboard/dynamics', {
      params: {
        scope: dynScope.value,
        metric: dynMetric.value,
        period: dynScope.value === 'year' ? dynYear.value : dynMonth.value,
      },
    });
    dynSeries.value = res.series || [];
    dynTotals.value = res.totals || { amountRub: 0, points: 0, deals: 0 };
  } catch {
    dynSeries.value = [];
    dynTotals.value = { amountRub: 0, points: 0, deals: 0 };
  }
  dynLoading.value = false;
}

// Подписи оси: за год — месяцы словами, за месяц — только число дня,
// иначе тридцать полных дат не помещаются и ось становится нечитаемой.
const MONTHS_SHORT = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
function dynLabel(label) {
  return dynScope.value === 'year'
    ? MONTHS_SHORT[Number(label.slice(5, 7)) - 1] || label
    : String(Number(label.slice(8, 10)));
}

const dynChart = computed(() => {
  const s = dynSeries.value;
  // Ось без единой продажи — это не график, а прямая по нулю: показываем текст.
  if (!s.length || !s.some((p) => p.amountRub > 0 || p.points > 0)) return null;

  return {
    data: {
      labels: s.map((p) => dynLabel(p.label)),
      datasets: [
        {
          label: 'Поступило, ₽',
          data: s.map((p) => p.amountRub),
          borderColor: 'rgb(76, 175, 80)',
          backgroundColor: 'rgba(76, 175, 80, 0.15)',
          fill: true,
          tension: 0.3,
          yAxisID: 'y',
        },
        {
          label: 'ЛП, баллы',
          data: s.map((p) => p.points),
          borderColor: 'rgb(255, 152, 0)',
          backgroundColor: 'rgba(255, 152, 0, 0.1)',
          fill: false,
          tension: 0.3,
          yAxisID: 'yPoints',
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'bottom' },
        tooltip: {
          callbacks: {
            // Деньги и баллы в одной подсказке легко перепутать — подписываем.
            label: (ctx) => ctx.datasetIndex === 0
              ? `Поступило: ${fmtMoney(ctx.parsed.y)}`
              : `ЛП: ${fmt(ctx.parsed.y)}`,
          },
        },
      },
      scales: {
        // Две шкалы: рубли и баллы отличаются на порядки, на одной оси
        // линия баллов легла бы в пол.
        y: {
          position: 'left',
          beginAtZero: true,
          ticks: { callback: (v) => (Number(v) || 0).toLocaleString('ru-RU') },
        },
        yPoints: {
          position: 'right',
          beginAtZero: true,
          grid: { drawOnChartArea: false },
        },
      },
    },
  };
});

const loading = ref(true);
const period = ref(new Date().toISOString().slice(0, 7));
const showLevels = ref(false);
const levels = ref([]);

const empty = {
  consultant: { id: 0, personName: '—', statusName: 'Партнёр', participantCode: null, active: false, ambassadorProducts: null, activityName: null },
  qualification: { nominalLevel: null, nextLevel: null },
  volumes: { personalVolume: 0, groupVolume: 0, groupVolumeCumulative: 0, prevPersonalVolume: 0, prevGroupVolume: 0, prevGroupVolumeCumulative: 0, firstLineVolume: 0, firstLineVolumeRub: 0, prevFirstLineVolume: 0, pending: null },
  team: { myClients: 0, teamClients: 0, firstLineAll: 0, firstLineActive: 0, totalPartners: 0, totalPartnersActive: 0, capitalUsd: 0 },
  statusInfo: null,
  partners: { total: 0, registered: 0, active: 0, terminated: 0 },
  prevPartners: { total: 0, registered: 0, active: 0, terminated: 0 },
  breakaway: null,
  breakawayRules: null,
  mandatoryPlan: null,
  poolInfo: null,
};
const data = ref({ ...empty });

function pct(cur, prev) {
  if (!prev && !cur) return { value: '0%', type: 'neutral' };
  if (!prev) return { value: '+100%', type: 'up' };
  const p = ((cur - prev) / prev) * 100;
  return { value: `${p >= 0 ? '+' : ''}${p.toFixed(1)}%`, type: p >= 0 ? 'up' : 'down' };
}

const statusProgress = computed(() => {
  const si = data.value.statusInfo;
  if (!si || !si.requiredPoints) return 0;
  return Math.min((si.currentPoints / si.requiredPoints) * 100, 100);
});

// Последний месяц перед терминацией: тот же порог 30 дней, что и у рассылки
// partners:notify-termination-soon — чтобы письмо и баннер не расходились.
const deadlineSoon = computed(() => {
  const d = data.value.statusInfo?.daysRemaining;
  return d != null && d <= 30;
});

// Какой это срок. yearPeriodEnd приходит только для активного партнёра —
// у него идёт годовой период удержания, а не активация.
const isYearPeriod = computed(() => !!data.value.statusInfo?.yearPeriodEnd);

// Дата срока: у «Зарегистрирован» — окно активации, у «Активен» — годовой период.
const deadlineDate = computed(() => {
  const si = data.value.statusInfo || {};
  const raw = si.activationDeadline || si.yearPeriodEnd;
  if (!raw) return '';
  const d = new Date(raw);
  return isNaN(d.getTime()) ? '' : d.toLocaleDateString('ru-RU');
});

function plural(n, one, few, many) {
  if (n % 10 === 1 && n % 100 !== 11) return one;
  if ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100)) return few;
  return many;
}

/**
 * Per spec ✅Дашборд.md §2 + ✅Квалификации.md §2:
 * «Единая квалификация — у партнёра ОДИН уровень в месяц».
 * Раньше показывались nominal и calculation отдельно;
 * теперь берём максимум из двух (выше всегда уровень с большим level).
 */
const currentLevel = computed(() => {
  const q = data.value.qualification || {};
  const n = q.nominalLevel;
  const c = q.calculationLevel;
  if (!n && !c) return null;
  if (!n) return c;
  if (!c) return n;
  return (n.level || 0) >= (c.level || 0) ? n : c;
});

// Per spec ✅Дашборд §3: остаются ТОЛЬКО ЛП и НГП (ГП — обязательный плановый
// показатель внутри расчёта, на дашборде партнёра не выводится).
// Продажи месяца, ещё не попавшие в снимок. Снимок обновляется кнопкой
// пересчёта, поэтому между нажатиями партнёр не видел своих свежих сделок.
// Показываем их отдельно как прогноз — цифра снимка (по которой считаются
// деньги) не подменяется. null, когда снимок актуален.
const pending = computed(() => data.value.volumes?.pending || null);

const volumeCards = computed(() => {
  const v = data.value.volumes;
  const lp = pct(v.personalVolume, v.prevPersonalVolume);
  const ngp = pct(v.groupVolumeCumulative, v.prevGroupVolumeCumulative);
  const fl = pct(v.firstLineVolume, v.prevFirstLineVolume);
  const p = v.pending;
  // Каждая карточка кликабельна — открывает Финансовый отчёт за тот же
  // период с подсветкой соответствующей метрики (frontend читает `metric`).
  return [
    { title: 'Личные продажи (ЛП)', value: v.personalVolume, change: lp.value, changeType: lp.type, icon: 'mdi-bank', color: 'green', tone: 'info', lucide: 'bank',
      hint: glossary.lp,
      pending: p?.personalVolume || 0, projected: p?.projectedPersonalVolume || 0,
      // У каждой карточки своя метрика: график считает по контрактам того
      // круга, о котором карточка. Один общий график был бы неверным —
      // под НГП показывались бы личные продажи партнёра.
      dynamics: { metric: 'lp', title: 'Личные продажи' },
      link: { path: '/finance/report', query: { month: period.value, metric: 'lp' } } },
    { title: 'НГП', value: v.groupVolumeCumulative, change: ngp.value, changeType: ngp.type, icon: 'mdi-trending-up', color: 'orange', tone: 'accent', lucide: 'trend',
      hint: glossary.ngp,
      pending: p?.groupVolume || 0, projected: p?.projectedGroupVolumeCumulative || 0,
      dynamics: { metric: 'team', title: 'Продажи команды' },
      link: { path: '/finance/report', query: { month: period.value, metric: 'ngp' } } },
    // Объём продаж первой линии: баллы (основное значение) + деньги (подпись).
    { title: 'Объём 1 линии', value: v.firstLineVolume, subValue: fmtMoney(v.firstLineVolumeRub),
      change: fl.value, changeType: fl.type, icon: 'mdi-account-arrow-right', color: 'blue', tone: '', lucide: 'users',
      hint: glossary.firstLineVolume,
      dynamics: { metric: 'first_line', title: 'Объём первой линии' },
      link: { path: '/structure', query: { line: '1' } } },
  ];
});

// KPI «Команда» — компактные карточки с orb-иконками и цифрой.
// Каждая → /structure с предзаполненным фильтром (line=1 / status=active).
const teamKpis = computed(() => {
  const t = data.value.team || {};
  return [
    { label: 'Партнёры 1 линии',  value: t.firstLineAll ?? 0,         icon: 'mdi-account-outline',         color: 'info',
      hint: glossary.firstLinePartners,
      link: { path: '/structure', query: { line: '1' } } },
    { label: 'Всего партнёров',   value: t.totalPartners ?? 0,        icon: 'mdi-account-group',           color: 'primary',
      hint: glossary.totalPartners,
      link: { path: '/structure' } },
    { label: 'Активных 1 линии',  value: t.firstLineActive ?? 0,      icon: 'mdi-account-check',           color: 'success',
      hint: glossary.activePartners,
      link: { path: '/structure', query: { line: '1', status: 'active' } } },
    { label: 'Всего активных',    value: t.totalPartnersActive ?? 0,  icon: 'mdi-account-multiple-check',  color: 'success',
      hint: glossary.activePartners,
      link: { path: '/structure', query: { status: 'active' } } },
  ];
});

const partnerCards = computed(() => {
  const p = data.value.partners || {};
  const pp = data.value.prevPartners || {};
  return [
    { label: 'Всего партнёров', value: p.total ?? 0, color: 'primary', diff: (p.total ?? 0) - (pp.total ?? 0),
      link: { path: '/structure' } },
    { label: 'Зарегистрировано', value: p.registered ?? 0, color: 'info', diff: (p.registered ?? 0) - (pp.registered ?? 0),
      link: { path: '/structure', query: { status: 'registered' } } },
    { label: 'Активных', value: p.active ?? 0, color: 'success',
      // Real Registered→Activated count for the period (by dateActivity),
      // not a diff of the live activity snapshot which lost transitions.
      diff: data.value.activatedInPeriod ?? 0,
      link: { path: '/structure', query: { status: 'active' } } },
    { label: 'Терминированных', value: p.terminated ?? 0, color: 'error', diff: (p.terminated ?? 0) - (pp.terminated ?? 0),
      link: { path: '/structure', query: { status: 'terminated' } } },
  ];
});

const nqpProgress = computed(() => {
  const target = data.value.qualification.nextLevel?.groupVolumeCumulative || 1;
  return Math.min((data.value.volumes.groupVolumeCumulative / target) * 100, 100);
});

async function loadData() {
  loading.value = true;
  try {
    const { data: d } = await api.get('/dashboard', { params: { month: period.value } });
    data.value = { ...empty, ...d };
  } catch {
    data.value = { ...empty };
  }
  loading.value = false;
}

onMounted(async () => {
  loadData();
  try {
    const { data: d } = await api.get('/status-levels');
    levels.value = d;
  } catch {}
});
</script>

<style scoped>
/* Дашборд партнёра, версия 2 (эталон — ds-static/partner.html).
   Цвета только токенами: хардкод-hex ломает тёмную тему. */
.dash {
  display: flex;
  flex-direction: column;
  gap: var(--space-6);
  max-width: none;
  font-family: var(--font-sans);
  color: var(--ink);
}

.dash-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
}
.dash-head h1 { margin: 0; font: 700 28px/34px var(--font-sans); letter-spacing: -0.015em; }
.dash-head p { margin: 4px 0 0; font: 400 14px/22px var(--font-sans); color: var(--ink-muted); }

.sec {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
  font: 600 17px/24px var(--font-sans);
}
.sec svg { color: var(--brand); }

.card {
  padding: var(--space-5);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
  box-shadow: var(--shadow-card);
}
.card-h {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0 0 var(--space-4);
  font: 600 17px/24px var(--font-sans);
}
.card-h svg { color: var(--brand); }

.num { font-variant-numeric: tabular-nums; }

/* ── Верх: квалификация + активационный период ── */
.dash-top {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 360px;
  gap: var(--space-6);
  align-items: start;
}

.hero {
  position: relative;
  overflow: hidden;
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1.1fr);
  gap: var(--space-6);
  align-items: center;
  padding: var(--space-6);
  border-radius: var(--radius-xl);
  background: var(--brand-deep);
  color: var(--on-brand-deep);
}
:global([data-theme="dark"]) .hero { box-shadow: inset 0 0 0 1px var(--brand-deep-2); }

.hero-mesh {
  position: absolute;
  left: 0;
  bottom: 0;
  width: 60%;
  height: 70%;
  pointer-events: none;
  background-image:
    repeating-linear-gradient(to right, var(--brand-glow) 0 1px, transparent 1px 40px),
    repeating-linear-gradient(to bottom, var(--brand-glow) 0 1px, transparent 1px 40px);
  opacity: 0.1;
  mask-image: linear-gradient(to top right, #000, transparent 70%);
}

.hero-qual { position: relative; display: flex; align-items: center; gap: var(--space-5); }
/* Кольцо на тёмном: трек — панель hero, заливка и цифра — яркий зелёный. */
.hero-ring {
  --ring-track: var(--brand-deep-2);
  --ring-color: var(--brand-glow);
  --ring-label-color: var(--on-brand-deep);
}
.hero-qual-text { min-width: 0; }
.hero-eyebrow {
  font: 600 11px/14px var(--font-sans);
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--brand-glow);
}
.hero-title {
  margin-top: 6px;
  font: 700 30px/36px var(--font-sans);
  letter-spacing: -0.02em;
}
.hero-chips { display: flex; flex-wrap: wrap; gap: var(--space-2); margin-top: var(--space-3); }
.hero-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border-radius: var(--radius-pill);
  background: var(--brand-deep-2);
  color: var(--on-brand-deep-muted);
  font: 500 12px/16px var(--font-sans);
}
.hero-chip.on { color: var(--on-brand-deep); }
.hero-chip .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--brand-glow); }

.hero-panel {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  padding: var(--space-5);
  border-radius: var(--radius-lg);
  background: var(--brand-deep-2);
}
.hp-row { display: flex; align-items: baseline; justify-content: space-between; gap: var(--space-3); }
.hp-label { font: 400 13px/20px var(--font-sans); color: var(--on-brand-deep-muted); }
.hp-value { font: 400 13px/20px var(--font-sans); color: var(--on-brand-deep-muted); font-variant-numeric: tabular-nums; }
.hp-value b { font: 700 18px/24px var(--font-sans); color: var(--on-brand-deep); }
.hp-bar { height: 8px; border-radius: var(--radius-pill); background: var(--brand-deep); overflow: hidden; }
.hp-bar span { display: block; height: 100%; border-radius: inherit; background: var(--brand-glow); }
.hp-bar span.warn { background: var(--accent-glow); }
.hp-scale span { font: 400 12px/16px var(--font-sans); color: var(--on-brand-deep-muted); }
.hp-note { margin: 0; font: 400 13px/20px var(--font-sans); color: var(--on-brand-deep-muted); }
.hp-note b { color: var(--on-brand-deep); }
.hp-note--max { color: var(--brand-glow); }
.hp-pending {
  margin: 0;
  font: 400 12px/18px var(--font-sans);
  color: var(--on-brand-deep-muted);
}
.hp-pending b { color: var(--on-brand-deep); }
.hp-mandatory { display: flex; flex-direction: column; gap: var(--space-2); }

.hp-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  height: 40px;
  margin-top: var(--space-2);
  border: 0;
  border-radius: var(--radius-md);
  background: var(--on-brand-deep);
  color: var(--brand-deep);
  font: 600 13px/18px var(--font-sans);
  cursor: pointer;
}
.hp-btn:hover { background: #fff; }
.hp-btn:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }

/* ── Активационный период ── */
.activation { display: flex; flex-direction: column; gap: var(--space-4); }
.activation--danger { border-color: var(--danger); }
.act-head { display: flex; align-items: flex-start; gap: var(--space-3); }
.act-ic {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  flex: 0 0 auto;
  border-radius: var(--radius-sm);
  background: var(--surface-2);
  color: var(--brand);
}
.activation--danger .act-ic { background: var(--accent-soft); color: var(--danger); }
.act-title { flex: 1 1 auto; min-width: 0; }
.act-title b { display: block; font: 600 15px/22px var(--font-sans); }
.act-title span { font: 400 13px/20px var(--font-sans); color: var(--ink-muted); }
.act-close {
  border: 0;
  background: transparent;
  color: var(--ink-muted);
  cursor: pointer;
  border-radius: var(--radius-sm);
  padding: 4px;
}
.act-close:hover { background: var(--surface-2); }
.act-close:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }

.act-body { display: flex; align-items: center; gap: var(--space-5); }
.act-ring { --ring-color: var(--info); }
.activation--danger .act-ring { --ring-color: var(--danger); }
.act-facts { display: flex; flex-direction: column; gap: var(--space-3); }
.act-cap { display: block; font: 400 12px/16px var(--font-sans); color: var(--ink-muted); }
.act-num { font: 400 13px/20px var(--font-sans); color: var(--ink-muted); font-variant-numeric: tabular-nums; }
.act-num b { font: 700 22px/28px var(--font-sans); color: var(--ink); letter-spacing: -0.02em; }

/* ── Объёмы ── */
.vol-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--space-5); }
.vol {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  padding: var(--space-5);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
  box-shadow: var(--shadow-card);
  text-decoration: none;
  color: inherit;
}
.vol:hover { box-shadow: var(--shadow-hover); }
.vol:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
.vol-head { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-3); }
.vol-title { font: 400 13px/20px var(--font-sans); color: var(--ink-muted); }
.vol-ic {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  flex: 0 0 auto;
  border: 0;
  border-radius: var(--radius-sm);
  background: var(--surface-2);
  color: var(--brand);
  cursor: pointer;
}
.vol-ic--static { cursor: default; }
.vol-ic:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
.vol-value {
  font: 700 32px/38px var(--font-sans);
  letter-spacing: -0.02em;
  font-variant-numeric: tabular-nums;
}
.vol-value.info { color: var(--info); }
.vol-value.accent { color: var(--accent); }
.vol-sub { font: 400 13px/20px var(--font-sans); color: var(--ink-muted); font-variant-numeric: tabular-nums; }
.vol-pending { font: 400 12px/18px var(--font-sans); color: var(--info); }
.vol-delta {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: auto;
  padding-top: var(--space-2);
  font: 500 12px/16px var(--font-sans);
  color: var(--ink-muted);
}
.vol-delta span { font-weight: 400; }
.vol-delta.up { color: var(--brand); }
.vol-delta.down { color: var(--danger); }

/* ── Отрыв ── */
.bw-head { display: flex; align-items: center; gap: var(--space-3); flex-wrap: wrap; }
.bw-head .card-h { margin: 0; }
.bw-hint { margin-left: auto; }
.bw-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border-radius: var(--radius-pill);
  font: 500 12px/16px var(--font-sans);
}
.bw-chip.ok { background: var(--brand-soft); color: var(--brand); }
.bw-chip.warn { background: var(--accent-soft); color: var(--accent); }
.bw-chip.bad { background: var(--accent-soft); color: var(--danger); }

.bw-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: var(--space-3);
  margin-top: var(--space-4);
}
.bw-tile {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: var(--space-4);
  border-radius: var(--radius-md);
  background: var(--surface-2);
}
.bw-cap { font: 400 12px/16px var(--font-sans); color: var(--ink-muted); }
.bw-val { font: 600 15px/22px var(--font-sans); }
.bw-val.ok { color: var(--brand); }
.bw-val.warn { color: var(--accent); }
.bw-val.bad { color: var(--danger); }

.bw-scale { margin-top: var(--space-5); }
.bw-track {
  position: relative;
  display: flex;
  height: 8px;
  border-radius: var(--radius-pill);
  overflow: visible;
}
.bw-zone { height: 100%; }
.bw-zone--ok { width: 70%; background: var(--brand-soft); border-radius: var(--radius-pill) 0 0 var(--radius-pill); }
.bw-zone--warn { width: 20%; background: var(--accent-soft); }
.bw-zone--bad { width: 10%; background: var(--danger); opacity: 0.35; border-radius: 0 var(--radius-pill) var(--radius-pill) 0; }
.bw-marker {
  position: absolute;
  top: -22px;
  transform: translateX(-50%);
  padding: 2px 8px;
  border-radius: var(--radius-pill);
  font: 600 11px/16px var(--font-sans);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.bw-marker.ok { background: var(--brand); color: var(--on-brand); }
.bw-marker.warn { background: var(--accent); color: #fff; }
.bw-marker.bad { background: var(--danger); color: var(--on-danger); }
.bw-legend { display: flex; justify-content: space-between; margin-top: var(--space-3); }
.bw-legend span { display: flex; flex-direction: column; font: 400 12px/16px var(--font-sans); color: var(--ink-muted); }
.bw-legend span:nth-child(2) { text-align: center; }
.bw-legend span:last-child { text-align: right; }
.bw-legend b { font-weight: 400; }

/* ── Команда и клиенты ── */
.two-col { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-6); align-items: start; }

.team-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-3); }
.team-tile {
  display: grid;
  grid-template-columns: 32px minmax(0, 1fr) auto;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border-radius: var(--radius-md);
  background: var(--surface-2);
  text-decoration: none;
  color: inherit;
}
.team-tile:hover { background: var(--brand-soft); }
.team-tile:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
.team-ic {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: var(--radius-sm);
  background: var(--surface);
  color: var(--brand);
}
.team-label { font: 400 13px/18px var(--font-sans); color: var(--ink-muted); }
.team-value { font: 700 20px/26px var(--font-sans); letter-spacing: -0.02em; }

.cl-row {
  display: grid;
  grid-template-columns: 32px minmax(0, 1fr) auto auto;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-4);
  border-radius: var(--radius-md);
  background: var(--surface-2);
  text-decoration: none;
  color: inherit;
}
.cl-row + .cl-row { margin-top: var(--space-3); }
.cl-row:hover { background: var(--brand-soft); }
.cl-row:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
.cl-row svg:last-child { color: var(--ink-muted); }
.cl-ic {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: var(--radius-sm);
  background: var(--surface);
  color: var(--brand);
}
.cl-label { font: 400 13px/18px var(--font-sans); color: var(--ink-muted); }
.cl-value { font: 700 22px/28px var(--font-sans); letter-spacing: -0.02em; }

/* ── Партнёры по статусу ── */
.pt-body { display: grid; grid-template-columns: 200px minmax(0, 1fr); gap: var(--space-6); align-items: center; }
.pt-total {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding-right: var(--space-5);
  border-right: 1px solid var(--border);
  text-decoration: none;
  color: inherit;
}
.pt-cap { font: 400 12px/16px var(--font-sans); color: var(--ink-muted); }
.pt-num { font: 700 32px/38px var(--font-sans); letter-spacing: -0.02em; }
.pt-delta {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font: 500 12px/16px var(--font-sans);
  color: var(--ink-muted);
}
.pt-delta span { font-weight: 400; }
.pt-delta.up { color: var(--brand); }
.pt-delta.down { color: var(--danger); }

.pt-bar { display: flex; height: 10px; border-radius: var(--radius-pill); overflow: hidden; background: var(--surface-2); }
.pt-seg.info { background: var(--info); }
.pt-seg.ok { background: var(--brand); }
.pt-seg.bad { background: var(--danger); }
.pt-seg.brand { background: var(--brand); }

.pt-legend { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--space-4); margin-top: var(--space-4); }
.pt-item {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  gap: var(--space-2);
  text-decoration: none;
  color: inherit;
}
.pt-item:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
.pt-dot { width: 8px; height: 8px; border-radius: 50%; }
.pt-dot.info { background: var(--info); }
.pt-dot.ok { background: var(--brand); }
.pt-dot.bad { background: var(--danger); }
.pt-dot.brand { background: var(--brand); }
.pt-label { font: 400 13px/18px var(--font-sans); color: var(--ink-muted); }
.pt-value { grid-column: 2; font: 700 22px/28px var(--font-sans); letter-spacing: -0.02em; }
.pt-item .pt-delta { grid-column: 2; }

/* ── Адаптив по правилам ds-redesign/design/BRAND.md ── */
@media (max-width: 1320px) {
  .dash-top { grid-template-columns: minmax(0, 1fr); }
  .vol-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 1180px) {
  .hero { grid-template-columns: minmax(0, 1fr); }
  .two-col { grid-template-columns: minmax(0, 1fr); }
  .bw-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 860px) {
  .dash { gap: var(--space-4); }
  .vol-grid { grid-template-columns: minmax(0, 1fr); gap: var(--space-4); }
  .two-col { gap: var(--space-4); }
  .pt-body { grid-template-columns: minmax(0, 1fr); gap: var(--space-4); }
  .pt-total { padding-right: 0; padding-bottom: var(--space-4); border-right: 0; border-bottom: 1px solid var(--border); }
  .pt-legend { grid-template-columns: minmax(0, 1fr); gap: var(--space-3); }
  .bw-legend { font-size: 11px; }
  .hero-title { font-size: 24px; line-height: 30px; }
}
@media (max-width: 560px) {
  .team-grid { grid-template-columns: minmax(0, 1fr); }
  .bw-grid { grid-template-columns: minmax(0, 1fr); }
  .act-body { flex-direction: column; align-items: flex-start; }
}
</style>
