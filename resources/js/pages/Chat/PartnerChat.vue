<template>
  <div class="chat-wrap">
    <!-- Left sidebar: dialog list -->
    <aside class="chat-sidebar" :class="{ 'mobile-hidden': mobile && activeChat }">
      <div class="sidebar-head">
        <h3>Мои обращения</h3>
        <button class="btn-new" @click="showNew = true"><v-icon size="18">mdi-plus</v-icon> Новый чат</button>
      </div>
      <div class="sidebar-quick">
        <button class="quick-btn" @click="openFounder"><v-icon size="14">mdi-email-edit</v-icon> Написать основателю</button>
        <button class="quick-btn" @click="openCase"><v-icon size="14">mdi-briefcase-plus</v-icon> Оставить кейс</button>
      </div>
      <!-- Search + filter chips -->
      <div class="sidebar-search">
        <v-icon size="16">mdi-magnify</v-icon>
        <input v-model="searchQuery" type="text" placeholder="Поиск по теме…" />
        <button v-if="searchQuery" class="clear-btn" @click="searchQuery = ''"><v-icon size="14">mdi-close</v-icon></button>
      </div>
      <div class="filter-row">
        <button v-for="opt in statusFilters" :key="opt.value"
          class="filter-chip" :class="{ active: statusFilter === opt.value }"
          @click="statusFilter = opt.value">{{ opt.label }}</button>
      </div>
      <div class="filter-row">
        <button v-for="opt in categoryFilters" :key="opt.value"
          class="filter-chip small" :class="{ active: categoryFilter === opt.value }"
          :style="categoryFilter === opt.value ? { background: catColor(opt.value) + '22', color: catColor(opt.value), borderColor: catColor(opt.value) } : {}"
          @click="categoryFilter = opt.value">{{ opt.label }}</button>
      </div>
      <div class="sidebar-list">
        <div v-for="t in visibleChats" :key="t.id" class="chat-item" :class="{ active: activeChat?.id === t.id, 'has-unread': t.unread > 0 }" @click="openChat(t)">
          <div class="chat-item-avatar" :style="{ background: catColor(t.category) }">
            <v-icon size="18" color="white">{{ catIcon(t.category) }}</v-icon>
          </div>
          <div class="chat-item-body">
            <div class="chat-item-top">
              <span class="chat-item-subject">{{ t.subject }}</span>
              <span class="chat-item-time">{{ ago(t.last_message_at) }}</span>
            </div>
            <div class="chat-item-bottom">
              <span class="chat-item-cat">{{ catLabel(t.category) }}</span>
              <span class="chat-item-status-chip" :style="{ background: statusClr(t.status) + '22', color: statusClr(t.status) }">{{ statusTxt(t.status) }}</span>
            </div>
          </div>
          <span v-if="t.unread > 0" class="unread-badge">{{ t.unread }}</span>
        </div>
        <div v-if="!visibleChats.length && !loading" class="sidebar-empty">
          <v-icon size="40" color="grey">mdi-chat-outline</v-icon>
          <p v-if="chats.length">Ничего не найдено по фильтрам</p>
          <p v-else>Нет обращений</p>
          <button v-if="!chats.length" class="btn-new small" @click="showNew = true">Создать первое</button>
          <button v-else-if="searchQuery || statusFilter !== 'all' || categoryFilter !== 'all'" class="btn-new small" @click="resetFilters">Сбросить фильтры</button>
        </div>
      </div>
    </aside>

    <!-- Center: chat area -->
    <main class="chat-main" :class="{ 'mobile-hidden': mobile && !activeChat }">
      <template v-if="activeChat">
        <!-- Header -->
        <div class="chat-header">
          <button v-if="mobile" class="btn-back" @click="closeActiveChat"><v-icon>mdi-arrow-left</v-icon></button>
          <div class="chat-header-info">
            <div class="chat-header-subject">{{ activeChat.subject }}</div>
            <div class="chat-header-meta">
              <span class="meta-cat" :style="{ background: catColor(activeChat.category) + '22', color: catColor(activeChat.category) }">{{ catLabel(activeChat.category) }}</span>
              <span class="meta-status-chip" :style="{ background: statusClr(activeChat.status) + '22', color: statusClr(activeChat.status) }">
                <v-icon size="10">{{ statusIcon(activeChat.status) }}</v-icon>
                {{ statusTxt(activeChat.status) }}
              </span>
              <span v-if="activeChat.assigned_name" class="meta-agent">
                <v-icon size="12">mdi-account</v-icon> {{ activeChat.assigned_name }}
              </span>
            </div>
          </div>
        </div>

        <!-- Messages -->
        <div ref="msgsRef" class="chat-messages" @scroll="onMessagesScroll">
          <template v-for="item in groupedMessages" :key="item.key">
            <!-- Date divider -->
            <div v-if="item.type === 'divider'" class="date-divider">
              <span>{{ item.label }}</span>
            </div>
            <!-- System message -->
            <div v-else-if="item.msg.isSystem" class="msg-row system">
              <div class="msg-system">{{ item.msg.content }}</div>
            </div>
            <!-- Regular message -->
            <div v-else class="msg-row" :class="{ mine: isMine(item.msg) }">
              <div class="msg-avatar" v-if="!isMine(item.msg)">
                <div class="avatar-circle agent">{{ initials(item.msg.senderName) }}</div>
              </div>
              <div class="msg-bubble" :class="isMine(item.msg) ? 'mine' : 'agent'">
                <div class="msg-sender">{{ item.msg.senderName }}</div>
                <div v-if="item.msg.content" class="msg-text">{{ item.msg.content }}</div>
                <template v-if="item.msg.attachmentPath">
                  <a v-if="isImageAttachment(item.msg.attachmentPath)"
                    :href="item.msg.attachmentPath" target="_blank" class="msg-image-link">
                    <img :src="item.msg.attachmentPath" :alt="item.msg.attachmentName || 'Изображение'" class="msg-image" loading="lazy" />
                  </a>
                  <a v-else :href="item.msg.attachmentPath" target="_blank" class="msg-attach">
                    <v-icon size="14">mdi-paperclip</v-icon> {{ item.msg.attachmentName || 'Файл' }}
                  </a>
                </template>
                <div class="msg-time">{{ fmtTime(item.msg.createdAt) }}</div>
              </div>
              <div class="msg-avatar" v-if="isMine(item.msg)">
                <div class="avatar-circle mine">{{ initials(item.msg.senderName) }}</div>
              </div>
            </div>
          </template>
          <div v-if="!messages.length" class="chat-empty-msg">Напишите первое сообщение</div>

          <!-- Typing indicator -->
          <div v-if="typingName" class="typing-indicator">
            <span class="typing-dots"><span></span><span></span><span></span></span>
            {{ typingName }} печатает…
          </div>
        </div>

        <!-- Jump to bottom button -->
        <button v-if="showJumpToBottom" class="jump-to-bottom" @click="scrollDown(true)">
          <v-icon size="16">mdi-arrow-down</v-icon>
          <span v-if="pendingMessages > 0">{{ pendingMessages }}</span>
        </button>

        <!-- Input -->
        <div v-if="activeChat.status !== 'closed'" class="chat-input"
          :class="{ 'drag-over': dragOver }"
          @dragover.prevent="dragOver = true"
          @dragleave.prevent="dragOver = false"
          @drop.prevent="onFileDrop">
          <input ref="fileRef" type="file" hidden @change="e => setFile(e.target.files?.[0])" />
          <button class="input-btn" title="Прикрепить файл" @click="$refs.fileRef.click()"><v-icon size="20">mdi-paperclip</v-icon></button>
          <div class="input-area">
            <textarea ref="taRef" v-model="msgText"
              placeholder="Введите сообщение… (Enter — отправить, Shift+Enter — перенос строки)"
              rows="1"
              @keydown.enter.exact.prevent="send"
              @input="onInput"
              @paste="onPaste"></textarea>
            <div v-if="file" class="input-file-preview">
              <img v-if="filePreviewUrl" :src="filePreviewUrl" alt="preview" />
              <div v-else class="input-file-icon"><v-icon size="16">mdi-file</v-icon></div>
              <div class="input-file-info">
                <div class="input-file-name">{{ file.name }}</div>
                <div class="input-file-size">{{ fmtFileSize(file.size) }}</div>
              </div>
              <button class="input-file-remove" @click="clearFile"><v-icon size="14">mdi-close</v-icon></button>
            </div>
          </div>
          <button class="input-send" :disabled="sending || (!msgText.trim() && !file)" title="Отправить (Enter)" @click="send">
            <v-icon size="20">mdi-send</v-icon>
          </button>
          <div v-if="dragOver" class="drop-overlay">
            <v-icon size="32">mdi-file-upload</v-icon>
            <span>Отпустите файл для прикрепления</span>
          </div>
        </div>
        <div v-else class="chat-closed">
          <v-icon size="16">mdi-lock</v-icon> Чат закрыт
        </div>
      </template>

      <!-- No chat selected -->
      <div v-else class="chat-placeholder">
        <v-icon size="64" color="grey-lighten-2">mdi-forum-outline</v-icon>
        <p>Выберите чат или создайте новый</p>
      </div>
    </main>

    <!-- New chat dialog -->
    <v-dialog v-model="showNew" max-width="480" persistent>
      <v-card>
        <v-card-title>Новое обращение</v-card-title>
        <v-card-text>
          <v-select v-model="newForm.category" :items="categories" item-title="label" item-value="value" label="Категория вопроса" class="mb-3" />
          <v-text-field v-model="newForm.subject" label="Тема" class="mb-3" />
          <v-textarea v-model="newForm.message" label="Ваш вопрос" rows="4" auto-grow />
          <v-alert v-if="newErr" type="error" density="compact" class="mt-2">{{ newErr }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="showNew = false">Отмена</v-btn>
          <v-btn color="primary" :loading="creating" @click="createChat">Отправить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue';
import { useDisplay } from 'vuetify';
import { useRoute } from 'vue-router';
import api from '../../api';
import { useAuthStore } from '../../stores/auth';

const { mobile } = useDisplay();
const route = useRoute();
const auth = useAuthStore();
const currentUserId = auth.userId;

const chats = ref([]);
const loading = ref(false);
const activeChat = ref(null);
const messages = ref([]);
const msgText = ref('');
const file = ref(null);
const sending = ref(false);
const msgsRef = ref(null);
const fileRef = ref(null);
const taRef = ref(null);
let poll = null;

// Socket
let socket = null;
const typingName = ref('');
let typingClearTimer = null;
let typingSendTimer = null;

// Scroll state
const showJumpToBottom = ref(false);
const pendingMessages = ref(0);
const BASE_TITLE = 'Обращения';

// Drag-drop + file preview
const dragOver = ref(false);
const filePreviewUrl = ref(null);

// Filters + search
const searchQuery = ref('');
const statusFilter = ref('all');
const categoryFilter = ref('all');
const statusFilters = [
  { label: 'Все', value: 'all' },
  { label: 'Новые', value: 'new' },
  { label: 'В работе', value: 'open' },
  { label: 'Ожидание', value: 'pending' },
  { label: 'Решён', value: 'resolved' },
  { label: 'Закрыт', value: 'closed' },
];
const categoryFilters = [
  { label: 'Все', value: 'all' },
  { label: 'Техподдержка', value: 'support' },
  { label: 'Бэк-офис', value: 'backoffice' },
  { label: 'Начисления', value: 'billing' },
  { label: 'Юридический', value: 'legal' },
  { label: 'Общий', value: 'general' },
];
function resetFilters() {
  searchQuery.value = '';
  statusFilter.value = 'all';
  categoryFilter.value = 'all';
}
const visibleChats = computed(() => {
  let list = chats.value;
  if (statusFilter.value !== 'all') list = list.filter(t => t.status === statusFilter.value);
  if (categoryFilter.value !== 'all') list = list.filter(t => t.category === categoryFilter.value);
  const q = searchQuery.value.trim().toLowerCase();
  if (q) list = list.filter(t => String(t.subject || '').toLowerCase().includes(q));
  return list;
});

// New chat dialog
const showNew = ref(false);
const creating = ref(false);
const newErr = ref('');
const newForm = ref({ category: 'support', subject: '', message: '' });

const categories = [
  { label: 'Техподдержка', value: 'support' },
  { label: 'Бэк-офис / Документы', value: 'backoffice' },
  { label: 'Начисления и выплаты', value: 'billing' },
  { label: 'Юридический вопрос', value: 'legal' },
  { label: 'Общий вопрос', value: 'general' },
];

function isMine(msg) { return String(msg.senderId) === String(currentUserId); }
function catColor(c) { return { support: '#3b82f6', backoffice: '#f97316', billing: '#22c55e', legal: '#a855f7', general: '#6b7280' }[c] || '#6b7280'; }
function catIcon(c) { return { support: 'mdi-headset', backoffice: 'mdi-briefcase', billing: 'mdi-cash', legal: 'mdi-scale-balance', general: 'mdi-help-circle' }[c] || 'mdi-chat'; }
function catLabel(c) { return { support: 'Техподдержка', backoffice: 'Бэк-офис', billing: 'Начисления', legal: 'Юридический', general: 'Общий' }[c] || c; }
function statusClr(s) { return { new: '#60a5fa', open: '#fbbf24', pending: '#f97316', resolved: '#34d399', closed: '#6b7280' }[s] || '#888'; }
function statusTxt(s) { return { new: 'Новый', open: 'В работе', pending: 'Ожидание', resolved: 'Решён', closed: 'Закрыт' }[s] || s; }
function statusIcon(s) { return { new: 'mdi-circle-outline', open: 'mdi-progress-clock', pending: 'mdi-pause-circle', resolved: 'mdi-check-circle', closed: 'mdi-lock' }[s] || 'mdi-circle'; }
function initials(name) {
  if (!name) return '?';
  const parts = String(name).trim().split(/\s+/);
  return (parts[0]?.[0] || '').toUpperCase() + (parts[1]?.[0] || '').toUpperCase();
}
function ago(d) { if (!d) return ''; const s = Math.floor((Date.now() - new Date(d).getTime()) / 1000); if (s < 60) return 'сейчас'; if (s < 3600) return Math.floor(s/60) + 'м'; if (s < 86400) return Math.floor(s/3600) + 'ч'; return Math.floor(s/86400) + 'д'; }
function fmtTime(d) { if (!d) return ''; const dt = new Date(d); if (isNaN(dt)) return ''; return dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }); }

