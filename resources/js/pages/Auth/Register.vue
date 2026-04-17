<template>
  <div class="register-page" @mousemove="onMouseMove">
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
      <v-col cols="12" sm="10" md="7" lg="5">
        <v-card class="pa-6 register-card" elevation="16" rounded="xl">
          <div class="text-center mb-4">
            <div class="text-h3 font-weight-black text-primary">DS</div>
            <div class="text-caption text-medium-emphasis" style="letter-spacing: 4px">КОНСАЛТИНГ ПЛАТФОРМА</div>
          </div>

          <div class="text-h5 text-center mb-4">Регистрация</div>

          <v-stepper v-model="step" flat>
            <v-stepper-header>
              <v-stepper-item :value="1" title="Ввод данных" />
              <v-divider />
              <v-stepper-item :value="2" title="Проверка" />
            </v-stepper-header>

            <v-stepper-window>
              <!-- Step 1 -->
              <v-stepper-window-item :value="1">
                <v-alert v-if="error" type="error" class="mb-3" density="compact">{{ error }}</v-alert>
                <v-row dense>
                  <v-col cols="12" sm="4"><v-text-field v-model="form.lastName" label="Фамилия *" /></v-col>
                  <v-col cols="12" sm="4"><v-text-field v-model="form.firstName" label="Имя *" /></v-col>
                  <v-col cols="12" sm="4"><v-text-field v-model="form.patronymic" label="Отчество" /></v-col>
                  <v-col cols="12"><v-text-field v-model="form.email" label="Электронная почта *" type="email" prepend-inner-icon="mdi-email" /></v-col>
                  <v-col cols="12" sm="6"><v-text-field v-model="form.phone" label="Телефон" prepend-inner-icon="mdi-phone" /></v-col>
                  <v-col cols="12" sm="6"><v-text-field v-model="form.telegram" label="Телеграм" prepend-inner-icon="mdi-send" placeholder="@username" /></v-col>
                  <v-col cols="12" sm="6"><v-text-field v-model="form.birthDate" label="Дата рождения" type="date" /></v-col>
                  <v-col cols="12" sm="6"><v-text-field v-model="form.city" label="Город" prepend-inner-icon="mdi-city" /></v-col>
                  <v-col cols="12"><v-text-field v-model="form.password" label="Пароль *" :type="showPw ? 'text' : 'password'" prepend-inner-icon="mdi-lock" :append-inner-icon="showPw ? 'mdi-eye-off' : 'mdi-eye'" @click:append-inner="showPw = !showPw" /></v-col>
                  <v-col cols="12"><v-text-field v-model="form.password_confirmation" label="Подтверждение пароля *" type="password" prepend-inner-icon="mdi-lock" /></v-col>
                  <v-col cols="12">
                    <v-checkbox v-model="form.consentPersonalData" label="Согласен на обработку персональных данных *" density="compact" />
                    <v-checkbox v-model="form.consentTerms" label="Согласен с правилами использования платформы *" density="compact" />
                  </v-col>
                </v-row>
                <v-btn block color="primary" size="large" @click="nextStep" :loading="loading">Далее →</v-btn>
              </v-stepper-window-item>

              <!-- Step 2 -->
              <v-stepper-window-item :value="2">
                <v-alert type="warning" class="mb-3" density="compact">
                  <strong>Проверьте данные.</strong> ФИО после регистрации можно изменить только через техподдержку.
                </v-alert>

                <v-card variant="tonal" class="mb-3 pa-3">
                  <v-row dense>
                    <v-col v-for="item in reviewItems" :key="item.label" cols="6">
                      <div class="text-caption text-medium-emphasis">{{ item.label }}</div>
                      <div class="text-body-2 font-weight-medium">{{ item.value || '—' }}</div>
                    </v-col>
                  </v-row>
                </v-card>

                <v-card color="amber-lighten-5" class="mb-4 pa-3" variant="flat">
                  <div class="text-subtitle-2 text-amber-darken-3 mb-1">Стартовый период</div>
                  <div class="text-body-2">
                    Для активации аккаунта необходимо набрать <strong>500 баллов</strong> личного объёма за 90 дней с даты регистрации.
                  </div>
                </v-card>

                <v-alert v-if="error" type="error" class="mb-3" density="compact">{{ error }}</v-alert>

                <div class="d-flex gap-3">
                  <v-btn variant="outlined" size="large" @click="step = 1" class="flex-grow-1">Назад</v-btn>
                  <v-btn color="primary" size="large" @click="handleRegister" :loading="loading" class="flex-grow-1">
                    Завершить регистрацию
                  </v-btn>
                </div>
              </v-stepper-window-item>
            </v-stepper-window>
          </v-stepper>

          <v-divider class="my-4" />
          <div class="text-center text-body-2">
            Уже есть аккаунт?
            <router-link to="/login" class="text-primary font-weight-bold text-decoration-none">Войти</router-link>
          </div>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import api from '../../api';
