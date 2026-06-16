<template>
  <div class="maint-wrap">
    <div class="maint-card">
      <v-icon size="64" color="warning" class="mb-4">mdi-wrench-clock</v-icon>
      <h1 class="text-h5 font-weight-bold mb-2">Технические работы</h1>
      <p class="text-body-1 text-medium-emphasis" style="max-width: 480px; white-space: pre-line">{{ message }}</p>
      <v-btn class="mt-6" variant="tonal" color="primary" prepend-icon="mdi-refresh" @click="retry">
        Обновить
      </v-btn>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
const message = ref('');
try { message.value = sessionStorage.getItem('maintenance_message') || 'Идут технические работы. Скоро вернёмся.'; } catch { message.value = 'Идут технические работы.'; }
function retry() { window.location.href = '/'; }
</script>

<style scoped>
.maint-wrap {
  min-height: 100vh; display: flex; align-items: center; justify-content: center;
  background: rgb(var(--v-theme-background)); padding: 24px;
}
.maint-card { text-align: center; display: flex; flex-direction: column; align-items: center; }
</style>
