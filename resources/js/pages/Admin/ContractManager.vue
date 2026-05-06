<template>
  <div>
    <PageHeader title="Менеджер контрактов" icon="mdi-file-document-edit" :count="total">
      <template #actions>
        <v-btn color="success" prepend-icon="mdi-plus" @click="openCreate">
          Новый контракт
        </v-btn>
      </template>
    </PageHeader>

    <FilterBar
      :show-reset="activeFilterCount > 0"
      @reset="resetFilters"
    >
      <v-col cols="12" md="2">
        <v-text-field v-model="filters.client_name" placeholder="ФИО клиента"
          density="comfortable" variant="outlined" hide-details clearable
          @update:model-value="debouncedLoad" />
      </v-col>
      <v-col cols="12" md="2">
        <v-text-field v-model="filters.consultant_name" placeholder="ФИО консультанта"
          density="comfortable" variant="outlined" hide-details clearable
          @update:model-value="debouncedLoad" />
      </v-col>
      <v-col cols="12" md="2">
        <v-text-field v-model="filters.number" placeholder="№ контракта"
          density="comfortable" variant="outlined" hide-details clearable
          @update:model-value="debouncedLoad" />
      </v-col>
      <v-col cols="12" md="2">
        <v-text-field v-model="filters.comment" placeholder="Комментарий"
          density="comfortable" variant="outlined" hide-details clearable
          @update:model-value="debouncedLoad" />
      </v-col>
      <v-col cols="12" md="2">
        <v-select v-model="statusFilter" :items="statusOptions" label="Статус"
          variant="outlined" density="comfortable" clearable hide-details
          @update:model-value="loadData" />
      </v-col>
      <v-col cols="12" md="2">
        <v-autocomplete v-model="filters.supplier" :items="supplierOptions"
          label="Поставщик" density="comfortable" variant="outlined" hide-details clearable
          @update:model-value="loadData" />
      </v-col>
      <v-col cols="12" md="3">
        <v-autocomplete v-model="filters.product" :items="productOptions" item-title="name" item-value="id"
          label="Продукт" density="comfortable" variant="outlined" hide-details clearable
          @update:model-value="onFilterProductChange" />
      </v-col>
      <v-col cols="12" md="3">
        <v-autocomplete v-model="filters.program" :items="filterPrograms" item-title="name" item-value="id"
          label="Программа" density="comfortable" variant="outlined" hide-details clearable
          @update:model-value="loadData" />
      </v-col>
      <v-col cols="12" md="3">
        <v-autocomplete v-model="filters.setup" :items="formData.setups" item-title="name" item-value="id"
          label="Сетап" density="comfortable" variant="outlined" hide-details clearable
          @update:model-value="loadData" />
      </v-col>
      <v-col cols="12" md="2">
        <v-btn variant="text" size="small" :prepend-icon="showAdvanced ? 'mdi-chevron-up' : 'mdi-chevron-down'"
          @click="showAdvanced = !showAdvanced">Доп. фильтры</v-btn>
      </v-col>
      <template v-if="showAdvanced">
        <v-col cols="12" md="2"><v-text-field v-model="filters.created_from" label="Создан с" type="date"
          density="comfortable" variant="outlined" hide-details @update:model-value="loadData" /></v-col>
        <v-col cols="12" md="2"><v-text-field v-model="filters.created_to" label="Создан по" type="date"
          density="comfortable" variant="outlined" hide-details @update:model-value="loadData" /></v-col>
        <v-col cols="12" md="2"><v-text-field v-model="filters.opened_from" label="Открыт с" type="date"
          density="comfortable" variant="outlined" hide-details @update:model-value="loadData" /></v-col>
        <v-col cols="12" md="2"><v-text-field v-model="filters.opened_to" label="Открыт по" type="date"
          density="comfortable" variant="outlined" hide-details @update:model-value="loadData" /></v-col>
        <v-col cols="12" md="2"><v-text-field v-model="filters.closed_from" label="Закрыт с" type="date"
          density="comfortable" variant="outlined" hide-details @update:model-value="loadData" /></v-col>
        <v-col cols="12" md="2"><v-text-field v-model="filters.closed_to" label="Закрыт по" type="date"
          density="comfortable" variant="outlined" hide-details @update:model-value="loadData" /></v-col>
      </template>
      <v-col v-if="activeFilterCount > 0" cols="auto" class="d-flex align-center">
        <v-chip size="small" color="info" variant="tonal">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : activeFilterCount < 5 ? 'фильтра' : 'фильтров' }}
        </v-chip>
      </v-col>
      <v-col cols="auto" class="d-flex align-center ms-auto">
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="contract-manager-cols" />
      </v-col>
    </FilterBar>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="visibleHeaders" :items-per-page="25" @update:options="onOptions">
      <template #item.ammount="{ item }">
        {{ fmt(item.ammount) }} {{ item.currencySymbol }}
      </template>
      <template #item.openDate="{ value }">
        {{ fmtDate(value) }}
      </template>
      <template #item.statusName="{ value }">
        <StatusChip :value="value" kind="contract" size="x-small" :text="value" />
      </template>
      <template #item.comment="{ value }">
        <span v-if="value" :title="value" class="d-inline-block text-truncate" style="max-width:240px">
          {{ value }}
        </span>
        <span v-else class="text-medium-emphasis">—</span>
      </template>
      <template #item.counterpartyContractId="{ value }">
        <span v-if="value">{{ value }}</span>
        <span v-else class="text-medium-emphasis">—</span>
      </template>
      <template #item.supplierName="{ value }">
        <span v-if="value">{{ value }}</span>
        <span v-else class="text-medium-emphasis">—</span>
      </template>
      <template #item.chat="{ item }">
        <StartChatButton :partner-id="item.consultantId || item.consultant" :partner-name="item.consultantName"
          context-type="Контракт" :context-id="item.id" :context-label="'#' + (item.number || item.id)" />
      </template>
      <template #item.actions="{ item }">
        <v-btn icon="mdi-pencil" size="x-small" variant="text" color="success" title="Редактировать"
          @click="openEdit(item)" />
        <v-btn icon="mdi-history" size="x-small" variant="text" title="История изменений"
          @click="openHistory(item)" />
      </template>
      <template #no-data><EmptyState /></template>
    </v-data-table-server>

    <!-- Модалка создания/редактирования (per spec ✅Менеджер контрактов §3) -->
    <v-navigation-drawer v-model="editOpen" location="right" temporary width="640">
      <v-card flat>
        <v-card-title class="d-flex align-center">
          <v-icon class="mr-2">{{ editingId ? 'mdi-pencil' : 'mdi-plus' }}</v-icon>
          {{ editingId ? 'Редактирование контракта' : 'Новый контракт' }}
          <v-spacer />
          <v-btn icon="mdi-close" size="small" variant="text" @click="editOpen = false" />
        </v-card-title>

        <v-card-text>
          <!-- Блок «Основное» -->
          <div class="text-subtitle-2 font-weight-bold mb-2">Основное</div>
          <v-text-field v-model="form.number" label="Номер контракта *"
            variant="outlined" density="comfortable" class="mb-2" />
          <v-text-field v-model="form.counterpartyContractId" label="Идентификатор контрагента"
            variant="outlined" density="comfortable" class="mb-2" />
          <v-select v-model="form.status" :items="formData.statuses" item-title="name" item-value="id"
            label="Статус *" variant="outlined" density="comfortable" class="mb-3" />

          <!-- Блок «Привязки» -->
          <div class="text-subtitle-2 font-weight-bold mb-2">Привязки</div>
          <div class="d-flex ga-2 mb-2">
            <v-autocomplete v-model="form.client" :items="clientOptions" item-title="personName" item-value="id"
              :loading="clientSearching" @update:search="searchClients"
              label="Клиент *" variant="outlined" density="comfortable" class="flex-grow-1"
              no-data-text="Начните вводить ФИО клиента" />
            <v-btn v-if="form.client" variant="outlined" color="secondary" :height="44"
              prepend-icon="mdi-pencil" :href="'/admin/clients?id=' + form.client" target="_blank"
              title="Открыть карточку клиента в новой вкладке">
              Изменить
            </v-btn>
          </div>
          <v-text-field :model-value="autoConsultant" label="Партнёр (авто из клиента)"
            variant="outlined" density="comfortable" disabled class="mb-2"
            hint="Подтягивается автоматически при выборе клиента" persistent-hint />
          <v-autocomplete v-model="form.product" :items="productOptions" item-title="name" item-value="id"
            label="Продукт *" variant="outlined" density="comfortable" clearable class="mb-2"
            @update:model-value="onProductChange" />
          <v-autocomplete v-model="form.program" :items="filteredPrograms" item-title="name" item-value="id"
            label="Программа *" variant="outlined" density="comfortable" clearable class="mb-2" />
          <v-select v-model="form.country" :items="formData.countries" item-title="name" item-value="id"
            label="Страна оформления" variant="outlined" density="comfortable" clearable class="mb-3" />

          <!-- Блок «Даты» -->
          <div class="text-subtitle-2 font-weight-bold mb-2">Даты</div>
          <v-row dense>
            <v-col cols="4"><v-text-field v-model="form.createDate" label="Создания *" type="date" variant="outlined" density="comfortable" /></v-col>
            <v-col cols="4"><v-text-field v-model="form.openDate" label="Открытия" type="date" variant="outlined" density="comfortable" /></v-col>
            <v-col cols="4"><v-text-field v-model="form.closeDate" label="Закрытия" type="date" variant="outlined" density="comfortable" /></v-col>
          </v-row>

          <!-- Блок «Сумма» -->
          <div class="text-subtitle-2 font-weight-bold mb-2 mt-3">Сумма</div>
          <v-row dense>
            <v-col cols="8"><v-text-field v-model.number="form.ammount" label="Сумма контракта *" type="number" variant="outlined" density="comfortable" /></v-col>
            <v-col cols="4">
              <v-select v-model="form.currency" :items="formData.currencies" item-title="symbol" item-value="id"
                label="Валюта *" variant="outlined" density="comfortable" />
            </v-col>
          </v-row>

          <!-- Блок «Настройки» -->
          <div class="text-subtitle-2 font-weight-bold mb-2 mt-3">Настройки</div>
          <v-select v-model="form.riskProfile" :items="formData.riskProfiles" item-title="name" item-value="id"
            label="Риск-профиль" variant="outlined" density="comfortable" clearable class="mb-2" />
          <v-select v-model="form.setup" :items="formData.setups" item-title="name" item-value="id"
            label="Сетап" variant="outlined" density="comfortable" clearable class="mb-2" />
          <v-select v-model="form.type" :items="typeOptions"
            label="Тип (для страховых)" variant="outlined" density="comfortable" clearable class="mb-2" />
          <v-textarea v-model="form.comment" label="Комментарий"
            variant="outlined" density="comfortable" rows="2" />

          <!-- Блок «Цепочка партнёров» (только при редактировании) -->
          <template v-if="editingId && chain.length">
            <div class="text-subtitle-2 font-weight-bold mb-2 mt-3">Цепочка партнёров (read-only)</div>
            <v-list density="compact">
              <v-list-item v-for="(p, idx) in chain" :key="p.id"
                :prepend-icon="idx === 0 ? 'mdi-account-circle' : 'mdi-account-arrow-up'"
                :title="p.personName"
                :subtitle="idx === 0 ? 'Прямой партнёр' : `Уровень ${idx}`" />
            </v-list>
          </template>

          <!-- Блок «Реквизиты прямого партнёра» (только при редактировании) -->
          <template v-if="editingId && chain.length">
            <div class="d-flex align-center mt-3 mb-2">
              <div class="text-subtitle-2 font-weight-bold">Реквизиты партнёра (read-only)</div>
              <v-spacer />
              <v-btn v-if="chain[0]" size="x-small" variant="text" prepend-icon="mdi-open-in-new"
                :href="'/admin/requisites?consultant=' + chain[0].id" target="_blank">
                В Реквизиты
              </v-btn>
            </div>
            <v-skeleton-loader v-if="reqLoading" type="list-item-three-line" />
            <v-alert v-else-if="!partnerRequisites.length" type="info" variant="tonal" density="compact">
              У партнёра нет ни одной заполненной анкеты ИП.
            </v-alert>
            <v-card v-else variant="outlined" class="pa-3" density="compact">
              <div class="d-flex align-center mb-2">
                <v-chip size="x-small" :color="partnerRequisites[0].verified ? 'success' : 'warning'">
                  {{ partnerRequisites[0].verified ? 'Верифицирован' : 'На проверке' }}
                </v-chip>
                <v-spacer />
                <span class="text-caption text-medium-emphasis">
                  {{ partnerRequisites[0].entityType === 'self_employed' ? 'Самозанятый' : 'ИП' }}
                </span>
              </div>
              <div class="text-body-2"><b>ИНН:</b> {{ partnerRequisites[0].inn || '—' }}</div>
              <div v-if="partnerRequisites[0].ogrnip" class="text-body-2"><b>ОГРНИП:</b> {{ partnerRequisites[0].ogrnip }}</div>
              <div v-if="partnerRequisites[0].fullName" class="text-body-2"><b>ФИО:</b> {{ partnerRequisites[0].fullName }}</div>
              <div v-if="partnerRequisites[0].address" class="text-body-2"><b>Адрес:</b> {{ partnerRequisites[0].address }}</div>
              <div v-if="partnerRequisites[0].bankAccount" class="text-body-2"><b>Р/счёт:</b> {{ partnerRequisites[0].bankAccount }}</div>
              <div v-if="partnerRequisites[0].bankName" class="text-body-2"><b>Банк:</b> {{ partnerRequisites[0].bankName }}</div>
              <div v-if="partnerRequisites[0].bik" class="text-body-2"><b>БИК:</b> {{ partnerRequisites[0].bik }}</div>
            </v-card>
          </template>
        </v-card-text>

        <v-card-actions class="d-flex flex-wrap ga-2">
          <v-btn v-if="editingId" color="error" variant="text"
            prepend-icon="mdi-delete" @click="confirmDelete">
            Удалить контракт
          </v-btn>
          <v-spacer />
          <v-btn @click="editOpen = false">Отмена</v-btn>
          <v-btn color="success" prepend-icon="mdi-content-save" :loading="saving"
            :disabled="!canSave" @click="saveContract">
            Сохранить контракт
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-navigation-drawer>

    <v-dialog v-model="deleteDialog" max-width="440">
      <v-card>
        <v-card-title>Удалить контракт?</v-card-title>
        <v-card-text>
          Действие необратимо. Контракт {{ form.number }} будет помечен удалённым
          (deletedAt = now()), все привязанные транзакции и комиссии останутся в БД.
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="deleteDialog = false">Отмена</v-btn>
          <v-btn color="error" :loading="saving" @click="deleteContract">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="4000">{{ snack.text }}</v-snackbar>

    <!-- Модалка «История контракта» (per spec ✅Менеджер контрактов §4) -->
    <v-dialog v-model="historyOpen" max-width="900">
      <v-card v-if="historyContext">
        <v-card-title>
          История изменений контракта {{ historyContext.number || ('#' + historyContext.id) }}
        </v-card-title>
        <v-card-text>
          <v-alert v-if="!historyRows.length && !historyLoading" type="info" variant="tonal" density="compact">
            Изменений не найдено (или контракт не редактировался после установки логирования).
          </v-alert>
          <v-table v-else density="compact">
            <thead>
              <tr>
                <th style="width:170px">Дата и время</th>
                <th>Что изменено</th>
                <th style="width:200px">Автор</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in historyRows" :key="row.id">
                <td class="text-no-wrap">{{ fmtDateTime(row.createdAt) }}</td>
                <td>
                  <div v-if="!row.changes.length" class="text-medium-emphasis">
                    {{ row.description || row.event }}
                  </div>
                  <div v-for="ch in row.changes" :key="ch.field" class="mb-1">
                    <span class="font-weight-medium">{{ ch.fieldLabel }}:</span>
                    <span class="text-medium-emphasis">{{ formatVal(ch.old) }}</span>
                    <v-icon size="14" class="mx-1">mdi-arrow-right</v-icon>
                    <span class="text-success">{{ formatVal(ch.new) }}</span>
                  </div>
                </td>
                <td class="text-no-wrap">{{ row.author }}</td>
              </tr>
            </tbody>
          </v-table>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="historyOpen = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import StartChatButton from '../../components/StartChatButton.vue';
