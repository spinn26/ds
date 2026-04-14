<template>
  <!-- FAB -->
  <v-btn
    v-if="!chatOpen"
    icon
    color="primary"
    size="56"
    class="chat-fab"
    elevation="8"
    @click="openWidget"
  >
    <v-badge v-if="totalUnread > 0" :content="totalUnread" color="error" floating>
      <v-icon size="28">mdi-chat</v-icon>
    </v-badge>
    <v-icon v-else size="28">mdi-chat</v-icon>
  </v-btn>

  <!-- Chat Dialog -->
  <v-dialog
    v-model="chatOpen"
    max-width="520"
    :fullscreen="mobile"
    scrollable
    persistent
  >
    <v-card height="600" class="d-flex flex-column">
      <!-- Header -->
      <v-card-title class="d-flex align-center ga-2 bg-primary pa-3">
        <v-btn
          v-if="currentView !== 'create'"
          icon
          size="small"
          variant="text"
          color="white"
          @click="goBack"
        >
          <v-icon>mdi-arrow-left</v-icon>
        </v-btn>
        <v-icon color="white">mdi-headset</v-icon>
        <span class="text-white font-weight-medium">
          {{ currentView === 'create' ? 'Новое обращение' : currentView === 'chat' ? activeTicket?.subject : 'Поддержка' }}
        </span>
        <v-spacer />
        <v-btn icon size="small" variant="text" color="white" @click="chatOpen = false">
          <v-icon>mdi-close</v-icon>
        </v-btn>
      </v-card-title>

      <!-- Create ticket form -->
      <template v-if="currentView === 'create'">
        <v-card-text class="flex-grow-1 overflow-y-auto">
          <v-select
            v-model="form.category"
            :items="categories"
            label="Категория"
            density="compact"
            variant="outlined"
            class="mb-3"
          />
          <v-text-field
            v-model="form.subject"
            label="Тема обращения"
            density="compact"
            variant="outlined"
            class="mb-3"
          />
          <v-textarea
            v-model="form.message"
            label="Сообщение"
            rows="5"
            auto-grow
            variant="outlined"
          />
          <v-alert v-if="formError" type="error" density="compact" class="mt-2">{{ formError }}</v-alert>
        </v-card-text>
        <v-card-actions class="pa-3 pt-0">
          <v-spacer />
          <v-btn variant="text" @click="chatOpen = false">Отмена</v-btn>
          <v-btn
            color="primary"
            :loading="sending"
            prepend-icon="mdi-send"
            @click="createTicket"
          >
            Отправить
          </v-btn>
        </v-card-actions>
      </template>

      <!-- Chat view -->
      <template v-if="currentView === 'chat' && activeTicket">
        <div class="d-flex align-center ga-2 px-3 py-2 border-b">
          <v-chip size="small" :color="categoryColor(activeTicket.category)">{{ categoryLabel(activeTicket.category) }}</v-chip>
          <v-chip size="small" :color="statusColor(activeTicket.status)" variant="tonal">{{ statusLabel(activeTicket.status) }}</v-chip>
          <v-spacer />
          <span class="text-caption text-medium-emphasis">{{ activeTicket.created_at }}</span>
        </div>
        <div ref="messagesContainer" class="flex-grow-1 overflow-y-auto pa-3" style="min-height: 0">
          <div v-for="msg in chatMessages" :key="msg.id" class="mb-3">
            <!-- System message -->
            <div v-if="msg.is_system" class="text-center">
              <span class="text-caption text-medium-emphasis font-italic">{{ msg.text }}</span>
            </div>
            <!-- Regular message -->
            <div v-else :class="['d-flex', msg.is_mine ? 'justify-end' : 'justify-start']">
              <div
                :class="[
                  'pa-2 rounded-lg',
                  msg.is_mine ? 'bg-primary text-white' : 'bg-grey-lighten-3',
                ]"
                style="max-width: 80%; word-break: break-word"
              >
                <div v-if="!msg.is_mine" class="text-caption font-weight-medium mb-1">{{ msg.sender_name }}</div>
                <div class="text-body-2" style="white-space: pre-line">{{ msg.text }}</div>
                <div v-if="msg.attachment_url" class="mt-1">
                  <a :href="msg.attachment_url" target="_blank" :class="msg.is_mine ? 'text-white' : 'text-primary'" class="text-caption">
                    <v-icon size="14">mdi-paperclip</v-icon> {{ msg.attachment_name || 'Вложение' }}
                  </a>
                </div>
                <div :class="['text-right mt-1', msg.is_mine ? 'text-white-darken' : 'text-medium-emphasis']" style="font-size: 0.7rem">
                  {{ msg.created_at }}
                </div>
              </div>
            </div>
          </div>
          <div v-if="!chatMessages.length" class="text-center pa-6">
            <v-icon size="48" color="grey-lighten-1">mdi-message-outline</v-icon>
            <div class="text-medium-emphasis mt-2">Нет сообщений</div>
          </div>
        </div>
        <!-- Input -->
        <div class="pa-3 border-t">
          <div class="d-flex ga-2 align-end">
            <v-textarea
              v-model="replyText"
              label="Сообщение..."
              rows="2"
              auto-grow
              density="compact"
              variant="outlined"
              hide-details
              class="flex-grow-1"
              @keydown.ctrl.enter="sendReply"
            />
            <input ref="fileInput" type="file" hidden @change="onFileSelected" />
            <v-btn icon size="small" variant="text" @click="$refs.fileInput.click()">
              <v-icon>mdi-paperclip</v-icon>
            </v-btn>
            <v-btn icon size="small" color="primary" :loading="sendingReply" @click="sendReply">
              <v-icon>mdi-send</v-icon>
            </v-btn>
          </div>
          <div v-if="selectedFile" class="text-caption text-medium-emphasis mt-1 d-flex align-center ga-1">
            <v-icon size="14">mdi-file</v-icon>
            {{ selectedFile.name }}
            <v-btn icon size="x-small" variant="text" @click="selectedFile = null"><v-icon size="14">mdi-close</v-icon></v-btn>
          </div>
        </div>
      </template>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { ref, nextTick, onMounted, onUnmounted, watch } from 'vue';
