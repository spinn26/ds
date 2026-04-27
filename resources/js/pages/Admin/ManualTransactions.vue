<template>
  <div>
    <PageHeader title="Ручной ввод транзакций" icon="mdi-cash-plus" />

    <!-- ВЕРХ: поиск контрактов -->
    <v-card class="mb-3">
      <v-card-title class="text-subtitle-1 d-flex align-center ga-2">
        <v-icon size="20">mdi-file-document-multiple</v-icon>
        Контракты
        <v-chip v-if="contractTotal" size="x-small" color="primary" variant="tonal">{{ contractTotal }}</v-chip>
      </v-card-title>

      <v-card-text class="pt-0">
        <v-row dense>
          <v-col cols="12" md="3">
            <v-text-field v-model="filters.consultantName" placeholder="ФИО консультанта"
              prepend-inner-icon="mdi-account-tie" density="compact" hide-details rounded clearable
              @update:model-value="debouncedSearch" />
          </v-col>
          <v-col cols="12" md="3">
            <v-text-field v-model="filters.clientName" placeholder="ФИО клиента"
              prepend-inner-icon="mdi-account" density="compact" hide-details rounded clearable
              @update:model-value="debouncedSearch" />
          </v-col>
          <v-col cols="12" md="2">
            <v-text-field v-model="filters.number" placeholder="№ контракта"
              prepend-inner-icon="mdi-pound" density="compact" hide-details rounded clearable
              @update:model-value="debouncedSearch" />
          </v-col>
          <v-col cols="12" md="4">
            <v-autocomplete v-model="filters.product" :items="productList" item-title="name" item-value="id"
              placeholder="Продукт" density="compact" hide-details rounded clearable
              @update:model-value="loadContracts" />
          </v-col>
        </v-row>
      </v-card-text>

      <v-data-table-server
        v-model="selectedContractIds"
        show-select
        :items="contracts" :items-length="contractTotal" :loading="loadingContracts"
        :headers="contractHeaders" :items-per-page="15" item-value="id"
        density="compact"
        @update:options="onContractOpts">
        <template #item.amount="{ item }">{{ fmt(item.amount) }} {{ item.currencySymbol || '' }}</template>
        <template #item.openDate="{ value }">{{ fmtDate(value) }}</template>
        <template #no-data><EmptyState message="Контракты не найдены" /></template>
      </v-data-table-server>

      <v-card-actions>
        <v-btn color="primary" :disabled="!selectedContractIds.length" prepend-icon="mdi-plus"
          @click="addToDrafts" :loading="adding">
          Добавить в черновики ({{ selectedContractIds.length }})
        </v-btn>
        <v-spacer />
      </v-card-actions>
    </v-card>

    <!-- НИЗ: рабочая зона черновиков -->
    <v-card class="mb-3">
      <v-card-title class="d-flex align-center ga-3 flex-wrap">
        <span class="text-subtitle-1">
          <v-icon size="20" class="mr-1">mdi-pencil</v-icon>
          Транзакции
          <v-chip size="x-small" color="warning" variant="tonal" class="ml-1">{{ drafts.length }}</v-chip>
        </span>
        <v-spacer />
        <v-switch v-model="showProduct" label="Показать продукт" hide-details density="compact" color="primary" />
        <v-switch v-model="showExtra" label="Показать доп. настройки" hide-details density="compact" color="primary" />
      </v-card-title>

      <v-card-text v-if="!drafts.length" class="text-center text-medium-emphasis py-4">
        Выберите контракты сверху и нажмите «Добавить в черновики»
      </v-card-text>

      <v-table v-else density="compact" class="manual-tx-table">
        <thead>
          <tr>
            <th style="width:36px"><v-checkbox v-model="allSelected" hide-details density="compact" :indeterminate="someSelected" /></th>
            <th>№</th>
            <th>Клиент</th>
            <th v-if="showProduct">Продукт</th>
            <th v-if="showProduct">Программа</th>
            <th v-if="showProduct">Поставщик</th>
            <th style="min-width:140px">Дата</th>
            <th style="min-width:160px">Комментарий</th>
            <th style="min-width:130px">Параметр</th>
            <th v-if="showExtra" style="min-width:110px">Год КВ</th>
            <th style="min-width:140px">Транзакция</th>
            <th style="min-width:90px">Валюта</th>
            <th class="text-end" style="min-width:80px">% ДС</th>
            <th style="width:50px"></th>
            <th class="text-end">Доход ДС</th>
            <th class="text-end">Без НДС</th>
            <th class="text-end">НДС</th>
            <th>Партнёр</th>
            <th class="text-end">Прибыль ДС</th>
            <th style="width:48px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="d in drafts" :key="d.id" :class="{ 'tx-row-ready': d.preview?.ready }">
            <td><v-checkbox v-model="selectedDraftIds" :value="d.id" hide-details density="compact" /></td>
            <td class="text-no-wrap">{{ d.contractNumber || '—' }}</td>
            <td class="text-no-wrap">{{ d.clientName || '—' }}</td>
            <td v-if="showProduct" class="text-no-wrap">{{ d.productName || '—' }}</td>
            <td v-if="showProduct" class="text-no-wrap">{{ d.programName || '—' }}</td>
            <td v-if="showProduct" class="text-no-wrap">{{ d.supplierName || '—' }}</td>
            <td>
              <v-text-field :model-value="d.date" type="date" density="compact" hide-details variant="plain"
                @update:model-value="v => patchField(d, 'date', v)" />
            </td>
            <td>
              <v-text-field :model-value="d.comment" placeholder="Введите" density="compact" hide-details variant="plain"
                @update:model-value="v => patchField(d, 'comment', v)" />
            </td>
            <td>
              <v-select :model-value="d.parameter" :items="parameterOptions" density="compact" hide-details variant="plain"
                @update:model-value="v => patchField(d, 'parameter', v)" />
            </td>
            <td v-if="showExtra">
              <v-select :model-value="d.yearKV" :items="yearKVOptions" density="compact" hide-details variant="plain" clearable
                @update:model-value="v => patchField(d, 'yearKV', v)" />
            </td>
            <td>
              <v-text-field :model-value="d.amount" type="number" density="compact" hide-details variant="plain"
                @update:model-value="v => patchField(d, 'amount', v)" />
            </td>
            <td>
              <v-select :model-value="d.currencyId" :items="currencyOptions" item-title="symbol" item-value="id"
                density="compact" hide-details variant="plain"
                @update:model-value="v => patchField(d, 'currency', v)" />
            </td>
            <td class="text-end">
              <span v-if="d.preview?.ready">{{ fmt2(d.preview.dsCommissionPercentage) }}%</span>
              <span v-else class="text-medium-emphasis">—</span>
            </td>
            <td>
              <v-btn icon="mdi-pencil-outline" size="x-small" variant="text"
                :disabled="!d.productId" :title="d.productId ? 'Изменить % ДС' : 'Нет продукта'"
                @click="openRateModal(d)" />
            </td>
            <td class="text-end text-no-wrap">
              <template v-if="showExtra && d.customCommission">
                <v-text-field :model-value="d.dsCommissionAbsolute" type="number" density="compact" hide-details variant="plain"
                  style="max-width:120px; display:inline-block"
                  @update:model-value="v => patchField(d, 'dsCommissionAbsolute', v)" />
                RUB
              </template>
              <template v-else>
                <span v-if="d.preview?.ready">{{ fmt2(d.preview.incomeDS) }} RUB</span>
                <span v-else class="text-medium-emphasis">—</span>
              </template>
            </td>
            <td class="text-end text-no-wrap">
              <span v-if="d.preview?.ready">{{ fmt2(d.preview.amountNoVat) }} RUB</span>
              <span v-else class="text-medium-emphasis">—</span>
            </td>
            <td class="text-end text-no-wrap">
              <span v-if="d.preview?.ready">{{ fmt2(d.preview.vat) }} RUB</span>
              <span v-else class="text-medium-emphasis">—</span>
            </td>
            <td>
              <v-menu open-on-hover open-delay="200" location="bottom start" :close-on-content-click="false">
                <template #activator="{ props }">
                  <span v-bind="props" class="text-no-wrap" :class="{ 'text-primary': d.preview?.chain }">
                    <v-icon size="14" class="mr-1">mdi-account-tree</v-icon>
                    {{ d.consultantName || '—' }}
                  </span>
                </template>
                <v-card v-if="d.preview?.chain?.length" min-width="380" class="pa-2">
                  <div class="text-caption font-weight-bold mb-1">Цепочка партнёров</div>
                  <v-table density="compact">
                    <thead>
                      <tr>
                        <th class="text-left">Партнёр</th>
                        <th class="text-end">ЛП</th>
                        <th class="text-end">Баллы</th>
                        <th class="text-end">Σ, RUB</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="row in d.preview.chain" :key="row.consultantId" :class="{ 'font-weight-bold': row.isDirect }">
                        <td>{{ row.name }}</td>
                        <td class="text-end">{{ fmt2(row.lp) }}</td>
                        <td class="text-end">{{ fmt2(row.points) }}</td>
                        <td class="text-end">{{ fmt2(row.sum) }} RUB</td>
                      </tr>
                    </tbody>
                  </v-table>
                </v-card>
              </v-menu>
            </td>
            <td class="text-end text-no-wrap">
              <span v-if="d.preview?.ready">{{ fmt2(d.preview.profitDS) }} RUB</span>
              <span v-else class="text-medium-emphasis">—</span>
            </td>
            <td>
              <v-btn icon="mdi-trash-can-outline" size="x-small" variant="text" color="error"
                @click="removeDraft(d)" />
            </td>
          </tr>
          <!-- Своя комиссия — тогглы рядом со строкой -->
          <template v-if="showExtra">
            <tr v-for="d in drafts" :key="'opts-' + d.id" class="tx-extra-row">
              <td colspan="20" class="pa-2">
                <v-checkbox :model-value="d.customCommission"
                  :label="'Своя комиссия для ' + (d.contractNumber || '—') + ' (Брокер+ и подобные)'"
                  hide-details density="compact" color="warning"
                  @update:model-value="v => patchField(d, 'customCommission', v)" />
              </td>
            </tr>
          </template>
        </tbody>
      </v-table>

      <v-card-actions class="d-flex flex-wrap ga-2">
        <v-btn color="success" :disabled="!fixableIds.length || fixing" prepend-icon="mdi-content-save"
          :loading="fixing" @click="fixSelected">
          Зафиксировать транзакции ({{ fixableIds.length }})
        </v-btn>
        <v-spacer />
        <v-btn v-if="drafts.length" color="error" variant="text" prepend-icon="mdi-trash-can-outline" @click="clearAll">
          Очистить все транзакции
        </v-btn>
      </v-card-actions>

      <!-- Чек-лист готовности -->
      <v-card-text v-if="drafts.length" class="pt-0">
        <div class="text-caption text-medium-emphasis mb-1">Готовность:</div>
        <div class="d-flex flex-wrap ga-2">
          <v-chip size="small" :color="cl.amounts ? 'success' : 'default'"
            :prepend-icon="cl.amounts ? 'mdi-check-circle' : 'mdi-checkbox-blank-circle-outline'">
            Введены суммы
          </v-chip>
          <v-chip size="small" :color="cl.dates ? 'success' : 'default'"
            :prepend-icon="cl.dates ? 'mdi-check-circle' : 'mdi-checkbox-blank-circle-outline'">
            Введены даты
          </v-chip>
          <v-chip size="small" :color="cl.calculated ? 'success' : 'warning'"
            :prepend-icon="cl.calculated ? 'mdi-check-circle' : 'mdi-checkbox-blank-circle-outline'">
            Рассчитаны комиссии
          </v-chip>
        </div>
      </v-card-text>
    </v-card>

    <!-- Модалка «Изменить % ДС» -->
    <v-dialog v-model="rateModal" max-width="540">
      <v-card v-if="rateContext">
        <v-card-title>Изменить комиссию ДС в контракте {{ rateContext.contractNumber || '' }}</v-card-title>
        <v-card-text>
          <v-alert v-if="!productRates.length" type="info" variant="tonal" density="compact">
            Для продукта нет настроенных тарифов в справочнике dsCommission.
          </v-alert>
          <v-radio-group v-else v-model="rateChoice">
            <v-radio v-for="r in productRates" :key="r.id" :value="r.comission">
              <template #label>
                <div>
                  <div class="font-weight-medium">{{ r.comission }}%</div>
                  <div class="text-caption text-medium-emphasis">{{ r.programName || '—' }}</div>
                </div>
              </template>
            </v-radio>
          </v-radio-group>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="rateModal = false">Отмена</v-btn>
          <v-btn color="primary" :disabled="!rateChoice" @click="applyRate">Сохранить комиссии ДС</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="4000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import { fmt2, fmtDate } from '../../composables/useDesign';

