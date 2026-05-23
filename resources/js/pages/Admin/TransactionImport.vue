<template>
  <div>
    <PageHeader title="Импорт транзакций" icon="mdi-upload" />

    <!-- Import form -->
    <v-card class="mb-4 pa-4">
      <v-tabs v-model="importMode" class="mb-3">
        <v-tab value="sheets" prepend-icon="mdi-google-spreadsheet">Google Sheets</v-tab>
        <v-tab value="file" prepend-icon="mdi-file-upload">Загрузить файл</v-tab>
      </v-tabs>

      <!-- Google Sheets mode -->
      <v-alert v-if="importMode === 'sheets' && sheetsError" type="warning" variant="tonal"
        density="compact" class="mb-3" closable>
        <div class="font-weight-medium">{{ sheetsError }}</div>
        <div class="text-caption mt-1">
          Заполни <strong>Google Sheets API Key</strong> и
          <strong>ID таблицы «Импорт транзакций»</strong> в
          <a href="/admin/api-keys" class="text-primary">/admin/api-keys</a>.
        </div>
      </v-alert>
      <v-row v-if="importMode === 'sheets'" dense>
        <v-col cols="12" sm="4">
          <v-select v-model="form.sheet" :items="sheetNames" label="Лист *"
            item-title="name" item-value="name"
            density="compact" variant="outlined" :loading="loadingSheets"
            :disabled="!!sheetsError"
            :no-data-text="sheetsError || 'Листы не найдены'" />
          <div v-if="selectedSheet?.profiled" class="text-caption text-success mt-1">
            <v-icon size="14">mdi-check-circle</v-icon>
            Поставщик: <b>{{ selectedSheet.counterpartyName }}</b>
            <template v-if="selectedSheet.productHint"> · {{ selectedSheet.productHint }}</template>
          </div>
          <div v-else-if="form.sheet" class="text-caption text-warning mt-1">
            <v-icon size="14">mdi-alert</v-icon>
            Лист не распознан, выбери поставщика вручную
          </div>
        </v-col>
        <v-col cols="12" sm="3">
          <v-select v-model="form.counterparty" :items="counterparties" item-title="name" item-value="id"
            label="Поставщик в БД" density="compact" variant="outlined"
            :disabled="selectedSheet?.profiled"
            :hint="selectedSheet?.profiled ? 'Автоопределён по профилю' : ''"
            persistent-hint />
        </v-col>
        <v-col cols="12" sm="3">
          <v-select v-model="form.currency" :items="currencies" item-title="symbol" item-value="id"
            label="Валюта" density="compact" variant="outlined" clearable />
        </v-col>
        <v-col cols="12" sm="2" class="d-flex align-end">
          <v-btn v-if="canFull('import')" color="primary" block prepend-icon="mdi-import" :loading="importing"
            :disabled="!form.sheet || (!selectedSheet?.profiled && !form.counterparty)"
            @click="runSheetsImport">
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
          <v-btn v-if="canFull('import')" color="primary" block prepend-icon="mdi-import" :loading="importing"
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
        <div v-if="result.needsCalc && result.importId" class="mt-3">
          <v-btn size="small" color="primary" variant="flat" prepend-icon="mdi-calculator"
            :loading="calculatingId === result.importId"
            @click="runCalculation({ id: result.importId })">
            Рассчитать комиссии сейчас
          </v-btn>
        </div>
      </v-alert>
    </v-card>

    <!-- Import history -->
    <v-card class="pa-4">
      <div class="d-flex justify-space-between align-center mb-3">
        <div class="text-subtitle-1 font-weight-bold">История импорта</div>
        <div class="d-flex align-center ga-2">
          <ColumnVisibilityMenu
            :headers="historyHeaders"
            v-model:visible="historyColumnVisible"
            storage-key="transaction-import-history-cols" />
          <v-btn size="small" variant="text" prepend-icon="mdi-refresh" @click="loadHistory">Обновить</v-btn>
        </div>
      </div>

      <v-data-table-server :items="history" :items-length="historyTotal" :loading="historyLoading"
        :headers="visibleHistoryHeaders" :items-per-page="historyPerPage"
        :items-per-page-options="[25, 50, 100, 200]"
        @update:options="onHistoryOptions" density="compact" hover>
        <template #item.status="{ value }">
          <StatusChip :value="value" kind="import" size="x-small" :text="statusLabel(value)" />
        </template>
        <template #item.counts="{ item }">
          <span class="text-success">{{ item.successCount }}</span>
          <span v-if="item.errorCount > 0" class="text-error ml-1">/ {{ item.errorCount }} ош.</span>
        </template>
        <template #item.calc="{ item }">
          <v-chip v-if="item.calcStatus === 'done'" size="x-small" color="success" variant="tonal"
            prepend-icon="mdi-check-circle">
            Рассчитано {{ item.calcSuccess }} / {{ item.calcTotal }}
            <v-tooltip activator="parent">
              Расчёт комиссий завершён {{ fmtDate(item.calcDoneAt) }}
            </v-tooltip>
          </v-chip>
          <v-chip v-else-if="item.calcStatus === 'partial'" size="x-small" color="warning" variant="tonal"
            prepend-icon="mdi-alert-circle-outline">
            Частично {{ item.calcSuccess }} / {{ item.calcTotal }}
            <v-tooltip activator="parent">
              Расчёт частичный, ошибок: {{ item.calcErrors }} ({{ fmtDate(item.calcDoneAt) }})
            </v-tooltip>
          </v-chip>
          <v-chip v-else-if="item.calcStatus === 'running'" size="x-small" color="info" variant="tonal"
            prepend-icon="mdi-loading">
            Считается {{ item.calcSuccess || 0 }} / {{ item.calcTotal }}
          </v-chip>
          <v-chip v-else-if="item.calcStatus === 'error'" size="x-small" color="error" variant="tonal"
            prepend-icon="mdi-alert">
            Ошибка расчёта
          </v-chip>
          <v-chip v-else-if="item.status === 'success' || item.status === 'partial'"
            size="x-small" color="grey" variant="tonal">
            Не рассчитан
          </v-chip>
          <span v-else class="text-medium-emphasis">—</span>
        </template>
        <template #item.createdAt="{ value }">{{ fmtDate(value) }}</template>
        <template #item.actions="{ item }">
          <v-chip v-if="item.frozen" size="x-small" color="grey" variant="tonal" prepend-icon="mdi-lock" class="mr-1">
            закрыт
            <v-tooltip activator="parent">Период закрыт — действия с импортом запрещены</v-tooltip>
          </v-chip>
          <v-btn v-if="canFull('import') && (item.status === 'success' || item.status === 'partial')"
            icon size="x-small" variant="text" color="primary"
            :loading="calculatingId === item.id"
            :disabled="item.frozen"
            @click="runCalculation(item)">
            <v-icon>mdi-calculator</v-icon>
            <v-tooltip activator="parent">
              {{ item.frozen ? 'Период закрыт — расчёт запрещён' : 'Рассчитать комиссии' }}
            </v-tooltip>
          </v-btn>
          <v-btn v-if="canFull('import') && item.status !== 'rolled_back'"
            icon size="x-small" variant="text" color="warning"
            :disabled="item.frozen"
            @click="confirmRollback(item)">
            <v-icon>mdi-undo</v-icon>
            <v-tooltip activator="parent">
              {{ item.frozen ? 'Период закрыт — откат запрещён' : 'Откатить' }}
            </v-tooltip>
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

    <DialogShell
      v-model="rollbackDialog"
      :title="`Откатить импорт #${rollbackTarget?.id}?`"
      :max-width="400"
      :loading="rolling"
      confirm-text="Откатить"
      confirm-color="error"
      @confirm="doRollback"
    >
      Все {{ rollbackTarget?.successCount }} транзакций, созданных этим импортом, будут удалены.
    </DialogShell>

    <DialogShell
      v-model="errorsDialog"
      :title="`Ошибки импорта #${errorsTarget?.id}`"
      :max-width="700"
      :show-confirm="false"
      cancel-text="Закрыть"
    >
      <template #header-extra>
        <v-btn size="small" variant="tonal" color="primary"
          prepend-icon="mdi-download"
          @click="downloadErrorsCsv(errorsTarget?.id)">
          Скачать CSV
        </v-btn>
      </template>
      <div v-for="(err, i) in errorsTarget?.errors || []" :key="'e' + i" class="text-body-2 mb-1">
        <v-icon size="14" color="error" class="mr-1">mdi-alert</v-icon>{{ err }}
      </div>
      <div v-if="errorsTarget?.warnings?.length" class="mt-3">
        <div class="text-caption text-medium-emphasis mb-1">Предупреждения (импорт прошёл, проверьте):</div>
        <div v-for="(w, i) in errorsTarget.warnings" :key="'w' + i" class="text-body-2 mb-1">
          <v-icon size="14" color="warning" class="mr-1">mdi-alert-outline</v-icon>{{ w }}
        </div>
      </div>
    </DialogShell>

    <v-dialog v-model="dupDialog" max-width="520" persistent>
      <v-card>
        <v-card-title class="d-flex align-center">
          <v-icon color="warning" class="mr-2">mdi-alert</v-icon>
          В этом месяце уже был импорт
        </v-card-title>
        <v-card-text>
          <div class="text-body-2 mb-2">
            Для этого поставщика в текущем месяце уже выполнялся импорт.
            Запуск повторного импорта <strong>может создать дубли транзакций</strong>.
          </div>
          <div class="text-caption text-medium-emphasis mb-2">Предыдущие импорты:</div>
          <div v-for="r in dupRecent" :key="r.id" class="text-body-2 mb-1">
            <v-chip size="x-small" color="primary" variant="tonal" class="mr-1">#{{ r.id }}</v-chip>
            {{ r.successCount }} стр. · {{ fmtDate(r.createdAt) }}
          </div>
          <v-alert type="info" variant="tonal" density="compact" class="mt-3 text-caption">
            Если этот файл — переимпорт того же периода, сначала откатите старый импорт
            кнопкой «Откатить» в истории, иначе будут дубли.
          </v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="dupCancel">Отмена</v-btn>
          <v-btn color="warning" variant="flat" @click="dupConfirm">Импортировать всё равно</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <ImportProgressDialog
      v-model="progressOpen"
      :tracker="progressTracker"
      :result="progressResult"
      :finished="progressFinished"
      title="Импорт транзакций"
      @finish="onImportFinish"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import StatusChip from '../../components/StatusChip.vue';
