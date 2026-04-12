<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-calendar-clock</v-icon>
      <h5 class="text-h5 font-weight-bold">Статусы партнёров</h5>
      <v-chip size="small" color="primary">{{ totalCount }}</v-chip>
    </div>

    <v-row class="mb-4">
      <v-col v-for="status in statuses" :key="status.id" cols="12" sm="6" md="3">
        <v-card class="pa-4 text-center" :color="statusCardColor(status.id)" variant="tonal">
          <div class="text-body-2 text-medium-emphasis">{{ status.name }}</div>
          <div class="text-h3 font-weight-bold">{{ status.count }}</div>
        </v-card>
      </v-col>
    </v-row>

    <v-card class="pa-4">
      <div class="text-subtitle-1 font-weight-bold mb-3">Сводка</div>
      <v-table density="compact">
        <thead>
          <tr>
            <th>ID</th>
            <th>Статус активности</th>
            <th class="text-right">Количество</th>
            <th class="text-right">% от общего</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in statuses" :key="s.id">
            <td>{{ s.id }}</td>
            <td>
              <v-chip size="x-small" :color="statusCardColor(s.id)">{{ s.name }}</v-chip>
            </td>
            <td class="text-right font-weight-bold">{{ s.count }}</td>
            <td class="text-right">{{ totalCount ? ((s.count / totalCount) * 100).toFixed(1) : 0 }}%</td>
          </tr>
          <tr class="font-weight-bold">
            <td colspan="2">Итого</td>
            <td class="text-right">{{ totalCount }}</td>
            <td class="text-right">100%</td>
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
const statuses = ref([]);

const totalCount = computed(() => statuses.value.reduce((sum, s) => sum + (s.count || 0), 0));

function statusCardColor(id) {
  const colors = { 1: 'success', 2: 'warning', 3: 'error', 4: 'info', 5: 'error' };
  return colors[id] || 'grey';
}

async function loadData() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/partner-statuses');
    statuses.value = Array.isArray(data) ? data : [];
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
