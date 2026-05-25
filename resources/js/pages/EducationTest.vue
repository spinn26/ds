<template>
  <div class="test-page">
    <div class="test-header">
      <v-btn variant="text" prepend-icon="mdi-arrow-left" :to="`/education/courses/${route.params.id}`">
        Назад
      </v-btn>
      <div class="text-subtitle-2 font-weight-bold ml-3">
        Тест: {{ courseTitle }}
      </div>
      <v-spacer />
      <span class="text-caption text-medium-emphasis">
        попыток: без ограничения · для допуска нужно 100%
      </span>
    </div>

    <div class="test-body">
      <div v-if="loading" class="d-flex justify-center pa-6">
        <v-progress-circular indeterminate color="primary" />
      </div>

      <!-- C — успех -->
      <div v-else-if="result?.passed" class="state-card success">
        <v-icon size="48" color="success">mdi-check-circle</v-icon>
        <div class="state-title text-success">Тест сдан!</div>
        <div class="state-body">
          Правильно: <b>{{ result.score }} из {{ result.total }}</b>.
          Доступ к продаже продукта <b>{{ courseTitle }}</b> открыт.
        </div>
        <div class="d-flex ga-2 mt-4">
          <v-btn color="primary" size="large" :to="`/education/courses/${route.params.id}`">
            К курсу
          </v-btn>
          <v-btn variant="outlined" :to="'/products'">
            К продукту
          </v-btn>
        </div>
      </div>

      <!-- B — провал -->
      <div v-else-if="result && !result.passed" class="state-card danger">
        <v-icon size="48" color="error">mdi-alert</v-icon>
        <div class="state-title text-error">Тест не пройден</div>
        <div class="state-body">
          Правильно: <b>{{ result.score }} из {{ result.total }}</b>.
          Для допуска нужно 100%. Попытки не ограничены — попробуйте ещё раз.
        </div>
        <div class="d-flex ga-2 mt-4">
          <v-btn color="primary" size="large" @click="retry">
            Пройти ещё раз
          </v-btn>
          <v-btn variant="outlined" :to="`/education/courses/${route.params.id}`">
            Вернуться к курсу
          </v-btn>
        </div>
      </div>

      <!-- A — идёт тест -->
      <div v-else-if="tests.length" class="test-running">
        <div class="d-flex align-center justify-space-between mb-2">
          <div class="text-subtitle-2 font-weight-bold">
            Вопрос {{ currentIdx + 1 }} из {{ tests.length }}
          </div>
          <div class="text-caption text-medium-emphasis tabular-nums">
            {{ currentIdx + 1 }} / {{ tests.length }}
          </div>
        </div>
        <v-progress-linear
          :model-value="progressPercent"
          color="primary" height="8" rounded
        />

        <v-card class="question-card mt-5 pa-6" elevation="0">
          <div class="text-caption text-uppercase text-primary font-weight-bold letter-spacing-1">
            Q{{ currentIdx + 1 }}
          </div>
          <div class="text-h6 font-weight-bold mt-2" style="line-height: 1.35">
            {{ currentQuestion.question }}
          </div>

          <div class="answers mt-5">
            <label
              v-for="(a, i) in currentQuestion.answers"
              :key="i"
              class="answer-row"
              :class="{ selected: answers[currentQuestion.id] === i }"
            >
              <input
                type="radio"
                :name="'q' + currentQuestion.id"
                :value="i"
                v-model="answers[currentQuestion.id]"
              />
              <span class="answer-label">{{ a }}</span>
            </label>
          </div>
        </v-card>

        <!-- Точки прогресса -->
        <div class="dots mt-5">
          <div
            v-for="(_, i) in tests"
            :key="i"
            class="dot"
            :class="{
              current: i === currentIdx,
              done: i < currentIdx || answers[tests[i].id] != null && i !== currentIdx,
            }"
            @click="currentIdx = i"
          >{{ i + 1 }}</div>
        </div>

        <div class="d-flex justify-space-between mt-5">
          <v-btn variant="outlined" :disabled="currentIdx === 0" @click="currentIdx--">
            <v-icon start>mdi-arrow-left</v-icon>
            Назад
          </v-btn>
          <v-btn
            v-if="currentIdx < tests.length - 1"
            color="primary"
            :disabled="answers[currentQuestion.id] == null"
            @click="currentIdx++"
          >
            Далее
            <v-icon end>mdi-arrow-right</v-icon>
          </v-btn>
          <v-btn
            v-else
            color="primary" size="large"
            :loading="submitting"
            :disabled="!allAnswered"
            @click="submit"
          >
            Отправить ответы
          </v-btn>
        </div>
      </div>

      <EmptyState
        v-else
        icon="mdi-clipboard-question-outline"
        title="Тестов в этом курсе пока нет"
        description="Сдавать нечего — материалы курса уже доступны"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import api from '../api';
