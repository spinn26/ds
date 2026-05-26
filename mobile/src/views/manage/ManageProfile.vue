<template>
  <div>
    <v-card class="profile-hero" elevation="0">
      <v-avatar size="76" color="warning" class="avatar-large">
        <span class="text-h4 font-weight-bold text-white">{{ initials }}</span>
      </v-avatar>
      <div class="profile-name">{{ userName }}</div>
      <div class="profile-id">ID {{ user?.id || '—' }} · {{ user?.email || '' }}</div>
      <v-chip color="warning" size="small" variant="tonal" class="mt-2">
        <v-icon size="14" start>mdi-shield-account-outline</v-icon>
        {{ roleLabel }}
      </v-chip>
    </v-card>

    <div class="menu-group mt-3">
      <div class="menu-cell" @click="$router.push('/manage/reports')">
        <v-icon>mdi-file-chart-outline</v-icon>
        <div class="menu-cell-title">Мои отчёты</div>
        <v-icon class="menu-cell-arrow">mdi-chevron-right</v-icon>
      </div>
      <div class="menu-cell">
        <v-icon>mdi-bell-outline</v-icon>
        <div class="menu-cell-title">Уведомления</div>
        <v-icon class="menu-cell-arrow">mdi-chevron-right</v-icon>
      </div>
    </div>

    <div class="menu-group">
      <div class="menu-cell" @click="$router.push('/app/security')">
        <v-icon>mdi-shield-lock-outline</v-icon>
        <div class="menu-cell-title">Безопасность и 2FA</div>
        <v-icon class="menu-cell-arrow">mdi-chevron-right</v-icon>
      </div>
      <div class="menu-cell" @click="$router.push('/app/password')">
        <v-icon>mdi-key-variant</v-icon>
        <div class="menu-cell-title">Сменить пароль</div>
        <v-icon class="menu-cell-arrow">mdi-chevron-right</v-icon>
      </div>
      <div class="menu-cell" @click="$router.push('/app/audit')">
        <v-icon>mdi-history</v-icon>
        <div class="menu-cell-title">Журнал действий</div>
        <v-icon class="menu-cell-arrow">mdi-chevron-right</v-icon>
      </div>
    </div>

    <div class="menu-group">
      <div class="menu-cell">
        <v-icon>mdi-theme-light-dark</v-icon>
        <div class="menu-cell-title">Тёмная тема</div>
        <v-switch v-model="darkMode" hide-details density="compact" color="warning" inset />
      </div>
      <div class="menu-cell">
        <v-icon>mdi-cellphone-cog</v-icon>
        <div class="menu-cell-title">Версия приложения</div>
        <span class="text-caption text-medium-emphasis">0.1.0 (1)</span>
      </div>
    </div>

    <v-btn block color="error" variant="tonal" class="mt-3" prepend-icon="mdi-logout" @click="onLogout">
      Выйти из аккаунта
    </v-btn>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import api from '@/api';

const router = useRouter();
const auth = useAuthStore();
const user = computed(() => auth.user);
const darkMode = ref(false);

const userName = computed(() => {
  const u = auth.user;
  if (!u) return 'Сотрудник';
  return [u.lastName, u.firstName].filter(Boolean).join(' ') || 'Сотрудник';
});
const initials = computed(() => {
  const u = auth.user;
  if (!u) return 'А';
  return ((u.lastName || '?')[0] + (u.firstName || '?')[0]).toUpperCase();
});
const roleLabel = computed(() => {
  const labels: Record<string, string> = {
    admin: 'Администратор',
    support: 'Тех. поддержка',
    head: 'Руководитель',
    business: 'Бизнес-подразделение',
    finance: 'Финансы',
    calculations: 'Расчётчик',
    corrections: 'Корректировки',
    backoffice: 'Бэк-офис',
    accounting: 'Бухгалтерия',
    legal: 'Юрист',
    owner: 'Собственник',
    staff: 'Сотрудник',
  };
  return labels[auth.user?.role || ''] || 'Сотрудник';
});

async function onLogout() {
  try { await api.post('/auth/logout'); } catch { /* ignore */ }
  await auth.logout();
  router.replace('/login');
}
</script>

<style scoped>
.profile-hero {
  background: linear-gradient(135deg, #FB8C00 0%, #FFCA28 100%);
  color: #fff;
  text-align: center;
  padding: 24px 16px 20px;
  border-radius: 16px;
  box-shadow: 0 8px 24px rgba(251, 140, 0, 0.2);
}
.avatar-large { border: 3px solid rgba(255, 255, 255, 0.25); }
.profile-name { font-size: 20px; font-weight: 700; letter-spacing: -0.4px; margin-top: 10px; }
.profile-id { font-size: 12px; opacity: 0.85; margin-top: 2px; }
</style>
