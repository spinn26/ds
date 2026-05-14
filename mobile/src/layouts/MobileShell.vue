<template>
  <div class="shell">
    <header class="shell-header">
      <div class="shell-header-titles">
        <div class="text-caption text-medium-emphasis">{{ greeting }},</div>
        <div class="shell-name">{{ userName }}</div>
      </div>
      <div class="d-flex align-center ga-1">
        <v-btn icon="mdi-bell-outline" size="small" variant="text" @click="router.push('/app/notifications')">
          <v-icon>mdi-bell-outline</v-icon>
          <span v-if="unreadCount > 0" class="badge">{{ unreadCount }}</span>
        </v-btn>
        <v-btn icon size="small" variant="text" @click="router.push('/app/profile')">
          <v-avatar size="32" color="primary">
            <span class="text-caption text-white font-weight-bold">{{ initials }}</span>
          </v-avatar>
        </v-btn>
      </div>
    </header>

    <main class="shell-main">
      <router-view v-slot="{ Component }">
        <transition name="fade" mode="out-in">
          <component :is="Component" />
        </transition>
      </router-view>
    </main>

    <nav class="tabbar">
      <router-link v-for="t in tabs" :key="t.path" :to="t.path" class="tab"
        :class="{ active: isActive(t.path) }">
        <v-icon size="22">{{ t.icon }}</v-icon>
        <span>{{ t.label }}</span>
      </router-link>
    </nav>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const tabs = [
  { path: '/app/home', label: 'Главная', icon: 'mdi-home-variant' },
  { path: '/app/transactions', label: 'Транзакции', icon: 'mdi-swap-horizontal' },
  { path: '/app/structure', label: 'Структура', icon: 'mdi-account-tree' },
  { path: '/app/chat', label: 'Чат', icon: 'mdi-message-outline' },
  { path: '/app/profile', label: 'Профиль', icon: 'mdi-account-circle-outline' },
];

const isActive = (path: string) => route.path.startsWith(path);

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

const greeting = computed(() => {
  const h = new Date().getHours();
  if (h < 5) return 'Доброй ночи';
  if (h < 12) return 'Доброе утро';
  if (h < 18) return 'Добрый день';
  return 'Добрый вечер';
});

const unreadCount = 3; // моковая цифра; заменим на реальный счётчик уведомлений
</script>

<style scoped>
.shell {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  background: rgb(var(--v-theme-background));
}
.shell-header {
  position: sticky;
  top: 0;
  z-index: 5;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: max(12px, env(safe-area-inset-top)) 16px 10px;
  background: rgba(var(--v-theme-surface), 0.96);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}
.shell-name {
  font-size: 18px;
  font-weight: 700;
  color: rgb(var(--v-theme-on-surface));
  letter-spacing: -0.3px;
}
.badge {
  position: absolute;
  top: 4px;
  right: 4px;
  background: rgb(var(--v-theme-error));
  color: #fff;
  font-size: 9px;
  font-weight: 700;
  padding: 1px 5px;
  border-radius: 8px;
  line-height: 1.4;
}

.shell-main {
  flex: 1;
  padding: 14px 16px calc(72px + max(14px, env(safe-area-inset-bottom)));
  overflow-x: hidden;
}

.tabbar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  height: calc(60px + env(safe-area-inset-bottom));
  padding-bottom: env(safe-area-inset-bottom);
  display: flex;
  background: rgba(var(--v-theme-surface), 0.97);
  backdrop-filter: blur(16px);
  border-top: 1px solid rgba(0, 0, 0, 0.06);
  z-index: 10;
}
.tab {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  text-decoration: none;
  font-size: 10px;
  font-weight: 500;
  color: rgba(var(--v-theme-on-surface), 0.5);
  transition: color 0.15s ease;
}
.tab.active {
  color: rgb(var(--v-theme-primary));
}
.tab:active {
  transform: scale(0.95);
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