import StatusChip from '../../components/StatusChip.vue';
import FilterBar from '../../components/FilterBar.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import { fmt, fmtDate, getContractStatusColor } from '../../composables/useDesign';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const statusFilter = ref(null);
const statusOptions = ref([]);
const showAdvanced = ref(false);
const filters = ref({
  client_name: '', consultant_name: '',
  number: '', comment: '', product: null, program: null,
  setup: null, supplier: null,
  created_from: '', created_to: '',
  opened_from: '', opened_to: '',
  closed_from: '', closed_to: '',
});
const page = ref(1);
const perPage = ref(25);

const headers = [
  { title: 'ID', key: 'id', width: 60 },
  { title: 'Номер', key: 'number', width: 120 },
  { title: 'ИД контрагента', key: 'counterpartyContractId', width: 130 },
  { title: 'Клиент', key: 'clientName' },
  { title: 'Партнёр', key: 'consultantName' },
  { title: 'Открыт', key: 'openDate', width: 120 },
  { title: 'Статус', key: 'statusName', width: 130 },
  { title: 'Сумма', key: 'ammount', width: 140 },
  { title: 'Продукт', key: 'productName' },
  { title: 'Программа', key: 'programName' },
  { title: 'Поставщик', key: 'supplierName', width: 130 },
  { title: 'Комментарий', key: 'comment' },
  { title: '', key: 'chat', sortable: false, width: 50 },
  { title: '', key: 'actions', sortable: false, width: 70 },
];

