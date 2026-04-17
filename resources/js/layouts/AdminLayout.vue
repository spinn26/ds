<template>
  <v-layout>
    <v-navigation-drawer v-model="drawer" :permanent="!mobile" :temporary="mobile"
      :rail="rail && !mobile" :width="280" :rail-width="72"
      color="grey-darken-4" theme="dark" class="admin-drawer">
      <div class="d-flex align-center pa-4 drawer-brand">
        <div class="admin-brand-mark" :class="{ 'mr-2': !rail }">
          <BrandWaves shape="circle" :width="32" :height="32"
            bg-color="#6EE87A" stroke-color="#000000"
            :rows="10" :columns="14" :amplitude="3" :frequency="1.0"
            :stroke-width="0.8" :stroke-opacity="0.8" />
        </div>
        <div v-if="!rail">
          <div class="d-flex align-center ga-1">
            <span class="text-h6 font-weight-black text-white">DS</span>
            <span class="text-caption text-grey-lighten-1">УПРАВЛЕНИЕ</span>
          </div>
          <div style="font-size: 0.55rem; letter-spacing: 1.5px; color: rgba(255,255,255,0.35); margin-top: -2px">
            ПАНЕЛЬ УПРАВЛЕНИЯ
          </div>
        </div>
      </div>
      <v-divider />

      <v-list density="compact" nav class="admin-nav-list">
        <v-list-item to="/" prepend-icon="mdi-arrow-left" title="На сайт" color="grey-lighten-1" />
        <v-divider />

        <v-list-item v-for="item in menuItems" :key="item.to"
          :to="item.to" :prepend-icon="item.icon" :title="item.title" color="secondary" />
      </v-list>

      <template #append>
        <v-divider />
        <v-list density="compact" nav class="admin-nav-list">
          <v-list-item :prepend-icon="rail ? 'mdi-chevron-right' : 'mdi-chevron-left'"
            :title="rail ? '' : 'Свернуть меню'"
            color="grey-lighten-1"
            @click="toggleRail" />
        </v-list>
      </template>
    </v-navigation-drawer>

    <v-app-bar flat border="b" color="grey-darken-4" theme="dark">
      <v-app-bar-nav-icon v-if="mobile" @click="drawer = !drawer" />
      <v-btn v-else icon size="small" class="mr-1" @click="toggleRail">
        <v-icon>{{ rail ? 'mdi-menu' : 'mdi-menu-open' }}</v-icon>
      </v-btn>
      <v-toolbar-title class="text-body-1">
        <v-icon icon="mdi-shield-crown" color="secondary" class="mr-1" /> DS Управление
      </v-toolbar-title>
      <v-spacer />
      <span class="text-body-2 text-grey-lighten-1 mr-3">{{ auth.user?.firstName }} {{ auth.user?.lastName }}</span>
      <v-menu>
        <template #activator="{ props }">
          <v-avatar v-bind="props" color="secondary" size="32" class="cursor-pointer">
            <span class="text-caption text-white">{{ initials }}</span>
          </v-avatar>
        </template>
        <v-list density="compact">
          <v-list-item to="/" prepend-icon="mdi-home" title="На сайт" />
          <v-list-item @click="auth.logout(); $router.push('/login')" prepend-icon="mdi-logout" title="Выйти" />
        </v-list>
      </v-menu>
    </v-app-bar>

    <v-main>
      <v-container fluid class="pa-4 pa-md-6">
        <router-view />
      </v-container>
    </v-main>
  </v-layout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useDisplay, useTheme } from 'vuetify';
import { useAuthStore } from '../stores/auth';
import BrandWaves from '../components/BrandWaves.vue';

const auth = useAuthStore();
const { mobile } = useDisplay();
const theme = useTheme();

// Admin always dark
let prevTheme = '';
onMounted(() => {
  prevTheme = theme.global.name.value;
  theme.global.name.value = 'dark';
});
onUnmounted(() => {
  theme.global.name.value = prevTheme || localStorage.getItem('theme') || 'dark';
});
const drawer = ref(true);

// Rail (minimalist) mode — persists across sessions
const rail = ref(localStorage.getItem('admin-nav-rail') === '1');
function toggleRail() {
  rail.value = !rail.value;
  localStorage.setItem('admin-nav-rail', rail.value ? '1' : '0');
}

const menuItems = [
  { to: '/admin/dashboard', icon: 'mdi-chart-areaspline', title: 'Дашборд' },
  { to: '/admin/users', icon: 'mdi-account-cog', title: 'Пользователи' },
  { to: '/admin/news', icon: 'mdi-newspaper', title: 'Новости' },
  { to: '/admin/products', icon: 'mdi-package-variant', title: 'Продукты' },
  { to: '/admin/education', icon: 'mdi-school', title: 'Обучение и тесты' },
  { to: '/admin/contests', icon: 'mdi-trophy', title: 'Конкурсы и события' },
  { to: '/admin/references', icon: 'mdi-book-cog', title: 'Справочники' },
  { to: '/admin/mail', icon: 'mdi-email-fast', title: 'Почтовая рассылка' },
  { to: '/admin/monitoring', icon: 'mdi-pulse', title: 'Мониторинг' },
];

const initials = computed(() =>
  `${auth.user?.firstName?.[0] || ''}${auth.user?.lastName?.[0] || ''}`.toUpperCase()
);
</script>

<style scoped>
.admin-brand-mark {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  overflow: hidden;
  flex-shrink: 0;
  box-shadow: 0 0 0 2px rgba(var(--v-theme-brand), 0.35);
}

/* Remove rounded corners from nav items — square-edge look */
.admin-nav-list :deep(.v-list-item) {
  border-radius: 0 !important;
}

/* Rail mode: center icon, hide label gap */
.admin-drawer :deep(.v-navigation-drawer--rail) .drawer-brand {
  justify-content: center;
  padding: 16px 0 !important;
}
.admin-drawer :deep(.v-navigation-drawer--rail) .admin-brand-mark {
  margin-right: 0 !important;
}
</style>
