<template>
  <div class="chat-wrap">
    <!-- Left sidebar: dialog list -->
    <aside class="chat-sidebar" :class="{ 'mobile-hidden': mobile && activeChat }">
      <div class="sidebar-head">
        <h3>Мои обращения</h3>
        <button class="btn-new" @click="showNew = true"><v-icon size="18">mdi-plus</v-icon> Новый чат</button>
      </div>
      <div class="sidebar-list">
        <div v-for="t in chats" :key="t.id" class="chat-item" :class="{ active: activeChat?.id === t.id, 'has-unread': t.unread > 0 }" @click="openChat(t)">
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
              <span class="chat-item-status" :style="{ color: statusClr(t.status) }">{{ statusTxt(t.status) }}</span>
            </div>
          </div>
          <span v-if="t.unread > 0" class="unread-badge">{{ t.unread }}</span>
        </div>
        <div v-if="!chats.length && !loading" class="sidebar-empty">
          <v-icon size="40" color="grey">mdi-chat-outline</v-icon>
          <p>Нет обращений</p>
          <button class="btn-new small" @click="showNew = true">Создать</button>
        </div>
      </div>
    </aside>

    <!-- Center: chat area -->
    <main class="chat-main" :class="{ 'mobile-hidden': mobile && !activeChat }">
      <template v-if="activeChat">
        <!-- Header -->
        <div class="chat-header">
          <button v-if="mobile" class="btn-back" @click="activeChat = null"><v-icon>mdi-arrow-left</v-icon></button>
          <div class="chat-header-info">
            <div class="chat-header-subject">{{ activeChat.subject }}</div>
            <div class="chat-header-meta">
              <span class="meta-cat" :style="{ background: catColor(activeChat.category) + '22', color: catColor(activeChat.category) }">{{ catLabel(activeChat.category) }}</span>
              <span class="meta-status" :style="{ color: statusClr(activeChat.status) }">{{ statusTxt(activeChat.status) }}</span>
              <span v-if="activeChat.assigned_name" class="meta-agent">
                <v-icon size="12">mdi-account</v-icon> {{ activeChat.assigned_name }}
              </span>
            </div>
          </div>
        </div>

        <!-- Messages -->
        <div ref="msgsRef" class="chat-messages">
          <div v-for="msg in messages" :key="msg.id" class="msg-row" :class="{ mine: isMine(msg), system: msg.isSystem }">
            <template v-if="msg.isSystem">
              <div class="msg-system">{{ msg.content }}</div>
            </template>
            <template v-else>
              <div class="msg-avatar" v-if="!isMine(msg)">
                <div class="avatar-circle agent">{{ msg.senderName?.[0] || 'С' }}</div>
              </div>
              <div class="msg-bubble" :class="isMine(msg) ? 'mine' : 'agent'">
                <div class="msg-sender">{{ msg.senderName }}</div>
                <div class="msg-text">{{ msg.content }}</div>
                <a v-if="msg.attachmentPath" :href="msg.attachmentPath" target="_blank" class="msg-attach">
                  <v-icon size="14">mdi-paperclip</v-icon> {{ msg.attachmentName || 'Файл' }}
                </a>
                <div class="msg-time">{{ fmtTime(msg.createdAt) }}</div>
              </div>
              <div class="msg-avatar" v-if="isMine(msg)">
                <div class="avatar-circle mine">{{ msg.senderName?.[0] || 'Я' }}</div>
              </div>
            </template>
          </div>
          <div v-if="!messages.length" class="chat-empty-msg">Напишите первое сообщение</div>
        </div>

        <!-- Input -->
        <div v-if="activeChat.status !== 'closed'" class="chat-input">
          <input ref="fileRef" type="file" hidden @change="e => file = e.target.files?.[0]" />
          <button class="input-btn" @click="$refs.fileRef.click()"><v-icon size="20">mdi-paperclip</v-icon></button>
          <div class="input-area">
            <textarea v-model="msgText" placeholder="Введите сообщение..." rows="1"
              @keydown.enter.exact.prevent="send" @input="autoGrow"></textarea>
            <div v-if="file" class="input-file">
              <v-icon size="14">mdi-file</v-icon> {{ file.name }}
              <button @click="file = null"><v-icon size="14">mdi-close</v-icon></button>
            </div>
          </div>
          <button class="input-send" :disabled="sending" @click="send">
            <v-icon size="20">mdi-send</v-icon>
          </button>
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
import { ref, nextTick, onMounted, onUnmounted } from 'vue';
import { useDisplay } from 'vuetify';
import api from '../../api';
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
const fileRef = ref(null);
let poll = null;

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

