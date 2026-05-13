<template>
  <v-card class="pa-4">
    <div class="d-flex align-center justify-space-between mb-3">
      <div class="text-subtitle-1 font-weight-bold">
        <v-icon class="mr-1" size="20" color="success">mdi-account-multiple-check</v-icon>
        Кто онлайн
        <span v-if="online.length" class="text-caption text-medium-emphasis ms-1">
          · {{ online.length }}
        </span>
      </div>
      <v-btn icon="mdi-refresh" size="x-small" variant="text" :loading="loading"
        @click="load" title="Обновить" />
    </div>

    <div v-if="loading && !online.length && !recent.length" class="text-center py-3">
      <v-progress-circular indeterminate size="20" />
    </div>
    <div v-else-if="!online.length && !recent.length"
      class="text-center text-medium-emphasis pa-3 text-caption">
      Никого из коллег рядом
    </div>

    <v-list density="compact" class="pa-0">
      <v-list-item v-for="u in online" :key="'on-' + u.id"
        class="user-row" @click="openChat(u)">
        <template #prepend>
          <div class="avatar-with-dot">
            <v-avatar size="32" color="primary" variant="tonal">
              <span class="text-caption font-weight-bold">{{ initials(u.name) }}</span>
            </v-avatar>
            <span class="presence-dot online" />
          </div>
        </template>
        <v-list-item-title class="text-body-2 font-weight-medium text-truncate">
          {{ u.name }}
        </v-list-item-title>
        <v-list-item-subtitle class="text-caption text-truncate">
          {{ shortRole(u.role) }} · в сети
        </v-list-item-subtitle>
        <template #append>
          <v-btn icon="mdi-message-text-outline" size="x-small" variant="text"
            color="primary" title="Написать" @click.stop="openChat(u)" />
        </template>
      </v-list-item>

      <v-divider v-if="online.length && recent.length" class="my-2" />

      <div v-if="recent.length" class="text-caption text-medium-emphasis px-3 pb-1">
        Недавно
      </div>
      <v-list-item v-for="u in recent" :key="'rc-' + u.id" class="user-row">
        <template #prepend>
          <div class="avatar-with-dot">
            <v-avatar size="32" color="grey-lighten-3" variant="tonal">
              <span class="text-caption font-weight-bold">{{ initials(u.name) }}</span>
            </v-avatar>
            <span class="presence-dot away" />
          </div>
        </template>
        <v-list-item-title class="text-body-2 text-truncate">{{ u.name }}</v-list-item-title>
        <v-list-item-subtitle class="text-caption text-truncate">
          {{ shortRole(u.role) }} · {{ minutesAgo(u.secAgo) }}
        </v-list-item-subtitle>
      </v-list-item>
    </v-list>
  </v-card>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api';

const router = useRouter();
const loading = ref(true);
const online = ref([]);
const recent = ref([]);
let timer = null;

function initials(name) {
  if (!name) return '?';
  const p = name.trim().split(/\s+/);
  return ((p[0]?.[0] || '') + (p[1]?.[0] || '')).toUpperCase() || '?';
}
function shortRole(role) {
  if (!role) return '—';
  const map = {
    admin: 'Админ', backoffice: 'Бэк-офис', support: 'Поддержка',
    head: 'Руководитель', finance: 'Финансы', calculations: 'Расчёты',
    corrections: 'Корректировки', education: 'Обучение',
  };
  const first = String(role).split(',')[0].trim();
  return map[first] || first;
}
function minutesAgo(sec) {
  if (sec < 60) return 'только что';
  const m = Math.floor(sec / 60);
  if (m < 60) return `${m} мин назад`;
  return `${Math.floor(m / 60)} ч назад`;
}

function openChat(u) {
  // Открываем staff-чат и сразу начинаем диалог с этим юзером.
  router.push(`/manage/chat?new=${u.id}`);
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/staff/online');
    online.value = data.online || [];
    recent.value = data.recent || [];
  } catch {}
  loading.value = false;
}

onMounted(() => {
  load();
  // Тот же интервал что и heartbeat — список обновляется в такт.
  timer = setInterval(() => {
    if (document.visibilityState === 'visible') load();
  }, 30_000);
});
onUnmounted(() => { if (timer) clearInterval(timer); });
</script>

<style scoped>
.avatar-with-dot { position: relative; margin-right: 8px; }
.presence-dot {
  position: absolute;
  bottom: -2px;
  right: -2px;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  border: 2px solid rgb(var(--v-theme-surface));
}
.presence-dot.online { background: #43a047; }
.presence-dot.away   { background: #f5a623; }
.user-row { cursor: pointer; }
.user-row:hover { background: rgba(var(--v-theme-primary), 0.06); }
</style>
