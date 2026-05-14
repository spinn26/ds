<template>
  <div class="login-screen">
    <div class="login-brand">
      <div class="brand-mark">
        <span class="brand-mark-text">DS</span>
      </div>
      <div class="brand-title">DS Partner</div>
      <div class="brand-subtitle">Партнёрский кабинет</div>
    </div>

    <v-card class="login-card pa-6" elevation="0">
      <div class="cabinet-toggle">
        <button v-for="opt in cabinetOptions" :key="opt.value"
          :class="['ctg-btn', { active: cabinet === opt.value }]"
          type="button" @click="cabinet = opt.value">
          <v-icon size="14">{{ opt.icon }}</v-icon>
          {{ opt.label }}
        </button>
      </div>
      <form @submit.prevent="onSubmit" novalidate>
        <v-text-field
          v-model="form.email"
          label="E-mail"
          type="email"
          autocomplete="email"
          prepend-inner-icon="mdi-email-outline"
          :error-messages="errors.email"
          @keyup.enter="onSubmit"
        />
        <v-text-field
          v-model="form.password"
          label="Пароль"
          :type="showPassword ? 'text' : 'password'"
          autocomplete="current-password"
          prepend-inner-icon="mdi-lock-outline"
          :append-inner-icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
          :error-messages="errors.password"
          @click:append-inner="showPassword = !showPassword"
          @keyup.enter="onSubmit"
        />

        <div v-if="errors.form" class="text-error text-caption mb-2">{{ errors.form }}</div>

        <v-btn
          type="button"
          color="primary"
          size="large"
          block
          :loading="submitting"
          :disabled="!canSubmit"
          @click="onSubmit"
        >
          Войти
        </v-btn>

        <div class="text-center mt-4">
          <a class="text-caption text-medium-emphasis" href="#">Забыли пароль?</a>
        </div>
      </form>
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
import api from '@/api';
import axios from 'axios';

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();

const form = reactive({ email: '', password: '' });
const errors = reactive<{ email?: string; password?: string; form?: string }>({});
const showPassword = ref(false);
const submitting = ref(false);
const cabinet = ref<'partner' | 'admin'>('partner');
const cabinetOptions = [
  { value: 'partner' as const, label: 'Партнёр', icon: 'mdi-account-outline' },
  { value: 'admin' as const, label: 'Сотрудник', icon: 'mdi-shield-account-outline' },
];

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
  // eslint-disable-next-line no-console
  console.log('[login] submit', form.email);
  try {
    // Реальный логин: POST /api/v1/auth/login → { token, user } или
    // { requires_2fa: true, challenge } для пользователей с включённой
    // двухфакторкой. 2FA на мобиле пока не реализован — выводим
    // сообщение и просим войти с веба.
    const { data } = await api.post('/auth/login', {
      email: form.email,
      password: form.password,
    });

    if (data?.requires_2fa) {
      errors.form = 'Для этого аккаунта включена двухфакторная аутентификация. Подтвердите вход на веб-версии — поддержка 2FA в мобилке готовится.';
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

    // Бэк сам определил роль — редиректим по факту (а не по toggle).
    // Toggle остаётся как hint, какой кабинет ожидает пользователь.
    const fallback = auth.isStaff ? '/manage/dashboard' : '/app/home';
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : fallback;
    await router.replace(redirect);
  } catch (e: unknown) {
    // eslint-disable-next-line no-console
    console.error('[login] error', e);
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
</script>

<style scoped>
.login-screen {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background: linear-gradient(180deg, rgba(46, 125, 50, 0.08) 0%, rgba(110, 232, 122, 0.04) 100%);
  padding: max(24px, env(safe-area-inset-top)) 20px max(24px, env(safe-area-inset-bottom));
}
.login-brand {
  margin: 32px auto 24px;
  text-align: center;
}
.brand-mark {
  width: 64px;
  height: 64px;
  border-radius: 18px;
  background: rgb(var(--v-theme-primary));
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 12px;
  box-shadow: 0 8px 24px rgba(46, 125, 50, 0.25);
}
.brand-mark-text {
  color: #fff;
  font-weight: 900;
  font-size: 24px;
  letter-spacing: -1px;
}
.brand-title {
  font-size: 22px;
  font-weight: 700;
  color: rgb(var(--v-theme-on-surface));
}
.brand-subtitle {
  font-size: 13px;
  color: rgba(var(--v-theme-on-surface), 0.6);
  margin-top: 2px;
}
.login-card {
  border-radius: 16px;
  background: rgba(var(--v-theme-surface), 1);
  box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
}
.cabinet-toggle {
  display: flex;
  gap: 4px;
  background: rgba(0, 0, 0, 0.05);
  border-radius: 12px;
  padding: 4px;
  margin-bottom: 14px;
}
.ctg-btn {
  flex: 1;
  border: 0;
  background: transparent;
  padding: 8px 12px;
  border-radius: 9px;
  font-size: 13px;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.6);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  transition: all 0.15s;
}
.ctg-btn.active {
  background: #fff;
  color: rgb(var(--v-theme-primary));
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
}
.login-footer {
  margin-top: auto;
  padding-top: 16px;
  text-align: center;
}
</style>
