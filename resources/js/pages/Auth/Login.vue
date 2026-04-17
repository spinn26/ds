<template>
  <div class="login-page">
    <!-- Brand wave pattern background (DS identity 2023) -->
    <div class="bg-brand">
      <BrandWaves :width="1800" :height="1000" shape="sheet"
        bg-color="#6EE87A" stroke-color="#ffffff"
        :rows="32" :columns="40" :amplitude="30" :frequency="1.15"
        :stroke-opacity="0.9" :stroke-width="1.4" />
    </div>

    <v-container class="fill-height position-relative" fluid style="z-index:1">
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

.bg-brand {
  position: fixed;
  inset: 0;
  z-index: 0;
  background: rgb(var(--v-theme-brand));
}
.bg-brand :deep(.brand-waves) { width: 100%; height: 100%; }

.login-card {
  backdrop-filter: blur(14px);
  background: rgba(var(--v-theme-surface), 0.94) !important;
  border: 1px solid rgba(var(--v-theme-brand), 0.4);
}

.logo-text {
  text-shadow: 0 2px 8px rgba(var(--v-theme-primary), 0.35);
}
</style>
