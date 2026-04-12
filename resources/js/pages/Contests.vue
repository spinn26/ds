<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-trophy</v-icon>
      <h5 class="text-h5 font-weight-bold">Список конкурсов</h5>
    </div>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-select v-model="filters.status" :items="statusFilterOptions" label="Статус" density="compact" variant="outlined"
          clearable hide-details style="max-width:200px" @update:model-value="loadData" />
        <v-select v-model="filters.type" :items="typeFilterOptions" label="Тип" density="compact" variant="outlined"
          clearable hide-details style="max-width:200px" @update:model-value="loadData" />
      </div>
    </v-card>

    <v-row>
      <v-col v-for="contest in contests" :key="contest.id" cols="12" sm="6" md="4">
        <v-card class="pa-4 d-flex flex-column" height="100%"
          :style="{ borderLeft: `4px solid ${contestBorderColor(contest.status)}` }">
          <div class="d-flex justify-space-between align-center mb-2">
            <v-chip size="small" :color="contestStatusColor(contest.status)">
              {{ contestStatusLabel(contest.status) }}
            </v-chip>
            <span v-if="contest.type" class="text-caption text-medium-emphasis">{{ contest.type }}</span>
          </div>
          <div class="text-subtitle-1 font-weight-bold mb-1">{{ contest.name }}</div>
          <div class="text-body-2 text-medium-emphasis flex-grow-1 mb-2">{{ contest.description }}</div>
          <div class="text-body-2 mb-1">
            <span class="text-medium-emphasis">Период:</span> {{ contest.startDate }} — {{ contest.endDate }}
          </div>
          <div v-if="contest.progress != null" class="mt-2">
            <v-progress-linear :model-value="contest.progress" height="8" rounded color="primary" />
            <div class="text-caption text-medium-emphasis mt-1">Прогресс: {{ contest.progress }}%</div>
          </div>
          <v-btn v-if="contest.presentationUrl" variant="outlined" size="small" color="primary"
            :href="contest.presentationUrl" target="_blank" class="mt-3" prepend-icon="mdi-presentation">
            Презентация
          </v-btn>
        </v-card>
      </v-col>
    </v-row>

    <div v-if="!contests.length && !loading" class="text-center text-medium-emphasis pa-6">
      Конкурсов не найдено
    </div>

    <v-overlay v-model="loading" class="align-center justify-center" persistent>
      <v-progress-circular indeterminate size="64" />
    </v-overlay>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../api';

const loading = ref(true);
const contests = ref([]);
const filters = ref({ status: null, type: null });

const statusFilterOptions = [
  { title: 'Активный', value: 1 },
  { title: 'Завершён', value: 2 },
  { title: 'Архив', value: 3 },
];

const typeFilterOptions = ref([]);

function contestStatusColor(status) {
  if (status === 1) return 'success';
  if (status === 2) return 'orange';
  return 'grey';
}

function contestBorderColor(status) {
  if (status === 1) return '#4CAF50';
  if (status === 2) return '#FF9800';
  return '#9E9E9E';
}

function contestStatusLabel(status) {
  if (status === 1) return 'Активный';
  if (status === 2) return 'Завершён';
  return 'Архив';
}

async function loadData() {
  loading.value = true;
  try {
    const params = {};
    if (filters.value.status) params.status = filters.value.status;
    if (filters.value.type) params.type = filters.value.type;
    const { data } = await api.get('/contests', { params });
    if (Array.isArray(data)) {
      contests.value = data;
    } else {
      contests.value = data.data || [];
      if (data.types) typeFilterOptions.value = data.types.map(t => ({ title: t.name, value: t.id }));
    }
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
