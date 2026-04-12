<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-currency-usd</v-icon>
      <h5 class="text-h5 font-weight-bold">Валюты и НДС</h5>
    </div>

    <v-row>
      <v-col cols="12" md="7">
        <v-card>
          <v-card-title class="text-subtitle-1 font-weight-bold">Курсы валют</v-card-title>
          <v-data-table :items="currencies" :headers="currencyHeaders" density="compact"
            hover no-data-text="Нет данных" :loading="loading">
            <template #item.rate="{ value }">{{ fmtRate(value) }}</template>
          </v-data-table>
        </v-card>
      </v-col>
      <v-col cols="12" md="5">
        <v-card>
          <v-card-title class="text-subtitle-1 font-weight-bold">Ставки НДС</v-card-title>
          <v-data-table :items="vatRates" :headers="vatHeaders" density="compact"
            hover no-data-text="Нет данных" :loading="loading">
            <template #item.rate="{ value }">{{ value }}%</template>
          </v-data-table>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';

const loading = ref(true);
const currencies = ref([]);
const vatRates = ref([]);

const currencyHeaders = [
  { title: 'Код', key: 'code', width: 80 },
  { title: 'Наименование', key: 'name' },
  { title: 'Символ', key: 'symbol', width: 80 },
  { title: 'Курс к RUB', key: 'rate', width: 140 },
  { title: 'Дата', key: 'updatedAt', width: 120 },
];

const vatHeaders = [
  { title: 'Наименование', key: 'name' },
  { title: 'Ставка', key: 'rate', width: 100 },
  { title: 'Действует с', key: 'effectiveFrom', width: 130 },
];

const fmtRate = (n) => Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 4 });

async function loadData() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/currencies');
    currencies.value = data.currencies || [];
    vatRates.value = data.vatRates || data.vat || [];
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
