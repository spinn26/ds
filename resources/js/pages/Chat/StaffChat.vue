<template>
  <div class="chat-wrap">
    <!-- Left: ticket list -->
    <aside class="chat-sidebar" :class="{ 'mobile-hidden': mobile && activeChat }">
      <div class="sidebar-head">
        <h3>Чаты</h3>
        <div class="sidebar-filters">
          <select v-model="filter.status" @change="loadChats">
            <option value="">Все</option>
            <option value="new">Новые</option>
            <option value="open">Открытые</option>
            <option value="pending">Ожидание</option>
            <option value="resolved">Решённые</option>
            <option value="closed">Закрытые</option>
          </select>
          <select v-model="filter.priority" @change="loadChats">
            <option value="">Приоритет</option>
            <option value="critical">Критический</option>
            <option value="high">Высокий</option>
            <option value="medium">Средний</option>
            <option value="low">Низкий</option>
          </select>
        </div>
        <input v-model="filter.search" placeholder="Поиск..." class="sidebar-search" @input="debouncedLoad" />
      </div>
      <div class="sidebar-list">
        <div v-for="t in chats" :key="t.id" class="chat-item" :class="{ active: activeChat?.id === t.id, 'has-unread': t.unread > 0 }" @click="openChat(t)">
          <div class="chat-item-avatar" :style="{ background: catColor(t.category || t.department) }">
            <v-icon size="18" color="white">{{ catIcon(t.category || t.department) }}</v-icon>
          </div>
          <div class="chat-item-body">
            <div class="chat-item-top">
              <span class="chat-item-subject">{{ t.subject }}</span>
              <span class="chat-item-time">{{ ago(t.last_message_at) }}</span>
            </div>
            <div class="chat-item-bottom">
              <span>{{ t.customer_name }}</span>
              <span v-if="t.recipient_name" style="color: #f97316">→ {{ t.recipient_name }}</span>
              <span v-if="t.priority === 'critical'" class="priority-dot critical"></span>
              <span v-if="t.priority === 'high'" class="priority-dot high"></span>
            </div>
          </div>
          <span v-if="t.unread > 0" class="unread-badge">{{ t.unread }}</span>
        </div>
        <div v-if="!chats.length && !loading" class="sidebar-empty">Нет чатов</div>
      </div>
    </aside>

    <!-- Center: messages -->
    <main class="chat-main" :class="{ 'mobile-hidden': mobile && !activeChat }">
      <template v-if="activeChat">
        <!-- Header with actions -->
        <div class="chat-header">
          <button v-if="mobile" class="btn-back" @click="activeChat = null"><v-icon>mdi-arrow-left</v-icon></button>
          <div class="chat-header-info">
            <div class="chat-header-subject">{{ activeChat.subject }}</div>
            <div class="chat-header-meta">
              <span>{{ activeChat.customer_name }}</span> ·
              <span :style="{ color: statusClr(activeChat.status) }">{{ statusTxt(activeChat.status) }}</span>
              <span v-if="activeChat.recipient_name" class="recipient-tag">
                <v-icon size="12">mdi-arrow-right</v-icon> {{ activeChat.recipient_name }}
              </span>
              <span v-if="activeChat.context_type" class="context-tag">
                <v-icon size="12">mdi-link-variant</v-icon> {{ activeChat.context_type }}{{ activeChat.context_id ? ': #' + activeChat.context_id : '' }}
              </span>
            </div>
          </div>
          <div class="chat-header-actions">
            <!-- Priority -->
            <v-menu>
              <template #activator="{ props }">
                <button v-bind="props" class="action-btn" :title="'Приоритет: ' + (activeChat.priority || 'medium')">
                  <v-icon size="16" :color="prioClr(activeChat.priority)">mdi-flag</v-icon>
                </button>
              </template>
              <v-list density="compact" style="min-width: 140px">
                <v-list-item v-for="p in priorities" :key="p.value" @click="setPriority(p.value)">
                  <template #prepend><v-icon size="14" :color="p.color">mdi-circle</v-icon></template>
                  <v-list-item-title class="text-body-2">{{ p.label }}</v-list-item-title>
                </v-list-item>
              </v-list>
            </v-menu>
            <!-- Assign -->
            <v-menu>
              <template #activator="{ props }">
                <button v-bind="props" class="action-btn" title="Назначить"><v-icon size="16">mdi-account-plus</v-icon></button>
              </template>
              <v-list density="compact" style="min-width: 200px">
                <v-list-item v-for="s in staffList" :key="s.id" @click="assignTo(s.id, s.name)">
                  <v-list-item-title class="text-body-2">{{ s.name }}</v-list-item-title>
                </v-list-item>
              </v-list>
            </v-menu>
            <!-- Status -->
            <v-menu>
              <template #activator="{ props }">
                <button v-bind="props" class="action-btn" title="Статус"><v-icon size="16">mdi-check-circle-outline</v-icon></button>
              </template>
              <v-list density="compact" style="min-width: 140px">
                <v-list-item v-for="s in statuses" :key="s.value" @click="setStatus(s.value)">
                  <v-list-item-title class="text-body-2" :style="{ color: s.color }">{{ s.label }}</v-list-item-title>
                </v-list-item>
              </v-list>
            </v-menu>
            <!-- Close -->
            <button class="action-btn" title="Закрыть чат" @click="setStatus('closed')"><v-icon size="16">mdi-close-circle-outline</v-icon></button>
          </div>
        </div>

        <!-- Tags -->
        <div v-if="parseTags(activeChat.tags).length" class="chat-tags">
          <v-chip v-for="tag in parseTags(activeChat.tags)" :key="tag" size="x-small" variant="outlined" class="mr-1">{{ tag }}</v-chip>
        </div>

        <!-- Messages -->
        <div ref="msgsRef" class="chat-messages">
          <div v-for="msg in messages" :key="msg.id" class="msg-row" :class="{ mine: isMine(msg), system: msg.isSystem }">
            <template v-if="msg.isSystem">
              <div class="msg-system">{{ msg.content }}</div>
            </template>
            <template v-else>
              <div class="msg-avatar" v-if="!isMine(msg)">
                <div class="avatar-circle partner">{{ msg.senderName?.[0] || 'П' }}</div>
              </div>
              <div class="msg-bubble" :class="isMine(msg) ? 'mine' : 'partner'">
                <div class="msg-sender">{{ msg.senderName }}</div>
                <div class="msg-text">{{ msg.content }}</div>
                <a v-if="msg.attachmentPath" :href="msg.attachmentPath" target="_blank" class="msg-attach">
                  <v-icon size="14">mdi-paperclip</v-icon> {{ msg.attachmentName || 'Файл' }}
                </a>
                <div class="msg-time">{{ fmtTime(msg.createdAt) }}</div>
              </div>
              <div class="msg-avatar" v-if="isMine(msg)">
                <div class="avatar-circle staff">{{ msg.senderName?.[0] || 'Я' }}</div>
              </div>
            </template>
          </div>
        </div>

        <!-- Input -->
        <div v-if="activeChat.status !== 'closed'" class="chat-input">
          <input ref="fileRef" type="file" hidden @change="e => file = e.target.files?.[0]" />
          <button class="input-btn" @click="$refs.fileRef.click()"><v-icon size="20">mdi-paperclip</v-icon></button>
          <div class="input-area">
            <textarea v-model="msgText" placeholder="Ответ..." rows="1" @keydown.enter.exact.prevent="send" @input="autoGrow"></textarea>
            <div v-if="file" class="input-file">
              <v-icon size="14">mdi-file</v-icon> {{ file.name }}
              <button @click="file = null"><v-icon size="14">mdi-close</v-icon></button>
            </div>
          </div>
          <button class="input-send" :disabled="sending" @click="send"><v-icon size="20">mdi-send</v-icon></button>
        </div>
      </template>
      <div v-else class="chat-placeholder">
        <v-icon size="64" color="grey-lighten-2">mdi-forum-outline</v-icon>
        <p>Выберите чат из списка</p>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, nextTick, onMounted, onUnmounted } from 'vue';
