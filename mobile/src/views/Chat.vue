<template>
  <div>
    <PageHeader title="Обращения">
      <template #actions>
        <v-btn color="primary" size="small" prepend-icon="mdi-plus">Новое</v-btn>
      </template>
    </PageHeader>

    <div class="search-bar">
      <v-text-field v-model="search" placeholder="Поиск по обращениям"
        density="compact" variant="outlined" hide-details rounded clearable
        prepend-inner-icon="mdi-magnify" />
    </div>

    <div class="chip-row">
      <v-chip v-for="f in filters" :key="f.value"
        :color="filter === f.value ? 'primary' : undefined"
        :variant="filter === f.value ? 'flat' : 'tonal'"
        size="small" label @click="filter = f.value">
        {{ f.label }}
      </v-chip>
    </div>

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>

    <div v-if="loading" class="text-center py-8">
      <v-progress-circular indeterminate color="primary" size="32" />
    </div>

    <div v-else-if="!filtered.length" class="empty-state">
      <v-icon size="48">mdi-forum-outline</v-icon>
      <div class="empty-state-text">Обращений нет</div>
    </div>

    <div v-else class="list">
      <div v-for="t in filtered" :key="t.id" class="ticket-card" @click="$router.push(`/app/chat/${t.id}`)">
        <v-avatar size="40" :color="categoryColor(t.department)" variant="tonal">
          <v-icon size="20">{{ categoryIcon(t.department) }}</v-icon>
        </v-avatar>
        <div class="ticket-body">
          <div class="ticket-head">
            <div class="ticket-subject">{{ t.subject || 'Без темы' }}</div>
            <span class="ticket-time">{{ formatTime(t.last_message_at || t.created_at) }}</span>
          </div>
          <div class="ticket-foot">
            <div class="ticket-preview">{{ t.last_message_preview || '—' }}</div>
            <v-chip v-if="t.unread > 0" size="x-small" color="error" variant="flat">
              {{ t.unread }}
            </v-chip>
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

interface Ticket {
  id: number;
  subject?: string;
  department?: string;
  status?: string;
  unread?: number;
  last_message_at?: string;
  created_at?: string;
  last_message_preview?: string;
}

const search = ref('');
const filter = ref('all');
const filters = [
  { value: 'all', label: 'Все' },
  { value: 'open', label: 'Открытые' },
  { value: 'in_progress', label: 'В работе' },
  { value: 'closed', label: 'Закрытые' },
];

const tickets = ref<Ticket[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  return tickets.value.filter((t) => {
    if (filter.value !== 'all' && t.status !== filter.value) return false;
    if (q && !(t.subject || '').toLowerCase().includes(q)) return false;
    return true;
  });
});

function categoryIcon(c?: string) {
  return ({
    support: 'mdi-lifebuoy',
    technical: 'mdi-lifebuoy',
    backoffice: 'mdi-folder-account-outline',
    accruals: 'mdi-cash',
    billing: 'mdi-cash',
    accounting: 'mdi-cash',
    legal: 'mdi-scale-balance',
    general: 'mdi-message-outline',
  } as Record<string, string>)[c || ''] || 'mdi-message-outline';
}
function categoryColor(c?: string) {
  return ({
    support: 'info',
    technical: 'info',
    backoffice: 'warning',
    accruals: 'success',
    billing: 'success',
    accounting: 'success',
    legal: 'error',
    general: 'primary',
  } as Record<string, string>)[c || ''] || 'grey';
}
function formatTime(iso?: string) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';
  const today = new Date();
  if (d.toDateString() === today.toDateString()) {
    return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  }
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' });
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const { data } = await api.get('/chat/tickets');
    tickets.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить чаты';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.ticket-card { display: flex; gap: 10px; padding: 12px; background: #fff; border-radius: 14px; margin-bottom: 8px; cursor: pointer; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.ticket-card:active { transform: scale(0.99); }
.ticket-body { flex: 1; min-width: 0; }
.ticket-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.ticket-subject { font-size: 14px; font-weight: 600; color: #1b1b1b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ticket-time { font-size: 11px; color: rgba(0,0,0,0.45); white-space: nowrap; }
.ticket-foot { display: flex; justify-content: space-between; align-items: center; margin-top: 4px; }
.ticket-preview { flex: 1; font-size: 12px; color: rgba(0,0,0,0.55); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.empty-state { padding: 60px 24px; text-align: center; }
.empty-state .v-icon { color: rgba(0,0,0,0.2); margin-bottom: 12px; }
.empty-state-text { font-size: 14px; color: rgba(0,0,0,0.5); }
</style>
