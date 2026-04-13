<template>
  <div class="register-page">
    <div class="bg-animated">
      <div class="bg-gradient"></div>
      <div class="bg-circles">
        <div v-for="n in 12" :key="n" class="circle" :style="circleStyle(n)"></div>
      </div>
      <div class="bg-grid"></div>
    </div>
  <v-container class="fill-height position-relative" fluid style="z-index:1">
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

function circleStyle(n) {
  const size = 40 + Math.random() * 120;
  return {
    width: `${size}px`, height: `${size}px`,
    left: `${Math.random() * 100}%`, top: `${Math.random() * 100}%`,
    animationDuration: `${15 + Math.random() * 25}s`,
    animationDelay: `${Math.random() * -20}s`,
    opacity: 0.03 + Math.random() * 0.08,
  };
}
</script>

<style scoped>
.register-page { position: relative; min-height: 100vh; overflow: hidden; }
.bg-animated { position: fixed; inset: 0; z-index: 0; }
.bg-gradient { position: absolute; inset: 0; background: linear-gradient(135deg, #0d1b2a 0%, #1b2d45 30%, #1a3a2a 60%, #0d2818 100%); animation: gradientShift 20s ease infinite; background-size: 400% 400%; }
@keyframes gradientShift { 0%, 100% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } }
.bg-circles { position: absolute; inset: 0; }
.circle { position: absolute; border-radius: 50%; background: radial-gradient(circle, rgba(76,175,80,0.6) 0%, transparent 70%); animation: float linear infinite; pointer-events: none; }
@keyframes float { 0% { transform: translate(0,0) scale(1); } 25% { transform: translate(30px,-50px) scale(1.1); } 50% { transform: translate(-20px,-100px) scale(0.9); } 75% { transform: translate(40px,-50px) scale(1.05); } 100% { transform: translate(0,0) scale(1); } }
.bg-grid { position: absolute; inset: 0; background-image: linear-gradient(rgba(76,175,80,0.05) 1px, transparent 1px), linear-gradient(90deg, rgba(76,175,80,0.05) 1px, transparent 1px); background-size: 60px 60px; animation: gridPan 30s linear infinite; }
@keyframes gridPan { 0% { transform: translate(0,0); } 100% { transform: translate(60px,60px); } }
.register-card { backdrop-filter: blur(20px); background: rgba(255,255,255,0.97) !important; border: 1px solid rgba(76,175,80,0.15); color: #333 !important; }
.register-card :deep(.v-field__input), .register-card :deep(.v-label), .register-card :deep(.v-field__field) { color: #333 !important; }
.register-card :deep(.v-icon) { color: #666 !important; }
</style>