const fmt = fmt2;

// Контракты — верхняя зона
const contracts = ref([]);
const contractTotal = ref(0);
const loadingContracts = ref(false);
const selectedContractIds = ref([]);
const contractPage = ref(1);
const contractPerPage = ref(15);
const filters = ref({ consultantName: '', clientName: '', number: '', product: null });

const contractHeaders = computed(() => {
  const cols = [
    { title: 'Номер', key: 'number', width: 130 },
    { title: 'Клиент', key: 'clientName' },
    { title: 'Партнёр', key: 'consultantName' },
    { title: 'Открыт', key: 'openDate', width: 110 },
    { title: 'Срок', key: 'term', width: 70 },
    { title: 'Поставщик', key: 'supplierName' },
    { title: 'Провайдер', key: 'providerName' },
    { title: 'Продукт', key: 'productName' },
    { title: 'Программа', key: 'programName' },
    { title: 'Сумма', key: 'amount', align: 'end', width: 140 },
  ];
  return cols;
});

// Черновики — нижняя зона
const drafts = ref([]);
const selectedDraftIds = ref([]);
const adding = ref(false);
const fixing = ref(false);
const showProduct = ref(false);
const showExtra = ref(false);

const productList = ref([]);
const currencyOptions = ref([]);
const productRates = ref([]);

