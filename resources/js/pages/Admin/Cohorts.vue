<template>
  <div>
    <PageHeader title="Когорты и retention" icon="mdi-chart-line" />

    <v-card>
      <v-card-text>
        Партнёры сгруппированы по месяцу регистрации. Показывается, сколько из
        каждой когорты сейчас активно / терминировано.
      </v-card-text>
      <v-data-table :items="rows" :headers="headers" density="comfortable" hover no-data-text="Нет данных за последние 12 месяцев">
        <template #item.cohort_month="{ value }">{{ formatMonth(value) }}</template>
        <template #item.retention_pct="{ item }">
          <span :class="retentionClass(item)">{{ retention(item) }}%</span>
        </template>
        <template #item.termination_pct="{ item }">
          <span :class="terminationClass(item)">{{ termination(item) }}%</span>
        </template>
      </v-data-table>
    </v-card>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';
import { PageHeader } from '../../components';

const rows = ref([]);

const headers = [
  { title: 'Месяц регистрации', key: 'cohort_month', width: 180 },
  { title: 'Размер когорты', key: 'cohort_size', align: 'end', width: 140 },
  { title: 'Активно сейчас', key: 'active_now', align: 'end', width: 140 },
  { title: 'Терминировано', key: 'terminated', align: 'end', width: 140 },
  { title: 'Retention', key: 'retention_pct', align: 'end', width: 120 },
  { title: 'Отвалилось', key: 'termination_pct', align: 'end', width: 120 },
];

function formatMonth(v) {
  if (!v) return '';
  const d = new Date(v);
  return d.toLocaleDateString('ru-RU', { year: 'numeric', month: 'long' });
}
function retention(r) { return r.cohort_size ? Math.round(r.active_now * 100 / r.cohort_size) : 0; }
function termination(r) { return r.cohort_size ? Math.round(r.terminated * 100 / r.cohort_size) : 0; }
function retentionClass(r) {
  const v = retention(r);
  if (v >= 70) return 'text-success font-weight-bold';
  if (v >= 40) return 'text-warning';
  return 'text-error';
}
function terminationClass(r) {
  const v = termination(r);
  if (v >= 30) return 'text-error font-weight-bold';
  if (v >= 10) return 'text-warning';
  return 'text-medium-emphasis';
}

async function load() {
  try {
    const { data } = await api.get('/admin/analytics/cohorts');
    rows.value = data.cohorts || [];
  } catch {}
}
onMounted(load);
</script>
