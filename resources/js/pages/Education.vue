<template>
  <div class="education-home pa-6">
    <!-- Заголовок -->
    <div class="d-flex align-end justify-space-between flex-wrap ga-3 mb-6">
      <div>
        <h1 class="text-h4 font-weight-bold">Обучение</h1>
        <div class="text-body-2 text-medium-emphasis mt-1">
          {{ totalCourses }} {{ pluralCourse(totalCourses) }} ·
          {{ inProgressCount }} в процессе ·
          {{ pendingTestCount }} ждут теста
        </div>
      </div>
      <v-text-field
        v-model="search"
        prepend-inner-icon="mdi-magnify"
        placeholder="Поиск по курсам"
        variant="outlined" density="compact" hide-details
        style="max-width: 280px"
      />
    </div>

    <!-- «Продолжить» -->
    <v-card v-if="continueCourse" class="continue-card pa-4 mb-4" elevation="0">
      <div class="d-flex align-center ga-4">
        <v-avatar size="52" color="primary-soft" rounded="lg">
          <v-icon size="28" color="primary">mdi-play-circle</v-icon>
        </v-avatar>
        <div class="flex-grow-1 min-w-0">
          <div class="text-caption font-weight-bold text-uppercase text-primary letter-spacing-1">
            продолжите с того места
          </div>
          <div class="text-subtitle-1 font-weight-bold mt-1 text-truncate">
            {{ continueCourse.title }}
          </div>
          <div class="d-flex align-center ga-3 mt-2">
            <v-progress-linear
              :model-value="continueCourse.progress"
              color="primary" height="6" rounded
              style="max-width: 320px"
            />
            <span class="text-caption text-medium-emphasis tabular-nums">
              {{ continueCourse.progress }}% · {{ continueCourse.lessonViewed }}/{{ continueCourse.lessonCount }}
            </span>
          </div>
        </div>
        <v-btn color="primary" size="large" :to="courseLink(continueCourse)">
          Продолжить
          <v-icon end>mdi-arrow-right</v-icon>
        </v-btn>
      </div>
    </v-card>

    <!-- База знаний — портал -->
    <v-card
      class="kb-card pa-5 mb-6"
      elevation="0"
      :to="'/education/kb'"
    >
      <div class="d-flex align-center ga-4">
        <v-avatar size="56" color="primary" rounded="lg">
          <v-icon size="28" color="white">mdi-book-open-variant</v-icon>
        </v-avatar>
        <div class="flex-grow-1">
          <div class="text-h6 font-weight-bold" style="color: rgb(var(--v-theme-primary))">
            База знаний
          </div>
          <div class="text-body-2 text-medium-emphasis mt-1">
            Регламенты, инструкции, записи деловых игр и созвонов
          </div>
        </div>
        <div class="text-caption text-medium-emphasis text-right" style="line-height: 1.4">
          <div>{{ kbStats.articles }} материалов</div>
          <div v-if="kbStats.lastUpdated">обновлено {{ fmtRelative(kbStats.lastUpdated) }}</div>
        </div>
        <v-icon size="28" color="primary">mdi-arrow-right</v-icon>
      </div>
    </v-card>

    <!-- Мои курсы -->
    <div class="d-flex align-baseline ga-3 mb-3">
      <h2 class="text-h6 font-weight-bold">Мои курсы</h2>
      <span class="text-caption text-medium-emphasis">
        · {{ filteredCourses.length }} из {{ totalCourses }}
      </span>
    </div>

    <div v-if="loading" class="d-flex justify-center pa-6">
      <v-progress-circular indeterminate color="primary" />
    </div>

    <EmptyState
      v-else-if="!filteredCourses.length"
      icon="mdi-school-outline"
      title="Курсов пока нет"
      description="Администратор скоро добавит учебные материалы"
    />

    <v-row v-else dense>
      <v-col
        v-for="(c, idx) in filteredCourses"
        :key="c.id"
        cols="12" sm="6" md="4" lg="3"
      >
        <v-card
          class="course-card pa-3 h-100 d-flex flex-column"
          elevation="0"
          :to="courseLink(c)"
        >
          <div class="course-cover" :style="coverStyle(idx, c)">
            <div class="course-num">{{ String(idx + 1).padStart(2, '0') }}</div>
            <v-chip
              v-if="c.productId && !c.testPassed"
              size="x-small" color="warning" variant="elevated"
              class="cover-chip"
            >
              <v-icon start size="14">mdi-lock</v-icon>
              нужен тест
            </v-chip>
            <v-chip
              v-else-if="c.productId && c.testPassed"
              size="x-small" color="success" variant="elevated"
              class="cover-chip"
            >
              <v-icon start size="14">mdi-lock-open</v-icon>
              открыто
            </v-chip>
          </div>

          <div class="course-title mt-3">{{ c.title }}</div>
          <div class="text-caption text-medium-emphasis">{{ subtitleFor(c) }}</div>

          <div class="mt-3">
            <v-progress-linear
              :model-value="c.progress"
              :color="c.progress === 100 ? 'success' : 'primary'"
              height="6" rounded
            />
          </div>

          <div class="d-flex justify-space-between align-center mt-2">
            <span
              class="text-caption tabular-nums"
              :class="c.progress === 100 ? 'text-success font-weight-bold' : 'text-medium-emphasis'"
            >
              <template v-if="c.progress === 100">✓ изучен</template>
              <template v-else>{{ c.progress }}%</template>
            </span>
            <span class="text-caption text-primary font-weight-medium">
              {{ ctaLabel(c) }} →
            </span>
          </div>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api';
