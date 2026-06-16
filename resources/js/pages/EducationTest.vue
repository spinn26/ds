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

      <!-- C — успех (certificate-style) -->
      <div v-else-if="result?.passed" class="success-screen">
        <div class="success-check-wrap">
          <div class="success-check">
            <v-icon size="60" color="success">mdi-check</v-icon>
          </div>
          <!-- Confetti при заходе на экран -->
          <div class="confetti-burst">
            <span v-for="n in 18" :key="n" class="confetti-piece" :style="confettiStyle(n)" />
          </div>
        </div>
        <h1 class="success-title">
          {{ userFirstName ? `${userFirstName}, ` : '' }}тест по «{{ courseTitle }}» пройден
        </h1>
        <p class="success-sub">
          Доступ к продаже продукта открыт. Условия комиссии — в разделе «Продукты».
        </p>

        <v-btn color="primary" size="x-large" class="success-cta" prepend-icon="mdi-check-circle"
          :to="'/products'">
          Перейти к продукту «{{ courseTitle }}»
        </v-btn>

        <div class="success-next">
          <div class="success-next__title">Что дальше</div>
          <div class="success-next__item">
            <v-icon size="16" color="success" class="me-2">mdi-check</v-icon>
            продукт уже доступен в каталоге
          </div>
          <div class="success-next__item">
            <v-icon size="16" color="success" class="me-2">mdi-check</v-icon>
            материалы остаются в Обучении — можно вернуться
          </div>
          <div class="success-next__item">
            <v-icon size="16" color="success" class="me-2">mdi-check</v-icon>
            записи деловых игр по продукту — в Базе знаний
          </div>
        </div>

        <div class="success-actions">
          <v-btn variant="text" prepend-icon="mdi-arrow-left" :to="'/education'">в Обучение</v-btn>
        </div>
      </div>

      <!-- B — провал -->
      <div v-else-if="result && !result.passed" class="fail-screen">
        <div class="fail-card">
          <div class="fail-card__head">
            <div class="fail-card__icon">
              <v-icon size="24" color="error">mdi-alert</v-icon>
            </div>
            <div>
              <div class="fail-card__title">Тест не пройден</div>
              <div class="fail-card__body">
                Правильно: <b>{{ result.score }} из {{ result.total }}</b>.
                Для допуска нужно 100%. Попытки не ограничены — попробуйте ещё раз.
                <template v-if="result.wrongIndexes?.length">
                  Ошибки были в вопросах <b>{{ result.wrongIndexes.join(', ') }}</b> — стоит освежить материал.
                </template>
              </div>
            </div>
          </div>
        </div>

        <div class="tip-card">
          <v-icon size="22" color="warning" class="me-3">mdi-lightbulb-on-outline</v-icon>
          <div>
            <b>Рекомендуем вернуться через 15 минут</b> — статистика показывает +20%
            к шансу при перерыве. Но можно повторить и сейчас.
          </div>
        </div>

        <div class="d-flex align-center ga-3 mt-5">
          <v-btn color="primary" size="large" prepend-icon="mdi-restart" @click="retry">
            Пройти ещё раз
          </v-btn>
          <v-btn variant="text" :to="`/education/courses/${route.params.id}`">
            или к материалам урока
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
import { useAuthStore } from '../stores/auth';
import { useEducationStore } from '../stores/education';
import EmptyState from '../components/EmptyState.vue';

const route = useRoute();
const auth = useAuthStore();
const edu = useEducationStore();
const loading = ref(true);
const submitting = ref(false);
const tests = ref([]);
const courseTitle = ref('');
const answers = ref({});
const currentIdx = ref(0);
const result = ref(null);

const userFirstName = computed(() => auth.user?.firstName || '');

