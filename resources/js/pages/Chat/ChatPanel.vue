<template>
  <div class="d-flex flex-column h-100">
    <!-- Header -->
    <div class="pa-3 border-b">
      <div class="d-flex align-center ga-2">
        <div class="flex-grow-1">
          <div class="text-subtitle-2 font-weight-bold">{{ ticket.subject }}</div>
          <div class="text-caption text-medium-emphasis">#{{ ticket.id }} · {{ ticket.customer_name }}</div>
        </div>
        <v-menu>
          <template #activator="{ props }">
            <v-chip v-bind="props" :color="statusColor(ticket.status)" size="small" variant="tonal" append-icon="mdi-chevron-down">
              {{ statusLabel(ticket.status) }}
            </v-chip>
          </template>
          <v-list density="compact">
            <v-list-item v-for="s in statuses" :key="s.value" @click="changeStatus(s.value)">
              <template #prepend><v-icon :color="s.color" size="14">mdi-circle</v-icon></template>
              <v-list-item-title>{{ s.title }}</v-list-item-title>
            </v-list-item>
          </v-list>
        </v-menu>
        <v-btn icon size="small" variant="text" @click="$emit('close')"><v-icon>mdi-close</v-icon></v-btn>
      </div>

      <!-- Tabs: Chat / Notes / Info -->
      <v-tabs v-model="tab" density="compact" class="mt-2" color="primary">
        <v-tab value="chat" size="small">Чат</v-tab>
        <v-tab value="notes" size="small">Заметки</v-tab>
        <v-tab value="info" size="small">Инфо</v-tab>
      </v-tabs>
    </div>

    <!-- TAB: Chat -->
    <template v-if="tab === 'chat'">
      <div ref="messagesRef" class="flex-grow-1 overflow-y-auto pa-3" style="min-height: 0; scroll-behavior: smooth">
        <div v-for="msg in messages" :key="msg.id" class="mb-3">
          <div v-if="msg.isSystem" class="text-center">
            <v-chip size="x-small" variant="text" class="text-medium-emphasis font-italic">{{ msg.content }}</v-chip>
          </div>
          <div v-else :class="['d-flex', msg.isAgent ? 'justify-end' : 'justify-start']">
            <div style="max-width: 75%">
              <div v-if="!msg.isAgent" class="text-caption font-weight-medium mb-1">{{ msg.senderName }}</div>
              <div :class="['pa-3 rounded-lg', msg.isAgent ? 'bg-primary text-white' : 'bg-surface-variant']" style="word-break: break-word">
                <div class="text-body-2" style="white-space: pre-line">{{ msg.content }}</div>
                <div v-if="msg.attachmentPath" class="mt-1">
                  <a :href="msg.attachmentPath" target="_blank" :class="msg.isAgent ? 'text-white' : 'text-primary'" class="text-caption">
                    <v-icon size="14">mdi-paperclip</v-icon> {{ msg.attachmentName || 'Файл' }}
                  </a>
                </div>
              </div>
              <div class="text-caption text-medium-emphasis mt-1" :class="msg.isAgent ? 'text-right' : ''">
                {{ fmtTime(msg.createdAt) }}
              </div>
            </div>
          </div>
        </div>
        <div v-if="!messages.length" class="text-center pa-8 text-medium-emphasis">Нет сообщений</div>
      </div>

      <!-- Quick replies -->
      <div v-if="showQuickReplies && quickReplies.length" class="border-t pa-2">
        <div class="text-caption text-medium-emphasis mb-1">Быстрые ответы</div>
        <div class="d-flex ga-1 flex-wrap">
          <v-chip v-for="qr in quickReplies" :key="qr.id" size="small" variant="outlined" @click="applyQuickReply(qr)">
            {{ qr.title }}
          </v-chip>
        </div>
      </div>

      <!-- Input -->
      <div v-if="ticket.status !== 'closed'" class="pa-3 border-t">
        <div class="d-flex ga-2 align-end">
          <v-btn icon size="x-small" variant="text" @click="showQuickReplies = !showQuickReplies">
            <v-icon>mdi-lightning-bolt</v-icon>
          </v-btn>
          <v-textarea v-model="messageText" placeholder="Сообщение..." rows="1" auto-grow max-rows="4"
            hide-details class="flex-grow-1" @keydown.enter.exact.prevent="sendMessage" />
          <input ref="fileInput" type="file" hidden @change="onFile" />
          <v-btn icon size="small" variant="text" @click="$refs.fileInput.click()"><v-icon>mdi-paperclip</v-icon></v-btn>
          <v-btn icon color="primary" :loading="sending" @click="sendMessage"><v-icon>mdi-send</v-icon></v-btn>
        </div>
        <div v-if="file" class="text-caption text-medium-emphasis mt-1 d-flex align-center ga-1">
          <v-icon size="14">mdi-file</v-icon> {{ file.name }}
          <v-btn icon size="x-small" variant="text" @click="file = null"><v-icon size="14">mdi-close</v-icon></v-btn>
        </div>
      </div>
    </template>

    <!-- TAB: Notes -->
    <template v-if="tab === 'notes'">
      <div class="flex-grow-1 overflow-y-auto pa-3" style="min-height: 0">
        <div v-for="note in notes" :key="note.id" class="mb-3">
          <v-card variant="tonal" color="amber" class="pa-3">
            <div class="text-body-2" style="white-space: pre-line">{{ note.content }}</div>
            <div class="text-caption text-medium-emphasis mt-1">{{ note.author_name }} · {{ fmtTime(note.created_at) }}</div>
          </v-card>
        </div>
        <div v-if="!notes.length" class="text-center pa-8 text-medium-emphasis">Нет заметок</div>
      </div>
      <div class="pa-3 border-t">
        <div class="d-flex ga-2 align-end">
          <v-textarea v-model="noteText" placeholder="Внутренняя заметка..." rows="2" auto-grow hide-details class="flex-grow-1" />
          <v-btn icon color="amber-darken-2" :loading="addingNote" @click="addNote"><v-icon>mdi-note-plus</v-icon></v-btn>
        </div>
      </div>
    </template>

    <!-- TAB: Info -->
    <template v-if="tab === 'info'">
      <div class="pa-4 overflow-y-auto" style="min-height: 0">
        <div class="mb-4">
          <div class="text-caption text-medium-emphasis">Клиент</div>
          <div class="font-weight-medium">{{ ticket.customer_name }}</div>
          <div class="text-caption">{{ ticket.customer_email }}</div>
        </div>
        <div class="mb-4">
          <div class="text-caption text-medium-emphasis">Отдел</div>
          <v-chip size="small" :color="deptColor(ticket.department)">{{ deptLabel(ticket.department) }}</v-chip>
        </div>
        <div class="mb-4">
          <div class="text-caption text-medium-emphasis">Приоритет</div>
          <v-chip size="small" :color="prioColor(ticket.priority)">{{ prioLabel(ticket.priority) }}</v-chip>
        </div>
        <div class="mb-4">
          <div class="text-caption text-medium-emphasis">Назначен</div>
          <div v-if="ticket.assigned_name">{{ ticket.assigned_name }}</div>
          <v-btn v-else variant="outlined" size="small" @click="assignDialog = true">Назначить</v-btn>
        </div>
        <div class="mb-4">
          <div class="text-caption text-medium-emphasis">Создан</div>
          <div class="text-body-2">{{ fmtTime(ticket.created_at) }}</div>
        </div>
        <div v-if="ticket.tags" class="mb-4">
          <div class="text-caption text-medium-emphasis mb-1">Теги</div>
          <div class="d-flex ga-1 flex-wrap">
            <v-chip v-for="tag in parseTags(ticket.tags)" :key="tag" size="x-small" variant="outlined">{{ tag }}</v-chip>
          </div>
        </div>
      </div>
    </template>

    <!-- Assign dialog -->
    <v-dialog v-model="assignDialog" max-width="400">
      <v-card>
        <v-card-title>Назначить сотрудника</v-card-title>
        <v-card-text>
          <v-list density="compact">
            <v-list-item v-for="s in staffList" :key="s.id" @click="assignTicket(s.id)">
              <v-list-item-title>{{ s.name }}</v-list-item-title>
              <v-list-item-subtitle>{{ s.email }}</v-list-item-subtitle>
            </v-list-item>
          </v-list>
        </v-card-text>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, nextTick } from 'vue';
