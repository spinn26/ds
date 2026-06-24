<template>
  <div>
    <PageHeader title="Комиссии" icon="mdi-receipt" :count="total">
      <template #actions>
        <ColumnVisibilityMenu :headers="headers"
          v-model:visible="columnVisible"
          v-model:order="columnOrder"
          storage-key="commissions-cols" />
      </template>
    </PageHeader>

    <!-- Компактный layout (как в Контрактах/Клиентах): основные поля в одной
         flex-строке, диапазон дат + редкие фильтры — за тогглом «Ещё». -->
    <v-card class="mb-3 pa-3">
      <div class="d-flex flex-wrap ga-2 align-center">
        <v-text-field v-model="filters.partner" placeholder="ФИО Партнёра"
          density="compact" variant="outlined" hide-details clearable
          prepend-inner-icon="mdi-magnify"
          style="max-width: 220px; flex: 1 1 180px"
          @update:model-value="debouncedLoad" />
        <v-text-field v-model="filters.chainPartner" placeholder="Партнёр в цепочке"
          density="compact" variant="outlined" hide-details clearable
          prepend-inner-icon="mdi-account-tree"
          title="Найти все сделки, с которых партнёр получал ГП"
          style="max-width: 220px; flex: 1 1 180px"
          @update:model-value="debouncedLoad" />
        <v-text-field v-model="filters.client" placeholder="ФИО клиента"
          density="compact" variant="outlined" hide-details clearable
          style="max-width: 200px; flex: 1 1 160px"
          @update:model-value="debouncedLoad" />
        <v-text-field v-model="filters.contract" placeholder="№ контракта"
          density="compact" variant="outlined" hide-details clearable
          style="max-width: 160px; flex: 1 1 120px"
          @update:model-value="debouncedLoad" />
        <v-autocomplete v-model="filters.supplier" :items="supplierOptions"
          placeholder="Поставщик" density="compact" variant="outlined"
          hide-details clearable
          style="max-width: 200px; flex: 1 1 160px"
          @update:model-value="loadData" />
        <v-checkbox v-model="filters.hideZero" label="Без нулевых"
          density="compact" hide-details color="primary"
          title="Скрыть транзакции с amountRUB=0"
          style="flex: 0 0 auto"
          @update:model-value="loadData" />

        <v-spacer />

        <v-btn :variant="advancedOpen ? 'tonal' : 'text'" size="small"
          :prepend-icon="advancedOpen ? 'mdi-chevron-up' : 'mdi-tune'"
          @click="advancedOpen = !advancedOpen">
          Ещё
          <v-chip v-if="advancedActiveCount > 0" size="x-small" color="info"
            variant="elevated" class="ms-1">{{ advancedActiveCount }}</v-chip>
        </v-btn>
        <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
        </v-chip>
        <v-btn v-if="activeFilterCount > 0" size="small" variant="text" color="secondary"
          prepend-icon="mdi-filter-remove" @click="resetFilters">
          Очистить
        </v-btn>
      </div>

      <v-expand-transition>
        <div v-show="advancedOpen" class="d-flex flex-wrap ga-3 mt-3 align-end">
          <div class="filter-range">
            <span class="text-caption text-medium-emphasis">Дата</span>
            <div class="d-flex ga-1">
              <v-text-field v-model="filters.dateFrom" type="date" placeholder="с"
                density="compact" variant="outlined" hide-details
                @update:model-value="loadData" />
              <v-text-field v-model="filters.dateTo" type="date" placeholder="по"
                density="compact" variant="outlined" hide-details
                @update:model-value="loadData" />
            </div>
          </div>
          <!-- Обёртка с такой же подписью, чтобы инпут не растягивался
               на всю высоту блока «Дата» (label + 2 инпута). Раньше из-за
               flex align-items=stretch Комментарий получался выше всех. -->
          <div class="filter-range">
            <span class="text-caption text-medium-emphasis">Комментарий</span>
            <v-text-field v-model="filters.comment" placeholder="Поиск по тексту"
              density="compact" variant="outlined" hide-details clearable
              style="min-width: 240px"
              @update:model-value="debouncedLoad" />
          </div>
        </div>
      </v-expand-transition>
    </v-card>

    <!-- Обёртка с горизонтальным скроллом — у Комиссий 12+ колонок,
         цифры вроде «156,19 ₽» рвались по символам из-за глобального
         overflow-wrap: anywhere в global.css. Теперь если таблица не
         помещается в viewport — появляется scrollbar внизу. -->
    <div class="commissions-table-wrap">
    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="visibleHeaders" :items-per-page="perPage"
      :items-per-page-options="[25, 50, 100, 200]"
      v-model:expanded="expanded" item-value="id" show-expand
      hover class="commissions-table"
      @click:row="onRowClick"
      @update:options="onOptions">

      <!-- Итоговая строка над заголовками: те же колонки, что в шапке,
           но вместо заголовков показываем суммы по всему фильтру.
           Отдельная карточка сверху не давала выравнивания с колонками —
           глазами сверять сложно, поэтому переехало внутрь thead. -->
      <template #thead="{ columns }">
        <tr v-if="aggregates && total > 0" class="commissions-totals">
          <th v-for="col in columns" :key="`tot-${col.key || col.title}`"
              :class="['text-' + (col.align || 'start'), 'commissions-totals__cell']">
            <template v-if="col.key === 'contractNumber'">
              <span class="text-caption text-medium-emphasis">Итого:</span>
              <strong class="ms-1">{{ total }}</strong>
            </template>
            <template v-else-if="col.key === 'amountRUB'">
              <strong>{{ fmt(aggregates.amountRUB) }} ₽</strong>
            </template>
            <template v-else-if="col.key === 'commissionsAmountRUB'">
              <strong>{{ fmt(aggregates.commissionsAmountRUB) }} ₽</strong>
            </template>
            <template v-else-if="col.key === 'commissionsAmountUSD'">
              <strong>{{ fmt(aggregates.commissionsAmountUSD) }} $</strong>
            </template>
            <template v-else-if="col.key === 'netRevenueRUB'">
              <strong>{{ fmt(aggregates.netRevenueRUB) }} ₽</strong>
            </template>
            <template v-else-if="col.key === 'netRevenueUSD'">
              <strong>{{ fmt(aggregates.netRevenueUSD) }} $</strong>
            </template>
            <template v-else-if="col.key === 'partnerCommissionRUB'">
              <strong>{{ fmt(aggregates.partnerCommissionRUB) }} ₽</strong>
            </template>
            <template v-else-if="col.key === 'dsWithholdingRUB'">
              <strong>{{ fmt(aggregates.dsWithholdingRUB) }} ₽</strong>
            </template>
            <template v-else-if="col.key === 'profitRUB'">
              <strong>{{ fmt(aggregates.profitRUB) }} ₽</strong>
            </template>
            <template v-else>&nbsp;</template>
          </th>
        </tr>
        <tr>
          <th v-for="col in columns" :key="col.key || col.title"
              :class="['text-' + (col.align || 'start'), { sortable: col.sortable !== false && col.key }]"
              :style="col.width ? `width: ${col.width}px; min-width: ${col.width}px` : ''"
              @click="col.sortable !== false && col.key ? onHeaderClick(col.key) : null">
            <span>{{ col.title }}</span>
            <v-icon v-if="sortBy === col.key" size="14" class="ms-1">
              {{ sortDir === 'asc' ? 'mdi-arrow-up' : 'mdi-arrow-down' }}
            </v-icon>
          </th>
        </tr>
      </template>

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
      <template #item.providerName="{ value }">{{ value || '—' }}</template>
      <template #item.productName="{ value }">
        <span class="text-no-wrap">{{ value || '—' }}</span>
      </template>
      <template #item.programName="{ value }">
        <span class="text-no-wrap">{{ value || '—' }}</span>
      </template>
      <template #item.consultantName="{ value }">
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
      <template #item.amountRUB="{ value }">
        <span class="text-no-wrap">{{ fmt(value) }} ₽</span>
      </template>
      <template #item.dsCommissionPercentage="{ value }">
        <span v-if="value != null" class="text-no-wrap">{{ value }}%</span>
        <span v-else class="text-medium-emphasis">—</span>
      </template>
      <template #item.commissionsAmountRUB="{ value }">
        <span class="text-no-wrap">{{ fmt(value) }} ₽</span>
      </template>
      <template #item.commissionsAmountUSD="{ value }">
        <span class="text-no-wrap">{{ fmt(value) }} $</span>
      </template>
      <template #item.netRevenueRUB="{ value }">
        <span class="text-no-wrap">{{ fmt(value) }} ₽</span>
      </template>
      <template #item.netRevenueUSD="{ value }">
        <span class="text-no-wrap">{{ fmt(value) }} $</span>
      </template>

      <template #item.partnerPV="{ value }">
        <span v-if="value != null" class="text-no-wrap">{{ fmt(value) }}</span>
        <span v-else class="text-medium-emphasis">—</span>
      </template>
      <template #item.partnerGV="{ value }">
        <span v-if="value != null" class="text-no-wrap">{{ fmt(value) }}</span>
        <span v-else class="text-medium-emphasis">—</span>
      </template>
      <template #item.partnerBonus="{ value }">
        <span v-if="value != null" class="text-no-wrap">{{ fmt(value) }}</span>
        <span v-else class="text-medium-emphasis">—</span>
      </template>
      <template #item.partnerCommissionRUB="{ value }">
        <span class="text-no-wrap font-weight-medium">{{ fmt(value) }} ₽</span>
      </template>
      <template #item.dsWithholdingRUB="{ value }">
        <span class="text-no-wrap">{{ fmt(value) }} ₽</span>
      </template>
      <template #item.profitRUB="{ value }">
        <span class="text-no-wrap" :class="value >= 0 ? 'text-success' : 'text-error'">
          {{ fmt(value) }} ₽
        </span>
      </template>

      <!-- Удаление: каскадно снимает все комиссии транзакции у партнёра и
           наставников + пересчитывает балансы (DELETE /admin/transactions/{id}).
           @click.stop — иначе клик по строке развернёт аккордеон. -->
      <template #item.actions="{ item }">
        <div class="d-flex">
          <v-btn v-if="canCalc" icon="mdi-pencil-outline" size="x-small"
            variant="text" color="primary"
            :title="item.periodFrozen ? 'Период закрыт — нельзя редактировать' : 'Редактировать транзакцию (сумма / %ДС / дата) с пересчётом комиссий'"
            :disabled="item.periodFrozen"
            @click.stop="openEditTx(item)" />
          <v-btn v-if="canCalc" icon="mdi-trash-can-outline" size="x-small"
            variant="text" color="error"
            :title="item.periodFrozen ? 'Период закрыт — нельзя удалить' : 'Удалить транзакцию со всеми комиссиями (пересчёт цепочки)'"
            :disabled="item.periodFrozen || deletingTxId === item.id"
            :loading="deletingTxId === item.id"
            @click.stop="confirmDeleteTx(item)" />
        </div>
      </template>

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

    <!-- Редактирование транзакции (сумма / %ДС / дата / комментарий) с
         пересчётом комиссий цепочки. Доступно admin/calculations; закрытый
         период блокируется на бэке. -->
    <v-dialog v-model="editDialog" max-width="460">
      <v-card>
        <v-card-title class="text-h6">Редактирование транзакции #{{ editForm.id }}</v-card-title>
        <v-card-text>
          <v-alert type="info" variant="tonal" density="compact" class="mb-3">
            После сохранения комиссии по цепочке наставников пересчитаются автоматически.
          </v-alert>
          <v-row dense>
            <v-col cols="8">
              <v-text-field v-model.number="editForm.amount" label="Сумма транзакции" type="number"
                variant="outlined" density="comfortable" />
            </v-col>
            <v-col cols="4">
              <v-text-field :model-value="editForm.currencySymbol || ''" label="Валюта" disabled
                variant="outlined" density="comfortable" />
            </v-col>
            <v-col cols="6">
              <v-text-field v-model.number="editForm.dsCommissionPercentage" label="% ДС" type="number"
                min="0" max="100" suffix="%" variant="outlined" density="comfortable" />
            </v-col>
            <v-col cols="6">
              <v-text-field v-model="editForm.date" label="Дата" type="date"
                variant="outlined" density="comfortable" />
            </v-col>
            <v-col cols="12">
              <v-textarea v-model="editForm.comment" label="Комментарий" rows="2"
                variant="outlined" density="comfortable" />
            </v-col>
          </v-row>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="editDialog = false">Отмена</v-btn>
          <v-btn color="primary" variant="flat" :loading="savingEdit" @click="saveEditTx">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import { fmt2 as fmt, fmtDate } from '../../composables/useDesign';
