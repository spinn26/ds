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
        <FunnelSteps :steps="steps" :total-ever="totalEver" />
      </v-card-text>
    </v-card>

    <!-- Воронка: партнёры с 1 июня текущего года -->
    <v-card>
      <v-card-title class="text-h6 pa-4 pb-2">
        <v-icon start>mdi-calendar-start</v-icon>
        Зарегистрированы с 1 июня {{ currentYear }}
      </v-card-title>
      <v-card-text>
        <FunnelSteps :steps="stepsSince" :total-ever="totalEverSince" />
      </v-card-text>
    </v-card>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';
import { PageHeader } from '../../components';
import FunnelSteps from './FunnelSteps.vue';

const currentYear = new Date().getFullYear();
const sinceDate   = `${currentYear}-06-01`;

const steps       = ref([]);
const totalEver   = ref(0);
const stepsSince  = ref([]);
const totalEverSince = ref(0);

async function load() {
  try {
    const [all, since] = await Promise.all([
      api.get('/admin/analytics/funnel'),
      api.get('/admin/analytics/funnel', { params: { since: sinceDate } }),
    ]);
    steps.value       = all.data.steps || [];
    totalEver.value   = all.data.totalEverRegistered || 0;
    stepsSince.value  = since.data.steps || [];
    totalEverSince.value = since.data.totalEverRegistered || 0;
  } catch {}
}
onMounted(load);
</script>