import EmptyState from '../components/EmptyState.vue';

const tree = ref([]);
const kbStats = ref({ articles: 0, lastUpdated: null });
const loading = ref(true);
const search = ref('');

/**
 * Корневые курсы для главной — `parent_id === null`. Модули/подмодули
 * показываем только внутри курса (страница курса). Прогресс
 * подсчитывается на всех вложенных уровнях рекурсивно: считаем сумму
 * lessonCount/lessonViewed по всему поддереву.
 */
const courses = computed(() => {
  return tree.value
    .filter(c => !c.parent_id)
    .map(c => {
      const stats = aggregateStats(c);
      const progress = stats.total ? Math.round((stats.viewed / stats.total) * 100) : 0;
      return {
        ...c,
        lessonCount: stats.total,
        lessonViewed: stats.viewed,
        progress,
      };
    });
});

function aggregateStats(node) {
  let total = node.lessonCount || 0;
  let viewed = node.lessonViewed || 0;
  for (const child of node.children || []) {
    const s = aggregateStats(child);
    total += s.total;
    viewed += s.viewed;
  }
  return { total, viewed };
}

const filteredCourses = computed(() => {
  if (!search.value) return courses.value;
  const q = search.value.trim().toLowerCase();
  return courses.value.filter(c =>
    (c.title || '').toLowerCase().includes(q) ||
    (c.description || '').toLowerCase().includes(q)
  );
});

const totalCourses = computed(() => courses.value.length);
const inProgressCount = computed(() =>
  courses.value.filter(c => c.progress > 0 && c.progress < 100).length
);
const pendingTestCount = computed(() =>
  courses.value.filter(c => c.productId && !c.testPassed).length
);
const continueCourse = computed(() => {
  const inProg = courses.value.find(c => c.progress > 0 && c.progress < 100);
  return inProg || null;
});

function courseLink(c) { return `/education/courses/${c.id}`; }

function subtitleFor(c) {
  const lessonsPart = c.lessonCount
    ? `${c.lessonCount} ${pluralLesson(c.lessonCount)}`
    : 'материалы готовятся';
  const productPart = c.productId ? ' · допуск к продукту' : '';
  return lessonsPart + productPart;
}

function ctaLabel(c) {
  if (c.progress === 0) return 'перейти';
  if (c.progress === 100) return 'повторить';
  return 'продолжить';
}

