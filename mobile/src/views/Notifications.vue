<template>
  <div>
    <PageHeader title="Уведомления">
      <template #actions>
        <v-btn variant="text" size="small" :loading="marking" @click="markAll">Прочитать все</v-btn>
      </template>
    </PageHeader>

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>

    <div v-if="loading" class="text-center py-8">
      <v-progress-circular indeterminate color="primary" size="32" />
    </div>

    <div v-else-if="!items.length" class="empty-state">
      <v-icon size="48">mdi-bell-off-outline</v-icon>
      <div class="empty-state-text">Нет уведомлений</div>
    </div>

    <template v-else>
      <div v-for="(group, idx) in grouped" :key="idx">
        <div class="day-label">{{ group.label }}</div>
        <div v-for="n in group.items" :key="n.id" class="notif-card" :class="{ unread: !n.read }" @click="open(n)">
          <v-avatar size="40" :color="n.color || 'primary'" variant="tonal">
            <v-icon size="20">{{ n.icon || 'mdi-bell-outline' }}</v-icon>
          </v-avatar>
          <div class="notif-body">
            <div class="notif-title">{{ n.title }}</div>
            <div class="notif-text">{{ n.message }}</div>
            <div class="notif-time">{{ formatTime(n.createdAt) }}</div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '@/api';
import PageHeader from '@/components/PageHeader.vue';
import { useNotificationsStore } from '@/stores/notifications';

const notificationsStore = useNotificationsStore();

interface Notif {
  id: number;
  title: string;
  message?: string;
  icon?: string;
  color?: string;
  read?: boolean;
  link?: string;
  createdAt?: string;
}

const router = useRouter();
const items = ref<Notif[]>([]);
const loading = ref(true);
const marking = ref(false);
const error = ref<string | null>(null);

const grouped = computed(() => {
  const today = new Date();
  const yesterday = new Date(today);
  yesterday.setDate(today.getDate() - 1);
  const weekAgo = new Date(today);
  weekAgo.setDate(today.getDate() - 7);

  const groups: { label: string; items: Notif[] }[] = [
    { label: 'Сегодня', items: [] },
    { label: 'Вчера', items: [] },
    { label: 'На прошлой неделе', items: [] },
    { label: 'Ранее', items: [] },
  ];
  for (const n of items.value) {
    const d = n.createdAt ? new Date(n.createdAt) : null;
    if (!d || isNaN(d.getTime())) {
      groups[3].items.push(n);
      continue;
    }
    if (d.toDateString() === today.toDateString()) groups[0].items.push(n);
    else if (d.toDateString() === yesterday.toDateString()) groups[1].items.push(n);
    else if (d >= weekAgo) groups[2].items.push(n);
    else groups[3].items.push(n);
  }
  return groups.filter((g) => g.items.length);
});

function formatTime(iso?: string) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';
  return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const { data } = await api.get('/notifications');
    items.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить';
  } finally {
    loading.value = false;
  }
}

async function open(n: Notif) {
  if (!n.read) {
    n.read = true;
    await notificationsStore.markRead(n.id);
  }
  if (n.link) router.push(n.link);
}

async function markAll() {
  marking.value = true;
  // Сразу красим список как прочитанный и сбрасываем счётчик в шапке —
  // store сам дёрнет /notifications/read-all и откатит при ошибке.
  items.value = items.value.map((n) => ({ ...n, read: true }));
  await notificationsStore.markAllRead();
  marking.value = false;
}

onMounted(load);
</script>

<style scoped>
.day-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.6px; color: rgba(0,0,0,0.45); margin: 14px 4px 6px; }
.notif-card { display: flex; gap: 10px; padding: 12px; background: #fff; border-radius: 14px; margin-bottom: 6px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); position: relative; cursor: pointer; }
.notif-card.unread::before { content: ''; position: absolute; top: 14px; left: 6px; width: 6px; height: 6px; border-radius: 50%; background: rgb(var(--v-theme-primary)); }
.notif-body { flex: 1; min-width: 0; }
.notif-title { font-size: 13px; font-weight: 600; }
.notif-text { font-size: 12px; color: rgba(0,0,0,0.6); margin-top: 2px; }
.notif-time { font-size: 11px; color: rgba(0,0,0,0.4); margin-top: 4px; }

.empty-state { padding: 60px 24px; text-align: center; }
.empty-state .v-icon { color: rgba(0,0,0,0.2); margin-bottom: 12px; }
.empty-state-text { font-size: 14px; color: rgba(0,0,0,0.5); }
</style>
