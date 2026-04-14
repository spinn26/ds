<template>
  <div>
    <div class="d-flex justify-space-between align-center mb-4 flex-wrap ga-2">
      <div class="d-flex align-center ga-2">
        <v-icon size="32" color="secondary">mdi-ticket-confirmation</v-icon>
        <h5 class="text-h5 font-weight-bold">Тикеты</h5>
      </div>
    </div>

    <!-- Stats cards -->
    <v-row class="mb-4" dense>
      <v-col cols="6" md="3">
        <v-card class="pa-3 text-center" color="blue" variant="tonal">
          <div class="text-h4 font-weight-bold">{{ stats.new_today }}</div>
          <div class="text-caption">Новые сегодня</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card class="pa-3 text-center" color="orange" variant="tonal">
          <div class="text-h4 font-weight-bold">{{ stats.total_open }}</div>
          <div class="text-caption">Открытые</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card class="pa-3 text-center" color="green" variant="tonal">
          <div class="text-h4 font-weight-bold">{{ stats.in_progress }}</div>
          <div class="text-caption">В работе</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card class="pa-3 text-center" color="grey" variant="tonal">
          <div class="text-h4 font-weight-bold">{{ stats.closed_today }}</div>
          <div class="text-caption">Закрыто сегодня</div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Filters -->
    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-select
          v-model="filters.category"
          :items="categoryOptions"
          label="Категория"
          density="compact"
          variant="outlined"
          clearable
          hide-details
          style="max-width: 200px"
        />
        <v-select
          v-model="filters.status"
          :items="statusOptions"
          label="Статус"
          density="compact"
          variant="outlined"
          clearable
          hide-details
          style="max-width: 200px"
        />
        <v-checkbox
          v-model="filters.assigned_to_me"
          label="Только мои"
          density="compact"
          hide-details
          class="flex-grow-0"
        />
        <v-text-field
          v-model="filters.search"
          placeholder="Поиск..."
          density="compact"
          variant="outlined"
          prepend-inner-icon="mdi-magnify"
          clearable
          hide-details
          style="max-width: 250px"
          @update:model-value="debouncedLoad"
        />
        <v-spacer />
        <v-btn
          v-if="hasActiveFilters"
          size="small"
          variant="text"
          color="secondary"
          prepend-icon="mdi-filter-remove"
          @click="resetFilters"
        >
          Сбросить
        </v-btn>
      </div>
    </v-card>

    <!-- Main split: list + chat -->
    <v-row no-gutters style="min-height: 600px">
      <!-- Ticket list (left 40%) -->
      <v-col cols="12" md="5">
        <v-card class="overflow-y-auto" style="height: 600px" :loading="loading">
          <v-list lines="three" class="pa-0">
            <template v-if="tickets.length === 0 && !loading">
              <div class="text-center pa-8">
                <v-icon size="48" color="grey-lighten-1" class="mb-2">mdi-ticket-outline</v-icon>
                <div class="text-medium-emphasis">Тикеты не найдены</div>
              </div>
            </template>
            <v-list-item
              v-for="ticket in tickets"
              :key="ticket.id"
              :active="activeTicket?.id === ticket.id"
              active-color="primary"
              class="ticket-list-item border-b"
              @click="selectTicket(ticket)"
            >
              <template #prepend>
                <v-badge
                  v-if="ticket.unread_count > 0"
                  dot
                  color="error"
                  class="mr-1"
                >
                  <v-avatar size="36" :color="categoryColor(ticket.category)" variant="tonal">
                    <span class="text-caption font-weight-bold">{{ getInitials(ticket.creator_name) }}</span>
                  </v-avatar>
                </v-badge>
                <v-avatar v-else size="36" :color="categoryColor(ticket.category)" variant="tonal">
                  <span class="text-caption font-weight-bold">{{ getInitials(ticket.creator_name) }}</span>
                </v-avatar>
              </template>
              <v-list-item-title class="d-flex align-center ga-2 mb-1">
                <span class="font-weight-bold text-truncate">{{ ticket.subject }}</span>
                <v-badge v-if="ticket.unread_count > 0" :content="ticket.unread_count" color="error" inline />
              </v-list-item-title>
              <v-list-item-subtitle>
                <div class="d-flex align-center ga-1 mb-1">
                  <v-chip size="x-small" :color="categoryColor(ticket.category)" variant="flat">{{ categoryLabel(ticket.category) }}</v-chip>
                  <v-chip size="x-small" :color="statusColor(ticket.status)" variant="tonal">{{ statusLabel(ticket.status) }}</v-chip>
                </div>
                <div class="text-caption text-medium-emphasis">{{ ticket.creator_name }}</div>
                <div class="text-caption text-truncate" style="color: rgba(0,0,0,0.45); max-width: 300px">
                  {{ ticket.last_message_preview || 'Нет сообщений' }}
                </div>
              </v-list-item-subtitle>
              <template #append>
                <div class="text-caption text-medium-emphasis text-no-wrap">{{ timeAgo(ticket.updated_at) }}</div>
              </template>
            </v-list-item>
          </v-list>
          <div v-if="totalPages > 1" class="d-flex justify-center pa-2 border-t">
            <v-pagination v-model="page" :length="totalPages" density="compact" size="small" @update:model-value="loadTickets" />
          </div>
        </v-card>
      </v-col>

      <!-- Chat panel (right 60%) -->
      <v-col cols="12" md="7">
        <v-card class="d-flex flex-column" style="height: 600px">
          <!-- No ticket selected -->
          <div v-if="!activeTicket" class="d-flex flex-column align-center justify-center flex-grow-1">
            <v-icon size="64" color="grey-lighten-2" class="mb-3">mdi-chat-outline</v-icon>
            <div class="text-medium-emphasis text-body-1">Выберите тикет из списка</div>
          </div>

          <template v-else>
            <!-- Chat header -->
            <div class="pa-3 border-b">
              <div class="d-flex align-center ga-2 mb-2">
                <div class="flex-grow-1">
                  <div class="font-weight-bold text-body-1">{{ activeTicket.subject }}</div>
                  <div class="d-flex align-center ga-1 mt-1">
                    <v-chip size="x-small" :color="categoryColor(activeTicket.category)" variant="flat">{{ categoryLabel(activeTicket.category) }}</v-chip>
                    <v-chip size="x-small" :color="statusColor(activeTicket.status)" variant="tonal">{{ statusLabel(activeTicket.status) }}</v-chip>
                  </div>
                </div>
                <div class="d-flex ga-1">
                  <v-tooltip text="Назначить ответственного" location="bottom">
                    <template #activator="{ props }">
                      <v-btn v-bind="props" icon size="small" variant="text" @click="showAssignDialog = true">
                        <v-icon>mdi-account-arrow-right</v-icon>
                      </v-btn>
                    </template>
                  </v-tooltip>
                  <v-tooltip text="Добавить участника" location="bottom">
                    <template #activator="{ props }">
                      <v-btn v-bind="props" icon size="small" variant="text" @click="showParticipantDialog = true">
                        <v-icon>mdi-account-plus</v-icon>
                      </v-btn>
                    </template>
                  </v-tooltip>
                  <v-tooltip text="Закрыть тикет" location="bottom">
                    <template #activator="{ props }">
                      <v-btn
                        v-if="activeTicket.status !== 'closed'"
                        v-bind="props"
                        icon
                        size="small"
                        variant="text"
                        color="error"
                        @click="closeTicket"
                      >
                        <v-icon>mdi-check-circle</v-icon>
                      </v-btn>
                    </template>
                  </v-tooltip>
                </div>
              </div>
              <div class="text-caption text-medium-emphasis">
                Автор: {{ activeTicket.creator_name }}
                <span v-if="activeTicket.assigned_to_name"> | Ответственный: {{ activeTicket.assigned_to_name }}</span>
              </div>
              <!-- Context info -->
              <div v-if="activeTicket.context_type" class="mt-1">
                <v-chip size="x-small" color="secondary" variant="tonal" prepend-icon="mdi-link-variant">
                  {{ activeTicket.context_info?.label || activeTicket.context_type }}
                </v-chip>
              </div>
            </div>

            <!-- Messages area -->
            <div ref="messagesContainer" class="flex-grow-1 overflow-y-auto pa-3" style="min-height: 0">
              <div v-for="msg in chatMessages" :key="msg.id" class="mb-3">
                <!-- System message -->
                <div v-if="msg.is_system" class="text-center my-2">
                  <span class="text-caption text-medium-emphasis font-italic">{{ msg.text }}</span>
                </div>
                <!-- Regular message -->
                <div v-else :class="['d-flex', msg.is_mine ? 'justify-end' : 'justify-start']">
                  <div style="max-width: 75%">
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
                    <div class="text-caption mt-1" style="color: rgba(0,0,0,0.4)">{{ msg.created_at }}</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Reply input -->
            <div v-if="activeTicket.status !== 'closed'" class="pa-3 border-t">
              <div class="d-flex ga-2 align-end">
                <v-textarea
                  v-model="replyText"
                  placeholder="Введите сообщение..."
                  rows="2"
                  auto-grow
                  max-rows="5"
                  density="compact"
                  variant="outlined"
                  hide-details
                  class="flex-grow-1"
                  @keydown="onReplyKeydown"
                />
                <input ref="fileInput" type="file" hidden @change="onFileSelected" />
                <v-btn icon size="small" variant="text" @click="$refs.fileInput.click()">
                  <v-icon>mdi-paperclip</v-icon>
                </v-btn>
                <v-btn icon color="primary" :loading="sending" @click="sendReply">
                  <v-icon>mdi-send</v-icon>
                </v-btn>
              </div>
              <div v-if="attachedFile" class="text-caption text-medium-emphasis mt-1 d-flex align-center ga-1">
                <v-icon size="14">mdi-file</v-icon>
                {{ attachedFile.name }}
                <v-btn icon size="x-small" variant="text" @click="attachedFile = null">
                  <v-icon size="14">mdi-close</v-icon>
                </v-btn>
              </div>
            </div>
            <div v-else class="pa-3 border-t text-center">
              <v-chip size="small" color="grey" variant="tonal">Тикет закрыт</v-chip>
            </div>
          </template>
        </v-card>
      </v-col>
    </v-row>

    <!-- Assign dialog -->
    <v-dialog v-model="showAssignDialog" max-width="420">
      <v-card>
        <v-card-title>Назначить ответственного</v-card-title>
        <v-card-text>
          <v-autocomplete
            v-model="assignUserId"
            :items="staffList"
            item-title="name"
            item-value="id"
            label="Сотрудник"
            density="compact"
            variant="outlined"
            :loading="loadingStaff"
            @update:search="searchStaff"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="showAssignDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="assigning" @click="assignTicket">Назначить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Add participant dialog -->
    <v-dialog v-model="showParticipantDialog" max-width="420">
      <v-card>
        <v-card-title>Добавить участника</v-card-title>
        <v-card-text>
          <v-autocomplete
            v-model="participantUserId"
            :items="staffList"
            item-title="name"
            item-value="id"
            label="Сотрудник"
            density="compact"
            variant="outlined"
            :loading="loadingStaff"
            @update:search="searchStaff"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="showParticipantDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="addingParticipant" @click="addParticipant">Добавить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue';