import PageHeader from '../../components/PageHeader.vue';
import DialogShell from '../../components/DialogShell.vue';
import ImportProgressDialog from '../../components/ImportProgressDialog.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import { usePermissions } from '../../composables/usePermissions';

const { canFull } = usePermissions();

const counterparties = ref([]);
const currencies = ref([]);
const sheetNames = ref([]);
const sheetsError = ref('');
const progressOpen = ref(false);
const progressTracker = ref(null);
const progressResult = ref(null);
const progressFinished = ref(false);
const selectedSheet = computed(() => {
  if (!form.value.sheet) return null;
  return sheetNames.value.find(s => (s.name || s) === form.value.sheet) || null;
});
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
const historyPerPage = ref(25);

// Dialogs
const rollbackDialog = ref(false);
const rollbackTarget = ref(null);
const rolling = ref(false);
const calculatingId = ref(null);
const calcResult = ref(null);
const errorsDialog = ref(false);
const errorsTarget = ref(null);
const dupDialog = ref(false);
const dupRecent = ref([]);
let dupResolve = null;

function preflightDuplicateCheck({ counterparty = null, sheet = null }) {
  return new Promise(async (resolve) => {
    try {
      const params = {};
      if (counterparty) params.counterparty = counterparty;
      if (sheet) params.sheet = sheet;
      const { data } = await api.get('/admin/transaction-import/check-duplicate', { params });
      if (!data.has_recent) {
        resolve(true);
        return;
      }
      dupRecent.value = data.recent || [];
      dupResolve = resolve;
      dupDialog.value = true;
    } catch {
      // Не удалось проверить — не блокируем импорт, чтобы не парализовать
      // оператора при упавшем endpoint'е.
      resolve(true);
    }
  });
}

