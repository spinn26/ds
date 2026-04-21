<template>
  <div>
    <PageHeader title="Загрузка контрактов" icon="mdi-upload" />

    <v-row>
      <v-col cols="12" md="6">
        <v-card class="pa-4">
          <v-tabs v-model="mode" class="mb-3">
            <v-tab value="sheets" prepend-icon="mdi-google-spreadsheet">Google Sheets</v-tab>
            <v-tab value="file" prepend-icon="mdi-file-upload">Загрузить файл</v-tab>
          </v-tabs>

          <!-- Google Sheets -->
          <template v-if="mode === 'sheets'">
            <v-alert v-if="sheetsError" type="warning" variant="tonal" density="compact" class="mb-3" closable>
              <div class="font-weight-medium">{{ sheetsError }}</div>
              <div class="text-caption mt-1">
                Заполни <strong>Google Sheets API Key</strong> и
                <strong>ID таблицы «Импорт контрактов»</strong> в
                <a href="/admin/api-keys" class="text-primary">/admin/api-keys</a>.
              </div>
            </v-alert>

            <v-select v-model="form.sheet" :items="sheetNames" label="Лист *"
              density="compact" variant="outlined" class="mb-3" :loading="loadingSheets"
              :disabled="!!sheetsError"
              :no-data-text="sheetsError || 'Листы не найдены'" />

            <v-select v-model="form.currency" :items="currencies" item-title="symbol" item-value="id"
              label="Валюта по умолчанию" density="compact" variant="outlined" class="mb-3" clearable />

            <v-btn color="primary" :loading="importing" :disabled="!form.sheet || !!sheetsError"
              prepend-icon="mdi-import" @click="runSheetsImport" block>
              Импортировать из Sheets
            </v-btn>
          </template>

          <!-- File upload -->
          <template v-else>
            <v-alert type="info" variant="tonal" density="compact" class="mb-4">
              Поддерживаемые форматы: CSV, XLSX. Файл должен содержать колонки: номер контракта, клиент, продукт, программа, сумма, дата открытия.
            </v-alert>
            <v-file-input v-model="file" label="Выберите файл" accept=".csv,.xlsx,.xls"
              prepend-icon="" prepend-inner-icon="mdi-file-document" class="mb-3"
              :rules="[v => !!v || 'Файл обязателен']" />
            <v-select v-model="format" :items="formats" label="Формат файла" class="mb-3" />
            <v-checkbox v-model="skipFirst" label="Пропустить первую строку (заголовки)" density="compact" hide-details class="mb-3" />
            <v-btn color="primary" :loading="uploading" :disabled="!file"
              prepend-icon="mdi-upload" @click="upload" block>
              Загрузить файл
            </v-btn>
          </template>

          <v-alert v-if="result" :type="result.type" density="compact" class="mt-4">
            {{ result.message }}
          </v-alert>
          <v-expansion-panels v-if="result?.errorsList?.length" class="mt-2">
            <v-expansion-panel title="Ошибки" :text="result.errorsList.join('\n')" />
          </v-expansion-panels>
        </v-card>
      </v-col>

      <v-col cols="12" md="6">
        <v-card class="pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">
            <v-icon class="mr-1">mdi-history</v-icon> История загрузок
          </div>
          <v-list v-if="history.length" density="compact">
            <v-list-item v-for="h in history" :key="h.id">
              <template #prepend>
                <v-icon :color="h.status === 'success' ? 'success' : h.status === 'error' ? 'error' : 'warning'">
                  {{ h.status === 'success' ? 'mdi-check-circle' : h.status === 'error' ? 'mdi-alert-circle' : 'mdi-clock' }}
                </v-icon>
              </template>
              <v-list-item-title>{{ h.filename }}</v-list-item-title>
              <v-list-item-subtitle>
                {{ h.created }} · {{ h.success_count || 0 }} успешно / {{ h.error_count || 0 }} ошибок
              </v-list-item-subtitle>
            </v-list-item>
          </v-list>
          <div v-else class="text-center text-medium-emphasis pa-6">Нет загрузок</div>
        </v-card>

        <v-card class="pa-4 mt-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">
            <v-icon class="mr-1">mdi-information</v-icon> Шаблон файла
          </div>
          <v-table density="compact">
            <thead>
              <tr>
                <th>Колонка</th>
                <th>Описание</th>
                <th>Обязательна</th>
              </tr>
            </thead>
            <tbody>
              <tr><td>number</td><td>Номер контракта</td><td>Да</td></tr>
              <tr><td>client_name</td><td>ФИО клиента</td><td>Да</td></tr>
              <tr><td>consultant_name</td><td>ФИО консультанта</td><td>Нет</td></tr>
              <tr><td>product_name</td><td>Название продукта</td><td>Да</td></tr>
              <tr><td>program_name</td><td>Название программы</td><td>Нет</td></tr>
              <tr><td>amount</td><td>Сумма контракта</td><td>Да</td></tr>
              <tr><td>currency</td><td>Валюта (RUB, USD, EUR)</td><td>Нет</td></tr>
              <tr><td>open_date</td><td>Дата открытия (DD.MM.YYYY)</td><td>Да</td></tr>
              <tr><td>term</td><td>Срок контракта</td><td>Нет</td></tr>
            </tbody>
          </v-table>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';

