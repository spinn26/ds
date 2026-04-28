<template>
  <div>
    <PageHeader title="Загрузка контрактов" icon="mdi-upload" />

    <!-- Этап 1. Триггер импорта (per spec ✅Загрузка контрактов §1) -->
    <v-card v-if="!sessionId" class="mb-4 pa-4">
      <v-row dense>
        <v-col cols="12" sm="6">
          <v-select v-model="form.sheet" :items="sheetNames" label="Лист *"
            density="compact" variant="outlined" :loading="loadingSheets"
            :no-data-text="sheetsError || 'Листы не найдены'"
            :hint="sheetsError || ''" persistent-hint />
        </v-col>
        <v-col cols="12" sm="3">
          <v-select v-model="form.currency" :items="currencies" item-title="symbol" item-value="id"
            label="Валюта по умолчанию" density="compact" variant="outlined" clearable />
        </v-col>
        <v-col cols="12" sm="3" class="d-flex align-center">
          <v-btn color="primary" size="large" prepend-icon="mdi-upload"
            :loading="loadingPreview" :disabled="!form.sheet"
            @click="loadPreview" block>
            Загрузить контракты
          </v-btn>
        </v-col>
      </v-row>

      <v-alert type="info" variant="tonal" density="compact" class="mt-3" icon="mdi-information">
        Контракты сначала попадают в буферную зону. Сохранятся в БД только
        после ручной проверки и подтверждения. Строки с ошибками подсвечены
        красным треугольником и блокируют сохранение.
      </v-alert>
    </v-card>

    <!-- Этап 2. Буферная таблица с индикацией ошибок -->
    <v-card v-if="sessionId">
      <v-card-title class="d-flex align-center ga-3 flex-wrap">
        <v-icon color="info">mdi-database-eye</v-icon>
        Предварительный реестр
        <v-chip v-if="stats.total" size="small" variant="tonal">{{ stats.total }} строк</v-chip>
        <v-chip v-if="stats.validCount" size="small" color="success" variant="tonal">
          ✓ {{ stats.validCount }} валидных
        </v-chip>
        <v-chip v-if="stats.invalidCount" size="small" color="error" variant="tonal">
          ✗ {{ stats.invalidCount }} с ошибками
        </v-chip>
        <v-spacer />
        <v-btn variant="text" color="error" prepend-icon="mdi-trash-can-outline"
          @click="clearAll">
          Удалить все контракты
        </v-btn>
        <v-btn variant="text" prepend-icon="mdi-refresh" @click="loadList">
          Обновить
        </v-btn>
      </v-card-title>

      <v-table density="compact" class="preview-table">
        <thead>
          <tr>
            <th style="width:40px"></th>
            <th>№ контракта</th>
            <th>Клиент (ID)</th>
            <th>Продукт (ID)</th>
            <th>Программа (ID)</th>
            <th class="text-end">Сумма</th>
            <th>Дата создания</th>
            <th style="width:100px">Действия</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in items" :key="row.id" :class="{ 'preview-row-invalid': row.status === 'invalid' }">
            <td>
              <v-tooltip v-if="row.status === 'invalid'" location="right">
                <template #activator="{ props }">
                  <v-icon v-bind="props" color="error" size="22">mdi-alert</v-icon>
                </template>
                <div class="text-caption">
                  <div v-for="e in row.errors" :key="e.field">
                    <strong>{{ e.field }}:</strong> {{ e.message }}
                  </div>
                </div>
              </v-tooltip>
              <v-icon v-else color="success" size="22">mdi-check-circle</v-icon>
            </td>
            <td>
              <span :class="hasFieldError(row, 'number') ? 'text-error' : ''">
                {{ row.rowData?.number || '—' }}
              </span>
            </td>
            <td>{{ row.rowData?.client || '—' }}</td>
            <td>{{ row.rowData?.product || '—' }}</td>
            <td>{{ row.rowData?.program || '—' }}</td>
            <td class="text-end">{{ row.rowData?.ammount || row.rowData?.amount || '—' }}</td>
            <td>{{ row.rowData?.createDate || '—' }}</td>
            <td>
              <v-btn icon="mdi-pencil" size="x-small" variant="text" color="success"
                title="Редактировать" @click="openEdit(row)" />
              <v-btn icon="mdi-trash-can-outline" size="x-small" variant="text" color="error"
                title="Удалить строку" @click="deleteRow(row)" />
            </td>
          </tr>
          <tr v-if="!items.length">
            <td colspan="8" class="text-center text-medium-emphasis pa-4">Буфер пуст</td>
          </tr>
        </tbody>
      </v-table>

      <v-card-actions class="d-flex flex-wrap ga-2 pa-3">
        <v-spacer />
        <v-btn variant="text" @click="exitPreview">Отменить весь импорт</v-btn>
        <!-- Per spec §3.2: большая зелёная кнопка появляется ТОЛЬКО когда нет ошибок -->
        <v-btn v-if="canFinalize" color="success" size="large" prepend-icon="mdi-content-save"
          :loading="finalizing" @click="finalizeImport">
          Сохранить заполненные контракты ({{ stats.validCount }})
        </v-btn>
        <v-alert v-else type="warning" variant="tonal" density="compact" class="ma-0">
          Кнопка сохранения появится когда все строки будут без ошибок.
          Текущие проблемы: {{ stats.invalidCount }}.
        </v-alert>
      </v-card-actions>
    </v-card>

    <!-- Inline-edit modal -->
    <v-dialog v-model="editOpen" max-width="640">
      <v-card v-if="editingRow">
        <v-card-title>Редактирование строки буфера</v-card-title>
        <v-card-text>
          <v-alert v-if="editingRow.errors?.length" type="error" variant="tonal" density="compact" class="mb-3">
            <div v-for="e in editingRow.errors" :key="e.field" class="text-body-2">
              <strong>{{ e.field }}:</strong> {{ e.message }}
            </div>
          </v-alert>

          <v-row dense>
            <v-col cols="12" sm="6">
              <v-text-field v-model="editForm.number" label="№ контракта *"
                density="compact" variant="outlined" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model.number="editForm.client" label="Client ID *" type="number"
                density="compact" variant="outlined" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model.number="editForm.product" label="Product ID *" type="number"
                density="compact" variant="outlined" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model.number="editForm.program" label="Program ID" type="number"
                density="compact" variant="outlined" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model.number="editForm.ammount" label="Сумма *" type="number"
                density="compact" variant="outlined" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model.number="editForm.currency" label="Currency ID" type="number"
                density="compact" variant="outlined" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="editForm.createDate" label="Дата создания" type="date"
                density="compact" variant="outlined" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="editForm.openDate" label="Дата открытия" type="date"
                density="compact" variant="outlined" />
            </v-col>
            <v-col cols="12">
              <v-textarea v-model="editForm.comment" label="Комментарий"
                density="compact" variant="outlined" rows="2" />
            </v-col>
          </v-row>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="editOpen = false">Отмена</v-btn>
          <v-btn color="primary" :loading="editSaving" @click="saveEdit">
            Сохранить и перепроверить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="4000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import { useConfirm } from '../../composables/useConfirm';