function dupConfirm() {
  dupDialog.value = false;
  if (dupResolve) dupResolve(true);
  dupResolve = null;
}
function dupCancel() {
  dupDialog.value = false;
  if (dupResolve) dupResolve(false);
  dupResolve = null;
}

const historyHeaders = [
  { title: 'ID', key: 'id', width: 60 },
  { title: 'Поставщик', key: 'counterpartyName' },
  { title: 'Статус', key: 'status', width: 150 },
  { title: 'Всего строк', key: 'totalRows', width: 100 },
  { title: 'Результат', key: 'counts', width: 130, sortable: false },
  { title: 'Расчёт', key: 'calc', width: 200, sortable: false },
  { title: 'Дата', key: 'createdAt', width: 150 },
  { title: '', key: 'actions', sortable: false, width: 90 },
];

const historyColumnVisible = ref({});
const visibleHistoryHeaders = computed(() =>
  historyHeaders.filter(h => historyColumnVisible.value[h.key] !== false)
);

function fmtDate(d) { if (!d) return '—'; try { return new Date(d).toLocaleDateString('ru-RU') + ' ' + new Date(d).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }); } catch { return d; } }


function statusLabel(s) {
  return { success: 'Успешно', partial: 'Частично', error: 'Ошибка', processing: 'В процессе', rolled_back: 'Откачено', pending: 'Ожидание' }[s] || s;
}

