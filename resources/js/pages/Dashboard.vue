<template>
  <div>
    <PageHeader title="Дашборд партнёра" icon="mdi-view-dashboard">
      <template #actions>
        <MonthPicker v-model="period" @update:model-value="loadData" />
      </template>
    </PageHeader>

    <!-- Status Info Alert -->
    <v-alert v-if="data.statusInfo && data.statusInfo.daysRemaining != null" :type="data.statusInfo.daysRemaining <= 30 ? 'warning' : 'info'"
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

    <!-- Status + Qualification -->
    <v-card class="mb-4 pa-4">
      <div class="d-flex justify-space-between align-center mb-3 flex-wrap ga-2">
        <div>
          <div class="text-body-2 text-medium-emphasis">Статус</div>
          <div class="d-flex align-center ga-2">
            <span class="text-h6">{{ data.consultant.statusName }}</span>
            <v-chip v-if="data.consultant.activityName" size="small"
              :color="data.consultant.active ? 'success' : 'grey'">
              {{ data.consultant.activityName }}
            </v-chip>
          </div>
        </div>
        <v-btn variant="outlined" color="secondary" @click="showLevels = true">Условия перехода</v-btn>
      </div>
      <v-row>
        <v-col cols="12" md="4">
          <div class="text-body-2 text-medium-emphasis">Закрытая квалификация</div>
          <div class="d-flex align-center ga-2">
            <v-chip size="small" color="secondary">{{ data.qualification.nominalLevel?.level ?? '—' }}</v-chip>
            <span class="font-weight-medium">{{ data.qualification.nominalLevel?.title ?? '—' }}</span>
          </div>
        </v-col>
        <v-col cols="12" md="4">
          <div class="text-body-2 text-medium-emphasis">Комиссия</div>
          <v-chip size="small" color="primary">{{ data.qualification.nominalLevel?.percent ?? 0 }}%</v-chip>
        </v-col>
        <v-col cols="12" md="4">
          <div class="text-body-2 text-medium-emphasis">НГП</div>
          <v-progress-linear :model-value="nqpProgress" height="10" rounded color="primary" class="mb-1" />
          <div class="text-body-2">{{ fmt(data.volumes.groupVolumeCumulative) }} / {{ fmt(data.qualification.nextLevel?.groupVolumeCumulative ?? 0) }}</div>
        </v-col>
      </v-row>
    </v-card>

    <!-- Volumes -->
    <h6 class="text-h6 mb-3">Показатели</h6>
    <v-row class="mb-4">
      <v-col v-for="card in volumeCards" :key="card.title" cols="12" md="4">
        <v-card class="pa-4">
          <div class="d-flex justify-space-between">
            <div>
              <div class="text-body-2 text-medium-emphasis">{{ card.title }}</div>
              <div class="text-h4 font-weight-bold my-1">{{ fmt(card.value) }}</div>
              <div class="d-flex align-center ga-1">
                <v-icon :color="card.changeType === 'up' ? 'success' : 'error'" size="16">
                  {{ card.changeType === 'up' ? 'mdi-trending-up' : 'mdi-trending-down' }}
                </v-icon>
                <span class="text-caption" :class="card.changeType === 'up' ? 'text-success' : 'text-error'">
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

    <!-- Breakaway -->
    <v-card v-if="data.breakaway" class="mb-4 pa-4" color="amber-lighten-5" variant="tonal">
      <div class="d-flex align-center ga-2 mb-2">
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
          <div class="text-body-2 text-medium-emphasis">Разница (%)</div>
          <div class="font-weight-medium">{{ data.breakaway.gapValuePercentage ?? 0 }}%</div>
        </v-col>
      </v-row>
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

    <!-- Qualification transition table -->
    <h6 class="text-h6 mb-3">Таблица квалификаций</h6>
    <v-card class="mb-4">
      <v-table density="compact" hover>
        <thead>
          <tr>
            <th>Уровень</th>
            <th>Квалификация</th>
            <th class="text-right">НГП</th>
            <th class="text-right">ГП</th>
            <th class="text-right">ЛП</th>
            <th class="text-right">Кол-во партнёров</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="lv in levels" :key="lv.id"
            :class="lv.level === data.qualification.nominalLevel?.level ? 'bg-green-lighten-5' : ''">
            <td>{{ lv.level }}</td>
            <td class="font-weight-medium">
              {{ lv.title }}
              <v-chip v-if="lv.level === data.qualification.nominalLevel?.level" size="x-small" color="success" class="ml-1">Текущий</v-chip>
            </td>
            <td class="text-right">{{ fmt(lv.groupVolumeCumulative) }}</td>
            <td class="text-right">{{ fmt(lv.groupVolume) }}</td>
            <td class="text-right">{{ fmt(lv.personalVolume ?? 0) }}</td>
            <td class="text-right">{{ lv.partnersCount ?? '—' }}</td>
          </tr>
        </tbody>
      </v-table>
    </v-card>

    <!-- Levels dialog (full conditions) -->
    <v-dialog v-model="showLevels" max-width="900">
      <v-card>
        <v-card-title>Условия перехода</v-card-title>
        <v-card-text>
          <v-table density="compact">
            <thead>
              <tr>
                <th>No.</th><th>Квалификация</th><th class="text-right">%</th>
                <th class="text-right">ЛП</th><th class="text-right">ГП</th>
                <th class="text-right">НГП</th><th class="text-right">Отрыв</th>
                <th class="text-right">Пул</th><th class="text-right">Доля DS</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="lv in levels" :key="lv.id" :class="lv.level === data.qualification.nominalLevel?.level ? 'bg-green-lighten-5' : ''">
                <td>{{ lv.level }}</td>
                <td class="font-weight-medium">
                  {{ lv.title }}
                  <v-chip v-if="lv.level === data.qualification.nominalLevel?.level" size="x-small" color="success" class="ml-1">Текущий</v-chip>
                </td>
                <td class="text-right">{{ lv.percent }}%</td>
                <td class="text-right">{{ fmt(lv.personalVolume ?? 0) }}</td>
                <td class="text-right">{{ fmt(lv.groupVolume) }}</td>
                <td class="text-right">{{ fmt(lv.groupVolumeCumulative) }}</td>
                <td class="text-right">{{ fmt(lv.breakaway ?? 0) }}</td>
                <td class="text-right">{{ lv.pool }}%</td>
                <td class="text-right">{{ lv.dsShare ?? '—' }}</td>
              </tr>
            </tbody>
          </v-table>
        </v-card-text>
        <v-card-actions><v-btn @click="showLevels = false">Закрыть</v-btn></v-card-actions>
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