const confirm = useConfirm();

const form = ref({ sheet: null, currency: null });
const sheetNames = ref([]);
const sheetsError = ref('');
const currencies = ref([]);
const loadingSheets = ref(false);
const loadingPreview = ref(false);
const finalizing = ref(false);
const sessionId = ref(null);
const items = ref([]);
const stats = ref({ total: 0, validCount: 0, invalidCount: 0 });

const editOpen = ref(false);
const editingRow = ref(null);
const editForm = ref({});
const editSaving = ref(false);

const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

const canFinalize = computed(() =>
  stats.value.total > 0 && stats.value.invalidCount === 0
);

function hasFieldError(row, field) {
  return (row.errors || []).some(e => e.field === field);
}

async function loadSheetNames() {
  loadingSheets.value = true;
  try {
    const { data } = await api.get('/admin/contract-import/sheet-names');
    sheetNames.value = (data.sheets || []).map(s => s.name || s);
    if (data.message) sheetsError.value = data.message;
  } catch (e) {
    sheetsError.value = e.response?.data?.message || 'Ошибка загрузки списка листов';
  }
  loadingSheets.value = false;
}

async function loadCurrencies() {
  try {
    const { data } = await api.get('/admin/transaction-import/form-data');
    currencies.value = (data.currencies || []).map(c => ({
      id: c.id, symbol: c.symbol || c.name, name: c.name,
    }));
  } catch {}
}