import EmptyState from '../components/EmptyState.vue';

const route = useRoute();
const loading = ref(true);
const submitting = ref(false);
const tests = ref([]);
const courseTitle = ref('');
const answers = ref({});
const currentIdx = ref(0);
const result = ref(null);

const currentQuestion = computed(() => tests.value[currentIdx.value] || { answers: [] });
const progressPercent = computed(() =>
  tests.value.length ? Math.round(((currentIdx.value + 1) / tests.value.length) * 100) : 0
);
const allAnswered = computed(() =>
  tests.value.every(q => answers.value[q.id] != null)
);

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/education/courses/${route.params.id}`);
    courseTitle.value = data.course?.title || '';
    tests.value = data.tests || [];
  } catch {}
  loading.value = false;
}

async function submit() {
  submitting.value = true;
  try {
    const { data } = await api.post(`/education/courses/${route.params.id}/test`, {
      answers: answers.value,
    });
    result.value = data;
  } catch {} finally { submitting.value = false; }
}

function retry() {
  answers.value = {};
  currentIdx.value = 0;
  result.value = null;
}

onMounted(load);
</script>

<style scoped>
.test-page { min-height: calc(100vh - 64px); }
.test-header {
  height: 56px;
  background: rgb(var(--v-theme-surface));
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  display: flex;
  align-items: center;
  padding: 0 16px;
  gap: 4px;
}
.test-body {
  padding: 40px 24px;
  display: flex;
  justify-content: center;
}
.test-running { width: 100%; max-width: 640px; }

.question-card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  border-radius: 14px;
  background: rgb(var(--v-theme-surface));
}

.answers { display: flex; flex-direction: column; gap: 10px; }
.answer-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  border: 1.5px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease;
  user-select: none;
}
.answer-row:hover { border-color: rgba(46, 125, 50, 0.5); }
.answer-row.selected {
  border-color: rgb(var(--v-theme-primary));
  background: rgba(46, 125, 50, 0.08);
  font-weight: 600;
}
.answer-row input[type="radio"] { accent-color: rgb(var(--v-theme-primary)); }

.dots { display: flex; gap: 6px; justify-content: center; }
.dot {
  width: 28px; height: 28px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 600;
  background: rgba(var(--v-theme-on-surface), 0.06);
  color: rgba(var(--v-theme-on-surface), 0.55);
  cursor: pointer;
  border: 1.5px solid transparent;
  transition: all 0.15s ease;
}
.dot.done { background: rgba(46, 125, 50, 0.12); color: rgb(var(--v-theme-primary)); }
.dot.current {
  background: rgb(var(--v-theme-primary)); color: white;
  border-color: rgb(var(--v-theme-primary));
}

.state-card {
  width: 100%; max-width: 640px;
  padding: 32px 36px;
  border-radius: 14px;
  text-align: center;
  display: flex; flex-direction: column; align-items: center;
}
.state-card.success {
  background: rgba(46, 125, 50, 0.06);
  border: 1.5px solid rgba(46, 125, 50, 0.2);
}
.state-card.danger {
  background: rgba(198, 40, 40, 0.06);
  border: 1.5px solid rgba(198, 40, 40, 0.2);
}
.state-title { font-size: 22px; font-weight: 700; margin-top: 12px; }
.state-body { margin-top: 8px; font-size: 14px; color: rgba(var(--v-theme-on-surface), 0.75); line-height: 1.55; }

.tabular-nums { font-variant-numeric: tabular-nums; }
.letter-spacing-1 { letter-spacing: 1.2px; }
</style>