function dateLabel(date) {
  const d = new Date(date);
  const today = new Date();
  const yesterday = new Date(today); yesterday.setDate(today.getDate() - 1);
  if (d.toDateString() === today.toDateString()) return 'Сегодня';
  if (d.toDateString() === yesterday.toDateString()) return 'Вчера';
  const diffDays = Math.abs((today - d) / 86400000);
  if (diffDays < 7) return d.toLocaleDateString('ru-RU', { weekday: 'long' });
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: today.getFullYear() === d.getFullYear() ? undefined : 'numeric' });
}

// Group messages with date dividers
const groupedMessages = computed(() => {
  const out = [];
  let prevDay = null;
  for (const msg of messages.value) {
    const day = msg.createdAt ? new Date(msg.createdAt).toDateString() : null;
    if (day && day !== prevDay) {
      out.push({ type: 'divider', key: `d-${day}`, label: dateLabel(msg.createdAt) });
      prevDay = day;
    }
    out.push({ type: 'msg', key: `m-${msg.id}`, msg });
  }
  return out;
});

// Scroll helpers
function isAtBottom(threshold = 80) {
  const el = msgsRef.value;
  if (!el) return true;
  return el.scrollHeight - el.scrollTop - el.clientHeight < threshold;
}
function onMessagesScroll() {
  if (isAtBottom()) {
    showJumpToBottom.value = false;
    pendingMessages.value = 0;
  } else {
    showJumpToBottom.value = true;
  }
}
function scrollDown(force = false) {
  nextTick(() => {
    const el = msgsRef.value;
    if (!el) return;
    if (force || isAtBottom()) {
      el.scrollTop = el.scrollHeight;
      pendingMessages.value = 0;
      showJumpToBottom.value = false;
    }
  });
}

