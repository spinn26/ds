<template>
  <v-card class="pa-4">
    <div class="d-flex align-center justify-space-between mb-3">
      <div class="text-subtitle-1 font-weight-bold">
        <v-icon class="mr-1" size="20" color="info">mdi-trophy-outline</v-icon>
        Мой день
      </div>
      <span class="text-caption text-medium-emphasis">{{ todayStr }}</span>
    </div>

    <v-row dense>
      <v-col v-for="m in metrics" :key="m.key" cols="6">
        <div class="metric-tile" :class="['tile-' + m.color]">
          <v-icon :color="m.color" size="22" class="mb-1">{{ m.icon }}</v-icon>
          <div class="metric-value">{{ data[m.key] ?? '—' }}</div>
          <div class="metric-label">{{ m.label }}</div>
        </div>
      </v-col>
    </v-row>

    <div v-if="totalActivity === 0 && !loading"
      class="text-caption text-medium-emphasis text-center mt-3">
      Пока тихо — самое время начать 🚀
    </div>
  </v-card>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api';

const data = ref({});
const loading = ref(true);

const metrics = [
  { key: 'closedToday',    label: 'Закрыто тикетов',    icon: 'mdi-check-circle-outline', color: 'success' },
  { key: 'messagesToday',  label: 'Отправлено сообщений', icon: 'mdi-message-text-outline', color: 'primary' },
  { key: 'assignedActive', label: 'У меня в работе',    icon: 'mdi-account-clock-outline', color: 'warning' },
  { key: 'auditToday',     label: 'Действий за день',    icon: 'mdi-history',              color: 'info' },
];

const totalActivity = computed(() =>
  (data.value.closedToday || 0) + (data.value.messagesToday || 0) + (data.value.auditToday || 0)
);

const todayStr = computed(() =>
  new Date().toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long' })
);

async function load() {
  try {
    const { data: d } = await api.get('/my-day');
    data.value = d || {};
  } catch {}
  loading.value = false;
}

onMounted(() => {
  load();
  // Раз в 2 минуты освежаем — метрики живут весь день, поллить чаще нет смысла.
  const t = setInterval(() => {
    if (document.visibilityState === 'visible') load();
  }, 120_000);
  // cleanup на unmount — Vue делает это сам, если переменная локальная,
  // но явный clearInterval спокойнее.
  return () => clearInterval(t);
});
</script>

<style scoped>
.metric-tile {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 12px 8px;
  border-radius: 12px;
  background: rgba(var(--v-theme-surface-variant), 0.4);
  text-align: center;
  transition: background 0.15s;
}
.metric-tile:hover { background: rgba(var(--v-theme-surface-variant), 0.7); }
.metric-value {
  font-size: 22px;
  font-weight: 700;
  line-height: 1.1;
  font-variant-numeric: tabular-nums;
}
.metric-label {
  font-size: 11px;
  color: rgba(var(--v-theme-on-surface), 0.65);
  margin-top: 2px;
}
.tile-success { background: rgba(var(--v-theme-success), 0.08); }
.tile-primary { background: rgba(var(--v-theme-primary), 0.08); }
.tile-warning { background: rgba(var(--v-theme-warning), 0.08); }
.tile-info    { background: rgba(var(--v-theme-info), 0.08); }
</style>
