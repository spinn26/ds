<template>
  <div>
    <PageHeader title="Обратная связь" icon="mdi-ticket-outline">
      <template #actions>
        <v-badge v-if="totalUnread > 0" :content="totalUnread" color="error" inline class="mr-2" />
        <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreateDialog">Новое обращение</v-btn>
      </template>
    </PageHeader>

    <!-- Mobile: toggle between list and chat -->
    <div v-if="mobile && selectedTicket" class="mb-2">
      <v-btn variant="text" prepend-icon="mdi-arrow-left" @click="selectedTicket = null">К списку</v-btn>
    </div>

    <v-row>
      <!-- Left panel: ticket list -->
      <v-col :cols="mobile ? 12 : 5" :lg="4" v-show="!mobile || !selectedTicket">
        <!-- Status filter -->
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
              <v-list-item
                :active="selectedTicket?.id === ticket.id"
                @click="selectTicket(ticket)"
                class="py-3"
              >
                <template #prepend>
                  <v-badge
                    v-if="ticket.unread_count > 0"
                    :content="ticket.unread_count"
                    color="error"
                    floating
                    offset-x="-4"
                    offset-y="-4"
                  >
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

      <!-- Right panel: chat -->
      <v-col :cols="mobile ? 12 : 7" :lg="8" v-show="!mobile || selectedTicket">
        <v-card v-if="selectedTicket" class="d-flex flex-column" style="height: calc(100vh - 200px); min-height: 300px; overflow-y: auto">
          <!-- Ticket header -->
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
              <!-- System message -->
              <div v-if="msg.is_system" class="text-center my-2">
                <v-chip size="small" variant="text" class="text-medium-emphasis font-italic">{{ msg.text }}</v-chip>
              </div>
              <!-- Regular message -->
              <div v-else :class="['d-flex', msg.is_mine ? 'justify-end' : 'justify-start']">
                <div class="d-flex ga-2" :class="{ 'flex-row-reverse': msg.is_mine }" style="max-width: 75%">
                  <v-avatar size="32" :color="msg.is_mine ? 'primary' : 'secondary'" class="flex-shrink-0 mt-1">
                    <span class="text-caption text-white font-weight-bold">{{ getInitials(msg.sender_name) }}</span>
                  </v-avatar>
                  <div>
                    <div v-if="!msg.is_mine" class="text-caption font-weight-medium mb-1">{{ msg.sender_name }}</div>
                    <div
                      :class="[
                        'pa-3 rounded-lg',
                        msg.is_mine ? 'bg-primary text-white' : 'bg-grey-lighten-4',
                      ]"
                      style="word-break: break-word"
                    >
                      <div class="text-body-2" style="white-space: pre-line">{{ msg.text }}</div>
                      <div v-if="msg.attachment_url" class="mt-1">
                        <a
                          :href="msg.attachment_url"
                          target="_blank"
                          :class="msg.is_mine ? 'text-white' : 'text-primary'"
                          class="text-caption d-flex align-center ga-1"
                        >
                          <v-icon size="14">mdi-paperclip</v-icon> {{ msg.attachment_name || 'Вложение' }}
                        </a>
                      </div>
                    </div>
                    <div :class="['text-caption mt-1', msg.is_mine ? 'text-right' : '']" style="color: rgba(0,0,0,0.4)">
                      {{ msg.created_at }}
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <EmptyState v-if="!messages.length && !loadingMessages" icon="mdi-message-outline" message="Нет сообщений" />
          </div>

          <!-- Input area -->
          <div v-if="selectedTicket.status !== 'closed'" class="pa-3 border-t">
            <div class="d-flex ga-2 align-end">
              <v-textarea
                v-model="replyText"
                placeholder="Введите сообщение... (Enter — отправить, Shift+Enter — новая строка)"
                rows="1"
                auto-grow
                max-rows="5"
                hide-details
                class="flex-grow-1"
                @keydown.enter.exact.prevent="sendMessage"
              />
              <input ref="fileInput" type="file" hidden @change="onFileSelected" />
              <v-btn icon size="small" variant="text" @click="$refs.fileInput.click()">
                <v-icon>mdi-paperclip</v-icon>
              </v-btn>
              <v-btn icon color="primary" :loading="sendingMessage" @click="sendMessage">
                <v-icon>mdi-send</v-icon>
              </v-btn>
            </div>
            <div v-if="selectedFile" class="text-caption text-medium-emphasis mt-1 d-flex align-center ga-1">
              <v-icon size="14">mdi-file</v-icon>
              {{ selectedFile.name }}
              <v-btn icon size="x-small" variant="text" @click="selectedFile = null">
                <v-icon size="14">mdi-close</v-icon>
              </v-btn>
            </div>
          </div>
          <div v-else class="pa-3 border-t text-center">
            <v-chip size="small" color="grey" variant="tonal">
              <v-icon start size="14">mdi-lock</v-icon>
              Обращение закрыто
            </v-chip>
          </div>
        </v-card>

        <!-- No ticket selected -->
        <v-card v-else class="d-flex align-center justify-center" style="height: calc(100vh - 200px); min-height: 300px; overflow-y: auto">
          <div class="text-center">
            <v-icon size="80" color="grey-lighten-1">mdi-chat-outline</v-icon>
            <div class="text-h6 text-medium-emphasis mt-3">Выберите обращение</div>
            <div class="text-body-2 text-medium-emphasis">или создайте новое</div>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Create ticket dialog -->
    <v-dialog v-model="createDialog" max-width="560" persistent>
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="primary">mdi-plus-circle</v-icon>
          Новое обращение
        </v-card-title>
        <v-card-text>
          <v-select
            v-model="createForm.category"
            :items="categoryOptions"
            label="Категория"
            class="mb-3"
          />
          <v-text-field
            v-model="createForm.subject"
            label="Тема обращения"
            class="mb-3"
          />
          <v-textarea
            v-model="createForm.message"
            label="Сообщение"
            rows="5"
            auto-grow
          />
          <v-alert v-if="createError" type="error" density="compact" class="mt-2">{{ createError }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="createDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="creating" prepend-icon="mdi-send" @click="createTicket">
            Отправить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, nextTick, onMounted, onUnmounted, watch } from 'vue';