// Detereministic «псевдо-рандом» по index — стабильно при re-render'ах.
function confettiStyle(n) {
  const seed = (n * 9301 + 49297) % 233280;
  const r = seed / 233280;
  const angle = r * 360;
  const distance = 240 + ((seed * 7) % 280);
  const tx = Math.cos(angle * Math.PI / 180) * distance;
  const ty = Math.sin(angle * Math.PI / 180) * distance;
  const colors = ['#6EE87A', '#2E7D32', '#A4E0AC', '#1B5E20', '#43a047', '#FFC107'];
  const color = colors[n % colors.length];
  const delay = (n % 6) * 40;
  const rotate = (seed % 720) - 360;
  return {
    '--tx': `${tx}px`,
    '--ty': `${ty}px`,
    '--rot': `${rotate}deg`,
    '--delay': `${delay}ms`,
    background: color,
  };
}

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
    // Если бэк не вернул wrongIndexes — считаем сами по correct_answer.
    // На fail-state это даёт партнёру конкретику «ошибся в Q2, Q5».
    let wrongIndexes = data.wrongIndexes;
    if (!Array.isArray(wrongIndexes) && !data.passed) {
      wrongIndexes = [];
      tests.value.forEach((q, i) => {
        if (q.correct_answer != null && answers.value[q.id] !== q.correct_answer) {
          wrongIndexes.push(i + 1);
        }
      });
    }
    result.value = { ...data, wrongIndexes };
    // Мгновенно помечаем курс сданным в общем сторе — списки/карточки
    // обновятся без перезахода.
    if (data.passed) edu.markPassed(route.params.id);
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
  border: 1px solid var(--ds-outline-variant, rgba(var(--v-theme-on-surface), 0.08));
  border-radius: var(--ds-radius-lg, 12px);
  background: rgb(var(--v-theme-surface));
}