const supplierOptions = ref([]);
const filterPrograms = computed(() => {
  const all = formData.value.programs || [];
  if (!filters.value.product) return all;
  return all.filter(p => p.productId == filters.value.product);
});

function onFilterProductChange() {
  filters.value.program = null;
  loadData();
}

// === Edit modal ===
const editOpen = ref(false);
const editingId = ref(null);
const saving = ref(false);
const deleteDialog = ref(false);
const chain = ref([]);
const partnerRequisites = ref([]);
const reqLoading = ref(false);

const formData = ref({ statuses: [], currencies: [], countries: [], riskProfiles: [], setups: [], suppliers: [], programs: [] });
const productOptions = ref([]);
const programsByProduct = ref({}); // productId → programs[]
const clientOptions = ref([]);
const clientSearching = ref(false);
let clientSearchTimer;

const typeOptions = [
  { title: 'Рисковое', value: 'risk' },
  { title: 'НСЖ', value: 'nszh' },
];

const blankForm = () => ({
  number: '', counterpartyContractId: '',
  status: null, client: null,
  product: null, program: null, country: null,
  createDate: new Date().toISOString().slice(0, 10),
  openDate: '', closeDate: '',
  ammount: 0, currency: null,
  riskProfile: null, setup: null, type: null, comment: '',
});

