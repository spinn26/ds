<template>
  <div>
    <PageHeader title="Калькулятор объёмов" icon="mdi-calculator" />

    <v-alert v-if="isReadOnly('calculator')" type="info" density="compact" variant="tonal"
      class="mb-4" icon="mdi-eye-outline">
      Режим только для просмотра — доступна история расчётов.
    </v-alert>

    <v-card v-if="!isReadOnly('calculator')" class="mb-4 pa-4">
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

        <!-- 4. Программа — если есть программы для продукта.
             Программа = уникальное имя; срок и год выплаты вынесены в
             отдельные селекторы ниже. См. consolidatedPrograms. -->
        <v-col cols="12" sm="6" md="4" v-if="form.product && consolidatedPrograms.length">
          <v-select v-model="form.programName" :items="consolidatedPrograms" item-title="name" item-value="name"
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
    <v-card v-if="result && !result.error && !isReadOnly('calculator')" class="mb-4 pa-4">
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
    <v-alert v-if="result?.error && !isReadOnly('calculator')" type="error" density="compact" class="mb-4">{{ result.error }}</v-alert>

    <!-- History -->
    <v-card class="pa-4">
      <div class="d-flex justify-space-between align-center mb-3">
        <div class="text-subtitle-1 font-weight-bold">Предыдущие расчёты</div>
        <div class="d-flex align-center ga-2">
          <ColumnVisibilityMenu
            :headers="historyHeaders"
            v-model:visible="historyColumnVisible"
            storage-key="calculator-history-cols" />
          <v-btn v-if="!isReadOnly('calculator')" size="small" variant="text" prepend-icon="mdi-broom"
            @click="clearHistory" :disabled="!history.length">Очистить</v-btn>
        </div>
      </div>
      <v-data-table :items="history" :headers="visibleHistoryHeaders" density="compact" hover
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
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import { fmt2 as fmt } from '../../composables/useDesign';
import { usePermissions } from '../../composables/usePermissions';

const { isReadOnly } = usePermissions();

const matrixLoading = ref(true);
const calculating = ref(false);
const result = ref(null);
const history = ref([]);

const matrix = reactive({
  categories: [], types: [], products: [], programs: [],
  properties: [], terms: [], levels: [], currencies: [],
});

