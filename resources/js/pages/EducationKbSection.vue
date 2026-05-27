<template>
  <div class="pa-6">
    <v-breadcrumbs :items="crumbs" density="compact" />
    <div v-if="loading" class="d-flex justify-center pa-6">
      <v-progress-circular indeterminate color="primary" />
    </div>
    <div v-else>
      <div class="d-flex align-center ga-3 mb-2">
        <v-avatar v-if="section?.icon" size="40" color="primary-soft" rounded="lg">
          <v-icon size="22" color="primary">{{ section.icon }}</v-icon>
        </v-avatar>
        <h1 class="text-h5 font-weight-bold">{{ section?.title || 'Раздел базы знаний' }}</h1>
      </div>
      <div v-if="section?.description" class="text-body-2 text-medium-emphasis mb-4">
        {{ section.description }}
      </div>

      <EmptyState
        v-if="!subsections.length && !articles.length"
        icon="mdi-file-document-outline"
        title="В этом разделе пока нет материалов"
        description="Скоро здесь появятся регламенты, инструкции и записи"
      />

      <div v-if="subsections.length" class="mb-6">
        <div class="text-subtitle-2 font-weight-bold text-uppercase letter-spacing-1 text-medium-emphasis mb-2">
          Подразделы
        </div>
        <v-row dense>
          <v-col
            v-for="s in subsections"
            :key="s.id"
            cols="12" sm="6" md="4"
          >
            <v-card
              class="section-card pa-4 h-100"
              elevation="0"
              :to="`/education/kb/sections/${s.id}`"
            >
              <div class="d-flex align-center ga-3 mb-2">
                <v-avatar size="40" color="primary-soft" rounded="lg">
                  <v-icon size="22" color="primary">{{ s.icon || 'mdi-folder-outline' }}</v-icon>
                </v-avatar>
                <div class="flex-grow-1">
                  <div class="text-subtitle-1 font-weight-bold">{{ s.title }}</div>
                  <div class="text-caption text-medium-emphasis">
                    {{ s.articleCount }} материалов
                    <span v-if="s.childCount"> · {{ s.childCount }} подразделов</span>
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

      <div v-if="articles.length">
        <div class="text-subtitle-2 font-weight-bold text-uppercase letter-spacing-1 text-medium-emphasis mb-2">
          Материалы
        </div>
        <v-list>
          <v-list-item
            v-for="a in articles"
            :key="a.id"
            :title="a.title"
            :subtitle="a.description"
            prepend-icon="mdi-file-document-outline"
            :to="`/education/kb/articles/${a.id}`"
          >
            <template #append>
              <v-icon>mdi-chevron-right</v-icon>
            </template>
          </v-list-item>
        </v-list>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '../api';
import EmptyState from '../components/EmptyState.vue';

const route = useRoute();
const loading = ref(true);
const section = ref(null);
const subsections = ref([]);
const articles = ref([]);
const breadcrumbs = ref([]);

const crumbs = computed(() => {
  const base = [
    { title: 'Обучение', to: '/education' },
    { title: 'База знаний', to: '/education/kb' },
  ];
  const trail = breadcrumbs.value.map((b, idx) => ({
    title: b.title,
    to: `/education/kb/sections/${b.id}`,
    disabled: idx === breadcrumbs.value.length - 1,
  }));
  return [...base, ...trail];
});

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/education/kb/sections/${route.params.id}`);
    section.value = data.section || null;
    subsections.value = data.subsections || [];
    articles.value = data.articles || [];
    breadcrumbs.value = data.breadcrumbs || [];
  } catch {
    section.value = null;
    subsections.value = [];
    articles.value = [];
    breadcrumbs.value = [];
  }
  loading.value = false;
}
onMounted(load);
watch(() => route.params.id, (id) => { if (id) load(); });
</script>

<style scoped>
.section-card {
  border: 1px solid var(--ds-outline-variant, rgba(var(--v-theme-on-surface), 0.08));
  border-radius: var(--ds-radius-lg, 12px);
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.section-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
}
</style>
