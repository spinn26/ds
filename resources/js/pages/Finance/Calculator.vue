<template>
  <div>
    <PageHeader title="Калькулятор объёмов" icon="mdi-calculator" />

    <v-card class="mb-4 pa-4">
      <v-row dense>
        <!-- 1. Квалификация — всегда видно -->
        <v-col cols="12" sm="6" md="4">
          <v-select v-model="form.qualification" :items="lvlItems" item-title="title" item-value="id"
            label="Квалификация" :loading="matrixLoading" />
        </v-col>

        <!-- 2. Тип продукта — после выбора квалификации -->
        <v-col cols="12" sm="6" md="4" v-if="form.qualification">
          <v-select v-model="form.productType" :items="filteredTypes" item-title="name" item-value="id"
            label="Тип продукта" clearable @update:model-value="resetFrom('productType')" />
        </v-col>

        <!-- 3. Продукт — после выбора типа -->
        <v-col cols="12" sm="6" md="4" v-if="form.productType">
          <v-select v-model="form.product" :items="filteredProducts" item-title="name" item-value="id"
            label="Продукт" clearable @update:model-value="resetFrom('product')" />
        </v-col>

        <!-- 4. Программа — после выбора продукта -->
        <v-col cols="12" sm="6" md="4" v-if="form.product">
          <v-select v-model="form.program" :items="filteredPrograms" item-title="name" item-value="id"
            label="Программа" clearable @update:model-value="resetFrom('program')" />
        </v-col>

        <!-- 5. Свойство — после выбора программы (filtered by program) -->
        <v-col cols="12" sm="6" md="4" v-if="form.program && filteredProperties.length">
          <v-select v-model="form.calcProperty" :items="filteredProperties" item-title="title" item-value="id"
            label="Свойство" />
        </v-col>

        <!-- 6. Срок контракта — если программа привязана к сроку -->
        <v-col cols="12" sm="6" md="4" v-if="form.program && filteredTerms.length">
          <v-select v-model="form.termContract" :items="filteredTerms" item-title="label" item-value="id"
            label="Срок контракта" clearable />
        </v-col>

        <!-- 7. Сумма — после свойства -->
        <v-col cols="12" sm="6" md="4" v-if="form.calcProperty">
          <v-text-field v-model.number="form.amount" label="Сумма взноса" type="number" />
        </v-col>

        <!-- 8. Валюта — после суммы -->
        <v-col cols="12" sm="6" md="4" v-if="form.amount > 0">
          <v-select v-model="form.currency" :items="allowedCurrencies" item-title="symbol" item-value="id"
            label="Валюта" />
        </v-col>
      </v-row>

      <div class="d-flex ga-2 mt-3">
        <v-btn color="primary" prepend-icon="mdi-calculator" :loading="calculating" @click="calculate"
          :disabled="!canCalculate">
          Рассчитать
        </v-btn>
        <v-btn variant="text" prepend-icon="mdi-refresh" @click="resetForm">
          Сбросить
        </v-btn>
      </div>

      <v-alert type="info" density="compact" variant="tonal" class="mt-3" icon="mdi-information">
        Расчёт комиссионных и объёмов для вновь открываемых контрактов с учётом НДС
      </v-alert>
    </v-card>

    <!-- Results -->
    <v-card v-if="result && !result.error" class="mb-4 pa-4">
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
    <v-alert v-if="result?.error" type="error" density="compact" class="mb-4">{{ result.error }}</v-alert>

    <!-- History -->
    <v-card class="pa-4">
      <div class="d-flex justify-space-between align-center mb-3">
        <div class="text-subtitle-1 font-weight-bold">Предыдущие расчёты</div>
        <v-btn size="small" variant="text" prepend-icon="mdi-broom" @click="clearHistory"
          :disabled="!history.length">Очистить</v-btn>
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
  calcProperty: null, termContract: null, amount: null, currency: null,
});

const lvlItems = computed(() =>
  matrix.levels.map(l => ({ ...l, title: `${l.level} [${l.title}] — ${l.percent}%` }))
);

// Only show: ₽ (RUB), $ (USD), € (EUR), £ (GBP)
const allowedSymbols = ['₽', '$', '€', '£', 'RUB', 'USD', 'EUR', 'GBP'];
const allowedCurrencies = computed(() =>
  matrix.currencies.filter(c => allowedSymbols.some(s => (c.symbol || '').includes(s) || (c.code || '').includes(s)))
);

// Cascading filters
const filteredTypes = computed(() => matrix.types);

const filteredProducts = computed(() => {
  if (!form.productType) return [];
  const byType = matrix.products.filter(p => p.typeId == form.productType);
  // Fallback: if no products match type (data issue), show all
  return byType.length ? byType : matrix.products;
});

const filteredPrograms = computed(() => {
  if (!form.product) return [];
  const byProduct = matrix.programs.filter(p => p.productId == form.product);
  return byProduct.length ? byProduct : matrix.programs;
});

// Filter properties by selected program's commissionCalcProperty
const filteredProperties = computed(() => {
  if (!form.program) return [];
  const prog = matrix.programs.find(p => p.id == form.program);
  if (prog?.calcPropertyId) {
    // Program has specific property — show only that one
    return matrix.properties.filter(p => p.id == prog.calcPropertyId);
  }
  // No specific property — show all
  return matrix.properties;
});

// Filter terms by selected program
const filteredTerms = computed(() => {
  if (!form.program) return [];
  const prog = matrix.programs.find(p => p.id == form.program);
  if (prog?.termContractId) {
    return matrix.terms.filter(t => t.id == prog.termContractId).map(t => ({ ...t, label: t.term + (t.term > 4 ? ' лет' : t.term > 1 ? ' года' : ' год') }));
  }
  // Show all terms
  return matrix.terms.map(t => ({ ...t, label: t.term + (t.term > 4 ? ' лет' : t.term > 1 ? ' года' : ' год') }));
});

const canCalculate = computed(() => {
  const needsProperty = filteredProperties.value.length > 0;
  return form.qualification && form.program && (!needsProperty || form.calcProperty) && form.amount > 0 && form.currency;
});

// Reset downstream fields
function resetFrom(field) {
  const order = ['productType', 'product', 'program', 'calcProperty', 'termContract', 'amount'];
  const idx = order.indexOf(field);
  for (let i = idx + 1; i < order.length; i++) {
    form[order[i]] = null;
  }
  result.value = null;

  // Auto-select if only one option
  if (field === 'program' && form.program) {
    const props = filteredProperties.value;
    if (props.length === 1) form.calcProperty = props[0].id;
    const terms = filteredTerms.value;
    if (terms.length === 1) form.termContract = terms[0].id;
  }
}

function resetForm() {
  form.qualification = null;
  form.productType = null;
  form.product = null;
  form.program = null;
  form.calcProperty = null;
  form.termContract = null;
  form.amount = null;
  form.currency = null;
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
    // Set default currency to RUB
    const rub = matrix.currencies.find(c => (c.symbol || '').includes('₽') || (c.code || '') === 'RUB');
    if (rub) form.currency = rub.id;
  } catch {}
  matrixLoading.value = false;
  loadHistory();
});
</script>
