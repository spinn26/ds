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
          <v-btn
            v-if="!lesson.viewed"
            color="primary" size="large"
            :loading="marking"
            prepend-icon="mdi-check"
            @click="markViewed"
          >
            Урок изучен
          </v-btn>
          <v-chip v-else size="default" color="success" variant="tonal" prepend-icon="mdi-check-circle">
            Изучено
          </v-chip>
        </div>

        <div class="body-blocks">
          <!-- Описание из legacy content -->
          <div v-if="lesson.description" class="block block-text">
            {{ lesson.description }}
          </div>

          <!-- Новый формат body[] -->
          <template v-if="blocks.length">
            <component
              v-for="(b, i) in blocks"
              :key="i"
              :is="blockComponentFor(b.type)"
              v-bind="b"
            />
          </template>

          <!-- Legacy video_urls / document_urls (если body пуст) -->
          <template v-else>
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
import { ref, computed, onMounted, watch, h } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../api';
import CourseTreeNode from '../components/education/CourseTreeNode.vue';

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

// Маппинг blocks → компонент-рендер (минималистично через h()).
function blockComponentFor(type) {
  const map = {
    text: { props: ['value'], render: (p) => h('div', { class: 'block block-text' }, p.value) },
    image: { props: ['value', 'label'], render: (p) => h('figure', { class: 'block' }, [
      h('img', { src: p.value, class: 'block-image' }),
      p.label ? h('figcaption', { class: 'block-caption mt-1' }, p.label) : null,
    ]) },
    file: { props: ['value', 'label'], render: (p) => h('a', {
      href: p.value, target: '_blank', class: 'block-file',
    }, [
      h('span', { class: 'mdi mdi-file-document mr-2' }),
      h('span', null, p.label || 'Открыть файл'),
    ]) },
    link: { props: ['value', 'label'], render: (p) => h('a', {
      href: p.value, target: '_blank', class: 'block-link',
    }, p.label || p.value) },
    video: { props: ['value', 'label'], render: (p) => {
      const e = toEmbed(p.value);
      return h('div', { class: 'block' }, [
        p.label ? h('div', { class: 'block-caption mb-2' }, p.label) : null,
        e ? h('div', { class: 'video-frame' }, h('iframe', {
          src: e, frameborder: 0, allowfullscreen: true,
        })) : h('a', { href: p.value, target: '_blank' }, 'Открыть видео'),
      ]);
    } },
  };
  return map[type] || map.text;
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
  grid-template-columns: 240px 1fr;
  min-height: calc(100vh - 110px);
}
@media (max-width: 960px) {
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
  padding: 20px 36px 14px;
  display: flex;
  align-items: flex-start;
  gap: 16px;
}

.body-blocks {
  padding: 24px 36px 40px;
  max-width: 800px;
  display: flex;
  flex-direction: column;
  gap: 22px;
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

.tabular-nums { font-variant-numeric: tabular-nums; }
.letter-spacing-1 { letter-spacing: 1.2px; }
.min-w-0 { min-width: 0; }
.flex-grow-1 { flex-grow: 1; }
</style>
