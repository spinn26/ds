<template>
  <div>
    <v-card class="profile-hero" elevation="0">
      <v-avatar size="76" color="primary" class="avatar-large">
        <span class="text-h4 font-weight-bold text-white">{{ initials }}</span>
      </v-avatar>
      <div class="profile-name">{{ userName }}</div>
      <div class="profile-id">ID {{ user?.id || '—' }} · {{ user?.email || '' }}</div>
      <v-chip color="warning" size="small" variant="tonal" class="mt-2">
        <v-icon size="14" start>mdi-trophy</v-icon>
        Silver DS · 45%
      </v-chip>
    </v-card>

    <div class="menu-group mt-3">
      <div class="menu-cell" @click="$router.push('/app/qualifications')">
        <v-icon color="primary">mdi-chart-line</v-icon>
        <div class="menu-cell-title">Квалификации и прогресс</div>
        <v-icon class="menu-cell-arrow">mdi-chevron-right</v-icon>
      </div>
      <div class="menu-cell" @click="$router.push('/app/finance')">
        <v-icon color="success">mdi-cash-multiple</v-icon>
        <div class="menu-cell-title">Финансы и реестр</div>
        <v-icon class="menu-cell-arrow">mdi-chevron-right</v-icon>
      </div>
      <div class="menu-cell" @click="$router.push('/app/clients')">
        <v-icon color="info">mdi-account-group-outline</v-icon>
        <div class="menu-cell-title">Мои клиенты</div>
        <v-icon class="menu-cell-arrow">mdi-chevron-right</v-icon>
      </div>
    </div>

    <div class="menu-group">
      <div class="menu-cell" @click="$router.push('/app/requisites')">
        <v-icon>mdi-bank-outline</v-icon>
        <div class="menu-cell-title">Реквизиты для выплат</div>
        <v-icon class="menu-cell-arrow">mdi-chevron-right</v-icon>
      </div>
      <div class="menu-cell" @click="$router.push('/app/documents')">
        <v-icon>mdi-file-document-multiple-outline</v-icon>
        <div class="menu-cell-title">Документы</div>
        <v-icon class="menu-cell-arrow">mdi-chevron-right</v-icon>
      </div>
      <div class="menu-cell" @click="$router.push('/app/education')">
        <v-icon>mdi-school-outline</v-icon>
        <div class="menu-cell-title">Обучение</div>
        <v-icon class="menu-cell-arrow">mdi-chevron-right</v-icon>
      </div>
    </div>

    <div class="menu-group">
      <div class="menu-cell" @click="$router.push('/app/settings')">
        <v-icon>mdi-cog-outline</v-icon>
        <div class="menu-cell-title">Настройки</div>
        <v-icon class="menu-cell-arrow">mdi-chevron-right</v-icon>
      </div>
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
      <div class="menu-cell">
        <v-icon>mdi-information-outline</v-icon>
        <div class="menu-cell-title">О приложении</div>
        <span class="text-caption text-medium-emphasis">v0.1.0</span>
      </div>
    </div>

    <v-btn block color="error" variant="tonal" class="mt-3" prepend-icon="mdi-logout" @click="onLogout">
      Выйти из аккаунта
    </v-btn>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import api from '@/api';

const router = useRouter();
const auth = useAuthStore();
const user = computed(() => auth.user);
const userName = computed(() => {
  const u = auth.user;
  if (!u) return 'Партнёр';
  return [u.lastName, u.firstName].filter(Boolean).join(' ') || 'Партнёр';
});
const initials = computed(() => {
  const u = auth.user;
  if (!u) return 'П';
  return ((u.lastName || '?')[0] + (u.firstName || '?')[0]).toUpperCase();
});

async function onLogout() {
  // Сначала пробуем уведомить бэк — он отзывает Sanctum-токен.
  // Если сеть упала или 401 (токен уже мёртв) — всё равно чистим
  // локальную сессию и перебрасываем на логин.
  try {
    await api.post('/auth/logout');
  } catch {
    // ignore
  }
  await auth.logout();
  router.replace('/login');
}
</script>

<style scoped>
.profile-hero {
  background: linear-gradient(135deg, #2E7D32 0%, #43A047 100%);
  color: #fff;
  text-align: center;
  padding: 24px 16px 20px;
  border-radius: 16px;
  box-shadow: 0 8px 24px rgba(46, 125, 50, 0.2);
}
.avatar-large {
  border: 3px solid rgba(255, 255, 255, 0.25);
}
.profile-name {
  font-size: 20px;
  font-weight: 700;
  letter-spacing: -0.4px;
  margin-top: 10px;
}
.profile-id {
  font-size: 12px;
  opacity: 0.85;
  margin-top: 2px;
}
</style>
