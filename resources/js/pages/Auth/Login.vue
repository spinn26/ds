<template>
  <div class="auth-page auth-page--minimal">
    <section class="auth-form-wrap">
      <div class="auth-form">
        <div class="form-mobile-brand">
          <div class="hero-mark hero-mark--inverse">DS</div>
          <div class="hero-brand-title text-on-surface">DS Consulting</div>
        </div>

        <h2 class="form-headline">{{ challenge ? 'Подтверждение входа' : 'Вход' }}</h2>

        <v-alert v-if="error" type="error" density="compact" variant="tonal" class="mb-4">
          {{ error }}
        </v-alert>

        <!-- Шаг 1: email + password -->
        <v-form v-if="!challenge" @submit.prevent="handleLogin" class="form-fields">
          <v-text-field
            v-model="email"
            label="Электронная почта"
            type="email"
            prepend-inner-icon="mdi-email-outline"
            density="comfortable"
            variant="outlined"
            rounded="md"
            autocomplete="email"
            required
          />
          <v-text-field
            v-model="password"
            label="Пароль"
            :type="showPw ? 'text' : 'password'"
            prepend-inner-icon="mdi-lock-outline"
            :append-inner-icon="showPw ? 'mdi-eye-off' : 'mdi-eye'"
            @click:append-inner="showPw = !showPw"
            density="comfortable"
            variant="outlined"
            rounded="md"
            autocomplete="current-password"
            required
          />

          <v-btn
            type="submit"
            color="primary"
            size="large"
            block
            :loading="loading"
            class="form-cta"
          >
            Войти
          </v-btn>
        </v-form>

        <!-- Шаг 2: 2FA TOTP -->
        <v-form v-else @submit.prevent="handleVerify2fa" class="form-fields">
          <v-text-field
            v-model="totpCode"
            label="Код из приложения"
            prepend-inner-icon="mdi-shield-key-outline"
            density="comfortable"
            variant="outlined"
            rounded="md"
            maxlength="6"
            inputmode="numeric"
            autofocus
            :rules="[v => /^\d{6}$/.test(v) || '6 цифр']"
          />
          <v-btn
            type="submit"
            color="primary"
            size="large"
            block
            :loading="loading"
            class="form-cta"
          >
            Подтвердить
          </v-btn>
          <v-btn variant="text" block @click="cancelVerify">Назад</v-btn>
        </v-form>
      </div>
    </section>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';

const auth = useAuthStore();
const router = useRouter();
const email = ref('');
const password = ref('');
const showPw = ref(false);
const error = ref('');
const loading = ref(false);
// 2FA challenge state — null до выдачи; после выдачи показывается шаг ввода кода.
const challenge = ref(null);
const totpCode = ref('');

async function handleLogin() {
  error.value = '';
  loading.value = true;
  try {
    const res = await auth.login(email.value, password.value);
    if (res?.requires2fa) {
      challenge.value = res.challenge;
      totpCode.value = '';
    } else {
      router.push('/');
    }
  } catch (e) {
    error.value = e.response?.data?.message || 'Неверная почта или пароль';
  } finally {
    loading.value = false;
  }
}

async function handleVerify2fa() {
  if (!/^\d{6}$/.test(totpCode.value)) { error.value = 'Введите 6 цифр'; return; }
  error.value = '';
  loading.value = true;
  try {
    await auth.verify2fa(challenge.value, totpCode.value);
    router.push('/');
  } catch (e) {
    error.value = e.response?.data?.message || 'Неверный код';
  } finally {
    loading.value = false;
  }
}

function cancelVerify() {
  challenge.value = null;
  totpCode.value = '';
  password.value = '';
  error.value = '';
}
</script>

<style scoped>
/* Минимальный экран авторизации: форма центрирована на surface.
   Только email + пароль + кнопка «Войти». Hero и доп-кнопки убраны
   по запросу 2026-05-26 — упрощённый flow. */
.auth-page {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  background: rgb(var(--v-theme-surface));
  padding: 32px 20px;
}

.auth-form-wrap {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
}

.auth-form {
  width: 100%;
  max-width: 380px;
}

.form-mobile-brand {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 28px;
}
.hero-mark {
  width: 44px; height: 44px;
  display: grid; place-items: center;
  border-radius: 10px;
  font-weight: 800;
  font-size: 18px;
  letter-spacing: 0.4px;
}
.hero-mark--inverse {
  background: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
}
.hero-brand-title { font-size: 18px; font-weight: 600; line-height: 1.2; }

.form-headline {
  font-size: 28px;
  font-weight: 700;
  line-height: 1.2;
  letter-spacing: -0.3px;
  color: rgb(var(--v-theme-on-surface));
  margin: 0 0 24px;
}

.form-fields { display: flex; flex-direction: column; gap: 14px; }
.form-cta { font-weight: 600; letter-spacing: 0.2px; margin-top: 4px; }
</style>
