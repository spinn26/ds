<template>
  <div class="lesson-page">
    <v-breadcrumbs :items="crumbItems" density="compact" class="px-6 py-2" />

    <div v-if="loading" class="d-flex justify-center pa-6">
      <v-progress-circular indeterminate color="primary" />
    </div>

    <div v-else-if="lesson" class="lesson-layout">
      <aside class="lesson-tree">
        <div class="px-3 pt-3 pb-2 text-caption text-uppercase font-weight-bold text-medium-emphasis letter-spacing-1">
          {{ kicker }}
        </div>
        <v-divider />
        <div class="pa-2">
          <CourseTreeNode
            v-for="node in rootCourse?.children || []"
            :key="node.id"
            :node="node"
            :current-id="String(route.params.id)"
            :level="1"
            @navigate="goToCourse"
          />
        </div>
      </aside>

      <section class="lesson-content">
        <div class="sticky-header">
          <div class="flex-grow-1 min-w-0">
            <div class="text-caption text-uppercase text-medium-emphasis font-weight-bold letter-spacing-1">
              {{ courseTitle }}
            </div>
            <h1 class="text-h5 font-weight-bold mt-1">{{ lesson.title }}</h1>
          </div>
          <v-chip v-if="!lesson.available" size="default" color="warning" variant="tonal" prepend-icon="mdi-lock-clock">
            {{ lesson.unavailableReason || 'Закрыт' }}
          </v-chip>
          <v-btn
            v-else-if="!lesson.viewed"
            color="primary" size="large"
            :loading="marking"
            prepend-icon="mdi-check"
            :disabled="lesson.requiresHomework && !homeworkApproved"
            @click="markViewed"
          >
            Урок изучен
          </v-btn>
          <v-chip v-else size="default" color="success" variant="tonal" prepend-icon="mdi-check-circle">
            Изучено
          </v-chip>
        </div>

        <div class="body-blocks">
          <!-- Урок-тест: CTA на прохождение теста курса -->
          <div v-if="lesson.isTest" class="test-lesson-card">
            <v-icon size="64" color="primary" class="mb-3">mdi-help-circle-outline</v-icon>
            <div class="text-h5 font-weight-bold mb-2">Тест по курсу</div>
            <div class="text-body-1 text-medium-emphasis mb-4" style="max-width: 540px;">
              Это итоговый тест. Ответьте правильно на все вопросы — и курс будет
              считаться пройденным. После сдачи откроется доступ к продукту.
            </div>
            <v-btn
              :to="`/education/courses/${route.params.id}/test`"
              color="primary" size="large"
              prepend-icon="mdi-play"
            >
              Пройти тест
            </v-btn>
          </div>

          <!-- Описание из legacy content -->
          <div v-else-if="lesson.description" class="block block-text">
            {{ lesson.description }}
          </div>

          <!-- Новый формат body[] — единый рендерер -->
          <LessonBlockRenderer v-if="!lesson.isTest && blocks.length" :blocks="blocks" />

          <!-- Legacy video_urls / document_urls (если body пуст) -->
          <template v-else-if="!lesson.isTest">
            <div v-if="videos.length" class="block">
              <div
                v-for="(v, vi) in videos"
                :key="'v' + vi"
                class="mb-4"
              >
                <div v-if="v.label" class="block-caption mb-2">{{ v.label }}</div>
                <div v-if="toEmbed(v.url)" class="video-frame">
                  <iframe
                    :src="toEmbed(v.url)"
                    frameborder="0"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen
                  />
                </div>
                <v-btn v-else :href="v.url" target="_blank" color="primary" variant="tonal" prepend-icon="mdi-play">
                  Открыть видео
                </v-btn>
              </div>
            </div>

            <div v-if="docs.length" class="block">
              <div class="block-caption mb-2">Материалы и ссылки</div>
              <div class="d-flex flex-wrap ga-2">
                <v-btn
                  v-for="(d, di) in docs"
                  :key="'d' + di"
                  :href="d.url" target="_blank"
                  :prepend-icon="docIcon(d.url)"
                  color="primary" variant="tonal" size="small"
                >
                  {{ d.label || `Открыть ${di + 1}` }}
                </v-btn>
              </div>
            </div>
          </template>

          <!-- Домашка (per ТЗ + правки) -->
          <div v-if="lesson.requiresHomework" class="homework-card">
            <div class="d-flex align-center mb-2">
              <v-icon size="20" color="primary" class="me-2">mdi-clipboard-edit-outline</v-icon>
              <span class="text-subtitle-1 font-weight-bold">Домашнее задание</span>
              <v-spacer />
              <v-chip v-if="homeworkStatusChip" size="small" :color="homeworkStatusChip.color" variant="tonal">
                {{ homeworkStatusChip.label }}
              </v-chip>
            </div>
            <div v-if="lesson.homeworkInstructions" class="text-body-2 text-medium-emphasis mb-3" style="white-space:pre-wrap">
              {{ lesson.homeworkInstructions }}
            </div>
            <v-textarea
              v-model="homeworkText"
              label="Ваш ответ"
              variant="outlined" density="comfortable"
              :readonly="homeworkApproved"
              rows="4" auto-grow
            />
            <!-- Вложения файлов -->
            <div v-if="hwAttachments.length" class="mt-2 d-flex flex-wrap ga-2">
              <v-chip
                v-for="(a, i) in hwAttachments"
                :key="i"
                size="small" variant="tonal" color="primary"
                :closable="!homeworkApproved"
                :href="a.url" target="_blank"
                prepend-icon="mdi-paperclip"
                @click:close="hwAttachments.splice(i, 1)"
              >
                {{ a.name || 'файл' }}
              </v-chip>
            </div>
            <input
              ref="hwFileInputRef"
              type="file"
              style="display:none"
              @change="onHwFileSelected"
            />
            <v-btn
              v-if="!homeworkApproved"
              variant="text" size="small"
              prepend-icon="mdi-paperclip"
              :loading="uploadingHwFile"
              class="mt-2"
              @click="hwFileInputRef?.click()"
            >
              Прикрепить файл
            </v-btn>
            <div v-if="myHomework?.reviewerComment" class="reviewer-comment mt-2">
              <v-icon size="16" color="primary" class="me-1">mdi-comment-text-outline</v-icon>
              <span class="text-body-2">{{ myHomework.reviewerComment }}</span>
            </div>
            <v-btn
              v-if="!homeworkApproved"
              color="primary" class="mt-2"
              :loading="submittingHw"
              prepend-icon="mdi-send"
              @click="submitHomework"
            >
              {{ myHomework ? 'Отправить ещё раз' : 'Отправить на проверку' }}
            </v-btn>
          </div>

          <!-- Навигация -->
          <div class="lesson-nav">
            <v-btn
              variant="outlined"
              :disabled="!prevLesson"
              @click="navTo(prevLesson)"
            >
              <v-icon start>mdi-arrow-left</v-icon>
              Предыдущий
            </v-btn>
            <v-btn variant="text" :to="`/education/courses/${route.params.id}`">
              К модулю
            </v-btn>
            <v-btn
              v-if="nextLesson"
              color="primary"
              @click="navTo(nextLesson)"
            >
              Следующий
              <v-icon end>mdi-arrow-right</v-icon>
            </v-btn>
            <v-btn
              v-else-if="hasTest"
              color="primary"
              :to="`/education/courses/${route.params.id}/test`"
              prepend-icon="mdi-clipboard-check"
            >
              К тесту
            </v-btn>
          </div>
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
import LessonBlockRenderer from '../components/education/LessonBlockRenderer.vue';