import api from '../../api';

// --- State ---
const loading = ref(false);
const tickets = ref([]);
const page = ref(1);
const perPage = ref(20);
const totalPages = ref(1);
const stats = ref({ new_today: 0, total_open: 0, in_progress: 0, closed_today: 0 });

const filters = ref({
  category: null,
  status: null,
  assigned_to_me: false,
  search: '',
});

const hasActiveFilters = computed(() =>
  filters.value.category || filters.value.status || filters.value.assigned_to_me || filters.value.search
);

// Active ticket & chat
const activeTicket = ref(null);
const chatMessages = ref([]);
const replyText = ref('');
const attachedFile = ref(null);
const sending = ref(false);
const messagesContainer = ref(null);
const fileInput = ref(null);
let chatRefreshInterval = null;

// Assign / participant dialogs
const showAssignDialog = ref(false);
const showParticipantDialog = ref(false);
const staffList = ref([]);
const loadingStaff = ref(false);
const assignUserId = ref(null);
const participantUserId = ref(null);
const assigning = ref(false);
const addingParticipant = ref(false);

// --- Options ---
const categoryOptions = [
  { title: 'Техподдержка', value: 'support' },
  { title: 'Бэк-офис', value: 'backoffice' },
  { title: 'Юридический', value: 'legal' },
  { title: 'Бухгалтерия', value: 'accounting' },
  { title: 'Начисления', value: 'accruals' },
];

