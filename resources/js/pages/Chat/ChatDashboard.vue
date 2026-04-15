<template>
  <div class="chat-dashboard d-flex flex-column" style="height: calc(100vh - 64px)">
    <!-- Toolbar -->
    <div class="d-flex align-center ga-3 pa-3 border-b flex-wrap">
      <!-- View switcher -->
      <v-btn-toggle v-model="viewMode" density="compact" color="primary" divided variant="outlined" mandatory>
        <v-btn value="kanban" size="small" prepend-icon="mdi-view-column">Канбан</v-btn>
        <v-btn value="list" size="small" prepend-icon="mdi-format-list-bulleted">Список</v-btn>
        <v-btn value="customers" size="small" prepend-icon="mdi-account-group">Клиенты</v-btn>
      </v-btn-toggle>

      <!-- Filters -->
      <v-menu>
        <template #activator="{ props }">
          <v-btn v-bind="props" variant="outlined" size="small" prepend-icon="mdi-filter-variant">
            Фильтры
            <v-icon end size="14">mdi-chevron-down</v-icon>
          </v-btn>
        </template>
        <v-list density="compact" width="200">
          <v-list-subheader>По статусу</v-list-subheader>
          <v-list-item v-for="s in statusOptions" :key="s.value" @click="setFilter('status', s.value, s.title)">
            <v-list-item-title>{{ s.title }}</v-list-item-title>
          </v-list-item>
          <v-divider />
          <v-list-subheader>По приоритету</v-list-subheader>
          <v-list-item v-for="p in priorityOptions" :key="p.value" @click="setFilter('priority', p.value, p.title)">
            <template #prepend><v-icon :color="p.color" size="14">mdi-circle</v-icon></template>
            <v-list-item-title>{{ p.title }}</v-list-item-title>
          </v-list-item>
          <v-divider />
          <v-list-subheader>По отделу</v-list-subheader>
          <v-list-item v-for="d in departmentOptions" :key="d.value" @click="setFilter('department', d.value, d.title)">
            <v-list-item-title>{{ d.title }}</v-list-item-title>
          </v-list-item>
        </v-list>
      </v-menu>

      <v-chip v-if="activeFilter" closable @click:close="activeFilter = null" color="primary" size="small">
        {{ activeFilter.label }}
      </v-chip>

      <!-- Stats -->
      <div class="text-caption text-medium-emphasis d-flex ga-2">
        <span>Всего: {{ stats.total }}</span>
        <span class="text-blue">Новых: {{ stats.new }}</span>
        <span class="text-warning">Открытых: {{ stats.open }}</span>
        <span v-if="stats.critical" class="text-error font-weight-bold">Критических: {{ stats.critical }}</span>
      </div>

      <v-spacer />

      <!-- Search -->
      <v-text-field v-model="search" placeholder="Поиск тикетов..." prepend-inner-icon="mdi-magnify"
        clearable hide-details style="max-width: 260px" @update:model-value="debouncedLoad" />

      <!-- New ticket -->
      <v-btn color="primary" size="small" prepend-icon="mdi-plus" @click="createDialog = true">
        Новый тикет
      </v-btn>
    </div>

    <!-- Content area -->
    <div class="flex-grow-1 d-flex" style="min-height: 0; overflow: hidden">
      <!-- KANBAN VIEW -->
      <template v-if="viewMode === 'kanban'">
        <div class="flex-grow-1 d-flex ga-3 pa-3" style="overflow-x: auto">
          <div v-for="col in kanbanColumns" :key="col.status" class="kanban-col d-flex flex-column"
            style="min-width: 280px; width: 280px; flex-shrink: 0">
            <div class="d-flex align-center ga-2 mb-2 px-1">
              <v-chip :color="col.color" size="small" variant="flat">{{ col.label }}</v-chip>
              <span class="text-caption text-medium-emphasis">{{ ticketsByStatus(col.status).length }}</span>
            </div>
            <div class="flex-grow-1 overflow-y-auto d-flex flex-column ga-2">
              <v-card v-for="t in ticketsByStatus(col.status)" :key="t.id" class="pa-3 cursor-pointer"
                :class="selectedTicket?.id === t.id ? 'border-primary' : ''" variant="outlined"
                @click="selectTicket(t)">
                <div class="d-flex align-center ga-2 mb-1">
                  <span class="text-caption text-medium-emphasis">#{{ t.id }}</span>
                  <v-spacer />
                  <v-chip :color="priorityColor(t.priority)" size="x-small" variant="tonal">{{ priorityLabel(t.priority) }}</v-chip>
                </div>
                <div class="text-body-2 font-weight-medium mb-1 text-truncate">{{ t.subject }}</div>
                <div class="d-flex align-center ga-1 text-caption text-medium-emphasis">
                  <v-icon size="12">mdi-account</v-icon>
                  <span class="text-truncate">{{ t.customer_name }}</span>
                  <v-spacer />
                  <v-icon size="12">mdi-message-outline</v-icon>
                  {{ t.messages_count }}
                </div>
              </v-card>
              <div v-if="!ticketsByStatus(col.status).length" class="text-center text-caption text-medium-emphasis pa-4">
                Пусто
              </div>
            </div>
          </div>
        </div>

        <!-- Right chat panel -->
        <div v-if="selectedTicket" class="border-l" style="width: 420px; flex-shrink: 0">
          <ChatPanel :ticket="selectedTicket" @close="selectedTicket = null" @status-change="onStatusChange" @reload="loadTickets" />
        </div>
      </template>

      <!-- LIST VIEW -->
      <template v-if="viewMode === 'list'">
        <div class="border-r" style="width: 380px; flex-shrink: 0; overflow-y: auto">
          <v-list lines="three" class="pa-0">
            <v-list-item v-for="t in filteredTickets" :key="t.id" :active="selectedTicket?.id === t.id"
              @click="selectTicket(t)" class="border-b">
              <template #prepend>
                <v-avatar size="36" :color="departmentColor(t.department)">
                  <v-icon size="18" color="white">{{ departmentIcon(t.department) }}</v-icon>
                </v-avatar>
              </template>
              <v-list-item-title class="d-flex align-center ga-2">
                <span class="text-truncate font-weight-medium">{{ t.subject }}</span>
                <v-chip :color="statusColor(t.status)" size="x-small" variant="tonal" class="ml-auto flex-shrink-0">
                  {{ statusLabel(t.status) }}
                </v-chip>
              </v-list-item-title>
              <v-list-item-subtitle>
                <span class="text-truncate">{{ t.customer_name }}</span>
                <span class="text-caption text-medium-emphasis ml-2">#{{ t.id }}</span>
              </v-list-item-subtitle>
              <template #append>
                <div class="text-caption text-medium-emphasis">{{ timeAgo(t.last_message_at || t.updated_at) }}</div>
              </template>
            </v-list-item>
            <v-list-item v-if="!filteredTickets.length && !loading">
              <div class="text-center pa-6 text-medium-emphasis">Нет тикетов</div>
            </v-list-item>
          </v-list>
        </div>

        <!-- Chat panel -->
        <div class="flex-grow-1">
          <ChatPanel v-if="selectedTicket" :ticket="selectedTicket" @close="selectedTicket = null" @status-change="onStatusChange" @reload="loadTickets" />
          <div v-else class="d-flex align-center justify-center h-100">
            <div class="text-center">
              <v-icon size="64" color="grey-lighten-1">mdi-chat-outline</v-icon>
              <div class="text-h6 text-medium-emphasis mt-2">Выберите тикет</div>
            </div>
          </div>
        </div>
      </template>

      <!-- CUSTOMERS VIEW -->
      <template v-if="viewMode === 'customers'">
        <div class="flex-grow-1 pa-4" style="overflow-y: auto">
          <v-row>
            <v-col v-for="group in customerGroups" :key="group.customerId" cols="12" md="6" lg="4">
              <v-card class="pa-4">
                <div class="d-flex align-center ga-3 mb-3">
                  <v-avatar color="primary" size="40">
                    <span class="text-white font-weight-bold">{{ group.customerName?.[0] || '?' }}</span>
                  </v-avatar>
                  <div>
                    <div class="font-weight-medium">{{ group.customerName }}</div>
                    <div class="text-caption text-medium-emphasis">{{ group.customerEmail }}</div>
                  </div>
                  <v-spacer />
                  <v-chip size="small" color="primary" variant="tonal">{{ group.tickets.length }} тикетов</v-chip>
                </div>
                <v-list density="compact" class="pa-0">
                  <v-list-item v-for="t in group.tickets" :key="t.id" @click="selectTicket(t); viewMode = 'list'"
                    class="px-0">
                    <v-list-item-title class="text-body-2">{{ t.subject }}</v-list-item-title>
                    <template #append>
                      <v-chip :color="statusColor(t.status)" size="x-small" variant="tonal">{{ statusLabel(t.status) }}</v-chip>
                    </template>
                  </v-list-item>
                </v-list>
              </v-card>
            </v-col>
          </v-row>
        </div>
      </template>
    </div>

    <!-- Create dialog -->
    <v-dialog v-model="createDialog" max-width="560" persistent>
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="primary">mdi-plus-circle</v-icon> Новый тикет
        </v-card-title>
        <v-card-text>
          <v-text-field v-model="createForm.subject" label="Тема" class="mb-3" />
          <v-row dense class="mb-3">
            <v-col cols="6">
              <v-select v-model="createForm.department" :items="departmentOptions" item-title="title" item-value="value" label="Отдел" />
            </v-col>
            <v-col cols="6">
              <v-select v-model="createForm.priority" :items="priorityOptions" item-title="title" item-value="value" label="Приоритет" />
            </v-col>
          </v-row>
          <v-textarea v-model="createForm.message" label="Сообщение" rows="4" auto-grow />
          <v-alert v-if="createError" type="error" density="compact" class="mt-2">{{ createError }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="createDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="creating" prepend-icon="mdi-send" @click="createTicket">Создать</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import { timeAgo } from '../../composables/useDesign';
import ChatPanel from './ChatPanel.vue';

const viewMode = ref('kanban');
const tickets = ref([]);
const loading = ref(false);
const search = ref('');
const selectedTicket = ref(null);
const activeFilter = ref(null);
const stats = ref({ total: 0, new: 0, open: 0, pending: 0, critical: 0 });

const createDialog = ref(false);
const creating = ref(false);
const createError = ref('');
const createForm = ref({ subject: '', department: 'general', priority: 'medium', message: '' });

const statusOptions = [
  { title: 'Новый', value: 'new' },
  { title: 'Открыт', value: 'open' },
  { title: 'Ожидание', value: 'pending' },
  { title: 'Решён', value: 'resolved' },
  { title: 'Закрыт', value: 'closed' },
];
const priorityOptions = [
  { title: 'Критический', value: 'critical', color: 'error' },
  { title: 'Высокий', value: 'high', color: 'orange' },
  { title: 'Средний', value: 'medium', color: 'warning' },
  { title: 'Низкий', value: 'low', color: 'success' },
];
const departmentOptions = [
  { title: 'Техподдержка', value: 'technical' },
  { title: 'Биллинг', value: 'billing' },
  { title: 'Продажи', value: 'sales' },
  { title: 'Общие', value: 'general' },
];

const kanbanColumns = [
  { status: 'new', label: 'Новые', color: 'blue' },
  { status: 'open', label: 'Открытые', color: 'warning' },
  { status: 'pending', label: 'Ожидание', color: 'orange' },
  { status: 'resolved', label: 'Решённые', color: 'success' },
  { status: 'closed', label: 'Закрытые', color: 'grey' },
];

function statusColor(s) { return { new: 'blue', open: 'warning', pending: 'orange', resolved: 'success', closed: 'grey' }[s] || 'grey'; }
function statusLabel(s) { return { new: 'Новый', open: 'Открыт', pending: 'Ожидание', resolved: 'Решён', closed: 'Закрыт' }[s] || s; }
function priorityColor(p) { return { critical: 'error', high: 'orange', medium: 'warning', low: 'success' }[p] || 'grey'; }
function priorityLabel(p) { return { critical: 'Крит.', high: 'Выс.', medium: 'Сред.', low: 'Низк.' }[p] || p; }
function departmentColor(d) { return { technical: 'blue', billing: 'green', sales: 'orange', general: 'grey' }[d] || 'grey'; }
function departmentIcon(d) { return { technical: 'mdi-headset', billing: 'mdi-credit-card', sales: 'mdi-handshake', general: 'mdi-help-circle' }[d] || 'mdi-help-circle'; }

function setFilter(type, value, label) { activeFilter.value = { type, value, label }; }

const filteredTickets = computed(() => {
  let list = tickets.value;
  if (activeFilter.value) {
    list = list.filter(t => t[activeFilter.value.type] === activeFilter.value.value);
  }
  return list;
});

function ticketsByStatus(status) {
  return filteredTickets.value.filter(t => t.status === status);
}

const customerGroups = computed(() => {
  const map = {};
  filteredTickets.value.forEach(t => {
    if (!map[t.created_by]) {
      map[t.created_by] = { customerId: t.created_by, customerName: t.customer_name, customerEmail: t.customer_email, tickets: [] };
    }
    map[t.created_by].tickets.push(t);
  });
  return Object.values(map);
});

function selectTicket(t) { selectedTicket.value = t; }

function onStatusChange(ticketId, newStatus) {
  const t = tickets.value.find(t => t.id === ticketId);
  if (t) t.status = newStatus;
  if (selectedTicket.value?.id === ticketId) selectedTicket.value.status = newStatus;
}

const { debounced: debouncedLoad } = useDebounce(loadTickets, 400);

async function loadTickets() {
  loading.value = true;
  try {
    const params = {};
    if (search.value) params.search = search.value;
    if (activeFilter.value) params[activeFilter.value.type] = activeFilter.value.value;
    const { data } = await api.get('/chat/tickets', { params });
    tickets.value = data.data || [];
  } catch {}
  loading.value = false;
}

async function loadStats() {
  try {
    const { data } = await api.get('/chat/tickets/stats');
    stats.value = data;
  } catch {}
}

async function createTicket() {
  if (!createForm.value.subject?.trim() || !createForm.value.message?.trim()) {
    createError.value = 'Заполните тему и сообщение';
    return;
  }
  creating.value = true;
  createError.value = '';
  try {
    const { data } = await api.post('/chat/tickets', createForm.value);
    createDialog.value = false;
    createForm.value = { subject: '', department: 'general', priority: 'medium', message: '' };
    await loadTickets();
    loadStats();
    if (data.ticket) selectTicket(data.ticket);
  } catch (e) {
    createError.value = e.response?.data?.message || 'Ошибка';
  }
  creating.value = false;
}

onMounted(() => { loadTickets(); loadStats(); });
</script>

<style scoped>
.border-b { border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.border-l { border-left: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.border-r { border-right: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.border-primary { border-color: rgb(var(--v-theme-primary)) !important; }
.cursor-pointer { cursor: pointer; }
.kanban-col { background: rgba(var(--v-theme-surface-variant), 0.3); border-radius: 12px; padding: 8px; }
</style>