const route = useRoute();
const router = useRouter();
const tree = ref([]);
const courseDetail = ref(null);
const loading = ref(true);
const marking = ref(false);

const lesson = computed(() => {
  const lid = Number(route.params.lid);
  return courseDetail.value?.lessons?.find(l => l.id === lid) || null;
});

// === Домашние задания ===
const myHomework = ref(null);
const homeworkText = ref('');
const hwAttachments = ref([]);
const submittingHw = ref(false);
const hwFileInputRef = ref(null);
const uploadingHwFile = ref(false);

async function onHwFileSelected(e) {
  const file = e.target?.files?.[0];
  if (!file) return;
  uploadingHwFile.value = true;
  try {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('kind', 'homework');
    const { data } = await api.post('/education/upload', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    if (data?.url) {
      hwAttachments.value.push({ name: data.name, url: data.url, size: data.size });
    }
  } catch (err) {
    alert(err.response?.data?.message || 'Не удалось загрузить файл');
  }
  uploadingHwFile.value = false;
  e.target.value = '';
}

const homeworkApproved = computed(() => myHomework.value?.status === 'approved');
const homeworkStatusChip = computed(() => {
  const s = myHomework.value?.status;
  if (s === 'approved') return { color: 'success', label: '✓ Принято куратором' };
  if (s === 'rejected') return { color: 'error',   label: '✗ Отклонено — попробуйте ещё раз' };
  if (s === 'pending')  return { color: 'warning', label: '⌛ На проверке' };
  return null;
});

async function submitHomework() {
  if (!lesson.value) return;
  submittingHw.value = true;
  try {
    await api.post(`/education/lessons/${lesson.value.id}/homework`, {
      answer_text: homeworkText.value,
      attachments: hwAttachments.value,
    });
    await loadHomework();
  } catch {}
  submittingHw.value = false;
}

async function loadHomework() {
  if (!lesson.value) return;
  try {
    const { data } = await api.get('/education/homework/my');
    const item = (data.items || []).find(h => h.lessonId === lesson.value.id);
    myHomework.value = item || null;
    homeworkText.value = item?.answerText || '';
    hwAttachments.value = Array.isArray(item?.attachments) ? [...item.attachments] : [];
  } catch {}
}

watch(lesson, (l) => {
  if (l?.requiresHomework) loadHomework();
  else {
    myHomework.value = null;
    homeworkText.value = '';
    hwAttachments.value = [];
  }
}, { immediate: false });

const blocks = computed(() => {
  const b = lesson.value?.body;
  if (!b) return [];
  if (Array.isArray(b)) return b;
  try { const p = JSON.parse(b); return Array.isArray(p) ? p : []; } catch { return []; }
});

const videos = computed(() => normalize(lesson.value?.videoUrls));
const docs = computed(() => normalize(lesson.value?.documentUrls));

function normalize(arr) {
  if (!Array.isArray(arr)) return [];
  return arr.map(i => typeof i === 'string'
    ? { url: i, label: null }
    : { url: i?.url || '', label: i?.label || null }
  ).filter(x => x.url);
}

const courseTitle = computed(() => courseDetail.value?.title || '');
const kicker = computed(() => {
  const idx = courseDetail.value?.lessons?.findIndex(l => l.id === Number(route.params.lid));
  const total = courseDetail.value?.lessons?.length || 0;
  if (idx == null || idx < 0) return courseTitle.value;
  return `Урок ${idx + 1} из ${total} · ${courseTitle.value}`;
});

const rootCourse = computed(() => {
  const cId = Number(route.params.id);
  return findRoot(tree.value, cId);
});

function findRoot(nodes, id) {
  for (const n of nodes || []) {
    if (n.id === id) return n;
    if (findInTree(n.children, id)) return n;
  }
  return null;
}
function findInTree(nodes, id) {
  for (const n of nodes || []) {
    if (n.id === id) return n;
    const s = findInTree(n.children, id);
    if (s) return s;
  }
  return null;
}

const prevLesson = computed(() => {
  const list = courseDetail.value?.lessons || [];
  const idx = list.findIndex(l => l.id === Number(route.params.lid));
  return idx > 0 ? list[idx - 1] : null;
});
const nextLesson = computed(() => {
  const list = courseDetail.value?.lessons || [];
  const idx = list.findIndex(l => l.id === Number(route.params.lid));
  return idx >= 0 && idx < list.length - 1 ? list[idx + 1] : null;
});

const hasTest = computed(() => {
  const c = findInTree(tree.value, Number(route.params.id));
  return c?.hasTest && !c?.testPassed;
});

const crumbItems = computed(() => {
  const items = [{ title: 'Обучение', to: '/education', disabled: false }];
  (courseDetail.value?.breadcrumbs || []).forEach(b => {
    items.push({ title: b.title, to: `/education/courses/${b.id}`, disabled: false });
  });
  items.push({ title: lesson.value?.title || '—', disabled: true });
  return items;
});

function navTo(l) {
  if (!l) return;
  router.push(`/education/courses/${route.params.id}/lessons/${l.id}`);
}
function goToCourse(id) {
  router.push(`/education/courses/${id}`);
}

async function markViewed() {
  if (!lesson.value || lesson.value.viewed) return;
  marking.value = true;
  try {
    await api.post(`/education/lessons/${lesson.value.id}/view`);
    lesson.value.viewed = true;
  } finally { marking.value = false; }
}

function toEmbed(url) {
  if (!url) return null;
  try {
    const u = new URL(url);
    const host = u.hostname.replace(/^www\./, '');
    if (host === 'rutube.ru') {
      if (u.pathname.startsWith('/play/embed/')) return url;
      const m = u.pathname.match(/\/video\/(?:private\/)?([a-f0-9]+)/i);
      if (m) {
        const p = u.searchParams.get('p');
        return `https://rutube.ru/play/embed/${m[1]}` + (p ? `?p=${encodeURIComponent(p)}` : '');
      }
    }
    if (host === 'youtube.com' || host === 'm.youtube.com') {
      const v = u.searchParams.get('v');
      if (v) return `https://www.youtube.com/embed/${v}`;
      if (u.pathname.startsWith('/embed/')) return url;
    }
    if (host === 'youtu.be') {
      const id = u.pathname.slice(1).split('/')[0];
      if (id) return `https://www.youtube.com/embed/${id}`;
    }
    if (host === 'vimeo.com') {
      const id = u.pathname.slice(1).split('/')[0];
      if (/^\d+$/.test(id)) return `https://player.vimeo.com/video/${id}`;
    }
  } catch {}
  return null;
}

function docIcon(url) {
  const u = (url || '').toLowerCase();
  if (u.endsWith('.pdf')) return 'mdi-file-pdf-box';
  if (u.match(/\.(docx?|odt)(\?|$)/)) return 'mdi-file-word-box';
  if (u.match(/\.(xlsx?|csv|ods)(\?|$)/)) return 'mdi-file-excel-box';
  if (u.match(/\.(pptx?|key)(\?|$)/)) return 'mdi-file-powerpoint-box';
  if (u.match(/\.(zip|rar|7z|tar|gz)(\?|$)/)) return 'mdi-folder-zip';
  return 'mdi-link';
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
  } catch {}
  loading.value = false;
}

