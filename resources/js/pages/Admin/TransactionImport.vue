<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-upload</v-icon>
      <h5 class="text-h5 font-weight-bold">Импорт транзакций</h5>
    </div>

    <!-- Import form -->
    <v-card class="mb-4 pa-4">
      <v-tabs v-model="importMode" class="mb-3">
        <v-tab value="sheets" prepend-icon="mdi-google-spreadsheet">Google Sheets</v-tab>
        <v-tab value="file" prepend-icon="mdi-file-upload">Загрузить файл</v-tab>
      </v-tabs>

      <!-- Google Sheets mode -->
      <v-row v-if="importMode === 'sheets'" dense>
        <v-col cols="12" sm="4">
          <v-select v-model="form.sheet" :items="sheetNames" label="Лист (поставщик) *"
            density="compact" variant="outlined" :loading="loadingSheets" />
        </v-col>
        <v-col cols="12" sm="3">
          <v-select v-model="form.counterparty" :items="counterparties" item-title="name" item-value="id"
            label="Поставщик в БД *" density="compact" variant="outlined" />
        </v-col>
        <v-col cols="12" sm="3">
          <v-select v-model="form.currency" :items="currencies" item-title="symbol" item-value="id"
            label="Валюта" density="compact" variant="outlined" clearable />
        </v-col>
        <v-col cols="12" sm="2" class="d-flex align-end">
          <v-btn color="primary" block prepend-icon="mdi-import" :loading="importing"
            :disabled="!form.sheet || !form.counterparty" @click="runSheetsImport">
            Импортировать
          </v-btn>
        </v-col>
      </v-row>

      <!-- File upload mode -->
      <v-row v-if="importMode === 'file'" dense>
        <v-col cols="12" sm="4">
          <v-select v-model="form.counterparty" :items="counterparties" item-title="name" item-value="id"
            label="Поставщик *" density="compact" variant="outlined" />
        </v-col>
        <v-col cols="12" sm="3">
          <v-select v-model="form.currency" :items="currencies" item-title="symbol" item-value="id"
            label="Валюта" density="compact" variant="outlined" clearable />
        </v-col>
        <v-col cols="12" sm="3">
          <v-file-input v-model="form.file" label="CSV файл" density="compact" variant="outlined"
            accept=".csv,.txt,.xlsx" prepend-icon="" prepend-inner-icon="mdi-file-delimited" hide-details />
        </v-col>
        <v-col cols="12" sm="2" class="d-flex align-end">
          <v-btn color="primary" block prepend-icon="mdi-import" :loading="importing"
            :disabled="!form.counterparty || !form.file" @click="runImport">
            Импортировать
          </v-btn>
        </v-col>
      </v-row>

      <!-- Result -->
      <v-alert v-if="result" :type="result.errors === 0 ? 'success' : result.success > 0 ? 'warning' : 'error'"
        class="mt-3" closable @click:close="result = null">
        <div class="font-weight-bold">{{ result.message }}</div>
        <div v-if="result.errorDetails?.length" class="mt-2">
          <div v-for="(err, i) in result.errorDetails" :key="i" class="text-caption">{{ err }}</div>
        </div>
      </v-alert>
    </v-card>

    <!-- Import history -->
    <v-card class="pa-4">
      <div class="d-flex justify-space-between align-center mb-3">
        <div class="text-subtitle-1 font-weight-bold">История импорта</div>
        <v-btn size="small" variant="text" prepend-icon="mdi-refresh" @click="loadHistory">Обновить</v-btn>
      </div>

      <v-data-table-server :items="history" :items-length="historyTotal" :loading="historyLoading"
        :headers="historyHeaders" :items-per-page="25" @update:options="onHistoryOptions" density="compact" hover>
        <template #item.status="{ value }">
          <v-chip size="x-small" :color="statusColor(value)">{{ statusLabel(value) }}</v-chip>
        </template>
        <template #item.counts="{ item }">
          <span class="text-success">{{ item.successCount }}</span>
          <span v-if="item.errorCount > 0" class="text-error ml-1">/ {{ item.errorCount }} ош.</span>
        </template>
        <template #item.createdAt="{ value }">{{ fmtDate(value) }}</template>
        <template #item.actions="{ item }">
          <v-btn v-if="item.status === 'success' || item.status === 'partial'" icon size="x-small" variant="text" color="primary"
            :loading="calculatingId === item.id" @click="runCalculation(item)">
            <v-icon>mdi-calculator</v-icon>
            <v-tooltip activator="parent">Рассчитать комиссии</v-tooltip>
          </v-btn>
          <v-btn v-if="item.status !== 'rolled_back'" icon size="x-small" variant="text" color="warning"
            @click="confirmRollback(item)">
            <v-icon>mdi-undo</v-icon>
            <v-tooltip activator="parent">Откатить</v-tooltip>
          </v-btn>
          <v-btn v-if="item.errors?.length" icon size="x-small" variant="text" color="info"
            @click="showErrors(item)">
            <v-icon>mdi-alert-circle-outline</v-icon>
            <v-tooltip activator="parent">Ошибки</v-tooltip>
          </v-btn>
        </template>
        <template #no-data>
          <div class="text-center pa-4">
            <v-icon size="48" color="grey-lighten-1" class="mb-2">mdi-file-search-outline</v-icon>
            <div class="text-medium-emphasis">Импортов пока нет</div>
          </div>
        </template>
      </v-data-table-server>
    </v-card>

    <!-- Rollback confirm -->
    <v-dialog v-model="rollbackDialog" max-width="400">
      <v-card>
        <v-card-title>Откатить импорт #{{ rollbackTarget?.id }}?</v-card-title>
        <v-card-text>
          Все {{ rollbackTarget?.successCount }} транзакций, созданных этим импортом, будут удалены.
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="rollbackDialog = false">Отмена</v-btn>
          <v-btn color="error" @click="doRollback" :loading="rolling">Откатить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Errors dialog -->
    <v-dialog v-model="errorsDialog" max-width="600">
      <v-card>
        <v-card-title>Ошибки импорта #{{ errorsTarget?.id }}</v-card-title>
        <v-card-text>
          <div v-for="(err, i) in errorsTarget?.errors || []" :key="i" class="text-body-2 mb-1">
            <v-icon size="14" color="error" class="mr-1">mdi-alert</v-icon>{{ err }}
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="errorsDialog = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';

