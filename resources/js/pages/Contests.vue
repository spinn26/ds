<template>
  <div>
    <BrandHero
      title="Конкурсы и события"
      subtitle="Активные конкурсы платформы, награды и условия участия"
      icon="mdi-trophy"
    />

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-select v-model="filters.status" :items="statusFilterOptions" label="Статус"
          clearable hide-details style="max-width:200px" @update:model-value="loadData" />
        <v-select v-model="filters.type" :items="typeFilterOptions" label="Тип"
          item-title="title" item-value="value"
          clearable hide-details style="max-width:240px" @update:model-value="loadData" />
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
            <span v-if="contest.typeName" class="text-caption text-medium-emphasis">{{ contest.typeName }}</span>
          </div>
          <div class="text-subtitle-1 font-weight-bold mb-1">{{ contest.name }}</div>
          <div class="text-body-2 text-medium-emphasis flex-grow-1 mb-2">{{ contest.description }}</div>
          <div class="text-body-2 mb-1">
            <span class="text-medium-emphasis">Период:</span> {{ fmtDate(contest.start) }} — {{ fmtDate(contest.end) }}
          </div>
          <div v-if="contest.numberOfWinners" class="text-caption text-medium-emphasis">
            Победителей: {{ contest.numberOfWinners }}
          </div>
          <v-btn v-if="contest.presentation" variant="outlined" size="small" color="primary"
            :href="contest.presentation" target="_blank" class="mt-3" prepend-icon="mdi-presentation">
            Презентация
          </v-btn>
        </v-card>
      </v-col>
    </v-row>

    <v-card v-if="!contests.length && !loading" class="pa-8 text-center">
      <v-icon size="64" color="grey-lighten-1" class="mb-3">mdi-trophy-outline</v-icon>
      <div class="text-h6 text-medium-emphasis mb-1">Конкурсов и событий пока нет</div>
      <div class="text-body-2 text-medium-emphasis">Следите за обновлениями — скоро здесь появятся новые конкурсы и события</div>
    </v-card>

    <v-overlay v-model="loading" class="align-center justify-center" persistent>
      <v-progress-circular indeterminate size="64" />
    </v-overlay>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../api';
import PageHeader from '../components/PageHeader.vue';
import BrandHero from '../components/BrandHero.vue';
import { fmtDate } from '../composables/useDesign';

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
      contests.value = data.contests || data.data || [];
      if (data.types) typeFilterOptions.value = data.types.map(t => ({ title: t.name, value: t.id }));
    }
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
