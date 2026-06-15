<template>
  <div>
    <PageHeader title="Воронка нового партнёра" icon="mdi-filter-variant" />

    <v-card class="mb-3">
      <v-card-text>
        Путь от регистрации до лидерской квалификации. Показывает, где партнёры
        теряются, чтобы можно было точечно работать со слабыми конверсиями.
      </v-card-text>
    </v-card>

    <!-- Воронка: все партнёры за всё время -->
    <v-card class="mb-4">
      <v-card-title class="text-h6 pa-4 pb-2">
        <v-icon start>mdi-chart-waterfall</v-icon>
        Все партнёры (за всё время)
      </v-card-title>
      <v-card-text>
        <template v-if="loading">
          <v-skeleton-loader type="list-item-three-line" v-for="n in 6" :key="n" class="mb-2" />
        </template>
        <template v-else>
          <div v-for="(s, i) in steps" :key="s.key" class="mb-4">
            <div class="d-flex align-center mb-1">
              <span class="text-body-1 font-weight-medium">{{ s.label }}</span>
              <v-spacer />
              <span class="text-body-1 font-weight-bold">{{ s.count.toLocaleString('ru-RU') }}</span>
              <span v-if="i > 0" class="text-caption text-medium-emphasis ms-3" style="min-width:80px;text-align:right">
                {{ s.rate }}% от пред.
              </span>
            </div>
            <v-progress-linear :model-value="widthOf(s, totalEver)" :color="s.negative ? 'error' : 'primary'" height="28" rounded>
              <span class="funnel-pct text-caption">{{ widthOf(s, totalEver).toFixed(1) }}% от {{ totalEver.toLocaleString('ru-RU') }} зарег.</span>
            </v-progress-linear>
          </div>
        </template>
      </v-card-text>
    </v-card>

    <!-- Воронка: партнёры с 1 июня текущего года -->
    <v-card>
      <v-card-title class="text-h6 pa-4 pb-2">
        <v-icon start>mdi-calendar-start</v-icon>
        Зарегистрированы с 1 июня {{ currentYear }}
      </v-card-title>
      <v-card-text>
        <template v-if="loadingSince">
          <v-skeleton-loader type="list-item-three-line" v-for="n in 6" :key="n" class="mb-2" />
        </template>
        <template v-else>
          <div v-for="(s, i) in stepsSince" :key="s.key" class="mb-4">
            <div class="d-flex align-center mb-1">
              <span class="text-body-1 font-weight-medium">{{ s.label }}</span>
              <v-spacer />
              <span class="text-body-1 font-weight-bold">{{ s.count.toLocaleString('ru-RU') }}</span>
              <span v-if="i > 0" class="text-caption text-medium-emphasis ms-3" style="min-width:80px;text-align:right">
                {{ s.rate }}% от пред.
              </span>
            </div>
            <v-progress-linear :model-value="widthOf(s, totalEverSince)" :color="s.negative ? 'error' : 'primary'" height="28" rounded>
              <span class="funnel-pct text-caption">{{ widthOf(s, totalEverSince).toFixed(1) }}% от {{ totalEverSince.toLocaleString('ru-RU') }} зарег.</span>
            </v-progress-linear>
          </div>
        </template>
      </v-card-text>
    </v-card>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';
import { PageHeader } from '../../components';

const currentYear    = new Date().getFullYear();
const sinceDate      = `${currentYear}-06-01`;

const steps          = ref([]);
const totalEver      = ref(0);
const loading        = ref(true);

const stepsSince     = ref([]);
const totalEverSince = ref(0);
const loadingSince   = ref(true);

function widthOf(s, total) {
  if (!total) return 0;
  return (s.count / total) * 100;
}

async function load() {
  try {
    const { data } = await api.get('/admin/analytics/funnel');
    steps.value     = data.steps || [];
    totalEver.value = data.totalEverRegistered || 0;
  } finally {
    loading.value = false;
  }
}

async function loadSince() {
  try {
    const { data } = await api.get('/admin/analytics/funnel', { params: { since: sinceDate } });
    stepsSince.value     = data.steps || [];
    totalEverSince.value = data.totalEverRegistered || 0;
  } finally {
    loadingSince.value = false;
  }
}

onMounted(() => { load(); loadSince(); });
</script>

<style scoped>
.funnel-pct {
  color: #fff;
  background: rgba(0, 0, 0, 0.5);
  border-radius: 6px;
  padding: 1px 8px;
  font-weight: 500;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
</style>
