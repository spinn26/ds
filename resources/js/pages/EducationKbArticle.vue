<template>
  <div class="pa-6">
    <v-breadcrumbs :items="crumbs" density="compact" />
    <div v-if="loading" class="d-flex justify-center pa-6">
      <v-progress-circular indeterminate color="primary" />
    </div>
    <div v-else-if="article" class="article">
      <h1 class="text-h4 font-weight-bold mb-2">{{ article.title }}</h1>
      <div v-if="article.description" class="text-body-1 text-medium-emphasis mb-4">
        {{ article.description }}
      </div>
      <div v-if="article.tags?.length" class="mb-4 d-flex ga-1 flex-wrap">
        <v-chip v-for="t in article.tags" :key="t" size="x-small" variant="tonal">#{{ t }}</v-chip>
      </div>
      <LessonBlockRenderer
        v-if="hasBlocks"
        :blocks="article.body"
        class="article-body"
      />
      <v-alert v-else type="info" variant="tonal" density="compact">
        Содержимое материала пока пустое — отдел обучения добавит блоки позже.
      </v-alert>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import api from '../api';
import LessonBlockRenderer from '../components/education/LessonBlockRenderer.vue';

const route = useRoute();
const loading = ref(true);
const article = ref(null);
const crumbs = computed(() => [
  { title: 'Обучение', to: '/education' },
  { title: 'База знаний', to: '/education/kb' },
  { title: article.value?.title || 'Материал', disabled: true },
]);
const hasBlocks = computed(() => {
  const b = article.value?.body;
  if (!b) return false;
  if (Array.isArray(b)) return b.length > 0;
  try { const p = JSON.parse(b); return Array.isArray(p) && p.length > 0; }
  catch { return false; }
});

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/education/kb/articles/${route.params.id}`);
    article.value = data;
  } catch {}
  loading.value = false;
}
onMounted(load);
</script>
