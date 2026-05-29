<template>
  <AuthShell
    hero-title="Новый пароль"
    hero-lead="Установите новый пароль и продолжайте работу с клиентами и контрактами."
  >
    <div class="form-eyebrow">сброс пароля</div>
    <h2 class="form-headline">Установите новый пароль</h2>
    <p class="form-lead">
      Минимум 8 символов, должны быть буква и цифра.
    </p>

    <v-alert v-if="error" type="error" density="compact" variant="tonal" class="mb-4">
      {{ error }}
    </v-alert>
    <v-alert v-if="done" type="success" density="compact" variant="tonal" class="mb-4">
      Пароль изменён. Сейчас перебросим на страницу входа…
    </v-alert>
    <v-alert v-if="!token || !email" type="warning" density="compact" variant="tonal" class="mb-4">
      В ссылке нет токена или email. Запросите новую ссылку
      на странице <router-link to="/forgot-password">восстановления пароля</router-link>.
    </v-alert>

    <v-form v-if="!done && token && email" @submit.prevent="handleSubmit" class="form-fields">
      <v-text-field
        :model-value="email"
        label="Электронная почта"
        prepend-inner-icon="mdi-email-outline"
        density="comfortable"
        variant="outlined"
        rounded="md"
        readonly
      />
      <v-text-field
        v-model="password"
        label="Новый пароль"
        :type="showPw ? 'text' : 'password'"
        prepend-inner-icon="mdi-lock-outline"
        :append-inner-icon="showPw ? 'mdi-eye-off' : 'mdi-eye'"
        @click:append-inner="showPw = !showPw"
        density="comfortable"
        variant="outlined"
        rounded="md"
        autocomplete="new-password"
        :rules="passwordRules"
        autofocus
        required
      />
      <v-text-field
        v-model="passwordConfirm"
        label="Повторите пароль"
        :type="showPw ? 'text' : 'password'"
        prepend-inner-icon="mdi-lock-check-outline"
        density="comfortable"
        variant="outlined"
        rounded="md"
        autocomplete="new-password"
        :rules="[v => v === password || 'Пароли не совпадают']"
        required
      />
      <v-btn type="submit" color="primary" size="large" block :loading="loading" class="form-cta">
        Сохранить пароль
      </v-btn>
    </v-form>

    <p class="form-aux mt-4">
      <router-link to="/login">Вернуться ко входу</router-link>
    </p>
  </AuthShell>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../../api';
import AuthShell from '../../components/AuthShell.vue';

const route = useRoute();
const router = useRouter();

const token = computed(() => String(route.query.token || ''));
const email = computed(() => String(route.query.email || ''));

const password = ref('');
const passwordConfirm = ref('');
const showPw = ref(false);
const loading = ref(false);
const error = ref('');
const done = ref(false);

const passwordRules = [
  v => !!v || 'Обязательное поле',
  v => (v && v.length >= 8) || 'Минимум 8 символов',
  v => /[A-Za-zА-Яа-я]/.test(v || '') || 'Должна быть хотя бы одна буква',
  v => /\d/.test(v || '') || 'Должна быть хотя бы одна цифра',
];

async function handleSubmit() {
  error.value = '';
  if (password.value !== passwordConfirm.value) {
    error.value = 'Пароли не совпадают';
    return;
  }
  loading.value = true;
  try {
    await api.post('/auth/reset-password', {
      token: token.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirm.value,
    });
    done.value = true;
    setTimeout(() => router.push('/login'), 2000);
  } catch (e) {
    error.value = e.response?.data?.message
      || 'Не удалось сбросить пароль. Попробуйте позже или запросите новую ссылку.';
  }
  loading.value = false;
}
</script>

<style scoped>
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