import { useDisplay } from 'vuetify';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import { useAuthStore } from '../../stores/auth';

const { mobile } = useDisplay();
const auth = useAuthStore();
const currentUserId = auth.userId;

function isMine(msg) { return String(msg.senderId) === String(currentUserId); }
const chats = ref([]);
const loading = ref(false);
const activeChat = ref(null);
const messages = ref([]);
const msgText = ref('');
const file = ref(null);
const sending = ref(false);
const msgsRef = ref(null);
const staffList = ref([]);
const filter = ref({ status: '', priority: '', search: '' });
let poll = null;

const priorities = [
  { label: 'Критический', value: 'critical', color: '#ef4444' },
  { label: 'Высокий', value: 'high', color: '#f97316' },
  { label: 'Средний', value: 'medium', color: '#fbbf24' },
  { label: 'Низкий', value: 'low', color: '#34d399' },
];
const statuses = [
  { label: 'Новый', value: 'new', color: '#60a5fa' },
  { label: 'Открыт', value: 'open', color: '#fbbf24' },
  { label: 'Ожидание', value: 'pending', color: '#f97316' },
  { label: 'Решён', value: 'resolved', color: '#34d399' },
  { label: 'Закрыт', value: 'closed', color: '#6b7280' },
];

function catColor(c) { return { support: '#3b82f6', backoffice: '#f97316', billing: '#22c55e', legal: '#a855f7', general: '#6b7280', technical: '#3b82f6', sales: '#f97316' }[c] || '#6b7280'; }
function catIcon(c) { return { support: 'mdi-headset', backoffice: 'mdi-briefcase', billing: 'mdi-cash', legal: 'mdi-scale-balance', general: 'mdi-help-circle', technical: 'mdi-headset', sales: 'mdi-handshake' }[c] || 'mdi-chat'; }
function statusClr(s) { return { new: '#60a5fa', open: '#fbbf24', pending: '#f97316', resolved: '#34d399', closed: '#6b7280' }[s] || '#888'; }
function statusTxt(s) { return { new: 'Новый', open: 'В работе', pending: 'Ожидание', resolved: 'Решён', closed: 'Закрыт' }[s] || s; }
function prioClr(p) { return { critical: '#ef4444', high: '#f97316', medium: '#fbbf24', low: '#34d399' }[p] || '#888'; }
function ago(d) { if (!d) return ''; const s = Math.floor((Date.now() - new Date(d).getTime()) / 1000); if (s < 60) return 'сейчас'; if (s < 3600) return Math.floor(s/60) + 'м'; if (s < 86400) return Math.floor(s/3600) + 'ч'; return Math.floor(s/86400) + 'д'; }
function fmtTime(d) { if (!d) return ''; const dt = new Date(d); if (isNaN(dt)) return ''; return dt.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }); }
function parseTags(t) { if (!t) return []; if (Array.isArray(t)) return t; try { return JSON.parse(t); } catch { return []; } }
function scrollDown() { nextTick(() => { if (msgsRef.value) msgsRef.value.scrollTop = msgsRef.value.scrollHeight; }); }
function autoGrow(e) { const t = e.target; t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight, 120) + 'px'; }

