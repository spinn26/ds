<template>
  <v-dialog :model-value="open" max-width="620" persistent scrollable>
    <v-card>
      <v-card-title class="d-flex align-center ga-2">
        <v-icon :color="titleColor">{{ titleIcon }}</v-icon>
        <span>{{ title }}</span>
      </v-card-title>

      <v-card-text style="max-height: 70vh">
        <!-- Шаг 2: наставник. Показывается сразу после успешного
             восстановления, ДО окна акцепта документов. -->
        <template v-if="step === 'mentor'">
          <v-alert type="success" density="compact" variant="tonal" class="mb-3">
            Участие восстановлено. Осталось подтвердить наставника — после этого
            нужно будет принять документы.
          </v-alert>

          <div class="text-body-2 mb-2">
            Сейчас вы закреплены за наставником:
            <strong>{{ currentInviter || 'наставник не назначен' }}</strong>.
          </div>

          <v-radio-group v-model="mentorChoice" density="compact" hide-details="auto" class="mb-2">
            <v-radio value="keep" label="Остаться с этим наставником" />
            <v-radio value="change" label="Выбрать нового наставника" />
          </v-radio-group>

          <v-text-field v-if="mentorChoice === 'change'" v-model="refCode"
            label="Реферальный код наставника" placeholder="например, gcpc=4bd2a"
            variant="outlined" density="comfortable" hide-details="auto"
            prepend-inner-icon="mdi-tag-outline" @keyup.enter="submitMentor" />

          <v-alert v-if="error" type="error" density="compact" class="mt-2">
            {{ error }}
          </v-alert>
        </template>

        <!-- Исключён: возврата через кабинет нет, только поддержка. Текст
             называет причину прямо — попытки были и закончились, иначе
             исчезнувшая кнопка «Восстановить участие» выглядит произволом. -->
        <template v-else-if="excluded">
          <p class="text-body-2 mb-3">
            Все {{ info.limit || 3 }}
            {{ plural(info.limit || 3, 'попытка', 'попытки', 'попыток') }}
            восстановления использованы, поэтому аккаунт переведён в статус
            «Исключён». Вернуть участие через личный кабинет больше нельзя.
          </p>
          <v-alert type="info" density="compact" variant="tonal">
            {{ info.blockedReason
              || 'Если считаете, что произошла ошибка, напишите в поддержку — решение принимается индивидуально.' }}
          </v-alert>
        </template>

        <!-- Терминирован и попытки есть. -->
        <template v-else>
          <p class="text-body-2 text-error font-weight-medium mb-3">
            Ваш партнёрский аккаунт переведён в статус «Терминирован»: агентский
            договор расторгнут, условия активационного периода не выполнены.
          </p>
          <p class="text-body-2 mb-3">
            Это не окончательное решение. Восстановить участие можно
            самостоятельно, прямо здесь — всего доступно
            {{ info.limit || 3 }}
            {{ plural(info.limit || 3, 'попытка', 'попытки', 'попыток') }}.
          </p>

          <v-alert type="warning" density="compact" variant="tonal" class="mb-3">
            <div class="text-body-2">Что произойдёт при восстановлении:</div>
            <ul class="mt-1 ps-4 text-body-2">
              <li>статус сменится на «Зарегистрирован»;</li>
              <li>баллы (ЛП и ГП) обнулятся;</li>
              <li>на набор {{ requiredPoints }} ЛП снова даётся {{ windowDays }} дней;</li>
              <li>
                контракты и клиенты, перешедшие к наставнику при терминации,
                <strong>остаются за ним</strong> и не возвращаются.
              </li>
            </ul>
          </v-alert>

          <div class="text-body-2 mb-2">
            Доступно восстановлений:
            <strong>{{ info.attemptsLeft }}</strong> из {{ info.limit }}.
            <template v-if="info.attemptsLeft <= 1">
              Это последняя попытка — следующая терминация приведёт к исключению.
            </template>
          </div>

          <v-checkbox v-model="confirmed" density="compact" hide-details="auto"
            label="Я понимаю условия и хочу восстановить участие" />

          <v-alert v-if="error" type="error" density="compact" class="mt-2">
            {{ error }}
          </v-alert>
        </template>
      </v-card-text>

      <v-card-actions class="pa-3">
        <v-btn variant="text" @click="emit('logout')">Выйти</v-btn>
        <v-spacer />
        <v-btn v-if="step === 'mentor'" color="primary" :loading="submitting"
          :disabled="mentorChoice === 'change' && !refCode.trim()"
          prepend-icon="mdi-check" @click="submitMentor">
          {{ mentorChoice === 'change' ? 'Сменить наставника' : 'Остаться' }}
        </v-btn>
        <v-btn v-else-if="!excluded" color="primary" :disabled="!confirmed || !info.canReinstate"
          :loading="submitting" prepend-icon="mdi-restart" @click="submit">
          Восстановить участие
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import api from '../api';

