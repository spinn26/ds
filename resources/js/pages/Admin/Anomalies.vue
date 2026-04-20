<template>
  <div>
    <PageHeader title="Аномалии и алерты" icon="mdi-alert-decagram">
      <template #actions>
        <v-btn variant="text" prepend-icon="mdi-refresh" :loading="loading" @click="load">Перепроверить</v-btn>
      </template>
    </PageHeader>

    <v-row dense class="mb-3">
      <v-col v-for="t in tiles" :key="t.key" cols="6" sm="3">
        <v-card :color="t.value > 0 ? 'warning' : 'success'" variant="tonal" class="pa-3">
          <div class="text-caption text-medium-emphasis">{{ t.label }}</div>
          <div class="text-h5 font-weight-bold">{{ t.value }}</div>
        </v-card>
      </v-col>
    </v-row>

    <v-card class="mb-3">
      <v-card-title class="pa-3">Клиенты с контрактами у разных партнёров (возможный «отжим»)</v-card-title>
      <v-data-table :items="data.multiContractClients || []" :headers="multiHeaders" density="compact" :items-per-page="10" />
    </v-card>

    <v-card class="mb-3">
      <v-card-title class="pa-3">Транзакции &gt;3σ от среднего продукта (за 90 дней)</v-card-title>
      <v-data-table :items="data.outlierTx || []" :headers="outlierHeaders" density="compact" :items-per-page="10">
        <template #item.amountRUB="{ value }"><MoneyCell :value="value" currency="₽" /></template>
        <template #item.avg_amt="{ value }"><MoneyCell :value="value" currency="₽" /></template>
        <template #item.date="{ value }">{{ fmtDate(value) }}</template>
      </v-data-table>
    </v-card>

    <v-card class="mb-3">
      <v-card-title class="pa-3">Партнёры с 0 ЛП и 10+ детьми (фиктивные ветки?)</v-card-title>
      <v-data-table :items="data.fakeBranches || []" :headers="fakeHeaders" density="compact" :items-per-page="10" />
    </v-card>

    <v-card>
      <v-card-title class="pa-3">Недавние перестановки (30 дней)</v-card-title>
      <v-data-table :items="data.recentTransfers || []" :headers="transferHeaders" density="compact" :items-per-page="10">
        <template #item.transferDate="{ value }">{{ fmtDate(value) }}</template>
      </v-data-table>
    </v-card>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, MoneyCell } from '../../components';
import { fmtDate } from '../../composables/useDesign';
import { useSnackbar } from '../../composables/useSnackbar';

const { showError } = useSnackbar();
const data = ref({});
const loading = ref(false);

const tiles = computed(() => {
  const s = data.value.summary || {};
  return [
    { key: 'multi', label: 'Клиенты у разных партнёров', value: s.multiContract ?? 0 },
    { key: 'out',   label: 'Аномальные транзакции',       value: s.outliers ?? 0 },
    { key: 'fake',  label: 'Фиктивные ветки',             value: s.fakeBranches ?? 0 },
    { key: 'tr',    label: 'Перестановки (30д)',          value: s.transfers ?? 0 },
  ];
});

const multiHeaders = [
  { title: 'Клиент #', key: 'id', width: 100 },
  { title: 'ФИО', key: 'personName' },
  { title: 'Партнёров', key: 'partners', width: 120 },
];
const outlierHeaders = [
  { title: 'Tx #', key: 'id', width: 100 },
  { title: 'Дата', key: 'date', width: 120 },
  { title: 'Сумма', key: 'amountRUB', align: 'end', width: 140 },
  { title: 'Продукт', key: 'productName' },
  { title: 'Среднее', key: 'avg_amt', align: 'end', width: 140 },
];
const fakeHeaders = [
  { title: 'Партнёр #', key: 'id', width: 100 },
  { title: 'ФИО', key: 'personName' },
  { title: 'Детей', key: 'children', width: 100 },
  { title: 'ЛП', key: 'lp', width: 100 },
];
const transferHeaders = [
  { title: 'ID', key: 'id', width: 80 },
  { title: 'Партнёр', key: 'consultant', width: 120 },
  { title: 'Старый наставник', key: 'oldInviter', width: 160 },
  { title: 'Новый наставник', key: 'newInviter', width: 160 },
  { title: 'Дата', key: 'transferDate', width: 140 },
];

async function load() {
  loading.value = true;
  try {
    const { data: d } = await api.get('/admin/analytics/anomalies');
    data.value = d;
  } catch (e) { showError(e.response?.data?.message || 'Не удалось загрузить аномалии'); }
  loading.value = false;
}
onMounted(load);
</script>
