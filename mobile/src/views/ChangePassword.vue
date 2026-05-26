<template>
  <div>
    <PageHeader title="Сменить пароль" back />

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>
    <v-alert v-if="success" type="success" variant="tonal" density="compact" class="mb-3">
      {{ success }}
    </v-alert>

    <v-card class="detail-card" elevation="0">
      <form @submit.prevent="onSubmit" novalidate>
        <v-text-field v-model="form.current_password" label="Текущий пароль"
          :type="showCurrent ? 'text' : 'password'" autocomplete="current-password"
          prepend-inner-icon="mdi-lock-outline"
          :append-inner-icon="showCurrent ? 'mdi-eye-off' : 'mdi-eye'"
          :error-messages="errors.current"
          @click:append-inner="showCurrent = !showCurrent" />

        <v-text-field v-model="form.new_password" label="Новый пароль"
          :type="showNew ? 'text' : 'password'" autocomplete="new-password"
          prepend-inner-icon="mdi-lock-plus-outline"
          :append-inner-icon="showNew ? 'mdi-eye-off' : 'mdi-eye'"
          :error-messages="errors.new"
          @click:append-inner="showNew = !showNew" />

        <v-text-field v-model="form.new_password_confirmation" label="Повторите новый"
          :type="showNew ? 'text' : 'password'" autocomplete="new-password"
          prepend-inner-icon="mdi-lock-check-outline"
          :error-messages="errors.confirm" />

        <div class="strength-row">
          <span class="text-caption text-medium-emphasis">Надёжность:</span>
          <div class="strength-bar">
            <div class="strength-fill" :style="{ width: strength.percent + '%', background: strength.color }" />
          </div>
          <span class="text-caption" :style="{ color: strength.color }">{{ strength.label }}</span>
        </div>

        <v-btn type="button" color="primary" size="large" block class="mt-3"
          :loading="submitting" :disabled="!canSubmit" @click="onSubmit">
          Изменить пароль
        </v-btn>
      </form>
    </v-card>

    <v-card class="detail-card mt-3" elevation="0">
      <div class="text-caption text-medium-emphasis">
        Минимум 8 символов. Рекомендуем использовать цифры, спецсимволы и заглавные буквы.
        После смены пароля все сессии на других устройствах будут завершены.
      </div>
    </v-card>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

const router = useRouter();
const form = reactive({ current_password: '', new_password: '', new_password_confirmation: '' });
const errors = reactive<{ current?: string; new?: string; confirm?: string }>({});
const showCurrent = ref(false);
const showNew = ref(false);
const submitting = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);

const strength = computed(() => {
  const p = form.new_password || '';
  let score = 0;
  if (p.length >= 8) score++;
  if (p.length >= 12) score++;
  if (/\d/.test(p)) score++;
  if (/[A-Z]/.test(p) && /[a-z]/.test(p)) score++;
  if (/[^A-Za-z0-9]/.test(p)) score++;
  const map = [
    { label: '—', color: '#aaa', percent: 0 },
    { label: 'Слабый', color: '#E53935', percent: 25 },
    { label: 'Так себе', color: '#FB8C00', percent: 45 },
    { label: 'Нормально', color: '#FBC02D', percent: 65 },
    { label: 'Хорошо', color: '#43A047', percent: 85 },
    { label: 'Отлично', color: '#2E7D32', percent: 100 },
  ];
  return map[Math.min(score, 5)];
});

const canSubmit = computed(() =>
  form.current_password.length >= 4 &&
  form.new_password.length >= 8 &&
  form.new_password === form.new_password_confirmation,
);

async function onSubmit() {
  errors.current = errors.new = errors.confirm = undefined;
  error.value = null;
  if (form.new_password !== form.new_password_confirmation) {
    errors.confirm = 'Пароли не совпадают';
    return;
  }
  if (form.new_password.length < 8) {
    errors.new = 'Минимум 8 символов';
    return;
  }
  submitting.value = true;
  try {
    await api.post('/profile/password', form);
    success.value = 'Пароль изменён. Через несколько секунд вы будете перенаправлены на вход.';
    setTimeout(() => router.push('/login'), 2000);
  } catch (e: any) {
    const data = e?.response?.data;
    if (data?.errors) {
      errors.current = data.errors.current_password?.[0];
      errors.new = data.errors.new_password?.[0];
      errors.confirm = data.errors.new_password_confirmation?.[0];
    }
    error.value = data?.message || 'Не удалось изменить пароль';
  } finally {
    submitting.value = false;
  }
}
</script>

<style scoped>
.detail-card { background: #fff; border-radius: 14px; padding: 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.strength-row { display: flex; align-items: center; gap: 8px; margin-top: 4px; }
.strength-bar { flex: 1; height: 4px; background: rgba(0,0,0,0.06); border-radius: 2px; overflow: hidden; }
.strength-fill { height: 100%; transition: width 0.2s, background 0.2s; }
</style>
