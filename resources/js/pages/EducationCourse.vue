<template>
  <div class="course-page">
    <v-breadcrumbs :items="crumbItems" density="compact" class="px-6 py-2" />

    <div v-if="loading" class="d-flex justify-center pa-6">
      <v-progress-circular indeterminate color="primary" />
    </div>

    <div v-else-if="course" class="course-layout">
      <!-- Дерево курса -->
      <aside class="course-tree">
        <div class="px-4 pt-4 pb-3">
          <div class="text-caption font-weight-bold text-uppercase text-medium-emphasis letter-spacing-1">
            Курс
          </div>
          <div class="text-subtitle-2 font-weight-bold mt-1">{{ rootCourse.title }}</div>
          <v-progress-linear
            :model-value="rootProgress.percent"
            color="primary" height="6" rounded
            class="mt-2"
          />
          <div class="text-caption text-medium-emphasis tabular-nums mt-1">
            {{ rootProgress.percent }}% · {{ rootProgress.viewed }} из {{ rootProgress.total }}
          </div>
        </div>
        <v-divider />
        <div class="pa-2">
          <CourseTreeNode
            v-for="node in rootCourse.children"
            :key="node.id"
            :node="node"
            :current-id="route.params.id"
            :level="1"
            @navigate="goToCourse"
          />
        </div>
      </aside>

      <!-- Контент -->
      <section class="course-content">
        <!-- Hero -->
        <div class="hero" :style="heroStyle">
          <div class="hero-overlay" />
          <div class="hero-pattern" />
          <div class="hero-content">
            <div class="text-caption text-uppercase text-white opacity-85 letter-spacing-1">
              {{ heroKicker }}
            </div>
            <h1 class="text-h4 font-weight-bold text-white mt-1">{{ course.title }}</h1>
            <div v-if="course.description" class="text-body-2 text-white mt-2" style="opacity: 0.9">
              {{ course.description }}
            </div>
          </div>
        </div>

        <!-- Прогресс + CTA -->
        <v-row dense class="mt-4 px-2">
          <v-col cols="12" md="8">
            <div class="d-flex align-baseline justify-space-between mb-2">
              <span class="text-subtitle-1 font-weight-bold">Прогресс по курсу</span>
              <span class="text-caption text-medium-emphasis tabular-nums">
                {{ totalProgress.percent }}% · изучено {{ totalProgress.viewed }} из {{ totalProgress.total }}
              </span>
            </div>
            <v-progress-linear
              :model-value="totalProgress.percent"
              color="primary" height="10" rounded
            />
          </v-col>
          <v-col v-if="nextLesson" cols="12" md="4">
            <v-card class="cta-card pa-3" elevation="0">
              <div class="d-flex align-center ga-3">
                <v-avatar size="36" color="primary" rounded="lg">
                  <v-icon size="18" color="white">mdi-play</v-icon>
                </v-avatar>
                <div class="flex-grow-1 min-w-0">
                  <div class="text-caption text-uppercase text-primary font-weight-bold letter-spacing-1">
                    следующий урок
                  </div>
                  <div
                    class="text-body-2 font-weight-bold mt-1 text-truncate"
                    style="color: rgb(var(--v-theme-primary))"
                  >
                    {{ nextLesson.title }}
                  </div>
                </div>
                <v-btn
                  icon="mdi-arrow-right" size="small"
                  color="primary" variant="text"
                  @click="openLesson(nextLesson)"
                />
              </div>
            </v-card>
          </v-col>
        </v-row>

        <!-- Прямые дочерние узлы (модули) или прямые уроки -->
        <div v-if="course.children?.length || ownLessons.length" class="mt-6 px-2">
          <h3 class="text-subtitle-1 font-weight-bold mb-3">
            {{ course.children?.length ? 'Структура курса' : 'Уроки курса' }}
          </h3>

          <!-- Модули как карточки -->
          <v-row v-if="course.children?.length" dense>
            <v-col
              v-for="(m, idx) in course.children"
              :key="m.id"
              cols="12" sm="6" md="4"
            >
              <v-card
                class="module-card pa-3 h-100"
                elevation="0"
                :to="`/education/courses/${m.id}`"
              >
                <div class="d-flex justify-space-between align-start mb-2">
                  <span class="module-num">M{{ idx + 1 }}</span>
                  <v-chip
                    v-if="moduleStatus(m).status === 'done'"
                    size="x-small" color="success" variant="tonal"
                  >
                    <v-icon start size="14">mdi-check-circle</v-icon>
                    изучен
                  </v-chip>
                  <v-chip
                    v-else-if="moduleStatus(m).status === 'prog'"
                    size="x-small" color="warning" variant="tonal"
                  >
                    <v-icon start size="14">mdi-circle-slice-4</v-icon>
                    в процессе
                  </v-chip>
                  <v-chip
                    v-else
                    size="x-small" variant="tonal"
                  >открыт</v-chip>
                </div>
                <div class="text-subtitle-2 font-weight-bold">{{ m.title }}</div>
                <div class="text-caption text-medium-emphasis mt-1">
                  {{ moduleSubtitle(m) }}
                </div>
                <v-progress-linear
                  :model-value="moduleStatus(m).percent"
                  :color="moduleStatus(m).percent === 100 ? 'success' : 'primary'"
                  height="6" rounded
                  class="mt-3"
                />
              </v-card>
            </v-col>
          </v-row>

          <!-- Прямые уроки (если курс — лист) -->
          <v-list v-else density="compact" class="pa-0">
            <v-list-item
              v-for="l in ownLessons"
              :key="l.id"
              :title="l.title"
              :subtitle="l.viewed ? '✓ изучен' : 'не изучен'"
              :prepend-icon="l.viewed ? 'mdi-check-circle' : 'mdi-circle-outline'"
              @click="openLesson(l)"
            >
              <template #append>
                <v-icon size="small">mdi-chevron-right</v-icon>
              </template>
            </v-list-item>
          </v-list>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../api';
