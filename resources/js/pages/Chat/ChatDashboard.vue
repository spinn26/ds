<template>
  <div class="chat-root">
    <!-- Toolbar -->
    <div class="chat-toolbar">
      <div class="d-flex align-center ga-3">
        <!-- View Switcher -->
        <div class="view-switcher">
          <button :class="['view-btn', viewMode === 'kanban' && 'active']" @click="viewMode = 'kanban'">
            <v-icon size="16">mdi-view-column</v-icon> Канбан
          </button>
          <button :class="['view-btn', viewMode === 'list' && 'active']" @click="viewMode = 'list'">
            <v-icon size="16">mdi-format-list-bulleted</v-icon> Список
          </button>
          <button :class="['view-btn', viewMode === 'customers' && 'active']" @click="viewMode = 'customers'">
            <v-icon size="16">mdi-account-group</v-icon> Клиенты
          </button>
        </div>

        <!-- Filters -->
        <v-menu>
          <template #activator="{ props }">
            <button v-bind="props" class="filter-btn">
              <v-icon size="16">mdi-filter-variant</v-icon> Фильтры <v-icon size="12">mdi-chevron-down</v-icon>
            </button>
          </template>
          <v-card class="pa-0" min-width="200" style="background: var(--chat-card)">
            <div class="px-3 py-2 text-caption font-weight-bold" style="color: var(--chat-muted)">По статусу</div>
            <div v-for="s in statusOptions" :key="s.value" class="filter-item" @click="setFilter('status', s.value, s.label)">
              <span class="status-dot" :style="{ background: s.color }"></span> {{ s.label }}
            </div>
            <v-divider style="border-color: var(--chat-border)" />
            <div class="px-3 py-2 text-caption font-weight-bold" style="color: var(--chat-muted)">По приоритету</div>
            <div v-for="p in priorityOptions" :key="p.value" class="filter-item" @click="setFilter('priority', p.value, p.label)">
              <span class="status-dot" :style="{ background: p.dotColor }"></span> {{ p.label }}
            </div>
          </v-card>
        </v-menu>

        <span v-if="activeFilter" class="active-filter" @click="activeFilter = null">
          {{ activeFilter.label }} <v-icon size="12">mdi-close</v-icon>
        </span>

        <!-- Stats -->
        <div class="chat-stats">
          <span>Всего: {{ stats.total }}</span>
          <span style="color: #60a5fa">Новых: {{ stats.new }}</span>
          <span style="color: #fbbf24">Открытых: {{ stats.open }}</span>
          <span v-if="stats.critical" style="color: #ef4444; font-weight: 600">Крит: {{ stats.critical }}</span>
        </div>
      </div>

      <div class="d-flex align-center ga-3">
        <div class="chat-search">
          <v-icon size="16" style="color: var(--chat-muted)">mdi-magnify</v-icon>
          <input v-model="search" placeholder="Поиск чатов..." @input="debouncedLoad" />
        </div>
        <button class="new-chat-btn" @click="newChatDialog = true">
          <v-icon size="16">mdi-plus</v-icon> Новый чат
        </button>
      </div>
    </div>

    <!-- Content -->
    <div class="chat-content">
      <!-- KANBAN -->
      <div v-if="viewMode === 'kanban'" class="kanban-container">
        <div v-for="col in kanbanColumns" :key="col.status" class="kanban-column"
          @dragover.prevent="dragOverCol = col.status" @dragleave="dragOverCol = null"
          @drop="onDrop(col.status)" :class="{ 'drag-over': dragOverCol === col.status }">
          <div class="kanban-header">
            <span class="kanban-dot" :style="{ background: col.color }"></span>
            <span class="kanban-title">{{ col.label }}</span>
            <span class="kanban-count">{{ ticketsByStatus(col.status).length }}</span>
          </div>
          <div class="kanban-cards">
            <div v-for="t in ticketsByStatus(col.status)" :key="t.id" class="kanban-card"
              :class="{ selected: selectedTicket?.id === t.id }" draggable="true"
              @dragstart="dragTicket = t" @click="selectTicket(t)">
              <div class="d-flex align-center ga-2 mb-1">
                <span class="ticket-id">#{{ t.id }}</span>
                <span class="flex-grow-1"></span>
                <span class="priority-badge" :style="{ background: priorityBg(t.priority), color: priorityFg(t.priority) }">
                  {{ priorityShort(t.priority) }}
                </span>
              </div>
              <div class="ticket-subject">{{ t.subject }}</div>
              <div class="ticket-meta">
                <v-icon size="12">mdi-account</v-icon>
                <span>{{ t.customer_name || 'Партнёр' }}</span>
                <span class="flex-grow-1"></span>
                <v-icon size="12">mdi-message-outline</v-icon>
                <span>{{ t.messages_count }}</span>
                <span class="ticket-time">{{ ago(t.last_message_at) }}</span>
              </div>
            </div>
            <div v-if="!ticketsByStatus(col.status).length" class="kanban-empty">Пусто</div>
          </div>
        </div>

        <!-- Side chat panel -->
        <div v-if="selectedTicket" class="side-panel">
          <ChatPanel :ticket="selectedTicket" @close="selectedTicket = null" @status-change="onStatusChange" @reload="loadTickets" />
        </div>
      </div>

      <!-- LIST -->
      <div v-if="viewMode === 'list'" class="list-container">
        <div class="list-sidebar">
          <div v-for="t in filteredTickets" :key="t.id" class="list-item"
            :class="{ selected: selectedTicket?.id === t.id }" @click="selectTicket(t)">
            <div class="d-flex align-center ga-2">
              <div class="list-avatar" :style="{ background: deptColor(t.department) }">
                <v-icon size="16" color="white">{{ deptIcon(t.department) }}</v-icon>
              </div>
              <div class="flex-grow-1 overflow-hidden">
                <div class="list-subject">{{ t.subject }}</div>
                <div class="list-meta">{{ t.customer_name }} · #{{ t.id }}</div>
              </div>
              <div class="d-flex flex-column align-end ga-1">
                <span class="status-badge" :style="{ background: statusBg(t.status) }">{{ statusShort(t.status) }}</span>
                <span class="list-time">{{ ago(t.last_message_at) }}</span>
              </div>
            </div>
          </div>
          <div v-if="!filteredTickets.length" class="list-empty">Нет чатов</div>
        </div>
        <div class="list-main">
          <ChatPanel v-if="selectedTicket" :ticket="selectedTicket" @close="selectedTicket = null" @status-change="onStatusChange" @reload="loadTickets" />
          <div v-else class="empty-state">
            <div style="font-size: 48px; margin-bottom: 12px">💬</div>
            <div style="font-size: 16px; color: var(--chat-muted)">Выберите чат для просмотра</div>
          </div>
        </div>
      </div>

      <!-- CUSTOMERS -->
      <div v-if="viewMode === 'customers'" class="customers-container">
        <div v-for="group in customerGroups" :key="group.customerId" class="customer-card">
          <div class="d-flex align-center ga-3 mb-3">
            <div class="customer-avatar">{{ group.customerName?.[0] || '?' }}</div>
            <div class="flex-grow-1">
              <div class="customer-name">{{ group.customerName }}</div>
              <div class="customer-email">{{ group.customerEmail }}</div>
            </div>
            <span class="customer-count">{{ group.tickets.length }}</span>
          </div>
          <div v-for="t in group.tickets" :key="t.id" class="customer-ticket" @click="selectTicket(t); viewMode = 'list'">
            <span class="flex-grow-1">{{ t.subject }}</span>
            <span class="status-badge" :style="{ background: statusBg(t.status) }">{{ statusShort(t.status) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- New Chat Dialog -->
    <v-dialog v-model="newChatDialog" max-width="500" persistent>
      <v-card style="background: var(--chat-card); color: var(--chat-fg)">
        <v-card-title class="d-flex align-center ga-2">
          <v-icon>mdi-chat-plus</v-icon> Новый чат
        </v-card-title>
        <v-card-text>
          <v-autocomplete v-model="newForm.recipientId" :items="partnersList" item-title="name" item-value="id"
            label="Кому написать" clearable class="mb-3" :loading="loadingPartners"
            @update:search="searchPartners" />
          <v-text-field v-model="newForm.subject" label="Тема" class="mb-3" />
          <v-textarea v-model="newForm.message" label="Сообщение" rows="4" auto-grow />
          <v-alert v-if="newError" type="error" density="compact" class="mt-2">{{ newError }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="newChatDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="creating" @click="createChat">Отправить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import ChatPanel from './ChatPanel.vue';

const viewMode = ref('kanban');
const tickets = ref([]);
const loading = ref(false);
const search = ref('');
const selectedTicket = ref(null);
const activeFilter = ref(null);
const stats = ref({ total: 0, new: 0, open: 0, pending: 0, critical: 0 });
const dragTicket = ref(null);
const dragOverCol = ref(null);

const newChatDialog = ref(false);
const creating = ref(false);
const newError = ref('');
const newForm = ref({ recipientId: null, subject: '', message: '' });
const partnersList = ref([]);
const loadingPartners = ref(false);

const statusOptions = [
  { label: 'Новый', value: 'new', color: '#60a5fa' },
  { label: 'Открыт', value: 'open', color: '#fbbf24' },
  { label: 'Ожидание', value: 'pending', color: '#f97316' },
  { label: 'Решён', value: 'resolved', color: '#34d399' },
  { label: 'Закрыт', value: 'closed', color: '#6b7280' },
];
const priorityOptions = [
  { label: 'Критический', value: 'critical', dotColor: '#ef4444' },
  { label: 'Высокий', value: 'high', dotColor: '#f97316' },
  { label: 'Средний', value: 'medium', dotColor: '#fbbf24' },
  { label: 'Низкий', value: 'low', dotColor: '#34d399' },
];

const kanbanColumns = [
  { status: 'new', label: 'Новые', color: '#60a5fa' },
  { status: 'open', label: 'Открытые', color: '#fbbf24' },
  { status: 'pending', label: 'Ожидание', color: '#f97316' },
  { status: 'resolved', label: 'Решённые', color: '#34d399' },
  { status: 'closed', label: 'Закрытые', color: '#6b7280' },
];

function statusBg(s) { return { new: '#1e3a5f', open: '#3d3200', pending: '#3d2200', resolved: '#1a3d2e', closed: '#1f1f1f' }[s] || '#1f1f1f'; }
function statusShort(s) { return { new: 'Новый', open: 'Открыт', pending: 'Ожидание', resolved: 'Решён', closed: 'Закрыт' }[s] || s; }
function priorityBg(p) { return { critical: 'rgba(239,68,68,0.15)', high: 'rgba(249,115,22,0.15)', medium: 'rgba(251,191,36,0.15)', low: 'rgba(52,211,153,0.15)' }[p] || 'transparent'; }
function priorityFg(p) { return { critical: '#ef4444', high: '#f97316', medium: '#fbbf24', low: '#34d399' }[p] || '#888'; }
function priorityShort(p) { return { critical: 'Крит', high: 'Выс', medium: 'Сред', low: 'Низ' }[p] || p; }
function deptColor(d) { return { technical: '#3b82f6', billing: '#22c55e', sales: '#f97316', general: '#6b7280' }[d] || '#6b7280'; }
function deptIcon(d) { return { technical: 'mdi-headset', billing: 'mdi-file-document', sales: 'mdi-handshake', general: 'mdi-help-circle' }[d] || 'mdi-chat'; }

function setFilter(type, value, label) { activeFilter.value = { type, value, label }; }

function ago(d) {
  if (!d) return '';
  const diff = Math.floor((Date.now() - new Date(d).getTime()) / 1000);
  if (diff < 60) return 'только что';
  if (diff < 3600) return `${Math.floor(diff / 60)}м`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}ч`;
  return `${Math.floor(diff / 86400)}д`;
}

const filteredTickets = computed(() => {
  let list = tickets.value;
  if (activeFilter.value) list = list.filter(t => t[activeFilter.value.type] === activeFilter.value.value);
  return list;
});
function ticketsByStatus(s) { return filteredTickets.value.filter(t => t.status === s); }
const customerGroups = computed(() => {
  const m = {};
  filteredTickets.value.forEach(t => {
    if (!m[t.created_by]) m[t.created_by] = { customerId: t.created_by, customerName: t.customer_name, customerEmail: t.customer_email, tickets: [] };
    m[t.created_by].tickets.push(t);
  });
  return Object.values(m);
});

function selectTicket(t) { selectedTicket.value = t; }
function onStatusChange(id, s) {
  const t = tickets.value.find(x => x.id === id);
  if (t) t.status = s;
  if (selectedTicket.value?.id === id) selectedTicket.value.status = s;
}
function onDrop(status) {
  if (dragTicket.value && dragTicket.value.status !== status) {
    api.post(`/chat/tickets/${dragTicket.value.id}/status`, { status }).catch(() => {});
    onStatusChange(dragTicket.value.id, status);
  }
  dragTicket.value = null;
  dragOverCol.value = null;
}

const { debounced: debouncedLoad } = useDebounce(loadTickets, 400);

async function loadTickets() {
  loading.value = true;
  try {
    const params = {};
    if (search.value) params.search = search.value;
    if (activeFilter.value) params[activeFilter.value.type] = activeFilter.value.value;
    const { data } = await api.get('/chat/tickets', { params });
    tickets.value = data.data || [];
  } catch {}
  loading.value = false;
}
async function loadStats() {
  try { const { data } = await api.get('/chat/tickets/stats'); stats.value = data; } catch {}
}

let searchTimer;
async function searchPartners(q) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    loadingPartners.value = true;
    try {
      const { data } = await api.get('/chat/tickets/staff');
      partnersList.value = data || [];
    } catch {}
    loadingPartners.value = false;
  }, 300);
}

async function createChat() {
  if (!newForm.value.subject?.trim() || !newForm.value.message?.trim()) {
    newError.value = 'Заполните тему и сообщение';
    return;
  }
  creating.value = true;
  newError.value = '';
  try {
    const { data } = await api.post('/chat/tickets', {
      subject: newForm.value.subject,
      message: newForm.value.message,
      department: 'general',
      assigned_to: newForm.value.recipientId,
    });
    newChatDialog.value = false;
    newForm.value = { recipientId: null, subject: '', message: '' };
    await loadTickets();
    loadStats();
    if (data.ticket) selectTicket(data.ticket);
  } catch (e) {
    newError.value = e.response?.data?.message || 'Ошибка';
  }
  creating.value = false;
}

onMounted(() => { loadTickets(); loadStats(); searchPartners(''); });
</script>

<style scoped>
:root { --chat-bg: #090909; --chat-card: #141414; --chat-fg: #f0f0f0; --chat-muted: #666; --chat-border: #222; }
.chat-root { display: flex; flex-direction: column; height: calc(100vh - 64px); background: var(--chat-bg, #090909); color: var(--chat-fg, #f0f0f0); }

/* Toolbar */
.chat-toolbar { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid var(--chat-border, #222); background: var(--chat-card, #141414); flex-wrap: wrap; gap: 8px; }
.view-switcher { display: flex; background: #1a1a1a; border-radius: 8px; padding: 3px; gap: 2px; }
.view-btn { display: flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 6px; border: none; background: transparent; color: #888; cursor: pointer; font-size: 13px; transition: all 0.15s; }
.view-btn.active { background: #fff; color: #000; }
.view-btn:hover:not(.active) { color: #ccc; }
.filter-btn { display: flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 6px; border: 1px solid #333; background: transparent; color: #ccc; cursor: pointer; font-size: 13px; }
.filter-btn:hover { border-color: #555; }
.filter-item { padding: 8px 16px; cursor: pointer; display: flex; align-items: center; gap: 8px; font-size: 13px; color: #ccc; }
.filter-item:hover { background: #1a1a1a; }
.status-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.active-filter { display: flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 6px; background: #1a1a1a; color: #ccc; cursor: pointer; font-size: 12px; }
.chat-stats { display: flex; gap: 12px; font-size: 12px; color: #666; }
.chat-search { display: flex; align-items: center; gap: 8px; background: #1a1a1a; border-radius: 8px; padding: 6px 12px; }
.chat-search input { background: transparent; border: none; outline: none; color: #ccc; font-size: 13px; width: 180px; }
.new-chat-btn { display: flex; align-items: center; gap: 6px; padding: 6px 16px; border-radius: 8px; border: none; background: #fff; color: #000; cursor: pointer; font-size: 13px; font-weight: 600; }
.new-chat-btn:hover { background: #e0e0e0; }

/* Content */
.chat-content { flex: 1; overflow: hidden; display: flex; }

/* Kanban */
.kanban-container { display: flex; flex: 1; gap: 12px; padding: 16px; overflow-x: auto; }
.kanban-column { min-width: 260px; width: 260px; flex-shrink: 0; display: flex; flex-direction: column; background: #0d0d0d; border-radius: 12px; padding: 12px; }
.kanban-column.drag-over { outline: 2px dashed #60a5fa; outline-offset: -2px; }
.kanban-header { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; padding: 0 4px; }
.kanban-dot { width: 10px; height: 10px; border-radius: 50%; }
.kanban-title { font-size: 13px; font-weight: 600; }
.kanban-count { font-size: 12px; color: #666; margin-left: auto; }
.kanban-cards { flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; }
.kanban-card { background: var(--chat-card, #141414); border: 1px solid #1f1f1f; border-radius: 10px; padding: 12px; cursor: pointer; transition: all 0.15s; }
.kanban-card:hover { border-color: #333; }
.kanban-card.selected { border-color: #60a5fa; }
.kanban-card[draggable=true] { cursor: grab; }
.ticket-id { font-size: 11px; color: #555; }
.ticket-subject { font-size: 13px; font-weight: 500; margin-bottom: 8px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ticket-meta { display: flex; align-items: center; gap: 4px; font-size: 11px; color: #555; }
.ticket-time { margin-left: 8px; }
.priority-badge { font-size: 10px; padding: 2px 6px; border-radius: 4px; font-weight: 600; }
.kanban-empty { text-align: center; padding: 24px; color: #444; font-size: 13px; }
.side-panel { width: 420px; flex-shrink: 0; border-left: 1px solid #222; overflow: hidden; }

/* List */
.list-container { display: flex; flex: 1; }
.list-sidebar { width: 380px; flex-shrink: 0; border-right: 1px solid #222; overflow-y: auto; }
.list-item { padding: 12px 16px; border-bottom: 1px solid #1a1a1a; cursor: pointer; transition: background 0.1s; }
.list-item:hover { background: #111; }
.list-item.selected { background: #1a1a1a; border-left: 3px solid #60a5fa; }
.list-avatar { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.list-subject { font-size: 13px; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.list-meta { font-size: 11px; color: #555; margin-top: 2px; }
.list-time { font-size: 10px; color: #555; }
.list-empty { text-align: center; padding: 48px; color: #444; }
.list-main { flex: 1; overflow: hidden; }
.status-badge { font-size: 10px; padding: 2px 8px; border-radius: 4px; color: #ccc; white-space: nowrap; }

/* Customers */
.customers-container { flex: 1; padding: 16px; overflow-y: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 12px; align-content: start; }
.customer-card { background: var(--chat-card, #141414); border: 1px solid #1f1f1f; border-radius: 12px; padding: 16px; }
.customer-avatar { width: 40px; height: 40px; border-radius: 50%; background: #60a5fa; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 16px; }
.customer-name { font-size: 14px; font-weight: 600; }
.customer-email { font-size: 12px; color: #555; }
.customer-count { background: #1a1a1a; padding: 2px 10px; border-radius: 12px; font-size: 12px; color: #888; }
.customer-ticket { padding: 8px 0; border-top: 1px solid #1a1a1a; display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; }
.customer-ticket:hover { color: #60a5fa; }

/* Empty */
.empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; }
</style>
