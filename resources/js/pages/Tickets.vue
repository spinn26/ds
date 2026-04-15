<template>
  <div>
    <PageHeader title="Обратная связь" icon="mdi-ticket-outline">
      <template #actions>
        <v-badge v-if="totalUnread > 0" :content="totalUnread" color="error" inline class="mr-2" />
        <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreateDialog">Новое обращение</v-btn>
      </template>
    </PageHeader>

    <!-- Mobile: back button -->
    <div v-if="mobile && selectedTicket" class="mb-2">
      <v-btn variant="text" prepend-icon="mdi-arrow-left" @click="selectedTicket = null">К списку</v-btn>
    </div>

    <v-row>
      <!-- Left: ticket list -->
      <v-col :cols="mobile ? 12 : 5" :lg="4" v-show="!mobile || !selectedTicket">
        <v-card class="mb-3 pa-3">
          <v-btn-toggle v-model="statusFilter" density="compact" color="primary" divided variant="outlined" mandatory>
            <v-btn value="all" size="small">Все</v-btn>
            <v-btn value="open" size="small">Открытые</v-btn>
            <v-btn value="in_progress" size="small">В работе</v-btn>
            <v-btn value="closed" size="small">Закрытые</v-btn>
          </v-btn-toggle>
        </v-card>

        <v-card :loading="loadingTickets">
          <v-list lines="three" class="pa-0">
            <template v-for="ticket in tickets" :key="ticket.id">
              <v-list-item :active="selectedTicket?.id === ticket.id" @click="selectTicket(ticket)" class="py-3">
                <template #prepend>
                  <v-badge v-if="ticket.unread_count > 0" :content="ticket.unread_count" color="error" floating offset-x="-4" offset-y="-4">
                    <v-avatar size="40" :color="categoryColor(ticket.category)">
                      <v-icon color="white" size="20">{{ categoryIcon(ticket.category) }}</v-icon>
                    </v-avatar>
                  </v-badge>
                  <v-avatar v-else size="40" :color="categoryColor(ticket.category)">
                    <v-icon color="white" size="20">{{ categoryIcon(ticket.category) }}</v-icon>
                  </v-avatar>
                </template>
                <v-list-item-title class="font-weight-medium d-flex align-center ga-2">
                  <span class="text-truncate">{{ ticket.subject }}</span>
                  <v-chip size="x-small" :color="statusColor(ticket.status)" variant="tonal" class="ml-auto flex-shrink-0">
                    {{ statusLabel(ticket.status) }}
                  </v-chip>
                </v-list-item-title>
                <v-list-item-subtitle class="mt-1">
                  <v-chip size="x-small" :color="categoryColor(ticket.category)" variant="outlined" class="mr-1">
                    {{ categoryLabel(ticket.category) }}
                  </v-chip>
                  <span class="text-caption text-medium-emphasis">{{ ticket.last_message_preview }}</span>
                </v-list-item-subtitle>
                <template #append>
                  <span class="text-caption text-medium-emphasis">{{ formatDate(ticket.updated_at) }}</span>
                </template>
              </v-list-item>
              <v-divider />
            </template>
            <v-list-item v-if="!tickets.length && !loadingTickets">
              <EmptyState icon="mdi-ticket-outline" message="Нет обращений" />
            </v-list-item>
          </v-list>
          <div v-if="ticketPages > 1" class="d-flex justify-center pa-3">
            <v-pagination v-model="ticketPage" :length="ticketPages" density="compact" @update:model-value="loadTickets" />
          </div>
        </v-card>
      </v-col>

      <!-- Right: chat -->
      <v-col :cols="mobile ? 12 : 7" :lg="8" v-show="!mobile || selectedTicket">
        <v-card v-if="selectedTicket" class="d-flex flex-column" style="height: calc(100vh - 200px); min-height: 300px">
          <!-- Header -->
          <div class="pa-3 border-b">
            <div class="d-flex align-center ga-2 flex-wrap">
              <span class="text-subtitle-1 font-weight-bold">{{ selectedTicket.subject }}</span>
              <v-spacer />
              <v-chip size="small" :color="categoryColor(selectedTicket.category)">{{ categoryLabel(selectedTicket.category) }}</v-chip>
              <v-chip size="small" :color="statusColor(selectedTicket.status)" variant="tonal">{{ statusLabel(selectedTicket.status) }}</v-chip>
            </div>
            <div v-if="selectedTicket.assigned_to_name" class="text-caption text-medium-emphasis mt-1">
              <v-icon size="14">mdi-account</v-icon> Ответственный: {{ selectedTicket.assigned_to_name }}
            </div>
          </div>

          <!-- Messages -->
          <div ref="messagesContainer" class="flex-grow-1 overflow-y-auto pa-3" style="min-height: 0">
            <div v-for="msg in messages" :key="msg.id" class="mb-3">
              <div v-if="msg.is_system" class="text-center my-2">
                <v-chip size="small" variant="text" class="text-medium-emphasis font-italic">{{ msg.text }}</v-chip>
              </div>
              <div v-else :class="['d-flex', msg.is_mine ? 'justify-end' : 'justify-start']">
                <div class="d-flex ga-2" :class="{ 'flex-row-reverse': msg.is_mine }" style="max-width: 75%">
                  <v-avatar size="32" :color="msg.is_mine ? 'primary' : 'secondary'" class="flex-shrink-0 mt-1">
                    <span class="text-caption text-white font-weight-bold">{{ getInitials(msg.sender_name) }}</span>
                  </v-avatar>
                  <div>
                    <div v-if="!msg.is_mine" class="text-caption font-weight-medium mb-1">{{ msg.sender_name }}</div>
                    <div :class="['pa-3 rounded-lg', msg.is_mine ? 'bg-primary text-white' : 'bg-grey-lighten-4']" style="word-break: break-word">
                      <div class="text-body-2" style="white-space: pre-line">{{ msg.text }}</div>
                      <div v-if="msg.attachment_url" class="mt-1">
                        <a :href="msg.attachment_url" target="_blank" :class="msg.is_mine ? 'text-white' : 'text-primary'" class="text-caption d-flex align-center ga-1">
                          <v-icon size="14">mdi-paperclip</v-icon> {{ msg.attachment_name || 'Вложение' }}
                        </a>
                      </div>
                    </div>
                    <div :class="['text-caption mt-1', msg.is_mine ? 'text-right' : '']" style="color: rgba(0,0,0,0.4)">{{ msg.created_at }}</div>
                  </div>
                </div>
              </div>
            </div>
            <!-- Typing indicator -->
            <div v-if="typingUser" class="text-caption text-medium-emphasis font-italic mb-2">
              {{ typingUser }} печатает...
            </div>
            <EmptyState v-if="!messages.length && !loadingMessages" icon="mdi-message-outline" message="Нет сообщений" />
          </div>

          <!-- Input -->
          <div v-if="selectedTicket.status !== 'closed'" class="pa-3 border-t">
            <div class="d-flex ga-2 align-end">
              <v-textarea v-model="replyText" placeholder="Введите сообщение..."
                rows="1" auto-grow max-rows="5" hide-details class="flex-grow-1"
                @keydown.enter.exact.prevent="sendMessage" @input="emitTypingThrottled" />
              <input ref="fileInput" type="file" hidden @change="onFileSelected" />
              <v-btn icon size="small" variant="text" @click="$refs.fileInput.click()">
                <v-icon>mdi-paperclip</v-icon>
              </v-btn>
              <v-btn icon color="primary" :loading="sendingMessage" @click="sendMessage">
                <v-icon>mdi-send</v-icon>
              </v-btn>
            </div>
            <div v-if="selectedFile" class="text-caption text-medium-emphasis mt-1 d-flex align-center ga-1">
              <v-icon size="14">mdi-file</v-icon> {{ selectedFile.name }}
              <v-btn icon size="x-small" variant="text" @click="selectedFile = null"><v-icon size="14">mdi-close</v-icon></v-btn>
            </div>
          </div>
          <div v-else class="pa-3 border-t text-center">
            <v-chip size="small" color="grey" variant="tonal"><v-icon start size="14">mdi-lock</v-icon> Обращение закрыто</v-chip>
          </div>
        </v-card>

        <v-card v-else class="d-flex align-center justify-center" style="height: calc(100vh - 200px); min-height: 300px">
          <div class="text-center">
            <v-icon size="80" color="grey-lighten-1">mdi-chat-outline</v-icon>
            <div class="text-h6 text-medium-emphasis mt-3">Выберите обращение</div>
            <div class="text-body-2 text-medium-emphasis">или создайте новое</div>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Create dialog -->
    <v-dialog v-model="createDialog" max-width="560" persistent>
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="primary">mdi-plus-circle</v-icon>
          {{ isOwnerChat ? 'Написать собственнику' : 'Новое обращение' }}
        </v-card-title>
        <v-card-text>
          <v-select v-if="!isOwnerChat" v-model="createForm.category" :items="categoryOptions" label="Категория" class="mb-3" />
          <v-text-field v-model="createForm.subject" label="Тема обращения" class="mb-3" />
          <v-textarea v-model="createForm.message" label="Сообщение" rows="5" auto-grow />
          <v-alert v-if="createError" type="error" density="compact" class="mt-2">{{ createError }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="createDialog = false; isOwnerChat = false">Отмена</v-btn>
          <v-btn color="primary" :loading="creating" prepend-icon="mdi-send" @click="createTicket">Отправить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, nextTick, onMounted, onUnmounted, watch, computed } from 'vue';