async function loadPreview() {
  if (!form.value.sheet) return;
  loadingPreview.value = true;
  try {
    const { data } = await api.post('/admin/contract-import/preview/from-sheets', form.value);
    sessionId.value = data.sessionId;
    stats.value = { total: data.total, validCount: data.valid, invalidCount: data.invalid };
    notify(`Загружено: ${data.total} строк (валидных ${data.valid}, с ошибками ${data.invalid})`);
    await loadList();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка загрузки', 'error');
  }
  loadingPreview.value = false;
}

async function loadList() {
  if (!sessionId.value) return;
  try {
    const { data } = await api.get(`/admin/contract-import/preview/${sessionId.value}`);
    items.value = data.data;
    stats.value = {
      total: data.total,
      validCount: data.validCount,
      invalidCount: data.invalidCount,
    };
  } catch {}
}

function openEdit(row) {
  editingRow.value = row;
  editForm.value = { ...(row.rowData || {}) };
  editOpen.value = true;
}

async function saveEdit() {
  if (!editingRow.value) return;
  editSaving.value = true;
  try {
    const { data } = await api.patch(`/admin/contract-import/preview/row/${editingRow.value.id}`, editForm.value);
    editOpen.value = false;
    if (data.status === 'valid') {
      notify('Строка прошла валидацию');
    } else {
      notify(`Остались ошибки: ${data.errors.length}`, 'warning');
    }
    await loadList();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  editSaving.value = false;
}

async function deleteRow(row) {
  if (!await confirm.ask({ title: 'Удалить строку?', message: 'Строка будет удалена из буфера импорта.', confirmText: 'Удалить', confirmColor: 'error', icon: 'mdi-trash-can' })) return;
  try {
    await api.delete(`/admin/contract-import/preview/row/${row.id}`);
    await loadList();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
}

async function clearAll() {
  if (!await confirm.ask({ title: 'Очистить буфер?', message: 'Все строки буфера будут удалены. Импорт можно будет запустить заново.', confirmText: 'Очистить', confirmColor: 'error', icon: 'mdi-trash-can' })) return;
  try {
    await api.delete(`/admin/contract-import/preview/${sessionId.value}`);
    sessionId.value = null;
    items.value = [];
    notify('Буфер очищен');
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
}

async function finalizeImport() {
  if (!canFinalize.value) return;
  if (!await confirm.ask({
    title: 'Сохранить контракты в БД?',
    message: `${stats.value.validCount} валидных строк будет добавлено в основную таблицу контрактов. Действие необратимо.`,
    confirmText: 'Сохранить', confirmColor: 'success', icon: 'mdi-content-save',
  })) return;
  finalizing.value = true;
  try {
    const { data } = await api.post(`/admin/contract-import/preview/${sessionId.value}/finalize`, {});
    notify(data.message || `Сохранено: ${data.written}`);
    sessionId.value = null;
    items.value = [];
    stats.value = { total: 0, validCount: 0, invalidCount: 0 };
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка фиксации', 'error');
  }
  finalizing.value = false;
}

async function exitPreview() {
  if (!await confirm.ask({
    title: 'Отменить импорт?',
    message: 'Все строки буфера будут потеряны. Это действие нельзя отменить.',
    confirmText: 'Отменить импорт', confirmColor: 'warning', icon: 'mdi-close-circle',
  })) return;
  await clearAll();
}

onMounted(() => {
  loadSheetNames();
  loadCurrencies();
});
</script>

<style scoped>
.preview-table :deep(td) { vertical-align: middle; }
.preview-table :deep(th) {
  background: rgba(var(--v-theme-surface-variant), 0.4);
  font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px;
}
.preview-row-invalid td { background: rgba(244, 67, 54, 0.06); }
</style>