import { useConfirm } from '../../composables/useConfirm';
import { useSnackbar } from '../../composables/useSnackbar';
import { usePermissions } from '../../composables/usePermissions';

const confirmDialog = useConfirm();
const { showSuccess, showError, showInfo } = useSnackbar();
// Удаление транзакции/комиссии — только у руководителя расчётов (canCalc).
const { canCalc } = usePermissions();

const items = ref([]);
const total = ref(0);
const aggregates = ref(null);
const loading = ref(false);
const page = ref(1);
const perPage = ref(25);
const sortBy = ref('');
const sortDir = ref('desc');
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

// Базовые headers в дефолтном порядке. Пользовательский порядок
// применяется поверх через columnOrder (ColumnVisibilityMenu + DnD).
const headers = [
  { title: '', key: 'period', width: 30, sortable: false },
  { title: '№ контракта', key: 'contractNumber', width: 130 },
  { title: 'Открыт', key: 'contractOpenDate', width: 110 },
  { title: 'Клиент', key: 'clientName' },
  { title: 'Партнёр', key: 'consultantName' },
  { title: 'Поставщик', key: 'providerName', width: 140 },
  { title: 'Продукт', key: 'productName', width: 160 },
  { title: 'Программа', key: 'programName', width: 160 },
  { title: 'Дата', key: 'date', width: 110 },
  { title: 'Комментарий', key: 'comment' },
  { title: 'Свойство', key: 'propertyTitle', width: 120 },
  { title: 'Срок', key: 'contractTerm', width: 80, align: 'end' },
  { title: 'Год КВ', key: 'yearKV', width: 80, align: 'end' },
  { title: 'Транзакция', key: 'amount', align: 'end', width: 130 },
  { title: 'В РУБ', key: 'amountRUB', align: 'end', width: 130 },
  { title: '% ДС', key: 'dsCommissionPercentage', align: 'end', width: 80 },
  { title: 'Доход DS RUB', key: 'commissionsAmountRUB', align: 'end', width: 140 },
  { title: 'Доход DS USD', key: 'commissionsAmountUSD', align: 'end', width: 140 },
  { title: 'Остаток ДС RUB', key: 'netRevenueRUB', align: 'end', width: 130 },
  { title: 'Остаток ДС USD', key: 'netRevenueUSD', align: 'end', width: 130 },
  { title: 'ЛП', key: 'partnerPV', align: 'end', width: 90 },
  { title: 'ГП', key: 'partnerGV', align: 'end', width: 90 },
  { title: 'Баллы', key: 'partnerBonus', align: 'end', width: 90 },
  { title: 'Комиссия', key: 'partnerCommissionRUB', align: 'end', width: 130 },
  { title: 'Удержание ДС', key: 'dsWithholdingRUB', align: 'end', width: 140 },
  { title: 'Прибыль', key: 'profitRUB', align: 'end', width: 130 },
  { title: '', key: 'actions', sortable: false, width: 92 },
  { title: '', key: 'data-table-expand', sortable: false, width: 50 },
];

