<template>
  <v-dialog
    v-model="open"
    persistent
    no-click-animation
    max-width="720"
    scrollable
    transition="scale-transition"
  >
    <v-card class="onboarding-card">
      <!-- Accent header -->
      <div class="onboarding-hero pa-6">
        <div class="d-flex align-center ga-3 mb-2">
          <v-avatar color="primary" size="44" class="onboarding-pulse">
            <v-icon color="white" size="24">mdi-clipboard-text-outline</v-icon>
          </v-avatar>
          <div>
            <div class="text-h6 font-weight-bold">Добро пожаловать в DS Consulting</div>
            <div class="text-body-2 text-medium-emphasis">
              Заполните короткую анкету — это нужно, чтобы подобрать вам подходящий путь развития
            </div>
          </div>
        </div>
        <div class="d-flex align-center ga-2 mt-3">
          <v-progress-linear :model-value="progress" height="6" rounded color="primary" />
          <span class="text-caption text-medium-emphasis" style="min-width:70px; text-align:right">
            {{ filledCount }} / {{ requiredCount }}
          </span>
        </div>
      </div>

      <v-divider />

      <v-card-text class="pa-6" style="max-height: 65vh">
        <!-- Auto-filled identity summary -->
        <v-alert
          type="info"
          variant="tonal"
          density="compact"
          class="mb-5"
          icon="mdi-account-check"
        >
          <div class="text-body-2">
            <strong>{{ identityName || '—' }}</strong>
            <span v-if="identityCity"> · {{ identityCity }}</span>
          </div>
          <div class="text-caption text-medium-emphasis">
            ФИО и город подставлены из вашего профиля.
          </div>
        </v-alert>

        <!-- Q3 -->
        <div class="mb-5">
          <label class="q-label">
            <span class="q-num">3.</span>
            В какой сфере вы сейчас работаете или работали?
          </label>
          <v-text-field
            v-model="form.workField"
            placeholder="Например: IT, банкинг, строительство"
            variant="outlined"
            density="compact"
            hide-details
          />
        </div>

        <!-- Q4 -->
        <div class="mb-5">
          <label class="q-label">
            <span class="q-num">4.</span>
            Есть ли у вас опыт в продажах? <span class="req">*</span>
          </label>
          <v-btn-toggle
            v-model="form.salesExperience"
            mandatory
            density="comfortable"
            color="primary"
            variant="outlined"
            class="d-flex flex-wrap"
          >
            <v-btn value="none" class="flex-grow-1">Нет</v-btn>
            <v-btn value="<1" class="flex-grow-1">До 1 года</v-btn>
            <v-btn value="1-3" class="flex-grow-1">1–3 года</v-btn>
            <v-btn value="3+" class="flex-grow-1">Более 3 лет</v-btn>
          </v-btn-toggle>
        </div>

        <!-- Q5 -->
        <div class="mb-5">
          <label class="q-label">
            <span class="q-num">5.</span>
            Есть ли опыт в финансах / инвестициях / страховании?
          </label>
          <v-textarea
            v-model="form.financeExperience"
            placeholder="Кратко опишите: продукты, компании, годы"
            variant="outlined"
            density="compact"
            rows="2"
            auto-grow
            hide-details
          />
        </div>

        <!-- Q6 -->
        <div class="mb-5">
          <label class="q-label">
            <span class="q-num">6.</span>
            Есть ли у вас потенциальные клиенты в окружении? <span class="req">*</span>
          </label>
          <v-btn-toggle
            v-model="form.hasPotentialClients"
            mandatory
            density="comfortable"
            color="primary"
            variant="outlined"
            class="d-flex flex-wrap"
          >
            <v-btn value="yes" class="flex-grow-1">Да</v-btn>
            <v-btn value="partly" class="flex-grow-1">Частично</v-btn>
            <v-btn value="no" class="flex-grow-1">Нет</v-btn>
          </v-btn-toggle>
        </div>

        <!-- Q7 (only if Q6 != no) -->
        <v-expand-transition>
          <div v-if="form.hasPotentialClients && form.hasPotentialClients !== 'no'" class="mb-5">
            <label class="q-label">
              <span class="q-num">7.</span>
              Сколько таких людей?
            </label>
            <v-btn-toggle
              v-model="form.potentialClientsCount"
              density="comfortable"
              color="primary"
              variant="outlined"
              class="d-flex flex-wrap"
            >
              <v-btn value="<10" class="flex-grow-1">До 10</v-btn>
              <v-btn value="10-30" class="flex-grow-1">10–30</v-btn>
              <v-btn value="30-100" class="flex-grow-1">30–100</v-btn>
              <v-btn value="100+" class="flex-grow-1">100+</v-btn>
            </v-btn-toggle>
          </div>
        </v-expand-transition>

        <!-- Q8 -->
        <div class="mb-5">
          <label class="q-label">
            <span class="q-num">8.</span>
            Ваш текущий доход в месяц?
          </label>
          <v-text-field
            v-model="form.currentIncome"
            placeholder="Например: 150 000 ₽ или диапазон"
            variant="outlined"
            density="compact"
            hide-details
          />
        </div>

        <!-- Q9 -->
        <div class="mb-5">
          <label class="q-label">
            <span class="q-num">9.</span>
            Сколько часов в неделю готовы уделять работе финансовым консультантом? <span class="req">*</span>
          </label>
          <v-btn-toggle
            v-model="form.weeklyHours"
            mandatory
            density="comfortable"
            color="primary"
            variant="outlined"
            class="d-flex flex-wrap"
          >
            <v-btn value="<10" class="flex-grow-1">До 10</v-btn>
            <v-btn value="10-20" class="flex-grow-1">10–20</v-btn>
            <v-btn value="20-40" class="flex-grow-1">20–40</v-btn>
            <v-btn value="full-time" class="flex-grow-1">Full-time</v-btn>
          </v-btn-toggle>
        </div>

        <!-- Q10 -->
        <div class="mb-2">
          <label class="q-label">
            <span class="q-num">10.</span>
            Как вы считаете, от чего зависит доход в этой сфере?
          </label>
          <v-textarea
            v-model="form.incomeFactors"
            placeholder="Ваше мнение в нескольких строках"
            variant="outlined"
            density="compact"
            rows="3"
            auto-grow
            hide-details
          />
        </div>

        <v-alert
          v-if="errorMessage"
          type="error"
          density="compact"
          variant="tonal"
          class="mt-3"
        >
          {{ errorMessage }}
        </v-alert>
      </v-card-text>

      <v-divider />

      <v-card-actions class="pa-4">
        <div class="text-caption text-medium-emphasis">
          <v-icon size="14" class="mr-1">mdi-lock</v-icon>
          Без заполнения анкеты остальные разделы заблокированы
        </div>
        <v-spacer />
        <v-btn
          color="primary"
          size="large"
          variant="flat"
          :loading="saving"
          :disabled="!canSubmit"
          prepend-icon="mdi-check"
          @click="submit"
        >
          Сохранить и продолжить
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import api from '../api';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  identityName: { type: String, default: '' },
  identityCity: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue', 'completed']);

