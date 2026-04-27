<template>
  <div>
    <PageHeader title="Транзакции" icon="mdi-swap-horizontal" />

    <v-tabs v-model="tab" color="primary" class="mb-3" density="compact">
      <v-tab value="manual" prepend-icon="mdi-cash-plus">Ручной ввод</v-tab>
      <v-tab value="log" prepend-icon="mdi-history">Журнал</v-tab>
    </v-tabs>

    <v-window v-model="tab">

      <!-- ВКЛАДКА 1: РУЧНОЙ ВВОД -->
      <v-window-item value="manual">
        <!-- Поиск контрактов -->
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
                  density="compact" hide-details rounded clearable variant="outlined"
                  @update:model-value="debouncedSearch" />
              </v-col>
              <v-col cols="12" md="3">
                <v-text-field v-model="filters.clientName" placeholder="ФИО клиента"
                  density="compact" hide-details rounded clearable variant="outlined"
                  @update:model-value="debouncedSearch" />
              </v-col>
              <v-col cols="12" md="2">
                <v-text-field v-model="filters.number" placeholder="№ контракта"
                  density="compact" hide-details rounded clearable variant="outlined"
                  @update:model-value="debouncedSearch" />
              </v-col>
              <v-col cols="12" md="2">
                <v-autocomplete v-model="filters.supplier" :items="lookupSuppliers"
                  placeholder="Поставщик" density="compact" hide-details rounded clearable variant="outlined"
                  @update:model-value="loadContracts" />
              </v-col>
              <v-col cols="12" md="2">
                <v-autocomplete v-model="filters.provider" :items="lookupProviders"
                  placeholder="Провайдер" density="compact" hide-details rounded clearable variant="outlined"
                  @update:model-value="loadContracts" />
              </v-col>
              <v-col cols="12" md="3">
                <v-autocomplete v-model="filters.product" :items="productList" item-title="name" item-value="id"
                  placeholder="Продукт" density="compact" hide-details rounded clearable variant="outlined"
                  @update:model-value="loadContracts" />
              </v-col>
              <v-col cols="12" md="3">
                <v-autocomplete v-model="filters.program" :items="programList" item-title="name" item-value="id"
                  placeholder="Программа" density="compact" hide-details rounded clearable variant="outlined"
                  @update:model-value="loadContracts" />
              </v-col>
              <v-col cols="12" md="auto" class="d-flex align-center">
                <v-btn variant="text" size="small" prepend-icon="mdi-filter-remove" @click="resetContractFilters">
                  Очистить фильтры
                </v-btn>
              </v-col>
            </v-row>
          </v-card-text>

          <v-data-table-server
            :items="contracts" :items-length="contractTotal" :loading="loadingContracts"
            :headers="contractHeaders" :items-per-page="15" item-value="id"
            density="compact"
            @update:options="onContractOpts">
            <template #item.add="{ item }">
              <v-btn icon="mdi-plus-circle" size="small" variant="text" color="primary"
                title="Добавить в черновики"
                @click="addContractToDrafts(item.id)" />
            </template>
            <template #item.amount="{ item }">{{ fmt2(item.amount) }} {{ item.currencySymbol || '' }}</template>
            <template #item.openDate="{ value }">{{ fmtDate(value) }}</template>
            <template #no-data><EmptyState message="Контракты не найдены" /></template>
          </v-data-table-server>
        </v-card>

        <!-- Рабочая зона черновиков -->
        <v-card class="mb-3">
          <v-card-title class="d-flex align-center ga-3 flex-wrap">
            <span class="text-subtitle-1">
              <v-icon size="20" class="mr-1">mdi-pencil</v-icon>
              Черновики транзакций
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
                <th style="width:32px"></th>
                <th>№</th>
                <th>Клиент</th>
                <th v-if="showProduct">Продукт</th>
                <th v-if="showProduct">Программа</th>
                <th v-if="showProduct">Поставщик</th>
                <th style="min-width:140px">Дата</th>
                <th style="min-width:160px">Комментарий</th>
                <th style="min-width:130px">Параметр</th>
                <th v-if="showExtra" style="min-width:110px">Год КВ</th>
                <th class="text-end" style="min-width:140px">Транзакция</th>
                <th style="min-width:90px">Валюта</th>
                <th class="text-end" style="min-width:80px">% ДС</th>
                <th style="width:50px">Изменить</th>
                <th class="text-end">Доход ДС</th>
                <th class="text-end">Без НДС, RUB</th>
                <th class="text-end">Без НДС, USD</th>
                <th>Партнёр</th>
                <th class="text-end">Прибыль ДС</th>
                <th style="width:48px"></th>
              </tr>
            </thead>
            <tbody>
              <!-- Строка-итог -->
              <tr class="tx-totals-row">
                <td></td>
                <td colspan="5" v-if="showProduct"></td>
                <td colspan="2" v-if="!showProduct"></td>
                <td class="text-success">{{ totals.maxDate || '—' }}</td>
                <td></td>
                <td></td>
                <td v-if="showExtra"></td>
                <td class="text-end text-success font-weight-bold">{{ fmt2(totals.amount) }}</td>
                <td class="text-success">{{ totals.currencySymbol || 'RUB' }}</td>
                <td></td>
                <td></td>
                <td class="text-end text-success font-weight-bold">{{ fmt2(totals.incomeDS) }} RUB</td>
                <td class="text-end text-success font-weight-bold">{{ fmt2(totals.noVatRub) }} RUB</td>
                <td class="text-end text-success font-weight-bold">{{ fmt2(totals.noVatUsd) }} USD</td>
                <td></td>
                <td class="text-end text-success font-weight-bold">{{ fmt2(totals.profit) }} RUB</td>
                <td></td>
              </tr>

              <template v-for="d in drafts" :key="d.id">
                <tr :class="{ 'tx-row-ready': d.preview?.ready }">
                  <td>
                    <v-icon size="20" color="primary">mdi-calculator</v-icon>
                  </td>
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
                  <td class="text-end">
                    <v-text-field :model-value="d.amount" type="number" density="compact" hide-details variant="plain"
                      reverse @update:model-value="v => patchField(d, 'amount', v)" />
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
                  <td class="text-center">
                    <v-btn icon="mdi-pencil-outline" size="x-small" variant="text"
                      :disabled="!d.productId" :title="d.productId ? 'Изменить % ДС' : 'Нет продукта'"
                      @click="openRateModal(d)" />
                  </td>
                  <td class="text-end text-no-wrap">
                    <template v-if="showExtra && d.customCommission">
                      <v-text-field :model-value="d.dsCommissionAbsolute" type="number" density="compact" hide-details variant="plain"
                        style="max-width:120px; display:inline-block"
                        reverse @update:model-value="v => patchField(d, 'dsCommissionAbsolute', v)" />
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
                    <span v-if="d.preview?.ready">{{ fmt2(d.preview.amountNoVatUSD) }} USD</span>
                    <span v-else class="text-medium-emphasis">—</span>
                  </td>
                  <td>
                    <a v-if="d.preview?.chain?.length" href="#" class="text-decoration-none text-primary"
                      @click.prevent="toggleChain(d.id)">
                      Цепочка партнёров
                      <v-icon size="14">{{ chainExpanded[d.id] ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                    </a>
                    <span v-else class="text-no-wrap text-medium-emphasis">{{ d.consultantName || '—' }}</span>
                  </td>
                  <td class="text-end text-no-wrap">
                    <span v-if="d.preview?.ready" class="font-weight-bold">{{ fmt2(d.preview.profitDS) }} RUB</span>
                    <span v-else class="text-medium-emphasis">—</span>
                  </td>
                  <td>
                    <v-btn icon="mdi-trash-can-outline" size="x-small" variant="text" color="error"
                      @click="removeDraft(d)" />
                  </td>
                </tr>

                <!-- Развёрнутая цепочка партнёров -->
                <template v-if="chainExpanded[d.id] && d.preview?.chain?.length">
                  <tr v-for="(row, idx) in d.preview.chain" :key="'chain-' + d.id + '-' + idx"
                    class="tx-chain-row" :class="{ 'font-weight-bold': row.isDirect }">
                    <td colspan="13"></td>
                    <td colspan="3" class="text-end pe-3">
                      <span class="text-medium-emphasis me-3">ЛП</span>
                      <span class="me-3">{{ fmt2(row.lp) }}</span>
                      <span class="text-medium-emphasis me-3">Баллы</span>
                      <span>{{ fmt2(row.points) }}</span>
                    </td>
                    <td>{{ row.name }}</td>
                    <td class="text-end text-no-wrap">{{ fmt2(row.sum) }} RUB</td>
                    <td></td>
                  </tr>
                </template>

                <!-- Своя комиссия в доп. настройках -->
                <tr v-if="showExtra" class="tx-extra-row">
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
            <v-btn color="primary" :disabled="!calculableIds.length || calculating" prepend-icon="mdi-calculator"
              :loading="calculating" @click="calcAll" size="large">
              Рассчитать транзакции
              <v-chip v-if="dirtyCount" size="x-small" color="white" variant="elevated" class="ms-2">
                {{ dirtyCount }}
              </v-chip>
            </v-btn>
            <v-btn color="success" :disabled="!fixableIds.length || fixing" prepend-icon="mdi-content-save"
              :loading="fixing" @click="fixAll" size="large" variant="outlined">
              Зафиксировать транзакции
              <v-chip v-if="fixableIds.length" size="x-small" color="success" variant="elevated" class="ms-2">
                {{ fixableIds.length }}
              </v-chip>
            </v-btn>
            <v-spacer />
            <v-btn v-if="drafts.length" color="error" variant="text" prepend-icon="mdi-trash-can-outline" @click="clearAll">
              Очистить все транзакции
            </v-btn>
          </v-card-actions>

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
      </v-window-item>

      <!-- ВКЛАДКА 2: ЖУРНАЛ ЗАФИКСИРОВАННЫХ ТРАНЗАКЦИЙ -->
      <v-window-item value="log">
        <v-card class="mb-3 pa-3">
          <div class="d-flex ga-2 flex-wrap align-center">
            <v-text-field v-model="logSearch" placeholder="Поиск по ID..."
              rounded prepend-inner-icon="mdi-magnify" clearable hide-details style="max-width:300px"
              @update:model-value="debouncedLogLoad" />
            <v-text-field v-model="logMonth" type="month" label="Месяц"
              hide-details style="max-width:200px" @update:model-value="loadLog" />
            <v-chip v-if="logActiveFilters > 0" size="small" color="info" variant="tonal" class="ml-1">
              {{ logActiveFilters }} {{ logActiveFilters === 1 ? 'фильтр' : 'фильтра' }}
            </v-chip>
            <v-btn v-if="logActiveFilters > 0" size="small" variant="text" color="secondary"
              prepend-icon="mdi-filter-remove" @click="resetLogFilters">Сбросить</v-btn>
            <v-spacer />
            <ColumnVisibilityMenu :headers="logHeaders" v-model:visible="logColumnVisible" storage-key="transactions-cols" />
          </div>
        </v-card>

        <DataTableWrapper
          :items="logItems"
          :items-length="logTotal"
          :loading="logLoading"
          :headers="logVisibleHeaders"
          :items-per-page="25"
          server-side
          empty-icon="mdi-swap-horizontal-variant"
          empty-message="Транзакции не найдены"
          @update:options="onLogOptions">
          <template #item.amount="{ item }">{{ fmt2(item.amount) }} {{ item.currencySymbol || '' }}</template>
          <template #item.amountRUB="{ value }">{{ fmt2(value) }}</template>
          <template #item.amountUSD="{ value }">{{ fmt2(value) }}</template>
          <template #item.date="{ value }">{{ fmtDate(value) }}</template>
          <template #item.chat="{ item }">
            <StartChatButton :partner-id="item.consultantId || item.consultant" :partner-name="item.consultantName"
              context-type="Транзакция" :context-id="item.id" :context-label="'#' + item.id" />
          </template>
        </DataTableWrapper>
      </v-window-item>

    </v-window>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="4000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import DataTableWrapper from '../../components/DataTableWrapper.vue';
import StartChatButton from '../../components/StartChatButton.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import { fmt2, fmtDate } from '../../composables/useDesign';

const tab = ref('manual');
const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

// === Manual entry: contracts top zone ===
const contracts = ref([]);
const contractTotal = ref(0);
const loadingContracts = ref(false);
const contractPage = ref(1);
const contractPerPage = ref(15);
const filters = ref({
  consultantName: '', clientName: '', number: '',
  supplier: null, provider: null, product: null, program: null,
});
const productList = ref([]);
const programList = ref([]);
const lookupSuppliers = ref([]);
const lookupProviders = ref([]);
const currencyOptions = ref([]);
const productRates = ref([]);

function resetContractFilters() {
  filters.value = { consultantName: '', clientName: '', number: '', supplier: null, provider: null, product: null, program: null };
  loadContracts();
}

const contractHeaders = computed(() => ([
  { title: '', key: 'add', sortable: false, width: 50 },
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
]));

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
    if (filters.value.program) params.program = filters.value.program;
    if (filters.value.supplier) params.supplier = filters.value.supplier;
    if (filters.value.provider) params.provider = filters.value.provider;
    const { data } = await api.get('/admin/manual-tx/contracts', { params });
    contracts.value = data.data;
    contractTotal.value = data.total;
  } catch {
    notify('Ошибка загрузки контрактов', 'error');
  }
  loadingContracts.value = false;
}