const columnVisible = ref({
  // По умолчанию скрываем менее важные, чтобы таблица помещалась
  propertyTitle: false,
  contractTerm: false,
  yearKV: false,
  netRevenueUSD: false,
  commissionsAmountUSD: false,
  dsWithholdingRUB: false, // дубль «Комиссии» — по умолчанию скрыт
});

// Пользовательский порядок колонок. Изначально пустой → используем
// дефолтный из headers. ColumnVisibilityMenu сохранит в localStorage
// per-user через columnPrefs store.
const columnOrder = ref([]);

const visibleHeaders = computed(() => {
  const byKey = new Map(headers.map(h => [h.key, h]));
  const seen = new Set();
  const ordered = [];
  // 1) period — всегда первая (служебный индикатор слева).
  if (byKey.has('period')) { ordered.push(byKey.get('period')); seen.add('period'); }
  // 2) пользовательский порядок (если задан) для остальных, кроме
  //    expand-toggle (всегда в конец).
  for (const key of columnOrder.value) {
    if (key === 'period' || key === 'data-table-expand') continue;
    if (byKey.has(key) && !seen.has(key)) {
      ordered.push(byKey.get(key));
      seen.add(key);
    }
  }
  // 3) хвост — то что не попало в order (новые колонки), в исходном порядке.
  for (const h of headers) {
    if (h.key === 'data-table-expand') continue;
    if (!seen.has(h.key)) { ordered.push(h); seen.add(h.key); }
  }
  // 4) expand-toggle всегда последняя.
  if (byKey.has('data-table-expand')) ordered.push(byKey.get('data-table-expand'));
  return ordered.filter(h => columnVisible.value[h.key] !== false);
});