import { useDisplay } from 'vuetify';
import { useRoute } from 'vue-router';
import api from '../api';
import PageHeader from '../components/PageHeader.vue';
import EmptyState from '../components/EmptyState.vue';
import { getInitials, statusLabels, categoryLabels, getCategoryColor, getStatusColor } from '../composables/useDesign';

const { mobile } = useDisplay();
const route = useRoute();

// State
const tickets = ref([]);
const loadingTickets = ref(false);
const ticketPage = ref(1);
const ticketPages = ref(1);
const statusFilter = ref('all');
const totalUnread = ref(0);

const selectedTicket = ref(null);
const messages = ref([]);
const loadingMessages = ref(false);
const replyText = ref('');
const selectedFile = ref(null);
const sendingMessage = ref(false);
const messagesContainer = ref(null);
const fileInput = ref(null);
const typingUser = ref(null);

const createDialog = ref(false);
const creating = ref(false);
const createError = ref('');
const createForm = ref({ category: 'support', subject: '', message: '' });
const isOwnerChat = ref(false);

let refreshInterval = null;
let socket = null;
let typingTimeout = null;
let lastTypingEmit = 0;

const categoryOptions = [
  { title: 'Техподдержка', value: 'support' },
  { title: 'Бэк-офис', value: 'backoffice' },
  { title: 'Юридический', value: 'legal' },
  { title: 'Бухгалтерия', value: 'accounting' },
  { title: 'Начисления', value: 'accruals' },
];

