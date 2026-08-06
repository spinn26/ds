<template>
  <v-dialog :model-value="open" max-width="620" persistent scrollable>
    <v-card>
      <v-card-title class="d-flex align-center ga-2">
        <v-icon :color="excluded ? 'error' : 'warning'">
          {{ excluded ? 'mdi-account-cancel' : 'mdi-account-clock' }}
        </v-icon>
        <span>{{ excluded ? 'Статус: Исключён' : 'Участие приостановлено' }}</span>
      </v-card-title>

      <v-card-text style="max-height: 70vh">
        <!-- Исключён: возврата через кабинет нет, только поддержка. -->
        <template v-if="excluded">
          <p class="text-body-2 mb-3">
            Ваш партнёрский аккаунт переведён в статус «Исключён». Восстановление
            через личный кабинет недоступно.
          </p>
          <v-alert type="info" density="compact" variant="tonal">
            {{ info.blockedReason || 'Для разбора ситуации обратитесь в поддержку.' }}
          </v-alert>
        </template>

        <!-- Терминирован и попытки есть. -->
        <template v-else>
          <p class="text-body-2 mb-3">
            Вы не выполнили условие активации, поэтому участие приостановлено
            (статус «Терминирован»). Вы можете вернуться в работу прямо сейчас.
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
        <v-btn v-if="!excluded" color="primary" :disabled="!confirmed || !info.canReinstate"
          :loading="submitting" prepend-icon="mdi-restart" @click="submit">
          Восстановить участие
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { computed, ref } from 'vue';
import api from '../api';

const props = defineProps({
  open: { type: Boolean, default: false },
  // Блок termination из /auth/me: {terminated, excluded, canReinstate, attemptsLeft, limit, blockedReason}
  info: { type: Object, default: () => ({}) },
  // Пороги показываем из настроек статусов (statusInfo), с дефолтами устава.
  requiredPoints: { type: [Number, String], default: 500 },
  windowDays: { type: [Number, String], default: 90 },
});
const emit = defineEmits(['reinstated', 'logout']);

const confirmed = ref(false);
const submitting = ref(false);
const error = ref('');

// Исключённому кнопку не показываем: этот статус снимает только поддержка.
const excluded = computed(() => props.info?.excluded === true);

async function submit() {
  error.value = '';
  submitting.value = true;
  try {
    await api.post('/profile/reinstate');
    emit('reinstated');
  } catch (e) {
    error.value = e.response?.data?.message || 'Не удалось восстановить участие. Попробуйте позже.';
  } finally {
    submitting.value = false;
  }
}
</script>
