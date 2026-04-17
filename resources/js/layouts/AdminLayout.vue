<template>
  <v-layout>
    <v-navigation-drawer v-model="drawer" :permanent="!mobile" :temporary="mobile" width="280" color="grey-darken-4" theme="dark">
      <div class="d-flex align-center pa-4">
        <div class="admin-brand-mark mr-2">
          <BrandWaves shape="circle" :width="32" :height="32"
            bg-color="#6EE87A" stroke-color="#000000"
            :rows="10" :columns="14" :amplitude="3" :frequency="1.0"
            :stroke-width="0.8" :stroke-opacity="0.8" />
        </div>
        <div>
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

      <v-list density="compact" nav>
        <v-list-item to="/" prepend-icon="mdi-arrow-left" title="На сайт" color="grey-lighten-1" rounded="lg" class="mb-2" />
        <v-divider class="mb-2" />

        <v-list-item to="/admin/dashboard" prepend-icon="mdi-chart-areaspline" title="Дашборд" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/users" prepend-icon="mdi-account-cog" title="Пользователи" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/news" prepend-icon="mdi-newspaper" title="Новости" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/products" prepend-icon="mdi-package-variant" title="Продукты" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/education" prepend-icon="mdi-school" title="Обучение и тесты" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/contests" prepend-icon="mdi-trophy" title="Конкурсы и события" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/references" prepend-icon="mdi-book-cog" title="Справочники" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/mail" prepend-icon="mdi-email-fast" title="Почтовая рассылка" color="secondary" rounded="lg" class="mb-1" />
      </v-list>
    </v-navigation-drawer>

    <v-app-bar flat border="b" color="grey-darken-4" theme="dark">
      <v-app-bar-nav-icon v-if="mobile" @click="drawer = !drawer" />
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
</style>