// === Manual entry: drafts ===
const drafts = ref([]);
const adding = ref(false);
const fixing = ref(false);
const showProduct = ref(false);
const showExtra = ref(false);
const chainExpanded = ref({});

function toggleChain(id) {
  chainExpanded.value[id] = !chainExpanded.value[id];
}

const totals = computed(() => {
  const ready = drafts.value.filter(d => d.preview?.ready);
  const maxDate = drafts.value.map(d => d.date).filter(Boolean).sort().pop();
  const sym = drafts.value[0]?.currencySymbol || 'RUB';
  return {
    maxDate: maxDate ? fmtDate(maxDate) : null,
    currencySymbol: sym,
    amount: drafts.value.reduce((s, d) => s + Number(d.amount || 0), 0),
    incomeDS: ready.reduce((s, d) => s + Number(d.preview.incomeDS || 0), 0),
    noVatRub: ready.reduce((s, d) => s + Number(d.preview.amountNoVat || 0), 0),
    noVatUsd: ready.reduce((s, d) => s + Number(d.preview.amountNoVatUSD || 0), 0),
    profit: ready.reduce((s, d) => s + Number(d.preview.profitDS || 0), 0),
  };
});

const parameterOptions = [
  { title: 'Стандарт', value: 'standard' },
  { title: 'Апфронт', value: 'upfront' },
  { title: 'Левел', value: 'level' },
  { title: 'MF', value: 'mf' },
];
const yearKVOptions = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

