<template>
  <div>
    <div class="d-flex justify-space-between align-center mb-4 flex-wrap ga-2">
      <div class="d-flex align-center ga-2">
        <v-icon size="32" color="primary">mdi-bank</v-icon>
        <h5 class="text-h5 font-weight-bold">Отчёт начислений и выплат</h5>
      </div>
      <v-text-field v-model="month" type="month" density="compact" variant="outlined"
        style="max-width:200px" hide-details @update:model-value="loadData" />
    </div>

    <!-- Summary Cards -->
    <v-row class="mb-4">
      <v-col v-for="card in summaryCards" :key="card.label" cols="12" sm="6" md="3">
        <v-card class="pa-4">
          <div class="text-body-2 text-medium-emphasis">{{ card.label }}</div>
          <div class="text-h5 font-weight-bold" :class="`text-${card.color}`">{{ card.value }}</div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Balance Card -->
    <v-card v-if="data.balance != null" class="pa-4 mb-4">
      <div class="d-flex align-center ga-2">
        <v-icon color="green">mdi-wallet</v-icon>
        <div class="text-body-2 text-medium-emphasis">Баланс</div>
      </div>
      <div class="text-h4 font-weight-bold text-green mt-1">{{ fmt(data.balance) }} RUB</div>
    </v-card>

    <!-- Tabs -->
    <v-tabs v-model="tab" color="primary" class="mb-4">
      <v-tab value="commissions">Комиссии</v-tab>
      <v-tab value="payments">Выплаты</v-tab>
    </v-tabs>

    <v-tabs-window v-model="tab">
      <v-tabs-window-item value="commissions">
        <v-card>
          <v-data-table :items="data.commissions || []" :headers="commHeaders" density="compact"
            hover no-data-text="Нет данных">
            <template #item.amount="{ value }">{{ fmt(value) }}</template>
          </v-data-table>
        </v-card>
      </v-tabs-window-item>

      <v-tabs-window-item value="payments">
        <v-card>
          <v-data-table :items="data.payments || []" :headers="payHeaders" density="compact"
            hover no-data-text="Нет данных">
            <template #item.amount="{ value }">{{ fmt(value) }}</template>
            <template #item.status="{ value }">
              <v-chip size="x-small" :color="value === 'paid' ? 'success' : 'warning'">
                {{ value === 'paid' ? 'Выплачено' : value }}
              </v-chip>
            </template>
          </v-data-table>
        </v-card>
      </v-tabs-window-item>
    </v-tabs-window>

    <v-overlay v-model="loading" class="align-center justify-center" persistent>
      <v-progress-circular indeterminate size="64" />
    </v-overlay>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';

const loading = ref(true);
const month = ref(new Date().toISOString().slice(0, 7));
const tab = ref('commissions');
const data = ref({});

const fmt = (n) => Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2 });

const summaryCards = computed(() => [
  { label: 'Итого начислений (RUB)', value: fmt(data.value.totalAmountRUB), color: 'primary' },
  { label: 'Личный объём', value: fmt(data.value.totalPersonalVolume), color: 'green' },
  { label: 'Групповой объём', value: fmt(data.value.totalGroupVolume), color: 'blue' },
  { label: 'Групповой бонус', value: fmt(data.value.totalGroupBonus), color: 'orange' },
]);

const commHeaders = [
  { title: 'Дата', key: 'date', width: 120 },
  { title: 'Тип', key: 'type' },
  { title: 'Описание', key: 'description' },
  { title: 'Сумма', key: 'amount', align: 'end', width: 140 },
  { title: 'Валюта', key: 'currency', width: 80 },
];

const payHeaders = [
  { title: 'Дата', key: 'date', width: 120 },
  { title: 'Описание', key: 'description' },
  { title: 'Сумма', key: 'amount', align: 'end', width: 140 },
  { title: 'Статус', key: 'status', width: 120 },
];

async function loadData() {
  loading.value = true;
  try {
    const { data: d } = await api.get('/finance/report', { params: { month: month.value } });
    data.value = d;
  } catch {
    data.value = {};
  }
  loading.value = false;
}

onMounted(loadData);
</script>