const parameterOptions = [
  { title: 'Стандарт', value: 'standard' },
  { title: 'Апфронт', value: 'upfront' },
  { title: 'Левел', value: 'level' },
  { title: 'MF', value: 'mf' },
];
const yearKVOptions = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

const { debounced: debouncedSearch } = useDebounce(loadContracts, 400);

function onContractOpts(opts) {
  contractPage.value = opts.page;
  if (opts.itemsPerPage) contractPerPage.value = opts.itemsPerPage;
  loadContracts();
}

async function loadContracts() {
  loadingContracts.value = true;
  try {
    const params = { page: contractPage.value, per_page: contractPerPage.value };
    if (filters.value.consultantName) params.consultantName = filters.value.consultantName;
    if (filters.value.clientName) params.clientName = filters.value.clientName;
    if (filters.value.number) params.number = filters.value.number;
    if (filters.value.product) params.product = filters.value.product;
    const { data } = await api.get('/admin/manual-tx/contracts', { params });
    contracts.value = data.data;
    contractTotal.value = data.total;
  } catch (e) {
    notify('Ошибка загрузки контрактов', 'error');
  }
  loadingContracts.value = false;
}

async function loadDrafts() {
  try {
    const { data } = await api.get('/admin/manual-tx/drafts');
    drafts.value = data.data;
  } catch {}
}