function autoGrow() {
  const t = taRef.value;
  if (!t) return;
  t.style.height = 'auto';
  t.style.height = Math.min(t.scrollHeight, 120) + 'px';
}

// File handling
const IMAGE_EXT = /\.(jpe?g|png|gif|webp|bmp|svg)(\?|$)/i;
function isImageAttachment(path) {
  return !!path && IMAGE_EXT.test(path);
}
function fmtFileSize(bytes) {
  if (!bytes) return '';
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / 1024 / 1024).toFixed(1) + ' MB';
}
function setFile(f) {
  if (!f) return;
  file.value = f;
  if (filePreviewUrl.value) URL.revokeObjectURL(filePreviewUrl.value);
  filePreviewUrl.value = f.type?.startsWith('image/') ? URL.createObjectURL(f) : null;
}
function clearFile() {
  if (filePreviewUrl.value) URL.revokeObjectURL(filePreviewUrl.value);
  filePreviewUrl.value = null;
  file.value = null;
}
function onFileDrop(e) {
  dragOver.value = false;
  const f = e.dataTransfer?.files?.[0];
  if (f) setFile(f);
}
function onPaste(e) {
  const items = e.clipboardData?.items || [];
  for (const it of items) {
    if (it.kind === 'file') {
      const f = it.getAsFile();
      if (f) { setFile(f); e.preventDefault(); return; }
    }
  }
}