const form = ref(blankForm());

const filteredPrograms = computed(() => {
  if (!form.value.product) return [];
  return programsByProduct.value[form.value.product] || [];
});

const autoConsultant = computed(() => {
  if (!form.value.client) return '';
  const c = clientOptions.value.find(x => x.id === form.value.client);
  return c?.consultantName || '';
});

const canSave = computed(() =>
  form.value.number && form.value.status && form.value.client &&
  form.value.product && form.value.program &&
  form.value.createDate && form.value.ammount > 0 && form.value.currency
);

const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

function openCreate() {
  editingId.value = null;
  form.value = blankForm();
  chain.value = [];
  ensureFormData();
  editOpen.value = true;
}

async function openEdit(item) {
  editingId.value = item.id;
  ensureFormData();
  try {
    const { data } = await api.get(`/admin/contracts/${item.id}`);
    const c = data.contract;
    form.value = {
      number: c.number || '',
      counterpartyContractId: c.counterpartyContractId || '',
      status: c.status,
      client: c.client,
      product: c.product,
      program: c.program,
      country: c.country,
      createDate: (c.createDate || '').slice(0, 10),
      openDate: (c.openDate || '').slice(0, 10),
      closeDate: (c.closeDate || '').slice(0, 10),
      ammount: Number(c.ammount || 0),
      currency: c.currency,
      riskProfile: c.riskProfile,
      setup: c.setup,
      type: c.type,
      comment: c.comment || '',
    };
    if (c.client) {
      clientOptions.value = [{ id: c.client, personName: c.clientName, consultantName: c.consultantName }];
    }
    chain.value = data.chain || [];
    editOpen.value = true;
    // После открытия — подгружаем реквизиты прямого партнёра контракта.
    loadPartnerRequisites();
  } catch (e) {
    notify(e.response?.data?.message || 'Не удалось загрузить контракт', 'error');
  }
}