async function addToDrafts() {
  if (!selectedContractIds.value.length) return;
  adding.value = true;
  try {
    await api.post('/admin/manual-tx/drafts', { contractIds: selectedContractIds.value });
    selectedContractIds.value = [];
    await loadDrafts();
    notify('Контракты добавлены в черновики');
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  adding.value = false;
}

const debouncedPatch = useDebounce((draft, payload) => doPatch(draft, payload), 500).debounced;

function patchField(draft, field, value) {
  draft[field] = value;
  debouncedPatch(draft, { [field]: value });
}

async function doPatch(draft, payload) {
  try {
    const { data } = await api.patch('/admin/manual-tx/drafts/' + draft.id, payload);
    Object.assign(draft, data);
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка сохранения', 'error');
  }
}

async function removeDraft(draft) {
  await api.delete('/admin/manual-tx/drafts/' + draft.id);
  drafts.value = drafts.value.filter(d => d.id !== draft.id);
}

async function clearAll() {
  if (!confirm('Удалить все черновики?')) return;
  await api.delete('/admin/manual-tx/drafts');
  drafts.value = [];
  selectedDraftIds.value = [];
}

const fixableIds = computed(() =>
  drafts.value
    .filter(d => selectedDraftIds.value.includes(d.id) && d.amount && d.date && d.preview?.ready)
    .map(d => d.id)
);

async function fixSelected() {
  if (!fixableIds.value.length) return;
  fixing.value = true;
  try {
    const { data } = await api.post('/admin/manual-tx/fix', { ids: fixableIds.value });
    if (data.fixed?.length) notify(`Зафиксировано: ${data.fixed.length}`);
    if (data.errors?.length) notify(`Ошибки: ${data.errors.length}`, 'warning');
    selectedDraftIds.value = [];
    await loadDrafts();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка фиксации', 'error');
  }
  fixing.value = false;
}

// Чек-лист
const cl = computed(() => ({
  amounts: drafts.value.length > 0 && drafts.value.every(d => Number(d.amount) > 0),
  dates: drafts.value.length > 0 && drafts.value.every(d => !!d.date),
  calculated: drafts.value.length > 0 && drafts.value.every(d => d.preview?.ready),
}));

// Все/часть выбраны
const allSelected = computed({
  get: () => drafts.value.length > 0 && selectedDraftIds.value.length === drafts.value.length,
  set: (v) => { selectedDraftIds.value = v ? drafts.value.map(d => d.id) : []; },
});
const someSelected = computed(() =>
  selectedDraftIds.value.length > 0 && selectedDraftIds.value.length < drafts.value.length
);

// Модалка «Изменить % ДС»
const rateModal = ref(false);
const rateContext = ref(null);
const rateChoice = ref(null);

async function openRateModal(d) {
  if (!d.productId) return;
  rateContext.value = d;
  rateChoice.value = d.dsCommissionPercentage || null;
  try {
    const { data } = await api.get(`/admin/manual-tx/products/${d.productId}/rates`);
    productRates.value = data.rates || [];
  } catch { productRates.value = []; }
  rateModal.value = true;
}

async function applyRate() {
  if (!rateContext.value || !rateChoice.value) return;
  await doPatch(rateContext.value, {
    dsCommissionPercentage: Number(rateChoice.value),
    commissionOverride: true,
  });
  rateModal.value = false;
}

onMounted(async () => {
  await loadContracts();
  await loadDrafts();
  // Справочники для фильтров и черновиков
  try {
    const [products, formData] = await Promise.all([
      api.get('/admin/products', { params: { per_page: 1000, active: true } }).catch(() => ({ data: { data: [] } })),
      api.get('/admin/transaction-import/form-data').catch(() => ({ data: { currencies: [] } })),
    ]);
    productList.value = (products.data?.data || []).map(p => ({ id: p.id, name: p.name }));
    currencyOptions.value = (formData.data?.currencies || []).map(c => ({
      id: c.id, symbol: c.symbol || c.name, name: c.name,
    }));
  } catch {}
});
</script>

<style scoped>
.manual-tx-table :deep(td) { vertical-align: middle; }
.manual-tx-table :deep(th) { white-space: nowrap; font-weight: 600; }
.tx-row-ready { background: rgba(76, 175, 80, 0.04); }
.tx-extra-row td { background: rgba(0, 0, 0, 0.02); border-top: 0; padding: 4px 8px !important; }
</style>
