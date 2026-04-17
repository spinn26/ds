<template>
  <div class="login-page" @mousemove="onMouseMove">
    <!-- Parallax layers: soft mint blobs on a dark base -->
    <div class="bg-base"></div>
    <div class="parallax">
      <div class="blob blob-a" :style="layerStyle(0.02, 0.03)"></div>
      <div class="blob blob-b" :style="layerStyle(-0.035, 0.02)"></div>
      <div class="blob blob-c" :style="layerStyle(0.025, -0.03)"></div>
      <div class="blob blob-d" :style="layerStyle(-0.015, -0.02)"></div>
      <div class="blob blob-e" :style="layerStyle(0.04, 0.015)"></div>
      <div class="sphere" :style="layerStyle(0.012, 0.008)">
        <BrandWaves shape="circle" :width="420" :height="420"
          bg-color="#6EE87A" stroke-color="#ffffff"
          :rows="14" :columns="18" :amplitude="4" :frequency="1.0"
          :stroke-width="0.9" :stroke-opacity="0.55" />
      </div>
    </div>
    <div class="vignette"></div>

    <v-container class="fill-height position-relative" fluid style="z-index:2">
      <v-row justify="center" align="center">
        <v-col cols="12" sm="8" md="5" lg="4">
          <v-card :class="mobile ? 'pa-4' : 'pa-8'" class="login-card" elevation="16" rounded="xl">
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

// Mouse position relative to viewport center (-1..1)
const mx = ref(0);
const my = ref(0);

function onMouseMove(e) {
  const w = window.innerWidth;
  const h = window.innerHeight;
  mx.value = (e.clientX - w / 2) / (w / 2);
  my.value = (e.clientY - h / 2) / (h / 2);
}

// Each blob shifts by its own speed for the parallax effect
function layerStyle(sx, sy) {
  const dx = mx.value * sx * 100;
  const dy = my.value * sy * 100;
  return { transform: `translate3d(${dx}px, ${dy}px, 0)` };
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
  background: #0B1210;
}

.bg-base {
  position: fixed;
  inset: 0;
  z-index: 0;
  background:
    radial-gradient(1200px 800px at 15% 20%, rgba(110, 232, 122, 0.18), transparent 60%),
    radial-gradient(900px 700px at 85% 85%, rgba(46, 125, 50, 0.22), transparent 65%),
    linear-gradient(135deg, #0B1F14 0%, #0E2C1B 50%, #081510 100%);
}

.parallax {
  position: fixed;
  inset: -60px;
  z-index: 1;
  pointer-events: none;
}

.blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(60px);
  opacity: 0.7;
  transition: transform 0.18s ease-out;
  will-change: transform;
}
.blob-a { width: 520px; height: 520px; top: -60px; left: -80px;
  background: radial-gradient(circle, rgba(110, 232, 122, 0.75) 0%, transparent 70%); }
.blob-b { width: 440px; height: 440px; bottom: -100px; right: -80px;
  background: radial-gradient(circle, rgba(110, 232, 122, 0.55) 0%, transparent 70%); }
.blob-c { width: 340px; height: 340px; top: 40%; right: 10%;
  background: radial-gradient(circle, rgba(46, 125, 50, 0.55) 0%, transparent 70%); }
.blob-d { width: 260px; height: 260px; bottom: 15%; left: 10%;
  background: radial-gradient(circle, rgba(168, 244, 180, 0.45) 0%, transparent 70%); }
.blob-e { width: 380px; height: 380px; top: 15%; left: 55%;
  background: radial-gradient(circle, rgba(94, 220, 107, 0.35) 0%, transparent 70%); }

.sphere {
  position: absolute;
  right: -120px;
  bottom: -80px;
  width: 420px;
  height: 420px;
  opacity: 0.35;
  transition: transform 0.18s ease-out;
  will-change: transform;
  filter: blur(0.3px);
}

.vignette {
  position: fixed;
  inset: 0;
  z-index: 1;
  pointer-events: none;
  background: radial-gradient(ellipse at center, transparent 50%, rgba(0, 0, 0, 0.45) 100%);
}

.login-card {
  backdrop-filter: blur(16px);
  background: rgba(var(--v-theme-surface), 0.95) !important;
  border: 1px solid rgba(var(--v-theme-brand), 0.3);
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
}

.logo-text {
  text-shadow: 0 2px 12px rgba(var(--v-theme-brand), 0.5);
}

@media (prefers-reduced-motion: reduce) {
  .blob, .sphere { transition: none !important; transform: none !important; }
}
</style>
