<template>
  <div>
    <PageHeader title="Обучение" icon="mdi-school" />

    <!-- Video section -->
    <v-card class="mb-4 pa-4">
      <div class="d-flex align-center ga-2 mb-3">
        <v-icon color="primary">mdi-play-circle</v-icon>
        <div class="text-subtitle-1 font-weight-bold">Видеоматериалы</div>
      </div>
      <v-row>
        <v-col v-for="video in videos" :key="video.title" cols="12" sm="6" md="4">
          <v-card variant="outlined" class="h-100">
            <div class="video-placeholder d-flex align-center justify-center" style="height: 180px; background: linear-gradient(135deg, #1a1f2e 0%, #2d3748 100%)">
              <div class="text-center">
                <v-icon size="48" color="primary" class="mb-2">mdi-play-circle-outline</v-icon>
                <div class="text-caption text-white">DS Consulting</div>
              </div>
            </div>
            <v-card-text>
              <div class="text-subtitle-2 font-weight-medium">{{ video.title }}</div>
              <div class="text-caption text-medium-emphasis">{{ video.description }}</div>
            </v-card-text>
            <v-card-actions>
              <v-btn v-if="video.url" :href="video.url" target="_blank" color="primary" variant="tonal" size="small" prepend-icon="mdi-play">
                Смотреть
              </v-btn>
              <v-chip v-else size="x-small" color="grey" variant="tonal">Скоро</v-chip>
            </v-card-actions>
          </v-card>
        </v-col>
      </v-row>
    </v-card>

    <!-- Documents section -->
    <v-card class="mb-4 pa-4">
      <div class="d-flex align-center ga-2 mb-3">
        <v-icon color="primary">mdi-file-document-multiple</v-icon>
        <div class="text-subtitle-1 font-weight-bold">Учебные материалы</div>
      </div>
      <v-row>
        <v-col v-for="doc in documents" :key="doc.title" cols="12" sm="6" md="4">
          <v-card variant="outlined" class="pa-3 h-100 d-flex flex-column">
            <div class="d-flex align-center ga-2 mb-2">
              <v-icon :color="doc.color || 'primary'">{{ doc.icon }}</v-icon>
              <div class="text-subtitle-2 font-weight-medium">{{ doc.title }}</div>
            </div>
            <div class="text-caption text-medium-emphasis flex-grow-1 mb-2">{{ doc.description }}</div>
            <v-btn v-if="doc.url" :href="doc.url" target="_blank" variant="tonal" color="primary" size="small" block prepend-icon="mdi-open-in-new">
              Открыть
            </v-btn>
            <v-chip v-else size="small" color="grey" variant="tonal">Скоро</v-chip>
          </v-card>
        </v-col>
      </v-row>
    </v-card>

    <!-- Product training section -->
    <v-card class="pa-4">
      <div class="d-flex align-center ga-2 mb-3">
        <v-icon color="primary">mdi-certificate</v-icon>
        <div class="text-subtitle-1 font-weight-bold">Обучение по продуктам</div>
      </div>
      <div class="text-body-2 text-medium-emphasis mb-3">
        Пройдите обучение и тестирование по продуктам компании для получения допуска к продажам.
      </div>
      <v-btn to="/products" color="primary" variant="tonal" prepend-icon="mdi-package-variant">
        Перейти к продуктам
      </v-btn>
    </v-card>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../api';
import PageHeader from '../components/PageHeader.vue';

const videos = ref([
  { title: 'Введение в DS Consulting', description: 'Знакомство с компанией, миссия и ценности', url: null },
  { title: 'Как работает партнёрская сеть', description: 'Структура, квалификации, комиссии', url: null },
  { title: 'Работа с клиентами', description: 'Привлечение, ведение, контракты', url: null },
  { title: 'Финансовые продукты', description: 'Обзор линейки продуктов компании', url: null },
  { title: 'Калькулятор объёмов', description: 'Как рассчитывать комиссионные', url: null },
  { title: 'Работа с платформой', description: 'Навигация по личному кабинету', url: null },
]);

const documents = ref([
  { title: 'Бизнес-модель', description: 'Полное описание квалификаций, комиссий и бонусов', icon: 'mdi-chart-timeline-variant', color: 'green', url: null },
  { title: 'Регламент работы', description: 'Правила и стандарты работы партнёра', icon: 'mdi-gavel', color: 'blue', url: null },
  { title: 'FAQ', description: 'Частые вопросы и ответы', icon: 'mdi-frequently-asked-questions', color: 'orange', url: null },
]);

onMounted(async () => {
  try {
    const { data } = await api.get('/education');
    if (data.videos?.length) videos.value = data.videos;
    if (data.documents?.length) documents.value = data.documents;
  } catch {}
});
</script>
