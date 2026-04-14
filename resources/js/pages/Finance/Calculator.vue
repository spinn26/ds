<template>
  <div>
    <PageHeader title="Калькулятор объёмов" icon="mdi-calculator" />

    <!-- Input form -->
    <v-card class="mb-4 pa-4">
      <v-row dense>
        <v-col cols="12" sm="6" md="3">
          <v-select v-model="form.qualification" :items="lvlItems" item-title="title" item-value="id"
            label="Квалификация" :loading="matrixLoading" />
        </v-col>
        <v-col cols="12" sm="6" md="3">
          <v-select v-model="form.productType" :items="filteredTypes" item-title="name" item-value="id"
            label="Тип продукта" clearable
            @update:model-value="form.product = null; form.program = null" />
        </v-col>
        <v-col cols="12" sm="6" md="3">
          <v-select v-model="form.product" :items="filteredProducts" item-title="name" item-value="id"
            label="Продукт" clearable
            @update:model-value="form.program = null" />
        </v-col>
        <v-col cols="12" sm="6" md="3">
          <v-select v-model="form.program" :items="filteredPrograms" item-title="name" item-value="id"
            label="Программа" clearable
            @update:model-value="onProgramChange" />
        </v-col>
        <v-col cols="12" sm="6" md="3">
          <v-select v-model="form.calcProperty" :items="matrix.properties" item-title="title" item-value="id"
            label="Свойство продукта" />
        </v-col>
        <v-col cols="12" sm="6" md="3" v-if="availableTerms.length">
          <v-select v-model="form.termContract" :items="availableTerms" item-title="label" item-value="id"
            label="Срок контракта (лет)" clearable />
        </v-col>
        <v-col cols="12" sm="6" md="3">
          <v-text-field v-model.number="form.amount" label="Сумма взноса" type="number" />
        </v-col>
        <v-col cols="12" sm="6" md="3">
          <v-select v-model="form.currency" :items="matrix.currencies" item-title="symbol" item-value="id"
            label="Валюта" />
        </v-col>
      </v-row>

      <div class="d-flex ga-2 mt-2">
        <v-btn color="primary" prepend-icon="mdi-calculator" :loading="calculating" @click="calculate"
          :disabled="!canCalculate">
          Рассчитать
        </v-btn>
        <v-btn variant="text" prepend-icon="mdi-refresh" @click="resetForm">
          Сбросить параметры
        </v-btn>
      </div>

      <v-alert type="info" density="compact" variant="tonal" class="mt-3" icon="mdi-information">
        Расчёт комиссионных и объёмов указан для вновь открываемых контрактов и с учётом НДС
      </v-alert>
    </v-card>

    <!-- Results -->
    <v-card v-if="result" class="mb-4 pa-4">
      <v-row>
        <v-col cols="12" md="6">
          <div class="text-body-2 text-medium-emphasis">Комиссия</div>
          <div class="text-h4 font-weight-bold text-primary">{{ fmt(result.commission) }} руб.</div>
        </v-col>
        <v-col cols="12" md="6">
          <div class="text-body-2 text-medium-emphasis">Личные продажи (ЛП)</div>
          <div class="text-h4 font-weight-bold text-green">{{ fmt(result.personalVolume) }} баллов</div>
        </v-col>
      </v-row>
      <v-divider class="my-3" />
      <v-row dense>
        <v-col cols="6" md="2">
          <div class="text-caption text-medium-emphasis">Сумма (RUB)</div>
          <div class="text-body-2 font-weight-medium">{{ fmt(result.amountRub) }}</div>
        </v-col>
        <v-col cols="6" md="2">
          <div class="text-caption text-medium-emphasis">Без НДС</div>
          <div class="text-body-2 font-weight-medium">{{ fmt(result.amountNoVat) }}</div>
        </v-col>
        <v-col cols="6" md="2">
          <div class="text-caption text-medium-emphasis">%DS</div>
          <div class="text-body-2 font-weight-medium">{{ result.dsCommissionPercent }}%</div>
        </v-col>
        <v-col cols="6" md="2">
          <div class="text-caption text-medium-emphasis">НДС</div>
          <div class="text-body-2 font-weight-medium">{{ result.vatPercent }}%</div>
        </v-col>
        <v-col cols="6" md="2">
          <div class="text-caption text-medium-emphasis">Курс</div>
          <div class="text-body-2 font-weight-medium">{{ result.currencyRate }}</div>
        </v-col>
        <v-col cols="6" md="2">
          <div class="text-caption text-medium-emphasis">Гр. бонус (руб)</div>
          <div class="text-body-2 font-weight-medium">{{ fmt(result.groupBonusRub) }}</div>
        </v-col>
      </v-row>
    </v-card>

    <!-- History -->
    <v-card class="pa-4">
      <div class="d-flex justify-space-between align-center mb-3">
        <div class="text-subtitle-1 font-weight-bold">Предыдущие расчёты</div>
        <v-btn size="small" variant="text" prepend-icon="mdi-broom" @click="clearHistory"
          :disabled="!history.length">Очистить историю</v-btn>
      </div>
      <v-data-table :items="history" :headers="historyHeaders" density="compact" hover
        no-data-text="Нет сохранённых расчётов" :items-per-page="10">
        <template #item.personalVolume="{ value }">{{ fmt(value) }}</template>
        <template #item.groupBonusRub="{ value }">{{ fmt(value) }}</template>
        <template #item.amount="{ value }">{{ fmt(value) }}</template>
      </v-data-table>
    </v-card>

    <v-overlay v-model="matrixLoading" class="align-center justify-center" persistent>
      <v-progress-circular indeterminate size="64" />
    </v-overlay>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import { fmt2 as fmt } from '../../composables/useDesign';

