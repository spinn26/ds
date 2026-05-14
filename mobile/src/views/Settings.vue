<template>
  <div>
    <PageHeader title="Настройки" back />

    <div class="menu-group">
      <div class="menu-cell">
        <v-icon>mdi-bell-outline</v-icon>
        <div class="menu-cell-title">Push-уведомления</div>
        <v-switch v-model="push" hide-details density="compact" color="primary" inset />
      </div>
      <div class="menu-cell">
        <v-icon>mdi-email-outline</v-icon>
        <div class="menu-cell-title">E-mail рассылка</div>
        <v-switch v-model="emailNotify" hide-details density="compact" color="primary" inset />
      </div>
      <div class="menu-cell">
        <v-icon>mdi-theme-light-dark</v-icon>
        <div class="menu-cell-title">Тёмная тема</div>
        <v-switch v-model="darkMode" hide-details density="compact" color="primary" inset />
      </div>
    </div>

    <div class="menu-group">
      <div class="menu-cell">
        <v-icon>mdi-fingerprint</v-icon>
        <div class="menu-cell-title">Вход по биометрии</div>
        <v-switch v-model="biometric" hide-details density="compact" color="primary" inset />
      </div>
      <div class="menu-cell">
        <v-icon>mdi-key-variant</v-icon>
        <div class="menu-cell-title">Сменить пароль</div>
        <v-icon class="menu-cell-arrow">mdi-chevron-right</v-icon>
      </div>
      <div class="menu-cell">
        <v-icon>mdi-shield-check-outline</v-icon>
        <div class="menu-cell-title">Двухфакторная аутентификация</div>
        <v-chip size="x-small" color="success" variant="tonal">включена</v-chip>
      </div>
    </div>

    <div class="menu-group">
      <div class="menu-cell">
        <v-icon>mdi-translate</v-icon>
        <div class="menu-cell-title">Язык</div>
        <span class="text-caption text-medium-emphasis">Русский</span>
      </div>
      <div class="menu-cell">
        <v-icon>mdi-cellphone-cog</v-icon>
        <div class="menu-cell-title">Версия приложения</div>
        <span class="text-caption text-medium-emphasis">0.1.0 (1)</span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

const push = ref(true);
const emailNotify = ref(false);
const darkMode = ref(false);
const biometric = ref(true);
</script>
