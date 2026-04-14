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
        <v-card class="pa-3 text-center" color="info" variant="tonal">
          <div class="text-h5 font-weight-bold">{{ stats.new_today }}</div>
          <div class="text-caption">Новые сегодня</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card class="pa-3 text-center" color="warning" variant="tonal">
          <div class="text-h5 font-weight-bold">{{ stats.total_open }}</div>
          <div class="text-caption">Открытые</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card class="pa-3 text-center" color="primary" variant="tonal">
          <div class="text-h5 font-weight-bold">{{ stats.in_progress }}</div>
          <div class="text-caption">В работе</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card class="pa-3 text-center" color="success" variant="tonal">
          <div class="text-h5 font-weight-bold">{{ stats.closed_today }}</div>
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
        <v-spacer />
        <v-btn
          v-if="filters.category || filters.status || filters.assigned_to_me"
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

    <!-- Tickets table -->
    <v-card :loading="loading">
      <v-data-table
        :headers="tableHeaders"
        :items="tickets"
        :items-per-page="perPage"
        :page="page"
        hover
        class="cursor-pointer"
        @click:row="(_, { item }) => openTicketDrawer(item)"
      >
        <template #item.subject="{ item }">
          <div class="d-flex align-center ga-2">
            <span class="font-weight-medium">{{ item.subject }}</span>
            <v-badge v-if="item.unread_count > 0" :content="item.unread_count" color="error" inline />
          </div>
        </template>
        <template #item.category="{ item }">
          <v-chip size="small" :color="categoryColor(item.category)">{{ categoryLabel(item.category) }}</v-chip>
        </template>
        <template #item.status="{ item }">
          <v-chip size="small" :color="statusColor(item.status)" variant="tonal">{{ statusLabel(item.status) }}</v-chip>
        </template>
        <template #item.priority="{ item }">
          <v-chip size="small" :color="priorityColor(item.priority)" variant="flat">{{ priorityLabel(item.priority) }}</v-chip>
        </template>
        <template #item.creator_name="{ item }">
          <span class="text-body-2">{{ item.creator_name }}</span>
        </template>
        <template #item.assigned_to_name="{ item }">
          <span class="text-body-2">{{ item.assigned_to_name || '---' }}</span>
        </template>
        <template #item.last_message_preview="{ item }">
          <span class="text-caption text-medium-emphasis text-truncate d-block" style="max-width: 200px">
            {{ item.last_message_preview }}
          </span>
        </template>
        <template #item.updated_at="{ item }">
          <span class="text-caption">{{ formatDate(item.updated_at) }}</span>
        </template>
        <template #bottom>
          <div v-if="totalPages > 1" class="d-flex justify-center pa-3">
            <v-pagination v-model="page" :length="totalPages" density="compact" @update:model-value="loadTickets" />
          </div>
        </template>
      </v-data-table>
    </v-card>

    <!-- Right drawer: ticket chat -->
    <v-navigation-drawer
      v-model="drawerOpen"
      location="right"
      temporary
      width="520"
    >
      <template v-if="activeTicket">
        <!-- Drawer header -->
        <v-toolbar color="secondary" density="compact">
          <v-btn icon @click="drawerOpen = false"><v-icon>mdi-close</v-icon></v-btn>
          <v-toolbar-title class="text-body-1 font-weight-medium">{{ activeTicket.subject }}</v-toolbar-title>
        </v-toolbar>

        <div class="d-flex flex-column" style="height: calc(100vh - 64px)">
          <!-- Ticket info -->
          <div class="pa-3 border-b">
            <div class="d-flex ga-2 flex-wrap align-center mb-2">
              <v-chip size="small" :color="categoryColor(activeTicket.category)">{{ categoryLabel(activeTicket.category) }}</v-chip>
              <v-chip size="small" :color="statusColor(activeTicket.status)" variant="tonal">{{ statusLabel(activeTicket.status) }}</v-chip>
              <v-chip v-if="activeTicket.priority" size="small" :color="priorityColor(activeTicket.priority)" variant="flat">{{ priorityLabel(activeTicket.priority) }}</v-chip>
            </div>
            <div class="text-caption text-medium-emphasis">
              Автор: {{ activeTicket.creator_name }} | Создан: {{ activeTicket.created_at }}
            </div>
            <div v-if="activeTicket.assigned_to_name" class="text-caption text-medium-emphasis">
              Ответственный: {{ activeTicket.assigned_to_name }}
            </div>

            <!-- Staff actions -->
            <div class="d-flex ga-2 mt-2 flex-wrap">
              <v-btn size="small" variant="outlined" prepend-icon="mdi-account-switch" @click="showAssignDialog = true">
                Назначить
              </v-btn>
              <v-btn size="small" variant="outlined" prepend-icon="mdi-account-plus" @click="showParticipantDialog = true">
                Участник
              </v-btn>
              <v-btn
                v-if="activeTicket.status !== 'closed'"
                size="small"
                variant="outlined"
                color="error"
                prepend-icon="mdi-check-circle"
                @click="closeTicket"
              >
                Закрыть
              </v-btn>
            </div>
          </div>

          <!-- Messages -->
          <div ref="drawerMessages" class="flex-grow-1 overflow-y-auto pa-3" style="min-height: 0">
            <div v-for="msg in drawerChatMessages" :key="msg.id" class="mb-3">
              <div v-if="msg.is_system" class="text-center my-2">
                <v-chip size="small" variant="text" class="text-medium-emphasis font-italic">{{ msg.text }}</v-chip>
              </div>
              <div v-else :class="['d-flex', msg.is_mine ? 'justify-end' : 'justify-start']">
                <div class="d-flex ga-2" :class="{ 'flex-row-reverse': msg.is_mine }" style="max-width: 80%">
                  <v-avatar size="32" :color="msg.is_mine ? 'secondary' : 'primary'" class="flex-shrink-0 mt-1">
                    <span class="text-caption text-white font-weight-bold">{{ getInitials(msg.sender_name) }}</span>
                  </v-avatar>
                  <div>
                    <div v-if="!msg.is_mine" class="text-caption font-weight-medium mb-1">{{ msg.sender_name }}</div>
                    <div
                      :class="[
                        'pa-3 rounded-lg',
                        msg.is_mine ? 'bg-secondary text-white' : 'bg-grey-lighten-4',
                      ]"
                      style="word-break: break-word"
                    >
                      <div class="text-body-2" style="white-space: pre-line">{{ msg.text }}</div>
                      <div v-if="msg.attachment_url" class="mt-1">
                        <a :href="msg.attachment_url" target="_blank" :class="msg.is_mine ? 'text-white' : 'text-primary'" class="text-caption d-flex align-center ga-1">
                          <v-icon size="14">mdi-paperclip</v-icon> {{ msg.attachment_name || 'Вложение' }}
                        </a>
                      </div>
                    </div>
                    <div class="text-caption mt-1" style="color: rgba(0,0,0,0.4)">{{ msg.created_at }}</div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Reply input -->
          <div v-if="activeTicket.status !== 'closed'" class="pa-3 border-t">
            <div class="d-flex ga-2 align-end">
              <v-textarea
                v-model="drawerReplyText"
                placeholder="Ответ..."
                rows="2"
                auto-grow
                max-rows="5"
                density="compact"
                variant="outlined"
                hide-details
                class="flex-grow-1"
                @keydown.ctrl.enter="sendDrawerReply"
              />
              <input ref="drawerFileInput" type="file" hidden @change="onDrawerFileSelected" />
              <v-btn icon size="small" variant="text" @click="$refs.drawerFileInput.click()">
                <v-icon>mdi-paperclip</v-icon>
              </v-btn>
              <v-btn icon color="secondary" :loading="drawerSending" @click="sendDrawerReply">
                <v-icon>mdi-send</v-icon>
              </v-btn>
            </div>
            <div v-if="drawerFile" class="text-caption text-medium-emphasis mt-1 d-flex align-center ga-1">
              <v-icon size="14">mdi-file</v-icon>
              {{ drawerFile.name }}
              <v-btn icon size="x-small" variant="text" @click="drawerFile = null">
                <v-icon size="14">mdi-close</v-icon>
              </v-btn>
            </div>
          </div>
        </div>
      </template>
    </v-navigation-drawer>

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
          <v-btn color="secondary" :loading="assigning" @click="assignTicket">Назначить</v-btn>
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
          <v-btn color="secondary" :loading="addingParticipant" @click="addParticipant">Добавить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, nextTick, onMounted, onUnmounted, watch } from 'vue';