import { useDisplay } from 'vuetify';
import api from '../api';
import PageHeader from '../components/PageHeader.vue';
import EmptyState from '../components/EmptyState.vue';
import { getInitials, statusColors, statusLabels, categoryLabels, getCategoryColor, getStatusColor } from '../composables/useDesign';

const { mobile } = useDisplay();

// Ticket list
const tickets = ref([]);
const loadingTickets = ref(false);
const ticketPage = ref(1);
const ticketPages = ref(1);
const statusFilter = ref('all');
const totalUnread = ref(0);

// Selected ticket & messages
const selectedTicket = ref(null);
const messages = ref([]);
const loadingMessages = ref(false);
const replyText = ref('');
const selectedFile = ref(null);
const sendingMessage = ref(false);
const messagesContainer = ref(null);
const fileInput = ref(null);
let refreshInterval = null;

// Create dialog
const createDialog = ref(false);
const creating = ref(false);
const createError = ref('');
const createForm = ref({ category: 'support', subject: '', message: '' });

const categoryOptions = [
  { title: 'Техподдержка', value: 'support' },
  { title: 'Бэк-офис', value: 'backoffice' },
  { title: 'Юридический', value: 'legal' },
  { title: 'Бухгалтерия', value: 'accounting' },
  { title: 'Начисления', value: 'accruals' },
];

function categoryColor(cat) { return getCategoryColor(cat); }
function categoryLabel(cat) { return categoryLabels[cat] || cat; }

function categoryIcon(cat) {
  const map = { support: 'mdi-headset', backoffice: 'mdi-briefcase', legal: 'mdi-scale-balance', accounting: 'mdi-calculator', accruals: 'mdi-cash-multiple' };
  return map[cat] || 'mdi-help-circle';
}

function statusColor(status) { return getStatusColor(status); }
function statusLabel(status) { return statusLabels[status] || status; }

function formatDate(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  const now = new Date();
  const isToday = d.toDateString() === now.toDateString();
  if (isToday) return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
}

// Load tickets
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

// Select ticket
async function selectTicket(ticket) {
  selectedTicket.value = ticket;
  await loadTicketMessages();
  startRefresh();
}

async function loadTicketMessages() {
  if (!selectedTicket.value?.id) return;
  loadingMessages.value = true;
  try {
    const { data } = await api.get(`/tickets/${selectedTicket.value.id}/messages`);
    messages.value = data.data || data;
    scrollToBottom();
    // Mark as read
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

// Send message
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

function onFileSelected(e) {
  selectedFile.value = e.target.files?.[0] || null;
}

// Create ticket
function openCreateDialog() {
  createForm.value = { category: 'support', subject: '', message: '' };
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
    const { data } = await api.post('/tickets', createForm.value);
    createDialog.value = false;
    await loadTickets();
    const ticket = data.ticket || data;
    selectTicket(ticket);
  } catch (e) {
    createError.value = e.response?.data?.message || 'Ошибка создания обращения';
  }
  creating.value = false;
}

// Auto-refresh
function startRefresh() {
  stopRefresh();
  refreshInterval = setInterval(loadTicketMessages, 5000);
}

function stopRefresh() {
  if (refreshInterval) {
    clearInterval(refreshInterval);
    refreshInterval = null;
  }
}

// Watch status filter
watch(statusFilter, () => {
  ticketPage.value = 1;
  loadTickets();
});

onMounted(() => {
  loadTickets();
  loadUnread();
});

onUnmounted(() => {
  stopRefresh();
});
</script>

<style scoped>
.border-b {
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.border-t {
  border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
</style>