import BrandWaves from '../../components/BrandWaves.vue';

const auth = useAuthStore();
const router = useRouter();
const step = ref(1);
const showPw = ref(false);
const error = ref('');
const loading = ref(false);
const form = ref({
  firstName: '', lastName: '', patronymic: '', email: '', phone: '',
  telegram: '', birthDate: '', city: '', password: '', password_confirmation: '',
  consentPersonalData: false, consentTerms: false,
});

// Parallax mouse tracking
const mx = ref(0);
const my = ref(0);
function onMouseMove(e) {
  const w = window.innerWidth;
  const h = window.innerHeight;
  mx.value = (e.clientX - w / 2) / (w / 2);
  my.value = (e.clientY - h / 2) / (h / 2);
}
function layerStyle(sx, sy) {
  return { transform: `translate3d(${mx.value * sx * 100}px, ${my.value * sy * 100}px, 0)` };
}

const reviewItems = computed(() => [
  { label: 'Фамилия', value: form.value.lastName },
  { label: 'Имя', value: form.value.firstName },
  { label: 'Отчество', value: form.value.patronymic },
  { label: 'Почта', value: form.value.email },
  { label: 'Телефон', value: form.value.phone },
  { label: 'Телеграм', value: form.value.telegram },
  { label: 'Дата рождения', value: form.value.birthDate },
  { label: 'Город', value: form.value.city },
]);

async function nextStep() {
  error.value = '';
  if (!form.value.lastName || !form.value.firstName || !form.value.email || !form.value.password) {
    error.value = 'Заполните все обязательные поля'; return;
  }
  if (form.value.password !== form.value.password_confirmation) {
    error.value = 'Пароли не совпадают'; return;
  }
  if (!form.value.consentPersonalData || !form.value.consentTerms) {
    error.value = 'Необходимо дать согласие'; return;
  }
  loading.value = true;
  try {
    const { data } = await api.post('/auth/check-duplicates', { email: form.value.email, phone: form.value.phone });
    if (data.duplicate) { error.value = data.message; return; }
    step.value = 2;
  } catch { error.value = 'Ошибка проверки'; }
  finally { loading.value = false; }
}

async function handleRegister() {
  error.value = '';
  loading.value = true;
  try {
    await auth.register(form.value);
    router.push('/');
  } catch (e) {
    error.value = e.response?.data?.message || 'Ошибка регистрации';
  } finally { loading.value = false; }
}

</script>

<style scoped>
.register-page {
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
}
.vignette {
  position: fixed;
  inset: 0;
  z-index: 1;
  pointer-events: none;
  background: radial-gradient(ellipse at center, transparent 50%, rgba(0, 0, 0, 0.45) 100%);
}
.register-card {
  backdrop-filter: blur(16px);
  background: rgba(var(--v-theme-surface), 0.95) !important;
  border: 1px solid rgba(var(--v-theme-brand), 0.3);
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
}
@media (prefers-reduced-motion: reduce) {
  .blob, .sphere { transition: none !important; transform: none !important; }
}
</style>