const matrixLoading = ref(true);
const calculating = ref(false);
const result = ref(null);
const history = ref([]);

const matrix = reactive({
  categories: [], types: [], products: [], programs: [],
  properties: [], terms: [], levels: [], currencies: [],
});

const form = reactive({
  qualification: null, productType: null, product: null, program: null,
  calcProperty: null, termContract: null, amount: null, currency: 67, // RUB
});

// Format qualification label
const lvlItems = computed(() =>
  matrix.levels.map(l => ({ ...l, title: `${l.level} [${l.title}] — ${l.percent}%` }))
);

// Cascading filters
const filteredTypes = computed(() =>
  matrix.types
);

const filteredProducts = computed(() => {
  if (!form.productType) return matrix.products;
  return matrix.products.filter(p => p.typeId == form.productType);
});

const filteredPrograms = computed(() => {
  if (!form.product) return matrix.programs;
  return matrix.programs.filter(p => p.productId == form.product);
});

const availableTerms = computed(() =>
  matrix.terms.map(t => ({ ...t, label: `${t.term} лет` }))
);

const canCalculate = computed(() =>
  form.qualification && form.program && form.calcProperty && form.amount > 0 && form.currency
);

function onProgramChange() {
  form.calcProperty = null;
  form.termContract = null;
}

function resetForm() {
  form.qualification = null;
  form.productType = null;
  form.product = null;
  form.program = null;
  form.calcProperty = null;
  form.termContract = null;
  form.amount = null;
  form.currency = 67;
  result.value = null;
}

async function calculate() {
  if (!canCalculate.value) return;
  calculating.value = true;
  result.value = null;
  try {
    const { data } = await api.post('/calculator/calculate', {
      qualification: form.qualification,
      program: form.program,
      calcProperty: form.calcProperty,
      amount: form.amount,
      currency: form.currency,
      termContract: form.termContract,
    });
    result.value = data;
    loadHistory();
  } catch (e) {
    result.value = { error: e.response?.data?.error || 'Ошибка расчёта' };
  }
  calculating.value = false;
}

async function loadHistory() {
  try {
    const { data } = await api.get('/calculator/history');
    history.value = data;
  } catch {}
}

async function clearHistory() {
  try {
    await api.delete('/calculator/history');
    history.value = [];
  } catch {}
}

const historyHeaders = [
  { title: 'Квалификация', key: 'qualification', width: 140 },
  { title: 'Продукт', key: 'productName' },
  { title: 'Программа', key: 'programName' },
  { title: 'Свойство', key: 'property', width: 120 },
  { title: 'Сумма', key: 'amount', align: 'end', width: 120 },
  { title: 'ЛП', key: 'personalVolume', align: 'end', width: 100 },
  { title: 'Бонус (руб)', key: 'groupBonusRub', align: 'end', width: 120 },
];

onMounted(async () => {
  try {
    const { data } = await api.get('/calculator/product-matrix');
    Object.assign(matrix, data);
  } catch {}
  matrixLoading.value = false;
  loadHistory();
});
</script>
