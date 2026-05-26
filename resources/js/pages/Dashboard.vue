<template>
  <div>
    <PageHeader title="Дашборд партнёра" icon="mdi-view-dashboard">
      <template #actions>
        <MonthPicker v-model="period" @update:model-value="loadData" />
      </template>
    </PageHeader>

    <!-- Status Info Alert (activation period countdown) -->
    <v-alert v-if="data.statusInfo && data.statusInfo.daysRemaining != null"
      :type="data.statusInfo.daysRemaining <= 30 ? 'warning' : 'info'"
      variant="tonal" class="mb-4" closable>
      <div class="d-flex justify-space-between align-center flex-wrap ga-2">
        <div>
          <div class="font-weight-bold">Активационный период</div>
          <div class="text-body-2">
            Осталось <strong>{{ data.statusInfo.daysRemaining }}</strong> дней.
            Требуется набрать <strong>{{ fmt(data.statusInfo.requiredPoints) }}</strong> баллов.
            Текущий прогресс: <strong>{{ fmt(data.statusInfo.currentPoints) }}</strong> баллов.
          </div>
        </div>
      </div>
      <v-progress-linear :model-value="statusProgress" height="8" rounded
        :color="data.statusInfo.daysRemaining <= 30 ? 'warning' : 'primary'" class="mt-2" />
    </v-alert>

    <!-- Hero квалификации — primary-tinted, выделяется среди остальных
         блоков как самый важный. Большая «10 [Кофаундер]» цифра. -->
    <v-card class="dashboard-hero mb-4" elevation="0">
      <div class="quals-hero-content pa-5">
        <div class="d-flex justify-space-between align-start mb-4 flex-wrap ga-3">
          <div>
            <div class="text-caption text-uppercase quals-eyebrow">
              Текущая квалификация
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
          <span class="text-body-2 text-medium-emphasis">НГП</span>
          <span class="text-body-2 font-weight-medium">
            {{ fmt(data.volumes.groupVolumeCumulative) }}
            <template v-if="data.qualification.nextLevel"> / {{ fmt(data.qualification.nextLevel.groupVolumeCumulative) }}</template>
          </span>
        </div>
        <v-progress-linear :model-value="nqpProgress" height="10" rounded color="primary" />
      </div>

      <!-- Per spec ✅Дашборд.md §2: «Логика разделения на закрытую/расчётную упразднена.
           Отображается только Текущая квалификация». Комиссия переехала в hero-meta. -->

      <!-- ОП по ГП progress bar (per spec — отдельный ГП не показываем) -->
      <div v-if="data.mandatoryPlan" class="mb-3">
        <div class="d-flex justify-space-between align-center mb-1">
          <span class="text-body-2 text-medium-emphasis">ОП по ГП</span>
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

    <!-- Volume cards — ЛП/ГП/НГП. Crupно цифра + sub trend. -->
    <div class="section-eyebrow">Объёмы</div>
    <v-row class="mb-5 dashboard-row">
      <v-col v-for="card in volumeCards" :key="card.title" cols="12" md="4">
        <v-card class="ds-card pa-5 h-100" elevation="0">
          <div class="d-flex justify-space-between align-start">
            <div class="flex-grow-1 min-w-0">
              <div class="text-caption text-uppercase text-medium-emphasis font-weight-bold letter-spacing-1">
                {{ card.title }}
              </div>
              <div class="text-h3 font-weight-bold my-2 tabular-nums">{{ fmt(card.value) }}</div>
              <div class="d-flex align-center ga-1">
                <v-icon :color="card.changeType === 'up' ? 'success' : card.changeType === 'down' ? 'error' : 'grey'" size="14">
                  {{ card.changeType === 'up' ? 'mdi-trending-up' : card.changeType === 'down' ? 'mdi-trending-down' : 'mdi-minus' }}
                </v-icon>
                <span class="text-caption" :class="card.changeType === 'up' ? 'text-success' : card.changeType === 'down' ? 'text-error' : 'text-medium-emphasis'">
                  {{ card.change }} к прошлому
                </span>
              </div>
            </div>
            <div class="kpi-icon-orb" :style="{ background: `rgba(var(--v-theme-${card.color}), 0.12)` }">
              <v-icon size="22" :color="card.color">{{ card.icon }}</v-icon>
            </div>
          </div>
        </v-card>
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

    <!-- Команда — показатели партнёров: 1 линия / всего, активные. -->
    <div class="section-eyebrow">Команда</div>
    <v-row class="mb-5 dashboard-row">
      <v-col v-for="kpi in teamKpis" :key="kpi.label" cols="12" sm="6" md="3">
        <v-card class="ds-card pa-4" elevation="0">
          <div class="d-flex align-center ga-3">
            <div class="kpi-icon-orb" :style="{ background: `rgba(var(--v-theme-${kpi.color}), 0.12)` }">
              <v-icon size="20" :color="kpi.color">{{ kpi.icon }}</v-icon>
            </div>
            <div class="min-w-0">
              <div class="text-caption text-medium-emphasis">{{ kpi.label }}</div>
              <div class="text-h5 font-weight-bold tabular-nums">{{ kpi.value }}</div>
            </div>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Клиенты — две большие интерактивные карточки. -->
    <div class="section-eyebrow">Клиенты</div>
    <v-row class="mb-5 dashboard-row">
      <v-col cols="12" sm="6">
        <router-link to="/clients" class="text-decoration-none">
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
        <router-link to="/clients" class="text-decoration-none">
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

    <!-- Партнёры по статусу — 4 кликабельные карточки с diff. -->
    <div class="section-eyebrow">Партнёры по статусу</div>
    <v-row class="mb-5 dashboard-row">
      <v-col v-for="card in partnerCards" :key="card.label" cols="12" sm="6" md="3">
        <router-link to="/structure" class="text-decoration-none">
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

    <!-- Loading: top progress bar instead of full-page overlay so the page skeleton stays visible -->
    <v-progress-linear v-if="loading" indeterminate color="primary"
      style="position: fixed; top: 0; left: 0; right: 0; z-index: 9; height: 3px;" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api';