async function loadPartnerRequisites() {
  partnerRequisites.value = [];
  if (!chain.value.length) return;
  const consultantId = chain.value[0]?.id;
  if (!consultantId) return;
  reqLoading.value = true;
  try {
    const { data } = await api.get('/admin/requisites', {
      params: { consultant: consultantId, per_page: 5 },
    });
    // Сортируем: верифицированные сверху, затем по id desc — самая свежая
    // версия первой. Тот же приоритет, что в Requisites.vue (DISTINCT ON).
    const rows = (data?.data || []).slice().sort((a, b) => {
      if ((b.verified ? 1 : 0) - (a.verified ? 1 : 0) !== 0) {
        return (b.verified ? 1 : 0) - (a.verified ? 1 : 0);
      }
      return (b.id || 0) - (a.id || 0);
    });
    partnerRequisites.value = rows;
  } catch {}
  reqLoading.value = false;
}

async function ensureFormData() {
  if (formData.value.statuses.length) return;
  try {
    const [fd, products] = await Promise.all([
      api.get('/admin/contracts/form-data'),
      api.get('/admin/products', { params: { per_page: 1000, active: true } }).catch(() => ({ data: { data: [] } })),
    ]);
    formData.value = fd.data;
    productOptions.value = (products.data?.data || []).map(p => ({ id: p.id, name: p.name }));
    supplierOptions.value = fd.data.suppliers || [];
  } catch {}
}

