<template>
  <div>
    <PageHeader title="Отчёты" icon="mdi-file-chart" />

    <!-- 1.1 Сгенерировать отчёт -->
    <v-card class="mb-4">
      <v-card-title class="text-subtitle-1 d-flex align-center ga-2">
        <v-icon size="20">mdi-plus-circle</v-icon>
        Сгенерировать отчёт
      </v-card-title>
      <v-card-text>
        <v-row dense>
          <v-col cols="12" sm="3">
            <v-text-field v-model="form.dateFrom" label="Период с" type="date"
              variant="outlined" density="comfortable" />
          </v-col>
          <v-col cols="12" sm="3">
            <v-text-field v-model="form.dateTo" label="Период по" type="date"
              variant="outlined" density="comfortable" />
          </v-col>
          <v-col cols="12" sm="6">
            <v-select v-model="form.type" :items="reportTypes" item-title="label" item-value="value"
              label="Тип отчёта" variant="outlined" density="comfortable" />
          </v-col>
        </v-row>

        <!-- Динамические фильтры -->
        <v-row v-if="form.type === 'partner_status'" dense>
          <v-col cols="12" sm="6">
            <v-select v-model="form.activity" :items="activityOptions" label="Статус активности"
              variant="outlined" density="comfortable" clearable />
          </v-col>
        </v-row>

        <v-btn color="primary" size="large" prepend-icon="mdi-download"
          :disabled="!canGenerate" :loading="generating" @click="generate">
          {{ generateLabel }}
        </v-btn>
      </v-card-text>
    </v-card>

    <!-- 1.2 Архив отчётов -->
    <v-card>
      <v-card-title class="d-flex align-center ga-2">
        <v-icon size="20">mdi-archive</v-icon>
        Скачать отчёты
        <v-spacer />
        <v-select v-model="archiveFilter" :items="reportTypes" item-title="label" item-value="value"
          placeholder="Тип отчёта" density="compact" variant="outlined" clearable hide-details
          style="max-width:280px" />
        <v-btn v-if="archiveFilter" size="small" variant="text" color="secondary"
          prepend-icon="mdi-filter-remove" @click="archiveFilter = null">Очистить</v-btn>
      </v-card-title>

      <v-data-table :items="filteredArchive" :headers="archiveHeaders"
        density="compact" :items-per-page="25">
        <template #item.type="{ item }">
          <v-chip size="x-small" variant="tonal">{{ reportLabel(item.type) }}</v-chip>
        </template>
        <template #item.status="{ value }">
          <v-chip size="x-small" :color="statusColor(value)" variant="tonal">{{ statusLabel(value) }}</v-chip>
        </template>
        <template #item.file="{ item }">
          <v-btn v-if="item.status === 'ready'" size="x-small" variant="tonal" color="info"
            prepend-icon="mdi-cloud-download" @click="downloadReport(item)">
            Скачать
          </v-btn>
          <span v-else class="text-medium-emphasis">—</span>
        </template>
        <template #item.dateFrom="{ value }">{{ fmtDate(value) }}</template>
        <template #item.dateTo="{ value }">{{ fmtDate(value) }}</template>
        <template #item.createdAt="{ value }">{{ fmtDateTime(value) }}</template>
        <template #no-data><EmptyState message="Архив пуст" /></template>
      </v-data-table>
    </v-card>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="4000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import { fmtDate } from '../../composables/useDesign';

const generating = ref(false);
const archive = ref([]);
const archiveFilter = ref(null);

// Per spec ✅Отчеты.md §1.1: список из 7 типов отчётов.
const reportTypes = [
  { label: 'Реестр выплат', value: 'payment_registry' },
  { label: 'Квалификации', value: 'qualifications' },
  { label: 'Комиссии', value: 'commissions' },
  { label: 'Выручка и расходы по продуктам', value: 'revenue_expenses' },
  { label: '[Финрез] Транзакции', value: 'finrez_transactions' },
  { label: '[Финрез] Комиссии по ФК', value: 'finrez_commissions' },
  { label: 'Статусы партнёров', value: 'partner_status' },
];

const activityOptions = [
  { title: 'Зарегистрирован', value: 4 },
  { title: 'Активен', value: 1 },
  { title: 'Терминирован', value: 3 },
  { title: 'Исключён', value: 5 },
];

const form = ref({ dateFrom: '', dateTo: '', type: null, activity: null });

const canGenerate = computed(() => form.value.dateFrom && form.value.dateTo && form.value.type);

const generateLabel = computed(() => {
  if (!form.value.type) return 'Сгенерировать отчёт';
  const t = reportLabel(form.value.type);
  if (form.value.dateFrom && form.value.dateTo) {
    return `Сгенерировать «${t}» за период с ${fmtDate(form.value.dateFrom)} по ${fmtDate(form.value.dateTo)}`;
  }
  return `Сгенерировать «${t}»`;
});

function reportLabel(value) {
  return reportTypes.find(r => r.value === value)?.label || value;
}
function statusColor(s) {
  return { ready: 'success', generating: 'warning', error: 'error' }[s] || 'grey';
}
function statusLabel(s) {
  return { ready: 'Отчёт сформирован', generating: 'Генерируем файл', error: 'Ошибка' }[s] || s;
}
function fmtDateTime(d) {
  if (!d) return '—';
  return new Date(d).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}

const archiveHeaders = [
  { title: 'Тип отчёта', key: 'type', width: 220 },
  { title: 'Статус', key: 'status', width: 200 },
  { title: 'Файл', key: 'file', width: 130 },
  { title: 'Период с', key: 'dateFrom', width: 130 },
  { title: 'Период по', key: 'dateTo', width: 130 },
  { title: 'Дата формирования', key: 'createdAt', width: 170 },
];

const filteredArchive = computed(() => {
  if (!archiveFilter.value) return archive.value;
  return archive.value.filter(r => r.type === archiveFilter.value);
});

const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

async function generate() {
  if (!canGenerate.value) return;
  generating.value = true;
  try {
    const { data } = await api.post('/admin/reports/generate', {
      type: form.value.type,
      date_from: form.value.dateFrom,
      date_to: form.value.dateTo,
      activity: form.value.activity,
    });
    notify(data.message || 'Отчёт поставлен в очередь');
    await loadArchive();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка генерации', 'error');
  }
  generating.value = false;
}

async function loadArchive() {
  try {
    const { data } = await api.get('/admin/reports/archive');
    archive.value = data.data || [];
  } catch {}
}

/**
 * Скачивание через XHR с Bearer-токеном — прямая ссылка на endpoint
 * требует Authorization header (через `auth:sanctum`), который браузер
 * не пошлёт при `<a target=_blank>`. Поэтому грузим как Blob и
 * триггерим скачивание программно.
 */
async function downloadReport(item) {
  try {
    const resp = await api.get(`/admin/reports/${item.id}/download`, {
      responseType: 'blob',
    });
    const filename = `report-${item.type}-${item.dateFrom}-${item.dateTo}.csv`;
    const url = URL.createObjectURL(resp.data);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  } catch (e) {
    notify(e.response?.data?.message || 'Не удалось скачать отчёт', 'error');
  }
}

onMounted(loadArchive);
</script>