import { useDisplay } from 'vuetify';
import { useRoute } from 'vue-router';
import api from '../api';

const props = defineProps({
  contextType: { type: String, default: '' },
  contextId: { type: [String, Number], default: null },
  contextInfo: { type: Object, default: () => ({}) },
});

const { mobile } = useDisplay();
const route = useRoute();

const chatOpen = ref(false);
const currentView = ref('create'); // 'create' | 'chat'
const totalUnread = ref(0);
const sending = ref(false);
const sendingReply = ref(false);
const formError = ref('');
const activeTicket = ref(null);
const chatMessages = ref([]);
const replyText = ref('');
const selectedFile = ref(null);
const messagesContainer = ref(null);
const fileInput = ref(null);
let refreshInterval = null;

const categories = [
  { title: 'Техподдержка', value: 'support' },
  { title: 'Бэк-офис', value: 'backoffice' },
  { title: 'Юридический', value: 'legal' },
  { title: 'Бухгалтерия', value: 'accounting' },
  { title: 'Начисления', value: 'accruals' },
];

const form = ref({
  category: 'support',
  subject: '',
  message: '',
});

function categoryColor(cat) {
  const map = { support: 'blue', backoffice: 'orange', legal: 'purple', accounting: 'green', accruals: 'red' };
  return map[cat] || 'grey';
}

function categoryLabel(cat) {
  const map = { support: 'Техподдержка', backoffice: 'Бэк-офис', legal: 'Юридический', accounting: 'Бухгалтерия', accruals: 'Начисления' };
  return map[cat] || cat;
}

function statusColor(status) {
  const map = { open: 'info', in_progress: 'warning', resolved: 'success', closed: 'grey' };
  return map[status] || 'grey';
}

function statusLabel(status) {
  const map = { open: 'Открыт', in_progress: 'В работе', resolved: 'Решён', closed: 'Закрыт' };
  return map[status] || status;
}

function openWidget() {
  currentView.value = 'create';
  form.value = { category: 'support', subject: '', message: '' };
  formError.value = '';
  chatOpen.value = true;
}

function goBack() {
  chatOpen.value = false;
  stopRefresh();
}

async function createTicket() {
  if (!form.value.subject?.trim() || !form.value.message?.trim()) {
    formError.value = 'Заполните тему и сообщение';
    return;
  }
  sending.value = true;
  formError.value = '';
  try {
    const payload = {
      category: form.value.category,
      subject: form.value.subject,
      message: form.value.message,
      context_type: props.contextType || route.name || route.path,
      context_id: props.contextId,
      context_info: props.contextInfo,
    };
    const { data } = await api.post('/tickets', payload);
    activeTicket.value = data.ticket || data;
    chatMessages.value = data.messages || [];
    currentView.value = 'chat';
    startRefresh();
    scrollToBottom();
  } catch (e) {
    formError.value = e.response?.data?.message || 'Ошибка создания обращения';
  }
  sending.value = false;
}

async function loadMessages() {
  if (!activeTicket.value?.id) return;
  try {
    const { data } = await api.get(`/tickets/${activeTicket.value.id}/messages`);
    chatMessages.value = data.data || data;
    scrollToBottom();
  } catch {}
}

async function sendReply() {
  if (!replyText.value?.trim() && !selectedFile.value) return;
  sendingReply.value = true;
  try {
    const fd = new FormData();
    fd.append('text', replyText.value || '');
    if (selectedFile.value) fd.append('attachment', selectedFile.value);
    await api.post(`/tickets/${activeTicket.value.id}/messages`, fd);
    replyText.value = '';
    selectedFile.value = null;
    await loadMessages();
  } catch {}
  sendingReply.value = false;
}

function onFileSelected(e) {
  selectedFile.value = e.target.files?.[0] || null;
}

function scrollToBottom() {
  nextTick(() => {
    const el = messagesContainer.value;
    if (el) el.scrollTop = el.scrollHeight;
  });
}

function startRefresh() {
  stopRefresh();
  refreshInterval = setInterval(loadMessages, 5000);
}

function stopRefresh() {
  if (refreshInterval) {
    clearInterval(refreshInterval);
    refreshInterval = null;
  }
}

async function loadUnread() {
  try {
    const { data } = await api.get('/tickets/unread-count');
    totalUnread.value = data.count || 0;
  } catch {}
}

let unreadInterval = null;

onMounted(() => {
  loadUnread();
  unreadInterval = setInterval(loadUnread, 30000);
});

onUnmounted(() => {
  stopRefresh();
  if (unreadInterval) clearInterval(unreadInterval);
});

watch(chatOpen, (val) => {
  if (!val) stopRefresh();
});
</script>

<style scoped>
.chat-fab {
  position: fixed;
  bottom: 24px;
  right: 24px;
  z-index: 1000;
}
.border-b {
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.border-t {
  border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.text-white-darken {
  color: rgba(255, 255, 255, 0.7);
}
</style>