async function onProductChange(pid) {
  form.value.program = null;
  if (!pid) return;
  if (programsByProduct.value[pid]) return;
  try {
    const { data } = await api.get(`/admin/products/${pid}/programs`);
    programsByProduct.value[pid] = (data?.data || data || []).map(p => ({ id: p.id, name: p.name }));
  } catch {}
}

function searchClients(q) {
  clearTimeout(clientSearchTimer);
  if (!q || q.length < 2) return;
  clientSearchTimer = setTimeout(async () => {
    clientSearching.value = true;
    try {
      const { data } = await api.get('/admin/clients', { params: { search: q, per_page: 25 } });
      clientOptions.value = (data?.data || []).map(c => ({
        id: c.id,
        personName: c.personName,
        consultantName: c.consultantName,
      }));
    } catch {}
    clientSearching.value = false;
  }, 300);
}

async function saveContract() {
  saving.value = true;
  try {
    const payload = { ...form.value };
    if (editingId.value) {
      await api.put(`/admin/contracts/${editingId.value}`, payload);
      notify('Контракт обновлён');
    } else {
      await api.post('/admin/contracts', payload);
      notify('Контракт создан');
    }
    editOpen.value = false;
    await loadData();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка сохранения', 'error');
  }
  saving.value = false;
}

function confirmDelete() { deleteDialog.value = true; }

async function deleteContract() {
  saving.value = true;
  try {
    await api.delete(`/admin/contracts/${editingId.value}`);
    deleteDialog.value = false;
    editOpen.value = false;
    await loadData();
    notify('Контракт удалён');
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  saving.value = false;
}

const historyOpen = ref(false);
const historyContext = ref(null);
const historyRows = ref([]);
const historyLoading = ref(false);

async function openHistory(item) {
  historyContext.value = item;
  historyRows.value = [];
  historyLoading.value = true;
  historyOpen.value = true;
  try {
    const { data } = await api.get(`/admin/contracts/${item.id}/history`);
    historyRows.value = data.data || [];
  } catch {}
  historyLoading.value = false;
}

function fmtDateTime(d) {
  if (!d) return '—';
  return new Date(d).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}

function formatVal(v) {
  if (v === null || v === undefined) return '—';
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
}

const columnVisible = ref({});
const visibleHeaders = computed(() => headers.filter(h => columnVisible.value[h.key] !== false));

const activeFilterCount = computed(() => {
  let c = 0;
  if (search.value) c++;
  if (statusFilter.value) c++;
  Object.values(filters.value).forEach(v => { if (v) c++; });
  return c;
});

function resetFilters() {
  search.value = '';
  statusFilter.value = null;
  filters.value = {
    client_name: '', consultant_name: '',
    number: '', comment: '', product: null, program: null,
    setup: null, supplier: null,
    created_from: '', created_to: '',
    opened_from: '', opened_to: '',
    closed_from: '', closed_to: '',
  };
  loadData();
}

const { debounced: debouncedLoad } = useDebounce(loadData, 400);

function onOptions(opts) {
  page.value = opts.page;
  if (opts.itemsPerPage) {
    // Vuetify шлёт -1 при выборе «All» — бэк трактует как невалид и
    // возвращает одну запись. Заменяем на большое значение чтобы
    // действительно получить весь список.
    perPage.value = opts.itemsPerPage === -1 ? 100000 : opts.itemsPerPage;
  }
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value };
    if (search.value) params.search = search.value;
    if (statusFilter.value) params.status = statusFilter.value;
    Object.entries(filters.value).forEach(([k, v]) => {
      if (v !== '' && v !== null && v !== undefined) params[k] = v;
    });
    const { data } = await api.get('/admin/contracts', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

async function loadStatuses() {
  try {
    const { data } = await api.get('/contracts/statuses');
    statusOptions.value = data.map(s => ({ title: s.name, value: s.id }));
  } catch {}
}

onMounted(() => {
  loadData();
  loadStatuses();
  ensureFormData();
});
</script>
