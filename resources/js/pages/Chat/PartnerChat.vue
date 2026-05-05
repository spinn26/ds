<template>
  <div class="chat-wrap">
    <!-- Connection-status banner: визуальный сигнал что real-time потерян. -->
    <div v-if="!socketConnected" class="conn-banner">
      <v-icon size="14">mdi-wifi-off</v-icon>
      Соединение потеряно. Сообщения придут с задержкой ~15 сек.
    </div>
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
        <div v-for="t in visibleChats" :key="t.id" class="chat-item" :class="{ active: activeChat?.id === t.id, 'has-unread': t.unread > 0, pinned: t.pinned_at }" @click="openChat(t)">
          <div class="chat-item-avatar" :style="{ background: catColor(t.category) }">
            <v-icon size="18" color="white">{{ catIcon(t.category) }}</v-icon>
          </div>
          <div class="chat-item-body">
            <div class="chat-item-top">
              <span class="chat-item-subject">
                <v-icon v-if="t.pinned_at" size="12" color="primary" class="mr-1">mdi-pin</v-icon>{{ t.subject }}
              </span>
              <span class="chat-item-time">{{ ago(t.last_message_at) }}</span>
            </div>
            <!-- Last message preview — Telegram/Slack стиль. «Вы:» если автор я,
                 ⚙️ для системных, 📎 для вложений уже в preview. -->
            <div v-if="t.last_message_preview" class="chat-item-preview">
              <span v-if="t.last_message_is_system" class="chat-item-preview-prefix">⚙</span>
              <span v-else-if="t.last_message_from_me" class="chat-item-preview-prefix">Вы:</span>
              <span class="chat-item-preview-text">{{ t.last_message_preview }}</span>
            </div>
            <div class="chat-item-bottom">
              <span class="chat-item-cat">{{ catLabel(t.category) }}</span>
              <span class="chat-item-status-chip" :style="{ background: statusClr(t.status) + '22', color: statusClr(t.status) }">{{ statusTxt(t.status) }}</span>
            </div>
          </div>
          <button class="chat-item-pin" :class="{ active: t.pinned_at }" :title="t.pinned_at ? 'Открепить' : 'Закрепить'" @click.stop="togglePin(t, $event)">
            <v-icon size="14">{{ t.pinned_at ? 'mdi-pin' : 'mdi-pin-outline' }}</v-icon>
          </button>
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
                <!-- Reply quote -->
                <div v-if="item.msg.replyTo" class="msg-reply-quote">
                  <v-icon size="12">mdi-reply</v-icon>
                  <div class="msg-reply-body">
                    <div class="msg-reply-sender">{{ item.msg.replyTo.senderName }}</div>
                    <div class="msg-reply-text">{{ item.msg.replyTo.content }}</div>
                  </div>
                </div>
                <!-- Inline edit mode -->
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
                  <a v-if="isImageAttachment(item.msg.attachmentName || item.msg.attachmentPath)"
                    :href="item.msg.attachmentPath" target="_blank" rel="noopener noreferrer" class="msg-image-link">
                    <img :src="item.msg.attachmentPath" :alt="item.msg.attachmentName || 'Изображение'" class="msg-image" loading="lazy" />
                  </a>
                  <a v-else :href="item.msg.attachmentPath" target="_blank" rel="noopener noreferrer" class="msg-attach">
                    <v-icon size="14">mdi-paperclip</v-icon> {{ item.msg.attachmentName || 'Файл' }}
                  </a>
                </template>
                <div class="msg-time">
                  {{ fmtTime(item.msg.createdAt) }}
                  <span v-if="item.msg.editedAt" class="msg-edited" title="Сообщение было изменено">· изменено</span>
                  <!-- Read receipts on own messages -->
                  <v-icon v-if="isMine(item.msg) && isSeen(item.msg)" size="12" class="msg-check seen" title="Прочитано">mdi-check-all</v-icon>
                  <v-icon v-else-if="isMine(item.msg)" size="12" class="msg-check" title="Отправлено">mdi-check</v-icon>
                </div>
                <!-- Reactions row -->
                <div v-if="item.msg.reactions && item.msg.reactions.length" class="msg-reactions">
                  <button v-for="r in item.msg.reactions" :key="r.emoji"
                    class="reaction-chip" :class="{ mine: r.mine }"
                    @click.stop="toggleReaction(item.msg, r.emoji)">
                    <span class="reaction-emoji">{{ r.emoji }}</span>
                    <span class="reaction-count">{{ r.count }}</span>
                  </button>
                </div>
                <!-- Hover actions -->
                <div class="msg-actions">
                  <v-menu location="bottom end">
                    <template #activator="{ props }">
                      <button v-bind="props" class="msg-action" title="Реакция" @click.stop><v-icon size="14">mdi-emoticon-happy-outline</v-icon></button>
                    </template>
                    <div class="reaction-picker">
                      <button v-for="emoji in REACTION_PALETTE" :key="emoji"
                        class="reaction-picker-btn"
                        @click="toggleReaction(item.msg, emoji)">{{ emoji }}</button>
                    </div>
                  </v-menu>
                  <button class="msg-action" title="Ответить" @click="startReply(item.msg)"><v-icon size="14">mdi-reply</v-icon></button>
                  <button v-if="canEdit(item.msg)" class="msg-action" title="Изменить (5 мин)" @click="startEdit(item.msg)"><v-icon size="14">mdi-pencil</v-icon></button>
                </div>
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

        <!-- Reply preview (above input) -->
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

        <!-- CSAT — оценка ответа после resolve/closed. Показывается только если
             ещё не оценено партнёром. После submit заменяется на сообщение
             благодарности. -->
        <div v-if="canRateChat" class="csat-prompt">
          <div class="csat-title">
            <v-icon size="16" color="primary">mdi-star-circle</v-icon>
            Оцените, как мы помогли
          </div>
          <div class="csat-stars">
            <button v-for="n in 5" :key="n"
              class="csat-star" :class="{ filled: csatHover >= n || csatRating >= n }"
              @click="csatRating = n"
              @mouseenter="csatHover = n"
              @mouseleave="csatHover = 0">
              <v-icon size="22">{{ (csatHover >= n || csatRating >= n) ? 'mdi-star' : 'mdi-star-outline' }}</v-icon>
            </button>
          </div>
          <textarea v-if="csatRating > 0" v-model="csatComment"
            class="csat-comment" rows="2" maxlength="1000"
            placeholder="Комментарий (необязательно)"></textarea>
          <button v-if="csatRating > 0" class="csat-submit" :disabled="csatSubmitting" @click="submitCsat">
            <v-icon size="14">mdi-send</v-icon>
            {{ csatSubmitting ? 'Отправка…' : 'Оценить' }}
          </button>
        </div>
        <div v-else-if="activeChat && activeChat.csat_rating" class="csat-shown">
          <v-icon size="14" color="success">mdi-check</v-icon>
          Вы поставили оценку
          <span class="csat-rate">{{ '★'.repeat(activeChat.csat_rating) }}{{ '☆'.repeat(5 - activeChat.csat_rating) }}</span>
        </div>
      </template>

      <!-- No chat selected -->
      <div v-else class="chat-placeholder">
        <v-icon size="64" color="grey-lighten-2">mdi-forum-outline</v-icon>
        <p>Выберите чат или создайте новый</p>
      </div>
    </main>

    <!-- Keyboard shortcuts modal -->
    <v-dialog v-model="showHotkeys" max-width="460">
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon>mdi-keyboard</v-icon>
          Горячие клавиши
        </v-card-title>
        <v-card-text>
          <div class="hotkey-row"><kbd>Enter</kbd><span>Отправить сообщение</span></div>
          <div class="hotkey-row"><kbd>Shift</kbd> + <kbd>Enter</kbd><span>Новая строка</span></div>
          <div class="hotkey-row"><kbd>Esc</kbd><span>Отмена ответа / правки / закрыть чат</span></div>
          <div class="hotkey-row"><kbd>Ctrl</kbd> + <kbd>/</kbd><span>Показать / скрыть эту панель</span></div>
          <div class="hotkey-row"><kbd>?</kbd><span>То же (вне поля ввода)</span></div>
          <v-divider class="my-2" />
          <div class="text-caption text-medium-emphasis">
            Наведи курсор на сообщение, чтобы увидеть кнопки «Ответить» и «Изменить» (редактирование в течение 5 мин после отправки).
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="showHotkeys = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

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
import { useSnackbar } from '../../composables/useSnackbar';
import { getChatStatusColor, getChatCategoryColor } from '../../composables/chatPalette';

