<template>
  <div>
    <PageHeader title="Дашборд партнёра" icon="mdi-view-dashboard">
      <template #actions>
        <MonthPicker v-model="period" @update:model-value="loadData" />
      </template>
    </PageHeader>

    <!-- Обратный отсчёт до терминации.
         ⚠ На последнем месяце текст МЕНЯЕТСЯ и баннер перестаёт закрываться:
         раньше он всегда говорил «активационный период», но не говорил, ЧТО
         будет, если его не пройти — партнёр видел счётчик и не понимал, что на
         кону расторжение договора. -->
    <v-alert v-if="data.statusInfo && data.statusInfo.daysRemaining != null"
      :type="deadlineSoon ? 'error' : 'info'"
      variant="tonal" class="mb-4" :closable="!deadlineSoon">
      <div class="d-flex justify-space-between align-center flex-wrap ga-2">
        <div v-if="deadlineSoon">
          <div class="font-weight-bold">
            До терминации {{ data.statusInfo.daysRemaining }}
            {{ plural(data.statusInfo.daysRemaining, 'день', 'дня', 'дней') }}
          </div>
          <div class="text-body-2">
            Если к {{ deadlineDate }} не набрать
            <strong>{{ fmt(data.statusInfo.requiredPoints) }}</strong> ЛП, агентский договор
            будет расторгнут: баллы обнулятся, клиенты и контракты перейдут наставнику.
            <template v-if="data.statusInfo.reinstate?.limit">
              Восстановить участие можно будет самостоятельно — доступно
              {{ data.statusInfo.reinstate.limit }}
              {{ plural(data.statusInfo.reinstate.limit, 'попытка', 'попытки', 'попыток') }}.
            </template>
          </div>
          <div class="text-body-2 mt-1">
            Сейчас у вас <strong>{{ fmt(data.statusInfo.currentPoints) }}</strong> из
            <strong>{{ fmt(data.statusInfo.requiredPoints) }}</strong> ЛП.
          </div>
        </div>
        <div v-else>
          <div class="font-weight-bold">Активационный период</div>
          <div class="text-body-2">
            Осталось <strong>{{ data.statusInfo.daysRemaining }}</strong> дней.
            Требуется набрать <strong>{{ fmt(data.statusInfo.requiredPoints) }}</strong> баллов.
            Текущий прогресс: <strong>{{ fmt(data.statusInfo.currentPoints) }}</strong> баллов.
          </div>
        </div>
      </div>
      <v-progress-linear :model-value="statusProgress" height="8" rounded
        :color="deadlineSoon ? 'error' : 'primary'" class="mt-2" />
    </v-alert>

    <!-- Hero квалификации — primary-tinted, выделяется среди остальных
         блоков как самый важный. Большая «10 [Кофаундер]» цифра. -->
    <v-card class="dashboard-hero mb-4" elevation="0">
      <div class="quals-hero-content pa-5">
        <div class="d-flex justify-space-between align-start mb-4 flex-wrap ga-3">
          <div>
            <div class="d-flex align-center ga-1 text-caption text-uppercase quals-eyebrow">
              <span>Текущая квалификация</span>
              <InfoHint :text="glossary.qualification" />
            </div>
            <div class="hero-qual-row mt-2">
              <div class="hero-qual-badge">
                {{ currentLevel?.level ?? '—' }}
              </div>
              <div class="hero-qual-meta">
                <div class="hero-qual-title">{{ currentLevel?.title ?? 'Start' }}</div>
                <div class="d-flex align-center ga-2 mt-1">
                  <v-chip v-if="data.consultant.activityName" size="x-small"
                    :color="data.consultant.active ? 'success' : 'grey'" variant="tonal">
                    {{ data.consultant.activityName }}
                  </v-chip>
                  <span class="text-caption text-medium-emphasis">
                    Комиссия <strong>{{ currentLevel?.percent ?? 15 }}%</strong>
                  </span>
                </div>
              </div>
            </div>
          </div>
          <v-btn variant="tonal" color="primary" prepend-icon="mdi-table" @click="showLevels = true">
            Условия квалификаций
          </v-btn>
        </div>

      <!-- НГП progress bar -->
      <div class="mb-3">
        <div class="d-flex justify-space-between align-center mb-1">
          <span class="d-flex align-center ga-1 text-body-2 text-medium-emphasis">
            НГП <InfoHint :text="glossary.ngp" :size="14" />
          </span>
          <span class="text-body-2 font-weight-medium">
            {{ fmt(data.volumes.groupVolumeCumulative) }}
            <template v-if="data.qualification.nextLevel"> / {{ fmt(data.qualification.nextLevel.groupVolumeCumulative) }}</template>
          </span>
        </div>
        <v-progress-linear :model-value="nqpProgress" height="10" rounded color="primary" />
        <!-- Снимок обновляется кнопкой пересчёта. Пока он не пересобран,
             свежие продажи в шкалу не попадают — говорим об этом прямо,
             иначе партнёр считает, что сделку потеряли. -->
        <div v-if="pending" class="d-flex align-center ga-1 mt-1 text-caption pending-hint">
          <v-icon size="13" color="info">mdi-clock-outline</v-icon>
          <span>
            +{{ fmt(pending.groupVolume) }} в этом месяце ещё не в снимке →
            <strong>{{ fmt(pending.projectedGroupVolumeCumulative) }}</strong> после закрытия месяца<template v-if="pending.snapshotAt">, снимок от {{ pending.snapshotAt }}</template>
          </span>
        </div>
      </div>

      <!-- Per spec ✅Дашборд.md §2: «Логика разделения на закрытую/расчётную упразднена.
           Отображается только Текущая квалификация». Комиссия переехала в hero-meta. -->

      <!-- ОП по ГП progress bar (per spec — отдельный ГП не показываем) -->
      <div v-if="data.mandatoryPlan" class="mb-3">
        <div class="d-flex justify-space-between align-center mb-1">
          <span class="d-flex align-center ga-1 text-body-2 text-medium-emphasis">
            ОП по ГП <InfoHint :text="glossary.mandatoryGp" :size="14" />
          </span>
          <span class="text-body-2 font-weight-medium">
            {{ fmt(data.mandatoryPlan.currentGP) }} / {{ fmt(data.mandatoryPlan.mandatoryGP) }}
          </span>
        </div>
        <v-progress-linear :model-value="data.mandatoryPlan.fulfillment" height="10" rounded
          :color="data.mandatoryPlan.fulfilled ? 'success' : data.mandatoryPlan.fulfillment >= 80 ? 'warning' : 'error'" />
      </div>

      <!-- Next level info -->
      <div v-if="data.qualification.nextLevel" class="mt-3">
        <v-divider class="mb-3" />
        <div class="text-caption text-medium-emphasis">
          До <strong>{{ data.qualification.nextLevel.title }}</strong>: осталось
          <strong>{{ fmt(Math.max(0, (data.qualification.nextLevel.groupVolumeCumulative || 0) - data.volumes.groupVolumeCumulative)) }}</strong> баллов НГП
        </div>
      </div>
      <div v-else class="mt-3">
        <v-chip color="amber" variant="tonal" prepend-icon="mdi-crown" size="small">Максимальная квалификация</v-chip>
      </div>
      </div>
    </v-card>

    <!-- Volume cards — ЛП/ГП/НГП. Кликабельны → отчёт начислений с фильтром по периоду. -->
    <div class="section-eyebrow">Объёмы</div>
    <v-row class="mb-5 dashboard-row">
      <v-col v-for="card in volumeCards" :key="card.title" cols="12" md="4">
        <router-link :to="card.link" class="text-decoration-none">
          <v-card class="ds-card ds-card--hover pa-5 h-100" elevation="0">
            <div class="d-flex justify-space-between align-start">
              <div class="flex-grow-1 min-w-0">
                <div class="d-flex align-center ga-1 text-caption text-uppercase text-medium-emphasis font-weight-bold letter-spacing-1">
                  <span>{{ card.title }}</span>
                  <InfoHint v-if="card.hint" :text="card.hint" @click.prevent.stop />
                </div>
                <div class="text-h3 font-weight-bold mt-2 mb-1 tabular-nums">{{ fmt(card.value) }}</div>
                <div v-if="card.subValue" class="text-body-2 font-weight-medium text-medium-emphasis mb-1 tabular-nums">
                  {{ card.subValue }}
                </div>
                <!-- Продажи, ещё не вошедшие в снимок. Основную цифру не
                     подменяем — она остаётся расчётной. -->
                <div v-if="card.pending > 0" class="d-flex align-center ga-1 mb-1 text-caption tabular-nums pending-hint">
                  <v-icon size="13" color="info">mdi-clock-outline</v-icon>
                  <span>+{{ fmt(card.pending) }} → {{ fmt(card.projected) }} после закрытия месяца</span>
                </div>
                <div class="d-flex align-center ga-1">
                  <v-icon :color="card.changeType === 'up' ? 'success' : card.changeType === 'down' ? 'error' : 'grey'" size="14">
                    {{ card.changeType === 'up' ? 'mdi-trending-up' : card.changeType === 'down' ? 'mdi-trending-down' : 'mdi-minus' }}
                  </v-icon>
                  <span class="text-caption" :class="card.changeType === 'up' ? 'text-success' : card.changeType === 'down' ? 'text-error' : 'text-medium-emphasis'">
                    {{ card.change }} к прошлому
                  </span>
                </div>
              </div>
              <!-- Иконка — отдельная точка входа: карточка целиком ведёт в
                   финотчёт за период, а орб открывает динамику по времени
                   (за месяц / за год). Клик по орбу гасим, иначе сработает
                   router-link родителя. -->
              <div class="kpi-icon-orb" :class="{ 'kpi-icon-orb--clickable': card.dynamics }"
                :style="{ background: `rgba(var(--v-theme-${card.color}), 0.12)` }"
                :title="card.dynamics ? 'Динамика по времени' : null"
                @click.prevent.stop="card.dynamics && openDynamics()">
                <v-icon size="22" :color="card.color">{{ card.icon }}</v-icon>
              </div>
            </div>
          </v-card>
        </router-link>
      </v-col>
    </v-row>

    <!-- Отрыв (breakaway) — те же пороги 70/90, что в финрезе.
         Цвет статуса видим только в чипе/прогрессе/доле — карточка
         остаётся на surface, чтобы text-medium-emphasis читался. -->
    <v-card v-if="data.breakaway" class="mb-4 pa-4 breakaway-card"
      :class="`breakaway-card--${
        data.breakaway.poolBlocked ? 'error'
        : data.breakaway.gpHeld ? 'warning'
        : 'success'
      }`">
      <div class="d-flex align-center ga-2 mb-3">
        <v-chip size="small" variant="flat"
          :color="data.breakaway.poolBlocked ? 'error'
                : data.breakaway.gpHeld ? 'warning'
                : 'success'"
          :prepend-icon="data.breakaway.poolBlocked ? 'mdi-alert-decagram'
                       : data.breakaway.gpHeld ? 'mdi-alert-circle-outline'
                       : 'mdi-check-decagram'">
          {{ data.breakaway.poolBlocked ? 'Отрыв ≥ 90% — пул не выплачивается'
           : data.breakaway.gpHeld ? 'Отрыв ≥ 70% — ветка не учитывается в ГП'
           : 'Отрыва нет' }}
        </v-chip>
        <InfoHint :text="glossary.breakaway" />
      </div>
      <v-row>
        <v-col cols="6" md="3">
          <div class="text-body-2 text-medium-emphasis">Топ ветка</div>
          <div class="font-weight-medium">{{ data.breakaway.partnerName || '—' }}</div>
        </v-col>
        <v-col cols="6" md="3">
          <div class="text-body-2 text-medium-emphasis">ГП ветки</div>
          <div class="font-weight-medium">{{ fmt(data.breakaway.groupVolume) }}</div>
        </v-col>
        <v-col cols="6" md="3">
          <div class="text-body-2 text-medium-emphasis">Доля от моего ГП</div>
          <div class="font-weight-bold"
            :class="data.breakaway.poolBlocked ? 'text-error'
                  : data.breakaway.gpHeld ? 'text-warning'
                  : 'text-success'">
            {{ data.breakaway.gapPercentage ?? 0 }}%
          </div>
        </v-col>
        <v-col cols="6" md="3">
          <div class="text-body-2 text-medium-emphasis">Превышение</div>
          <div class="font-weight-medium">{{ fmt(data.breakaway.gapValue) }}</div>
        </v-col>
      </v-row>
      <!-- Шкала с порогами 70% / 90% -->
      <div class="mt-3">
        <v-progress-linear
          :model-value="Math.min(data.breakaway.gapPercentage || 0, 100)"
          height="8" rounded
          :color="data.breakaway.poolBlocked ? 'error'
                : data.breakaway.gpHeld ? 'warning'
                : 'success'" />
        <div class="d-flex justify-space-between text-caption text-medium-emphasis mt-1">
          <span>0%</span>
          <span>70% — удержание ГП</span>
          <span>90% — блокировка пула</span>
          <span>100%</span>
        </div>
      </div>
    </v-card>

    <!-- Команда — показатели партнёров: 1 линия / всего, активные.
         Каждая карточка → /structure с предзаполненным фильтром. -->
    <div class="section-eyebrow">Команда</div>
    <v-row class="mb-5 dashboard-row">
      <v-col v-for="kpi in teamKpis" :key="kpi.label" cols="12" sm="6" md="3">
        <router-link :to="kpi.link" class="text-decoration-none">
          <v-card class="ds-card ds-card--hover pa-4" elevation="0">
            <div class="d-flex align-center ga-3">
              <div class="kpi-icon-orb" :style="{ background: `rgba(var(--v-theme-${kpi.color}), 0.12)` }">
                <v-icon size="20" :color="kpi.color">{{ kpi.icon }}</v-icon>
              </div>
              <div class="min-w-0 flex-grow-1">
                <div class="d-flex align-center ga-1 text-caption text-medium-emphasis">
                  <span>{{ kpi.label }}</span>
                  <InfoHint v-if="kpi.hint" :text="kpi.hint" :size="13" @click.prevent.stop />
                </div>
                <div class="text-h5 font-weight-bold tabular-nums">{{ kpi.value }}</div>
              </div>
            </div>
          </v-card>
        </router-link>
      </v-col>
    </v-row>

    <!-- Клиенты — две большие интерактивные карточки. Каждая → /clients
         с предзаполненным scope (team / mine) в query. -->
    <div class="section-eyebrow">Клиенты</div>
    <v-row class="mb-5 dashboard-row">
      <v-col cols="12" sm="6">
        <router-link to="/clients?scope=team" class="text-decoration-none">
          <v-card class="ds-card ds-card--hover pa-5" elevation="0">
            <div class="d-flex align-center ga-4">
              <div class="kpi-icon-orb kpi-icon-orb--lg" style="background: rgba(var(--v-theme-primary), 0.12)">
                <v-icon size="26" color="primary">mdi-account-multiple</v-icon>
              </div>
              <div class="flex-grow-1 min-w-0">
                <div class="text-caption text-medium-emphasis">Клиенты команды</div>
                <div class="text-h3 font-weight-bold text-primary tabular-nums">{{ data.team?.teamClients ?? 0 }}</div>
              </div>
              <v-icon size="22" color="primary">mdi-arrow-right</v-icon>
            </div>
          </v-card>
        </router-link>
      </v-col>
      <v-col cols="12" sm="6">
        <router-link to="/clients?scope=mine" class="text-decoration-none">
          <v-card class="ds-card ds-card--hover pa-5" elevation="0">
            <div class="d-flex align-center ga-4">
              <div class="kpi-icon-orb kpi-icon-orb--lg" style="background: rgba(var(--v-theme-secondary), 0.12)">
                <v-icon size="26" color="secondary">mdi-account</v-icon>
              </div>
              <div class="flex-grow-1 min-w-0">
                <div class="text-caption text-medium-emphasis">Мои клиенты</div>
                <div class="text-h3 font-weight-bold text-secondary tabular-nums">{{ data.team?.myClients ?? 0 }}</div>
              </div>
              <v-icon size="22" color="secondary">mdi-arrow-right</v-icon>
            </div>
          </v-card>
        </router-link>
      </v-col>
    </v-row>

    <!-- Партнёры по статусу — каждая → /structure с фильтром по статусу. -->
    <div class="section-eyebrow">Партнёры по статусу</div>
    <v-row class="mb-5 dashboard-row">
      <v-col v-for="card in partnerCards" :key="card.label" cols="12" sm="6" md="3">
        <router-link :to="card.link" class="text-decoration-none">
          <v-card class="ds-card ds-card--hover pa-4 text-center" elevation="0">
            <div class="text-caption text-uppercase text-medium-emphasis font-weight-bold letter-spacing-1">
              {{ card.label }}
            </div>
            <div class="text-h3 font-weight-bold my-2 tabular-nums" :class="`text-${card.color}`">{{ card.value }}</div>
            <div v-if="card.diff != null" class="d-flex align-center justify-center ga-1 mt-1">
              <v-icon :color="card.diff >= 0 ? 'success' : 'error'" size="14">
                {{ card.diff >= 0 ? 'mdi-trending-up' : 'mdi-trending-down' }}
              </v-icon>
              <span class="text-caption" :class="card.diff >= 0 ? 'text-success' : 'text-error'">
                {{ card.diff >= 0 ? '+' : '' }}{{ card.diff }} к прошлому
              </span>
            </div>
          </v-card>
        </router-link>
      </v-col>
    </v-row>

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
          <span>Динамика личных продаж</span>
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
import api from '../api';
import MonthPicker from '../components/MonthPicker.vue';
import PageHeader from '../components/PageHeader.vue';
import InfoHint from '../components/InfoHint.vue';
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

// ── Динамика личных продаж (диалог по клику на иконку карточки ЛП) ──
const showDynamics = ref(false);
const dynLoading = ref(false);
const dynScope = ref('year');
const dynYear = ref(String(new Date().getFullYear()));
const dynMonth = ref(new Date().toISOString().slice(0, 7));
const dynSeries = ref([]);
const dynTotals = ref({ amountRub: 0, points: 0, deals: 0 });

function openDynamics() {
  showDynamics.value = true;
  loadDynamics();
}

async function loadDynamics() {
  dynLoading.value = true;
  try {
    const { data: res } = await api.get('/dashboard/dynamics', {
      params: {
        scope: dynScope.value,
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
    { title: 'Личные продажи (ЛП)', value: v.personalVolume, change: lp.value, changeType: lp.type, icon: 'mdi-bank', color: 'green',
      hint: glossary.lp,
      pending: p?.personalVolume || 0, projected: p?.projectedPersonalVolume || 0,
      // Динамика есть только здесь: эндпоинт считает СВОИ продажи партнёра.
      // Вешать тот же график на НГП или объём первой линии нельзя — это
      // другие величины, и цифры под иконкой были бы неверными.
      dynamics: true,
      link: { path: '/finance/report', query: { month: period.value, metric: 'lp' } } },
    { title: 'НГП', value: v.groupVolumeCumulative, change: ngp.value, changeType: ngp.type, icon: 'mdi-trending-up', color: 'orange',
      hint: glossary.ngp,
      pending: p?.groupVolume || 0, projected: p?.projectedGroupVolumeCumulative || 0,
      link: { path: '/finance/report', query: { month: period.value, metric: 'ngp' } } },
    // Объём продаж первой линии: баллы (основное значение) + деньги (подпись).
    { title: 'Объём 1 линии', value: v.firstLineVolume, subValue: fmtMoney(v.firstLineVolumeRub),
      change: fl.value, changeType: fl.type, icon: 'mdi-account-arrow-right', color: 'blue',
      hint: glossary.firstLineVolume,
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
/* Breakaway-карточка: цветной индикатор слева, surface-фон сохраняем,
   чтобы text-medium-emphasis labels читались. См. одноимённый стиль
   в Finance/Report.vue — общий паттерн. */
.breakaway-card {
  border-left: 4px solid transparent !important;
}
.breakaway-card--success {
  border-left-color: rgb(var(--v-theme-success)) !important;
}
.breakaway-card--warning {
  border-left-color: rgb(var(--v-theme-warning)) !important;
}
.breakaway-card--error {
  border-left-color: rgb(var(--v-theme-error)) !important;
}

/* === Hero «Текущая квалификация» — выделяется среди обычных карточек
   primary-tinted gradient'ом. Это самый важный блок на странице. === */
.dashboard-hero {
  border-radius: 16px;
  background: linear-gradient(135deg,
    rgba(var(--v-theme-primary), 0.06) 0%,
    rgba(var(--v-theme-primary), 0.02) 100%);
  border: 1px solid rgba(var(--v-theme-primary), 0.15);
  box-shadow:
    0 1px 2px rgba(0, 0, 0, 0.04),
    0 8px 24px rgba(46, 125, 50, 0.06);
}
.quals-eyebrow {
  letter-spacing: 1.4px;
  color: rgb(var(--v-theme-primary));
  font-weight: 700;
  font-size: 11px;
}
.hero-qual-row {
  display: flex;
  align-items: center;
  gap: 16px;
}
/* Большая числовая «10» с круглым фоном — фокусная точка hero'а. */
.hero-qual-badge {
  width: 64px; height: 64px;
  border-radius: 50%;
  background: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
  display: flex; align-items: center; justify-content: center;
  font-size: 26px; font-weight: 700;
  letter-spacing: -0.5px;
  font-variant-numeric: tabular-nums;
  box-shadow: 0 4px 12px rgba(46, 125, 50, 0.25);
  flex-shrink: 0;
}
.hero-qual-title {
  font-size: 22px; font-weight: 700; line-height: 1.2;
  letter-spacing: -0.3px;
  color: rgb(var(--v-theme-on-surface));
}

/* === Section eyebrow — мини-заголовок над KPI-рядами === */
.section-eyebrow {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 1.4px;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), 0.5);
  margin-bottom: 10px;
}

/* === KPI icon orb — круглая «таблетка» с иконкой в primary-tint === */
/* Подпись «ещё не в снимке» — info-тон, но приглушённый: это прогноз,
   он не должен спорить с основной цифрой. */
.pending-hint {
  color: rgb(var(--v-theme-info));
  opacity: 0.85;
  line-height: 1.3;
}

.kpi-icon-orb {
  width: 44px; height: 44px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.kpi-icon-orb--lg { width: 56px; height: 56px; }

/* Орб с динамикой — вторая точка входа внутри кликабельной карточки.
   Без подсказки её не найти, поэтому даём курсор и заметный отклик. */
.kpi-icon-orb--clickable {
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.kpi-icon-orb--clickable:hover {
  transform: scale(1.08);
  box-shadow: 0 0 0 3px rgba(var(--v-theme-primary), 0.18);
}

.text-decoration-none { text-decoration: none; color: inherit; }
.min-w-0 { min-width: 0; }

/* DS tabular-nums на всех числовых значениях дашборда. */
:deep(.text-h3), :deep(.text-h4), :deep(.text-h5) {
  font-variant-numeric: tabular-nums;
}

/* === Stagger fade-up для рядов карточек: каждый ряд появляется
   с задержкой, KPI внутри ряда уже анимируются через .ds-card. === */
.dashboard-row > * {
  animation: fadeUp 0.5s cubic-bezier(0.2, 0.8, 0.2, 1) backwards;
}
.dashboard-row > *:nth-child(1) { animation-delay: 60ms; }
.dashboard-row > *:nth-child(2) { animation-delay: 120ms; }
.dashboard-row > *:nth-child(3) { animation-delay: 180ms; }
.dashboard-row > *:nth-child(4) { animation-delay: 240ms; }

@keyframes fadeUp {
  from { opacity: 0; transform: translateY(12px); }
  to   { opacity: 1; transform: translateY(0); }
}
</style>

