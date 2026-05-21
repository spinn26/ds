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
            <div v-if="l.content" class="text-body-2 mb-3" style="white-space: pre-wrap">{{ l.content }}</div>
            <!-- Видео: массив объектов {url, label} с fallback на legacy. -->
            <div v-if="lessonVideos(l).length" class="d-flex flex-wrap ga-2 mb-3">
              <v-btn v-for="(item, vi) in lessonVideos(l)" :key="'v' + vi"
                :href="item.url" target="_blank" rel="noopener"
                color="primary" variant="tonal" size="small" prepend-icon="mdi-play">
                {{ item.label || (lessonVideos(l).length > 1 ? `Смотреть видео ${vi + 1}` : 'Смотреть видео') }}
              </v-btn>
            </div>
            <!-- Документы / ссылки: массив объектов {url, label} с fallback. -->
            <div v-if="lessonDocs(l).length" class="d-flex flex-wrap ga-2 mb-3">
              <v-btn v-for="(item, di) in lessonDocs(l)" :key="'d' + di"
                :href="item.url" target="_blank" rel="noopener"
                variant="tonal" size="small" prepend-icon="mdi-file-document">
                {{ item.label || (lessonDocs(l).length > 1 ? `Открыть ${di + 1}` : 'Открыть') }}
              </v-btn>
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

      <v-alert
        v-if="!allLessonsViewed"
        type="info"
        density="compact"
        class="mb-3"
      >
        Просмотрите все уроки, чтобы открыть тест.
      </v-alert>

      <div v-else-if="course.completion" class="mb-3">
        <v-alert type="success" density="compact">
          Тест сдан: {{ course.completion.score }} / {{ course.completion.total }}
        </v-alert>
      </div>

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