const { showError } = useSnackbar();

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
const socketConnected = ref(true);
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

// Read receipts
const otherLastReadAt = ref(null);

// Reply-to + edit state
const replyTo = ref(null); // { id, senderName, content }
const editing = ref(null); // { id, content }
const showHotkeys = ref(false);

// CSAT (Customer Satisfaction) — 5-звёздочная оценка после resolve/closed.
// Показывается inline в области ввода когда тикет закрыт и оценка ещё
// не поставлена. После submit ChatTicket.csat_rating заполняется.
const csatRating = ref(0);
const csatHover = ref(0);
const csatComment = ref('');
const csatSubmitting = ref(false);
const canRateChat = computed(() =>
  activeChat.value
  && ['resolved', 'closed'].includes(activeChat.value.status)
  && !activeChat.value.csat_rating
);

async function submitCsat() {
  if (!activeChat.value || !csatRating.value) return;
  csatSubmitting.value = true;
  try {
    await api.post(`/chat/tickets/${activeChat.value.id}/csat`, {
      rating: csatRating.value,
      comment: csatComment.value || null,
    });
    activeChat.value.csat_rating = csatRating.value;
    activeChat.value.csat_comment = csatComment.value || null;
    csatRating.value = 0; csatHover.value = 0; csatComment.value = '';
    showSuccess('Спасибо за оценку!');
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось отправить оценку');
  } finally {
    csatSubmitting.value = false;
  }
}

