<template>
  <div>
    <PageHeader title="Обучение" icon="mdi-school" />

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

    <!-- Группировка по 9 смысловым блокам per spec ✅Обучение §3 -->
    <div v-else>
      <div v-for="block in blocksWithCourses" :key="block.id" class="mb-6">
        <div class="d-flex align-center ga-2 mb-2">
          <v-icon size="20" color="primary">{{ block.icon }}</v-icon>
          <h3 class="text-h6 font-weight-bold">{{ block.title }}</h3>
          <v-chip size="x-small" variant="tonal">
            {{ block.courses.length }} {{ block.courses.length === 1 ? 'курс' : 'курсов' }}
          </v-chip>
        </div>
        <v-expansion-panels v-model="openedPanel">
          <v-expansion-panel v-for="c in block.courses" :key="c.id" :value="c.id">
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
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import api from '../api';
import PageHeader from '../components/PageHeader.vue';
import EmptyState from '../components/EmptyState.vue';
import CourseRunner from '../components/education/CourseRunner.vue';

const courses = ref([]);
const detail = ref({});
const openedPanel = ref(null);
const loading = ref(true);
const loadError = ref('');

// 9 смысловых блоков per spec ✅Обучение §3 + «База знаний» (block=0).
const BLOCKS = [
  { id: 1, title: 'DS: кто мы и как у нас устроен бизнес', icon: 'mdi-domain' },
  { id: 2, title: 'Продукты DS', icon: 'mdi-package-variant' },
  { id: 3, title: 'Методология работы с клиентом', icon: 'mdi-handshake' },
  { id: 4, title: 'Привлечение клиентов', icon: 'mdi-bullhorn' },
  { id: 5, title: 'Основы продаж', icon: 'mdi-chart-line' },
  { id: 6, title: 'Построение команды и масштабирование', icon: 'mdi-account-group' },
  { id: 7, title: 'Вознаграждение, события и клубы', icon: 'mdi-trophy' },
  { id: 8, title: 'Договор и этический кодекс', icon: 'mdi-shield-check' },
  { id: 9, title: 'Технические аспекты работы', icon: 'mdi-cog' },
  { id: 0, title: 'База знаний', icon: 'mdi-book-open-variant' },
];

const blocksWithCourses = computed(() => {
  return BLOCKS
    .map(b => ({ ...b, courses: courses.value.filter(c => (c.block ?? 0) === b.id) }))
    .filter(b => b.courses.length > 0);
});

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
