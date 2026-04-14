<template>
  <v-layout>
    <v-navigation-drawer v-model="drawer" :permanent="!mobile" :temporary="mobile" width="280" color="grey-darken-4" theme="dark">
      <div class="d-flex align-center pa-4">
        <v-icon color="secondary" size="24" class="mr-2">mdi-shield-crown</v-icon>
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
import { ref, computed } from 'vue';
import { useDisplay } from 'vuetify';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const { mobile } = useDisplay();
const drawer = ref(true);

const initials = computed(() =>
  `${auth.user?.firstName?.[0] || ''}${auth.user?.lastName?.[0] || ''}`.toUpperCase()
);
</script>