.answers { display: flex; flex-direction: column; gap: 10px; }
.answer-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  border: 1.5px solid var(--ds-outline, rgba(var(--v-theme-on-surface), 0.12));
  border-radius: var(--ds-radius-md, 8px);
  background: rgb(var(--v-theme-surface));
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease;
  user-select: none;
}
.answer-row:hover { border-color: rgba(var(--v-theme-primary), 0.5); }
.answer-row.selected {
  border-color: rgb(var(--v-theme-primary));
  background: var(--ds-primary-soft, rgba(46, 125, 50, 0.08));
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
.dot.done { background: var(--ds-primary-soft, rgba(46, 125, 50, 0.12)); color: rgb(var(--v-theme-primary)); }
.dot.current {
  background: rgb(var(--v-theme-primary)); color: rgb(var(--v-theme-on-primary));
  border-color: rgb(var(--v-theme-primary));
}

/* === Success screen (certificate-style) === */
.success-screen {
  width: 100%;
  max-width: 640px;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding-top: 24px;
}
.success-check-wrap {
  position: relative;
  margin-bottom: 24px;
}
.success-check {
  width: 96px; height: 96px;
  border-radius: 50%;
  background: rgb(var(--v-theme-surface));
  border: 4px solid rgba(var(--v-theme-success), 0.18);
  display: flex; align-items: center; justify-content: center;
  box-shadow:
    0 1px 2px rgba(0, 0, 0, 0.04),
    0 12px 32px rgba(46, 125, 50, 0.18);
  animation: checkPop 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes checkPop {
  0%   { transform: scale(0.3); opacity: 0; }
  60%  { transform: scale(1.1);  opacity: 1; }
  100% { transform: scale(1); }
}

.success-title {
  font-size: 28px;
  font-weight: 700;
  letter-spacing: -0.3px;
  line-height: 1.25;
  margin: 0 0 12px;
  animation: fadeUp 0.5s cubic-bezier(0.2, 0.8, 0.2, 1) backwards;
  animation-delay: 200ms;
}
.success-sub {
  margin: 0 0 24px;
  font-size: 15px;
  color: rgba(var(--v-theme-on-surface), 0.7);
  line-height: 1.55;
  max-width: 480px;
  animation: fadeUp 0.5s cubic-bezier(0.2, 0.8, 0.2, 1) backwards;
  animation-delay: 280ms;
}
.success-cta {
  letter-spacing: -0.2px;
  font-weight: 600;
  animation: fadeUp 0.5s cubic-bezier(0.2, 0.8, 0.2, 1) backwards;
  animation-delay: 360ms;
}

.success-next {
  margin: 32px 0 8px;
  padding: 18px 20px;
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  border-radius: 14px;
  text-align: left;
  width: 100%;
  max-width: 420px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04), 0 4px 12px rgba(0, 0, 0, 0.04);
  animation: fadeUp 0.5s cubic-bezier(0.2, 0.8, 0.2, 1) backwards;
  animation-delay: 440ms;
}
.success-next__title {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 1.4px;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), 0.55);
  margin-bottom: 10px;
}
.success-next__item {
  display: flex;
  align-items: center;
  font-size: 14px;
  line-height: 1.6;
  color: rgb(var(--v-theme-on-surface));
}
.success-actions {
  margin-top: 16px;
  animation: fadeUp 0.5s cubic-bezier(0.2, 0.8, 0.2, 1) backwards;
  animation-delay: 520ms;
}
@keyframes fadeUp {
  from { opacity: 0; transform: translateY(12px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* Confetti */
.confetti-burst {
  position: absolute;
  top: 50%; left: 50%;
  width: 0; height: 0;
  pointer-events: none;
}
.confetti-piece {
  position: absolute;
  width: 10px; height: 10px;
  border-radius: 2px;
  opacity: 0;
  animation: confetti-fly 1.4s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
  animation-delay: var(--delay, 0ms);
}
@keyframes confetti-fly {
  0%   { opacity: 1; transform: translate(0, 0) rotate(0deg) scale(0.8); }
  35%  { opacity: 1; transform: translate(calc(var(--tx) * 0.55), calc(var(--ty) * 0.55)) rotate(calc(var(--rot) * 0.5)) scale(1); }
  100% { opacity: 0; transform: translate(var(--tx), calc(var(--ty) + 120px)) rotate(var(--rot)) scale(0.5); }
}

/* === Fail screen === */
.fail-screen {
  width: 100%;
  max-width: 640px;
  padding-top: 24px;
}
.fail-card {
  background: rgba(var(--v-theme-error), 0.06);
  border: 1px solid rgba(var(--v-theme-error), 0.2);
  border-radius: 14px;
  padding: 18px 20px;
  animation: fadeUp 0.45s cubic-bezier(0.2, 0.8, 0.2, 1) backwards;
}
.fail-card__head { display: flex; align-items: flex-start; gap: 14px; }
.fail-card__icon {
  width: 36px; height: 36px;
  border-radius: 50%;
  background: rgba(var(--v-theme-error), 0.12);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  margin-top: 2px;
}
.fail-card__title {
  font-size: 18px;
  font-weight: 700;
  color: rgb(var(--v-theme-error));
  margin-bottom: 4px;
}
.fail-card__body {
  font-size: 14px;
  line-height: 1.55;
  color: rgba(var(--v-theme-on-surface), 0.85);
}

.tip-card {
  margin-top: 14px;
  background: rgba(255, 193, 7, 0.08);
  border: 1px solid rgba(255, 193, 7, 0.25);
  border-radius: 12px;
  padding: 14px 18px;
  display: flex;
  align-items: flex-start;
  font-size: 13.5px;
  line-height: 1.55;
  color: rgba(var(--v-theme-on-surface), 0.85);
  animation: fadeUp 0.45s cubic-bezier(0.2, 0.8, 0.2, 1) backwards;
  animation-delay: 100ms;
}

.tabular-nums { font-variant-numeric: tabular-nums; }
.letter-spacing-1 { letter-spacing: 1.2px; }
</style>