// Draft autosave per ticket
function draftKey(ticketId) { return `chat-draft-${ticketId}`; }
watch(msgText, (v) => {
  if (activeChat.value) {
    if (v) localStorage.setItem(draftKey(activeChat.value.id), v);
    else localStorage.removeItem(draftKey(activeChat.value.id));
  }
  nextTick(autoGrow);
  sendTyping();
});

// Typing (debounced emit)
function sendTyping() {
  if (!socket || !activeChat.value) return;
  if (typingSendTimer) return; // throttle to once per 2.5s
  socket.emit('ticket:typing', { ticketId: activeChat.value.id, isTyping: true });
  typingSendTimer = setTimeout(() => {
    socket?.emit('ticket:typing', { ticketId: activeChat.value?.id, isTyping: false });
    typingSendTimer = null;
  }, 2500);
}

// Unread counter in browser title
function updateTitle() {
  const total = chats.value.reduce((s, t) => s + (t.unread || 0), 0);
  document.title = total > 0 ? `(${total}) ${BASE_TITLE}` : BASE_TITLE;
}
watch(chats, updateTitle, { deep: true });

async function loadChats() {
  loading.value = true;
  try { const { data } = await api.get('/chat/tickets'); chats.value = data.data || []; } catch {}
  loading.value = false;
}