const { debounced: debouncedLoad } = useDebounce(loadChats, 400);

async function loadChats() {
  loading.value = true;
  try {
    const params = {};
    if (filter.value.status) params.status = filter.value.status;
    if (filter.value.priority) params.priority = filter.value.priority;
    if (filter.value.search) params.search = filter.value.search;
    const { data } = await api.get('/chat/tickets', { params });
    chats.value = data.data || [];
  } catch {}
  loading.value = false;
}

async function openChat(t) {
  activeChat.value = t;
  t.unread = 0; // Clear unread immediately
  try {
    const { data } = await api.get(`/chat/tickets/${t.id}`);
    messages.value = data.messages || [];
    scrollDown();
  } catch {}
  startPoll();
}

async function refreshMessages() {
  if (!activeChat.value) return;
  try { const { data } = await api.get(`/chat/tickets/${activeChat.value.id}`); messages.value = data.messages || []; } catch {}
}

async function send() {
  if (!msgText.value?.trim() && !file.value) return;
  sending.value = true;
  try {
    const fd = new FormData();
    fd.append('message', msgText.value || '');
    if (file.value) fd.append('attachment', file.value);
    await api.post(`/chat/tickets/${activeChat.value.id}/messages`, fd);
    msgText.value = ''; file.value = null;
    await refreshMessages(); scrollDown();
    activeChat.value.unread = 0;
    loadChats(); // Refresh list to update unread badges
  } catch {}
  sending.value = false;
}

