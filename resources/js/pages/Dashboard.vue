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

    <!-- Qualification + Commission card -->
    <v-card class="mb-4 pa-4">
      <div class="d-flex justify-space-between align-center mb-4 flex-wrap ga-2">
        <div>
          <div class="text-caption text-medium-emphasis text-uppercase" style="letter-spacing: 1px">Квалификация</div>
          <div class="d-flex align-center ga-3 mt-1">
            <span class="text-h5 font-weight-bold">{{ data.qualification.nominalLevel?.title ?? 'Start' }}</span>
            <v-chip color="primary" size="small" variant="flat">
              {{ data.qualification.nominalLevel?.percent ?? 15 }}% комиссия
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

      <!-- Progress to next level (НГП) -->
      <div v-if="data.qualification.nextLevel" class="mb-3">
        <div class="d-flex justify-space-between align-center mb-1">
          <span class="text-body-2 text-medium-emphasis">
            Прогресс до <strong>{{ data.qualification.nextLevel.title }}</strong>
          </span>
          <span class="text-body-2 font-weight-medium">
            {{ fmt(data.volumes.groupVolumeCumulative) }} / {{ fmt(data.qualification.nextLevel.groupVolumeCumulative) }} НГП
          </span>
        </div>
        <v-progress-linear :model-value="nqpProgress" height="12" rounded color="primary" />
        <div class="text-caption text-medium-emphasis mt-1">
          Осталось набрать: <strong>{{ fmt(Math.max(0, (data.qualification.nextLevel.groupVolumeCumulative || 0) - data.volumes.groupVolumeCumulative)) }}</strong> баллов НГП
        </div>
      </div>
      <v-chip v-else color="amber" variant="tonal" prepend-icon="mdi-crown">Максимальная квалификация</v-chip>
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

    <!-- Mandatory GP plan (ОП по ГП) — from Expert onwards -->
    <v-card v-if="data.mandatoryPlan" class="mb-4 pa-4">
      <div class="d-flex align-center ga-2 mb-3">
        <v-icon :color="data.mandatoryPlan.fulfilled ? 'success' : 'warning'">
          {{ data.mandatoryPlan.fulfilled ? 'mdi-check-circle' : 'mdi-alert-circle' }}
        </v-icon>
        <span class="text-h6 font-weight-bold">Обязательный план (ОП по ГП)</span>
      </div>

      <v-row align="center">
        <v-col cols="12" md="6">
          <div class="d-flex justify-space-between mb-1">
            <span class="text-body-2">Выполнение плана</span>
            <span class="text-body-2 font-weight-bold" :class="data.mandatoryPlan.fulfilled ? 'text-success' : 'text-warning'">
              {{ data.mandatoryPlan.fulfillment }}%
            </span>
          </div>
          <v-progress-linear
            :model-value="data.mandatoryPlan.fulfillment"
            height="14" rounded
            :color="data.mandatoryPlan.fulfilled ? 'success' : data.mandatoryPlan.fulfillment >= 80 ? 'warning' : 'error'" />
          <div class="text-caption text-medium-emphasis mt-1">
            {{ fmt(data.mandatoryPlan.currentGP) }} / {{ fmt(data.mandatoryPlan.mandatoryGP) }} баллов ГП
          </div>
        </v-col>
        <v-col cols="12" md="6">
          <v-alert v-if="!data.mandatoryPlan.fulfilled" type="warning" variant="tonal" density="compact">
            <div class="text-body-2">
              При невыполнении плана ГП комиссия уменьшается на <strong>{{ data.mandatoryPlan.commissionReduction }}%</strong> от ОП.
              Баллы объёмов не уменьшаются.
            </div>
          </v-alert>
          <v-alert v-else type="success" variant="tonal" density="compact">
            <div class="text-body-2">План выполнен. Личные продажи рассчитываются по текущей квалификации.</div>
          </v-alert>
        </v-col>
      </v-row>
    </v-card>

    <!-- Breakaway (Отрыв) -->
    <v-card v-if="data.breakaway" class="mb-4 pa-4" color="amber-lighten-5" variant="tonal">
      <div class="d-flex align-center ga-2 mb-3">
        <v-icon color="amber-darken-2">mdi-alert-decagram</v-icon>
        <span class="text-h6 font-weight-bold text-amber-darken-3">Отрыв зафиксирован</span>
      </div>
      <v-row>
        <v-col cols="12" sm="6" md="3">
          <div class="text-body-2 text-medium-emphasis">Ветка</div>
          <div class="font-weight-medium">{{ data.breakaway.branchWithGapName || '—' }}</div>
        </v-col>
        <v-col cols="12" sm="6" md="3">
          <div class="text-body-2 text-medium-emphasis">ГП ветки</div>
          <div class="font-weight-medium">{{ fmt(data.breakaway.branchWithGapGroupVolume) }}</div>
        </v-col>
        <v-col cols="12" sm="6" md="3">
          <div class="text-body-2 text-medium-emphasis">Разница (баллы)</div>
          <div class="font-weight-medium">{{ fmt(data.breakaway.gapValue) }}</div>
        </v-col>
        <v-col cols="12" sm="6" md="3">
          <div class="text-body-2 text-medium-emphasis">% от общего ГП</div>
          <div class="font-weight-medium text-amber-darken-3">{{ data.breakaway.gapValuePercentage ?? 0 }}%</div>
        </v-col>
      </v-row>
      <v-alert type="info" variant="tonal" density="compact" class="mt-3">
        <div class="text-body-2">
          Если одна ветка приносит более <strong>70%</strong> от общего объёма ГП, происходит снижение комиссионного вознаграждения от этой ветки.
        </div>
      </v-alert>
    </v-card>

    <!-- Breakaway rules info (if applicable to current level) -->
    <v-card v-if="data.breakawayRules && !data.breakaway" class="mb-4 pa-4" variant="outlined">
      <div class="d-flex align-center ga-2 mb-2">
        <v-icon color="info" size="20">mdi-information-outline</v-icon>
        <span class="text-body-1 font-weight-medium">Правила отрыва</span>
      </div>
      <div class="text-body-2 text-medium-emphasis">
        На вашей квалификации действует правило отрыва: если одна ветка приносит более <strong>{{ data.breakawayRules.threshold }}%</strong> от общего ГП,
        комиссия от этой ветки снижается. Отрыв не зафиксирован.
      </div>
    </v-card>

    <!-- Pool info (from TOP FC onwards) -->
    <v-card v-if="data.poolInfo" class="mb-4 pa-4">
      <div class="d-flex align-center ga-2 mb-2">
        <v-icon :color="data.poolInfo.eligible ? 'primary' : 'grey'">mdi-cash-multiple</v-icon>
        <span class="text-h6 font-weight-bold">Пул</span>
        <v-chip size="small" :color="data.poolInfo.eligible ? 'success' : 'error'" variant="tonal">
          {{ data.poolInfo.eligible ? 'Участвуете' : 'Не участвуете' }}
        </v-chip>
      </div>
      <div class="text-body-2 text-medium-emphasis mb-2">
        Пул — бонус для лидерских квалификаций (от TOP FC). Рассчитывается как
        <strong>{{ data.poolInfo.poolPercent }}%</strong> от выручки DS без НДС, поделённый на количество
        финансовых консультантов в соответствующей квалификации.
      </div>
      <v-alert v-if="!data.poolInfo.eligible" type="warning" variant="tonal" density="compact">
        {{ data.poolInfo.reason }}. Для участия в пуле необходимо выполнить план ГП на 80%.
      </v-alert>
    </v-card>

    <!-- Partners block -->
    <h6 class="text-h6 mb-3">Партнёры</h6>
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

    <!-- Clients block -->
    <h6 class="text-h6 mb-3">Клиенты</h6>
    <v-row class="mb-4">
      <v-col cols="12" sm="6">
        <router-link to="/clients" style="text-decoration: none; color: inherit">
          <v-card class="pa-4 text-center" hover>
            <v-icon size="32" color="primary" class="mb-2">mdi-account-multiple</v-icon>
            <div class="text-body-2 text-medium-emphasis">Мои клиенты</div>
            <div class="text-h3 font-weight-bold text-primary">{{ data.team.myClients }}</div>
          </v-card>
        </router-link>
      </v-col>
      <v-col cols="12" sm="6">
        <router-link to="/clients" style="text-decoration: none; color: inherit">
          <v-card class="pa-4 text-center" hover>
            <v-icon size="32" color="secondary" class="mb-2">mdi-account-group</v-icon>
            <div class="text-body-2 text-medium-emphasis">Клиенты команды</div>
            <div class="text-h3 font-weight-bold text-secondary">{{ data.team.teamClients }}</div>
          </v-card>
        </router-link>
      </v-col>
    </v-row>

    <!-- Qualification conditions table -->
    <h6 class="text-h6 mb-3">Условия квалификаций</h6>
    <v-card class="mb-4">
      <div style="overflow-x: auto">
        <v-table density="compact" hover>
          <thead>
            <tr>
              <th>#</th>
              <th>Квалификация</th>
              <th class="text-right">% вознаграждения</th>
              <th class="text-right">НГП</th>
              <th class="text-right">ОП по ГП</th>
              <th class="text-right">Отрыв по ГП</th>
              <th class="text-right">Пул %</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="lv in levels" :key="lv.id"
              :class="lv.level === data.qualification.nominalLevel?.level ? 'bg-green-lighten-5' : ''">
              <td>{{ lv.level }}</td>
              <td class="font-weight-medium">
                {{ lv.title }}
                <v-chip v-if="lv.level === data.qualification.nominalLevel?.level" size="x-small" color="success" class="ml-1">
                  Текущий
                </v-chip>
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
    </v-card>

    <!-- Full conditions dialog -->
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

    <v-overlay v-model="loading" class="align-center justify-center" persistent>
      <v-progress-circular indeterminate size="64" />
    </v-overlay>
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
  team: { myClients: 0, teamClients: 0 },
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