async function openChat(t) {
  // Leave previous ticket room
  if (socket && activeChat.value) socket.emit('ticket:leave', activeChat.value.id);

  activeChat.value = t;
  typingName.value = '';
  try {
    const { data } = await api.get(`/chat/tickets/${t.id}`);
    messages.value = data.messages || [];
    if (t.unread > 0) { t.unread = 0; }
    scrollDown(true);
  } catch {}

  // Restore draft
  msgText.value = localStorage.getItem(draftKey(t.id)) || '';
  nextTick(() => { taRef.value?.focus(); autoGrow(); });

  // Join new room
  if (socket) socket.emit('ticket:join', t.id);

  startPoll();
}

function closeActiveChat() {
  if (socket && activeChat.value) socket.emit('ticket:leave', activeChat.value.id);
  activeChat.value = null;
  typingName.value = '';
}

async function refreshMessages() {
  if (!activeChat.value) return;
  try {
    const { data } = await api.get(`/chat/tickets/${activeChat.value.id}`);
    const wasAtBottom = isAtBottom();
    const prevCount = messages.value.length;
    messages.value = data.messages || [];
    if (messages.value.length > prevCount) {
      if (wasAtBottom) scrollDown(true);
      else pendingMessages.value += messages.value.length - prevCount;
    }
  } catch {}
}

async function send() {
  if (!msgText.value?.trim() && !file.value) return;
  sending.value = true;
  try {
    const fd = new FormData();
    fd.append('message', msgText.value || '');
    if (file.value) fd.append('attachment', file.value);
    await api.post(`/chat/tickets/${activeChat.value.id}/messages`, fd);
    // Clear draft on successful send
    localStorage.removeItem(draftKey(activeChat.value.id));
    msgText.value = '';
    clearFile();
    nextTick(autoGrow);
    await refreshMessages();
    scrollDown(true);
    activeChat.value.unread = 0;
    loadChats();
    taRef.value?.focus();
  } catch {}
  sending.value = false;
}

function onInput() {
  autoGrow();
}

async function createChat() {
  if (!newForm.value.subject?.trim() || !newForm.value.message?.trim()) { newErr.value = 'Заполните все поля'; return; }
  creating.value = true; newErr.value = '';
  try {
    const { data } = await api.post('/chat/tickets', { ...newForm.value, department: newForm.value.category });
    showNew.value = false;
    newForm.value = { category: 'support', subject: '', message: '' };
    await loadChats();
    if (data.ticket) openChat(data.ticket);
  } catch (e) { newErr.value = e.response?.data?.message || 'Ошибка'; }
  creating.value = false;
}

function startPoll() {
  stopPoll();
  poll = setInterval(() => { refreshMessages(); loadChats(); }, 15000); // slower since socket handles real-time
}
function stopPoll() { if (poll) { clearInterval(poll); poll = null; } }

function openFounder() {
  newForm.value = { category: 'general', subject: 'Сообщение основателю', message: '' };
  showNew.value = true;
}

function openCase() {
  newForm.value = { category: 'general', subject: 'Кейс', message: '' };
  showNew.value = true;
}