function catColor(c) { return { support: '#3b82f6', backoffice: '#f97316', billing: '#22c55e', legal: '#a855f7', general: '#6b7280' }[c] || '#6b7280'; }
function catIcon(c) { return { support: 'mdi-headset', backoffice: 'mdi-briefcase', billing: 'mdi-cash', legal: 'mdi-scale-balance', general: 'mdi-help-circle' }[c] || 'mdi-chat'; }
function catLabel(c) { return { support: 'Техподдержка', backoffice: 'Бэк-офис', billing: 'Начисления', legal: 'Юридический', general: 'Общий' }[c] || c; }
function statusClr(s) { return { new: '#60a5fa', open: '#fbbf24', pending: '#f97316', resolved: '#34d399', closed: '#6b7280' }[s] || '#888'; }
function statusTxt(s) { return { new: 'Новый', open: 'В работе', pending: 'Ожидание', resolved: 'Решён', closed: 'Закрыт' }[s] || s; }
function ago(d) { if (!d) return ''; const s = Math.floor((Date.now() - new Date(d).getTime()) / 1000); if (s < 60) return 'сейчас'; if (s < 3600) return Math.floor(s/60) + 'м'; if (s < 86400) return Math.floor(s/3600) + 'ч'; return Math.floor(s/86400) + 'д'; }
function fmtTime(d) { if (!d) return ''; const dt = new Date(d); if (isNaN(dt)) return ''; const now = new Date(); if (dt.toDateString() === now.toDateString()) return dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }); return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }) + ' ' + dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }); }
function scrollDown() { nextTick(() => { if (msgsRef.value) msgsRef.value.scrollTop = msgsRef.value.scrollHeight; }); }
function autoGrow(e) { const t = e.target; t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight, 120) + 'px'; }

async function loadChats() {
  loading.value = true;
  try { const { data } = await api.get('/chat/tickets'); chats.value = data.data || []; } catch {}
  loading.value = false;
}

async function openChat(t) {
  activeChat.value = t;
  try {
    const { data } = await api.get(`/chat/tickets/${t.id}`);
    messages.value = data.messages || [];
    if (t.unread > 0) { t.unread = 0; }
    scrollDown();
  } catch {}
  startPoll();
}

async function refreshMessages() {
  if (!activeChat.value) return;
  try {
    const { data } = await api.get(`/chat/tickets/${activeChat.value.id}`);
    messages.value = data.messages || [];
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
    msgText.value = ''; file.value = null;
    await refreshMessages();
    scrollDown();
    activeChat.value.unread = 0;
    loadChats(); // Refresh list
  } catch {}
  sending.value = false;
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
  poll = setInterval(() => { refreshMessages(); loadChats(); }, 8000);
}
function stopPoll() { if (poll) { clearInterval(poll); poll = null; } }

onMounted(loadChats);
onUnmounted(stopPoll);
</script>

<style scoped>
.chat-wrap { display: flex; height: calc(100vh - 64px); overflow: hidden; }