import CourseTreeNode from '../components/education/CourseTreeNode.vue';

const route = useRoute();
const router = useRouter();
const tree = ref([]);
const courseDetail = ref(null);
const loading = ref(true);

const course = computed(() => {
  if (!courseDetail.value) return null;
  return findInTree(tree.value, Number(route.params.id)) || courseDetail.value;
});

const crumbItems = computed(() => {
  const items = [{ title: 'Обучение', to: '/education', disabled: false }];
  if (courseDetail.value?.breadcrumbs) {
    courseDetail.value.breadcrumbs.forEach((b, i, arr) => {
      items.push({
        title: b.title,
        to: i === arr.length - 1 ? undefined : `/education/courses/${b.id}`,
        disabled: i === arr.length - 1,
      });
    });
  }
  return items;
});

const rootCourse = computed(() => {
  const cId = Number(route.params.id);
  return findRoot(tree.value, cId) || course.value;
});

const rootProgress = computed(() => aggregate(rootCourse.value));
const totalProgress = computed(() => aggregate(course.value));

const ownLessons = computed(() => courseDetail.value?.lessons || []);

const nextLesson = computed(() => {
  for (const l of ownLessons.value) if (!l.viewed) return l;
  return null;
});

const heroKicker = computed(() => {
  if (course.value?.isContainer) return 'Модуль';
  return course.value?.parent_id ? 'Подмодуль · курс' : 'Курс · базовый';
});

const heroStyle = computed(() => ({
  background: course.value?.coverUrl
    ? `url(${course.value.coverUrl}) center/cover`
    : 'linear-gradient(135deg, #1B5E20, #6EE87A)',
}));

function aggregate(node) {
  if (!node) return { total: 0, viewed: 0, percent: 0 };
  let total = node.lessonCount || 0;
  let viewed = node.lessonViewed || 0;
  for (const c of node.children || []) {
    const s = aggregate(c);
    total += s.total;
    viewed += s.viewed;
  }
  return {
    total,
    viewed,
    percent: total ? Math.round((viewed / total) * 100) : 0,
  };
}