const palette = [
  'linear-gradient(135deg, #1B5E20, #6EE87A)',
  'linear-gradient(135deg, #2E7D32, #A4E0AC)',
  'linear-gradient(135deg, #4a5d4e, #c5d8c8)',
  'linear-gradient(135deg, #2E7D32, #6EE87A)',
  'linear-gradient(135deg, #3d4a3f, #8aa68d)',
  'linear-gradient(135deg, #2d3a30, #6e8470)',
  'linear-gradient(135deg, #1B5E20, #4caf50)',
  'linear-gradient(135deg, #3d5240, #8eb293)',
];
function coverStyle(idx, c) {
  if (c.coverUrl) return { background: `url(${c.coverUrl}) center/cover` };
  return { background: palette[idx % palette.length] };
}

function pluralCourse(n) {
  if (n % 10 === 1 && n % 100 !== 11) return 'курс';
  if ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100)) return 'курса';
  return 'курсов';
}
function pluralLesson(n) {
  if (n % 10 === 1 && n % 100 !== 11) return 'урок';
  if ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100)) return 'урока';
  return 'уроков';
}

function fmtRelative(d) {
  try {
    const date = new Date(d);
    const diff = (Date.now() - date.getTime()) / 1000;
    if (diff < 3600) return `${Math.round(diff / 60)} мин назад`;
    if (diff < 86400) return `сегодня в ${date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}`;
    if (diff < 7 * 86400) return `${Math.round(diff / 86400)} д назад`;
    return date.toLocaleDateString('ru-RU');
  } catch { return ''; }
}

async function loadTree() {
  loading.value = true;
  try {
    const [{ data: t }, { data: kb }] = await Promise.all([
      api.get('/education/tree'),
      api.get('/education/kb'),
    ]);
    tree.value = t.tree || [];
    const articles = (kb.sections || []).reduce((sum, s) => {
      let cnt = s.articleCount || 0;
      const stack = [...(s.children || [])];
      while (stack.length) {
        const c = stack.pop();
        cnt += c.articleCount || 0;
        stack.push(...(c.children || []));
      }
      return sum + cnt;
    }, 0);
    kbStats.value = { articles, lastUpdated: null };
  } catch {}
  loading.value = false;
}

onMounted(loadTree);
</script>

<style scoped>
.education-home { max-width: 1400px; margin: 0 auto; }

.tabular-nums { font-variant-numeric: tabular-nums; }
.letter-spacing-1 { letter-spacing: 1.2px; }
.min-w-0 { min-width: 0; }

.continue-card {
  border: 1px solid var(--ds-outline-variant, rgba(var(--v-theme-on-surface), 0.08));
  border-left: 4px solid rgb(var(--v-theme-primary));
  border-radius: var(--ds-radius-lg, 12px);
}

.kb-card {
  border-radius: var(--ds-radius-lg, 12px);
  border: 1.5px dashed rgb(var(--v-theme-primary));
  background: linear-gradient(135deg,
    rgba(46, 125, 50, 0.06) 0%,
    rgba(110, 232, 122, 0.04) 100%);
  position: relative;
  overflow: hidden;
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.kb-card::before {
  content: '';
  position: absolute; inset: 0;
  background-image: repeating-linear-gradient(135deg,
    transparent 0 14px, rgba(46, 125, 50, 0.04) 14px 15px);
  pointer-events: none;
}
.kb-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(46, 125, 50, 0.12);
}

.course-card {
  border: 1px solid var(--ds-outline-variant, rgba(var(--v-theme-on-surface), 0.08));
  border-radius: var(--ds-radius-lg, 12px);
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.course-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
}

.course-cover {
  height: 110px;
  border-radius: var(--ds-radius-md, 8px);
  position: relative;
  overflow: hidden;
}
.course-num {
  position: absolute; top: 12px; left: 14px;
  color: rgba(255, 255, 255, 0.92);
  font-family: 'JetBrains Mono', monospace;
  font-weight: 700; font-size: 13px;
  letter-spacing: 1px;
}
.cover-chip {
  position: absolute; top: 10px; right: 10px;
  backdrop-filter: blur(4px);
}

.course-title {
  font-size: 14px;
  font-weight: 600;
  line-height: 1.3;
  min-height: 36px;
  color: rgb(var(--v-theme-on-surface));
}
</style>
