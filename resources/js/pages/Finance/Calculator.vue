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

        <!-- 2. Продукт — сразу после квалификации (тип продукта убран) -->
        <v-col cols="12" sm="6" md="4" v-if="form.qualification">
          <v-autocomplete v-model="form.product" :items="filteredProducts" item-title="name" item-value="id"
            label="Продукт" clearable @update:model-value="resetFrom('product')" />
        </v-col>

        <!-- 4. Программа — если есть программы для продукта -->
        <v-col cols="12" sm="6" md="4" v-if="form.product && filteredPrograms.length">
          <v-select v-model="form.program" :items="filteredPrograms" item-title="name" item-value="id"
            label="Программа" clearable @update:model-value="resetFrom('program')" />
        </v-col>

        <!-- 5. Свойство — после программы или сразу если нет программ -->
        <v-col cols="12" sm="6" md="4" v-if="showRemainingFields && filteredProperties.length">
          <v-select v-model="form.calcProperty" :items="filteredProperties" item-title="title" item-value="id"
            label="Свойство" />
        </v-col>

        <!-- 6. Срок контракта -->
        <v-col cols="12" sm="6" md="4" v-if="showRemainingFields && filteredTerms.length">
          <v-select v-model="form.termContract" :items="filteredTerms" item-title="label" item-value="id"
            label="Срок контракта" clearable />
        </v-col>

        <!-- 6.5. Год выплаты КВ — per spec ✅Калькулятор объемов §2 -->
        <v-col cols="12" sm="6" md="4" v-if="showRemainingFields && kvYearOptions.length">
          <v-select v-model="form.kvPayoutYear" :items="kvYearOptions"
            label="Год выплаты КВ" clearable
            hint="Год выплаты комиссионного вознаграждения от провайдера" persistent-hint />
        </v-col>

        <!-- 7. Сумма -->
        <v-col cols="12" sm="6" md="4" v-if="showRemainingFields">
          <v-text-field v-model.number="form.amount" label="Сумма взноса" type="number" />
        </v-col>

        <!-- 8. Валюта -->
        <v-col cols="12" sm="6" md="4" v-if="showRemainingFields">
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
          <div class="text-body-2 text-medium-emphasis">Комиссионные</div>
          <div class="text-h4 font-weight-bold text-primary">{{ fmt(result.groupBonusRub ?? result.commission) }} руб.</div>
        </v-col>
        <v-col cols="12" md="6">
          <div class="text-body-2 text-medium-emphasis">Личные продажи (ЛП)</div>
          <div class="text-h4 font-weight-bold text-success">{{ fmt(result.personalVolume) }} баллов</div>
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

    <v-progress-linear v-if="matrixLoading" indeterminate color="primary"
      style="position:fixed;top:0;left:0;right:0;z-index:2000" />
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
  qualification: null, product: null, program: null,
  calcProperty: null, termContract: null, kvPayoutYear: null,
  amount: null, currency: null,
});

const lvlItems = computed(() =>
  matrix.levels.map(l => ({ ...l, title: `${l.level} [${l.title}] — ${l.percent}%` }))
);

// Only show: ₽ (RUB), $ (USD), € (EUR), £ (GBP)
const allowedSymbols = ['₽', '$', '€', '£', 'RUB', 'USD', 'EUR', 'GBP'];
const allowedCurrencies = computed(() =>
  matrix.currencies.filter(c => allowedSymbols.some(s => (c.symbol || '').includes(s) || (c.code || '').includes(s)))
);

// Тип продукта убран из UI (per user request) — сразу показываем все
// активные продукты из matrix.products.
const filteredProducts = computed(() => matrix.products);

const filteredPrograms = computed(() => {
  if (!form.product) return [];
  return matrix.programs.filter(p => p.productId == form.product);
});

// Per spec ✅Калькулятор объёмов §2: «Свойство / Срок / Год выплаты КВ» —
// опциональные поля, выводятся ТОЛЬКО если применимы к выбранной программе.
// Список «применимых» приходит с бэкенда как program.availableProperties[]
// и program.availableTerms[] (distinct из таблицы dsCommission).
// Если массив пуст — поле в UI скрывается полностью.

const selectedProgram = computed(() =>
  form.program ? matrix.programs.find(p => p.id == form.program) : null
);

const filteredProperties = computed(() => {
  const prog = selectedProgram.value;
  if (!prog) return [];
  // Если у программы есть фиксированное свойство — показываем только его.
  if (prog.calcPropertyId) {
    return matrix.properties.filter(p => p.id == prog.calcPropertyId);
  }
  // Иначе — варианты из dsCommission. Если пусто — поле скрыто.
  const ids = prog.availableProperties || [];
  if (!ids.length) return [];
  return matrix.properties.filter(p => ids.includes(Number(p.id)));
});

const filteredTerms = computed(() => {
  const prog = selectedProgram.value;
  if (!prog) return [];
  const labelize = (t) => ({ ...t, label: t.term + (t.term > 4 ? ' лет' : t.term > 1 ? ' года' : ' год') });
  if (prog.termContractId) {
    return matrix.terms.filter(t => t.id == prog.termContractId).map(labelize);
  }
  const ids = prog.availableTerms || [];
  if (!ids.length) return [];
  return matrix.terms.filter(t => ids.includes(Number(t.id))).map(labelize);
});

// Show remaining fields if program selected OR no programs exist for product
const showRemainingFields = computed(() => {
  if (!form.product) return false;
  // If no programs for this product — show fields immediately
  if (filteredPrograms.value.length === 0) return true;
  // If program selected — show fields
  return !!form.program;
});

const canCalculate = computed(() => {
  const needsProgram = filteredPrograms.value.length > 0;
  const needsProperty = filteredProperties.value.length > 0;
  return form.qualification && form.product && (!needsProgram || form.program) && (!needsProperty || form.calcProperty) && form.amount > 0 && form.currency;
});

// Reset downstream fields
function resetFrom(field) {
  const order = ['product', 'program', 'calcProperty', 'termContract', 'amount'];
  const idx = order.indexOf(field);
  for (let i = idx + 1; i < order.length; i++) {
    form[order[i]] = null;
  }
  result.value = null;

  // Auto-select if only one option available
  if (field === 'product' && form.product) {
    const progs = filteredPrograms.value;
    if (progs.length === 1) {
      form.program = progs[0].id;
      resetFrom('program');
    }
  }
  if (field === 'program' && form.program) {
    const props = filteredProperties.value;
    if (props.length === 1) form.calcProperty = props[0].id;
    const terms = filteredTerms.value;
    if (terms.length === 1) form.termContract = terms[0].id;
  }
}

function resetForm() {
  form.qualification = null;
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
      kvPayoutYear: form.kvPayoutYear,
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
  { title: 'Год КВ', key: 'kvPayoutYear', align: 'end', width: 90 },
  { title: 'Сумма', key: 'amount', align: 'end', width: 120 },
  { title: 'ЛП', key: 'personalVolume', align: 'end', width: 100 },
  { title: 'Бонус (руб)', key: 'groupBonusRub', align: 'end', width: 120 },
];

// Per spec ✅Калькулятор объемов §2 — Год выплаты КВ:
// если у выбранной программы заполнено поле kvPayoutYear, выводим
// варианты от 1 до этого года.
const kvYearOptions = computed(() => {
  const program = matrix.programs?.find(p => p.id === form.program);
  const max = Number(program?.kvPayoutYear ?? 0);
  if (!max || max < 1) return [];
  return Array.from({ length: max }, (_, i) => i + 1);
});

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
