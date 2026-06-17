<template>
  <div>
    <div v-if="course.course.description" class="text-body-2 text-medium-emphasis mb-4">
      {{ course.course.description }}
    </div>

    <!-- Lessons -->
    <div v-if="course.lessons.length" class="mb-4">
      <div class="text-subtitle-2 font-weight-bold mb-2">Уроки</div>
      <v-expansion-panels variant="accordion" multiple>
        <v-expansion-panel v-for="(l, i) in course.lessons" :key="l.id">
          <v-expansion-panel-title>
            <div class="d-flex align-center ga-2 flex-grow-1">
              <v-icon :color="l.viewed ? 'success' : 'grey'" size="small">
                {{ l.viewed ? 'mdi-check-circle' : 'mdi-circle-outline' }}
              </v-icon>
              <div class="text-body-2">{{ i + 1 }}. {{ l.title }}</div>
            </div>
          </v-expansion-panel-title>
          <v-expansion-panel-text>
            <!-- 1. Видео-плеер (основной фрейм, per spec ✅Обучение §2.2.1).
                 Если есть несколько видео — табы для переключения; первый
                 ролик активен по умолчанию. Поддерживаем Rutube / YouTube /
                 Vimeo через embed-iframe. Прямые ссылки (mp4 / неизвестный
                 хостинг) — отдаём кнопкой «Открыть видео» как fallback. -->
            <div v-if="lessonVideos(l).length" class="mb-4">
              <div v-if="lessonVideos(l).length > 1" class="d-flex flex-wrap ga-2 mb-2">
                <v-btn v-for="(item, vi) in lessonVideos(l)" :key="'tab' + vi"
                  :variant="(activeVideo[l.id] ?? 0) === vi ? 'flat' : 'tonal'"
                  :color="(activeVideo[l.id] ?? 0) === vi ? 'primary' : undefined"
                  size="small" prepend-icon="mdi-play"
                  @click="activeVideo[l.id] = vi">
                  {{ item.label || `Видео ${vi + 1}` }}
                </v-btn>
              </div>
              <template v-for="(item, vi) in lessonVideos(l)" :key="'v' + vi">
                <div v-if="(activeVideo[l.id] ?? 0) === vi">
                  <div v-if="toEmbedUrl(item.url)" class="video-frame">
                    <iframe :src="toEmbedUrl(item.url)"
                      frameborder="0"
                      allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                      allowfullscreen></iframe>
                  </div>
                  <v-btn v-else :href="item.url" target="_blank" rel="noopener"
                    color="primary" variant="tonal" prepend-icon="mdi-play">
                    Открыть видео в новой вкладке
                  </v-btn>
                </div>
              </template>
            </div>

            <!-- 2. Содержание (текстовый блок, per spec §2.2.3). -->
            <div v-if="l.content" class="lesson-content mb-4">
              <div class="text-subtitle-2 font-weight-bold mb-1">Содержание</div>
              <div class="text-body-2" style="white-space: pre-wrap">{{ l.content }}</div>
            </div>

            <!-- 3. Кнопки / ссылки (вложения, per spec §2.2.4). -->
            <div v-if="lessonDocs(l).length" class="mb-4">
              <div class="text-subtitle-2 font-weight-bold mb-2">Материалы и ссылки</div>
              <div class="d-flex flex-wrap ga-2">
                <v-btn v-for="(item, di) in lessonDocs(l)" :key="'d' + di"
                  :href="item.url" target="_blank" rel="noopener"
                  color="primary" variant="tonal" size="small"
                  :prepend-icon="docIcon(item.url)">
                  {{ item.label || (lessonDocs(l).length > 1 ? `Открыть ${di + 1}` : 'Открыть') }}
                </v-btn>
              </div>
            </div>

            <v-btn
              v-if="!l.viewed"
              size="small"
              color="primary"
              :loading="marking === l.id"
              prepend-icon="mdi-check"
              @click="markViewed(l.id)"
            >
              Отметить как изученный
            </v-btn>
            <v-chip v-else size="small" color="success" variant="tonal" prepend-icon="mdi-check">Изучено</v-chip>
          </v-expansion-panel-text>
        </v-expansion-panel>
      </v-expansion-panels>
    </div>

    <!-- Test -->
    <div v-if="course.tests.length">
      <v-divider class="my-3" />
      <div class="text-subtitle-2 font-weight-bold mb-2">Тест</div>

      <!-- Тест сдан — показываем ВСЕГДА при наличии completion, даже если не
           все уроки просмотрены (единый критерий «пройден = тест сдан»). -->
      <div v-if="course.completion" class="mb-3">
        <v-alert type="success" density="compact">
          Тест сдан: {{ course.completion.score }} / {{ course.completion.total }}
          <template v-if="course.completion.total">
            ({{ Math.round(course.completion.score / course.completion.total * 100) }}%)
          </template>
        </v-alert>
      </div>

      <v-alert
        v-else-if="!allLessonsViewed"
        type="info"
        density="compact"
        class="mb-3"
      >
        Просмотрите все уроки, чтобы открыть тест.
      </v-alert>

      <template v-else>
        <v-alert v-if="testResult && !testResult.passed" type="error" density="compact" class="mb-3">
          Правильных ответов: {{ testResult.score }} / {{ testResult.total }}. Нужно ответить на все вопросы верно.
        </v-alert>

        <v-card v-for="(q, i) in course.tests" :key="q.id" variant="outlined" class="mb-2 pa-3">
          <div class="text-body-2 font-weight-medium mb-2">{{ i + 1 }}. {{ q.question }}</div>
          <v-radio-group v-model="answers[q.id]" density="compact" hide-details>
            <v-radio
              v-for="(a, idx) in q.answers"
              :key="idx"
              :label="a"
              :value="idx"
            />
          </v-radio-group>
        </v-card>

        <v-btn
          color="primary"
          class="mt-2"
          :loading="submitting"
          :disabled="!allAnswered"
          @click="submitTest"
        >
          Отправить ответы
        </v-btn>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import api from '../../api';

