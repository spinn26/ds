<template>
  <v-container class="fill-height" fluid>
    <v-row justify="center" align="center">
      <v-col cols="12" sm="8" md="5" lg="4">
        <v-card class="pa-6" elevation="8" rounded="xl">
          <div class="text-center mb-6">
            <div class="text-h3 font-weight-black text-primary">DS</div>
            <div class="text-caption text-medium-emphasis" style="letter-spacing: 4px">КОНСАЛТИНГ ПЛАТФОРМА</div>
          </div>

          <div class="text-h5 text-center mb-4">Вход в систему</div>

          <v-alert v-if="error" type="error" class="mb-4" density="compact">{{ error }}</v-alert>

          <v-form @submit.prevent="handleLogin">
            <v-text-field v-model="email" label="Электронная почта" type="email"
              prepend-inner-icon="mdi-email" required class="mb-2" />
            <v-text-field v-model="password" label="Пароль"
              :type="showPw ? 'text' : 'password'"
              prepend-inner-icon="mdi-lock"
              :append-inner-icon="showPw ? 'mdi-eye-off' : 'mdi-eye'"
              @click:append-inner="showPw = !showPw"
              required class="mb-4" />
            <v-btn type="submit" block size="large" color="primary" :loading="loading" class="mb-4">
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
