<template>
  <!-- DS spec ds-missing-partner.jsx::PartnerTerminated:
       центрированная карточка max-width 560, 88px lock-кружок в
       error-container фоне, два списка "Что это значит" / "Для
       восстановления", две кнопки внизу (filled + outlined). -->
  <div class="terminated-page">
    <v-card class="terminated-card pa-9 text-center">
      <div class="t-icon">
        <v-icon size="42" color="error">mdi-lock</v-icon>
      </div>

      <h2 class="ds-headline-m text-error mt-4">Доступ ограничен</h2>
      <div class="ds-body-l ds-muted mt-2">
        <template v-if="excluded">
          Ваш аккаунт находится в статусе «Исключён». Доступ к разделам платформы закрыт.
        </template>
        <template v-else>
          <span class="text-error font-weight-medium">
            Ваш партнёрский аккаунт переведён в статус «Терминирован»: агентский
            договор расторгнут, условия активационного периода не выполнены.
          </span>
          <br>
          Доступ к разделам платформы временно закрыт.
        </template>
      </div>

      <v-divider class="my-6" />

      <div class="t-lists d-flex flex-column ga-5 text-left">
        <div>
          <div class="ds-title-s mb-2">Что это значит:</div>
          <ul class="t-bullets">
            <li>Накопленные баллы обнулены</li>
            <li>Клиенты и контракты переданы вышестоящему партнёру и остаются за ним</li>
          </ul>
        </div>

        <!-- Попытки ещё есть: возврат делается в кабинете, а не через поддержку.
             Раньше страница отправляла в техподдержку и к наставнику — она
             писалась до появления самовосстановления и разошлась с ним. -->
        <div v-if="!excluded">
          <div class="ds-title-s mb-2">Как вернуться в работу:</div>
          <ul class="t-bullets">
            <li>
              Восстановить участие можно самостоятельно — окно возврата
              открывается при входе в кабинет
            </li>
            <li>
              Доступно
              <strong>{{ attemptsLeft }}</strong>
              {{ plural(attemptsLeft, 'попытка', 'попытки', 'попыток') }}
              из {{ limit }}
            </li>
            <li>После восстановления снова даётся {{ windowDays }} дней на набор {{ minLp }} ЛП</li>
          </ul>
        </div>

        <!-- Попытки исчерпаны: кнопки возврата нет, и это надо объяснить. -->
        <div v-else>
          <div class="ds-title-s mb-2">Почему возврат недоступен:</div>
          <ul class="t-bullets">
            <li>Все {{ limit }} {{ plural(limit, 'попытка', 'попытки', 'попыток') }} восстановления использованы</li>
            <li>Если считаете, что произошла ошибка, напишите в поддержку — решение принимается индивидуально</li>
          </ul>
        </div>
      </div>

      <div class="d-flex justify-center ga-2 mt-6">
        <v-btn to="/communication" color="primary" variant="flat" size="large" prepend-icon="mdi-chat">
          Обратная связь
        </v-btn>
        <v-btn to="/profile" variant="outlined" size="large" prepend-icon="mdi-account">
          Профиль
        </v-btn>
      </div>
    </v-card>
  </div>
</template>

<script setup>
import { computed, ref, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import api from '../api';

const auth = useAuthStore();
const router = useRouter();

// Подстраховка: роутер уводит СЮДА, но обратно не уводит никогда. Если статус
// уже снят (восстановились в этой вкладке или в соседней), страница не должна
// продолжать показывать «Доступ ограничен».
watch(() => auth.user?.termination?.terminated, (terminated) => {
  if (terminated === false) router.replace('/');
}, { immediate: true });

// Блок termination из /auth/me — тот же, по которому MainLayout показывает
// окно возврата. Здесь он нужен, чтобы страница не обещала восстановление
// тому, у кого попытки кончились, и наоборот.
const termination = computed(() => auth.user?.termination || {});
const excluded = computed(() => termination.value.excluded === true);
const attemptsLeft = computed(() => Number(termination.value.attemptsLeft ?? 3));
const limit = computed(() => Number(termination.value.limit ?? 3));

// Пороги активации берём из профиля, а не хардкодим: они настраиваются
// в system_settings (activation.window_days / activation.min_lp).
const statusInfo = ref(null);
const windowDays = computed(() => statusInfo.value?.windowDays ?? 120);
const minLp = computed(() => statusInfo.value?.activationPoints ?? 500);

onMounted(async () => {
  try {
    const { data } = await api.get('/profile');
    statusInfo.value = data.statusInfo || null;
  } catch { /* дефолты устава выше — страница информационная, падать нечему */ }
});

function plural(n, one, few, many) {
  if (n % 10 === 1 && n % 100 !== 11) return one;
  if ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100)) return few;
  return many;
}
</script>

<style scoped>
.terminated-page {
  display: grid;
  place-items: center;
  min-height: 70vh;
  padding: 28px;
}
.terminated-card {
  max-width: 560px;
  width: 100%;
  border-radius: var(--ds-radius-lg, 12px) !important;
}
.t-icon {
  width: 88px;
  height: 88px;
  border-radius: 50%;
  background: var(--ds-error-container, rgb(var(--v-theme-error-container), 0.15));
  display: grid;
  place-items: center;
  margin: 0 auto;
}
.t-bullets {
  margin: 0;
  padding-left: 20px;
  color: var(--ds-on-surface-variant, rgba(var(--v-theme-on-surface), 0.65));
  font-size: 14px;
  line-height: 1.65;
}
.t-bullets li {
  margin-bottom: 2px;
}
</style>
