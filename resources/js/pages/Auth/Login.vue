<template>
  <div class="auth-page" :class="{ 'auth-page--mobile': mobile }">
    <!-- Hero (left half on desktop, hidden/collapsed on mobile) -->
    <aside class="auth-hero">
      <div class="hero-waves">
        <BrandWaves :width="900" :height="900"
          bg-color="transparent" stroke-color="#ffffff"
          :rows="18" :columns="22" :amplitude="6" :frequency="1.2"
          :stroke-width="0.8" :stroke-opacity="0.35" />
      </div>

      <header class="hero-brand">
        <div class="hero-mark">DS</div>
        <div>
          <div class="hero-brand-title">DS Consulting</div>
          <div class="hero-brand-sub">Партнёрская платформа</div>
        </div>
      </header>

      <div class="hero-pitch">
        <h1 class="hero-headline">Партнёрский кабинет для финансовых консультантов</h1>
        <p class="hero-lead">
          Клиенты, контракты, комиссии и обучение — в одном месте.
          Real-time чат с поддержкой и кураторами.
        </p>
      </div>

      <footer class="hero-footer">© DS Consulting · 2026 · 152-ФЗ</footer>
    </aside>

    <!-- Form (right half on desktop, full width on mobile) -->
    <section class="auth-form-wrap">
      <div class="auth-form">
        <!-- На мобилке логотип сверху над формой -->
        <div v-if="mobile" class="form-mobile-brand">
          <div class="hero-mark hero-mark--inverse">DS</div>
          <div class="hero-brand-title text-on-surface">DS Consulting</div>
        </div>

        <div class="form-eyebrow">вход в кабинет</div>
        <h2 class="form-headline">{{ challenge ? 'Подтверждение входа' : 'С возвращением' }}</h2>
        <p class="form-lead">
          <template v-if="!challenge">
            Войдите, чтобы продолжить работу с клиентами и контрактами.
          </template>
          <template v-else>
            Откройте Google Authenticator и введите 6-значный код.
          </template>
        </p>

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
            Войти в кабинет
          </v-btn>

          <div class="form-divider">
            <span>или</span>
          </div>

          <v-btn
            variant="outlined"
            size="large"
            block
            prepend-icon="mdi-send"
            class="form-cta-secondary"
            disabled
          >
            Войти через Telegram
          </v-btn>

          <p class="form-aux">
            Ещё не партнёр?
            <router-link to="/register">Подать заявку</router-link>
          </p>
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
import { useDisplay } from 'vuetify';
import { useAuthStore } from '../../stores/auth';
import BrandWaves from '../../components/BrandWaves.vue';

const { mobile } = useDisplay();

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
.auth-page {
  display: grid;
  grid-template-columns: 1fr 480px;
  min-height: 100vh;
  background: rgb(var(--v-theme-surface));
}
.auth-page--mobile { grid-template-columns: 1fr; }

/* ───────── HERO ───────── */
.auth-hero {
  position: relative;
  overflow: hidden;
  padding: 48px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  color: #fff;
  background:
    linear-gradient(135deg,
      rgb(var(--v-theme-primary)) 0%,
      rgb(var(--v-theme-secondary)) 100%);
}
.auth-page--mobile .auth-hero { display: none; }

.hero-waves {
  position: absolute;
  inset: 0;
  opacity: 0.45;
  pointer-events: none;
}

.hero-brand {
  position: relative;
  display: flex;
  align-items: center;
  gap: 14px;
}
.hero-mark {
  width: 44px; height: 44px;
  display: grid; place-items: center;
  background: rgba(255, 255, 255, 0.16);
  border: 1.5px solid rgba(255, 255, 255, 0.4);
  border-radius: 10px;
  font-weight: 800;
  font-size: 18px;
  letter-spacing: 0.4px;
  color: #fff;
  backdrop-filter: blur(4px);
}
.hero-mark--inverse {
  background: rgb(var(--v-theme-primary));
  color: #fff;
  border-color: transparent;
}
.hero-brand-title { font-size: 18px; font-weight: 600; line-height: 1.2; }
.hero-brand-sub { font-size: 13px; opacity: 0.82; margin-top: 2px; }

.hero-pitch { position: relative; max-width: 540px; }
.hero-headline {
  font-size: 38px;
  line-height: 1.12;
  font-weight: 700;
  letter-spacing: -0.5px;
  margin: 0 0 18px;
}
.hero-lead {
  font-size: 16px;
  line-height: 1.5;
  opacity: 0.95;
  margin: 0;
  max-width: 460px;
}

.hero-footer {
  position: relative;
  font-size: 13px;
  opacity: 0.78;
}

/* ───────── FORM ───────── */
.auth-form-wrap {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 48px 56px;
  background: rgb(var(--v-theme-surface));
}
.auth-page--mobile .auth-form-wrap { padding: 32px 20px; }

.auth-form {
  width: 100%;
  max-width: 380px;
}

.form-mobile-brand {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 24px;
}

.form-eyebrow {
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 1.2px;
  text-transform: uppercase;
  color: rgb(var(--v-theme-primary));
  margin-bottom: 6px;
}
.form-headline {
  font-size: 28px;
  font-weight: 700;
  line-height: 1.2;
  letter-spacing: -0.3px;
  color: rgb(var(--v-theme-on-surface));
  margin: 0 0 6px;
}
.form-lead {
  font-size: 14px;
  line-height: 1.5;
  color: rgba(var(--v-theme-on-surface), 0.65);
  margin: 0 0 28px;
}

.form-fields { display: flex; flex-direction: column; gap: 14px; }

.form-cta { font-weight: 600; letter-spacing: 0.2px; }

.form-divider {
  display: flex;
  align-items: center;
  gap: 14px;
  margin: 6px 0;
  color: rgba(var(--v-theme-on-surface), 0.5);
  font-size: 13px;
}
.form-divider::before,
.form-divider::after {
  content: '';
  flex: 1;
  height: 1px;
  background: rgba(var(--v-theme-on-surface), 0.12);
}

.form-cta-secondary { font-weight: 500; }

.form-aux {
  text-align: center;
  font-size: 13px;
  color: rgba(var(--v-theme-on-surface), 0.6);
  margin: 12px 0 0;
}
.form-aux :deep(a) {
  color: rgb(var(--v-theme-primary));
  font-weight: 600;
  text-decoration: none;
}
.form-aux :deep(a:hover) { text-decoration: underline; }
</style>