function checkQuery() {
  if (route.query.to === 'founder') {
    newForm.value = { category: 'general', subject: 'Сообщение основателю', message: '' };
    showNew.value = true;
  } else if (route.query.type === 'case') {
    newForm.value = { category: 'general', subject: 'Кейс', message: '' };
    showNew.value = true;
  }
}

// Socket connection
async function connectSocket() {
  const token = localStorage.getItem('auth_token');
  if (!token) return;
  try {
    const { io } = await import('socket.io-client');
    const host = window.__SOCKET_URL__ || (location.protocol === 'https:' ? 'wss:' : 'ws:') + '//' + location.hostname + ':3001';
    socket = io(host, { auth: { token }, transports: ['websocket', 'polling'], reconnection: true });

    socket.on('chat:new-message', (m) => {
      if (!activeChat.value || Number(m.ticketId) !== Number(activeChat.value.id)) return;
      // Dedupe by id (message may arrive via socket + refresh)
      if (messages.value.some(x => String(x.id) === String(m.id))) return;
      const wasAtBottom = isAtBottom();
      messages.value.push({
        id: m.id,
        senderId: m.senderId,
        senderName: m.senderName,
        content: m.content,
        isSystem: false,
        createdAt: m.createdAt,
      });
      if (wasAtBottom) scrollDown(true);
      else pendingMessages.value++;
      // Refresh list to update last message time
      loadChats();
    });

    socket.on('ticket:typing', (e) => {
      if (!activeChat.value || String(e.userId) === String(currentUserId)) return;
      typingName.value = e.userName || 'Собеседник';
      if (typingClearTimer) clearTimeout(typingClearTimer);
      typingClearTimer = setTimeout(() => { typingName.value = ''; }, 3500);
    });

    socket.on('chat:new-ticket', () => {
      // Refresh list when a new ticket appears anywhere (staff would see it)
      loadChats();
    });
  } catch (e) {
    // Socket unavailable — polling keeps the UI alive
    console.warn('Chat socket unavailable, falling back to polling:', e?.message);
  }
}

watch(() => route.query, checkQuery, { immediate: false });

onMounted(() => {
  loadChats();
  checkQuery();
  connectSocket();
});

onUnmounted(() => {
  stopPoll();
  if (socket && activeChat.value) socket.emit('ticket:leave', activeChat.value.id);
  socket?.disconnect();
  document.title = BASE_TITLE;
});
</script>

<style scoped>
.chat-wrap { display: flex; height: calc(100vh - 64px); overflow: hidden; position: relative; }