const advancedOpen = ref(false);

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

// Активные фильтры в свёрнутом блоке «Ещё» — нужен индикатор на тогле,
// чтобы пользователь не пропустил, что фильтрация уже идёт.
const advancedActiveCount = computed(() => {
  let c = 0;
  if (filters.value.dateFrom) c++;
  if (filters.value.dateTo) c++;
  if (filters.value.comment) c++;
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
  // sortBy от v-data-table-server здесь не используем — мы полностью
  // переопределили #thead (для totals-строки сверху), поэтому
  // сортировка идёт через onHeaderClick. Иначе клик по заголовку
  // не доходит до Vuetify-обработчика.
  loadData();
}

function onHeaderClick(key) {
  if (sortBy.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
  } else {
    sortBy.value = key;
    sortDir.value = 'desc';
  }
  page.value = 1;
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
    if (sortBy.value) {
      params.sort_by = sortBy.value;
      params.sort_dir = sortDir.value;
    }
    const { data } = await api.get('/admin/transactions', { params });
    items.value = data.data || [];
    total.value = data.total || 0;
    aggregates.value = data.aggregates || null;
  } catch {}
  loading.value = false;
}

// Удаление одной зафиксированной транзакции (= снятие всех её комиссий).
// Раздел «Комиссии» показывает транзакции с разбивкой по комиссиям, поэтому
// «удалить комиссию» = удалить транзакцию: бэк (DELETE /admin/transactions/{id})
// каскадно soft-delete'ит commission всей цепочки наставников, блокирует
// закрытый период (422) и пересчитывает балансы. Доступно admin/calculations
// (gate canFull('reports-access') — то же, что в разделе «Транзакции»).
const deletingTxId = ref(null);

