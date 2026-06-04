<template>
  <AuthShell
    hero-title="Восстановите доступ"
    hero-lead="Введите свой email — пришлём ссылку для сброса пароля. Ссылка действительна 60 минут."
  >
    <div class="form-eyebrow">сброс пароля</div>
    <h2 class="form-headline">Забыли пароль?</h2>
    <p class="form-lead">
      Введите email от вашего аккаунта. Если он зарегистрирован — пришлём
      одноразовую ссылку для установки нового пароля.
    </p>

    <v-alert v-if="error" :type="cooldown ? 'warning' : 'error'" density="compact" variant="tonal" class="mb-4">
      {{ error }}
      <div v-if="cooldown" class="mt-1 font-weight-medium">
        Повторить можно через {{ cooldownText }}
      </div>
    </v-alert>
    <v-alert v-if="sent" type="success" density="compact" variant="tonal" class="mb-4">
      {{ sentMessage }}
    </v-alert>

    <v-form v-if="!sent" @submit.prevent="handleSubmit" class="form-fields">
      <v-text-field
        v-model="email"
        label="Электронная почта"
        type="email"
        prepend-inner-icon="mdi-email-outline"
        density="comfortable"
        variant="outlined"
        rounded="md"
        autocomplete="email"
        autofocus
        required
      />
      <v-btn type="submit" color="primary" size="large" block
        :loading="loading" :disabled="cooldown" class="form-cta">
        {{ cooldown ? `Повторить через ${cooldownText}` : 'Отправить ссылку' }}
      </v-btn>
      <p class="form-hint">
        Повторный запрос письма для сброса — не чаще одного раза в 5 минут.
      </p>
    </v-form>

    <p class="form-aux mt-4">
      Вспомнили пароль? <router-link to="/login">Вернуться ко входу</router-link>
    </p>
  </AuthShell>
</template>

<script setup>
import { ref, computed, onUnmounted } from 'vue';
import api from '../../api';
import AuthShell from '../../components/AuthShell.vue';

const email = ref('');
const loading = ref(false);
const error = ref('');
const sent = ref(false);
const sentMessage = ref('');

// Обратный отсчёт до повторного запроса (бэк троттлит сброс раз в 5 минут
// на один email и отдаёт остаток в retry_after / заголовке Retry-After).
const retryAfter = ref(0);
let retryTimer = null;
const cooldown = computed(() => retryAfter.value > 0);
const cooldownText = computed(() => {
  const m = Math.floor(retryAfter.value / 60);
  const s = retryAfter.value % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
});

function startCooldown(seconds) {
  retryAfter.value = Math.max(1, Math.ceil(Number(seconds) || 0));
  clearInterval(retryTimer);
  retryTimer = setInterval(() => {
    retryAfter.value -= 1;
    if (retryAfter.value <= 0) {
      clearInterval(retryTimer);
      retryTimer = null;
      error.value = '';
    }
  }, 1000);
}

onUnmounted(() => clearInterval(retryTimer));

async function handleSubmit() {
  if (cooldown.value) return;
  error.value = '';
  loading.value = true;
  try {
    const { data } = await api.post('/auth/forgot-password', { email: email.value });
    sent.value = true;
    sentMessage.value = data.message
      || 'Если такой email зарегистрирован, ссылка для сброса отправлена. Проверьте почту.';
  } catch (e) {
    if (e.response?.status === 429) {
      const secs = (e.response?.data?.retry_after
        ?? Number(e.response?.headers?.['retry-after'])) || 300;
      startCooldown(secs);
      // Свой per-email message (с retry_after) показываем как есть; для
      // IP-троттла (route throttle) приходит англ. "Too Many Attempts" —
      // подменяем русским текстом.
      error.value = e.response?.data?.retry_after
        ? e.response.data.message
        : 'Слишком частые запросы. Письмо для сброса можно запрашивать раз в 5 минут.';
    } else {
      error.value = e.response?.data?.message || 'Не удалось отправить ссылку. Попробуйте позже.';
    }
  }
  loading.value = false;
}
</script>

<style scoped>
/* Shared form-text стили дублируем здесь, т.к. в AuthShell их нет
   (там только layout). Совпадают с Login.vue для визуальной
   консистентности трёх auth-страниц. */
.form-eyebrow {
  font-size: 12px; font-weight: 600; letter-spacing: 1.2px;
  text-transform: uppercase;
  color: rgb(var(--v-theme-primary));
  margin-bottom: 6px;
}
.form-headline {
  font-size: 28px; font-weight: 700; line-height: 1.2;
  letter-spacing: -0.3px;
  color: rgb(var(--v-theme-on-surface));
  margin: 0 0 6px;
}
.form-lead {
  font-size: 14px; line-height: 1.5;
  color: rgba(var(--v-theme-on-surface), 0.65);
  margin: 0 0 28px;
}
.form-fields { display: flex; flex-direction: column; gap: 14px; }
.form-cta { font-weight: 600; letter-spacing: 0.2px; }
.form-hint {
  margin: 0;
  font-size: 12px;
  line-height: 1.4;
  color: rgba(var(--v-theme-on-surface), 0.55);
}
.form-aux {
  text-align: center; font-size: 13px;
  color: rgba(var(--v-theme-on-surface), 0.6);
}
.form-aux :deep(a) {
  color: rgb(var(--v-theme-primary));
  font-weight: 600; text-decoration: none;
}
.form-aux :deep(a:hover) { text-decoration: underline; }
</style>