const open = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
});

const saving = ref(false);
const errorMessage = ref('');

const form = ref({
  workField: '',
  salesExperience: null,
  financeExperience: '',
  hasPotentialClients: null,
  potentialClientsCount: null,
  currentIncome: '',
  weeklyHours: null,
  incomeFactors: '',
});

// Reset Q7 when Q6 switches to 'no'
watch(() => form.value.hasPotentialClients, (v) => {
  if (v === 'no') form.value.potentialClientsCount = null;
});

// Required for submission: Q4, Q6, Q9
const requiredCount = 3;
const filledCount = computed(() => {
  let n = 0;
  if (form.value.salesExperience) n++;
  if (form.value.hasPotentialClients) n++;
  if (form.value.weeklyHours) n++;
  return n;
});
const progress = computed(() => (filledCount.value / requiredCount) * 100);
const canSubmit = computed(() => filledCount.value === requiredCount);

async function submit() {
  if (!canSubmit.value) return;
  saving.value = true;
  errorMessage.value = '';
  try {
    await api.post('/profile/questionnaire', form.value);
    emit('completed');
    open.value = false;
  } catch (e) {
    errorMessage.value = e.response?.data?.message || 'Не удалось сохранить. Попробуйте снова.';
  }
  saving.value = false;
}
</script>

<style scoped>
.onboarding-card {
  border-radius: 20px;
  overflow: hidden;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
}

.onboarding-hero {
  background: linear-gradient(135deg,
    rgba(var(--v-theme-primary), 0.12) 0%,
    rgba(var(--v-theme-primary), 0.02) 100%);
}

.onboarding-pulse {
  animation: onboardingPulse 2.4s ease-in-out infinite;
  box-shadow: 0 0 0 0 rgba(var(--v-theme-primary), 0.6);
}

@keyframes onboardingPulse {
  0%   { box-shadow: 0 0 0 0   rgba(var(--v-theme-primary), 0.55); }
  70%  { box-shadow: 0 0 0 14px rgba(var(--v-theme-primary), 0); }
  100% { box-shadow: 0 0 0 0   rgba(var(--v-theme-primary), 0); }
}

.q-label {
  display: block;
  font-size: 14px;
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
  margin-bottom: 8px;
}

.q-num {
  display: inline-block;
  color: rgb(var(--v-theme-primary));
  font-weight: 800;
  margin-right: 6px;
}

.req {
  color: rgb(var(--v-theme-error));
  margin-left: 2px;
}
</style>