/* Sidebar */
.chat-sidebar { width: 340px; flex-shrink: 0; border-right: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); display: flex; flex-direction: column; background: rgba(var(--v-theme-surface), 1); }
.sidebar-head { display: flex; align-items: center; justify-content: space-between; padding: 16px; border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.sidebar-head h3 { font-size: 16px; font-weight: 700; margin: 0; }
.btn-new { display: flex; align-items: center; gap: 4px; padding: 6px 14px; border-radius: 10px; border: none; background: rgb(var(--v-theme-primary)); color: #fff; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-new.small { margin-top: 8px; }
.sidebar-quick { display: flex; gap: 6px; padding: 8px 16px; border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.quick-btn { display: flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 8px; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); background: transparent; color: inherit; font-size: 11px; cursor: pointer; white-space: nowrap; }
.quick-btn:hover { background: rgba(var(--v-theme-primary), 0.08); }
.sidebar-list { flex: 1; overflow-y: auto; }
.sidebar-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 48px 16px; gap: 8px; color: rgba(var(--v-theme-on-surface), 0.4); text-align: center; }
.sidebar-empty p { margin: 0; font-size: 13px; }

/* Search + filters */
.sidebar-search { display: flex; align-items: center; gap: 6px; padding: 8px 12px; border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); color: rgba(var(--v-theme-on-surface), 0.5); }
.sidebar-search input { flex: 1; border: none; outline: none; background: transparent; color: inherit; font-size: 13px; font-family: inherit; }
.sidebar-search .clear-btn { background: none; border: none; cursor: pointer; color: inherit; padding: 2px; }
.filter-row { display: flex; flex-wrap: wrap; gap: 4px; padding: 6px 10px; border-bottom: 1px solid rgba(var(--v-border-color), 0.15); }
.filter-chip { padding: 3px 10px; border-radius: 14px; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); background: transparent; color: rgba(var(--v-theme-on-surface), 0.7); font-size: 11px; cursor: pointer; white-space: nowrap; transition: all 0.15s; }
.filter-chip:hover { background: rgba(var(--v-theme-primary), 0.06); }
.filter-chip.active { background: rgb(var(--v-theme-primary)); color: #fff; border-color: rgb(var(--v-theme-primary)); }
.filter-chip.small { font-size: 10px; padding: 2px 8px; }

/* Chat item */
.chat-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; cursor: pointer; border-bottom: 1px solid rgba(var(--v-border-color), 0.3); transition: background 0.1s; position: relative; }
.chat-item:hover { background: rgba(var(--v-theme-primary), 0.04); }
.chat-item.active { background: rgba(var(--v-theme-primary), 0.08); border-left: 3px solid rgb(var(--v-theme-primary)); }
.chat-item-avatar { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.chat-item-body { flex: 1; min-width: 0; }
.chat-item-top { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.chat-item-subject { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-item-time { font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.4); flex-shrink: 0; }
.chat-item-bottom { display: flex; gap: 6px; margin-top: 4px; font-size: 11px; align-items: center; }
.chat-item-cat { color: rgba(var(--v-theme-on-surface), 0.5); }
.chat-item-status-chip { padding: 2px 8px; border-radius: 10px; font-weight: 600; font-size: 10px; }
.unread-badge { position: absolute; right: 12px; top: 12px; background: rgb(var(--v-theme-error)); color: #fff; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 10px; min-width: 18px; text-align: center; }
.chat-item.has-unread { background: rgba(var(--v-theme-primary), 0.06); }
.chat-item.has-unread .chat-item-subject { color: rgb(var(--v-theme-primary)); font-weight: 700; }

/* Main chat area */
.chat-main { flex: 1; display: flex; flex-direction: column; min-width: 0; position: relative; }
.chat-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; gap: 12px; color: rgba(var(--v-theme-on-surface), 0.3); }
.chat-placeholder p { font-size: 15px; }

/* Header */
.chat-header { padding: 12px 20px; border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); display: flex; align-items: center; gap: 12px; }
.btn-back { background: none; border: none; cursor: pointer; color: inherit; padding: 4px; }
.chat-header-subject { font-size: 15px; font-weight: 700; }
.chat-header-meta { display: flex; gap: 8px; align-items: center; margin-top: 4px; font-size: 12px; flex-wrap: wrap; }
.meta-cat { padding: 2px 8px; border-radius: 6px; font-weight: 600; }
.meta-status-chip { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; border-radius: 10px; font-weight: 600; font-size: 11px; }
.meta-agent { display: flex; align-items: center; gap: 4px; color: rgba(var(--v-theme-on-surface), 0.5); }

/* Messages */
.chat-messages { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 12px; scroll-behavior: smooth; }
.chat-empty-msg { text-align: center; color: rgba(var(--v-theme-on-surface), 0.3); padding: 48px; }
.date-divider { display: flex; align-items: center; justify-content: center; margin: 8px 0; position: relative; }
.date-divider::before { content: ''; position: absolute; left: 0; right: 0; top: 50%; border-top: 1px solid rgba(var(--v-border-color), 0.3); z-index: 0; }
.date-divider span { position: relative; z-index: 1; background: rgb(var(--v-theme-background)); padding: 2px 12px; font-size: 11px; font-weight: 600; color: rgba(var(--v-theme-on-surface), 0.5); text-transform: capitalize; }
.msg-row { display: flex; align-items: flex-end; gap: 8px; }
.msg-row.mine { flex-direction: row-reverse; }
.msg-row.system { justify-content: center; }
.msg-system { font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.4); font-style: italic; padding: 4px 12px; background: rgba(var(--v-theme-surface-variant), 0.5); border-radius: 12px; }
.msg-avatar { flex-shrink: 0; }
.avatar-circle { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: #fff; letter-spacing: -0.5px; }
.avatar-circle.agent { background: rgb(var(--v-theme-secondary)); }
.avatar-circle.mine { background: rgb(var(--v-theme-primary)); }
.msg-bubble { max-width: 65%; padding: 10px 14px; border-radius: 16px; position: relative; }
.msg-bubble.agent { background: rgba(var(--v-theme-surface-variant), 1); border-bottom-left-radius: 4px; }
.msg-bubble.mine { background: #1a3a2e; color: #d1e8d5; border-bottom-right-radius: 4px; }
.msg-sender { font-size: 11px; font-weight: 600; margin-bottom: 2px; color: rgba(var(--v-theme-on-surface), 0.6); }
.msg-bubble.mine .msg-sender { color: rgba(209,232,213,0.7); }
.msg-text { font-size: 14px; line-height: 1.5; white-space: pre-line; word-break: break-word; }
.msg-attach { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; margin-top: 6px; }
.msg-bubble.mine .msg-attach { color: rgba(209,232,213,0.7); }
.msg-image-link { display: block; margin-top: 6px; border-radius: 10px; overflow: hidden; max-width: 320px; }
.msg-image { display: block; width: 100%; height: auto; max-height: 280px; object-fit: cover; border-radius: 10px; background: rgba(0,0,0,0.05); }
.msg-time { font-size: 10px; margin-top: 4px; opacity: 0.5; }
.msg-bubble.mine .msg-time { text-align: right; }

/* Typing */
.typing-indicator { display: flex; align-items: center; gap: 8px; padding: 6px 14px; font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.5); font-style: italic; }
.typing-dots { display: inline-flex; gap: 3px; }
.typing-dots span { width: 5px; height: 5px; border-radius: 50%; background: rgba(var(--v-theme-on-surface), 0.4); animation: typing-blink 1.2s infinite ease-in-out; }
.typing-dots span:nth-child(2) { animation-delay: 0.15s; }
.typing-dots span:nth-child(3) { animation-delay: 0.3s; }
@keyframes typing-blink { 0%, 80%, 100% { opacity: 0.2; } 40% { opacity: 1; } }

/* Jump to bottom */
.jump-to-bottom { position: absolute; right: 24px; bottom: 90px; display: flex; align-items: center; gap: 4px; padding: 6px 10px; border-radius: 16px; background: rgb(var(--v-theme-primary)); color: #fff; border: none; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-size: 12px; font-weight: 600; z-index: 5; }
.jump-to-bottom:hover { opacity: 0.9; }

/* Input */
.chat-input { display: flex; align-items: flex-end; gap: 8px; padding: 12px 20px; border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); position: relative; transition: background 0.15s; }
.chat-input.drag-over { background: rgba(var(--v-theme-primary), 0.08); }
.input-btn { background: none; border: none; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.5); padding: 6px; border-radius: 8px; }
.input-btn:hover { background: rgba(var(--v-theme-primary), 0.1); }
.input-area { flex: 1; }
.input-area textarea { width: 100%; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 12px; padding: 10px 14px; font-size: 14px; resize: none; background: rgba(var(--v-theme-surface-variant), 0.3); color: inherit; outline: none; font-family: inherit; }
.input-area textarea:focus { border-color: rgb(var(--v-theme-primary)); }
.input-file-preview { display: flex; align-items: center; gap: 8px; margin-top: 6px; padding: 6px 8px; border-radius: 10px; background: rgba(var(--v-theme-primary), 0.08); border: 1px solid rgba(var(--v-theme-primary), 0.2); }
.input-file-preview img { width: 40px; height: 40px; object-fit: cover; border-radius: 6px; }
.input-file-icon { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 6px; background: rgba(var(--v-theme-primary), 0.15); color: rgb(var(--v-theme-primary)); }
.input-file-info { flex: 1; min-width: 0; }
.input-file-name { font-size: 12px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.input-file-size { font-size: 10px; color: rgba(var(--v-theme-on-surface), 0.5); }
.input-file-remove { background: none; border: none; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.5); padding: 4px; border-radius: 6px; }
.input-file-remove:hover { background: rgba(var(--v-theme-error), 0.1); color: rgb(var(--v-theme-error)); }
.drop-overlay { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; background: rgba(var(--v-theme-primary), 0.15); border: 2px dashed rgb(var(--v-theme-primary)); border-radius: 8px; color: rgb(var(--v-theme-primary)); font-weight: 600; font-size: 13px; pointer-events: none; z-index: 10; }
.input-send { background: rgb(var(--v-theme-primary)); color: #fff; border: none; border-radius: 10px; padding: 8px 12px; cursor: pointer; transition: opacity 0.15s; }
.input-send:hover { opacity: 0.9; }
.input-send:disabled { opacity: 0.4; cursor: not-allowed; }
.chat-closed { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 16px; border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); color: rgba(var(--v-theme-on-surface), 0.4); font-size: 13px; }

/* Mobile */
@media (max-width: 959px) {
  .chat-sidebar { width: 100%; }
  .mobile-hidden { display: none !important; }
  .jump-to-bottom { right: 16px; bottom: 80px; }
}
</style>