async function confirmDeleteTx(item) {
  if (item.periodFrozen) return;
  const ok = await confirmDialog.ask({
    title: `Удалить транзакцию #${item.id}?`,
    message:
      `Сумма ${item.amount ?? '—'} ${item.currencySymbol || ''} от ${item.date ?? '—'}. ` +
      `Партнёр: ${item.consultantName || '—'}.\n\n` +
      `Будут отменены все комиссии по этой транзакции у партнёра и всех его наставников. ` +
      `Балансы пересчитаются автоматически. Действие обратимо только восстановлением вручную в БД.`,
    confirmText: 'Удалить',
    confirmColor: 'error',
  });
  if (!ok) return;
  deletingTxId.value = item.id;
  try {
    const { data } = await api.delete(`/admin/transactions/${item.id}`);
    // Если пул за месяц уже применён — выплаты у партнёров посчитаны по старой
    // сумме commission, нужно перезапустить пул вручную через карточку периода.
    if (data?.poolWasApplied && data?.poolPeriod) {
      showInfo(
        data.message || `Транзакция #${item.id} удалена. Пересчитайте пул за ${data.poolPeriod} вручную.`,
        { label: 'Открыть период', to: `/manage/periods/${data.poolPeriod}` },
      );
    } else {
      showSuccess(data?.message || `Транзакция #${item.id} удалена`);
    }
    await loadData();
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось удалить транзакцию');
  }
  deletingTxId.value = null;
}

