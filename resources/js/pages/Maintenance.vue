<template>
  <div class="maint-wrap">
    <!-- Анимированный фирменный фон (BrandWaves) -->
    <div class="maint-bg" aria-hidden="true">
      <BrandWaves class="wave wave-1" :width="1600" :height="900" shape="sheet"
        bg-color="transparent" stroke-color="#6EE87A" :stroke-opacity="0.9" :stroke-width="1.1"
        :rows="26" :columns="34" :amplitude="30" :frequency="1.3" />
      <BrandWaves class="wave wave-2" :width="1600" :height="900" shape="sheet"
        bg-color="transparent" stroke-color="#2E7D32" :stroke-opacity="0.9" :stroke-width="1.5"
        :rows="16" :columns="22" :amplitude="46" :frequency="0.85" />
      <div class="glow" />
    </div>

    <div class="maint-card">
      <div class="icon-badge">
        <v-icon size="42" color="warning">mdi-wrench-clock</v-icon>
      </div>
      <h1 class="text-h4 font-weight-bold mb-2">Технические работы</h1>
      <p class="maint-msg">{{ message }}</p>

      <div v-if="countdown" class="countdown mt-8">
        <div class="cd-label">До завершения обновления</div>
        <div class="cd-value">{{ countdown }}</div>
      </div>
      <div v-else-if="endsPassed" class="mt-8 text-body-2 text-medium-emphasis">
        Завершаем последние шаги…
      </div>

      <div class="maint-actions mt-8">
        <v-btn size="large" variant="flat" color="primary"
          prepend-icon="mdi-refresh" @click="retry">
          Обновить
        </v-btn>
        <v-btn v-if="loggedIn" size="large" variant="text"
          prepend-icon="mdi-logout" @click="logout">
          Выйти
        </v-btn>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import api from '../api';
import BrandWaves from '../components/BrandWaves.vue';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const loggedIn = computed(() => !!(auth.token || auth.user));
function logout() { auth.logout(); window.location.href = '/login'; }

const message = ref('');
try { message.value = sessionStorage.getItem('maintenance_message') || 'Идут технические работы. Скоро вернёмся.'; } catch { message.value = 'Идут технические работы.'; }

const endsAtMs = ref(null);   // целевое время (мс)
const serverOffset = ref(0);  // серверное время - клиентское (мс)
const nowTick = ref(Date.now());
const endsPassed = ref(false);

let pollTimer = null;
let tickTimer = null;

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
    if (!data.enabled) { window.location.href = '/'; return; }
    if (data.message) { message.value = data.message; }
    if (data.server_time) { serverOffset.value = new Date(data.server_time).getTime() - Date.now(); }
    endsAtMs.value = data.ends_at ? new Date(data.ends_at).getTime() : null;
    endsPassed.value = endsAtMs.value !== null && (endsAtMs.value - (Date.now() + serverOffset.value)) <= 0;
  } catch { /* сеть/сервер недоступны — ждём следующий поллинг */ }
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
  position: relative;
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  overflow: hidden;
  background:
    radial-gradient(120% 90% at 50% -10%, #0e1c12 0%, #08120b 45%, #050806 100%);
}

/* ---- animated background ---- */
.maint-bg { position: absolute; inset: 0; z-index: 0; pointer-events: none; }
.wave {
  position: absolute;
  top: -20%; left: -20%;
  width: 140%; height: 140%;
  will-change: transform;
}
.wave-1 { opacity: 0.16; animation: drift1 42s ease-in-out infinite; }
.wave-2 { opacity: 0.10; animation: drift2 64s ease-in-out infinite; }
.glow {
  position: absolute;
  left: 50%; top: 50%;
  width: 900px; height: 900px;
  transform: translate(-50%, -50%);
  background: radial-gradient(circle, rgba(110, 232, 122, 0.16) 0%, rgba(110, 232, 122, 0.05) 35%, transparent 68%);
  filter: blur(8px);
  animation: breathe 9s ease-in-out infinite;
}

/* ---- card ---- */
.maint-card {
  position: relative;
  z-index: 1;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  max-width: 540px;
  width: 100%;
  padding: 48px 40px;
  border-radius: 24px;
  background: rgba(12, 20, 14, 0.55);
  border: 1px solid rgba(110, 232, 122, 0.14);
  box-shadow: 0 24px 80px rgba(0, 0, 0, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.04);
  backdrop-filter: blur(14px) saturate(120%);
  -webkit-backdrop-filter: blur(14px) saturate(120%);
  color: #eef6f0;
  animation: cardIn 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.icon-badge {
  width: 84px; height: 84px;
  display: flex; align-items: center; justify-content: center;
  border-radius: 22px;
  margin-bottom: 20px;
  background: rgba(255, 167, 38, 0.10);
  border: 1px solid rgba(255, 167, 38, 0.22);
  animation: floaty 4.5s ease-in-out infinite;
}
.maint-msg {
  color: rgba(238, 246, 240, 0.66);
  font-size: 1.02rem;
  line-height: 1.5;
  max-width: 420px;
  margin: 0 auto;
  white-space: pre-line;
}
.maint-actions { display: flex; gap: 12px; flex-wrap: wrap; justify-content: center; }
.countdown { display: flex; flex-direction: column; align-items: center; }
.cd-label {
  text-transform: uppercase;
  letter-spacing: 0.14em;
  font-size: 0.7rem;
  color: rgba(238, 246, 240, 0.45);
  margin-bottom: 6px;
}
.cd-value {
  font-size: 3.4rem;
  font-weight: 800;
  line-height: 1;
  font-variant-numeric: tabular-nums;
  color: #6EE87A;
  text-shadow: 0 0 32px rgba(110, 232, 122, 0.35);
  animation: pulse 2.4s ease-in-out infinite;
}

@keyframes drift1 {
  0%   { transform: translate(-3%, -2%) scale(1.06) rotate(0deg); }
  50%  { transform: translate(2%, 3%) scale(1.12) rotate(1.4deg); }
  100% { transform: translate(-3%, -2%) scale(1.06) rotate(0deg); }
}
@keyframes drift2 {
  0%   { transform: translate(3%, 2%) scale(1.14) rotate(0deg); }
  50%  { transform: translate(-3%, -3%) scale(1.2) rotate(-2deg); }
  100% { transform: translate(3%, 2%) scale(1.14) rotate(0deg); }
}
@keyframes breathe {
  0%, 100% { opacity: 0.7; transform: translate(-50%, -50%) scale(1); }
  50%      { opacity: 1;   transform: translate(-50%, -50%) scale(1.08); }
}
@keyframes floaty {
  0%, 100% { transform: translateY(0); }
  50%      { transform: translateY(-7px); }
}
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50%      { opacity: 0.82; }
}
@keyframes cardIn {
  from { opacity: 0; transform: translateY(16px) scale(0.98); }
  to   { opacity: 1; transform: none; }
}

@media (max-width: 600px) {
  .maint-card { padding: 36px 22px; border-radius: 18px; }
  .cd-value { font-size: 2.6rem; }
}
@media (prefers-reduced-motion: reduce) {
  .wave, .glow, .icon-badge, .cd-value, .maint-card { animation: none !important; }
}
</style>