import api from '../../api';

const props = defineProps({ ticket: { type: Object, required: true } });
const emit = defineEmits(['close', 'status-change', 'reload']);

const tab = ref('chat');
const messages = ref([]);
const notes = ref([]);
const quickReplies = ref([]);
const staffList = ref([]);
const messageText = ref('');
const noteText = ref('');
const file = ref(null);
const sending = ref(false);
const addingNote = ref(false);
const showQuickReplies = ref(false);
const assignDialog = ref(false);
const messagesRef = ref(null);
const fileInput = ref(null);
let pollInterval = null;

const statuses = [
  { title: 'Новый', value: 'new', color: 'blue' },
  { title: 'Открыт', value: 'open', color: 'warning' },
  { title: 'Ожидание', value: 'pending', color: 'orange' },
  { title: 'Решён', value: 'resolved', color: 'success' },
  { title: 'Закрыт', value: 'closed', color: 'grey' },
];

function statusColor(s) { return { new: 'blue', open: 'warning', pending: 'orange', resolved: 'success', closed: 'grey' }[s] || 'grey'; }
function statusLabel(s) { return { new: 'Новый', open: 'Открыт', pending: 'Ожидание', resolved: 'Решён', closed: 'Закрыт' }[s] || s; }
function deptColor(d) { return { technical: 'blue', billing: 'green', sales: 'orange', general: 'grey' }[d] || 'grey'; }
function deptLabel(d) { return { technical: 'Техподдержка', billing: 'Биллинг', sales: 'Продажи', general: 'Общие' }[d] || d; }
function prioColor(p) { return { critical: 'error', high: 'orange', medium: 'warning', low: 'success' }[p] || 'grey'; }
function prioLabel(p) { return { critical: 'Критический', high: 'Высокий', medium: 'Средний', low: 'Низкий' }[p] || p; }

