<template>
  <div class="pa-6">
    <v-breadcrumbs :items="[
      { title: 'Обучение', to: '/education' },
      { title: 'База знаний', disabled: true },
    ]" density="compact" />

    <div class="d-flex align-end justify-space-between flex-wrap ga-3 mb-4">
      <div>
        <h1 class="text-h4 font-weight-bold">База знаний</h1>
        <div class="text-body-2 text-medium-emphasis mt-1">
          {{ totalArticles }} материалов в {{ sections.length }} разделах
        </div>
      </div>
      <v-text-field
        v-model="search"
        prepend-inner-icon="mdi-magnify"
        placeholder="Поиск по базе знаний"
        variant="outlined" density="compact" hide-details
        style="max-width: 320px"
        @update:model-value="onSearch"
      />
    </div>

    <div v-if="loading" class="d-flex justify-center pa-6">
      <v-progress-circular indeterminate color="primary" />
    </div>

    <EmptyState
      v-else-if="!sections.length"
      icon="mdi-bookshelf"
      title="База знаний пока пуста"
      description="Сотрудник отдела обучения добавит разделы и материалы"
    />

    <v-row v-else dense>
      <v-col
        v-for="s in sections"
        :key="s.id"
        cols="12" sm="6" md="4"
      >
        <v-card
          class="section-card pa-4 h-100"
          elevation="0"
          :to="`/education/kb/sections/${s.id}`"
        >
          <div class="d-flex align-center ga-3 mb-3">
            <v-avatar size="44" color="primary-soft" rounded="lg">
              <v-icon size="24" color="primary">{{ s.icon || 'mdi-folder-outline' }}</v-icon>
            </v-avatar>
            <div class="flex-grow-1">
              <div class="text-subtitle-1 font-weight-bold">{{ s.title }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ s.articleCount }} материалов
                <span v-if="s.children?.length"> · {{ s.children.length }} подразделов</span>
              </div>
            </div>
            <v-icon size="20" color="medium-emphasis">mdi-chevron-right</v-icon>
          </div>
          <div v-if="s.description" class="text-body-2 text-medium-emphasis">
            {{ s.description }}
          </div>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api';
import EmptyState from '../components/EmptyState.vue';

const sections = ref([]);
const loading = ref(true);
const search = ref('');

const totalArticles = computed(() => {
  let n = 0;
  const walk = (nodes) => {
    for (const x of nodes || []) {
      n += x.articleCount || 0;
      walk(x.children);
    }
  };
  walk(sections.value);
  return n;
});

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/education/kb');
    sections.value = data.sections || [];
  } catch {}
  loading.value = false;
}

let searchTimer;
function onSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    if (!search.value || search.value.length < 2) return;
    try {
      await api.get('/education/search', { params: { q: search.value } });
      // Результат поиска покажем отдельным списком в будущем коммите
    } catch {}
  }, 400);
}

onMounted(load);
</script>

<style scoped>
.section-card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  border-radius: 12px;
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.section-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
}
</style>
