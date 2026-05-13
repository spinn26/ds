<template>
  <v-tooltip :text="tooltip" location="bottom">
    <template #activator="{ props: tip }">
      <router-link v-bind="tip" to="/status" class="status-chip-link" :class="['status-' + status]">
        <span class="status-dot" :class="['dot-' + status]">
          <span class="dot-ping" />
        </span>
        <span v-if="!compact" class="status-label">{{ label }}</span>
      </router-link>
    </template>
  </v-tooltip>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import api from '../api';

defineProps({
  compact: { type: Boolean, default: false },
});

const status = ref('operational');
const label = ref('Все системы работают');
const activeCount = ref(0);
let timer = null;

const STATUS_LABELS = {
  operational: 'Все системы работают',
  maintenance: 'Тех. работы',
  degraded: 'Снижение производительности',
  partial_outage: 'Частичный сбой',
  major_outage: 'Серьёзный сбой',
};

const tooltip = computed(() => {
  if (activeCount.value > 0) {
    return `${label.value} · активных инцидентов: ${activeCount.value} · открыть страницу статуса`;
  }
  return `${label.value} · открыть страницу статуса`;
});

async function load() {
  try {
    const { data } = await api.get('/system-status');
    status.value = data.overall?.status || 'operational';
    label.value = STATUS_LABELS[status.value] || data.overall?.label || 'Статус системы';
    activeCount.value = (data.active || []).length;
  } catch {
    // silent — статус в шапке необязательный, не спамим snackbar'ом
  }
}

onMounted(() => {
  load();
  // Polling раз в 60 сек — статус-страница ничего тяжёлого.
  timer = setInterval(load, 60_000);
});
onUnmounted(() => {
  if (timer) clearInterval(timer);
});
</script>

<style scoped>
.status-chip-link {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 4px 10px;
  border-radius: 999px;
  text-decoration: none;
  color: rgba(var(--v-theme-on-surface), 0.85);
  font-size: 12px;
  font-weight: 500;
  background: rgba(var(--v-theme-surface-variant), 0.4);
  transition: background 0.15s;
  white-space: nowrap;
}
.status-chip-link:hover {
  background: rgba(var(--v-theme-surface-variant), 0.7);
}

.status-dot {
  position: relative;
  display: inline-block;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}
.dot-operational    { background: #43a047; }
.dot-maintenance    { background: #1e88e5; }
.dot-degraded       { background: #f5a623; }
.dot-partial_outage { background: #fb8c00; }
.dot-major_outage   { background: #e53935; }

/* «пульс»-анимация: точка дублируется и плавно расходится */
.dot-ping {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  background: inherit;
  animation: status-ping 1.6s cubic-bezier(0, 0, 0.2, 1) infinite;
  opacity: 0.75;
}
.dot-operational .dot-ping {
  /* operational — слабый пульс, чтобы не отвлекал */
  animation-duration: 3.2s;
  opacity: 0.5;
}
.dot-major_outage .dot-ping,
.dot-partial_outage .dot-ping {
  /* серьёзные — частый, заметный */
  animation-duration: 1.0s;
}
@keyframes status-ping {
  0%   { transform: scale(1);    opacity: 0.75; }
  80%  { transform: scale(2.4);  opacity: 0;    }
  100% { transform: scale(2.4);  opacity: 0;    }
}

/* Подсветка контейнера на серьёзных статусах */
.status-major_outage    { background: rgba(229, 57, 53, 0.15); color: #ef5350; }
.status-partial_outage  { background: rgba(251, 140, 0, 0.15); color: #ffa726; }
.status-degraded        { background: rgba(245, 166, 35, 0.12); color: #ffb74d; }
.status-maintenance     { background: rgba(30, 136, 229, 0.15); color: #64b5f6; }

.status-label {
  max-width: 240px;
  overflow: hidden;
  text-overflow: ellipsis;
}

@media (max-width: 960px) {
  .status-label { display: none; }
  .status-chip-link { padding: 4px; }
}
</style>
