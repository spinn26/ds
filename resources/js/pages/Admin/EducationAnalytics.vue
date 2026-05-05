<template>
  <div>
    <PageHeader title="Статистика обучения" icon="mdi-chart-line">
      <template #actions>
        <v-btn color="primary" prepend-icon="mdi-microsoft-excel" :loading="exporting"
          variant="tonal" size="small" @click="exportCsv">
          Выгрузить Excel
        </v-btn>
      </template>
    </PageHeader>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по ФИО / e-mail"
          density="compact" variant="outlined" hide-details rounded clearable
          prepend-inner-icon="mdi-magnify" style="max-width:320px"
          @update:model-value="onFilterChange" />
        <v-autocomplete v-model="courseId" :items="courseOptions" item-title="title" item-value="id"
          placeholder="Курс" clearable density="compact" variant="outlined" hide-details
          style="max-width:300px" prepend-inner-icon="mdi-school"
          @update:model-value="onFilterChange" />
        <v-spacer />
        <span v-if="total" class="text-caption text-medium-emphasis">
          Найдено партнёров: <strong>{{ total }}</strong>
        </span>
      </div>
    </v-card>

    <v-card>
      <v-data-table-server v-model:items-per-page="perPage" v-model:page="page"
        :items="rows" :headers="headers" :items-length="total" :loading="loading"
        density="comfortable" hover @update:options="loadServer">
        <template #item.lessons_viewed="{ item }">
          <v-chip size="x-small" :color="item.lessons_viewed > 0 ? 'success' : 'default'" variant="tonal">
            {{ item.lessons_viewed }}
          </v-chip>
        </template>
        <template #item.courses_completed="{ item }">
          <span :class="item.courses_completed > 0 ? 'text-success font-weight-medium' : 'text-medium-emphasis'">
            {{ item.courses_completed }} / {{ item.courses_total }}
          </span>
        </template>
        <template #item.avg_score_pct="{ item }">
          <span v-if="item.avg_score_pct != null"
            :class="item.avg_score_pct >= 80 ? 'text-success' : 'text-warning'">
            {{ item.avg_score_pct }}%
          </span>
          <span v-else class="text-disabled">—</span>
        </template>
        <template #item.test_attempts="{ item }">
          <span v-if="item.test_attempts > 0">
            <strong>{{ item.test_passed }}</strong>
            <span class="text-medium-emphasis">/ {{ item.test_attempts }}</span>
          </span>
          <span v-else class="text-disabled">—</span>
        </template>
        <template #item.last_activity="{ item }">
          <span v-if="item.last_activity" class="text-caption">
            {{ formatDate(item.last_activity) }}
          </span>
          <span v-else class="text-disabled">—</span>
        </template>
        <template #no-data>
          <EmptyState message="Партнёров не найдено по выбранным фильтрам" />
        </template>
      </v-data-table-server>
    </v-card>

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
const courseId = ref(null);
const page = ref(1);
const perPage = ref(25);
const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const exporting = ref(false);
const courseOptions = ref([]);

const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

const headers = [
  { title: 'Партнёр', key: 'name', sortable: false },
  { title: 'E-mail', key: 'email', sortable: false },
  { title: 'Уроки', key: 'lessons_viewed', sortable: false, align: 'end', width: 110 },
  { title: 'Курсы', key: 'courses_completed', sortable: false, align: 'end', width: 130 },
  { title: 'Средний балл', key: 'avg_score_pct', sortable: false, align: 'end', width: 140 },
  { title: 'Попытки тестов', key: 'test_attempts', sortable: false, align: 'end', width: 150 },
  { title: 'Последняя активность', key: 'last_activity', sortable: false, width: 200 },
];

function formatDate(s) {
  if (!s) return '—';
  const d = new Date(s);
  if (isNaN(d.getTime())) return s;
  return d.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}

async function loadServer() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/education/analytics', {
      params: {
        search: search.value || undefined,
        course_id: courseId.value || undefined,
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

async function loadCourses() {
  try {
    const { data } = await api.get('/admin/education/courses', { params: { search: '' } });
    courseOptions.value = data.data || [];
  } catch {}
}

async function exportCsv() {
  exporting.value = true;
  try {
    const response = await api.get('/admin/education/analytics/export', {
      params: {
        search: search.value || undefined,
        course_id: courseId.value || undefined,
      },
      responseType: 'blob',
    });
    const url = URL.createObjectURL(new Blob([response.data], {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    }));
    const a = document.createElement('a');
    a.href = url;
    a.download = `education-analytics-${new Date().toISOString().slice(0, 10)}.xlsx`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка экспорта', 'error');
  }
  exporting.value = false;
}

onMounted(() => {
  loadCourses();
  loadServer();
});
</script>