const mode = ref('sheets');
const file = ref(null);
const format = ref('auto');
const skipFirst = ref(true);
const uploading = ref(false);
const importing = ref(false);
const result = ref(null);
const history = ref([]);

const sheetNames = ref([]);
const sheetsError = ref('');
const loadingSheets = ref(false);
const currencies = ref([]);
const form = ref({ sheet: null, currency: null });

const formats = [
  { title: 'Автоопределение', value: 'auto' },
  { title: 'CSV (;)', value: 'csv' },
  { title: 'XLSX', value: 'xlsx' },
];

async function loadSheets() {
  loadingSheets.value = true;
  sheetsError.value = '';
  try {
    const { data } = await api.get('/admin/contract-import/sheet-names');
    sheetNames.value = data.sheets || [];
    if (!sheetNames.value.length && data.message) sheetsError.value = data.message;
  } catch (e) {
    sheetsError.value = e.response?.data?.message || 'Не удалось загрузить листы';
  }
  loadingSheets.value = false;
}

async function loadCurrencies() {
  try {
    const { data } = await api.get('/admin/references/currency');
    currencies.value = (data.items || []).map(c => ({ id: c.id, symbol: c.symbol || c.nameRu }));
  } catch {}
}

async function runSheetsImport() {
  if (!form.value.sheet) return;
  importing.value = true;
  result.value = null;
  try {
    const { data } = await api.post('/admin/contract-import/from-sheets', {
      sheet: form.value.sheet,
      currency: form.value.currency,
    });
    const t = data.errors === 0 ? 'success' : data.success > 0 ? 'warning' : 'error';
    result.value = {
      type: t,
      message: `Импортировано: ${data.success} / ${data.total}. Ошибок: ${data.errors}`,
      errorsList: data.errorsList || [],
    };
    loadHistory();
  } catch (e) {
    result.value = { type: 'error', message: e.response?.data?.message || 'Ошибка импорта' };
  }
  importing.value = false;
}

async function upload() {
  if (!file.value) return;
  uploading.value = true;
  result.value = null;
  try {
    const fd = new FormData();
    fd.append('file', file.value);
    fd.append('format', format.value);
    fd.append('skip_first', skipFirst.value ? '1' : '0');
    const { data } = await api.post('/admin/contracts/upload', fd);
    result.value = { type: 'success', message: `Загружено: ${data.success || 0} контрактов. Ошибок: ${data.errors || 0}` };
    file.value = null;
    loadHistory();
  } catch (e) {
    result.value = { type: 'error', message: e.response?.data?.message || 'Ошибка загрузки' };
  }
  uploading.value = false;
}

async function loadHistory() {
  try {
    const { data } = await api.get('/admin/contracts/upload-history');
    history.value = data || [];
  } catch {}
}


onMounted(() => { loadSheets(); loadCurrencies(); loadHistory(); });
</script>
