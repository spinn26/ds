<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-calculator</v-icon>
      <h5 class="text-h5 font-weight-bold">Калькулятор объёмов</h5>
    </div>

    <!-- Current Volumes -->
    <v-row class="mb-4">
      <v-col cols="12" sm="4">
        <v-card class="pa-4">
          <div class="text-body-2 text-medium-emphasis">Личный объём (ЛП)</div>
          <div class="text-h5 font-weight-bold text-green">{{ fmt(data.personalVolume) }}</div>
        </v-card>
      </v-col>
      <v-col cols="12" sm="4">
        <v-card class="pa-4">
          <div class="text-body-2 text-medium-emphasis">Групповой объём (ГП)</div>
          <div class="text-h5 font-weight-bold text-blue">{{ fmt(data.groupVolume) }}</div>
        </v-card>
      </v-col>
      <v-col cols="12" sm="4">
        <v-card class="pa-4">
          <div class="text-body-2 text-medium-emphasis">Накопленный ГП (НГП)</div>
          <div class="text-h5 font-weight-bold text-orange">{{ fmt(data.groupVolumeCumulative) }}</div>
        </v-card>
      </v-col>
    </v-row>

    <!-- NGP Projection -->
    <v-card class="pa-4 mb-4">
      <div class="text-subtitle-1 font-weight-bold mb-3">Прогноз НГП</div>
      <div class="text-body-2 text-medium-emphasis mb-2">Предполагаемый ежемесячный ГП</div>
      <v-slider v-model="projectedGP" :min="0" :max="maxSlider" :step="100" thumb-label="always" color="primary">
        <template #thumb-label="{ modelValue }">{{ fmt(modelValue) }}</template>
      </v-slider>
      <div class="text-body-2 mt-2">
        Прогноз НГП через 12 мес: <strong class="text-primary">{{ fmt(projectedNGP) }}</strong>
      </div>
    </v-card>

    <!-- Qualification Forecast -->
    <v-card class="pa-4 mb-4">
      <div class="text-subtitle-1 font-weight-bold mb-3">Прогноз квалификации</div>
      <div v-if="data.currentQualification" class="mb-2">
        Текущая: <v-chip size="small" color="secondary">{{ data.currentQualification.title }}</v-chip>
      </div>
      <div v-if="forecastQualification" class="mb-3">
        Прогнозируемая: <v-chip size="small" color="primary">{{ forecastQualification.title }}</v-chip>
        <v-progress-linear :model-value="forecastProgress" height="10" rounded color="primary" class="mt-2" />
        <div class="text-body-2 mt-1">{{ fmt(projectedNGP) }} / {{ fmt(forecastQualification.groupVolumeCumulative) }}</div>
      </div>
    </v-card>

    <!-- Qualification Table -->
    <v-card>
      <v-card-title class="text-subtitle-1 font-weight-bold">Таблица квалификаций</v-card-title>
      <v-table density="compact">
        <thead>
          <tr>
            <th>Уровень</th><th>Квалификация</th><th class="text-right">%</th>
            <th class="text-right">ГП</th><th class="text-right">НГП</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="lv in data.levels || []" :key="lv.level"
            :class="lv.level === data.currentQualification?.level ? 'bg-green-lighten-5' : (lv.level === forecastQualification?.level ? 'bg-blue-lighten-5' : '')">
            <td>{{ lv.level }}</td>
            <td>
              {{ lv.title }}
              <v-chip v-if="lv.level === data.currentQualification?.level" size="x-small" color="success" class="ml-1">Текущий</v-chip>
              <v-chip v-if="lv.level === forecastQualification?.level && lv.level !== data.currentQualification?.level" size="x-small" color="info" class="ml-1">Прогноз</v-chip>
            </td>
            <td class="text-right">{{ lv.percent }}%</td>
            <td class="text-right">{{ fmt(lv.groupVolume) }}</td>
            <td class="text-right">{{ fmt(lv.groupVolumeCumulative) }}</td>
          </tr>
        </tbody>
      </v-table>
    </v-card>

    <v-overlay v-model="loading" class="align-center justify-center" persistent>
      <v-progress-circular indeterminate size="64" />
    </v-overlay>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';

const loading = ref(true);
const data = ref({});
const projectedGP = ref(0);

const fmt = (n) => Number(n || 0).toLocaleString('ru-RU');

const maxSlider = computed(() => Math.max(50000, (data.value.groupVolume || 0) * 3));

const projectedNGP = computed(() => {
  return (data.value.groupVolumeCumulative || 0) + projectedGP.value * 12;
});

const forecastQualification = computed(() => {
  const levels = data.value.levels || [];
  let best = null;
  for (const lv of levels) {
    if (projectedNGP.value >= (lv.groupVolumeCumulative || 0)) best = lv;
  }
  return best;
});

const forecastProgress = computed(() => {
  if (!forecastQualification.value) return 0;
  const target = forecastQualification.value.groupVolumeCumulative || 1;
  return Math.min((projectedNGP.value / target) * 100, 100);
});

async function loadData() {
  loading.value = true;
  try {
    const { data: d } = await api.get('/finance/calculator');
    data.value = d;
    projectedGP.value = d.groupVolume || 0;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
