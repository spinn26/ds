<template>
  <div>
    <PageHeader title="Дашборд владельца" icon="mdi-crown" />

    <!-- KPI tiles -->
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
      <!-- Revenue chart (bars as simple progress) -->
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

      <!-- Top-10 partners -->
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
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, MoneyCell } from '../../components';

const data = ref({});

const latestMonth = computed(() => {
  const arr = data.value.monthlyRevenue || [];
  return arr[arr.length - 1] || {};
});

const maxRev = computed(() => {
  const arr = data.value.monthlyRevenue || [];
  return Math.max(1, ...arr.map(r => Number(r.net) || 0));
});

function barFor(v) { return maxRev.value > 0 ? (Number(v) / maxRev.value) * 100 : 0; }

function formatMonth(v) {
  if (!v) return '';
  return new Date(v).toLocaleDateString('ru-RU', { year: '2-digit', month: 'short' });
}

async function load() {
  try {
    const { data: d } = await api.get('/admin/analytics/owner-dashboard');
    data.value = d;
  } catch {}
}
onMounted(load);
</script>
