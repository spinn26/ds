<template>
  <div>
    <PageHeader :title="headerTitle" icon="mdi-school">
      <template v-if="selectedGroup" #actions>
        <v-btn variant="text" prepend-icon="mdi-arrow-left" @click="selectedGroup = null">
          К категориям
        </v-btn>
      </template>
    </PageHeader>

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

    <!-- Уровень 1: карточки-кнопки категорий -->
    <v-row v-else-if="!selectedGroup" dense>
      <v-col v-for="group in groupsWithCourses" :key="group.kind + ':' + group.id"
        cols="12" sm="6" md="4" lg="3">
        <v-card class="category-card pa-4 h-100" hover @click="selectedGroup = group">
          <div class="d-flex align-center ga-3 mb-2">
            <v-avatar size="44" color="primary" variant="tonal">
              <v-icon size="24">{{ group.icon }}</v-icon>
            </v-avatar>
            <div class="flex-grow-1 min-w-0">
              <div class="text-subtitle-1 font-weight-bold text-truncate">{{ group.title }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ group.courses.length }} {{ pluralCourse(group.courses.length) }}
              </div>
            </div>
            <v-icon color="medium-emphasis">mdi-chevron-right</v-icon>
          </div>
          <div v-if="group.completedCount" class="d-flex align-center ga-1 text-caption text-success">
            <v-icon size="14">mdi-check-circle</v-icon>
            Пройдено: {{ group.completedCount }} / {{ group.courses.length }}
          </div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Уровень 2: курсы внутри выбранной категории -->
    <div v-else>
      <div class="d-flex align-center ga-2 mb-3">
        <v-icon size="20" color="primary">{{ selectedGroup.icon }}</v-icon>
        <h3 class="text-h6 font-weight-bold">{{ selectedGroup.title }}</h3>
        <v-chip size="x-small" variant="tonal">
          {{ selectedGroup.courses.length }} {{ pluralCourse(selectedGroup.courses.length) }}
        </v-chip>
      </div>
      <v-expansion-panels v-model="openedPanel">
        <v-expansion-panel v-for="c in selectedGroup.courses" :key="c.id" :value="c.id">
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
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import api from '../api';
import PageHeader from '../components/PageHeader.vue';
import EmptyState from '../components/EmptyState.vue';
import CourseRunner from '../components/education/CourseRunner.vue';

const courses = ref([]);
const categories = ref([]);
const detail = ref({});
const openedPanel = ref(null);
const loading = ref(true);
const loadError = ref('');

// Текущая выбранная категория (объект из groupsWithCourses) — null означает
// уровень 1 (показываем карточки). При выборе перерисовываемся в уровень 2.
const selectedGroup = ref(null);

const headerTitle = computed(() =>
  selectedGroup.value ? `Обучение / ${selectedGroup.value.title}` : 'Обучение'
);

function pluralCourse(n) {
  if (n % 10 === 1 && n % 100 !== 11) return 'курс';
  if ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100)) return 'курса';
  return 'курсов';
}

// Легаси-фоллбэк: 9 семантических блоков per spec ✅Обучение §3 + «База
// знаний» (block=0). Используется, пока админ не создал ни одной категории.
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

const groupsWithCourses = computed(() => {
  // Категории — если они вообще заведены или хотя бы у одного курса есть category_id.
  const useCategories = categories.value.length > 0 || courses.value.some(c => c.category_id);

  const withCounts = (g) => ({
    ...g,
    completedCount: g.courses.filter(c => c.completed).length,
  });

  if (useCategories) {
    const groups = categories.value.map(cat => withCounts({
      kind: 'category',
      id: cat.id,
      title: cat.name,
      icon: 'mdi-folder-outline',
      courses: courses.value.filter(c => c.category_id === cat.id),
    }));
    // Курсы без категории — последняя группа.
    const orphans = courses.value.filter(c => !c.category_id);
    if (orphans.length) {
      groups.push(withCounts({
        kind: 'category', id: 'none',
        title: 'Без категории', icon: 'mdi-folder-question-outline',
        courses: orphans,
      }));
    }
    return groups.filter(g => g.courses.length > 0);
  }

  // Fallback: легаси-блоки.
  return BLOCKS
    .map(b => withCounts({ kind: 'block', ...b, courses: courses.value.filter(c => (c.block ?? 0) === b.id) }))
    .filter(b => b.courses.length > 0);
});

// Если selectedGroup устарел (после ре-fetch'а courses/categories) — синхронизируем
// его с актуальной версией из groupsWithCourses, чтобы прогресс/состав курсов
// внутри обновились без сброса навигации.
watch(groupsWithCourses, (groups) => {
  if (!selectedGroup.value) return;
  const fresh = groups.find(g => g.kind === selectedGroup.value.kind && g.id === selectedGroup.value.id);
  if (fresh) selectedGroup.value = fresh;
  else selectedGroup.value = null; // категория исчезла → вернёмся к списку
});

async function loadCourses() {
  loading.value = true;
  loadError.value = '';
  try {
    const { data } = await api.get('/education/courses');
    courses.value = data.data || [];
    categories.value = data.categories || [];
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

<style scoped>
.category-card {
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.category-card:hover {
  transform: translateY(-2px);
}
</style>
