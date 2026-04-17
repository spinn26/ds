<template>
  <div class="chat-wrap">
    <!-- Left: ticket list -->
    <aside class="chat-sidebar" :class="{ 'mobile-hidden': mobile && activeChat }">
      <div class="sidebar-head">
        <h3>Обращения</h3>
        <div class="sidebar-search-row">
          <v-icon size="16">mdi-magnify</v-icon>
          <input v-model="filter.search" placeholder="Поиск по теме / клиенту…" @input="debouncedLoad" />
          <button v-if="filter.search" class="clear-btn" @click="filter.search = ''; loadChats()"><v-icon size="14">mdi-close</v-icon></button>
        </div>
        <div class="filter-row">
          <button v-for="s in statusFilterPills" :key="s.value"
            class="filter-chip" :class="{ active: filter.status === s.value }"
            @click="filter.status = s.value; loadChats()">{{ s.label }}</button>
        </div>
        <div class="filter-row">
          <button v-for="p in priorityFilterPills" :key="p.value"
            class="filter-chip small" :class="{ active: filter.priority === p.value }"
            :style="filter.priority === p.value ? { background: p.color + '22', color: p.color, borderColor: p.color } : {}"
            @click="filter.priority = p.value; loadChats()">{{ p.label }}</button>
        </div>
      </div>
      <div class="sidebar-list">
        <div v-for="t in chats" :key="t.id" class="chat-item" :class="{ active: activeChat?.id === t.id, 'has-unread': t.unread > 0, stale: isStale(t) }" @click="openChat(t)">
          <div class="chat-item-avatar" :style="{ background: catColor(t.category || t.department) }">
            <v-icon size="18" color="white">{{ catIcon(t.category || t.department) }}</v-icon>
          </div>
          <div v-if="t.priority && t.priority !== 'medium'" class="priority-bar" :style="{ background: prioClr(t.priority) }"></div>
          <div class="chat-item-body">
            <div class="chat-item-top">
              <span class="chat-item-subject">{{ t.subject }}</span>
              <span class="chat-item-time" :class="{ stale: isStale(t) }">{{ ago(t.last_message_at) }}</span>
            </div>
            <div class="chat-item-bottom">
              <span class="customer">{{ t.customer_name }}</span>
              <span v-if="t.recipient_name" class="recipient"> → {{ t.recipient_name }}</span>
              <span class="chat-item-status-chip" :style="{ background: statusClr(t.status) + '22', color: statusClr(t.status) }">{{ statusTxt(t.status) }}</span>
            </div>
          </div>
          <span v-if="t.unread > 0" class="unread-badge">{{ t.unread }}</span>
        </div>
        <div v-if="!chats.length && !loading" class="sidebar-empty">
          <v-icon size="40" color="grey">mdi-inbox-outline</v-icon>
          <p>Ничего не найдено</p>
        </div>
      </div>
    </aside>

    <!-- Center: messages -->
    <main class="chat-main" :class="{ 'mobile-hidden': mobile && !activeChat }">
      <template v-if="activeChat">
        <!-- Header with actions -->
        <div class="chat-header">
          <button v-if="mobile" class="btn-back" @click="closeActiveChat"><v-icon>mdi-arrow-left</v-icon></button>
          <div class="chat-header-info">
            <div class="chat-header-subject">{{ activeChat.subject }}</div>
            <div class="chat-header-meta">
              <span class="customer-name">{{ activeChat.customer_name }}</span>
              <span class="meta-status-chip" :style="{ background: statusClr(activeChat.status) + '22', color: statusClr(activeChat.status) }">
                <v-icon size="10">{{ statusIcon(activeChat.status) }}</v-icon>
                {{ statusTxt(activeChat.status) }}
              </span>
              <span v-if="activeChat.priority && activeChat.priority !== 'medium'" class="meta-priority-chip" :style="{ background: prioClr(activeChat.priority) + '22', color: prioClr(activeChat.priority) }">
                <v-icon size="10">mdi-flag</v-icon> {{ prioLabel(activeChat.priority) }}
              </span>
              <span v-if="activeChat.recipient_name" class="recipient-tag">
                <v-icon size="12">mdi-arrow-right</v-icon> {{ activeChat.recipient_name }}
              </span>
              <span v-if="slaLabel" class="sla-chip" :class="slaClass">
                <v-icon size="10">mdi-clock-outline</v-icon> {{ slaLabel }}
              </span>
            </div>
          </div>
          <div class="chat-header-actions">
            <!-- Priority -->
            <v-menu>
              <template #activator="{ props }">
                <button v-bind="props" class="action-btn" :title="'Приоритет: ' + prioLabel(activeChat.priority || 'medium')">
                  <v-icon size="16" :color="prioClr(activeChat.priority)">mdi-flag</v-icon>
                </button>
              </template>
              <v-list density="compact" style="min-width: 160px">
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
              <v-list density="compact" style="min-width: 220px">
                <v-list-item @click="assignTo(currentUserId, currentUserName)">
                  <template #prepend><v-icon size="14">mdi-account-check</v-icon></template>
                  <v-list-item-title class="text-body-2 font-weight-bold">Назначить на себя</v-list-item-title>
                </v-list-item>
                <v-divider />
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
              <v-list density="compact" style="min-width: 160px">
                <v-list-item v-for="s in statuses" :key="s.value" @click="setStatus(s.value)">
                  <template #prepend><v-icon size="14" :color="s.color">{{ s.icon }}</v-icon></template>
                  <v-list-item-title class="text-body-2">{{ s.label }}</v-list-item-title>
                </v-list-item>
              </v-list>
            </v-menu>
            <!-- Notes -->
            <button class="action-btn" :class="{ active: showNotes }" title="Внутренние заметки" @click="toggleNotes">
              <v-icon size="16">mdi-note-text-outline</v-icon>
            </button>
            <!-- Hotkeys -->
            <button class="action-btn" title="Горячие клавиши (?)" @click="showHotkeys = true">
              <v-icon size="16">mdi-keyboard-outline</v-icon>
            </button>
          </div>
        </div>

        <!-- Tags (editable) -->
        <div class="chat-tags">
          <v-chip v-for="tag in currentTags" :key="tag" size="x-small" variant="outlined" closable class="mr-1" @click:close="removeTag(tag)">{{ tag }}</v-chip>
          <button v-if="!addingTag" class="add-tag-btn" @click="addingTag = true">
            <v-icon size="12">mdi-plus</v-icon> тег
          </button>
          <input v-else v-model="newTag" ref="tagInput" class="tag-input"
            @keydown.enter.prevent="addTag" @keydown.esc="cancelAddTag" @blur="addTag" />
        </div>

        <!-- Notes panel (collapsible) -->
        <div v-if="showNotes" class="notes-panel">
          <div class="notes-head">
            <v-icon size="14" color="warning">mdi-shield-account</v-icon>
            <span>Внутренние заметки · видны только сотрудникам</span>
          </div>
          <div class="notes-list">
            <div v-for="n in notes" :key="n.id" class="note-item">
              <div class="note-meta">
                <strong>{{ n.authorName || 'Staff' }}</strong>
                <span class="note-time">{{ fmtTime(n.createdAt) }}</span>
              </div>
              <div class="note-text">{{ n.content }}</div>
            </div>
            <div v-if="!notes.length" class="notes-empty">Заметок нет</div>
          </div>
          <div class="notes-input">
            <textarea v-model="noteText" rows="2" placeholder="Добавить внутреннюю заметку…" @keydown.enter.exact.prevent="addNote"></textarea>
            <button class="notes-send" :disabled="!noteText.trim()" @click="addNote"><v-icon size="16">mdi-send</v-icon></button>
          </div>
        </div>

        <!-- Messages -->
        <div ref="msgsRef" class="chat-messages" @scroll="onMessagesScroll">
          <template v-for="item in groupedMessages" :key="item.key">
            <div v-if="item.type === 'divider'" class="date-divider">
              <span>{{ item.label }}</span>
            </div>
            <div v-else-if="item.msg.isSystem" class="msg-row system">
              <div class="msg-system">{{ item.msg.content }}</div>
            </div>
            <div v-else class="msg-row" :class="{ mine: isMine(item.msg) }">
              <div class="msg-avatar" v-if="!isMine(item.msg)">
                <div class="avatar-circle partner">{{ initials(item.msg.senderName) }}</div>
              </div>
              <div class="msg-bubble" :class="isMine(item.msg) ? 'mine' : 'partner'">
                <div class="msg-sender">{{ item.msg.senderName }}</div>
                <div v-if="item.msg.replyTo" class="msg-reply-quote">
                  <v-icon size="12">mdi-reply</v-icon>
                  <div class="msg-reply-body">
                    <div class="msg-reply-sender">{{ item.msg.replyTo.senderName }}</div>
                    <div class="msg-reply-text">{{ item.msg.replyTo.content }}</div>
                  </div>
                </div>
                <template v-if="editing && editing.id === item.msg.id">
                  <textarea v-model="editing.content" class="msg-edit-area" rows="2"
                    @keydown.enter.exact.prevent="saveEdit"
                    @keydown.esc.prevent="cancelEdit"></textarea>
                  <div class="msg-edit-actions">
                    <button class="msg-edit-btn cancel" @click="cancelEdit">Отмена</button>
                    <button class="msg-edit-btn save" @click="saveEdit">Сохранить</button>
                  </div>
                </template>
                <template v-else>
                  <div v-if="item.msg.content" class="msg-text">{{ item.msg.content }}</div>
                </template>
                <template v-if="item.msg.attachmentPath">
                  <a v-if="isImageAttachment(item.msg.attachmentPath)"
                    :href="item.msg.attachmentPath" target="_blank" class="msg-image-link">
                    <img :src="item.msg.attachmentPath" :alt="item.msg.attachmentName || 'Изображение'" class="msg-image" loading="lazy" />
                  </a>
                  <a v-else :href="item.msg.attachmentPath" target="_blank" class="msg-attach">
                    <v-icon size="14">mdi-paperclip</v-icon> {{ item.msg.attachmentName || 'Файл' }}
                  </a>
                </template>
                <div class="msg-time">
                  {{ fmtTime(item.msg.createdAt) }}
                  <span v-if="item.msg.editedAt" class="msg-edited" title="Изменено">· изменено</span>
                  <v-icon v-if="isMine(item.msg) && isSeen(item.msg)" size="12" class="msg-check seen" title="Прочитано">mdi-check-all</v-icon>
                  <v-icon v-else-if="isMine(item.msg)" size="12" class="msg-check" title="Отправлено">mdi-check</v-icon>
                </div>
                <div class="msg-actions">
                  <button class="msg-action" title="Ответить" @click="startReply(item.msg)"><v-icon size="14">mdi-reply</v-icon></button>
                  <button v-if="canEdit(item.msg)" class="msg-action" title="Изменить (5 мин)" @click="startEdit(item.msg)"><v-icon size="14">mdi-pencil</v-icon></button>
                </div>
              </div>
              <div class="msg-avatar" v-if="isMine(item.msg)">
                <div class="avatar-circle staff">{{ initials(item.msg.senderName) }}</div>
              </div>
            </div>
          </template>
          <div v-if="typingName" class="typing-indicator">
            <span class="typing-dots"><span></span><span></span><span></span></span>
            {{ typingName }} печатает…
          </div>
        </div>

        <button v-if="showJumpToBottom" class="jump-to-bottom" @click="scrollDown(true)">
          <v-icon size="16">mdi-arrow-down</v-icon>
          <span v-if="pendingMessages > 0">{{ pendingMessages }}</span>
        </button>

        <!-- Reply preview -->
        <div v-if="replyTo && activeChat.status !== 'closed'" class="reply-bar">
          <v-icon size="16" color="primary">mdi-reply</v-icon>
          <div class="reply-bar-body">
            <div class="reply-bar-sender">Ответ на: {{ replyTo.senderName }}</div>
            <div class="reply-bar-text">{{ replyTo.content }}</div>
          </div>
          <button class="reply-bar-close" @click="cancelReply"><v-icon size="14">mdi-close</v-icon></button>
        </div>

        <!-- Input -->
        <div v-if="activeChat.status !== 'closed'" class="chat-input"
          :class="{ 'drag-over': dragOver }"
          @dragover.prevent="dragOver = true"
          @dragleave.prevent="dragOver = false"
          @drop.prevent="onFileDrop">
          <input ref="fileRef" type="file" hidden @change="e => setFile(e.target.files?.[0])" />
          <button class="input-btn" title="Прикрепить файл" @click="$refs.fileRef.click()"><v-icon size="20">mdi-paperclip</v-icon></button>
          <!-- Quick replies -->
          <v-menu v-if="quickReplies.length">
            <template #activator="{ props }">
              <button v-bind="props" class="input-btn" title="Быстрые ответы"><v-icon size="20">mdi-lightning-bolt-outline</v-icon></button>
            </template>
            <v-list density="compact" style="max-width: 360px; max-height: 400px; overflow-y: auto">
              <v-list-item v-for="q in quickReplies" :key="q.id" @click="insertQuickReply(q)">
                <v-list-item-title class="text-body-2 font-weight-bold">{{ q.title }}</v-list-item-title>
                <v-list-item-subtitle class="text-caption" style="white-space: normal">{{ q.content }}</v-list-item-subtitle>
              </v-list-item>
            </v-list>
          </v-menu>
          <div class="input-area">
            <textarea ref="taRef" v-model="msgText"
              placeholder="Ответ… (Enter — отправить, Shift+Enter — перенос строки)"
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
      </template>
      <div v-else class="chat-placeholder">
        <v-icon size="64" color="grey-lighten-2">mdi-forum-outline</v-icon>
        <p>Выберите чат из списка</p>
      </div>
    </main>

    <!-- Hotkeys modal -->
    <v-dialog v-model="showHotkeys" max-width="500">
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon>mdi-keyboard</v-icon>
          Горячие клавиши
        </v-card-title>
        <v-card-text>
          <div class="hotkey-row"><kbd>Enter</kbd><span>Отправить ответ</span></div>
          <div class="hotkey-row"><kbd>Shift</kbd> + <kbd>Enter</kbd><span>Новая строка</span></div>
          <div class="hotkey-row"><kbd>Esc</kbd><span>Отмена ответа / правки / закрыть чат</span></div>
          <div class="hotkey-row"><kbd>Ctrl</kbd> + <kbd>/</kbd><span>Показать / скрыть эту панель</span></div>
          <div class="hotkey-row"><kbd>?</kbd><span>То же (вне поля ввода)</span></div>
          <v-divider class="my-2" />
          <div class="text-caption text-medium-emphasis">
            Наведи курсор на сообщение — появятся кнопки «Ответить» и «Изменить» (редактирование в течение 5 мин).
            В шапке чата: смена приоритета, назначение, статус, заметки.
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="showHotkeys = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue';
import { useDisplay } from 'vuetify';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import { useAuthStore } from '../../stores/auth';

