<template>
  <div>
    <PageHeader title="Обучение" />

    <v-card class="hero-card" elevation="0">
      <div class="hero-content">
        <div class="hero-title">Продолжайте</div>
        <div class="hero-sub">{{ inProgress.title }}</div>
        <v-progress-linear :model-value="inProgress.percent" color="brand" height="6" rounded class="my-2" />
        <div class="hero-meta">
          <span>{{ inProgress.percent }}% завершено</span>
          <span>урок {{ inProgress.current }} из {{ inProgress.total }}</span>
        </div>
        <v-btn color="brand" class="brand-ink mt-2" size="small" prepend-icon="mdi-play">
          Продолжить
        </v-btn>
      </div>
    </v-card>

    <div class="chip-row mt-3">
      <v-chip v-for="c in categories" :key="c.value"
        :color="category === c.value ? 'primary' : undefined"
        :variant="category === c.value ? 'flat' : 'tonal'"
        size="small" label @click="category = c.value">
        {{ c.label }}
      </v-chip>
    </div>

    <div class="course-grid">
      <div v-for="course in courses" :key="course.id" class="course-card">
        <div class="course-cover" :style="{ background: course.bg }">
          <v-icon :color="course.iconColor" size="32">{{ course.icon }}</v-icon>
        </div>
        <div class="course-body">
          <div class="course-title">{{ course.title }}</div>
          <div class="course-meta">
            <v-icon size="12" color="grey">mdi-clock-outline</v-icon>
            <span>{{ course.duration }}</span>
            <v-icon size="12" color="grey">mdi-book-open-page-variant-outline</v-icon>
            <span>{{ course.lessons }} уроков</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

const category = ref('all');
const categories = [
  { value: 'all', label: 'Все' },
  { value: 'start', label: 'Старт' },
  { value: 'sales', label: 'Продажи' },
  { value: 'leadership', label: 'Лидерство' },
];

const inProgress = {
  title: 'Школа стартапа: первые 90 дней',
  percent: 64,
  current: 7,
  total: 11,
};

const courses = [
  { id: 1, title: 'Продукты Investor Trust', duration: '2 ч 15 мин', lessons: 12, icon: 'mdi-finance', iconColor: 'primary', bg: 'rgba(46,125,50,0.10)' },
  { id: 2, title: 'Возражения клиентов', duration: '1 ч 40 мин', lessons: 8, icon: 'mdi-account-voice', iconColor: 'info', bg: 'rgba(30,136,229,0.10)' },
  { id: 3, title: 'Построение структуры', duration: '3 ч', lessons: 14, icon: 'mdi-account-tree', iconColor: 'warning', bg: 'rgba(251,140,0,0.10)' },
  { id: 4, title: 'Medlife: страховка и здоровье', duration: '55 мин', lessons: 5, icon: 'mdi-heart-pulse', iconColor: 'error', bg: 'rgba(229,57,53,0.10)' },
];
</script>

<style scoped>
.hero-card {
  background: linear-gradient(135deg, #0A2B10 0%, #2E7D32 100%);
  color: #fff;
  border-radius: 16px;
  padding: 18px;
  box-shadow: 0 8px 24px rgba(46, 125, 50, 0.2);
}
.hero-title {
  font-size: 12px;
  opacity: 0.7;
  text-transform: uppercase;
  letter-spacing: 0.6px;
}
.hero-sub {
  font-size: 17px;
  font-weight: 700;
  margin-top: 4px;
}
.hero-meta {
  display: flex;
  justify-content: space-between;
  font-size: 11px;
  opacity: 0.8;
}
.brand-ink {
  color: #0A2B10 !important;
}

.course-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.course-card {
  background: #fff;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
}
.course-cover {
  height: 90px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.course-body {
  padding: 10px 12px 12px;
}
.course-title {
  font-size: 13px;
  font-weight: 600;
  line-height: 1.3;
  color: #1b1b1b;
}
.course-meta {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 10px;
  color: rgba(0, 0, 0, 0.5);
  margin-top: 6px;
  flex-wrap: wrap;
}
.course-meta .v-icon { margin-right: 1px; }
</style>