const statusOptions = [
  { title: 'Открыт', value: 'open' },
  { title: 'В работе', value: 'in_progress' },
  { title: 'Решён', value: 'resolved' },
  { title: 'Закрыт', value: 'closed' },
];

// --- Helpers ---
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

function getInitials(name) {
  if (!name) return '?';
  return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
}

function timeAgo(dateStr) {
  if (!dateStr) return '';
  const now = new Date();
  const d = new Date(dateStr);
  const diffMs = now - d;
  const diffMin = Math.floor(diffMs / 60000);
  if (diffMin < 1) return 'только что';
  if (diffMin < 60) return `${diffMin} мин назад`;
  const diffHours = Math.floor(diffMin / 60);
  if (diffHours < 24) return `${diffHours} ч назад`;
  const diffDays = Math.floor(diffHours / 24);
  if (diffDays === 1) return 'вчера';
  if (diffDays < 7) return `${diffDays} дн назад`;
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
}

function resetFilters() {
  filters.value = { category: null, status: null, assigned_to_me: false, search: '' };
}

// --- API calls ---
let debounceTimer;
function debouncedLoad() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    page.value = 1;
    loadTickets();
  }, 400);
}

async function loadTickets() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value };
    if (filters.value.category) params.category = filters.value.category;
    if (filters.value.status) params.status = filters.value.status;
    if (filters.value.assigned_to_me) params.assigned_to_me = 1;
    if (filters.value.search) params.search = filters.value.search;
    const { data } = await api.get('/tickets', { params });
    tickets.value = data.data || data;
    totalPages.value = data.last_page || 1;
  } catch {}
  loading.value = false;
}