async function setStatus(status) {
  try { await api.post(`/chat/tickets/${activeChat.value.id}/status`, { status }); activeChat.value.status = status; await refreshMessages(); } catch {}
}

async function setPriority(priority) {
  try { await api.post(`/chat/tickets/${activeChat.value.id}/status`, { status: activeChat.value.status, priority }); activeChat.value.priority = priority; } catch {}
}

async function assignTo(userId, name) {
  try { await api.post(`/chat/tickets/${activeChat.value.id}/assign`, { user_id: userId }); activeChat.value.assigned_to = userId; activeChat.value.assigned_name = name; await refreshMessages(); } catch {}
}

function startPoll() {
  stopPoll();
  poll = setInterval(() => { refreshMessages(); loadChats(); }, 8000);
}
function stopPoll() { if (poll) clearInterval(poll); }

onMounted(async () => {
  loadChats();
  try { const { data } = await api.get('/chat/tickets/staff'); staffList.value = data || []; } catch {}
});
onUnmounted(stopPoll);
</script>

<style scoped>
.chat-wrap { display: flex; height: calc(100vh - 64px); overflow: hidden; }
.chat-sidebar { width: 360px; flex-shrink: 0; border-right: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); display: flex; flex-direction: column; background: rgba(var(--v-theme-surface), 1); }
.sidebar-head { padding: 12px 16px; border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.sidebar-head h3 { font-size: 16px; font-weight: 700; margin: 0 0 8px; }
.sidebar-filters { display: flex; gap: 6px; margin-bottom: 8px; }
.sidebar-filters select { flex: 1; padding: 4px 8px; border-radius: 8px; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); background: rgba(var(--v-theme-surface-variant), 0.3); color: inherit; font-size: 12px; }
.sidebar-search { width: 100%; padding: 6px 12px; border-radius: 8px; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); background: rgba(var(--v-theme-surface-variant), 0.3); color: inherit; font-size: 13px; }
.sidebar-list { flex: 1; overflow-y: auto; }
.sidebar-empty { text-align: center; padding: 48px; color: rgba(var(--v-theme-on-surface), 0.3); }
.chat-item { display: flex; align-items: center; gap: 12px; padding: 10px 16px; cursor: pointer; border-bottom: 1px solid rgba(var(--v-border-color), 0.3); position: relative; }
.chat-item:hover { background: rgba(var(--v-theme-primary), 0.04); }
.chat-item.active { background: rgba(var(--v-theme-primary), 0.08); border-left: 3px solid rgb(var(--v-theme-primary)); }
.chat-item-avatar { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.chat-item-body { flex: 1; min-width: 0; }
.chat-item-top { display: flex; justify-content: space-between; gap: 8px; }
.chat-item-subject { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-item-time { font-size: 10px; color: rgba(var(--v-theme-on-surface), 0.4); flex-shrink: 0; }
.chat-item-bottom { font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.5); margin-top: 2px; display: flex; align-items: center; gap: 6px; }
.priority-dot { width: 8px; height: 8px; border-radius: 50%; }
.priority-dot.critical { background: #ef4444; }
.priority-dot.high { background: #f97316; }
.unread-badge { position: absolute; right: 12px; top: 10px; background: rgb(var(--v-theme-error)); color: #fff; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 10px; min-width: 18px; text-align: center; }
.chat-item.has-unread { background: rgba(var(--v-theme-primary), 0.06); }
.chat-item.has-unread .chat-item-subject { color: rgb(var(--v-theme-primary)); font-weight: 700; }

.chat-main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.chat-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; gap: 12px; color: rgba(var(--v-theme-on-surface), 0.3); }
.chat-header { padding: 10px 16px; border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); display: flex; align-items: center; gap: 12px; }
.btn-back { background: none; border: none; cursor: pointer; color: inherit; }
.chat-header-info { flex: 1; }
.chat-header-subject { font-size: 14px; font-weight: 700; }
.chat-header-meta { font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.5); margin-top: 2px; display: flex; gap: 6px; align-items: center; }
.recipient-tag { background: rgba(249,115,22,0.15); color: #f97316; padding: 1px 8px; border-radius: 4px; font-size: 11px; display: flex; align-items: center; gap: 3px; }
.context-tag { background: rgba(var(--v-theme-primary), 0.1); color: rgb(var(--v-theme-primary)); padding: 1px 6px; border-radius: 4px; font-size: 11px; }
.chat-header-actions { display: flex; gap: 4px; }
.action-btn { background: none; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 8px; padding: 6px; cursor: pointer; color: inherit; }
.action-btn:hover { background: rgba(var(--v-theme-primary), 0.08); }
.chat-tags { padding: 6px 16px; border-bottom: 1px solid rgba(var(--v-border-color), 0.3); }

.chat-messages { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 10px; scroll-behavior: smooth; }
.msg-row { display: flex; align-items: flex-end; gap: 8px; }
.msg-row.mine { flex-direction: row-reverse; }
.msg-row.system { justify-content: center; }
.msg-system { font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.4); font-style: italic; }
.avatar-circle { width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: #fff; }
.avatar-circle.partner { background: #f97316; }
.avatar-circle.staff { background: rgb(var(--v-theme-primary)); }
.msg-bubble { max-width: 60%; padding: 10px 14px; border-radius: 14px; }
.msg-bubble.partner { background: rgba(var(--v-theme-surface-variant), 1); border-bottom-left-radius: 4px; }
.msg-bubble.mine { background: #1a3a2e; color: #d1e8d5; border-bottom-right-radius: 4px; }
.msg-sender { font-size: 11px; font-weight: 700; margin-bottom: 2px; color: #f97316; }
.msg-text { font-size: 13px; line-height: 1.5; white-space: pre-line; word-break: break-word; }
.msg-attach { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; margin-top: 4px; }
.msg-time { font-size: 10px; margin-top: 4px; opacity: 0.5; }

.chat-input { display: flex; align-items: flex-end; gap: 8px; padding: 10px 16px; border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.input-btn { background: none; border: none; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.5); padding: 6px; }
.input-area { flex: 1; }
.input-area textarea { width: 100%; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 12px; padding: 8px 12px; font-size: 13px; resize: none; background: rgba(var(--v-theme-surface-variant), 0.3); color: inherit; outline: none; font-family: inherit; }
.input-file { display: flex; align-items: center; gap: 4px; font-size: 11px; margin-top: 4px; color: rgba(var(--v-theme-on-surface), 0.5); }
.input-file button { background: none; border: none; cursor: pointer; color: inherit; }
.input-send { background: rgb(var(--v-theme-primary)); color: #fff; border: none; border-radius: 10px; padding: 8px 12px; cursor: pointer; }

@media (max-width: 959px) {
  .chat-sidebar { width: 100%; }
  .mobile-hidden { display: none !important; }
}
</style>