watch(() => `${route.params.id}/${route.params.lid}`, () => load());
onMounted(load);
</script>

<style scoped>
.lesson-page { min-height: calc(100vh - 64px); }
.lesson-layout {
  display: grid;
  grid-template-columns: 280px 1fr;
  min-height: calc(100vh - 110px);
}
@media (max-width: 1100px) {
  .lesson-layout { grid-template-columns: 1fr; }
  .lesson-tree { display: none; }
}
.lesson-tree {
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  background: rgb(var(--v-theme-surface));
  position: sticky;
  top: 64px;
  max-height: calc(100vh - 110px);
  overflow-y: auto;
}
.lesson-content {
  padding: 0;
  overflow-x: hidden;
  background: rgb(var(--v-theme-background));
}

.sticky-header {
  position: sticky;
  top: 0;
  z-index: 2;
  background: rgb(var(--v-theme-background));
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  padding: 28px 56px 20px;
  display: flex;
  align-items: flex-start;
  gap: 16px;
}
.sticky-header h1 { font-size: 28px !important; line-height: 1.3; }

.body-blocks {
  padding: 32px 56px 56px;
  max-width: 1100px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 28px;
}
@media (max-width: 700px) {
  .sticky-header { padding: 20px 20px 14px; }
  .body-blocks { padding: 20px 20px 40px; }
}

