<template>
  <div>
    <PageHeader title="Загрузка контрактов" icon="mdi-upload" />

    <v-row>
      <v-col cols="12" md="6">
        <v-card class="pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">
            <v-icon class="mr-1">mdi-file-upload</v-icon> Загрузить файл контрактов
          </div>
          <v-alert type="info" variant="tonal" density="compact" class="mb-4">
            Поддерживаемые форматы: CSV, XLSX. Файл должен содержать колонки: номер контракта, клиент, продукт, программа, сумма, дата открытия.
          </v-alert>

          <v-file-input v-model="file" label="Выберите файл" accept=".csv,.xlsx,.xls"
            prepend-icon="" prepend-inner-icon="mdi-file-document" class="mb-3"
            :rules="[v => !!v || 'Файл обязателен']" />

          <v-select v-model="format" :items="formats" label="Формат файла" class="mb-3" />

          <v-checkbox v-model="skipFirst" label="Пропустить первую строку (заголовки)" density="compact" hide-details class="mb-3" />

          <v-btn color="primary" :loading="uploading" :disabled="!file" prepend-icon="mdi-upload" @click="upload" block>
            Загрузить
          </v-btn>

          <v-alert v-if="result" :type="result.type" density="compact" class="mt-4">
            {{ result.message }}
          </v-alert>
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

const file = ref(null);
const format = ref('auto');
const skipFirst = ref(true);
const uploading = ref(false);
const result = ref(null);
const history = ref([]);

const formats = [
  { title: 'Автоопределение', value: 'auto' },
  { title: 'CSV (;)', value: 'csv' },
  { title: 'XLSX', value: 'xlsx' },
];

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

onMounted(loadHistory);
</script>
