<template>
  <div class="d-flex" style="height: calc(100vh - 120px); min-height: 400px">
    <!-- Left: chat list -->
    <div class="border-r d-flex flex-column" :style="mobile && selectedTicket ? 'display:none!important' : ''" style="width: 340px; flex-shrink: 0">
      <div class="pa-3 border-b d-flex align-center ga-2">
        <span class="text-h6 font-weight-bold flex-grow-1">Сообщения</span>
        <v-btn color="primary" size="small" icon @click="newDialog = true"><v-icon>mdi-plus</v-icon></v-btn>
      </div>

      <div class="flex-grow-1 overflow-y-auto">
        <v-list lines="three" class="pa-0">
          <v-list-item v-for="t in tickets" :key="t.id" :active="selectedTicket?.id === t.id"
            @click="selectTicket(t)" class="border-b py-3">
            <template #prepend>
              <v-avatar size="40" :color="deptColor(t.department)">
                <v-icon size="20" color="white">{{ deptIcon(t.department) }}</v-icon>
              </v-avatar>
            </template>
            <v-list-item-title class="font-weight-medium">{{ t.subject }}</v-list-item-title>
            <v-list-item-subtitle class="mt-1">
              <v-chip size="x-small" :color="statusColor(t.status)" variant="tonal" class="mr-1">{{ statusLabel(t.status) }}</v-chip>
              <span class="text-caption">{{ deptLabel(t.department) }}</span>
            </v-list-item-subtitle>
            <template #append>
              <div class="text-caption text-medium-emphasis">{{ ago(t.last_message_at || t.updated_at) }}</div>
            </template>
          </v-list-item>
        </v-list>
        <div v-if="!tickets.length && !loading" class="text-center pa-8">
          <v-icon size="48" color="grey-lighten-1">mdi-chat-outline</v-icon>
          <div class="text-body-2 text-medium-emphasis mt-2">Нет обращений</div>
          <v-btn color="primary" variant="tonal" size="small" class="mt-3" @click="newDialog = true">Создать обращение</v-btn>
        </div>
      </div>
    </div>

    <!-- Right: chat window -->
    <div class="flex-grow-1 d-flex flex-column" :style="mobile && !selectedTicket ? 'display:none!important' : ''">
      <template v-if="selectedTicket">
        <!-- Header -->
        <div class="pa-3 border-b d-flex align-center ga-2">
          <v-btn v-if="mobile" icon size="small" variant="text" @click="selectedTicket = null">
            <v-icon>mdi-arrow-left</v-icon>
          </v-btn>
          <div class="flex-grow-1">
            <div class="font-weight-medium">{{ selectedTicket.subject }}</div>
            <div class="text-caption text-medium-emphasis d-flex align-center ga-2">
              <v-chip size="x-small" :color="deptColor(selectedTicket.department)" variant="tonal">{{ deptLabel(selectedTicket.department) }}</v-chip>
              <v-chip size="x-small" :color="statusColor(selectedTicket.status)" variant="tonal">{{ statusLabel(selectedTicket.status) }}</v-chip>
              <span v-if="selectedTicket.assigned_name">Ответственный: {{ selectedTicket.assigned_name }}</span>
            </div>
          </div>
        </div>

        <!-- Messages -->
        <div ref="messagesRef" class="flex-grow-1 overflow-y-auto pa-4" style="min-height: 0; scroll-behavior: smooth">
          <div v-for="msg in messages" :key="msg.id" class="mb-4">
            <div v-if="msg.isSystem" class="text-center my-2">
              <v-chip size="x-small" variant="text" class="text-medium-emphasis font-italic">{{ msg.content }}</v-chip>
            </div>
            <div v-else :class="['d-flex', !msg.isAgent ? 'justify-end' : 'justify-start']">
              <div style="max-width: 70%">
                <div v-if="msg.isAgent" class="text-caption font-weight-medium mb-1 text-primary">{{ msg.senderName }}</div>
                <div :class="['pa-3 rounded-xl', !msg.isAgent ? 'bg-primary text-white' : 'bg-surface-variant']"
                  style="word-break: break-word">
                  <div class="text-body-2" style="white-space: pre-line">{{ msg.content }}</div>
                  <div v-if="msg.attachmentPath" class="mt-1">
                    <a :href="msg.attachmentPath" target="_blank"
                      :class="!msg.isAgent ? 'text-white' : 'text-primary'" class="text-caption d-inline-flex align-center ga-1">
                      <v-icon size="14">mdi-paperclip</v-icon>{{ msg.attachmentName || 'Файл' }}
                    </a>
                  </div>
                </div>
                <div class="text-caption text-medium-emphasis mt-1" :class="!msg.isAgent ? 'text-right' : ''">
                  {{ fmtTime(msg.createdAt) }}
                </div>
              </div>
            </div>
          </div>
          <div v-if="!messages.length && !loadingMessages" class="text-center pa-8 text-medium-emphasis">
            <v-icon size="48" color="grey-lighten-1">mdi-message-text-outline</v-icon>
            <div class="mt-2">Нет сообщений</div>
          </div>
        </div>

        <!-- Input -->
        <div v-if="selectedTicket.status !== 'closed'" class="pa-3 border-t">
          <div class="d-flex ga-2 align-end">
            <v-textarea v-model="msgText" placeholder="Напишите сообщение..."
              rows="1" auto-grow max-rows="5" hide-details class="flex-grow-1"
              @keydown.enter.exact.prevent="sendMessage" />
            <input ref="fileInput" type="file" hidden @change="onFile" />
            <v-btn icon size="small" variant="text" @click="$refs.fileInput.click()"><v-icon>mdi-paperclip</v-icon></v-btn>
            <v-btn icon color="primary" :loading="sending" @click="sendMessage"><v-icon>mdi-send</v-icon></v-btn>
          </div>
          <div v-if="attachFile" class="text-caption mt-1 d-flex align-center ga-1 text-medium-emphasis">
            <v-icon size="14">mdi-file</v-icon>{{ attachFile.name }}
            <v-btn icon size="x-small" variant="text" @click="attachFile = null"><v-icon size="14">mdi-close</v-icon></v-btn>
          </div>
        </div>
        <div v-else class="pa-3 border-t text-center">
          <v-chip size="small" color="grey" variant="tonal" prepend-icon="mdi-lock">Обращение закрыто</v-chip>
        </div>
      </template>

      <!-- No ticket selected -->
      <div v-else class="d-flex align-center justify-center h-100">
        <div class="text-center">
          <v-icon size="72" color="grey-lighten-1">mdi-chat-processing-outline</v-icon>
          <div class="text-h6 text-medium-emphasis mt-3">Выберите обращение</div>
          <div class="text-body-2 text-medium-emphasis mb-3">или создайте новое</div>
          <v-btn color="primary" prepend-icon="mdi-plus" @click="newDialog = true">Новое обращение</v-btn>
        </div>
      </div>
    </div>

    <!-- New ticket dialog -->
    <v-dialog v-model="newDialog" max-width="500" persistent>
      <v-card>
        <v-card-title>Новое обращение</v-card-title>
        <v-card-text>
          <v-select v-model="form.department" :items="departments" item-title="title" item-value="value"
            label="Куда отправить" class="mb-3" />
          <v-text-field v-model="form.subject" label="Тема" class="mb-3" />
          <v-textarea v-model="form.message" label="Сообщение" rows="4" auto-grow />
          <v-alert v-if="formError" type="error" density="compact" class="mt-2">{{ formError }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="newDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="creating" @click="createTicket">Отправить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, nextTick, onMounted, onUnmounted, watch } from 'vue';
import { useDisplay } from 'vuetify';
import api from '../../api';

const { mobile } = useDisplay();

const tickets = ref([]);
const loading = ref(false);
const selectedTicket = ref(null);
const messages = ref([]);
const loadingMessages = ref(false);
const msgText = ref('');
const attachFile = ref(null);
const sending = ref(false);
const messagesRef = ref(null);
const fileInput = ref(null);
let pollInterval = null;

const newDialog = ref(false);
const creating = ref(false);
const formError = ref('');
const form = ref({ department: 'general', subject: '', message: '' });

const departments = [
  { title: 'Техподдержка', value: 'technical' },
  { title: 'Бэк-офис / Документы', value: 'billing' },
  { title: 'Отдел продаж', value: 'sales' },
  { title: 'Общие вопросы', value: 'general' },
];

function statusColor(s) { return { new: 'blue', open: 'info', pending: 'orange', resolved: 'success', closed: 'grey' }[s] || 'grey'; }
function statusLabel(s) { return { new: 'Новый', open: 'В работе', pending: 'Ожидание', resolved: 'Решён', closed: 'Закрыт' }[s] || s; }
function deptColor(d) { return { technical: 'blue', billing: 'green', sales: 'orange', general: 'primary' }[d] || 'grey'; }
function deptIcon(d) { return { technical: 'mdi-headset', billing: 'mdi-file-document', sales: 'mdi-handshake', general: 'mdi-help-circle' }[d] || 'mdi-chat'; }
function deptLabel(d) { return { technical: 'Техподдержка', billing: 'Бэк-офис', sales: 'Продажи', general: 'Общие' }[d] || d; }

function ago(d) {
  if (!d) return '';
  const diff = Math.floor((Date.now() - new Date(d).getTime()) / 1000);
  if (diff < 60) return 'сейчас';
  if (diff < 3600) return `${Math.floor(diff / 60)}м`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}ч`;
  return `${Math.floor(diff / 86400)}д`;
}

function fmtTime(d) {
  if (!d) return '';
  const date = new Date(d);
  if (isNaN(date.getTime())) return '';
  const now = new Date();
  if (date.toDateString() === now.toDateString()) return date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  return date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }) + ' ' + date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

function scrollBottom() {
  nextTick(() => { if (messagesRef.value) messagesRef.value.scrollTop = messagesRef.value.scrollHeight; });
}

async function loadTickets() {
  loading.value = true;
  try {
    const { data } = await api.get('/chat/tickets');
    tickets.value = data.data || [];
  } catch {}
  loading.value = false;
}

async function selectTicket(t) {
  selectedTicket.value = t;
  await loadMessages();
  startPoll();
}

async function loadMessages() {
  if (!selectedTicket.value) return;
  loadingMessages.value = true;
  try {
    const { data } = await api.get(`/chat/tickets/${selectedTicket.value.id}`);
    messages.value = data.messages || [];
    scrollBottom();
  } catch {}
  loadingMessages.value = false;
}

async function sendMessage() {
  if (!msgText.value?.trim() && !attachFile.value) return;
  sending.value = true;
  try {
    const fd = new FormData();
    fd.append('message', msgText.value || '');
    if (attachFile.value) fd.append('attachment', attachFile.value);
    await api.post(`/chat/tickets/${selectedTicket.value.id}/messages`, fd);
    msgText.value = '';
    attachFile.value = null;
    await loadMessages();
  } catch {}
  sending.value = false;
}

function onFile(e) { attachFile.value = e.target.files?.[0] || null; }

async function createTicket() {
  if (!form.value.subject?.trim() || !form.value.message?.trim()) {
    formError.value = 'Заполните тему и сообщение';
    return;
  }
  creating.value = true;
  formError.value = '';
  try {
    const { data } = await api.post('/chat/tickets', form.value);
    newDialog.value = false;
    form.value = { department: 'general', subject: '', message: '' };
    await loadTickets();
    if (data.ticket) selectTicket(data.ticket);
  } catch (e) {
    formError.value = e.response?.data?.message || 'Ошибка';
  }
  creating.value = false;
}

function startPoll() {
  stopPoll();
  pollInterval = setInterval(loadMessages, 10000);
}
function stopPoll() {
  if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
}

onMounted(loadTickets);
onUnmounted(stopPoll);
</script>

<style scoped>
.border-b { border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.border-t { border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.border-r { border-right: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.bg-surface-variant { background: rgba(var(--v-theme-surface-variant), 1); }
</style>