async function loadStats() {
  try {
    const { data } = await api.get('/tickets/stats');
    stats.value = data;
  } catch {}
}

// --- Ticket selection & chat ---
async function selectTicket(ticket) {
  activeTicket.value = ticket;
  replyText.value = '';
  attachedFile.value = null;
  await loadMessages();
  startChatRefresh();
}

async function loadMessages() {
  if (!activeTicket.value?.id) return;
  try {
    const { data } = await api.get(`/tickets/${activeTicket.value.id}/messages`);
    chatMessages.value = data.data || data;
    nextTick(() => {
      const el = messagesContainer.value;
      if (el) el.scrollTop = el.scrollHeight;
    });
  } catch {}
}

function onReplyKeydown(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendReply();
  }
}

async function sendReply() {
  if (!replyText.value?.trim() && !attachedFile.value) return;
  sending.value = true;
  try {
    const fd = new FormData();
    fd.append('message', replyText.value || '');
    if (attachedFile.value) fd.append('attachment', attachedFile.value);
    await api.post(`/tickets/${activeTicket.value.id}/messages`, fd);
    replyText.value = '';
    attachedFile.value = null;
    await loadMessages();
  } catch {}
  sending.value = false;
}

function onFileSelected(e) {
  attachedFile.value = e.target.files?.[0] || null;
}

// --- Actions ---
async function closeTicket() {
  try {
    await api.post(`/tickets/${activeTicket.value.id}/close`);
    activeTicket.value.status = 'closed';
    loadTickets();
    loadStats();
    await loadMessages();
  } catch {}
}

let searchTimeout = null;
async function searchStaff(query) {
  if (searchTimeout) clearTimeout(searchTimeout);
  searchTimeout = setTimeout(async () => {
    if (!query || query.length < 2) return;
    loadingStaff.value = true;
    try {
      const { data } = await api.get('/tickets/staff', { params: { search: query } });
      staffList.value = data.data || data;
    } catch {}
    loadingStaff.value = false;
  }, 300);
}

async function assignTicket() {
  if (!assignUserId.value) return;
  assigning.value = true;
  try {
    await api.post(`/tickets/${activeTicket.value.id}/assign`, { user_id: assignUserId.value });
    showAssignDialog.value = false;
    await loadMessages();
    loadTickets();
  } catch {}
  assigning.value = false;
}

async function addParticipant() {
  if (!participantUserId.value) return;
  addingParticipant.value = true;
  try {
    await api.post(`/tickets/${activeTicket.value.id}/participants`, { user_id: participantUserId.value });
    showParticipantDialog.value = false;
    await loadMessages();
  } catch {}
  addingParticipant.value = false;
}

// --- Refresh ---
function startChatRefresh() {
  stopChatRefresh();
  chatRefreshInterval = setInterval(loadMessages, 5000);
}

function stopChatRefresh() {
  if (chatRefreshInterval) {
    clearInterval(chatRefreshInterval);
    chatRefreshInterval = null;
  }
}

// --- Watchers ---
watch(() => [filters.value.category, filters.value.status, filters.value.assigned_to_me], () => {
  page.value = 1;
  loadTickets();
});

// --- Lifecycle ---
onMounted(() => {
  loadTickets();
  loadStats();
});

onUnmounted(() => {
  stopChatRefresh();
  if (searchTimeout) clearTimeout(searchTimeout);
  if (debounceTimer) clearTimeout(debounceTimer);
});
</script>

<style scoped>
.border-b {
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.border-t {
  border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.ticket-list-item {
  cursor: pointer;
}
</style>