const { mobile } = useDisplay();
const auth = useAuthStore();
const currentUserId = auth.userId;
const currentUserName = computed(() => `${auth.user?.lastName || ''} ${auth.user?.firstName || ''}`.trim() || 'Staff');

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
const tagInput = ref(null);
const staffList = ref([]);
const quickReplies = ref([]);
const filter = ref({ status: '', priority: '', search: '' });
let poll = null;

// Socket
let socket = null;
const typingName = ref('');
let typingClearTimer = null;
let typingSendTimer = null;

// Scroll state
const showJumpToBottom = ref(false);
const pendingMessages = ref(0);
const BASE_TITLE = 'Чаты';

// Drag-drop + preview
const dragOver = ref(false);
const filePreviewUrl = ref(null);

// Read receipts, reply, edit
const otherLastReadAt = ref(null);
const replyTo = ref(null);
const editing = ref(null);
const showHotkeys = ref(false);

// Tags (editable)
const currentTags = ref([]);
const addingTag = ref(false);
const newTag = ref('');

// Notes (internal)
const showNotes = ref(false);
const notes = ref([]);
const noteText = ref('');

function isMine(msg) { return String(msg.senderId) === String(currentUserId); }

const priorities = [
  { label: 'Критический', value: 'critical', color: '#ef4444' },
  { label: 'Высокий', value: 'high', color: '#f97316' },
  { label: 'Средний', value: 'medium', color: '#fbbf24' },
  { label: 'Низкий', value: 'low', color: '#34d399' },
];
const statuses = [
  { label: 'Новый', value: 'new', color: '#60a5fa', icon: 'mdi-circle-outline' },
  { label: 'В работе', value: 'open', color: '#fbbf24', icon: 'mdi-progress-clock' },
  { label: 'Ожидание', value: 'pending', color: '#f97316', icon: 'mdi-pause-circle' },
  { label: 'Решён', value: 'resolved', color: '#34d399', icon: 'mdi-check-circle' },
  { label: 'Закрыт', value: 'closed', color: '#6b7280', icon: 'mdi-lock' },
];
const statusFilterPills = [{ label: 'Все', value: '' }, ...statuses.map(s => ({ label: s.label, value: s.value }))];
const priorityFilterPills = [{ label: 'Все', value: '', color: '#888' }, ...priorities];

