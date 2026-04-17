<template>
  <div>
    <v-card flat rounded="xl" class="brand-hero mb-4 overflow-hidden position-relative">
      <div class="brand-hero-waves">
        <BrandWaves :width="1400" :height="170" shape="sheet"
          bg-color="#6EE87A" stroke-color="#ffffff"
          :rows="18" :columns="36" :amplitude="16" :frequency="1.2"
          :stroke-opacity="0.75" :stroke-width="1" />
      </div>
      <div class="brand-hero-content d-flex justify-space-between align-center flex-wrap ga-3 pa-5">
        <div class="d-flex align-center ga-2">
          <v-icon size="28" color="brand-ink">mdi-view-dashboard</v-icon>
          <div>
            <div class="text-h5 font-weight-bold" style="color: rgb(var(--v-theme-brand-ink))">Дашборд партнёра</div>
            <div class="text-body-2" style="color: rgba(10, 43, 16, 0.7)">Твоя квалификация, ЛП / ГП и прогресс по периоду</div>
          </div>
        </div>
        <MonthPicker v-model="period" @update:model-value="loadData" />
      </div>
    </v-card>

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

    <!-- Qualification + Commission card -->
    <v-card class="mb-4 pa-4">
      <div class="d-flex justify-space-between align-center mb-4 flex-wrap ga-2">
        <div>
          <div class="text-caption text-medium-emphasis text-uppercase" style="letter-spacing: 1px">Закрытая квалификация</div>
          <div class="d-flex align-center ga-3 mt-1 flex-wrap">
            <v-chip color="secondary" size="default" variant="flat" class="font-weight-bold">
              {{ data.qualification.nominalLevel?.level ?? '—' }} [{{ data.qualification.nominalLevel?.title ?? 'Start' }}]
            </v-chip>
            <v-chip v-if="data.consultant.activityName" size="small"
              :color="data.consultant.active ? 'success' : 'grey'" variant="tonal">
              {{ data.consultant.activityName }}
            </v-chip>
          </div>
        </div>
        <v-btn variant="outlined" color="secondary" prepend-icon="mdi-table" @click="showLevels = true">
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

      <!-- Calculation level (if differs from nominal = breakaway) -->
      <div v-if="data.qualification.levelsDontMatch && data.qualification.calculationLevel" class="mb-3">
        <div class="d-flex align-center ga-2 flex-wrap">
          <span class="text-body-2 text-medium-emphasis">Уровень расчёта комиссионных</span>
          <v-chip color="warning" size="small" variant="flat">
            {{ data.qualification.calculationLevel.level }} [{{ data.qualification.calculationLevel.title }}]
          </v-chip>
          <span class="text-body-2">Комиссия <strong>{{ data.qualification.calculationLevel.percent }}%</strong></span>
        </div>
      </div>
      <div v-else class="mb-3">
        <span class="text-body-2 text-medium-emphasis">Комиссия</span>
        <span class="text-body-2 font-weight-bold ml-2">{{ data.qualification.nominalLevel?.percent ?? 15 }}%</span>
      </div>

      <!-- GP progress bar -->
      <div v-if="data.mandatoryPlan" class="mb-3">
        <div class="d-flex justify-space-between align-center mb-1">
          <span class="text-body-2 text-medium-emphasis">ГП</span>
          <span class="text-body-2 font-weight-medium">
            {{ fmt(data.mandatoryPlan.currentGP) }} / {{ fmt(data.mandatoryPlan.mandatoryGP) }}
          </span>
        </div>
        <v-progress-linear :model-value="data.mandatoryPlan.fulfillment" height="10" rounded
          :color="data.mandatoryPlan.fulfilled ? 'success' : data.mandatoryPlan.fulfillment >= 80 ? 'warning' : 'error'" />
      </div>

      <!-- Breakaway status -->
      <div class="mb-1">
        <span class="text-body-2 text-medium-emphasis">Отрыв</span>
        <span v-if="data.breakaway" class="text-body-2 text-warning font-weight-medium ml-2">
          Зафиксирован ({{ data.breakaway.gapValuePercentage }}% — ветка {{ data.breakaway.branchWithGapName }})
        </span>
        <span v-else class="text-body-2 text-medium-emphasis ml-2">
          Продажи в дочерних ветках не зафиксированы
        </span>
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
    </v-card>

    <!-- Volume cards -->
    <v-row class="mb-4">
      <v-col v-for="card in volumeCards" :key="card.title" cols="12" md="4">
        <v-card class="pa-4 h-100">
          <div class="d-flex justify-space-between">
            <div>
              <div class="text-body-2 text-medium-emphasis">{{ card.title }}</div>
              <div class="text-h4 font-weight-bold my-1">{{ fmt(card.value) }}</div>
              <div class="d-flex align-center ga-1">
                <v-icon :color="card.changeType === 'up' ? 'success' : card.changeType === 'down' ? 'error' : 'grey'" size="16">
                  {{ card.changeType === 'up' ? 'mdi-trending-up' : card.changeType === 'down' ? 'mdi-trending-down' : 'mdi-minus' }}
                </v-icon>
                <span class="text-caption" :class="card.changeType === 'up' ? 'text-success' : card.changeType === 'down' ? 'text-error' : 'text-medium-emphasis'">
                  {{ card.change }} к прошлому месяцу
                </span>
              </div>
            </div>
            <v-avatar :color="card.color" size="48" variant="tonal">
              <v-icon>{{ card.icon }}</v-icon>
            </v-avatar>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Обязательные продажи (ОП) -->
    <v-card v-if="data.mandatoryPlan" class="mb-4 pa-4">
      <div class="d-flex align-center ga-2 mb-3">
        <v-icon :color="data.mandatoryPlan.fulfilled ? 'success' : 'warning'">
          {{ data.mandatoryPlan.fulfilled ? 'mdi-check-circle' : 'mdi-alert-circle' }}
        </v-icon>
        <span class="text-subtitle-1 font-weight-bold">Обязательные продажи (ОП по ГП)</span>
      </div>
      <v-row align="center">
        <v-col cols="12" md="6">
          <div class="d-flex justify-space-between mb-1">
            <span class="text-body-2">Выполнение плана</span>
            <span class="text-body-2 font-weight-bold" :class="data.mandatoryPlan.fulfilled ? 'text-success' : 'text-warning'">
              {{ data.mandatoryPlan.fulfillment }}%
            </span>
          </div>
          <v-progress-linear :model-value="data.mandatoryPlan.fulfillment" height="12" rounded
            :color="data.mandatoryPlan.fulfilled ? 'success' : data.mandatoryPlan.fulfillment >= 80 ? 'warning' : 'error'" />
          <div class="text-caption text-medium-emphasis mt-1">
            {{ fmt(data.mandatoryPlan.currentGP) }} / {{ fmt(data.mandatoryPlan.mandatoryGP) }} баллов ГП за месяц
          </div>
        </v-col>
        <v-col cols="12" md="6">
          <v-alert v-if="!data.mandatoryPlan.fulfilled" type="warning" variant="tonal" density="compact">
            При невыполнении ОП комиссия уменьшается на {{ data.mandatoryPlan.commissionReduction }}%.
            Баллы объёмов не уменьшаются.
          </v-alert>
          <v-alert v-else type="success" variant="tonal" density="compact">
            План выполнен. Комиссия рассчитывается по текущей квалификации.
          </v-alert>
        </v-col>
      </v-row>
    </v-card>

    <!-- Показатели -->
    <h6 class="text-h6 mb-3">Показатели</h6>
    <v-row class="mb-4">
      <v-col cols="12" sm="6" md="3">
        <v-card class="pa-4 text-center">
          <v-icon size="24" color="blue" class="mb-1">mdi-account-outline</v-icon>
          <div class="text-caption text-medium-emphasis">Партнёры 1 линии</div>
          <div class="text-h4 font-weight-bold">{{ data.team?.firstLineAll ?? 0 }}</div>
        </v-card>
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <v-card class="pa-4 text-center">
          <v-icon size="24" color="blue-darken-2" class="mb-1">mdi-account-group</v-icon>
          <div class="text-caption text-medium-emphasis">Всего партнёров</div>
          <div class="text-h4 font-weight-bold">{{ data.team?.totalPartners ?? 0 }}</div>
        </v-card>
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <v-card class="pa-4 text-center">
          <v-icon size="24" color="green" class="mb-1">mdi-account-check</v-icon>
          <div class="text-caption text-medium-emphasis">Активных 1 линии</div>
          <div class="text-h4 font-weight-bold">{{ data.team?.firstLineActive ?? 0 }}</div>
        </v-card>
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <v-card class="pa-4 text-center">
          <v-icon size="24" color="green-darken-2" class="mb-1">mdi-account-multiple-check</v-icon>
          <div class="text-caption text-medium-emphasis">Всего активных</div>
          <div class="text-h4 font-weight-bold">{{ data.team?.totalPartnersActive ?? 0 }}</div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Клиенты -->
    <v-row class="mb-4">
      <v-col cols="12" sm="6">
        <router-link to="/clients" style="text-decoration: none; color: inherit">
          <v-card class="pa-4 text-center" hover>
            <v-icon size="28" color="primary" class="mb-1">mdi-account-multiple</v-icon>
            <div class="text-caption text-medium-emphasis">Клиенты команды</div>
            <div class="text-h4 font-weight-bold text-primary">{{ data.team?.teamClients ?? 0 }}</div>
          </v-card>
        </router-link>
      </v-col>
      <v-col cols="12" sm="6">
        <router-link to="/clients" style="text-decoration: none; color: inherit">
          <v-card class="pa-4 text-center" hover>
            <v-icon size="28" color="secondary" class="mb-1">mdi-account</v-icon>
            <div class="text-caption text-medium-emphasis">Мои клиенты</div>
            <div class="text-h4 font-weight-bold text-secondary">{{ data.team?.myClients ?? 0 }}</div>
          </v-card>
        </router-link>
      </v-col>
    </v-row>

    <!-- Партнёры по статусу -->
    <h6 class="text-h6 mb-3">Партнёры по статусу</h6>
    <v-row class="mb-4">
      <v-col v-for="card in partnerCards" :key="card.label" cols="12" sm="6" md="3">
        <router-link to="/structure" style="text-decoration: none; color: inherit">
          <v-card class="pa-4 text-center" hover>
            <div class="text-body-2 text-medium-emphasis">{{ card.label }}</div>
            <div class="text-h3 font-weight-bold" :class="`text-${card.color}`">{{ card.value }}</div>
            <div v-if="card.diff != null" class="d-flex align-center justify-center ga-1 mt-1">
              <v-icon :color="card.diff >= 0 ? 'success' : 'error'" size="14">
                {{ card.diff >= 0 ? 'mdi-trending-up' : 'mdi-trending-down' }}
              </v-icon>
              <span class="text-caption" :class="card.diff >= 0 ? 'text-success' : 'text-error'">
                {{ card.diff >= 0 ? '+' : '' }}{{ card.diff }} к прошлому периоду
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
                  :class="lv.level === data.qualification.nominalLevel?.level ? 'bg-green-lighten-5' : ''">
                  <td>{{ lv.level }}</td>
                  <td class="font-weight-medium">
                    {{ lv.title }}
                    <v-chip v-if="lv.level === data.qualification.nominalLevel?.level" size="x-small" color="success" class="ml-1">Текущий</v-chip>
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

          <!-- Rules summary -->
          <v-divider class="my-4" />
          <div class="text-body-2 text-medium-emphasis">
            <p class="mb-2"><strong>НГП</strong> — накопительные продажи за период нахождения ФК в сети. ГП — это ГП+ЛП за месяц.</p>
            <p class="mb-2"><strong>ОП по ГП</strong> — обязательный план продаж (от Expert). При невыполнении комиссия уменьшается на 20% от ОП.</p>
            <p class="mb-2"><strong>Отрыв</strong> — если одна ветка приносит более 70% от общего объёма ГП, происходит снижение комиссии от этой ветки.</p>
            <p class="mb-0"><strong>Пул</strong> — бонус для лидерских квалификаций (от TOP FC): 1% от выручки DS без НДС, делённый на количество ФК в квалификации.</p>
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
import BrandWaves from '../components/BrandWaves.vue';
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

const volumeCards = computed(() => {
  const v = data.value.volumes;
  const lp = pct(v.personalVolume, v.prevPersonalVolume);
  const gp = pct(v.groupVolume, v.prevGroupVolume);
  const ngp = pct(v.groupVolumeCumulative, v.prevGroupVolumeCumulative);
  return [
    { title: 'Личные продажи (ЛП)', value: v.personalVolume, change: lp.value, changeType: lp.type, icon: 'mdi-bank', color: 'green' },
    { title: 'Групповые продажи (ГП)', value: v.groupVolume, change: gp.value, changeType: gp.type, icon: 'mdi-account-group', color: 'blue' },
    { title: 'Накопленные ГП (НГП)', value: v.groupVolumeCumulative, change: ngp.value, changeType: ngp.type, icon: 'mdi-trending-up', color: 'orange' },
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
.brand-hero { border: 1px solid rgba(var(--v-theme-brand), 0.35); }
.brand-hero-waves { position: absolute; inset: 0; z-index: 0; opacity: 0.95; }
.brand-hero-content { position: relative; z-index: 1; }
</style>