// Reactions
const REACTION_PALETTE = ['👍', '❤️', '😂', '🎉', '🙏', '✅'];

// Notifications (desktop + sound)
const notifyEnabled = ref(localStorage.getItem('chat-notify') !== '0');
watch(notifyEnabled, v => localStorage.setItem('chat-notify', v ? '1' : '0'));

function playPing() {
  if (!notifyEnabled.value) return;
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(880, ctx.currentTime);
    osc.frequency.exponentialRampToValueAtTime(1320, ctx.currentTime + 0.1);
    gain.gain.setValueAtTime(0.15, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.25);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + 0.25);
  } catch {}
}

function notifyDesktop(title, body) {
  if (!notifyEnabled.value) return;
  if (!('Notification' in window) || Notification.permission !== 'granted') return;
  try {
    const n = new Notification(title, { body, icon: '/favicon.ico', silent: false, tag: 'ds-chat' });
    n.onclick = () => { window.focus(); n.close(); };
  } catch {}
}

async function requestNotifPermission() {
  if (!('Notification' in window)) return;
  if (Notification.permission === 'default') {
    try { await Notification.requestPermission(); } catch {}
  }
}

// Pinning
async function togglePin(ticket, e) {
  e?.stopPropagation();
  const prev = ticket.pinned_at;
  ticket.pinned_at = prev ? null : new Date().toISOString();
  try {
    const { data } = await api.post(`/chat/tickets/${ticket.id}/pin`);
    ticket.pinned_at = data.pinnedAt;
    // Move pinned to top locally (server returns same ordering on next reload)
    chats.value = [...chats.value].sort(sortChats);
  } catch {
    ticket.pinned_at = prev;
  }
}
function sortChats(a, b) {
  const pa = a.pinned_at ? 1 : 0;
  const pb = b.pinned_at ? 1 : 0;
  if (pa !== pb) return pb - pa;
  return new Date(b.last_message_at || 0) - new Date(a.last_message_at || 0);
}

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
const catColor = getChatCategoryColor;
function catIcon(c) { return { support: 'mdi-headset', backoffice: 'mdi-briefcase', billing: 'mdi-cash', legal: 'mdi-scale-balance', general: 'mdi-help-circle' }[c] || 'mdi-chat'; }
function catLabel(c) { return { support: 'Техподдержка', backoffice: 'Бэк-офис', billing: 'Начисления', legal: 'Юридический', general: 'Общий' }[c] || c; }
const statusClr = getChatStatusColor;
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
  replyTo.value = null;
  editing.value = null;
  csatRating.value = 0;
  csatHover.value = 0;
  csatComment.value = '';
  try {
    const { data } = await api.get(`/chat/tickets/${t.id}`);
    messages.value = data.messages || [];
    otherLastReadAt.value = data.otherLastReadAt || null;
    // Подтягиваем CSAT-поля из server-side ticket в активный объект,
    // чтобы canRateChat / отображение оценки работали без перезагрузки.
    if (data.ticket) {
      activeChat.value.csat_rating = data.ticket.csat_rating ?? null;
      activeChat.value.csat_comment = data.ticket.csat_comment ?? null;
    }
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
    otherLastReadAt.value = data.otherLastReadAt || null;
    if (messages.value.length > prevCount) {
      if (wasAtBottom) scrollDown(true);
      else pendingMessages.value += messages.value.length - prevCount;
    }
  } catch {}
}