import api from '../../api';

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
});

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

const tableHeaders = [
  { title: 'Тема', key: 'subject', sortable: false },
  { title: 'Категория', key: 'category', sortable: false, width: '120px' },
  { title: 'Автор', key: 'creator_name', sortable: false, width: '140px' },
  { title: 'Статус', key: 'status', sortable: false, width: '110px' },
  { title: 'Приоритет', key: 'priority', sortable: false, width: '100px' },
  { title: 'Ответственный', key: 'assigned_to_name', sortable: false, width: '140px' },
  { title: 'Последнее', key: 'last_message_preview', sortable: false, width: '200px' },
  { title: 'Дата', key: 'updated_at', sortable: false, width: '100px' },
];

// Drawer state
const drawerOpen = ref(false);
const activeTicket = ref(null);
const drawerChatMessages = ref([]);
const drawerReplyText = ref('');
const drawerFile = ref(null);
const drawerSending = ref(false);
const drawerMessages = ref(null);
const drawerFileInput = ref(null);
let drawerRefreshInterval = null;

// Assign / participant dialogs
const showAssignDialog = ref(false);
const showParticipantDialog = ref(false);
const staffList = ref([]);
const loadingStaff = ref(false);
const assignUserId = ref(null);
const participantUserId = ref(null);
const assigning = ref(false);
const addingParticipant = ref(false);

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

