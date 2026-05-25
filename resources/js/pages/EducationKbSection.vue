<template>
  <div class="pa-6">
    <v-breadcrumbs :items="crumbs" density="compact" />
    <div v-if="loading" class="d-flex justify-center pa-6">
      <v-progress-circular indeterminate color="primary" />
    </div>
    <div v-else>
      <h1 class="text-h5 font-weight-bold mb-4">Раздел базы знаний</h1>
      <EmptyState
        v-if="!articles.length"
        icon="mdi-file-document-outline"
        title="В этом разделе пока нет материалов"
        description="Скоро здесь появятся регламенты, инструкции и записи"
      />
      <v-list v-else>
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
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import api from '../api';
import EmptyState from '../components/EmptyState.vue';

const route = useRoute();
const loading = ref(true);
const articles = ref([]);
const crumbs = computed(() => [
  { title: 'Обучение', to: '/education' },
  { title: 'База знаний', to: '/education/kb' },
  { title: 'Раздел', disabled: true },
]);

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/education/kb/sections/${route.params.id}`);
    articles.value = data.articles || [];
  } catch {}
  loading.value = false;
}
onMounted(load);
</script>
