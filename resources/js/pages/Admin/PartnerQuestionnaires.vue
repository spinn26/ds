<template>
  <div>
    <PageHeader title="Анкеты партнёров" icon="mdi-clipboard-account" :count="total">
      <template #actions>
        <v-btn color="primary" prepend-icon="mdi-microsoft-excel" :loading="exporting"
          variant="tonal" size="small" @click="exportCsv">
          Выгрузить Excel
        </v-btn>
      </template>
    </PageHeader>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по ФИО / e-mail / телефону"
          density="compact" variant="outlined" hide-details rounded clearable
          prepend-inner-icon="mdi-magnify" style="max-width:360px"
          @update:model-value="onFilterChange" />
        <v-checkbox v-model="onlyCompleted" label="Только заполненные"
          density="compact" hide-details color="primary"
          @update:model-value="onFilterChange" />
        <v-spacer />
      </div>
    </v-card>

    <v-card>
      <v-data-table-server v-model:items-per-page="perPage" v-model:page="page"
        :items="rows" :headers="headers" :items-length="total" :loading="loading"
        density="comfortable" hover @update:options="loadServer">
        <template #item.completed_at="{ item }">
          <span v-if="item.completed_at" class="text-caption">
            {{ formatDate(item.completed_at) }}
          </span>
          <v-chip v-else size="x-small" color="warning" variant="tonal">
            Не заполнено
          </v-chip>
        </template>
        <template #item.actions="{ item }">
          <v-btn :disabled="!item.completed_at" size="small" variant="text"
            prepend-icon="mdi-eye" @click="openDialog(item)">
            Посмотреть
          </v-btn>
        </template>
        <template #no-data>
          <EmptyState message="Анкет не найдено" />
        </template>
      </v-data-table-server>
    </v-card>

    <!-- Просмотр анкеты -->
    <v-dialog v-model="dialog" max-width="640">
      <v-card v-if="current">
        <v-card-title class="d-flex align-center ga-2">
          <v-icon size="22">mdi-clipboard-account</v-icon>
          {{ current.name }}
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" size="small" @click="dialog = false" />
        </v-card-title>

        <v-card-text>
          <div class="text-caption text-medium-emphasis mb-3">
            Заполнено: {{ formatDate(current.completed_at) }}
            <span v-if="current.email"> · {{ current.email }}</span>
            <span v-if="current.phone"> · {{ current.phone }}</span>
            <span v-if="current.city"> · {{ current.city }}</span>
          </div>

          <v-table density="compact">
            <tbody>
              <tr v-for="(label, key) in fieldLabels" :key="key">
                <td class="text-medium-emphasis" style="width:45%">{{ label }}</td>
                <td>
                  <span v-if="current.fields[key]">{{ formatField(key, current.fields[key]) }}</span>
                  <span v-else class="text-disabled">—</span>
                </td>
              </tr>
            </tbody>
          </v-table>
        </v-card-text>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="4000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import { useDebounce } from '../../composables/useDebounce';

const search = ref('');
const onlyCompleted = ref(true);
const page = ref(1);
const perPage = ref(25);
const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const exporting = ref(false);
const dialog = ref(false);
const current = ref(null);

const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

const headers = [
  { title: 'Партнёр', key: 'name', sortable: false },
  { title: 'E-mail', key: 'email', sortable: false },
  { title: 'Телефон', key: 'phone', sortable: false, width: 160 },
  { title: 'Город', key: 'city', sortable: false, width: 140 },
  { title: 'Заполнено', key: 'completed_at', sortable: false, width: 170 },
  { title: '', key: 'actions', sortable: false, width: 140, align: 'end' },
];

// Соответствует AdminQuestionnaireController::FIELDS — порядок и подписи.
const fieldLabels = {
  workField: 'Сфера работы',
  salesExperience: 'Опыт в продажах',
  financeExperience: 'Опыт в финансах',
  hasPotentialClients: 'Потенциальные клиенты',
  potentialClientsCount: 'Кол-во клиентов',
  currentIncome: 'Текущий доход',
  weeklyHours: 'Часов в неделю',
  incomeFactors: 'От чего зависит доход',
};

// Расшифровка коротких enum-ов из формы регистрации.
const enumMap = {
  salesExperience: { none: 'Нет', '<1': 'Менее 1 года', '1-3': '1–3 года', '3+': 'Более 3 лет' },
  hasPotentialClients: { yes: 'Да', partly: 'Частично', no: 'Нет' },
  potentialClientsCount: { '<10': 'До 10', '10-30': '10–30', '30-100': '30–100', '100+': 'Более 100' },
  weeklyHours: { '<10': 'До 10 ч', '10-20': '10–20 ч', '20-40': '20–40 ч', 'full-time': 'Полный день' },
};

function formatField(key, value) {
  return enumMap[key]?.[value] ?? value;
}

function formatDate(s) {
  if (!s) return '—';
  const d = new Date(s);
  if (isNaN(d.getTime())) return s;
  return d.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}

async function loadServer() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/partners/questionnaires', {
      params: {
        search: search.value || undefined,
        only_completed: onlyCompleted.value ? 1 : 0,
        page: page.value,
        per: perPage.value,
      },
    });
    rows.value = data.data || [];
    total.value = data.total || 0;
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка загрузки', 'error');
  }
  loading.value = false;
}

const { debounced: debouncedReload } = useDebounce(() => {
  page.value = 1;
  loadServer();
}, 350);

function onFilterChange() {
  debouncedReload();
}

async function openDialog(item) {
  if (!item.completed_at) return;
  try {
    const { data } = await api.get(`/admin/partners/${item.id}/questionnaire`);
    current.value = data;
    dialog.value = true;
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
}

async function exportCsv() {
  exporting.value = true;
  try {
    const response = await api.get('/admin/partners/questionnaires/export', {
      params: {
        search: search.value || undefined,
        only_completed: onlyCompleted.value ? 1 : 0,
      },
      responseType: 'blob',
    });
    const url = URL.createObjectURL(new Blob([response.data], {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    }));
    const a = document.createElement('a');
    a.href = url;
    a.download = `partner-questionnaires-${new Date().toISOString().slice(0, 10)}.xlsx`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка экспорта', 'error');
  }
  exporting.value = false;
}

onMounted(loadServer);
</script>