async function runSheetsImport() {
  if (!form.value.sheet) return;
  if (!selectedSheet.value?.profiled && !form.value.counterparty) return;

  // Анти-дубли «для тупых»: в этом месяце уже был успешный импорт того
  // же поставщика? Просим оператора подтвердить.
  const ok = await preflightDuplicateCheck({
    counterparty: form.value.counterparty,
    sheet: form.value.sheet,
  });
  if (!ok) return;

  importing.value = true;
  result.value = null;
  progressResult.value = null;
  progressFinished.value = false;
  progressTracker.value = 'tx-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
  progressOpen.value = true;

  try {
    // Бэк возвращает 202 + {importId, tracker, status:'queued'} — job
    // продолжит в очереди, фронт берёт финальный результат из polling'а
    // (см. onImportFinish), а не из этого ответа.
    await api.post('/admin/transaction-import/from-sheets', {
      sheet: form.value.sheet,
      counterparty: form.value.counterparty,
      currency: form.value.currency,
      tracker: progressTracker.value,
    });
  } catch (e) {
    // 422 уйдёт только когда контроллер не успел поставить в очередь
    // (не настроен Sheets, нет counterparty для generic-листа и т.п.) —
    // в очередь ничего не легло, диалог сразу финализируем как ошибку.
    const d = e.response?.data;
    const payload = {
      message: d?.message || 'Ошибка импорта из Google Sheets',
      success: d?.success ?? 0,
      errors: d?.errors ?? 1,
      errorDetails: d?.errorDetails || [],
    };
    result.value = payload;
    progressResult.value = payload;
    progressFinished.value = true;
    importing.value = false;
  }
  // Успех: importing держим включённым пока ImportProgressDialog не
  // эмитнёт finish — тогда onImportFinish сбросит флаги.
}