import MonthPicker from '../components/MonthPicker.vue';
import PageHeader from '../components/PageHeader.vue';
import { fmt } from '../composables/useDesign';

const loading = ref(true);
const period = ref(new Date().toISOString().slice(0, 7));
const showLevels = ref(false);
const levels = ref([]);

const empty = {
  consultant: { id: 0, personName: '—', statusName: 'Партнёр', participantCode: null, active: false, ambassadorProducts: null, activityName: null },
  qualification: { nominalLevel: null, nextLevel: null },
  volumes: { personalVolume: 0, groupVolume: 0, groupVolumeCumulative: 0, prevPersonalVolume: 0, prevGroupVolume: 0, prevGroupVolumeCumulative: 0 },
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
const volumeCards = computed(() => {
  const v = data.value.volumes;
  const lp = pct(v.personalVolume, v.prevPersonalVolume);
  const ngp = pct(v.groupVolumeCumulative, v.prevGroupVolumeCumulative);
  return [
    { title: 'Личные продажи (ЛП)', value: v.personalVolume, change: lp.value, changeType: lp.type, icon: 'mdi-bank', color: 'green' },
    { title: 'НГП', value: v.groupVolumeCumulative, change: ngp.value, changeType: ngp.type, icon: 'mdi-trending-up', color: 'orange' },
  ];
});

// KPI «Команда» — компактные карточки с orb-иконками и цифрой.
const teamKpis = computed(() => {
  const t = data.value.team || {};
  return [
    { label: 'Партнёры 1 линии',  value: t.firstLineAll ?? 0,         icon: 'mdi-account-outline',         color: 'info' },
    { label: 'Всего партнёров',   value: t.totalPartners ?? 0,        icon: 'mdi-account-group',           color: 'primary' },
    { label: 'Активных 1 линии',  value: t.firstLineActive ?? 0,      icon: 'mdi-account-check',           color: 'success' },
    { label: 'Всего активных',    value: t.totalPartnersActive ?? 0,  icon: 'mdi-account-multiple-check',  color: 'success' },
  ];
});

const partnerCards = computed(() => {
  const p = data.value.partners || {};
  const pp = data.value.prevPartners || {};
  return [
    { label: 'Всего партнёров', value: p.total ?? 0, color: 'primary', diff: (p.total ?? 0) - (pp.total ?? 0) },
    { label: 'Зарегистрировано', value: p.registered ?? 0, color: 'info', diff: (p.registered ?? 0) - (pp.registered ?? 0) },
    { label: 'Активных', value: p.active ?? 0, color: 'success', diff: (p.active ?? 0) - (pp.active ?? 0) },
    { label: 'Терминированных', value: p.terminated ?? 0, color: 'error', diff: (p.terminated ?? 0) - (pp.terminated ?? 0) },
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
.kpi-icon-orb {
  width: 44px; height: 44px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.kpi-icon-orb--lg { width: 56px; height: 56px; }

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

