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

    <v-alert v-if="error" type="error" density="compact" variant="tonal" class="mb-4">
      {{ error }}
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
      <v-btn type="submit" color="primary" size="large" block :loading="loading" class="form-cta">
        Отправить ссылку
      </v-btn>
    </v-form>

    <p class="form-aux mt-4">
      Вспомнили пароль? <router-link to="/login">Вернуться ко входу</router-link>
    </p>
  </AuthShell>
</template>

<script setup>
import { ref } from 'vue';
import api from '../../api';
import AuthShell from '../../components/AuthShell.vue';

const email = ref('');
const loading = ref(false);
const error = ref('');
const sent = ref(false);
const sentMessage = ref('');

async function handleSubmit() {
  error.value = '';
  loading.value = true;
  try {
    const { data } = await api.post('/auth/forgot-password', { email: email.value });
    sent.value = true;
    sentMessage.value = data.message
      || 'Если такой email зарегистрирован, ссылка для сброса отправлена. Проверьте почту.';
  } catch (e) {
    if (e.response?.status === 429) {
      const retry = e.response?.headers?.['retry-after'];
      error.value = retry
        ? `Слишком часто. Повторите через ${retry} сек.`
        : 'Слишком много попыток. Подождите минуту и попробуйте снова.';
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
