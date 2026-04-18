<template>
  <div>
    <BrandHero
      title="Обучение"
      subtitle="Курсы и тесты для доступа к продуктам"
      icon="mdi-school"
    />

    <v-alert v-if="loadError" type="error" class="mb-3" density="compact">{{ loadError }}</v-alert>

    <div v-if="loading" class="d-flex justify-center pa-6">
      <v-progress-circular indeterminate color="primary" />
    </div>

    <EmptyState
      v-else-if="!loading && courses.length === 0"
      icon="mdi-school-outline"
      title="Курсы пока не добавлены"
      description="Администратор добавит учебные материалы позже."
    />

    <v-expansion-panels v-else v-model="openedPanel" class="mb-4">
      <v-expansion-panel v-for="c in courses" :key="c.id" :value="c.id">
        <v-expansion-panel-title>
          <div class="d-flex align-center ga-3 flex-grow-1">
            <v-icon :color="c.completed ? 'success' : (c.testPassed || c.lessonViewed > 0 ? 'primary' : 'grey')">
              {{ c.completed ? 'mdi-check-circle' : (c.testPassed || c.lessonViewed > 0 ? 'mdi-progress-clock' : 'mdi-circle-outline') }}
            </v-icon>
            <div class="flex-grow-1">
              <div class="font-weight-medium">{{ c.title }}</div>
              <div class="text-caption text-medium-emphasis">
                Уроков: {{ c.lessonViewed }} / {{ c.lessonCount }}
                <span v-if="c.testPassed" class="ml-2 text-success">• тест сдан</span>
              </div>
            </div>
            <v-chip v-if="c.completed" size="small" color="success" variant="tonal">Пройден</v-chip>
          </div>
        </v-expansion-panel-title>
        <v-expansion-panel-text @group:selected="onPanelOpen(c.id)">
          <CourseRunner
            v-if="detail[c.id]"
            :course="detail[c.id]"
            @lesson-viewed="onLessonViewed(c.id, $event)"
            @test-submitted="onTestSubmitted(c.id, $event)"
          />
          <div v-else class="d-flex justify-center pa-4">
            <v-progress-circular indeterminate size="24" color="primary" />
          </div>
        </v-expansion-panel-text>
      </v-expansion-panel>
    </v-expansion-panels>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import api from '../api';
import BrandHero from '../components/BrandHero.vue';
import EmptyState from '../components/EmptyState.vue';
import CourseRunner from '../components/education/CourseRunner.vue';

const courses = ref([]);
const detail = ref({});
const openedPanel = ref(null);
const loading = ref(true);
const loadError = ref('');

async function loadCourses() {
  loading.value = true;
  loadError.value = '';
  try {
    const { data } = await api.get('/education/courses');
    courses.value = data.data || [];
  } catch (e) {
    loadError.value = e.response?.data?.message || 'Не удалось загрузить курсы';
  } finally {
    loading.value = false;
  }
}

async function loadCourse(id) {
  try {
    const { data } = await api.get(`/education/courses/${id}`);
    detail.value = { ...detail.value, [id]: data };
  } catch (e) {
    loadError.value = e.response?.data?.message || 'Не удалось загрузить курс';
  }
}

watch(openedPanel, (id) => {
  if (id && !detail.value[id]) loadCourse(id);
});

function onPanelOpen(id) {
  if (!detail.value[id]) loadCourse(id);
}

function onLessonViewed(courseId, lessonId) {
  const d = detail.value[courseId];
  if (!d) return;
  const lesson = d.lessons.find(l => l.id === lessonId);
  if (lesson && !lesson.viewed) {
    lesson.viewed = true;
    const c = courses.value.find(x => x.id === courseId);
    if (c) c.lessonViewed = Math.min(c.lessonViewed + 1, c.lessonCount);
  }
}

function onTestSubmitted(courseId, result) {
  const c = courses.value.find(x => x.id === courseId);
  if (c && result.passed) {
    c.testPassed = true;
    c.testScore = result.score;
    c.testTotal = result.total;
    c.completed = c.lessonViewed >= c.lessonCount;
  }
}

loadCourses();
</script>
