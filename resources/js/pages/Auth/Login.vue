<template>
  <div class="login-page">
    <!-- Animated background -->
    <div class="bg-animated">
      <div class="bg-gradient"></div>
      <div class="bg-circles">
        <div v-for="n in 12" :key="n" class="circle" :style="circleStyle(n)"></div>
      </div>
      <div class="bg-grid"></div>
    </div>

    <v-container class="fill-height position-relative" fluid style="z-index:1">
      <v-row justify="center" align="center">
        <v-col cols="12" sm="8" md="5" lg="4">
          <v-card class="pa-8 login-card" elevation="16" rounded="xl">
            <div class="text-center mb-6">
              <div class="text-h3 font-weight-black text-primary logo-text">DS</div>
              <div class="text-caption text-medium-emphasis" style="letter-spacing: 4px">КОНСАЛТИНГ ПЛАТФОРМА</div>
            </div>

            <div class="text-h5 text-center mb-5 font-weight-medium">Вход в систему</div>

            <v-alert v-if="error" type="error" class="mb-4" density="compact" variant="tonal">{{ error }}</v-alert>

            <v-form @submit.prevent="handleLogin">
              <v-text-field v-model="email" label="Электронная почта" type="email"
                prepend-inner-icon="mdi-email" variant="outlined" rounded="lg" required class="mb-3" />
              <v-text-field v-model="password" label="Пароль"
                :type="showPw ? 'text' : 'password'"
                prepend-inner-icon="mdi-lock"
                :append-inner-icon="showPw ? 'mdi-eye-off' : 'mdi-eye'"
                @click:append-inner="showPw = !showPw"
                variant="outlined" rounded="lg" required class="mb-5" />
              <v-btn type="submit" block size="large" color="primary" :loading="loading"
                rounded="lg" elevation="4" class="mb-4 text-none font-weight-bold"
                style="font-size: 16px; letter-spacing: 1px">
                Войти
              </v-btn>
            </v-form>

            <div class="text-center text-body-2">
              Нет аккаунта?
              <router-link to="/register" class="text-primary font-weight-bold text-decoration-none">
                Зарегистрироваться
              </router-link>
            </div>
          </v-card>
        </v-col>
      </v-row>
    </v-container>
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

function circleStyle(n) {
  const size = 40 + Math.random() * 120;
  const x = Math.random() * 100;
  const y = Math.random() * 100;
  const duration = 15 + Math.random() * 25;
  const delay = Math.random() * -20;
  const opacity = 0.03 + Math.random() * 0.08;
  return {
    width: `${size}px`,
    height: `${size}px`,
    left: `${x}%`,
    top: `${y}%`,
    animationDuration: `${duration}s`,
    animationDelay: `${delay}s`,
    opacity,
  };
}

async function handleLogin() {
  error.value = '';
  loading.value = true;
  try {
    await auth.login(email.value, password.value);
    router.push('/');
  } catch (e) {
    error.value = e.response?.data?.message || 'Неверная почта или пароль';
  } finally {
    loading.value = false;
  }
}
</script>

<style scoped>
.login-page {
  position: relative;
  min-height: 100vh;
  overflow: hidden;
}

.bg-animated {
  position: fixed;
  inset: 0;
  z-index: 0;
}

.bg-gradient {
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, #0d1b2a 0%, #1b2d45 30%, #1a3a2a 60%, #0d2818 100%);
  animation: gradientShift 20s ease infinite;
  background-size: 400% 400%;
}

@keyframes gradientShift {
  0%, 100% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
}

.bg-circles {
  position: absolute;
  inset: 0;
}

.circle {
  position: absolute;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(76, 175, 80, 0.6) 0%, transparent 70%);
  animation: float linear infinite;
  pointer-events: none;
}

@keyframes float {
  0% { transform: translate(0, 0) scale(1); }
  25% { transform: translate(30px, -50px) scale(1.1); }
  50% { transform: translate(-20px, -100px) scale(0.9); }
  75% { transform: translate(40px, -50px) scale(1.05); }
  100% { transform: translate(0, 0) scale(1); }
}

.bg-grid {
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(rgba(76, 175, 80, 0.05) 1px, transparent 1px),
    linear-gradient(90deg, rgba(76, 175, 80, 0.05) 1px, transparent 1px);
  background-size: 60px 60px;
  animation: gridPan 30s linear infinite;
}

@keyframes gridPan {
  0% { transform: translate(0, 0); }
  100% { transform: translate(60px, 60px); }
}

.login-card {
  backdrop-filter: blur(20px);
  background: rgba(255, 255, 255, 0.97) !important;
  border: 1px solid rgba(76, 175, 80, 0.15);
  color: #333 !important;
}
.login-card :deep(.v-field__input),
.login-card :deep(.v-label),
.login-card :deep(.v-field__field) {
  color: #333 !important;
}
.login-card :deep(.v-icon) {
  color: #666 !important;
}

.logo-text {
  text-shadow: 0 2px 8px rgba(76, 175, 80, 0.3);
}
</style>
