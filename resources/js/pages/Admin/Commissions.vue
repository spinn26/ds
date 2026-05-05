<template>
  <div>
    <PageHeader title="Комиссии" icon="mdi-receipt" :count="total">
      <template #actions>
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="commissions-cols" />
      </template>
    </PageHeader>

    <v-card class="mb-3 pa-3">
      <v-row dense align="center">
        <v-col cols="12" md="2">
          <v-text-field v-model="filters.partner" placeholder="ФИО Партнёра"
            density="comfortable" variant="outlined" hide-details clearable
            prepend-inner-icon="mdi-magnify"
            @update:model-value="debouncedLoad" />
        </v-col>
        <v-col cols="12" md="2">
          <v-text-field v-model="filters.chainPartner" placeholder="Партнёр в цепочке"
            density="comfortable" variant="outlined" hide-details clearable
            prepend-inner-icon="mdi-account-tree"
            @update:model-value="debouncedLoad"
            title="Найти все сделки, с которых партнёр получал ГП" />
        </v-col>
        <v-col cols="12" md="2">
          <v-text-field v-model="filters.client" placeholder="ФИО клиента"
            density="comfortable" variant="outlined" hide-details clearable
            @update:model-value="debouncedLoad" />
        </v-col>
        <v-col cols="12" md="2">
          <v-text-field v-model="filters.contract" placeholder="№ контракта"
            density="comfortable" variant="outlined" hide-details clearable
            @update:model-value="debouncedLoad" />
        </v-col>
        <v-col cols="6" md="2">
          <v-text-field v-model="filters.dateFrom" type="date" label="Дата с"
            density="comfortable" variant="outlined" hide-details
            @update:model-value="loadData" />
        </v-col>
        <v-col cols="6" md="2">
          <v-text-field v-model="filters.dateTo" type="date" label="Дата по"
            density="comfortable" variant="outlined" hide-details
            @update:model-value="loadData" />
        </v-col>
        <v-col cols="12" md="3">
          <v-autocomplete v-model="filters.supplier" :items="supplierOptions"
            placeholder="Поставщик" density="comfortable" variant="outlined"
            hide-details clearable
            @update:model-value="loadData" />
        </v-col>
        <v-col cols="12" md="3">
          <v-text-field v-model="filters.comment" placeholder="Поиск по комментарию"
            density="comfortable" variant="outlined" hide-details clearable
            @update:model-value="debouncedLoad" />
        </v-col>
        <v-col cols="12" md="3" class="d-flex align-center ga-2">
          <v-checkbox v-model="filters.hideZero" label="Скрыть нулевые"
            density="compact" hide-details color="primary"
            title="Скрыть транзакции с amountRUB=0"
            @update:model-value="loadData" />
          <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal">
            {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
          </v-chip>
        </v-col>
        <v-col cols="auto" class="d-flex align-center ms-auto">
          <v-btn v-if="activeFilterCount > 0" size="small" variant="text" color="secondary"
            prepend-icon="mdi-filter-remove" @click="resetFilters">
            Очистить фильтры
          </v-btn>
        </v-col>
      </v-row>
    </v-card>

    <!-- Сводка сверху таблицы — суммы по основным денежным колонкам
         за все строки текущего фильтра (не только видимая страница). -->
    <v-card v-if="aggregates && total > 0" variant="tonal" color="primary" class="mb-2 pa-3">
      <div class="d-flex flex-wrap ga-4 text-body-2">
        <span><strong>Записей:</strong> {{ total }}</span>
        <span v-if="aggregates.amountRUB != null">
          <strong>Сумма ₽:</strong> {{ fmt(aggregates.amountRUB) }}
        </span>
        <span v-if="aggregates.commissionsAmountRUB != null">
          <strong>Комиссия ₽:</strong> {{ fmt(aggregates.commissionsAmountRUB) }}
        </span>
        <span v-if="aggregates.netRevenueRUB != null">
          <strong>Выручка ДС ₽:</strong> {{ fmt(aggregates.netRevenueRUB) }}
        </span>
        <span v-if="aggregates.commissionsAmountUSD != null">
          <strong>Комиссия $:</strong> {{ fmt(aggregates.commissionsAmountUSD) }}
        </span>
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="visibleHeaders" :items-per-page="25"
      v-model:expanded="expanded" item-value="id" show-expand
      hover class="commissions-table"
      @click:row="onRowClick"
      @update:options="onOptions">

      <!-- Индикатор периода -->
      <template #item.period="{ item }">
        <v-icon :color="item.periodFrozen ? 'grey' : 'info'" size="14"
          :title="item.periodFrozen ? 'Период закрыт (заморожен)' : 'Период открыт'">
          mdi-square
        </v-icon>
      </template>

      <template #item.contractNumber="{ item }">
        <span class="text-no-wrap">{{ item.contractNumber || '—' }}</span>
      </template>
      <template #item.contractOpenDate="{ value }">{{ value ? fmtDate(value) : '—' }}</template>
      <template #item.clientName="{ value }">
        <span class="text-no-wrap">{{ value || '—' }}</span>
      </template>
      <template #item.date="{ value }">{{ fmtDate(value) }}</template>
      <template #item.comment="{ value }">{{ value || '—' }}</template>
      <template #item.propertyTitle="{ value }">{{ value || '—' }}</template>
      <template #item.contractTerm="{ value }">{{ value || '—' }}</template>
      <template #item.yearKV="{ value }">{{ value || '—' }}</template>

      <template #item.amount="{ item }">
        <span class="text-no-wrap">{{ fmt(item.amount) }} {{ item.currencySymbol || '' }}</span>
      </template>
      <template #item.amountRUB="{ value }">{{ fmt(value) }} ₽</template>
      <template #item.dsCommissionPercentage="{ value }">
        <span v-if="value != null">{{ value }}%</span>
        <span v-else class="text-medium-emphasis">—</span>
      </template>
      <template #item.commissionsAmountRUB="{ value }">{{ fmt(value) }} ₽</template>
      <template #item.commissionsAmountUSD="{ value }">{{ fmt(value) }} $</template>
      <template #item.netRevenueRUB="{ value }">{{ fmt(value) }} ₽</template>
      <template #item.netRevenueUSD="{ value }">{{ fmt(value) }} $</template>

      <!-- Аккордеон: цепочка выплат -->
      <template #expanded-row="{ columns, item }">
        <tr>
          <td :colspan="columns.length" class="pa-3" style="background: rgba(var(--v-theme-surface-variant), 0.3)">
            <div v-if="!chainCache[item.id]" class="d-flex align-center pa-2">
              <v-progress-circular indeterminate size="20" class="me-2" />
              Загружаю цепочку…
            </div>
            <template v-else>
              <div class="text-subtitle-2 mb-2 d-flex align-center ga-2">
                <v-icon size="18" color="primary">mdi-account-tree</v-icon>
                Цепочка выплат · Прибыль ДС:
                <strong class="ms-1" :class="chainCache[item.id].profitDS >= 0 ? 'text-success' : 'text-error'">
                  {{ fmt(chainCache[item.id].profitDS) }} ₽
                </strong>
              </div>
              <v-table density="compact" class="commissions-chain">
                <thead>
                  <tr>
                    <th>Партнёр</th>
                    <th>Квалификация</th>
                    <th class="text-end">% кв.</th>
                    <th class="text-end">ЛП</th>
                    <th class="text-end">ГП</th>
                    <th class="text-end">Баллы</th>
                    <th class="text-end">Комиссия ₽</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in chainCache[item.id].data" :key="row.id"
                    :class="{ 'font-weight-bold': row.chainOrder === 1 }">
                    <td>{{ row.consultantName || '—' }}</td>
                    <td>
                      <v-chip v-if="row.levelNum" size="x-small" variant="tonal">
                        {{ row.levelNum }} [{{ row.levelTitle }}]
                      </v-chip>
                      <span v-else class="text-medium-emphasis">—</span>
                    </td>
                    <td class="text-end">{{ row.percent }}%</td>
                    <td class="text-end">{{ fmt(row.personalVolume) }}</td>
                    <td class="text-end">{{ fmt(row.groupVolume) }}</td>
                    <td class="text-end">{{ fmt(row.groupBonus) }}</td>
                    <td class="text-end font-weight-medium">{{ fmt(row.amountRUB) }}</td>
                  </tr>
                  <tr v-if="!chainCache[item.id].data.length">
                    <td colspan="7" class="text-center text-medium-emphasis py-2">
                      Цепочка пуста
                    </td>
                  </tr>
                </tbody>
              </v-table>
              <div class="text-caption text-medium-emphasis mt-2">
                Полужирным — прямой партнёр (получатель транзакции).
                Прибыль ДС = Доход без НДС − Σ комиссии цепочки.
              </div>
            </template>
          </td>
        </tr>
      </template>

      <template #no-data><EmptyState message="Транзакции не найдены" icon="mdi-receipt-outline" /></template>
    </v-data-table-server>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import { fmt2 as fmt, fmtDate } from '../../composables/useDesign';

const items = ref([]);
const total = ref(0);
const aggregates = ref(null);
const loading = ref(false);
const page = ref(1);
const perPage = ref(25);
const expanded = ref([]);
const chainCache = ref({});
const supplierOptions = ref([]);

const filters = ref({
  partner: '',
  chainPartner: '',
  client: '',
  contract: '',
  dateFrom: '',
  dateTo: '',
  supplier: null,
  comment: '',
  hideZero: true,
});

const headers = [
  { title: '', key: 'period', width: 30, sortable: false },
  { title: '№ контракта', key: 'contractNumber', width: 130 },
  { title: 'Открыт', key: 'contractOpenDate', width: 110 },
  { title: 'Клиент', key: 'clientName' },
  { title: 'Партнёр', key: 'consultantName' },
  { title: 'Дата', key: 'date', width: 110 },
  { title: 'Комментарий', key: 'comment' },
  { title: 'Свойство', key: 'propertyTitle', width: 120 },
  { title: 'Срок', key: 'contractTerm', width: 80, align: 'end' },
  { title: 'Год КВ', key: 'yearKV', width: 80, align: 'end' },
  { title: 'Транзакция', key: 'amount', align: 'end', width: 130 },
  { title: 'В РУБ', key: 'amountRUB', align: 'end', width: 130 },
  { title: '% DS', key: 'dsCommissionPercentage', align: 'end', width: 80 },
  { title: 'Доход DS RUB', key: 'commissionsAmountRUB', align: 'end', width: 140 },
  { title: 'Доход DS USD', key: 'commissionsAmountUSD', align: 'end', width: 140 },
  { title: 'Без НДС RUB', key: 'netRevenueRUB', align: 'end', width: 130 },
  { title: 'Без НДС USD', key: 'netRevenueUSD', align: 'end', width: 130 },
  { title: '', key: 'data-table-expand', sortable: false, width: 50 },
];

const columnVisible = ref({
  // По умолчанию скрываем менее важные, чтобы таблица помещалась
  propertyTitle: false,
  contractTerm: false,
  yearKV: false,
  netRevenueUSD: false,
  commissionsAmountUSD: false,
});
const visibleHeaders = computed(() => headers.filter(h => columnVisible.value[h.key] !== false));

const activeFilterCount = computed(() => {
  let c = 0;
  if (filters.value.partner) c++;
  if (filters.value.chainPartner) c++;
  if (filters.value.client) c++;
  if (filters.value.contract) c++;
  if (filters.value.dateFrom) c++;
  if (filters.value.dateTo) c++;
  if (filters.value.supplier) c++;
  if (filters.value.comment) c++;
  if (!filters.value.hideZero) c++;
  return c;
});

function resetFilters() {
  filters.value = {
    partner: '', chainPartner: '', client: '', contract: '',
    dateFrom: '', dateTo: '', supplier: null, comment: '',
    hideZero: true,
  };
  loadData();
}

const { debounced: debouncedLoad } = useDebounce(loadData, 400);

function onOptions(opts) {
  page.value = opts.page;
  if (opts.itemsPerPage) perPage.value = opts.itemsPerPage;
  loadData();
}

// Per spec ✅Комиссии §1.3: «при клике на строку транзакции она разворачивается».
function onRowClick(_event, { item }) {
  const id = item?.id;
  if (!id) return;
  const idx = expanded.value.indexOf(id);
  if (idx === -1) expanded.value = [...expanded.value, id];
  else expanded.value = expanded.value.filter(x => x !== id);
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value };
    if (filters.value.partner) params.search = filters.value.partner;
    if (filters.value.chainPartner) params.chain_partner = filters.value.chainPartner;
    if (filters.value.client) params.client = filters.value.client;
    if (filters.value.contract) params.contract_number = filters.value.contract;
    if (filters.value.dateFrom) params.date_from = filters.value.dateFrom;
    if (filters.value.dateTo) params.date_to = filters.value.dateTo;
    if (filters.value.supplier) params.supplier = filters.value.supplier;
    if (filters.value.comment) params.comment = filters.value.comment;
    if (filters.value.hideZero) params.hide_zero = 1;
    const { data } = await api.get('/admin/transactions', { params });
    items.value = data.data || [];
    total.value = data.total || 0;
    aggregates.value = data.aggregates || null;
  } catch {}
  loading.value = false;
}

// Лениво подгружаем цепочку при раскрытии строки.
watch(expanded, async (ids) => {
  for (const id of ids) {
    if (chainCache.value[id]) continue;
    try {
      const { data } = await api.get(`/admin/commissions/chain/${id}`);
      chainCache.value = { ...chainCache.value, [id]: data };
    } catch {}
  }
});

async function loadSuppliers() {
  try {
    const { data } = await api.get('/admin/manual-tx/lookups');
    supplierOptions.value = data.suppliers || [];
  } catch {}
}

onMounted(() => { loadData(); loadSuppliers(); });
</script>

<style scoped>
.commissions-chain :deep(td) { vertical-align: middle; }
.commissions-chain :deep(th) {
  background: rgba(var(--v-theme-surface-variant), 0.5);
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
}
/* Курсор pointer на строках основной таблицы — намёк что строка кликабельна. */
.commissions-table :deep(tbody tr:not(.v-data-table__tr--expanded-content)) {
  cursor: pointer;
}
</style>
