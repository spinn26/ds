<template>
  <v-card :color="status.enabled ? undefined : undefined" class="maint-ctl" variant="outlined">
    <v-card-item>
      <template #prepend>
        <v-icon :color="status.enabled ? 'error' : 'success'" size="28">
          {{ status.enabled ? 'mdi-lock' : 'mdi-lock-open-variant' }}
        </v-icon>
      </template>
      <v-card-title class="text-h6">Режим обслуживания</v-card-title>
      <v-card-subtitle>
        {{ status.enabled ? 'Доступ закрыт — платформа видна только админам' : 'Публичный доступ открыт' }}
      </v-card-subtitle>
    </v-card-item>

    <v-card-text>
      <!-- Активен: отсчёт + кнопка открыть -->
      <template v-if="status.enabled">
        <v-alert type="error" variant="tonal" density="comfortable" class="mb-4">
          Все пользователи, кроме администраторов, видят страницу техработ и не могут войти.
        </v-alert>
        <div v-if="countdown" class="d-flex align-center mb-4" style="gap: 12px">
          <v-icon color="primary">mdi-timer-outline</v-icon>
          <div>
            <div class="text-caption text-medium-emphasis">До ожидаемого завершения</div>
            <div class="countdown-value">{{ countdown }}</div>
          </div>
        </div>
        <v-btn color="success" size="large" prepend-icon="mdi-lock-open-variant"
          :loading="saving" @click="disable">
          Открыть доступ
        </v-btn>
      </template>

      <!-- Выключен: настройка + кнопка закрыть -->
      <template v-else>
        <v-textarea v-model="message" label="Сообщение для пользователей" rows="2" auto-grow
          variant="outlined" density="comfortable" class="mb-3" hide-details="auto" />

        <div class="text-body-2 font-weight-medium mb-1">Ожидаемое завершение</div>
        <div class="d-flex flex-wrap align-center mb-2" style="gap: 8px">
          <v-btn v-for="p in presets" :key="p.value" size="small" variant="tonal"
            @click="setPreset(p.value)">{{ p.title }}</v-btn>
          <v-btn size="small" variant="text" @click="clearEnd">Без отсчёта</v-btn>
        </div>
        <v-menu v-model="menu" :close-on-content-click="false" location="bottom start">
          <template #activator="{ props }">
            <v-text-field v-bind="props" :model-value="endLabel" readonly label="Дата и время"
              variant="outlined" density="comfortable" prepend-inner-icon="mdi-calendar-clock"
              style="max-width: 320px" class="mb-4" hide-details />
          </template>
          <v-card min-width="300">
            <v-date-picker v-model="endDate" :min="minDate" color="primary" show-adjacent-months hide-header />
            <div class="px-4 pb-2">
              <v-text-field v-model="endTime" type="time" label="Время" variant="outlined"
                density="compact" hide-details />
            </div>
            <v-card-actions>
              <v-spacer />
              <v-btn variant="text" @click="menu = false">Готово</v-btn>
            </v-card-actions>
          </v-card>
        </v-menu>

        <v-btn color="error" size="large" prepend-icon="mdi-lock"
          :loading="saving" @click="enable">
          Закрыть доступ (техработы)
        </v-btn>
        <div class="text-caption text-medium-emphasis mt-2">
          Вы (админ) продолжите работать как обычно.
        </div>
      </template>
    </v-card-text>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="4000">{{ snack.text }}</v-snackbar>
  </v-card>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import api from '../api';

const status = reactive({ enabled: false, message: '', ends_at: null, server_time: null });
const message = ref('Идут технические работы. Скоро вернёмся.');
const saving = ref(false);
const snack = ref({ open: false, color: 'success', text: '' });

// Выбор времени завершения: календарь (дата) + время. Пресеты быстро заполняют.
const menu = ref(false);
const endDate = ref(null);   // Date
const endTime = ref('');     // 'HH:MM'
const minDate = new Date();
const presets = [
  { title: '+15 мин', value: 15 },
  { title: '+30 мин', value: 30 },
  { title: '+1 час', value: 60 },
  { title: '+2 часа', value: 120 },
];

function pad(n) { return String(n).padStart(2, '0'); }
function setPreset(min) {
  const d = new Date(Date.now() + min * 60000);
  endDate.value = d;
  endTime.value = `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
function clearEnd() { endDate.value = null; endTime.value = ''; }

const endLabel = computed(() => {
  if (!endDate.value) return 'Без обратного отсчёта';
  const d = new Date(endDate.value);
  return d.toLocaleDateString('ru-RU') + (endTime.value ? ` ${endTime.value}` : '');
});

function endsAtIso() {
  if (!endDate.value) return null;
  const d = new Date(endDate.value);
  const [hh, mm] = (endTime.value || '00:00').split(':');
  d.setHours(parseInt(hh || '0', 10), parseInt(mm || '0', 10), 0, 0);
  return d.toISOString();
}

// Живой отсчёт (синхронизирован с серверными часами).
const serverOffset = ref(0);
const nowTick = ref(Date.now());
let tickTimer = null;
const countdown = computed(() => {
  if (!status.ends_at) return '';
  const ms = new Date(status.ends_at).getTime() - (nowTick.value + serverOffset.value);
  if (ms <= 0) return '';
  const s = Math.floor(ms / 1000);
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(h)}:${pad(m)}:${pad(sec)}`;
});

function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

function apply(data) {
  status.enabled = data.enabled;
  status.message = data.message;
  status.ends_at = data.ends_at;
  status.server_time = data.server_time;
  if (data.server_time) serverOffset.value = new Date(data.server_time).getTime() - Date.now();
  if (data.message) message.value = data.message;
}

async function load() {
  try { const { data } = await api.get('/maintenance'); apply(data); } catch { /* тихо */ }
}

async function enable() {
  saving.value = true;
  try {
    const { data } = await api.post('/admin/maintenance', {
      enabled: true,
      ends_at: endsAtIso(),
      message: message.value || null,
    });
    apply(data);
    notify('Режим обслуживания включён — доступ закрыт');
  } catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
  saving.value = false;
}

async function disable() {
  saving.value = true;
  try {
    const { data } = await api.post('/admin/maintenance', { enabled: false });
    apply(data);
    notify('Доступ открыт');
  } catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
  saving.value = false;
}

onMounted(() => {
  setPreset(30); // разумный дефолт — через 30 минут
  load();
  tickTimer = setInterval(() => { nowTick.value = Date.now(); }, 1000);
});
onBeforeUnmount(() => { if (tickTimer) clearInterval(tickTimer); });
</script>

<style scoped>
.countdown-value {
  font-size: 1.6rem; font-weight: 700; line-height: 1.1;
  font-variant-numeric: tabular-nums; color: rgb(var(--v-theme-primary));
}
</style>