function catColor(c) { return { support: '#3b82f6', backoffice: '#f97316', billing: '#22c55e', legal: '#a855f7', general: '#6b7280', technical: '#3b82f6', sales: '#f97316' }[c] || '#6b7280'; }
function catIcon(c) { return { support: 'mdi-headset', backoffice: 'mdi-briefcase', billing: 'mdi-cash', legal: 'mdi-scale-balance', general: 'mdi-help-circle', technical: 'mdi-headset', sales: 'mdi-handshake' }[c] || 'mdi-chat'; }
function statusClr(s) { return { new: '#60a5fa', open: '#fbbf24', pending: '#f97316', resolved: '#34d399', closed: '#6b7280' }[s] || '#888'; }
function statusTxt(s) { return { new: 'Новый', open: 'В работе', pending: 'Ожидание', resolved: 'Решён', closed: 'Закрыт' }[s] || s; }
function statusIcon(s) { return { new: 'mdi-circle-outline', open: 'mdi-progress-clock', pending: 'mdi-pause-circle', resolved: 'mdi-check-circle', closed: 'mdi-lock' }[s] || 'mdi-circle'; }
function prioClr(p) { return { critical: '#ef4444', high: '#f97316', medium: '#fbbf24', low: '#34d399' }[p] || '#888'; }
function prioLabel(p) { return priorities.find(x => x.value === p)?.label || p; }
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