.test-lesson-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 56px 24px;
  border: 1px dashed rgba(46, 125, 50, 0.35);
  border-radius: 16px;
  background: rgba(110, 232, 122, 0.06);
}

.block { width: 100%; }
.block-text { font-size: 14.5px; line-height: 1.6; color: rgb(var(--v-theme-on-surface)); white-space: pre-wrap; }
.block-caption { font-size: 12px; font-weight: 600; color: rgba(var(--v-theme-on-surface), 0.55); }
.block-image { width: 100%; border-radius: 10px; }
.block-file, .block-link {
  display: inline-flex;
  align-items: center;
  padding: 10px 14px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 10px;
  color: rgb(var(--v-theme-primary));
  text-decoration: none;
  font-weight: 500;
}
.block-file:hover, .block-link:hover { background: rgba(46, 125, 50, 0.06); }

.video-frame {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 9;
  border-radius: 10px;
  overflow: hidden;
  background: rgba(var(--v-theme-on-surface), 0.04);
}
.video-frame iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }

.lesson-nav {
  margin-top: 22px;
  padding-top: 22px;
  border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}

.homework-card {
  margin-top: 18px;
  padding: 16px;
  border: 1px solid rgba(46, 125, 50, 0.2);
  background: rgba(46, 125, 50, 0.04);
  border-radius: 12px;
}
.reviewer-comment {
  padding: 10px 12px;
  background: rgba(46, 125, 50, 0.08);
  border-left: 3px solid rgb(var(--v-theme-primary));
  border-radius: 6px;
}

.tabular-nums { font-variant-numeric: tabular-nums; }
.letter-spacing-1 { letter-spacing: 1.2px; }
.min-w-0 { min-width: 0; }
.flex-grow-1 { flex-grow: 1; }
</style>
