<template>
  <div>
    <PageHeader title="Конкурсы и события" icon="mdi-trophy" />

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-select v-model="filters.type" :items="typeFilterOptions" label="Тип"
          item-title="title" item-value="value"
          clearable hide-details style="max-width:240px" @update:model-value="loadData" />
      </div>
    </v-card>

    <!-- Skeleton while loading -->
    <v-row v-if="loading">
      <v-col v-for="n in 6" :key="n" cols="12" sm="6" md="4">
        <v-skeleton-loader type="article" />
      </v-col>
    </v-row>

    <v-row v-else>
      <v-col v-for="contest in contests" :key="contest.id" cols="12" sm="6" md="4">
        <v-card class="pa-4 d-flex flex-column contest-card" height="100%">
          <div class="d-flex justify-space-between align-center mb-2">
            <v-chip size="small" color="success">Активный</v-chip>
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

    <v-card v-if="!loading && !contests.length" class="pa-4 text-center">
      <v-icon size="64" color="grey-lighten-1" class="mb-3">mdi-trophy-outline</v-icon>
      <div class="text-h6 text-medium-emphasis mb-1">Конкурсов и событий пока нет</div>
      <div class="text-body-2 text-medium-emphasis">Следите за обновлениями — скоро здесь появятся новые конкурсы и события</div>
    </v-card>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../api';
import PageHeader from '../components/PageHeader.vue';
import { fmtDate } from '../composables/useDesign';

const loading = ref(true);
const contests = ref([]);
const filters = ref({ type: null });

const typeFilterOptions = ref([]);

async function loadData() {
  loading.value = true;
  try {
    const params = {};
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

<style scoped>
.contest-card {
  border-left: 4px solid rgb(var(--v-theme-primary));
}
</style>
