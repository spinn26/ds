<template>
  <div>
    <PageHeader title="Тикеты">
      <template #actions>
        <v-btn icon="mdi-refresh" size="small" variant="text" :loading="loading" @click="load" />
      </template>
    </PageHeader>

    <div class="search-bar">
      <v-text-field v-model="search" placeholder="Поиск по теме / клиенту"
        density="compact" variant="outlined" hide-details rounded clearable
        prepend-inner-icon="mdi-magnify" />
    </div>

    <div class="chip-row">
      <v-chip v-for="v in views" :key="v.value"
        :color="view === v.value ? 'warning' : undefined"
        :variant="view === v.value ? 'flat' : 'tonal'"
        size="small" label @click="view = v.value">
        {{ v.label }} <span class="ml-1 chip-count">{{ counts[v.value] ?? 0 }}</span>
      </v-chip>
    </div>

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>

    <div v-if="loading" class="text-center py-8">
      <v-progress-circular indeterminate color="warning" size="32" />
    </div>

    <div v-else-if="!filtered.length" class="empty-state">
      <v-icon size="48">mdi-forum-outline</v-icon>
      <div class="empty-state-text">Тикетов нет</div>
    </div>

    <div v-else class="list">
      <div v-for="t in filtered" :key="t.id" class="ticket-card" @click="$router.push(`/app/chat/${t.id}`)">
        <v-avatar size="40" :color="categoryColor(t.department)" variant="tonal">
          <v-icon size="18">{{ categoryIcon(t.department) }}</v-icon>
        </v-avatar>
        <div class="ticket-body">
          <div class="ticket-head">
            <div class="ticket-subject">{{ t.subject || 'Без темы' }}</div>
            <span class="ticket-time">{{ formatTime(t.last_message_at || t.created_at) }}</span>
          </div>
          <div class="ticket-foot">
            <div class="ticket-meta">
              <v-chip v-if="t.priority" size="x-small" :color="priorityColor(t.priority)" variant="tonal">{{ t.priority }}</v-chip>
              <span class="ml-1">{{ t.customer_name }}</span>
              <span v-if="t.assigned_to_name" class="ml-1">· {{ t.assigned_to_name }}</span>
            </div>
            <v-chip v-if="t.unread > 0" size="x-small" color="error" variant="flat">{{ t.unread }}</v-chip>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';
import { useAuthStore } from '@/stores/auth';

interface Ticket {
  id: number;
  subject?: string;
  department?: string;
  status?: string;
  priority?: string;
  unread?: number;
  customer_name?: string;
  assigned_to?: number;
  assigned_to_name?: string;
  last_message_at?: string;
  created_at?: string;
  updated_at?: string;
}

const auth = useAuthStore();
const search = ref('');
const view = ref('mine');
const tickets = ref<Ticket[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const views = [
  { value: 'all', label: 'Все' },
  { value: 'mine', label: 'Мои' },
  { value: 'unassigned', label: 'Без назначения' },
  { value: 'stale', label: 'Залежавшиеся' },
];

const currentUserId = computed(() => auth.user?.id);
const STALE_HOURS = 24;

function isStale(t: Ticket) {
  const u = t.updated_at || t.last_message_at || t.created_at;
  if (!u) return false;
  return Date.now() - new Date(u).getTime() > STALE_HOURS * 3600 * 1000;
}

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  return tickets.value.filter((t) => {
    if (view.value === 'mine' && String(t.assigned_to) !== String(currentUserId.value)) return false;
    if (view.value === 'unassigned' && t.assigned_to) return false;
    if (view.value === 'stale' && !isStale(t)) return false;
    if (q && !(t.subject || '').toLowerCase().includes(q) && !(t.customer_name || '').toLowerCase().includes(q)) return false;
    return true;
  });
});

const counts = computed(() => ({
  all: tickets.value.length,
  mine: tickets.value.filter((t) => String(t.assigned_to) === String(currentUserId.value)).length,
  unassigned: tickets.value.filter((t) => !t.assigned_to).length,
  stale: tickets.value.filter(isStale).length,
} as Record<string, number>));

function categoryIcon(c?: string) {
  return ({ support: 'mdi-lifebuoy', technical: 'mdi-lifebuoy', backoffice: 'mdi-folder-account-outline', accruals: 'mdi-cash', billing: 'mdi-cash', accounting: 'mdi-cash', legal: 'mdi-scale-balance', general: 'mdi-message-outline' } as Record<string, string>)[c || ''] || 'mdi-message-outline';
}
function categoryColor(c?: string) {
  return ({ support: 'info', technical: 'info', backoffice: 'warning', accruals: 'success', billing: 'success', accounting: 'success', legal: 'error', general: 'primary' } as Record<string, string>)[c || ''] || 'grey';
}
function priorityColor(p: string) {
  return ({ critical: 'error', high: 'warning', medium: 'info', low: 'grey' } as Record<string, string>)[p] || 'grey';
}
function formatTime(iso?: string) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';
  const today = new Date();
  if (d.toDateString() === today.toDateString()) return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' });
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const { data } = await api.get('/chat/tickets');
    tickets.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить тикеты';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.chip-count { background: rgba(0,0,0,0.08); padding: 1px 5px; border-radius: 8px; font-size: 10px; }
.ticket-card { display: flex; gap: 10px; padding: 12px; background: #fff; border-radius: 14px; margin-bottom: 8px; cursor: pointer; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.ticket-body { flex: 1; min-width: 0; }
.ticket-head { display: flex; justify-content: space-between; gap: 8px; }
.ticket-subject { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ticket-time { font-size: 11px; color: rgba(0,0,0,0.45); white-space: nowrap; }
.ticket-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 4px; }
.ticket-meta { flex: 1; font-size: 11px; color: rgba(0,0,0,0.55); display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.empty-state { padding: 60px 24px; text-align: center; }
.empty-state .v-icon { color: rgba(0,0,0,0.2); margin-bottom: 12px; }
.empty-state-text { font-size: 14px; color: rgba(0,0,0,0.5); }
</style>