const form = reactive({
  qualification: null, product: null, programName: null,
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

// Все строки program для выбранного продукта (вариации с одинаковым name
// сюда тоже попадают — будем схлопывать на уровне consolidatedPrograms).
const productPrograms = computed(() => {
  if (!form.product) return [];
  return matrix.programs.filter(p => p.productId == form.product);
});

// Per spec: одна программа = одно имя. Срок контракта и год выплаты КВ —
// это свойства, не отдельные программы (см. «Жизнь+», «EVO», «Совкомбанк»).
// Группируем по name, сохраняя список вариантов, чтобы по выбранному
// сроку/году подобрать конкретный program.id для бэкенда.
const consolidatedPrograms = computed(() => {
  const groups = new Map();
  for (const p of productPrograms.value) {
    if (!groups.has(p.name)) {
      groups.set(p.name, { name: p.name, variants: [] });
    }
    groups.get(p.name).variants.push(p);
  }
  return Array.from(groups.values());
});

// Варианты (program-rows) выбранной по имени программы.
const programVariants = computed(() => {
  if (!form.programName) return [];
  const group = consolidatedPrograms.value.find(g => g.name === form.programName);
  return group ? group.variants : [];
});

// Per spec ✅Калькулятор объёмов §2: «Свойство / Срок / Год выплаты КВ» —
// опциональные поля, выводятся ТОЛЬКО если применимы к выбранной программе.
// Список «применимых» приходит с бэкенда как program.availableProperties[]
// и program.availableTerms[] (distinct из таблицы dsCommission). После
// схлопывания дубликатов берём union этих списков по всем вариантам.

const selectedProduct = computed(() =>
  form.product ? matrix.products.find(p => p.id == form.product) : null
);

const filteredProperties = computed(() => {
  if (selectedProduct.value && selectedProduct.value.hasProperty === false) return [];
  if (!programVariants.value.length) return [];
  const ids = new Set();
  for (const v of programVariants.value) {
    if (v.calcPropertyId) ids.add(Number(v.calcPropertyId));
    for (const pid of (v.availableProperties || [])) ids.add(Number(pid));
  }
  if (!ids.size) return [];
  return matrix.properties.filter(p => ids.has(Number(p.id)));
});

const filteredTerms = computed(() => {
  if (selectedProduct.value && selectedProduct.value.hasTerm === false) return [];
  if (!programVariants.value.length) return [];
  const labelize = (t) => ({ ...t, label: t.term + (t.term > 4 ? ' лет' : t.term > 1 ? ' года' : ' год') });
  const ids = new Set();
  for (const v of programVariants.value) {
    if (v.termContractId) ids.add(Number(v.termContractId));
    for (const tid of (v.availableTerms || [])) ids.add(Number(tid));
  }
  if (!ids.size) return [];
  return matrix.terms.filter(t => ids.has(Number(t.id))).map(labelize);
});

// Подобрать program.id из вариантов по (termContract, kvPayoutYear) —
// бэкенду нужен конкретный id; UI работает с именем.
const resolvedProgramId = computed(() => {
  if (!programVariants.value.length) return null;
  const scored = programVariants.value.map(v => {
    let score = 0;
    let viable = true;
    if (form.termContract) {
      const hasTerm = v.termContractId == form.termContract
        || (v.availableTerms || []).includes(Number(form.termContract));
      if (hasTerm) score += 2;
      else if (v.termContractId || (v.availableTerms || []).length) viable = false;
    }
    if (form.kvPayoutYear) {
      if (Number(v.kvPayoutYear || 0) >= Number(form.kvPayoutYear)) score += 1;
    }
    return { id: v.id, score, viable };
  });
  const pool = scored.filter(x => x.viable);
  const list = pool.length ? pool : scored;
  list.sort((a, b) => b.score - a.score);
  return list[0]?.id ?? programVariants.value[0].id;
});

// Show remaining fields if program selected OR no programs exist for product
const showRemainingFields = computed(() => {
  if (!form.product) return false;
  if (consolidatedPrograms.value.length === 0) return true;
  return !!form.programName;
});

const canCalculate = computed(() => {
  const needsProgram = consolidatedPrograms.value.length > 0;
  const needsProperty = filteredProperties.value.length > 0;
  return form.qualification && form.product && (!needsProgram || form.programName) && (!needsProperty || form.calcProperty) && form.amount > 0 && form.currency;
});

// Reset downstream fields
function resetFrom(field) {
  // 'program' в order означает поле выбора программы (теперь programName).
  const order = ['product', 'program', 'calcProperty', 'termContract', 'kvPayoutYear', 'amount'];
  const map = { program: 'programName' };
  const idx = order.indexOf(field);
  for (let i = idx + 1; i < order.length; i++) {
    const key = map[order[i]] || order[i];
    form[key] = null;
  }
  result.value = null;

  // Auto-select if only one option available
  if (field === 'product' && form.product) {
    const progs = consolidatedPrograms.value;
    if (progs.length === 1) {
      form.programName = progs[0].name;
      resetFrom('program');
    }
  }
  if (field === 'program' && form.programName) {
    const props = filteredProperties.value;
    if (props.length === 1) form.calcProperty = props[0].id;
    const terms = filteredTerms.value;
    if (terms.length === 1) form.termContract = terms[0].id;
  }
}

function resetForm() {
  form.qualification = null;
  form.product = null;
  form.programName = null;
  form.calcProperty = null;
  form.termContract = null;
  form.kvPayoutYear = null;
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
      // UI выбирает программу по имени; бэкенду нужен конкретный id —
      // подбираем его из вариантов по выбранным сроку/году.
      program: resolvedProgramId.value,
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

const historyColumnVisible = ref({});
const visibleHistoryHeaders = computed(() =>
  historyHeaders.filter(h => historyColumnVisible.value[h.key] !== false)
);

// Per spec ✅Калькулятор объемов §2 — Год выплаты КВ:
// если у выбранной программы заполнено поле kvPayoutYear, выводим
// варианты от 1 до этого года.
const kvYearOptions = computed(() => {
  // Гейт по config-флагу: продукты без kvPayoutYear-схемы (страхование без
  // year-of-payout, разовые услуги) скрывают поле даже если в legacy
  // program.kvPayoutYear осталось ненулевое значение.
  if (selectedProduct.value && selectedProduct.value.hasYearKv === false) return [];
  let max = 0;
  for (const v of programVariants.value) {
    max = Math.max(max, Number(v.kvPayoutYear || 0));
  }
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