const counterparties = ref([]);
const currencies = ref([]);
const sheetNames = ref([]);
const loadingSheets = ref(false);
const importing = ref(false);
const importMode = ref('sheets');
const result = ref(null);
const form = ref({ counterparty: null, currency: null, file: null, sheet: null });

// History
const history = ref([]);
const historyTotal = ref(0);
const historyLoading = ref(false);
const historyPage = ref(1);

// Dialogs
const rollbackDialog = ref(false);
const rollbackTarget = ref(null);
const rolling = ref(false);
const calculatingId = ref(null);
const calcResult = ref(null);
const errorsDialog = ref(false);
const errorsTarget = ref(null);

const historyHeaders = [
  { title: 'ID', key: 'id', width: 60 },
  { title: 'Поставщик', key: 'counterpartyName' },
  { title: 'Статус', key: 'status', width: 150 },
  { title: 'Всего строк', key: 'totalRows', width: 100 },
  { title: 'Результат', key: 'counts', width: 130, sortable: false },
  { title: 'Дата', key: 'createdAt', width: 150 },
  { title: '', key: 'actions', sortable: false, width: 90 },
];

function fmtDate(d) { if (!d) return '—'; try { return new Date(d).toLocaleDateString('ru-RU') + ' ' + new Date(d).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }); } catch { return d; } }

function statusColor(s) {
  return { success: 'success', partial: 'warning', error: 'error', processing: 'info', rolled_back: 'grey' }[s] || 'grey';
}

function statusLabel(s) {
  return { success: 'Успешно', partial: 'Частично', error: 'Ошибка', processing: 'В процессе', rolled_back: 'Откачено', pending: 'Ожидание' }[s] || s;
}

async function runSheetsImport() {
  if (!form.value.sheet || !form.value.counterparty) return;
  importing.value = true;
  result.value = null;
  try {
    const { data } = await api.post('/admin/transaction-import/from-sheets', {
      sheet: form.value.sheet,
      counterparty: form.value.counterparty,
      currency: form.value.currency,
    });
    result.value = data;
    loadHistory();
  } catch (e) {
    result.value = { message: e.response?.data?.message || 'Ошибка импорта из Google Sheets', errors: 1, success: 0 };
  }
  importing.value = false;
}

async function runImport() {
  if (!form.value.counterparty || !form.value.file) return;
  importing.value = true;
  result.value = null;
  try {
    const fd = new FormData();
    fd.append('file', form.value.file);
    fd.append('counterparty', form.value.counterparty);
    if (form.value.currency) fd.append('currency', form.value.currency);
    const { data } = await api.post('/admin/transaction-import', fd);
    result.value = data;
    form.value.file = null;
    loadHistory();
  } catch (e) {
    result.value = { message: e.response?.data?.message || 'Ошибка импорта', errors: 1, success: 0 };
  }
  importing.value = false;
}

function onHistoryOptions(opts) { historyPage.value = opts.page; loadHistory(); }

async function loadHistory() {
  historyLoading.value = true;
  try {
    const { data } = await api.get('/admin/transaction-import/history', { params: { page: historyPage.value } });
    history.value = data.data;
    historyTotal.value = data.total;
  } catch {}
  historyLoading.value = false;
}

function confirmRollback(item) { rollbackTarget.value = item; rollbackDialog.value = true; }

async function doRollback() {
  rolling.value = true;
  try {
    await api.post(`/admin/transaction-import/${rollbackTarget.value.id}/rollback`);
    rollbackDialog.value = false;
    loadHistory();
  } catch {}
  rolling.value = false;
}

async function runCalculation(item) {
  calculatingId.value = item.id;
  try {
    const { data } = await api.post(`/admin/transaction-import/${item.id}/calculate`);
    result.value = { message: data.message, success: data.success, errors: data.errors };
  } catch (e) {
    result.value = { message: e.response?.data?.message || 'Ошибка расчёта', errors: 1, success: 0 };
  }
  calculatingId.value = null;
}

function showErrors(item) { errorsTarget.value = item; errorsDialog.value = true; }

onMounted(async () => {
  try {
    const { data } = await api.get('/admin/transaction-import/form-data');
    counterparties.value = data.counterparties;
    currencies.value = data.currencies;
  } catch {}
  // Load Google Sheets names
  loadingSheets.value = true;
  try {
    const { data } = await api.get('/admin/transaction-import/sheet-names');
    sheetNames.value = data.sheets || [];
  } catch {}
  loadingSheets.value = false;
  loadHistory();
});
</script>