// Helpers
function categoryColor(cat) { return getCategoryColor(cat); }
function categoryLabel(cat) { return categoryLabels[cat] || cat; }
function categoryIcon(cat) {
  const map = { support: 'mdi-headset', backoffice: 'mdi-briefcase', legal: 'mdi-scale-balance', accounting: 'mdi-calculator', accruals: 'mdi-cash-multiple' };
  return map[cat] || 'mdi-help-circle';
}
function statusColor(s) { return getStatusColor(s); }
function statusLabel(s) { return statusLabels[s] || s; }
function formatDate(d) {
  if (!d) return '';
  const date = new Date(d);
  if (isNaN(date.getTime())) return '';
  const now = new Date();
  if (date.toDateString() === now.toDateString()) return date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  return date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
}

// Socket.IO — initialized once, reused
async function initSocket() {
  if (socket?.connected) return socket;
  try {
    const { io } = await import('socket.io-client');
    const host = window.__SOCKET_URL__ || (location.protocol === 'https:' ? 'wss:' : 'ws:') + '//' + location.hostname + ':3001';
    const userId = localStorage.getItem('auth_user_id');
    const userName = localStorage.getItem('auth_user_name') || '';
    socket = io(host, {
      query: { userId, userName },
      transports: ['websocket', 'polling'],
      reconnection: true,
      reconnectionDelay: 2000,
      reconnectionAttempts: 10,
    });
    socket.on('connect', () => console.log('[Socket] Connected:', socket.id));
    socket.on('disconnect', () => console.log('[Socket] Disconnected'));
    // Global notification listener
    socket.on('notification', (data) => {
      totalUnread.value = (totalUnread.value || 0) + 1;
    });
  } catch (e) {
    console.warn('[Socket] Failed to connect:', e.message);
  }
  return socket;
}