async function loadDrafts() {
  try {
    const { data } = await api.get('/admin/manual-tx/drafts');
    drafts.value = data.data;
  } catch {}
}

async function addContractToDrafts(contractId) {
  adding.value = true;
  try {
    await api.post('/admin/manual-tx/drafts', { contractIds: [contractId] });
    await loadDrafts();
    notify('Добавлено в черновики');
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
}

// Готовые к расчёту: есть сумма и дата.
const calculableIds = computed(() =>
  drafts.value.filter(d => d.amount && d.date).map(d => d.id)
);
// Готовые к фиксации: уже посчитано превью.
const fixableIds = computed(() =>
  drafts.value.filter(d => d.amount && d.date && d.preview?.ready).map(d => d.id)
);
// Сколько строк ждут расчёта (есть данные, но превью пустое).
const dirtyCount = computed(() =>
  drafts.value.filter(d => d.amount && d.date && !d.preview?.ready).length
);

const calculating = ref(false);
async function calcAll() {
  calculating.value = true;
  try {
    const { data } = await api.post('/admin/manual-tx/calc', {});
    if (data.calculated) notify(`Рассчитано: ${data.calculated}`);
    if (data.skipped) notify(`Пропущено (нет суммы/даты): ${data.skipped}`, 'warning');
    await loadDrafts();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка расчёта', 'error');
  }
  calculating.value = false;
}

async function fixAll() {
  if (!fixableIds.value.length) return;
  fixing.value = true;
  try {
    const { data } = await api.post('/admin/manual-tx/fix', { ids: fixableIds.value });
    if (data.fixed?.length) notify(`Зафиксировано: ${data.fixed.length}`);
    if (data.errors?.length) notify(`Ошибки: ${data.errors.length}`, 'warning');
    await loadDrafts();
    if (data.fixed?.length) loadLog();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка фиксации', 'error');
  }
  fixing.value = false;
}

const cl = computed(() => ({
  amounts: drafts.value.length > 0 && drafts.value.every(d => Number(d.amount) > 0),
  dates: drafts.value.length > 0 && drafts.value.every(d => !!d.date),
  calculated: drafts.value.length > 0 && drafts.value.every(d => d.preview?.ready),
}));

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

// === Журнал зафиксированных ===
const logItems = ref([]);
const logTotal = ref(0);
const logLoading = ref(false);
const logSearch = ref('');
const logMonth = ref(new Date().toISOString().slice(0, 7));
const logPage = ref(1);
const logPerPage = ref(25);
const defaultMonth = new Date().toISOString().slice(0, 7);

const logHeaders = [
  { title: 'ID', key: 'id', width: 60 },
  { title: 'Контракт', key: 'contract', width: 130 },
  { title: 'Сумма', key: 'amount', width: 140 },
  { title: 'Сумма (руб)', key: 'amountRUB', align: 'end', width: 140 },
  { title: 'Сумма (USD)', key: 'amountUSD', align: 'end', width: 140 },
  { title: 'Дата', key: 'date', width: 120 },
  { title: '', key: 'chat', sortable: false, width: 50 },
];

const logColumnVisible = ref({});
const logVisibleHeaders = computed(() => logHeaders.filter(h => logColumnVisible.value[h.key] !== false));

const logActiveFilters = computed(() => {
  let c = 0;
  if (logSearch.value) c++;
  if (logMonth.value && logMonth.value !== defaultMonth) c++;
  return c;
});

function resetLogFilters() {
  logSearch.value = '';
  logMonth.value = defaultMonth;
  loadLog();
}

const { debounced: debouncedLogLoad } = useDebounce(loadLog, 400);

function onLogOptions(opts) {
  logPage.value = opts.page;
  if (opts.itemsPerPage) logPerPage.value = opts.itemsPerPage;
  loadLog();
}

async function loadLog() {
  logLoading.value = true;
  try {
    const params = { page: logPage.value, per_page: logPerPage.value };
    if (logSearch.value) params.search = logSearch.value;
    if (logMonth.value) params.month = logMonth.value;
    const { data } = await api.get('/admin/transactions', { params });
    logItems.value = data.data;
    logTotal.value = data.total;
  } catch {}
  logLoading.value = false;
}

onMounted(async () => {
  await Promise.all([loadContracts(), loadDrafts(), loadLog()]);
  try {
    const [products, formData, lookups] = await Promise.all([
      api.get('/admin/products', { params: { per_page: 1000, active: true } }).catch(() => ({ data: { data: [] } })),
      api.get('/admin/transaction-import/form-data').catch(() => ({ data: { currencies: [] } })),
      api.get('/admin/manual-tx/lookups').catch(() => ({ data: { suppliers: [], providers: [] } })),
    ]);
    productList.value = (products.data?.data || []).map(p => ({ id: p.id, name: p.name }));
    currencyOptions.value = (formData.data?.currencies || []).map(c => ({
      id: c.id, symbol: c.symbol || c.name, name: c.name,
    }));
    lookupSuppliers.value = lookups.data?.suppliers || [];
    lookupProviders.value = lookups.data?.providers || [];
  } catch {}
});
</script>

<style scoped>
.manual-tx-table :deep(td) { vertical-align: middle; }
.manual-tx-table :deep(th) { white-space: nowrap; font-weight: 600; }
.tx-row-ready { background: rgba(76, 175, 80, 0.04); }
.tx-extra-row td { background: rgba(0, 0, 0, 0.02); border-top: 0; padding: 4px 8px !important; }
</style>
