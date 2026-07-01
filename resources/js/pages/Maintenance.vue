<template>
  <div class="maint-wrap">
    <div class="maint-card">
      <v-icon size="64" color="warning" class="mb-4">mdi-wrench-clock</v-icon>
      <h1 class="text-h5 font-weight-bold mb-2">Технические работы</h1>
      <p class="text-body-1 text-medium-emphasis" style="max-width: 480px; white-space: pre-line">{{ message }}</p>

      <div v-if="countdown" class="countdown mt-6">
        <div class="text-caption text-medium-emphasis mb-1">До завершения обновления</div>
        <div class="countdown-value">{{ countdown }}</div>
      </div>
      <div v-else-if="endsPassed" class="mt-6 text-body-2 text-medium-emphasis">
        Завершаем последние шаги…
      </div>

      <v-btn class="mt-6" variant="tonal" color="primary" prepend-icon="mdi-refresh" @click="retry">
        Обновить
      </v-btn>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import api from '../api';

const message = ref('');
try { message.value = sessionStorage.getItem('maintenance_message') || 'Идут технические работы. Скоро вернёмся.'; } catch { message.value = 'Идут технические работы.'; }

const endsAtMs = ref(null);   // целевое время (мс)
const serverOffset = ref(0);  // серверное время - клиентское (мс)
const nowTick = ref(Date.now());
const endsPassed = ref(false);

let pollTimer = null;
let tickTimer = null;

// Оценка серверного «сейчас» — чтобы отсчёт не зависел от кривых часов клиента.
const estServerNow = computed(() => nowTick.value + serverOffset.value);
const remainingMs = computed(() => (endsAtMs.value ? endsAtMs.value - estServerNow.value : null));

const countdown = computed(() => {
  const ms = remainingMs.value;
  if (ms === null || ms <= 0) return '';
  const s = Math.floor(ms / 1000);
  const d = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = s % 60;
  const pad = (n) => String(n).padStart(2, '0');
  return (d > 0 ? `${d}д ` : '') + `${pad(h)}:${pad(m)}:${pad(sec)}`;
});

async function fetchStatus() {
  try {
    const { data } = await api.get('/maintenance');
    // Режим уже выключен — впускаем обратно.
    if (!data.enabled) { window.location.href = '/'; return; }
    if (data.message) { message.value = data.message; }
    if (data.server_time) { serverOffset.value = new Date(data.server_time).getTime() - Date.now(); }
    endsAtMs.value = data.ends_at ? new Date(data.ends_at).getTime() : null;
    endsPassed.value = endsAtMs.value !== null && (endsAtMs.value - (Date.now() + serverOffset.value)) <= 0;
  } catch { /* сеть/сервер недоступны — просто ждём следующий поллинг */ }
}

function retry() { window.location.href = '/'; }

onMounted(() => {
  fetchStatus();
  pollTimer = setInterval(fetchStatus, 15000);
  tickTimer = setInterval(() => {
    nowTick.value = Date.now();
    if (endsAtMs.value !== null) endsPassed.value = remainingMs.value <= 0;
  }, 1000);
});

onBeforeUnmount(() => {
  if (pollTimer) clearInterval(pollTimer);
  if (tickTimer) clearInterval(tickTimer);
});
</script>

<style scoped>
.maint-wrap {
  min-height: 100vh; display: flex; align-items: center; justify-content: center;
  background: rgb(var(--v-theme-background)); padding: 24px;
}
.maint-card { text-align: center; display: flex; flex-direction: column; align-items: center; }
.countdown-value {
  font-size: 2.4rem; font-weight: 700; line-height: 1.1;
  font-variant-numeric: tabular-nums; color: rgb(var(--v-theme-primary));
}
</style>
