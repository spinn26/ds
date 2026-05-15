<template>
  <div>
    <PageHeader title="Дашборд руководителя" icon="mdi-crown" />

    <!-- KPI плитки: основные цифры за текущий месяц. -->
    <v-row dense class="mb-3">
      <v-col cols="6" sm="3">
        <v-card variant="tonal" color="primary" class="pa-3">
          <div class="text-caption text-medium-emphasis">Активных партнёров</div>
          <div class="text-h4 font-weight-bold">{{ data.activeCount ?? '—' }}</div>
          <div class="text-caption text-medium-emphasis">из {{ data.totalCount ?? '—' }}</div>
        </v-card>
      </v-col>
      <v-col cols="6" sm="3">
        <v-card variant="tonal" color="success" class="pa-3">
          <div class="text-caption text-medium-emphasis">Выручка (текущий мес.)</div>
          <div class="text-h4 font-weight-bold">
            <MoneyCell :value="latestMonth.net" currency="₽" />
          </div>
        </v-card>
      </v-col>
      <v-col cols="6" sm="3">
        <v-card variant="tonal" color="warning" class="pa-3">
          <div class="text-caption text-medium-emphasis">К выплате (текущий мес.)</div>
          <div class="text-h4 font-weight-bold">
            <MoneyCell :value="data.currentMonthPayable" currency="₽" />
          </div>
        </v-card>
      </v-col>
      <v-col cols="6" sm="3">
        <v-card variant="tonal" color="info" class="pa-3">
          <div class="text-caption text-medium-emphasis">Пул (текущий мес.)</div>
          <div class="text-h4 font-weight-bold">
            <MoneyCell :value="data.currentMonthPool" currency="₽" />
          </div>
        </v-card>
      </v-col>
    </v-row>

    <v-row dense>
      <!-- Выручка по месяцам — гистограмма. -->
      <v-col cols="12" md="7">
        <v-card>
          <v-card-title class="pa-3">Выручка ДС по месяцам</v-card-title>
          <v-card-text>
            <div v-for="r in data.monthlyRevenue || []" :key="r.m" class="mb-2">
              <div class="d-flex align-center mb-1">
                <span class="text-body-2" style="width: 110px">{{ formatMonth(r.m) }}</span>
                <v-progress-linear
                  :model-value="barFor(r.net)"
                  height="22"
                  color="success"
                  class="flex-grow-1"
                />
                <span class="text-body-2 font-weight-bold ms-3" style="min-width: 120px; text-align: right">
                  <MoneyCell :value="r.net" currency="₽" />
                </span>
              </div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- Топ-10 партнёров по ГП. -->
      <v-col cols="12" md="5">
        <v-card>
          <v-card-title class="pa-3">Топ-10 партнёров по ГП</v-card-title>
          <v-list>
            <v-list-item v-for="(p, i) in data.topPartners || []" :key="p.id">
              <template #prepend>
                <v-avatar size="32" color="primary"><span class="text-caption text-white">{{ i + 1 }}</span></v-avatar>
              </template>
              <v-list-item-title>{{ p.personName }}</v-list-item-title>
              <v-list-item-subtitle>{{ p.title ? `${p.level} [${p.title}]` : 'нет квалификации' }}</v-list-item-subtitle>
              <template #append>
                <div class="text-right">
                  <div class="text-body-2 font-weight-medium"><MoneyCell :value="p.groupVolume" /></div>
                  <div class="text-caption text-medium-emphasis">ГП</div>
                </div>
              </template>
            </v-list-item>
          </v-list>
        </v-card>
      </v-col>
    </v-row>

    <!-- ===========================================================
         Воронка нового партнёра: единый блок — описание + сама
         воронка. Раньше была отдельной страницей /admin/funnel.
         =========================================================== -->
    <v-card class="mt-4" variant="tonal" color="info">
      <v-card-title class="d-flex align-center ga-2 pa-3">
        <v-icon>mdi-filter-variant</v-icon>
        Воронка нового партнёра — как читать
      </v-card-title>
      <v-card-text class="pb-4">
        <p class="mb-2">
          Воронка показывает <strong>путь от регистрации до лидерской квалификации</strong>.
          Каждая полоса — это этап жизненного пути партнёра. Чем длиннее полоса — тем больше людей дошло до этого шага.
        </p>
        <ul class="ps-4 mb-2">
          <li class="mb-1"><strong>Цифра справа</strong> — сколько партнёров прошли этот этап.</li>
          <li class="mb-1"><strong>«% от пред. шага»</strong> — конверсия: какая доля партнёров с предыдущего этапа добралась сюда.</li>
          <li class="mb-1"><strong>Красная полоса</strong> — этап, который означает «выпал» (терминирован, не дошёл).</li>
        </ul>
        <p class="mb-0">
          Если конверсия между двумя соседними этапами сильно проседает — это и есть «узкое место» воронки.
          Там стоит разобраться, почему партнёры теряются: слабый онбординг, отсутствие сопровождения, нет первой продажи и т.&nbsp;п.
        </p>
      </v-card-text>
    </v-card>

    <v-card class="mt-3">
      <v-card-title class="pa-3 d-flex align-center ga-2">
        <v-icon color="primary">mdi-filter-variant</v-icon>
        Воронка нового партнёра
      </v-card-title>
      <v-card-text>
        <div v-for="(s, i) in funnelSteps" :key="s.key" class="mb-4">
          <div class="d-flex align-center mb-1">
            <span class="text-body-1 font-weight-medium">{{ s.label }}</span>
            <v-spacer />
            <span class="text-body-1 font-weight-bold">
              {{ s.count.toLocaleString('ru-RU') }}
            </span>
            <span v-if="i > 0" class="text-caption text-medium-emphasis ms-3" style="min-width: 100px; text-align:right">
              {{ s.rate }}% от пред. шага
            </span>
          </div>
          <v-progress-linear
            :model-value="widthOf(s)"
            :color="s.negative ? 'error' : 'primary'"
            height="28"
          >
            <span class="text-caption text-white px-2">
              {{ widthOf(s).toFixed(1) }}% от {{ totalEver.toLocaleString('ru-RU') }} зарегистрированных
            </span>
          </v-progress-linear>
        </div>
        <div v-if="!funnelSteps.length" class="text-center text-medium-emphasis pa-4">
          Нет данных по воронке за выбранный период
        </div>
      </v-card-text>
    </v-card>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, MoneyCell } from '../../components';