const IMAGE_EXT = /\.(jpe?g|png|gif|webp|bmp|svg)(\?|$)/i;
function isImageAttachment(path) { return !!path && IMAGE_EXT.test(path); }
function fmtFileSize(bytes) { if (!bytes) return ''; if (bytes < 1024) return bytes + ' B'; if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'; return (bytes / 1024 / 1024).toFixed(1) + ' MB'; }
function parseTags(t) { if (!t) return []; if (Array.isArray(t)) return t; try { return JSON.parse(t); } catch { return []; } }

// SLA: stale if open/new and last message > 30 min old from partner
const SLA_THRESHOLD_MIN = 30;
function isStale(t) {
  if (!t.last_message_at || ['resolved', 'closed'].includes(t.status)) return false;
  const mins = (Date.now() - new Date(t.last_message_at).getTime()) / 60000;
  return mins > SLA_THRESHOLD_MIN;
}
const slaLabel = computed(() => {
  if (!activeChat.value?.last_message_at || ['resolved', 'closed'].includes(activeChat.value.status)) return '';
  const mins = Math.floor((Date.now() - new Date(activeChat.value.last_message_at).getTime()) / 60000);
  if (mins < 1) return 'только что';
  if (mins < 60) return `${mins} мин`;
  const hrs = Math.floor(mins / 60);
  return `${hrs} ч${mins % 60 ? ` ${mins % 60} мин` : ''}`;
});
const slaClass = computed(() => {
  if (!activeChat.value?.last_message_at) return '';
  const mins = (Date.now() - new Date(activeChat.value.last_message_at).getTime()) / 60000;
  if (mins > SLA_THRESHOLD_MIN) return 'sla-danger';
  if (mins > SLA_THRESHOLD_MIN / 2) return 'sla-warn';
  return '';
});

// Grouped messages with date dividers
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

function isAtBottom(threshold = 80) {
  const el = msgsRef.value;
  if (!el) return true;
  return el.scrollHeight - el.scrollTop - el.clientHeight < threshold;
}
function onMessagesScroll() {
  if (isAtBottom()) { showJumpToBottom.value = false; pendingMessages.value = 0; }
  else showJumpToBottom.value = true;
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
function onInput() { autoGrow(); }

// Draft autosave per ticket
function draftKey(ticketId) { return `staff-chat-draft-${ticketId}`; }
watch(msgText, (v) => {
  if (activeChat.value) {
    if (v) localStorage.setItem(draftKey(activeChat.value.id), v);
    else localStorage.removeItem(draftKey(activeChat.value.id));
  }
  nextTick(autoGrow);
  sendTyping();
});

function sendTyping() {
  if (!socket || !activeChat.value) return;
  if (typingSendTimer) return;
  socket.emit('ticket:typing', { ticketId: activeChat.value.id, isTyping: true });
  typingSendTimer = setTimeout(() => {
    socket?.emit('ticket:typing', { ticketId: activeChat.value?.id, isTyping: false });
    typingSendTimer = null;
  }, 2500);
}

function updateTitle() {
  const total = chats.value.reduce((s, t) => s + (t.unread || 0), 0);
  document.title = total > 0 ? `(${total}) ${BASE_TITLE}` : BASE_TITLE;
}
watch(chats, updateTitle, { deep: true });

// File handling
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
  if (socket && activeChat.value) socket.emit('ticket:leave', activeChat.value.id);
  activeChat.value = t;
  typingName.value = '';
  replyTo.value = null;
  editing.value = null;
  showNotes.value = false;
  t.unread = 0;
  try {
    const { data } = await api.get(`/chat/tickets/${t.id}`);
    messages.value = data.messages || [];
    otherLastReadAt.value = data.otherLastReadAt || null;
    currentTags.value = parseTags(t.tags);
    scrollDown(true);
  } catch {}

  msgText.value = localStorage.getItem(draftKey(t.id)) || '';
  nextTick(() => { taRef.value?.focus(); autoGrow(); });

  if (socket) socket.emit('ticket:join', t.id);
  startPoll();
  loadNotes();
}

function closeActiveChat() {
  if (socket && activeChat.value) socket.emit('ticket:leave', activeChat.value.id);
  activeChat.value = null;
  typingName.value = '';
  showNotes.value = false;
}

async function refreshMessages() {
  if (!activeChat.value) return;
  try {
    const { data } = await api.get(`/chat/tickets/${activeChat.value.id}`);
    const wasAtBottom = isAtBottom();
    const prevCount = messages.value.length;
    messages.value = data.messages || [];
    otherLastReadAt.value = data.otherLastReadAt || null;
    if (messages.value.length > prevCount) {
      if (wasAtBottom) scrollDown(true);
      else pendingMessages.value += messages.value.length - prevCount;
    }
  } catch {}
}

function isSeen(msg) {
  if (!otherLastReadAt.value || !msg.createdAt) return false;
  return new Date(otherLastReadAt.value) >= new Date(msg.createdAt);
}
function canEdit(msg) {
  if (!isMine(msg) || msg.isSystem) return false;
  if (!msg.createdAt) return false;
  return (Date.now() - new Date(msg.createdAt).getTime()) / 60000 <= 5;
}

function startReply(msg) {
  replyTo.value = { id: msg.id, senderName: msg.senderName, content: msg.content };
  nextTick(() => taRef.value?.focus());
}
function cancelReply() { replyTo.value = null; }
function startEdit(msg) { editing.value = { id: msg.id, content: msg.content }; }
function cancelEdit() { editing.value = null; }
async function saveEdit() {
  if (!editing.value) return;
  const newText = editing.value.content.trim();
  if (!newText) return;
  try {
    await api.put(`/chat/messages/${editing.value.id}`, { content: newText });
    const m = messages.value.find(x => String(x.id) === String(editing.value.id));
    if (m) { m.content = newText; m.editedAt = new Date().toISOString(); }
    editing.value = null;
  } catch (e) {
    alert(e?.response?.data?.message || 'Не удалось изменить');
  }
}

async function send() {
  if (!msgText.value?.trim() && !file.value) return;
  sending.value = true;
  try {
    const fd = new FormData();
    fd.append('message', msgText.value || '');
    if (file.value) fd.append('attachment', file.value);
    if (replyTo.value) fd.append('reply_to_id', String(replyTo.value.id));
    await api.post(`/chat/tickets/${activeChat.value.id}/messages`, fd);
    localStorage.removeItem(draftKey(activeChat.value.id));
    msgText.value = '';
    clearFile();
    replyTo.value = null;
    nextTick(autoGrow);
    await refreshMessages();
    scrollDown(true);
    activeChat.value.unread = 0;
    loadChats();
    taRef.value?.focus();
  } catch {}
  sending.value = false;
}

async function setStatus(status) {
  try {
    await api.post(`/chat/tickets/${activeChat.value.id}/status`, { status });
    activeChat.value.status = status;
    await refreshMessages();
    loadChats();
  } catch {}
}

async function setPriority(priority) {
  try {
    await api.post(`/chat/tickets/${activeChat.value.id}/status`, { status: activeChat.value.status, priority });
    activeChat.value.priority = priority;
    loadChats();
  } catch {}
}

async function assignTo(userId, name) {
  try {
    await api.post(`/chat/tickets/${activeChat.value.id}/assign`, { user_id: userId });
    activeChat.value.assigned_to = userId;
    activeChat.value.assigned_name = name;
    await refreshMessages();
  } catch {}
}

// Tags
function addTag() {
  const t = newTag.value.trim();
  if (t && !currentTags.value.includes(t)) {
    currentTags.value.push(t);
    syncTags();
  }
  newTag.value = '';
  addingTag.value = false;
}
function cancelAddTag() {
  newTag.value = '';
  addingTag.value = false;
}
function removeTag(tag) {
  currentTags.value = currentTags.value.filter(t => t !== tag);
  syncTags();
}
async function syncTags() {
  if (!activeChat.value) return;
  try {
    // Reuse status endpoint to send tags alongside (same validation)
    await api.post(`/chat/tickets/${activeChat.value.id}/status`, {
      status: activeChat.value.status,
      tags: currentTags.value,
    });
    activeChat.value.tags = JSON.stringify(currentTags.value);
  } catch (e) {
    alert('Не удалось сохранить теги');
  }
}
watch(addingTag, (v) => { if (v) nextTick(() => tagInput.value?.focus()); });

// Notes
function toggleNotes() {
  showNotes.value = !showNotes.value;
  if (showNotes.value) loadNotes();
}
async function loadNotes() {
  if (!activeChat.value) return;
  try {
    const { data } = await api.get(`/chat/tickets/${activeChat.value.id}/notes`);
    notes.value = data.data || data || [];
  } catch {}
}
async function addNote() {
  const text = noteText.value.trim();
  if (!text || !activeChat.value) return;
  try {
    await api.post(`/chat/tickets/${activeChat.value.id}/notes`, { content: text });
    noteText.value = '';
    await loadNotes();
  } catch (e) {
    alert('Не удалось добавить заметку');
  }
}

// Quick replies
function insertQuickReply(q) {
  const content = (q.content || '')
    .replace(/\{client_name\}/g, activeChat.value?.customer_name || '')
    .replace(/\{ticket_id\}/g, activeChat.value?.id || '')
    .replace(/\{staff_name\}/g, currentUserName.value);
  msgText.value = msgText.value ? `${msgText.value}\n${content}` : content;
  nextTick(() => { taRef.value?.focus(); autoGrow(); });
}

function startPoll() { stopPoll(); poll = setInterval(() => { refreshMessages(); loadChats(); }, 15000); }
function stopPoll() { if (poll) { clearInterval(poll); poll = null; } }

// Socket
async function connectSocket() {
  const token = localStorage.getItem('auth_token');
  if (!token) return;
  try {
    const { io } = await import('socket.io-client');
    const host = window.__SOCKET_URL__ || (location.protocol === 'https:' ? 'wss:' : 'ws:') + '//' + location.hostname + ':3001';
    socket = io(host, { auth: { token }, transports: ['websocket', 'polling'], reconnection: true });

    socket.on('chat:new-message', (m) => {
      if (!activeChat.value || Number(m.ticketId) !== Number(activeChat.value.id)) return;
      if (messages.value.some(x => String(x.id) === String(m.id))) return;
      const wasAtBottom = isAtBottom();
      messages.value.push({
        id: m.id, senderId: m.senderId, senderName: m.senderName,
        content: m.content, isSystem: false, createdAt: m.createdAt,
      });
      if (wasAtBottom) scrollDown(true); else pendingMessages.value++;
      loadChats();
    });
    socket.on('ticket:typing', (e) => {
      if (!activeChat.value || String(e.userId) === String(currentUserId)) return;
      typingName.value = e.userName || 'Собеседник';
      if (typingClearTimer) clearTimeout(typingClearTimer);
      typingClearTimer = setTimeout(() => { typingName.value = ''; }, 3500);
    });
    socket.on('chat:new-ticket', () => loadChats());
    socket.on('chat:message-edited', (e) => {
      if (!activeChat.value || Number(e.ticketId) !== Number(activeChat.value.id)) return;
      const m = messages.value.find(x => String(x.id) === String(e.id));
      if (m) { m.content = e.content; m.editedAt = e.editedAt; }
    });
  } catch (e) {
    console.warn('Chat socket unavailable, falling back to polling:', e?.message);
  }
}

// Global keyboard shortcuts
function onGlobalKey(e) {
  const tag = e.target?.tagName;
  const inField = tag === 'INPUT' || tag === 'TEXTAREA' || e.target?.isContentEditable;
  if ((e.ctrlKey || e.metaKey) && e.key === '/') { e.preventDefault(); showHotkeys.value = !showHotkeys.value; return; }
  if (!inField && e.key === '?' && !e.ctrlKey && !e.metaKey && !e.altKey) { e.preventDefault(); showHotkeys.value = !showHotkeys.value; return; }
  if (e.key === 'Escape') {
    if (showHotkeys.value) { showHotkeys.value = false; return; }
    if (editing.value) { cancelEdit(); return; }
    if (replyTo.value) { cancelReply(); return; }
    if (addingTag.value) { cancelAddTag(); return; }
    if (activeChat.value && !inField) { closeActiveChat(); return; }
  }
}

onMounted(async () => {
  loadChats();
  connectSocket();
  window.addEventListener('keydown', onGlobalKey);
  try { const { data } = await api.get('/chat/tickets/staff'); staffList.value = data || []; } catch {}
  try { const { data } = await api.get('/chat/quick-replies'); quickReplies.value = data.data || data || []; } catch {}
});
onUnmounted(() => {
  stopPoll();
  if (socket && activeChat.value) socket.emit('ticket:leave', activeChat.value.id);
  socket?.disconnect();
  document.title = BASE_TITLE;
  window.removeEventListener('keydown', onGlobalKey);
});
</script>

<style scoped>
.chat-wrap { display: flex; height: calc(100vh - 64px); overflow: hidden; position: relative; }
.chat-sidebar { width: 360px; flex-shrink: 0; border-right: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); display: flex; flex-direction: column; background: rgba(var(--v-theme-surface), 1); }
.sidebar-head { padding: 12px 14px; border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.sidebar-head h3 { font-size: 16px; font-weight: 700; margin: 0 0 8px; }
.sidebar-search-row { display: flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 10px; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); background: rgba(var(--v-theme-surface-variant), 0.3); margin-bottom: 6px; color: rgba(var(--v-theme-on-surface), 0.5); }
.sidebar-search-row input { flex: 1; border: none; outline: none; background: transparent; color: inherit; font-size: 13px; font-family: inherit; }
.sidebar-search-row .clear-btn { background: none; border: none; cursor: pointer; color: inherit; padding: 2px; }
.filter-row { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
.filter-chip { padding: 3px 10px; border-radius: 14px; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); background: transparent; color: rgba(var(--v-theme-on-surface), 0.7); font-size: 11px; cursor: pointer; white-space: nowrap; transition: all 0.15s; }
.filter-chip:hover { background: rgba(var(--v-theme-primary), 0.06); }
.filter-chip.active { background: rgb(var(--v-theme-primary)); color: #fff; border-color: rgb(var(--v-theme-primary)); }
.filter-chip.small { font-size: 10px; padding: 2px 8px; }

.sidebar-list { flex: 1; overflow-y: auto; }
.sidebar-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 48px 16px; gap: 8px; color: rgba(var(--v-theme-on-surface), 0.4); text-align: center; }