function findInTree(nodes, id) {
  for (const n of nodes || []) {
    if (n.id === id) return n;
    const sub = findInTree(n.children, id);
    if (sub) return sub;
  }
  return null;
}

function findRoot(nodes, id) {
  for (const n of nodes || []) {
    if (n.id === id) return n;
    if (findInTree(n.children, id)) return n;
  }
  return null;
}

function moduleStatus(m) {
  const a = aggregate(m);
  let status = 'open';
  if (a.percent === 100 && a.total > 0) status = 'done';
  else if (a.percent > 0) status = 'prog';
  return { ...a, status };
}

function moduleSubtitle(m) {
  const childCount = m.children?.length || 0;
  const lessonCount = aggregate(m).total;
  const parts = [];
  if (childCount) parts.push(`${childCount} ${plural(childCount, 'подмодуль', 'подмодуля', 'подмодулей')}`);
  if (lessonCount) parts.push(`${lessonCount} ${plural(lessonCount, 'урок', 'урока', 'уроков')}`);
  return parts.join(' · ') || 'материалы готовятся';
}

function plural(n, one, few, many) {
  if (n % 10 === 1 && n % 100 !== 11) return one;
  if ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100)) return few;
  return many;
}

function goToCourse(id) {
  router.push(`/education/courses/${id}`);
}

function openLesson(l) {
  router.push(`/education/courses/${route.params.id}/lessons/${l.id}`);
}

/**
 * Сертификат — endpoint защищён auth:sanctum (Bearer-токен), поэтому
 * прямой <a target=_blank> не сработает. Качаем через axios, открываем
 * в новой вкладке через blob.
 */
async function openCertificate() {
  try {
    const resp = await api.get(`/education/courses/${route.params.id}/certificate`, {
      responseType: 'blob',
    });
    const blob = new Blob([resp.data], { type: 'text/html;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    window.open(url, '_blank');
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
  } catch (e) {
    alert(e.response?.data?.message || 'Сертификат недоступен');
  }
}

async function load() {
  loading.value = true;
  try {
    const [{ data: t }, { data: d }] = await Promise.all([
      api.get('/education/tree'),
      api.get(`/education/courses/${route.params.id}/full`),
    ]);
    tree.value = t.tree || [];
    courseDetail.value = d;
  } catch (e) {
    courseDetail.value = null;
  }
  loading.value = false;
}

watch(() => route.params.id, (id) => { if (id) load(); });
onMounted(load);
</script>

<style scoped>
.course-page {
  display: flex;
  flex-direction: column;
  min-height: calc(100vh - 64px);
}
.course-layout {
  display: grid;
  grid-template-columns: 280px 1fr;
  flex: 1;
}
@media (max-width: 960px) {
  .course-layout { grid-template-columns: 1fr; }
  .course-tree { display: none; }
}
.course-tree {
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  background: rgb(var(--v-theme-surface));
  max-height: calc(100vh - 110px);
  overflow-y: auto;
  position: sticky;
  top: 64px;
}
.course-content { padding: 16px 28px 40px; overflow-x: hidden; }

.tabular-nums { font-variant-numeric: tabular-nums; }
.letter-spacing-1 { letter-spacing: 1.2px; }
.min-w-0 { min-width: 0; }

.hero {
  border-radius: 14px;
  overflow: hidden;
  height: 180px;
  padding: 24px 28px;
  position: relative;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
}
.hero-overlay {
  position: absolute; inset: 0;
  background: linear-gradient(180deg, transparent 0%, rgba(0,0,0,0.45) 100%);
}
.hero-pattern {
  position: absolute; inset: 0;
  background-image: repeating-linear-gradient(45deg,
    transparent 0 30px, rgba(255,255,255,0.04) 30px 31px);
}
.hero-content { position: relative; z-index: 1; }

.cta-card {
  background: rgba(46, 125, 50, 0.08);
  border: 1px solid rgba(46, 125, 50, 0.2);
  border-radius: 12px;
}

.module-card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  border-radius: 12px;
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.module-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
}
.module-num {
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  font-weight: 600;
  color: rgba(var(--v-theme-on-surface), 0.55);
}
</style>