// Редактирование транзакции (сумма / %ДС / дата / комментарий). Бэкенд
// (PUT /admin/transactions/{id}) валидирует, блокирует закрытый период и
// пересчитывает комиссии цепочки. Только admin/calculations (canCalc).
const editDialog = ref(false);
const savingEdit = ref(false);
const editForm = ref({ id: null, amount: null, dsCommissionPercentage: null, date: '', comment: '', currencySymbol: '' });

function openEditTx(item) {
  if (item.periodFrozen) return;
  editForm.value = {
    id: item.id,
    amount: item.amount ?? null,
    dsCommissionPercentage: item.dsCommissionPercentage ?? null,
    date: (item.date || '').slice(0, 10),
    comment: item.comment || '',
    currencySymbol: item.currencySymbol || '',
  };
  editDialog.value = true;
}

async function saveEditTx() {
  savingEdit.value = true;
  try {
    const payload = {
      amount: editForm.value.amount,
      dsCommissionPercentage: editForm.value.dsCommissionPercentage,
      date: editForm.value.date || null,
      comment: editForm.value.comment,
    };
    const { data } = await api.put(`/admin/transactions/${editForm.value.id}`, payload);
    showSuccess(data?.message || 'Транзакция обновлена');
    editDialog.value = false;
    await loadData();
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось сохранить');
  }
  savingEdit.value = false;
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

const route = useRoute();
onMounted(() => {
  // Поддержка глубоких ссылок: /manage/commissions?date_from=YYYY-MM-DD&date_to=...
  // Используется кнопкой «Открыть в Комиссиях» после фиксации ручных транзакций —
  // иначе свежие апрельские записи теряются среди тысяч таких же по дате.
  if (route.query.date_from) filters.value.dateFrom = String(route.query.date_from);
  if (route.query.date_to) filters.value.dateTo = String(route.query.date_to);
  if (route.query.partner) filters.value.partner = String(route.query.partner);
  if (route.query.contract) filters.value.contract = String(route.query.contract);
  loadData();
  loadSuppliers();
});
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
/* Компактный диапазон дат: подпись + два узких инпута в строку. */
.filter-range {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 220px;
}
.filter-range :deep(.v-field) {
  min-width: 100px;
}
/* Горизонтальный скролл — таблица не помещается в viewport, особенно на
   Mac Air ≤1440px. У Vuetify v-data-table-server overflow-x:auto уже на
   .v-table__wrapper, но из-за глобального overflow-wrap:anywhere цифры
   рвались по символам внутри ячеек. Здесь явно отключаем wrap. */
.commissions-table-wrap {
  overflow-x: auto;
}
.commissions-table :deep(td),
.commissions-table :deep(th) {
  overflow-wrap: normal !important;
  word-break: normal !important;
}
/* Итоги встроены в thead — выделяем фоном и зеленой каймой снизу,
   чтобы сразу читались как «sum-row» а не как обычный заголовок. */
.commissions-table :deep(tr.commissions-totals) {
  background: rgba(var(--v-theme-primary), 0.08);
}
.commissions-table :deep(tr.commissions-totals th) {
  font-weight: 500;
  border-bottom: 1px solid rgba(var(--v-theme-primary), 0.3);
  padding-top: 6px;
  padding-bottom: 6px;
  white-space: nowrap;
}
/* Заголовки кликабельны, если sortable — добавляем cursor и подсветку. */
.commissions-table :deep(thead th.sortable) {
  cursor: pointer;
  user-select: none;
}
.commissions-table :deep(thead th.sortable:hover) {
  background: rgba(var(--v-theme-on-surface), 0.04);
}
</style>
