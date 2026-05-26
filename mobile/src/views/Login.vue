<template>
  <div class="login-screen">
    <div class="login-brand">
      <div class="brand-mark">
        <span class="brand-mark-text">DS</span>
      </div>
      <div class="brand-title">DS Partner</div>
      <div class="brand-subtitle">Личный кабинет</div>
    </div>

    <v-card class="login-card" elevation="0">
      <!-- Шаг 1: email + пароль (единая форма для всех ролей —
           бэкенд сам определяет кабинет по data.user.role) -->
      <template v-if="!twoFA.challenge">
        <form @submit.prevent="onSubmit" novalidate>
          <v-text-field v-model="form.email" label="E-mail" type="email"
            autocomplete="email" prepend-inner-icon="mdi-email-outline"
            :error-messages="errors.email" @keyup.enter="onSubmit" />
          <v-text-field v-model="form.password" label="Пароль"
            :type="showPassword ? 'text' : 'password'" autocomplete="current-password"
            prepend-inner-icon="mdi-lock-outline"
            :append-inner-icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
            :error-messages="errors.password"
            @click:append-inner="showPassword = !showPassword"
            @keyup.enter="onSubmit" />
          <div v-if="errors.form" class="text-error text-caption mb-2">{{ errors.form }}</div>
          <v-btn type="button" color="primary" size="large" block rounded="lg"
            :loading="submitting" :disabled="!canSubmit" @click="onSubmit">
            Войти
          </v-btn>
          <div class="text-center mt-4">
            <a class="text-caption text-medium-emphasis" href="#">Забыли пароль?</a>
          </div>
        </form>
      </template>

      <!-- Шаг 2: TOTP-код -->
      <template v-else>
        <div class="text-center mb-4">
          <v-icon size="36" color="primary">mdi-shield-key-outline</v-icon>
          <div class="text-h6 font-weight-bold mt-2">Подтвердите вход</div>
          <div class="text-caption text-medium-emphasis">
            Введите 6-значный код из приложения-аутентификатора
          </div>
        </div>
        <v-text-field v-model="twoFA.code" label="Код"
          inputmode="numeric" maxlength="6" placeholder="123456"
          prepend-inner-icon="mdi-numeric"
          :error-messages="errors.form"
          @keyup.enter="verify2FA" />
        <v-btn color="primary" size="large" block rounded="lg" :loading="verifying"
          :disabled="twoFA.code.length !== 6" @click="verify2FA">
          Подтвердить
        </v-btn>
        <v-btn variant="text" size="small" block class="mt-2"
          @click="twoFA.challenge = ''; twoFA.code = ''; errors.form = undefined">
          Назад
        </v-btn>
      </template>
    </v-card>

    <div class="login-footer text-caption text-medium-emphasis">
      v{{ appVersion }} · {{ apiHost }}
    </div>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref, computed } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useNotificationsStore } from '@/stores/notifications';
import api from '@/api';
import axios from 'axios';

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();
const notifications = useNotificationsStore();

const form = reactive({ email: '', password: '' });
const errors = reactive<{ email?: string; password?: string; form?: string }>({});
const showPassword = ref(false);
const submitting = ref(false);

// 2FA state: после успешного шага 1 бэк может вернуть challenge —
// переключаемся в режим ввода TOTP.
const twoFA = reactive({ challenge: '', code: '' });
const verifying = ref(false);