function priorityColor(p) {
  const map = { low: 'success', normal: 'info', high: 'warning', urgent: 'error' };
  return map[p] || 'grey';
}

function priorityLabel(p) {
  const map = { low: 'Низкий', normal: 'Обычный', high: 'Высокий', urgent: 'Срочный' };
  return map[p] || p || '---';
}

function formatDate(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

function getInitials(name) {
  if (!name) return '?';
  return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
}

function resetFilters() {
  filters.value = { category: null, status: null, assigned_to_me: false };
}

// Load tickets
async function loadTickets() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value };
    if (filters.value.category) params.category = filters.value.category;
    if (filters.value.status) params.status = filters.value.status;
    if (filters.value.assigned_to_me) params.assigned_to_me = 1;
    const { data } = await api.get('/staff/tickets', { params });
    tickets.value = data.data || data;
    totalPages.value = data.last_page || 1;
  } catch {}
  loading.value = false;
}

async function loadStats() {
  try {
    const { data } = await api.get('/staff/tickets/stats');
    stats.value = data;
  } catch {}
}

// Drawer
async function openTicketDrawer(ticket) {
  activeTicket.value = ticket;
  drawerOpen.value = true;
  drawerReplyText.value = '';
  drawerFile.value = null;
  await loadDrawerMessages();
  startDrawerRefresh();
}

async function loadDrawerMessages() {
  if (!activeTicket.value?.id) return;
  try {
    const { data } = await api.get(`/staff/tickets/${activeTicket.value.id}/messages`);
    drawerChatMessages.value = data.data || data;
    nextTick(() => {
      const el = drawerMessages.value;
      if (el) el.scrollTop = el.scrollHeight;
    });
  } catch {}
}

async function sendDrawerReply() {
  if (!drawerReplyText.value?.trim() && !drawerFile.value) return;
  drawerSending.value = true;
  try {
    const fd = new FormData();
    fd.append('text', drawerReplyText.value || '');
    if (drawerFile.value) fd.append('attachment', drawerFile.value);
    await api.post(`/staff/tickets/${activeTicket.value.id}/messages`, fd);
    drawerReplyText.value = '';
    drawerFile.value = null;
    await loadDrawerMessages();
  } catch {}
  drawerSending.value = false;
}

function onDrawerFileSelected(e) {
  drawerFile.value = e.target.files?.[0] || null;
}

// Close ticket
async function closeTicket() {
  try {
    await api.post(`/staff/tickets/${activeTicket.value.id}/close`);
    activeTicket.value.status = 'closed';
    loadTickets();
    loadStats();
    await loadDrawerMessages();
  } catch {}
}

// Assign
let searchTimeout = null;
async function searchStaff(query) {
  if (searchTimeout) clearTimeout(searchTimeout);
  searchTimeout = setTimeout(async () => {
    if (!query || query.length < 2) return;
    loadingStaff.value = true;
    try {
      const { data } = await api.get('/staff/users', { params: { search: query, role: 'staff' } });
      staffList.value = data.data || data;
    } catch {}
    loadingStaff.value = false;
  }, 300);
}

async function assignTicket() {
  if (!assignUserId.value) return;
  assigning.value = true;
  try {
    await api.post(`/staff/tickets/${activeTicket.value.id}/assign`, { user_id: assignUserId.value });
    showAssignDialog.value = false;
    await loadDrawerMessages();
    loadTickets();
  } catch {}
  assigning.value = false;
}

async function addParticipant() {
  if (!participantUserId.value) return;
  addingParticipant.value = true;
  try {
    await api.post(`/staff/tickets/${activeTicket.value.id}/participants`, { user_id: participantUserId.value });
    showParticipantDialog.value = false;
    await loadDrawerMessages();
  } catch {}
  addingParticipant.value = false;
}

function startDrawerRefresh() {
  stopDrawerRefresh();
  drawerRefreshInterval = setInterval(loadDrawerMessages, 5000);
}

function stopDrawerRefresh() {
  if (drawerRefreshInterval) {
    clearInterval(drawerRefreshInterval);
    drawerRefreshInterval = null;
  }
}

// Watch filters
watch(filters, () => {
  page.value = 1;
  loadTickets();
}, { deep: true });

watch(drawerOpen, (val) => {
  if (!val) stopDrawerRefresh();
});

onMounted(() => {
  loadTickets();
  loadStats();
});

onUnmounted(() => {
  stopDrawerRefresh();
  if (searchTimeout) clearTimeout(searchTimeout);
});
</script>

<style scoped>
.border-b {
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.border-t {
  border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.cursor-pointer :deep(tbody tr) {
  cursor: pointer;
}
</style>
