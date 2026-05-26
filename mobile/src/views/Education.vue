<template>
  <div>
    <PageHeader title="Обучение" />

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>

    <div v-if="loading" class="text-center py-8">
      <v-progress-circular indeterminate color="primary" size="32" />
    </div>

    <template v-else>
      <v-card v-if="inProgress" class="hero-card" elevation="0">
        <div class="hero-content">
          <div class="hero-title">Продолжайте</div>
          <div class="hero-sub">{{ inProgress.title }}</div>
          <v-progress-linear :model-value="inProgress.percent || 0" color="brand" height="6" rounded class="my-2" />
          <div class="hero-meta">
            <span>{{ inProgress.percent || 0 }}% завершено</span>
            <span v-if="inProgress.totalLessons">урок {{ inProgress.currentLesson || 1 }} из {{ inProgress.totalLessons }}</span>
          </div>
          <v-btn color="brand" class="brand-ink mt-2" size="small" prepend-icon="mdi-play">
            Продолжить
          </v-btn>
        </div>
      </v-card>

      <div v-if="!items.length" class="empty-state mt-3">
        <v-icon size="48">mdi-school-outline</v-icon>
        <div class="empty-state-text">Курсов пока нет</div>
      </div>

      <div v-else class="course-grid">
        <div v-for="course in items" :key="course.id" class="course-card">
          <div class="course-cover" :style="{ background: coverBg(course) }">
            <v-icon :color="coverColor(course)" size="32">{{ course.icon || 'mdi-school' }}</v-icon>
          </div>
          <div class="course-body">
            <div class="course-title">{{ course.title || course.name }}</div>
            <div class="course-meta">
              <v-icon size="12" color="grey">mdi-book-open-page-variant-outline</v-icon>
              <span>{{ course.lessonsCount ?? course.lessons ?? 0 }} уроков</span>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

interface Course {
  id: number;
  title?: string;
  name?: string;
  lessonsCount?: number;
  lessons?: number;
  icon?: string;
  color?: string;
  bg?: string;
  progress?: number;
  status?: string;
  currentLesson?: number;
  totalLessons?: number;
  percent?: number;
}

const items = ref<Course[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const inProgress = computed(() => items.value.find((c) => (c.progress || c.percent || 0) > 0 && (c.progress || c.percent || 0) < 100));

function coverBg(c: Course) { return c.bg || 'rgba(46,125,50,0.10)'; }
function coverColor(c: Course) { return c.color || 'primary'; }

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const { data } = await api.get('/education/courses');
    items.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.hero-card { background: linear-gradient(135deg, #0A2B10 0%, #2E7D32 100%); color: #fff; border-radius: 16px; padding: 18px; box-shadow: 0 8px 24px rgba(46,125,50,0.2); }
.hero-title { font-size: 12px; opacity: 0.7; text-transform: uppercase; letter-spacing: 0.6px; }
.hero-sub { font-size: 17px; font-weight: 700; margin-top: 4px; }
.hero-meta { display: flex; justify-content: space-between; font-size: 11px; opacity: 0.8; }
.brand-ink { color: #0A2B10 !important; }
.course-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 14px; }
.course-card { background: #fff; border-radius: 14px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.course-cover { height: 90px; display: flex; align-items: center; justify-content: center; }
.course-body { padding: 10px 12px 12px; }
.course-title { font-size: 13px; font-weight: 600; line-height: 1.3; color: #1b1b1b; }
.course-meta { display: flex; align-items: center; gap: 4px; font-size: 10px; color: rgba(0,0,0,0.5); margin-top: 6px; }
.empty-state { padding: 60px 24px; text-align: center; }
.empty-state .v-icon { color: rgba(0,0,0,0.2); margin-bottom: 12px; }
.empty-state-text { font-size: 14px; color: rgba(0,0,0,0.5); }
</style>