const props = defineProps({
  open: { type: Boolean, default: false },
  // Блок termination из /auth/me: {terminated, excluded, canReinstate, attemptsLeft, limit, blockedReason}
  info: { type: Object, default: () => ({}) },
  // Пороги показываем из настроек статусов (statusInfo), с дефолтами устава.
  requiredPoints: { type: [Number, String], default: 500 },
  windowDays: { type: [Number, String], default: 120 },
});
const emit = defineEmits(['reinstated', 'logout']);

const confirmed = ref(false);
const submitting = ref(false);
const error = ref('');

function plural(n, one, few, many) {
  if (n % 10 === 1 && n % 100 !== 11) return one;
  if ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100)) return few;
  return many;
}

// Шаги окна: сначала само восстановление, потом наставник. Акцепт документов
// идёт третьим и живёт в отдельном окне — оно ждёт, пока это закроется.
const step = ref('reinstate');
const mentorChoice = ref('keep');
const refCode = ref('');
const currentInviter = ref('');

// Партнёр уже восстановился, но про наставника не ответил (закрыл вкладку,
// перезашёл) — открываем окно сразу на этом шаге.
watch(() => props.info, (info) => {
  if (info?.mentorPending) {
    step.value = 'mentor';
    if (! currentInviter.value) currentInviter.value = info.inviterName || '';
  }
}, { immediate: true, deep: true });

// Исключённому кнопку не показываем: этот статус снимает только поддержка.
const excluded = computed(() => props.info?.excluded === true);

const title = computed(() => {
  if (step.value === 'mentor') return 'Выбор наставника';
  return excluded.value ? 'Статус: Исключён' : 'Участие приостановлено';
});
const titleIcon = computed(() => {
  if (step.value === 'mentor') return 'mdi-account-supervisor';
  return excluded.value ? 'mdi-account-cancel' : 'mdi-account-clock';
});
const titleColor = computed(() => {
  if (step.value === 'mentor') return 'primary';
  return excluded.value ? 'error' : 'warning';
});

async function submit() {
  error.value = '';
  submitting.value = true;
  try {
    const { data } = await api.post('/profile/reinstate');
    currentInviter.value = data?.inviterName || '';
    // Статус уже восстановлен — окно не закрываем, переводим на шаг
    // «наставник», иначе следом сразу выскочит акцепт документов.
    step.value = 'mentor';
  } catch (e) {
    error.value = e.response?.data?.message || 'Не удалось восстановить участие. Попробуйте позже.';
  } finally {
    submitting.value = false;
  }
}

async function submitMentor() {
  error.value = '';
  submitting.value = true;
  try {
    await api.post('/profile/reinstate/mentor', {
      action: mentorChoice.value,
      refCode: mentorChoice.value === 'change' ? refCode.value.trim() : null,
    });
    // Только теперь отпускаем флоу: родитель перечитает профиль, окно
    // закроется, и покажется акцепт документов.
    emit('reinstated');
  } catch (e) {
    error.value = e.response?.data?.message || 'Не удалось сохранить наставника. Попробуйте позже.';
  } finally {
    submitting.value = false;
  }
}
</script>
