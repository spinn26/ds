<template>
  <div>
    <div v-if="loading" class="d-flex justify-center pa-8">
      <v-progress-circular indeterminate color="primary" />
    </div>
    <v-card v-else-if="page" class="ds-card pa-5" elevation="0">
      <h1 class="text-h5 font-weight-bold mb-4">{{ page.title }}</h1>
      <!-- Контент авторства админа (доверенный источник). -->
      <div class="content-body" v-html="page.body"></div>
    </v-card>
    <EmptyState v-else icon="mdi-file-remove-outline" title="Страница не найдена" />
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import api from '../api';
import EmptyState from '../components/EmptyState.vue';

const route = useRoute();
const loading = ref(true);
const page = ref(null);

async function load() {
  loading.value = true;
  page.value = null;
  try {
    const { data } = await api.get(`/page/${route.params.slug}`);
    page.value = data.page;
  } catch { /* 404 → EmptyState */ }
  loading.value = false;
}

watch(() => route.params.slug, load);
onMounted(load);
</script>

<style scoped>
.content-body { line-height: 1.6; }
.content-body :deep(h2) { font-size: 1.25rem; font-weight: 600; margin: 16px 0 8px; }
.content-body :deep(p) { margin-bottom: 10px; }
.content-body :deep(ul), .content-body :deep(ol) { padding-left: 20px; margin-bottom: 10px; }
.content-body :deep(a) { color: rgb(var(--v-theme-primary)); }
</style>