/* Sidebar */
.chat-sidebar { width: 340px; flex-shrink: 0; border-right: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); display: flex; flex-direction: column; background: rgba(var(--v-theme-surface), 1); }
.sidebar-head { display: flex; align-items: center; justify-content: space-between; padding: 16px; border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.sidebar-head h3 { font-size: 16px; font-weight: 700; margin: 0; }
.btn-new { display: flex; align-items: center; gap: 4px; padding: 6px 14px; border-radius: 10px; border: none; background: rgb(var(--v-theme-primary)); color: #fff; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-new.small { margin-top: 8px; }
.sidebar-list { flex: 1; overflow-y: auto; }
.sidebar-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 48px 16px; gap: 8px; color: rgba(var(--v-theme-on-surface), 0.4); }

/* Chat item */
.chat-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; cursor: pointer; border-bottom: 1px solid rgba(var(--v-border-color), 0.3); transition: background 0.1s; position: relative; }
.chat-item:hover { background: rgba(var(--v-theme-primary), 0.04); }
.chat-item.active { background: rgba(var(--v-theme-primary), 0.08); border-left: 3px solid rgb(var(--v-theme-primary)); }
.chat-item-avatar { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.chat-item-body { flex: 1; min-width: 0; }
.chat-item-top { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.chat-item-subject { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-item-time { font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.4); flex-shrink: 0; }
.chat-item-bottom { display: flex; gap: 8px; margin-top: 4px; font-size: 11px; }
.chat-item-cat { color: rgba(var(--v-theme-on-surface), 0.5); }
.chat-item-status { font-weight: 600; }
.unread-badge { position: absolute; right: 12px; top: 12px; background: rgb(var(--v-theme-error)); color: #fff; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 10px; min-width: 18px; text-align: center; }
.chat-item.has-unread { background: rgba(var(--v-theme-primary), 0.06); }
.chat-item.has-unread .chat-item-subject { color: rgb(var(--v-theme-primary)); font-weight: 700; }

/* Main chat area */
.chat-main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.chat-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; gap: 12px; color: rgba(var(--v-theme-on-surface), 0.3); }
.chat-placeholder p { font-size: 15px; }

/* Header */
.chat-header { padding: 12px 20px; border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); display: flex; align-items: center; gap: 12px; }
.btn-back { background: none; border: none; cursor: pointer; color: inherit; padding: 4px; }
.chat-header-subject { font-size: 15px; font-weight: 700; }
.chat-header-meta { display: flex; gap: 8px; align-items: center; margin-top: 4px; font-size: 12px; }
.meta-cat { padding: 2px 8px; border-radius: 6px; font-weight: 600; }
.meta-status { font-weight: 600; }
.meta-agent { display: flex; align-items: center; gap: 4px; color: rgba(var(--v-theme-on-surface), 0.5); }

/* Messages */
.chat-messages { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 12px; scroll-behavior: smooth; }
.chat-empty-msg { text-align: center; color: rgba(var(--v-theme-on-surface), 0.3); padding: 48px; }
.msg-row { display: flex; align-items: flex-end; gap: 8px; }
.msg-row.mine { flex-direction: row-reverse; }
.msg-row.system { justify-content: center; }
.msg-system { font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.4); font-style: italic; padding: 4px 12px; background: rgba(var(--v-theme-surface-variant), 0.5); border-radius: 12px; }
.msg-avatar { flex-shrink: 0; }
.avatar-circle { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; color: #fff; }
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
.msg-time { font-size: 10px; margin-top: 4px; opacity: 0.5; }
.msg-bubble.mine .msg-time { text-align: right; }

/* Input */
.chat-input { display: flex; align-items: flex-end; gap: 8px; padding: 12px 20px; border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.input-btn { background: none; border: none; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.5); padding: 6px; border-radius: 8px; }
.input-btn:hover { background: rgba(var(--v-theme-primary), 0.1); }
.input-area { flex: 1; }
.input-area textarea { width: 100%; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 12px; padding: 10px 14px; font-size: 14px; resize: none; background: rgba(var(--v-theme-surface-variant), 0.3); color: inherit; outline: none; font-family: inherit; }
.input-area textarea:focus { border-color: rgb(var(--v-theme-primary)); }
.input-file { display: flex; align-items: center; gap: 4px; font-size: 12px; margin-top: 4px; color: rgba(var(--v-theme-on-surface), 0.5); }
.input-file button { background: none; border: none; cursor: pointer; color: inherit; }
.input-send { background: rgb(var(--v-theme-primary)); color: #fff; border: none; border-radius: 10px; padding: 8px 12px; cursor: pointer; }
.input-send:hover { opacity: 0.9; }
.input-send:disabled { opacity: 0.5; }
.chat-closed { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 16px; border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); color: rgba(var(--v-theme-on-surface), 0.4); font-size: 13px; }

/* Mobile */
@media (max-width: 959px) {
  .chat-sidebar { width: 100%; }
  .mobile-hidden { display: none !important; }
}
</style>