async function runImport() {
  if (!form.value.counterparty || !form.value.file) return;

  const ok = await preflightDuplicateCheck({ counterparty: form.value.counterparty });
  if (!ok) return;

  importing.value = true;
  result.value = null;
  progressResult.value = null;
  progressFinished.value = false;
  progressTracker.value = 'tx-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
  progressOpen.value = true;

  try {
    const fd = new FormData();
    fd.append('file', form.value.file);
    fd.append('counterparty', form.value.counterparty);
    if (form.value.currency) fd.append('currency', form.value.currency);
    fd.append('tracker', progressTracker.value);
    // Аналогично runSheetsImport: 202 + tracker, финал — через polling.
    await api.post('/admin/transaction-import', fd);
    form.value.file = null;
  } catch (e) {
    const d = e.response?.data;
    const payload = {
      message: d?.message || 'Ошибка импорта',
      success: d?.success ?? 0,
      errors: d?.errors ?? 1,
      errorDetails: d?.errorDetails || [],
    };
    result.value = payload;
    progressResult.value = payload;
    progressFinished.value = true;
    importing.value = false;
  }
}

function onImportFinish(payload) {
  result.value = payload;
  progressResult.value = payload;
  progressFinished.value = true;
  importing.value = false;
  calculatingId.value = null;
  loadHistory();
}

function onHistoryOptions(opts) {
  historyPage.value = opts.page;
  if (opts.itemsPerPage) historyPerPage.value = opts.itemsPerPage;
  loadHistory();
}

async function loadHistory() {
  historyLoading.value = true;
  try {
    const { data } = await api.get('/admin/transaction-import/history', {
      params: { page: historyPage.value, per_page: historyPerPage.value },
    });
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
  // Расчёт async через очередь: 1267 транзакций × каскад = ~5-10 мин,
  // axios timeout 30s раньше падал как «Ошибка расчёта». Бэк теперь
  // отдаёт 202 + tracker, прогресс — через ImportProgressDialog.
  calculatingId.value = item.id;
  progressResult.value = null;
  progressFinished.value = false;
  try {
    const { data } = await api.post(`/admin/transaction-import/${item.id}/calculate`);
    if (data?.tracker) {
      progressTracker.value = data.tracker;
      progressOpen.value = true;
      // calculatingId сбросится в onImportFinish (когда диалог финализируется).
      return;
    }
    // Старый sync-режим (на случай отката бэка).
    result.value = { message: data.message, success: data.success, errors: data.errors };
    calculatingId.value = null;
  } catch (e) {
    result.value = { message: e.response?.data?.message || 'Ошибка расчёта', errors: 1, success: 0 };
    calculatingId.value = null;
  }
}

function showErrors(item) { errorsTarget.value = item; errorsDialog.value = true; }

function downloadErrorsCsv(importId) {
  if (!importId) return;
  // Открываем в новой вкладке: Sanctum token живёт в localStorage, axios
  // его автоматически подкладывает, но прямой <a download> идёт без header'ов.
  // Используем api.get с responseType=blob и принудительно скачиваем.
  api.get(`/admin/transaction-import/${importId}/errors.csv`, { responseType: 'blob' })
    .then(resp => {
      const blob = new Blob([resp.data], { type: 'text/csv;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `import-${importId}-errors.csv`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    });
}

onMounted(async () => {
  try {
    const { data } = await api.get('/admin/transaction-import/form-data');
    counterparties.value = data.counterparties;
    currencies.value = data.currencies;
  } catch {}
  // Load Google Sheets names
  loadingSheets.value = true;
  sheetsError.value = '';
  try {
    const { data } = await api.get('/admin/transaction-import/sheet-names');
    sheetNames.value = data.sheets || [];
    // Backend может вернуть 200 с пустым массивом и message (ключи не настроены)
    if (!sheetNames.value.length && data.message) {
      sheetsError.value = data.message;
    }
  } catch (e) {
    sheetsError.value = e.response?.data?.message || 'Не удалось загрузить список листов';
  }
  loadingSheets.value = false;
  loadHistory();
});
</script>