// Read-receipt check: true if OTHER side has read past this message
function isSeen(msg) {
  if (!otherLastReadAt.value || !msg.createdAt) return false;
  return new Date(otherLastReadAt.value) >= new Date(msg.createdAt);
}

// Edit window: own message ≤ 5 min old
function canEdit(msg) {
  if (!isMine(msg) || msg.isSystem) return false;
  if (!msg.createdAt) return false;
  return (Date.now() - new Date(msg.createdAt).getTime()) / 60000 <= 5;
}

function startReply(msg) {
  replyTo.value = { id: msg.id, senderName: msg.senderName, content: msg.content };
  nextTick(() => taRef.value?.focus());
}

function cancelReply() {
  replyTo.value = null;
}

function startEdit(msg) {
  editing.value = { id: msg.id, content: msg.content };
}

function cancelEdit() {
  editing.value = null;
}

async function saveEdit() {
  if (!editing.value) return;
  const newText = editing.value.content.trim();
  if (!newText) return;
  try {
    await api.put(`/chat/messages/${editing.value.id}`, { content: newText });
    const msg = messages.value.find(m => String(m.id) === String(editing.value.id));
    if (msg) { msg.content = newText; msg.editedAt = new Date().toISOString(); }
    editing.value = null;
  } catch (e) {
    showError(e?.response?.data?.message || 'Не удалось изменить');
  }
}

async function toggleReaction(msg, emoji) {
  // Optimistic update
  msg.reactions = msg.reactions || [];
  const existing = msg.reactions.find(r => r.emoji === emoji);
  if (existing) {
    if (existing.mine) {
      existing.count--;
      existing.mine = false;
      if (existing.count <= 0) {
        msg.reactions = msg.reactions.filter(r => r.emoji !== emoji);
      }
    } else {
      existing.count++;
      existing.mine = true;
    }
  } else {
    msg.reactions.push({ emoji, count: 1, mine: true });
  }
  try {
    await api.post(`/chat/messages/${msg.id}/reactions`, { emoji });
  } catch {
    // revert: caller can call refreshMessages to resync
    refreshMessages();
  }
}