.chat-item { display: flex; align-items: center; gap: 12px; padding: 10px 14px; cursor: pointer; border-bottom: 1px solid rgba(var(--v-border-color), 0.2); position: relative; }
.chat-item:hover { background: rgba(var(--v-theme-primary), 0.04); }
.chat-item.active { background: rgba(var(--v-theme-primary), 0.08); border-left: 3px solid rgb(var(--v-theme-primary)); }
.chat-item.stale { background: rgba(239, 68, 68, 0.04); }
.chat-item-avatar { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.priority-bar { position: absolute; top: 0; bottom: 0; left: 0; width: 3px; }
.chat-item-body { flex: 1; min-width: 0; }
.chat-item-top { display: flex; justify-content: space-between; gap: 8px; align-items: center; }
.chat-item-subject { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-item-time { font-size: 10px; color: rgba(var(--v-theme-on-surface), 0.4); flex-shrink: 0; }
.chat-item-time.stale { color: #ef4444; font-weight: 700; }
.chat-item-bottom { font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.5); margin-top: 2px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.customer { font-weight: 600; }
.recipient { color: #f97316; }
.chat-item-status-chip { padding: 1px 7px; border-radius: 10px; font-size: 10px; font-weight: 600; margin-left: auto; }
.unread-badge { position: absolute; right: 12px; top: 10px; background: rgb(var(--v-theme-error)); color: #fff; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 10px; min-width: 18px; text-align: center; }
.chat-item.has-unread { background: rgba(var(--v-theme-primary), 0.06); }
.chat-item.has-unread .chat-item-subject { color: rgb(var(--v-theme-primary)); font-weight: 700; }

.chat-main { flex: 1; display: flex; flex-direction: column; min-width: 0; position: relative; }
.chat-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; gap: 12px; color: rgba(var(--v-theme-on-surface), 0.3); }

.chat-header { padding: 10px 16px; border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); display: flex; align-items: center; gap: 12px; }
.btn-back { background: none; border: none; cursor: pointer; color: inherit; padding: 4px; }
.chat-header-info { flex: 1; min-width: 0; }
.chat-header-subject { font-size: 14px; font-weight: 700; }
.chat-header-meta { font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.5); margin-top: 2px; display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.customer-name { font-weight: 600; color: rgba(var(--v-theme-on-surface), 0.8); }
.meta-status-chip, .meta-priority-chip { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; border-radius: 10px; font-weight: 600; font-size: 11px; }
.recipient-tag { background: rgba(249,115,22,0.15); color: #f97316; padding: 1px 8px; border-radius: 4px; font-size: 11px; display: flex; align-items: center; gap: 3px; }
.sla-chip { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; border-radius: 10px; background: rgba(var(--v-theme-on-surface), 0.06); font-size: 11px; font-weight: 600; }
.sla-chip.sla-warn { background: rgba(251,191,36,0.18); color: #c27803; }
.sla-chip.sla-danger { background: rgba(239,68,68,0.15); color: #dc2626; }
.chat-header-actions { display: flex; gap: 4px; }
.action-btn { background: none; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 8px; padding: 6px; cursor: pointer; color: inherit; transition: all 0.15s; }
.action-btn:hover { background: rgba(var(--v-theme-primary), 0.08); }
.action-btn.active { background: rgba(var(--v-theme-primary), 0.12); color: rgb(var(--v-theme-primary)); border-color: rgba(var(--v-theme-primary), 0.4); }

.chat-tags { display: flex; align-items: center; flex-wrap: wrap; gap: 4px; padding: 6px 16px; border-bottom: 1px solid rgba(var(--v-border-color), 0.2); }
.add-tag-btn { display: inline-flex; align-items: center; gap: 2px; padding: 2px 8px; border-radius: 10px; border: 1px dashed rgba(var(--v-border-color), var(--v-border-opacity)); background: transparent; color: rgba(var(--v-theme-on-surface), 0.5); font-size: 11px; cursor: pointer; }
.add-tag-btn:hover { border-color: rgb(var(--v-theme-primary)); color: rgb(var(--v-theme-primary)); }
.tag-input { padding: 2px 8px; border-radius: 10px; border: 1px solid rgb(var(--v-theme-primary)); background: transparent; font-size: 11px; color: inherit; font-family: inherit; outline: none; min-width: 80px; }

/* Notes panel */
.notes-panel { border-bottom: 1px solid rgba(var(--v-border-color), 0.3); background: rgba(251,191,36,0.06); max-height: 240px; display: flex; flex-direction: column; }
.notes-head { padding: 8px 16px; font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.6); display: flex; align-items: center; gap: 6px; }
.notes-list { flex: 1; overflow-y: auto; padding: 0 16px; }
.note-item { padding: 8px 10px; background: rgba(var(--v-theme-surface-variant), 0.3); border-radius: 8px; margin-bottom: 6px; }
.note-meta { display: flex; justify-content: space-between; gap: 8px; font-size: 10px; color: rgba(var(--v-theme-on-surface), 0.5); }
.note-text { font-size: 12px; margin-top: 4px; white-space: pre-line; }
.notes-empty { text-align: center; padding: 16px; font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.4); }
.notes-input { display: flex; gap: 6px; padding: 8px 16px; border-top: 1px solid rgba(var(--v-border-color), 0.2); align-items: flex-end; }
.notes-input textarea { flex: 1; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 8px; padding: 6px 10px; font-size: 12px; background: rgba(var(--v-theme-surface), 0.9); color: inherit; resize: none; outline: none; font-family: inherit; }
.notes-send { background: rgb(var(--v-theme-primary)); color: #fff; border: none; border-radius: 8px; padding: 6px 10px; cursor: pointer; }
.notes-send:disabled { opacity: 0.5; cursor: not-allowed; }

/* Messages */
.chat-messages { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 10px; scroll-behavior: smooth; }
.date-divider { display: flex; align-items: center; justify-content: center; margin: 6px 0; position: relative; }
.date-divider::before { content: ''; position: absolute; left: 0; right: 0; top: 50%; border-top: 1px solid rgba(var(--v-border-color), 0.3); }
.date-divider span { position: relative; background: rgb(var(--v-theme-background)); padding: 2px 12px; font-size: 11px; font-weight: 600; color: rgba(var(--v-theme-on-surface), 0.5); text-transform: capitalize; }
.msg-row { display: flex; align-items: flex-end; gap: 8px; }
.msg-row.mine { flex-direction: row-reverse; }
.msg-row.system { justify-content: center; }
.msg-system { font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.4); font-style: italic; padding: 4px 12px; background: rgba(var(--v-theme-surface-variant), 0.5); border-radius: 12px; }
.msg-avatar { flex-shrink: 0; }
.avatar-circle { width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: #fff; letter-spacing: -0.5px; }
.avatar-circle.partner { background: #f97316; }
.avatar-circle.staff { background: rgb(var(--v-theme-primary)); }
.msg-bubble { max-width: 60%; padding: 10px 14px; border-radius: 14px; position: relative; }
.msg-bubble.partner { background: rgba(var(--v-theme-surface-variant), 1); border-bottom-left-radius: 4px; }
.msg-bubble.mine { background: #1a3a2e; color: #d1e8d5; border-bottom-right-radius: 4px; }
.msg-sender { font-size: 11px; font-weight: 700; margin-bottom: 2px; color: #f97316; }
.msg-bubble.mine .msg-sender { color: rgba(209,232,213,0.7); }
.msg-text { font-size: 13px; line-height: 1.5; white-space: pre-line; word-break: break-word; }
.msg-attach { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; margin-top: 4px; }
.msg-bubble.mine .msg-attach { color: rgba(209,232,213,0.7); }
.msg-image-link { display: block; margin-top: 6px; border-radius: 10px; overflow: hidden; max-width: 320px; }
.msg-image { display: block; width: 100%; height: auto; max-height: 260px; object-fit: cover; border-radius: 10px; background: rgba(0,0,0,0.05); }
.msg-time { font-size: 10px; margin-top: 4px; opacity: 0.5; display: inline-flex; align-items: center; gap: 4px; }
.msg-bubble.mine .msg-time { justify-content: flex-end; width: 100%; }
.msg-edited { font-style: italic; }
.msg-check { opacity: 0.6; }
.msg-check.seen { color: #4fc3f7 !important; opacity: 1; }

.msg-reply-quote { display: flex; gap: 6px; padding: 6px 10px; margin-bottom: 6px; background: rgba(0,0,0,0.08); border-left: 3px solid rgba(var(--v-theme-primary), 0.5); border-radius: 6px; font-size: 11px; }
.msg-bubble.mine .msg-reply-quote { background: rgba(255,255,255,0.1); border-left-color: rgba(255,255,255,0.5); }
.msg-reply-body { flex: 1; min-width: 0; }
.msg-reply-sender { font-weight: 700; opacity: 0.9; }
.msg-reply-text { opacity: 0.7; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.msg-actions { position: absolute; top: -12px; right: 8px; display: none; gap: 2px; background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 8px; padding: 2px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
.msg-row.mine .msg-actions { right: auto; left: 8px; }
.msg-bubble:hover .msg-actions { display: flex; }
.msg-action { background: none; border: none; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.6); padding: 4px; border-radius: 6px; }
.msg-action:hover { background: rgba(var(--v-theme-primary), 0.1); color: rgb(var(--v-theme-primary)); }

.msg-edit-area { width: 100%; border: 1px solid rgba(var(--v-theme-primary), 0.5); border-radius: 8px; padding: 6px 10px; font-size: 13px; background: rgba(var(--v-theme-surface), 1); color: rgb(var(--v-theme-on-surface)); resize: vertical; font-family: inherit; outline: none; }
.msg-edit-actions { display: flex; gap: 6px; justify-content: flex-end; margin-top: 6px; }
.msg-edit-btn { padding: 3px 10px; border-radius: 6px; border: none; cursor: pointer; font-size: 11px; font-weight: 600; }
.msg-edit-btn.cancel { background: transparent; color: rgba(var(--v-theme-on-surface), 0.6); }
.msg-edit-btn.save { background: rgb(var(--v-theme-primary)); color: #fff; }

.typing-indicator { display: flex; align-items: center; gap: 8px; padding: 6px 14px; font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.5); font-style: italic; }
.typing-dots { display: inline-flex; gap: 3px; }
.typing-dots span { width: 5px; height: 5px; border-radius: 50%; background: rgba(var(--v-theme-on-surface), 0.4); animation: typing-blink 1.2s infinite ease-in-out; }
.typing-dots span:nth-child(2) { animation-delay: 0.15s; }
.typing-dots span:nth-child(3) { animation-delay: 0.3s; }
@keyframes typing-blink { 0%, 80%, 100% { opacity: 0.2; } 40% { opacity: 1; } }

.jump-to-bottom { position: absolute; right: 24px; bottom: 90px; display: flex; align-items: center; gap: 4px; padding: 6px 10px; border-radius: 16px; background: rgb(var(--v-theme-primary)); color: #fff; border: none; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-size: 12px; font-weight: 600; z-index: 5; }

.reply-bar { display: flex; align-items: center; gap: 8px; padding: 8px 14px; background: rgba(var(--v-theme-primary), 0.06); border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-left: 3px solid rgb(var(--v-theme-primary)); }
.reply-bar-body { flex: 1; min-width: 0; font-size: 12px; }
.reply-bar-sender { font-weight: 700; color: rgb(var(--v-theme-primary)); }
.reply-bar-text { color: rgba(var(--v-theme-on-surface), 0.6); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.reply-bar-close { background: none; border: none; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.5); padding: 4px; border-radius: 6px; }

.chat-input { display: flex; align-items: flex-end; gap: 8px; padding: 10px 16px; border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); position: relative; transition: background 0.15s; }
.chat-input.drag-over { background: rgba(var(--v-theme-primary), 0.08); }
.input-btn { background: none; border: none; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.5); padding: 6px; border-radius: 8px; }
.input-btn:hover { background: rgba(var(--v-theme-primary), 0.1); }
.input-area { flex: 1; }
.input-area textarea { width: 100%; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 12px; padding: 8px 12px; font-size: 13px; resize: none; background: rgba(var(--v-theme-surface-variant), 0.3); color: inherit; outline: none; font-family: inherit; }
.input-area textarea:focus { border-color: rgb(var(--v-theme-primary)); }
.input-file-preview { display: flex; align-items: center; gap: 8px; margin-top: 6px; padding: 6px 8px; border-radius: 10px; background: rgba(var(--v-theme-primary), 0.08); border: 1px solid rgba(var(--v-theme-primary), 0.2); }
.input-file-preview img { width: 40px; height: 40px; object-fit: cover; border-radius: 6px; }
.input-file-icon { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 6px; background: rgba(var(--v-theme-primary), 0.15); color: rgb(var(--v-theme-primary)); }
.input-file-info { flex: 1; min-width: 0; }
.input-file-name { font-size: 12px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.input-file-size { font-size: 10px; color: rgba(var(--v-theme-on-surface), 0.5); }
.input-file-remove { background: none; border: none; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.5); padding: 4px; border-radius: 6px; }
.drop-overlay { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; background: rgba(var(--v-theme-primary), 0.15); border: 2px dashed rgb(var(--v-theme-primary)); border-radius: 8px; color: rgb(var(--v-theme-primary)); font-weight: 600; font-size: 13px; pointer-events: none; z-index: 10; }
.input-send { background: rgb(var(--v-theme-primary)); color: #fff; border: none; border-radius: 10px; padding: 8px 12px; cursor: pointer; }
.input-send:disabled { opacity: 0.4; cursor: not-allowed; }

.hotkey-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px dashed rgba(var(--v-border-color), 0.3); font-size: 13px; }
.hotkey-row:last-of-type { border-bottom: none; }
.hotkey-row kbd { display: inline-block; padding: 2px 8px; border-radius: 6px; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); background: rgba(var(--v-theme-surface-variant), 0.5); font-family: ui-monospace, monospace; font-size: 11px; font-weight: 600; min-width: 24px; text-align: center; }
.hotkey-row span { flex: 1; color: rgba(var(--v-theme-on-surface), 0.8); }

@media (max-width: 959px) {
  .chat-sidebar { width: 100%; }
  .mobile-hidden { display: none !important; }
}
</style>