const props = defineProps({
  course: { type: Object, required: true },
});
const emit = defineEmits(['lesson-viewed', 'test-submitted']);

const marking = ref(null);
const submitting = ref(false);
const answers = ref({});
const testResult = ref(null);
// activeVideo[lessonId] = индекс активного видео в табах. Дефолт 0.
const activeVideo = ref({});

/**
 * Конвертирует публичный URL видеохостинга в embed-форму для iframe.
 * Поддержка: Rutube, YouTube, Vimeo, VK Video. Если URL не опознан —
 * возвращает null, и вызывающий код показывает fallback-кнопку.
 */
function toEmbedUrl(url) {
  if (!url) return null;
  try {
    const u = new URL(url);
    const host = u.hostname.replace(/^www\./, '');

    // Rutube. Форматы:
    //   https://rutube.ru/video/<hash>/
    //   https://rutube.ru/play/embed/<hash>
    //   https://rutube.ru/video/private/<hash>?p=<key>
    if (host === 'rutube.ru') {
      if (u.pathname.startsWith('/play/embed/')) return url;
      const m = u.pathname.match(/\/video\/(?:private\/)?([a-f0-9]+)\/?/i);
      if (m) {
        const p = u.searchParams.get('p');
        return `https://rutube.ru/play/embed/${m[1]}` + (p ? `?p=${encodeURIComponent(p)}` : '');
      }
    }

    // YouTube
    if (host === 'youtube.com' || host === 'm.youtube.com') {
      const v = u.searchParams.get('v');
      if (v) return `https://www.youtube.com/embed/${v}`;
      const m = u.pathname.match(/^\/embed\/([\w-]+)/);
      if (m) return url;
    }
    if (host === 'youtu.be') {
      const id = u.pathname.replace(/^\//, '').split('/')[0];
      if (id) return `https://www.youtube.com/embed/${id}`;
    }

    // Vimeo
    if (host === 'vimeo.com') {
      const id = u.pathname.replace(/^\//, '').split('/')[0];
      if (/^\d+$/.test(id)) return `https://player.vimeo.com/video/${id}`;
    }
    if (host === 'player.vimeo.com') return url;

    // VK Video — пример: https://vk.com/video123_456 или https://vkvideo.ru/video123_456
    if (host === 'vk.com' || host === 'vkvideo.ru') {
      const m = u.pathname.match(/\/video(-?\d+)_(\d+)/);
      if (m) return `https://vk.com/video_ext.php?oid=${m[1]}&id=${m[2]}&hd=2`;
    }
  } catch {}
  return null;
}

/** Иконка для кнопки-вложения по расширению/типу URL. */
function docIcon(url) {
  if (!url) return 'mdi-link';
  const u = url.toLowerCase();
  if (u.endsWith('.pdf')) return 'mdi-file-pdf-box';
  if (u.match(/\.(docx?|odt|rtf)(\?|$)/)) return 'mdi-file-word-box';
  if (u.match(/\.(xlsx?|csv|ods)(\?|$)/)) return 'mdi-file-excel-box';
  if (u.match(/\.(pptx?|key)(\?|$)/)) return 'mdi-file-powerpoint-box';
  if (u.match(/\.(zip|rar|7z|tar|gz)(\?|$)/)) return 'mdi-folder-zip';
  if (u.match(/\.(png|jpg|jpeg|gif|webp|svg)(\?|$)/)) return 'mdi-image';
  return 'mdi-link';
}

// Нормализуем элементы урока к {url, label}. Бэк сейчас отдаёт массив
// объектов; на всякий случай умеем массив строк (старый формат) и
// одиночный legacy video_url/document_url.
function normalize(arr, legacySingle) {
  if (Array.isArray(arr) && arr.length) {
    return arr.map(item => typeof item === 'string'
      ? { url: item, label: null }
      : { url: item?.url ?? '', label: item?.label ?? null })
      .filter(i => i.url);
  }
  return legacySingle ? [{ url: legacySingle, label: null }] : [];
}
function lessonVideos(l) { return normalize(l.video_urls, l.video_url); }
function lessonDocs(l)   { return normalize(l.document_urls, l.document_url); }

const allLessonsViewed = computed(() =>
  props.course.lessons.length > 0 && props.course.lessons.every(l => l.viewed)
);

const allAnswered = computed(() =>
  props.course.tests.every(q => answers.value[q.id] !== undefined && answers.value[q.id] !== null)
);

async function markViewed(lessonId) {
  marking.value = lessonId;
  try {
    await api.post(`/education/lessons/${lessonId}/view`);
    emit('lesson-viewed', lessonId);
  } finally {
    marking.value = null;
  }
}

async function submitTest() {
  submitting.value = true;
  testResult.value = null;
  try {
    const { data } = await api.post(`/education/courses/${props.course.course.id}/test`, {
      answers: answers.value,
    });
    testResult.value = data;
    emit('test-submitted', data);
    if (data.passed) {
      props.course.completion = {
        score: data.score,
        total: data.total,
        completed_at: new Date().toISOString(),
      };
    }
  } finally {
    submitting.value = false;
  }
}
</script>

<style scoped>
/* Responsive 16:9 wrapper для iframe видео-плеера. Aspect-ratio
   корректно сжимается на мобильных. */
.video-frame {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 9;
  background: rgba(var(--v-theme-on-surface), 0.04);
  border-radius: 8px;
  overflow: hidden;
}
.video-frame iframe {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  border: 0;
}
.lesson-content {
  background: rgba(var(--v-theme-on-surface), 0.04);
  border-radius: 8px;
  padding: 12px 14px;
}
</style>