const appVersion = '0.1.0';
const apiHost = (import.meta.env.VITE_API_BASE || 'dev.dsconsult.ru').replace(/^https?:\/\//, '').split('/')[0];

const canSubmit = computed(() => form.email.trim() && form.password.length >= 4);

async function onSubmit() {
  if (submitting.value) return;
  errors.email = errors.password = errors.form = undefined;
  if (!canSubmit.value) {
    errors.form = 'Введите e-mail и пароль (минимум 4 символа)';
    return;
  }
  submitting.value = true;
  try {
    const { data } = await api.post('/auth/login', {
      email: form.email,
      password: form.password,
    });

    if (data?.requires_2fa && data?.challenge) {
      twoFA.challenge = data.challenge;
      twoFA.code = '';
      return;
    }

    if (!data?.token || !data?.user) {
      errors.form = 'Сервер вернул некорректный ответ. Попробуйте ещё раз.';
      return;
    }

    await auth.setSession(data.token, {
      id: data.user.id,
      firstName: data.user.firstName,
      lastName: data.user.lastName,
      email: data.user.email,
      role: data.user.role,
    });

    // Бэк определяет роль — редирект по факту (а не по выбору на форме).
    notifications.refresh();
    notifications.startPolling();
    const fallback = auth.isStaff ? '/manage/dashboard' : '/app/home';
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : fallback;
    await router.replace(redirect);
  } catch (e: unknown) {
    let msg = 'Не удалось войти';
    if (axios.isAxiosError(e)) {
      msg = e.response?.data?.message || (e.response?.status === 0 || !e.response
        ? 'Нет соединения с сервером. Проверьте интернет.'
        : `Ошибка ${e.response.status}`);
    } else if (e instanceof Error) {
      msg = e.message;
    }
    errors.form = msg;
  } finally {
    submitting.value = false;
  }
}

async function verify2FA() {
  errors.form = undefined;
  if (twoFA.code.length !== 6) return;
  verifying.value = true;
  try {
    const { data } = await api.post('/2fa/verify', {
      challenge: twoFA.challenge,
      code: twoFA.code,
    });
    if (!data?.token || !data?.user) {
      errors.form = 'Сервер вернул некорректный ответ.';
      return;
    }
    await auth.setSession(data.token, {
      id: data.user.id,
      firstName: data.user.firstName,
      lastName: data.user.lastName,
      email: data.user.email,
      role: data.user.role,
    });
    twoFA.challenge = '';
    twoFA.code = '';
    notifications.refresh();
    notifications.startPolling();
    const fallback = auth.isStaff ? '/manage/dashboard' : '/app/home';
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : fallback;
    await router.replace(redirect);
  } catch (e: unknown) {
    let msg = 'Неверный код';
    if (axios.isAxiosError(e)) {
      msg = e.response?.data?.message || msg;
    }
    errors.form = msg;
  } finally {
    verifying.value = false;
  }
}
</script>

<style scoped>
.login-screen {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background:
    radial-gradient(circle at 20% 0%, rgba(110, 232, 122, 0.18) 0%, transparent 45%),
    radial-gradient(circle at 80% 100%, rgba(46, 125, 50, 0.10) 0%, transparent 50%),
    linear-gradient(180deg, #fafcfb 0%, #f5faf6 100%);
  padding: max(32px, env(safe-area-inset-top)) 24px max(28px, env(safe-area-inset-bottom));
}
.login-brand {
  margin: 56px auto 36px;
  text-align: center;
}
.brand-mark {
  width: 76px;
  height: 76px;
  border-radius: 22px;
  background: linear-gradient(135deg, #2E7D32 0%, #43A047 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 16px;
  box-shadow: 0 18px 40px rgba(46, 125, 50, 0.30), 0 4px 12px rgba(46, 125, 50, 0.15);
}
.brand-mark-text {
  color: #fff;
  font-weight: 900;
  font-size: 28px;
  letter-spacing: -1.5px;
}
.brand-title {
  font-size: 26px;
  font-weight: 800;
  letter-spacing: -0.6px;
  color: rgb(var(--v-theme-on-surface));
}
.brand-subtitle {
  font-size: 14px;
  color: rgba(var(--v-theme-on-surface), 0.55);
  margin-top: 4px;
}
.login-card {
  border-radius: 22px;
  background: rgba(255, 255, 255, 0.85);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  padding: 28px 22px;
  box-shadow:
    0 1px 0 rgba(255, 255, 255, 0.6) inset,
    0 12px 32px rgba(0, 0, 0, 0.06),
    0 2px 8px rgba(0, 0, 0, 0.03);
}
.login-footer {
  margin-top: auto;
  padding-top: 16px;
  text-align: center;
}
</style>
