<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-calendar-clock</v-icon>
      <h5 class="text-h5 font-weight-bold">Статусы партнёров</h5>
    </div>

    <v-row class="mb-4">
      <v-col v-for="card in summaryCards" :key="card.label" cols="12" sm="6" md="3">
        <v-card class="pa-4 text-center">
          <div class="text-body-2 text-medium-emphasis">{{ card.label }}</div>
          <div class="text-h3 font-weight-bold" :class="`text-${card.color}`">{{ card.value }}</div>
        </v-card>
      </v-col>
    </v-row>

    <v-card v-if="details.length" class="pa-4">
      <div class="text-subtitle-1 font-weight-bold mb-3">Детализация по статусам</div>
      <v-table density="compact">
        <thead>
          <tr>
            <th>Статус</th>
            <th class="text-right">Всего</th>
            <th class="text-right">Активных</th>
            <th class="text-right">Неактивных</th>
            <th class="text-right">Просрочено</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in details" :key="row.status">
            <td>{{ row.status }}</td>
            <td class="text-right">{{ row.total }}</td>
            <td class="text-right text-success">{{ row.active }}</td>
            <td class="text-right text-error">{{ row.inactive }}</td>
            <td class="text-right text-warning">{{ row.expired }}</td>
          </tr>
        </tbody>
      </v-table>
    </v-card>

    <v-overlay v-model="loading" class="align-center justify-center" persistent>
      <v-progress-circular indeterminate size="64" />
    </v-overlay>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';

const loading = ref(true);
const data = ref({});
const details = ref([]);

const summaryCards = computed(() => [
  { label: 'Всего партнёров', value: data.value.total ?? 0, color: 'primary' },
  { label: 'Активных', value: data.value.active ?? 0, color: 'success' },
  { label: 'Неактивных', value: data.value.inactive ?? 0, color: 'error' },
  { label: 'Истекает скоро', value: data.value.expiringSoon ?? 0, color: 'warning' },
]);

async function loadData() {
  loading.value = true;
  try {
    const { data: d } = await api.get('/admin/partner-statuses');
    data.value = d;
    details.value = d.details || [];
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