function joinTicketRoom(ticketId) {
  if (!socket?.connected) return;
  socket.emit('ticket:join', ticketId);
  socket.off('ticket:new-message');
  socket.off('ticket:typing');

  socket.on('ticket:new-message', (data) => {
    if (data.ticketId != ticketId) return;
    const authId = localStorage.getItem('auth_user_id');
    if (String(data.userId) !== String(authId)) {
      messages.value.push({
        id: Date.now(),
        text: data.message || data.text,
        sender_name: data.userName,
        is_mine: false,
        is_system: data.isSystem || false,
        attachment_url: data.attachmentUrl,
        attachment_name: data.attachmentName,
        created_at: new Date().toLocaleString('ru-RU'),
      });
      scrollToBottom();
    }
  });

  socket.on('ticket:typing', (data) => {
    const authId = localStorage.getItem('auth_user_id');
    if (String(data.userId) === String(authId)) return;
    typingUser.value = data.userName;
    clearTimeout(typingTimeout);
    typingTimeout = setTimeout(() => { typingUser.value = null; }, 3000);
  });
}

function leaveTicketRoom(ticketId) {
  if (!socket?.connected) return;
  socket.emit('ticket:leave', ticketId);
  socket.off('ticket:new-message');
  socket.off('ticket:typing');
}

function emitTypingThrottled() {
  if (!socket?.connected || !selectedTicket.value) return;
  const now = Date.now();
  if (now - lastTypingEmit < 2000) return;
  lastTypingEmit = now;
  socket.emit('ticket:typing', { ticketId: selectedTicket.value.id, isTyping: true });
}

// API calls
async function loadTickets() {
  loadingTickets.value = true;
  try {
    const params = { page: ticketPage.value };
    if (statusFilter.value !== 'all') params.status = statusFilter.value;
    const { data } = await api.get('/tickets', { params });
    tickets.value = data.data || data;
    ticketPages.value = data.last_page || 1;
  } catch {}
  loadingTickets.value = false;
}

async function loadUnread() {
  try {
    const { data } = await api.get('/tickets/unread-count');
    totalUnread.value = data.count || 0;
  } catch {}
}

async function selectTicket(ticket) {
  // Leave previous room
  if (selectedTicket.value) leaveTicketRoom(selectedTicket.value.id);
  selectedTicket.value = ticket;
  await loadTicketMessages();
  // Join new room
  joinTicketRoom(ticket.id);
  startPolling();
}

async function loadTicketMessages() {
  if (!selectedTicket.value?.id) return;
  loadingMessages.value = true;
  try {
    const { data } = await api.get(`/tickets/${selectedTicket.value.id}/messages`);
    messages.value = data.data || data;
    scrollToBottom();
    if (selectedTicket.value.unread_count > 0) {
      api.post(`/tickets/${selectedTicket.value.id}/read`).catch(() => {});
      selectedTicket.value.unread_count = 0;
      loadUnread();
    }
  } catch {}
  loadingMessages.value = false;
}