function parseTags(tags) {
  if (Array.isArray(tags)) return tags;
  try { return JSON.parse(tags); } catch { return []; }
}

function fmtTime(d) {
  if (!d) return '';
  const date = new Date(d);
  if (isNaN(date.getTime())) return '';
  return date.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

function scrollBottom() {
  nextTick(() => { if (messagesRef.value) messagesRef.value.scrollTop = messagesRef.value.scrollHeight; });
}

async function loadMessages() {
  try {
    const { data } = await api.get(`/chat/tickets/${props.ticket.id}`);
    messages.value = data.messages || [];
    scrollBottom();
  } catch {}
}

async function loadNotes() {
  try {
    const { data } = await api.get(`/chat/tickets/${props.ticket.id}/notes`);
    notes.value = data || [];
  } catch {}
}

async function loadQuickReplies() {
  try {
    const { data } = await api.get('/chat/quick-replies');
    quickReplies.value = data || [];
  } catch {}
}

async function loadStaff() {
  try {
    const { data } = await api.get('/chat/tickets/staff');
    staffList.value = data || [];
  } catch {}
}

async function sendMessage() {
  if (!messageText.value?.trim() && !file.value) return;
  sending.value = true;
  try {
    const fd = new FormData();
    fd.append('message', messageText.value || '');
    if (file.value) fd.append('attachment', file.value);
    await api.post(`/chat/tickets/${props.ticket.id}/messages`, fd);
    messageText.value = '';
    file.value = null;
    await loadMessages();
  } catch {}
  sending.value = false;
}

async function addNote() {
  if (!noteText.value?.trim()) return;
  addingNote.value = true;
  try {
    await api.post(`/chat/tickets/${props.ticket.id}/notes`, { content: noteText.value });
    noteText.value = '';
    await loadNotes();
  } catch {}
  addingNote.value = false;
}

async function changeStatus(status) {
  try {
    await api.post(`/chat/tickets/${props.ticket.id}/status`, { status });
    emit('status-change', props.ticket.id, status);
    await loadMessages();
  } catch {}
}

async function assignTicket(userId) {
  try {
    await api.post(`/chat/tickets/${props.ticket.id}/assign`, { user_id: userId });
    assignDialog.value = false;
    emit('reload');
    await loadMessages();
  } catch {}
}

function applyQuickReply(qr) {
  messageText.value = qr.content.replace('{agent_name}', 'Сотрудник');
  showQuickReplies.value = false;
}

function onFile(e) { file.value = e.target.files?.[0] || null; }

watch(() => props.ticket.id, () => {
  messages.value = [];
  notes.value = [];
  tab.value = 'chat';
  loadMessages();
  loadNotes();
}, { immediate: true });

onMounted(() => {
  loadQuickReplies();
  loadStaff();
  pollInterval = setInterval(loadMessages, 10000);
});

import { onUnmounted } from 'vue';
onUnmounted(() => { if (pollInterval) clearInterval(pollInterval); });
</script>

<style scoped>
.border-b { border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.border-t { border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.bg-surface-variant { background: rgba(var(--v-theme-surface-variant), 1); }
</style>