async function send() {
  if (!msgText.value?.trim() && !file.value) return;
  sending.value = true;
  // Идемпотентный токен — backend дедуплицирует, фронт игнорирует
  // socket-emit с этим же id.
  const clientMessageId = (crypto?.randomUUID?.() ?? `cmid-${Date.now()}-${Math.random().toString(36).slice(2)}`);
  try {
    const fd = new FormData();
    fd.append('message', msgText.value || '');
    fd.append('client_message_id', clientMessageId);
    if (file.value) fd.append('attachment', file.value);
    if (replyTo.value) fd.append('reply_to_id', String(replyTo.value.id));
    await api.post(`/chat/tickets/${activeChat.value.id}/messages`, fd);
    // Clear draft on successful send
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
  } catch (e) {
    showError(e?.response?.data?.message || 'Не удалось отправить сообщение');
  }
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
  if (document.hidden) return;
  poll = setInterval(() => { refreshMessages(); loadChats(); }, 15000); // slower since socket handles real-time
}
function stopPoll() { if (poll) { clearInterval(poll); poll = null; } }
function onVisibilityChange() {
  if (document.hidden) {
    stopPoll();
  } else if (activeChat.value) {
    refreshMessages();
    loadChats();
    startPoll();
  }
}

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
  const token = auth.token;
  if (!token) return;
  try {
    const { io } = await import('socket.io-client');
    // Priority: explicit override -> local dev on :3001 -> same-origin (nginx proxy on prod)
    const isLocal = ['localhost', '127.0.0.1'].includes(location.hostname);
    const defaultHost = isLocal
      ? `ws://${location.hostname}:3001`
      : `${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}`;
    const host = window.__SOCKET_URL__ || defaultHost;
    socket = io(host, { auth: { token }, transports: ['websocket', 'polling'], reconnection: true });

    socket.on('connect', () => { socketConnected.value = true; });
    socket.on('disconnect', () => { socketConnected.value = false; });
    socket.on('connect_error', () => { socketConnected.value = false; });

    socket.on('chat:new-message', (m) => {
      const isOwn = String(m.senderId) === String(currentUserId);
      const isActive = activeChat.value && Number(m.ticketId) === Number(activeChat.value.id);

      // Push live into open conversation
      if (isActive) {
        if (!messages.value.some(x => String(x.id) === String(m.id))) {
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
        }
      }

      // Notification: foreign message AND (tab hidden OR chat not open)
      if (!isOwn && (document.hidden || !isActive)) {
        playPing();
        notifyDesktop(m.senderName || 'Новое сообщение',
          (m.content || '').slice(0, 120) || 'Прислали сообщение');
      }

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

    socket.on('chat:message-edited', (e) => {
      if (!activeChat.value || Number(e.ticketId) !== Number(activeChat.value.id)) return;
      const m = messages.value.find(x => String(x.id) === String(e.id));
      if (m) { m.content = e.content; m.editedAt = e.editedAt; }
    });

    socket.on('chat:reaction-toggled', (e) => {
      if (!activeChat.value || Number(e.ticketId) !== Number(activeChat.value.id)) return;
      if (String(e.userId) === String(currentUserId)) return; // own action already applied optimistically
      const msg = messages.value.find(m => String(m.id) === String(e.messageId));
      if (!msg) return;
      msg.reactions = msg.reactions || [];
      const r = msg.reactions.find(x => x.emoji === e.emoji);
      if (e.action === 'added') {
        if (r) r.count++;
        else msg.reactions.push({ emoji: e.emoji, count: 1, mine: false });
      } else if (e.action === 'removed') {
        if (r) {
          r.count--;
          if (r.count <= 0) msg.reactions = msg.reactions.filter(x => x.emoji !== e.emoji);
        }
      }
    });

    // Staff changed status / priority / assignee / pin on our ticket — reflect it live
    socket.on('chat:ticket-updated', (e) => {
      const t = chats.value.find(x => Number(x.id) === Number(e.ticketId));
      if (t) {
        if (e.status !== undefined) t.status = e.status;
        if (e.priority !== undefined) t.priority = e.priority;
        if (e.assignedName !== undefined) t.assigned_name = e.assignedName;
        if (e.pinnedAt !== undefined) { t.pinned_at = e.pinnedAt; chats.value = [...chats.value].sort(sortChats); }
      }
      if (activeChat.value && Number(activeChat.value.id) === Number(e.ticketId)) {
        if (e.status !== undefined) activeChat.value.status = e.status;
        if (e.priority !== undefined) activeChat.value.priority = e.priority;
        if (e.assignedName !== undefined) activeChat.value.assigned_name = e.assignedName;
        if (e.pinnedAt !== undefined) activeChat.value.pinned_at = e.pinnedAt;
      }
    });
  } catch (e) {
    // Socket unavailable — polling keeps the UI alive
    if (import.meta.env.DEV) console.warn('Chat socket unavailable, falling back to polling:', e?.message);
  }
}

watch(() => route.query, checkQuery, { immediate: false });

// Global keyboard shortcuts
function onGlobalKey(e) {
  // Ignore shortcuts when the user is typing inside an input / textarea
  const tag = e.target?.tagName;
  const inField = tag === 'INPUT' || tag === 'TEXTAREA' || e.target?.isContentEditable;

  // Ctrl+/ or ? — toggle hotkeys modal
  if ((e.ctrlKey || e.metaKey) && e.key === '/') {
    e.preventDefault();
    showHotkeys.value = !showHotkeys.value;
    return;
  }
  if (!inField && e.key === '?' && !e.ctrlKey && !e.metaKey && !e.altKey) {
    e.preventDefault();
    showHotkeys.value = !showHotkeys.value;
    return;
  }
  if (e.key === 'Escape') {
    if (showHotkeys.value) { showHotkeys.value = false; return; }
    if (editing.value) { cancelEdit(); return; }
    if (replyTo.value) { cancelReply(); return; }
    if (activeChat.value && !inField) { closeActiveChat(); return; }
  }
}

onMounted(() => {
  loadChats();
  checkQuery();
  connectSocket();
  window.addEventListener('keydown', onGlobalKey);
  document.addEventListener('visibilitychange', onVisibilityChange);
  requestNotifPermission();
});

onUnmounted(() => {
  stopPoll();
  if (socket && activeChat.value) socket.emit('ticket:leave', activeChat.value.id);
  socket?.disconnect();
  document.title = BASE_TITLE;
  window.removeEventListener('keydown', onGlobalKey);
  document.removeEventListener('visibilitychange', onVisibilityChange);
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
.chat-item-preview { display: flex; gap: 4px; margin-top: 2px; font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.62); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
.chat-item-preview-prefix { color: rgba(var(--v-theme-on-surface), 0.45); flex-shrink: 0; }
.chat-item-preview-text { overflow: hidden; text-overflow: ellipsis; }
.chat-item.has-unread .chat-item-preview { color: rgba(var(--v-theme-on-surface), 0.92); font-weight: 500; }
.conn-banner { position: absolute; top: 0; left: 0; right: 0; z-index: 100; padding: 6px 12px; background: rgba(var(--v-theme-warning), 0.18); color: rgb(var(--v-theme-warning)); font-size: 12px; display: flex; align-items: center; gap: 6px; border-bottom: 1px solid rgba(var(--v-theme-warning), 0.3); }
.csat-prompt { padding: 14px 16px; background: rgba(var(--v-theme-primary), 0.06); border-top: 1px solid rgba(var(--v-theme-primary), 0.18); display: flex; flex-direction: column; gap: 8px; }
.csat-title { font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px; }
.csat-stars { display: flex; gap: 4px; }
.csat-star { background: transparent; border: none; padding: 2px; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.35); transition: color 0.15s, transform 0.1s; }
.csat-star:hover { transform: scale(1.15); }
.csat-star.filled { color: #f5a524; }
.csat-comment { width: 100%; resize: vertical; min-height: 50px; padding: 6px 10px; border-radius: 8px; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); background: rgb(var(--v-theme-surface)); color: inherit; font-size: 13px; }
.csat-submit { align-self: flex-start; display: inline-flex; align-items: center; gap: 4px; padding: 6px 14px; border-radius: 8px; border: none; background: rgb(var(--v-theme-primary)); color: #fff; font-size: 12px; font-weight: 600; cursor: pointer; }
.csat-submit:disabled { opacity: 0.5; cursor: not-allowed; }
.csat-shown { padding: 12px 16px; font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.6); display: flex; align-items: center; gap: 6px; border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.csat-rate { color: #f5a524; letter-spacing: 1px; font-weight: 600; }
.chat-item-status-chip { padding: 2px 8px; border-radius: 10px; font-weight: 600; font-size: 10px; }
.unread-badge { position: absolute; right: 12px; top: 12px; background: rgb(var(--v-theme-error)); color: #fff; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 10px; min-width: 18px; text-align: center; }
.chat-item.has-unread { background: rgba(var(--v-theme-primary), 0.06); }
.chat-item.has-unread .chat-item-subject { color: rgb(var(--v-theme-primary)); font-weight: 700; }
.chat-item.pinned { background: rgba(var(--v-theme-primary), 0.04); }
.chat-item-pin { position: absolute; right: 12px; bottom: 10px; background: none; border: none; padding: 2px; border-radius: 4px; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.3); opacity: 0; transition: opacity 0.15s, color 0.15s; }
.chat-item:hover .chat-item-pin { opacity: 1; }
.chat-item-pin.active { color: rgb(var(--v-theme-primary)); opacity: 1; }
.chat-item-pin:hover { color: rgb(var(--v-theme-primary)); }

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
.msg-bubble.mine { background: rgb(var(--v-theme-primary)); color: #fff; border-bottom-right-radius: 4px; }
.msg-sender { font-size: 11px; font-weight: 600; margin-bottom: 2px; color: rgba(var(--v-theme-on-surface), 0.6); }
.msg-bubble.mine .msg-sender { color: rgba(255,255,255,0.8); }
.msg-text { font-size: 14px; line-height: 1.5; white-space: pre-line; word-break: break-word; }
.msg-attach { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; margin-top: 6px; }
.msg-bubble.mine .msg-attach { color: rgba(255,255,255,0.85); }
.msg-image-link { display: block; margin-top: 6px; border-radius: 10px; overflow: hidden; max-width: 320px; }
.msg-image { display: block; width: 100%; height: auto; max-height: 280px; object-fit: cover; border-radius: 10px; background: rgba(0,0,0,0.05); }
.msg-time { font-size: 10px; margin-top: 4px; opacity: 0.5; display: inline-flex; align-items: center; gap: 4px; }
.msg-bubble.mine .msg-time { text-align: right; justify-content: flex-end; width: 100%; }
.msg-edited { font-style: italic; opacity: 0.7; }
.msg-check { opacity: 0.6; }
.msg-check.seen { color: #4fc3f7 !important; opacity: 1; }

/* Reply quote inside message */
.msg-reply-quote { display: flex; gap: 6px; padding: 6px 10px; margin-bottom: 6px; background: rgba(0,0,0,0.1); border-left: 3px solid rgba(var(--v-theme-primary), 0.5); border-radius: 6px; font-size: 11px; }
.msg-bubble.mine .msg-reply-quote { background: rgba(255,255,255,0.1); border-left-color: rgba(255,255,255,0.5); }
.msg-reply-body { flex: 1; min-width: 0; }
.msg-reply-sender { font-weight: 700; opacity: 0.9; }
.msg-reply-text { opacity: 0.7; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Hover actions on messages */
.msg-bubble { transition: box-shadow 0.15s; }
.msg-actions { position: absolute; top: -12px; right: 8px; display: none; gap: 2px; background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 8px; padding: 2px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
.msg-row.mine .msg-actions { right: auto; left: 8px; }
.msg-bubble:hover .msg-actions { display: flex; }
.msg-action { background: none; border: none; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.6); padding: 4px; border-radius: 6px; }
.msg-action:hover { background: rgba(var(--v-theme-primary), 0.1); color: rgb(var(--v-theme-primary)); }

/* Reactions */
.msg-reactions { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
.reaction-chip { display: inline-flex; align-items: center; gap: 3px; padding: 2px 7px; border-radius: 12px; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); background: rgba(var(--v-theme-surface-variant), 0.5); font-size: 11px; cursor: pointer; transition: all 0.15s; }
.reaction-chip:hover { background: rgba(var(--v-theme-primary), 0.1); border-color: rgba(var(--v-theme-primary), 0.5); }
.reaction-chip.mine { background: rgba(var(--v-theme-primary), 0.15); border-color: rgb(var(--v-theme-primary)); color: rgb(var(--v-theme-primary)); font-weight: 700; }
.reaction-emoji { font-size: 13px; line-height: 1; }
.reaction-count { font-size: 10px; font-weight: 600; }
.msg-bubble.mine .reaction-chip { background: rgba(255,255,255,0.15); border-color: rgba(255,255,255,0.3); color: #fff; }
.msg-bubble.mine .reaction-chip.mine { background: rgba(255,255,255,0.25); border-color: rgba(255,255,255,0.5); }
.reaction-picker { display: flex; gap: 2px; padding: 4px; background: rgb(var(--v-theme-surface)); border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.reaction-picker-btn { background: none; border: none; cursor: pointer; padding: 4px 6px; border-radius: 6px; font-size: 16px; line-height: 1; transition: background 0.1s; }
.reaction-picker-btn:hover { background: rgba(var(--v-theme-primary), 0.1); }

/* Inline edit */
.msg-edit-area { width: 100%; border: 1px solid rgba(var(--v-theme-primary), 0.5); border-radius: 8px; padding: 6px 10px; font-size: 14px; background: rgba(var(--v-theme-surface), 1); color: rgb(var(--v-theme-on-surface)); resize: vertical; font-family: inherit; outline: none; }
.msg-edit-actions { display: flex; gap: 6px; justify-content: flex-end; margin-top: 6px; }
.msg-edit-btn { padding: 3px 10px; border-radius: 6px; border: none; cursor: pointer; font-size: 11px; font-weight: 600; }
.msg-edit-btn.cancel { background: transparent; color: rgba(var(--v-theme-on-surface), 0.6); }
.msg-edit-btn.save { background: rgb(var(--v-theme-primary)); color: #fff; }

/* Reply preview bar above input */
.reply-bar { display: flex; align-items: center; gap: 8px; padding: 8px 14px; background: rgba(var(--v-theme-primary), 0.06); border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-left: 3px solid rgb(var(--v-theme-primary)); }
.reply-bar-body { flex: 1; min-width: 0; font-size: 12px; }
.reply-bar-sender { font-weight: 700; color: rgb(var(--v-theme-primary)); }
.reply-bar-text { color: rgba(var(--v-theme-on-surface), 0.6); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.reply-bar-close { background: none; border: none; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.5); padding: 4px; border-radius: 6px; }
.reply-bar-close:hover { background: rgba(var(--v-theme-error), 0.1); color: rgb(var(--v-theme-error)); }

/* Hotkeys modal rows */
.hotkey-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px dashed rgba(var(--v-border-color), 0.3); font-size: 13px; }
.hotkey-row:last-of-type { border-bottom: none; }
.hotkey-row kbd { display: inline-block; padding: 2px 8px; border-radius: 6px; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); background: rgba(var(--v-theme-surface-variant), 0.5); font-family: ui-monospace, monospace; font-size: 11px; font-weight: 600; min-width: 24px; text-align: center; }
.hotkey-row span { flex: 1; color: rgba(var(--v-theme-on-surface), 0.8); }

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
