<template>
  <div>
    <PageHeader title="Программы" icon="mdi-format-list-bulleted-type" />

    <v-card class="ds-card mb-3 pa-3" elevation="0">
      <div class="d-flex ga-3 align-center flex-wrap">
        <v-text-field v-model="search" placeholder="Программа, продукт или поставщик…"
          density="compact" hide-details variant="outlined" style="max-width:320px"
          prepend-inner-icon="mdi-magnify" clearable @keyup.enter="load" @click:clear="load" />
        <v-select v-model="productId" :items="productOptions" item-title="name" item-value="id"
          label="Продукт" density="compact" hide-details variant="outlined" clearable
          style="max-width:260px" @update:model-value="load" />
        <v-select v-model="activeFilter" :items="ACTIVE_OPTIONS" label="Активность"
          density="compact" hide-details variant="outlined" clearable
          style="max-width:180px" @update:model-value="load" />
        <!-- flex:0 0 auto обязателен: у .v-selection-control в Vuetify стоит
             `flex: 1 0` (= basis 0%), и один лишь класс flex-grow-0 давал
             `flex: 0 0 0%` — нулевую ширину, из-за чего лейбл разваливался
             по одной букве в строку. Базис надо вернуть в auto. -->
        <v-checkbox-btn v-model="needsSetup" label="без расчётных параметров"
          density="compact" hide-details class="text-no-wrap"
          style="flex:0 0 auto" @update:model-value="load" />
        <v-btn size="small" variant="tonal" @click="load">Найти</v-btn>
        <v-spacer />
        <span class="text-caption text-medium-emphasis">{{ total }} программ</span>
      </div>
    </v-card>

    <v-card class="ds-card" elevation="0">
      <v-table density="compact" class="programs-table">
        <thead>
          <tr>
            <th>Продукт</th>
            <th>Программа</th>
            <th>Поставщик</th>
            <th class="text-end">%ДС</th>
            <th>Начисление ЛП</th>
            <th>Свойство расчёта</th>
            <th class="text-end">Год КВ</th>
            <th class="text-end">Тарифов</th>
            <th>Видимость</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in rows" :key="p.id">
            <td class="text-caption">
              {{ p.productName || '—' }}
              <div v-if="!p.productActive" class="text-medium-emphasis">продукт выключен</div>
            </td>
            <td>
              {{ p.name }}
              <div class="text-caption text-medium-emphasis">id {{ p.id }}</div>
            </td>
            <td class="text-caption">{{ p.providerName || '—' }}</td>
            <td class="text-end">
              <span v-if="p.dsPercent !== null && p.dsPercent !== undefined">{{ p.dsPercent }}</span>
              <span v-else class="text-medium-emphasis">—</span>
            </td>
            <td class="text-caption">{{ methodLabel(p.pointsMethod) }}</td>
            <td class="text-caption">{{ p.commissionCalcProperty || '—' }}</td>
            <td class="text-end text-caption">{{ p.kvPayoutYear || '—' }}</td>
            <td class="text-end">
              <span :class="p.tariffCount ? '' : 'text-medium-emphasis'">{{ p.tariffCount }}</span>
            </td>
            <td>
              <v-chip size="x-small" :color="p.active ? 'success' : 'default'" variant="tonal">
                {{ p.active ? 'активна' : 'выключена' }}
              </v-chip>
              <v-chip v-if="p.visibleToResident" size="x-small" variant="tonal" class="ms-1">партнёру</v-chip>
              <v-chip v-if="p.visibleToCalculator" size="x-small" variant="tonal" class="ms-1">калькулятор</v-chip>
            </td>
            <td class="text-end">
              <v-btn size="x-small" variant="tonal" color="primary"
                :to="`/manage/products?product=${p.productId}&program=${p.id}`">Открыть</v-btn>
            </td>
          </tr>
        </tbody>
      </v-table>

      <div v-if="!loading && !rows.length" class="pa-4 text-center text-medium-emphasis">
        Ничего не найдено.
      </div>

      <div class="d-flex align-center ga-3 pa-3">
        <v-pagination v-model="page" :length="pageCount" :total-visible="7" density="compact"
          @update:model-value="load" />
        <v-select v-model="perPage" :items="[25, 50, 100]" density="compact" hide-details
          variant="outlined" style="max-width:110px" @update:model-value="() => { page = 1; load(); }" />
      </div>
    </v-card>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';

const ACTIVE_OPTIONS = [
  { title: 'Активные', value: '1' },
  { title: 'Выключенные', value: '0' },
];

// Подписи способов начисления ЛП — те же ключи, что читает калькулятор.
const METHODS = {
  amount_x_dsPercent: 'Сумма без НДС × %ДС',
  amount_div_100: 'Сумма ÷ 100',
  fixed: 'Фиксированные баллы',
  formula: 'По формуле',
};
const methodLabel = (m) => (m ? (METHODS[m] || m) : '—');

const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const productId = ref(null);
const activeFilter = ref(null);
const needsSetup = ref(false);
const page = ref(1);
const perPage = ref(50);
const productOptions = ref([]);

const pageCount = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)));

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/products-catalog/programs', {
      params: {
        search: search.value || undefined,
        product_id: productId.value || undefined,
        active: activeFilter.value ?? undefined,
        needs_setup: needsSetup.value ? 1 : undefined,
        page: page.value,
        per_page: perPage.value,
      },
    });
    rows.value = data.data || [];
    total.value = data.total || 0;
  } finally {
    loading.value = false;
  }
}

async function loadProducts() {
  const { data } = await api.get('/admin/products-catalog', { params: { per_page: 500 } });
  productOptions.value = (data.data || []).map(p => ({ id: p.id, name: p.name }));
}

onMounted(() => { loadProducts(); load(); });
</script>

<style scoped>
.programs-table :deep(td) { vertical-align: top; }
</style>