const data = ref({});
const funnelSteps = ref([]);
const totalEver = ref(0);

const latestMonth = computed(() => {
  const arr = data.value.monthlyRevenue || [];
  return arr[arr.length - 1] || {};
});

const maxRev = computed(() => {
  const arr = data.value.monthlyRevenue || [];
  return Math.max(1, ...arr.map(r => Number(r.net) || 0));
});

function barFor(v) { return maxRev.value > 0 ? (Number(v) / maxRev.value) * 100 : 0; }
function widthOf(s) {
  if (!totalEver.value) return 0;
  return (s.count / totalEver.value) * 100;
}

function formatMonth(v) {
  if (!v) return '';
  return new Date(v).toLocaleDateString('ru-RU', { year: '2-digit', month: 'short' });
}

async function load() {
  // Грузим оба эндпоинта параллельно — единый дашборд показывает обе
  // секции (KPI/Топ + воронка) одним экраном.
  try {
    const [dash, funnel] = await Promise.all([
      api.get('/admin/analytics/owner-dashboard'),
      api.get('/admin/analytics/funnel'),
    ]);
    data.value = dash.data || {};
    funnelSteps.value = funnel.data?.steps || [];
    totalEver.value = funnel.data?.totalEverRegistered || 0;
  } catch {}
}
onMounted(load);
</script>