function scrollToBottom() {
  nextTick(() => {
    const el = messagesContainer.value;
    if (el) el.scrollTop = el.scrollHeight;
  });
}

async function sendMessage() {
  if (!replyText.value?.trim() && !selectedFile.value) return;
  sendingMessage.value = true;
  try {
    const fd = new FormData();
    fd.append('message', replyText.value || '');
    if (selectedFile.value) fd.append('attachment', selectedFile.value);
    await api.post(`/tickets/${selectedTicket.value.id}/messages`, fd);
    replyText.value = '';
    selectedFile.value = null;
    await loadTicketMessages();
  } catch {}
  sendingMessage.value = false;
}

function onFileSelected(e) { selectedFile.value = e.target.files?.[0] || null; }

// Create ticket
function openCreateDialog(ownerMode = false) {
  isOwnerChat.value = ownerMode === true;
  createForm.value = {
    category: ownerMode ? 'support' : 'support',
    subject: ownerMode ? 'Сообщение собственнику' : '',
    message: '',
  };
  createError.value = '';
  createDialog.value = true;
}

async function createTicket() {
  if (!createForm.value.subject?.trim() || !createForm.value.message?.trim()) {
    createError.value = 'Заполните тему и сообщение';
    return;
  }
  creating.value = true;
  createError.value = '';
  try {
    const payload = { ...createForm.value };
    if (isOwnerChat.value) {
      payload.category = 'owner';
      payload.context_type = 'owner_message';
    }
    const { data } = await api.post('/tickets', payload);
    createDialog.value = false;
    isOwnerChat.value = false;
    await loadTickets();
    selectTicket(data.ticket || data);
  } catch (e) {
    createError.value = e.response?.data?.message || 'Ошибка создания обращения';
  }
  creating.value = false;
}

// Polling fallback
function startPolling() {
  stopPolling();
  refreshInterval = setInterval(loadTicketMessages, 15000);
}
function stopPolling() {
  if (refreshInterval) { clearInterval(refreshInterval); refreshInterval = null; }
}

// Watchers
watch(statusFilter, () => { ticketPage.value = 1; loadTickets(); });

// Watch route query for ?to=owner or ?type=case (works even without remount)
watch(() => route.query, (q) => {
  if (q.to === 'owner') openCreateDialog(true);
  else if (q.type === 'case') {
    isOwnerChat.value = false;
    createForm.value = { category: 'support', subject: 'Кейс', message: '' };
    createError.value = '';
    createDialog.value = true;
  }
}, { immediate: false });

// Lifecycle
onMounted(async () => {
  loadTickets();
  loadUnread();
  await initSocket();
  // Check initial query
  if (route.query.to === 'owner') openCreateDialog(true);
  else if (route.query.type === 'case') {
    createForm.value = { category: 'support', subject: 'Кейс', message: '' };
    createDialog.value = true;
  }
});

onUnmounted(() => {
  if (selectedTicket.value) leaveTicketRoom(selectedTicket.value.id);
  stopPolling();
  if (socket) {
    socket.off('ticket:new-message');
    socket.off('ticket:typing');
    socket.off('notification');
  }
});
</script>

<style scoped>
.border-b { border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.border-t { border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }

/* Chat message bubbles */
.bg-primary .text-caption { color: rgba(255,255,255,0.7) !important; }
.bg-grey-lighten-4 { background: rgba(var(--v-theme-surface-variant), 1) !important; }

/* Smooth scroll for messages */
.overflow-y-auto { scroll-behavior: smooth; }

/* Ticket list hover */
:deep(.v-list-item:hover) { background: rgba(var(--v-theme-primary), 0.04); }
:deep(.v-list-item--active) { background: rgba(var(--v-theme-primary), 0.08) !important; }

/* Input area styling */
.border-t :deep(.v-field) { border-radius: 20px; }
</style>
