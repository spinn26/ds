<template>
  <div>
    <PageHeader title="Тех. поддержка" icon="mdi-lifebuoy">
      <template #actions>
        <v-btn variant="text" prepend-icon="mdi-refresh" :loading="loading" @click="load">
          Обновить
        </v-btn>
      </template>
    </PageHeader>

    <!-- KPI -->
    <v-row class="mb-3">
      <v-col cols="6" md="3">
        <v-card class="pa-4">
          <div class="text-caption text-medium-emphasis">Открыто</div>
          <div class="text-h4 font-weight-bold text-info">{{ kpi.open ?? 0 }}</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card class="pa-4">
          <div class="text-caption text-medium-emphasis">Активных инцидентов</div>
          <div class="text-h4 font-weight-bold text-error">{{ kpi.incidentsActive ?? 0 }}</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card class="pa-4">
          <div class="text-caption text-medium-emphasis">Решено сегодня</div>
          <div class="text-h4 font-weight-bold text-success">{{ kpi.resolvedToday ?? 0 }}</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card class="pa-4">
          <div class="text-caption text-medium-emphasis">Закрыто сегодня</div>
          <div class="text-h4 font-weight-bold">{{ kpi.closedToday ?? 0 }}</div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Filter pills -->
    <v-card class="mb-3 pa-3">
      <div class="d-flex align-center flex-wrap ga-2">
        <span class="text-caption text-medium-emphasis me-1">Статус:</span>
        <v-chip v-for="s in statusOptions" :key="s.value || 'all'"
          :color="status === s.value ? 'primary' : undefined"
          :variant="status === s.value ? 'flat' : 'tonal'"
          size="small" @click="status = s.value; load()">
          {{ s.label }}
        </v-chip>
        <v-spacer />
        <v-chip
          :color="incidentsOnly ? 'error' : undefined"
          :variant="incidentsOnly ? 'flat' : 'tonal'"
          size="small" prepend-icon="mdi-alert-decagram"
          @click="incidentsOnly = !incidentsOnly; load()">
          Только инциденты
        </v-chip>
      </div>
    </v-card>

    <!-- Tickets table -->
    <v-card>
      <v-data-table
        :items="tickets" :headers="headers" :loading="loading"
        density="compact" hover items-per-page="50" no-data-text="Тикетов не найдено">
        <template #item.subject="{ item }">
          <div class="d-flex align-center ga-2">
            <v-icon v-if="item.isIncident" color="error" size="18"
              :title="`Инцидент ${item.incidentNo}`">mdi-alert-decagram</v-icon>
            <span>{{ item.subject || '—' }}</span>
          </div>
        </template>
        <template #item.incidentNo="{ item }">
          <v-chip v-if="item.incidentNo" size="x-small" color="error" variant="tonal">
            {{ item.incidentNo }}
          </v-chip>
          <span v-else class="text-medium-emphasis">—</span>
        </template>
        <template #item.incidentSeverity="{ value }">
          <v-chip v-if="value" size="x-small" :color="severityColor(value)" variant="tonal">
            {{ severityLabel(value) }}
          </v-chip>
          <span v-else class="text-medium-emphasis">—</span>
        </template>
        <template #item.status="{ value }">
          <v-chip size="x-small" :color="statusColor(value)" variant="tonal">
            {{ statusLabel(value) }}
          </v-chip>
        </template>
        <template #item.lastMessageAt="{ value }">{{ fmtDateTime(value) }}</template>
        <template #item.actions="{ item }">
          <v-btn icon="mdi-message-text" size="x-small" variant="text" color="primary"
            title="Открыть чат" @click="openChat(item)" />
          <v-btn v-if="!item.isIncident"
            icon="mdi-alert-decagram" size="x-small" variant="text" color="error"
            title="Зафиксировать как инцидент" @click="openIncidentDialog(item)" />
          <v-btn v-else-if="!item.incidentResolvedAt"
            icon="mdi-alert-decagram-outline" size="x-small" variant="text" color="warning"
            title="Изменить приоритет" @click="openIncidentDialog(item)" />
          <v-btn v-if="item.isIncident && !item.incidentResolvedAt"
            icon="mdi-check-decagram" size="x-small" variant="text" color="success"
            title="Закрыть инцидент" @click="resolveIncident(item)" />
        </template>
      </v-data-table>
    </v-card>

    <!-- Incident dialog -->
    <v-dialog v-model="incidentDialog" max-width="480" persistent>
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="error">mdi-alert-decagram</v-icon>
          {{ incidentTarget?.isIncident ? 'Изменить приоритет инцидента' : 'Зафиксировать инцидент' }}
        </v-card-title>
        <v-card-text>
          <div v-if="incidentTarget" class="text-body-2 mb-3">
            <div class="text-medium-emphasis">Тикет</div>
            <div class="font-weight-medium">{{ incidentTarget.subject }}</div>
          </div>
          <v-radio-group v-model="incidentForm.severity" inline density="compact">
            <v-radio v-for="s in severityOptions" :key="s.value" :value="s.value"
              :label="s.label" :color="severityColor(s.value)" />
          </v-radio-group>
          <v-textarea v-model="incidentForm.note" label="Комментарий (необязательно)" rows="2"
            placeholder="Что сломалось, как воспроизводится…" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="incidentDialog = false">Отмена</v-btn>
          <v-btn color="error" :loading="saving" @click="saveIncident">
            {{ incidentTarget?.isIncident ? 'Сохранить' : 'Зафиксировать' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="3500">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import { fmtDateTime } from '../../composables/useDesign';

const router = useRouter();

const loading = ref(false);
const saving = ref(false);
const kpi = ref({});
const tickets = ref([]);
const status = ref('');
const incidentsOnly = ref(false);
const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

const statusOptions = [
  { label: 'Все', value: '' },
  { label: 'Новый', value: 'new' },
  { label: 'Открыт', value: 'open' },
  { label: 'В работе', value: 'in_progress' },
  { label: 'Решён', value: 'resolved' },
  { label: 'Закрыт', value: 'closed' },
];

const severityOptions = [
  { label: 'Critical', value: 'critical' },
  { label: 'High', value: 'high' },
  { label: 'Medium', value: 'medium' },
  { label: 'Low', value: 'low' },
];

function severityColor(s) {
  return ({ critical: 'error', high: 'warning', medium: 'info', low: 'success' })[s] || 'default';
}
function severityLabel(s) {
  return ({ critical: 'Critical', high: 'High', medium: 'Medium', low: 'Low' })[s] || s;
}
function statusColor(s) {
  return ({ new: 'info', open: 'info', in_progress: 'warning', pending: 'warning', resolved: 'success', closed: 'grey' })[s] || 'default';
}
function statusLabel(s) {
  return ({ new: 'Новый', open: 'Открыт', in_progress: 'В работе', pending: 'Ожидание', resolved: 'Решён', closed: 'Закрыт' })[s] || s;
}

const headers = [
  { title: '№', key: 'id', width: 70 },
  { title: 'Тема', key: 'subject' },
  { title: 'Партнёр', key: 'customerName', width: 200 },
  { title: 'Статус', key: 'status', width: 120 },
  { title: 'Инцидент', key: 'incidentNo', width: 130 },
  { title: 'Приоритет', key: 'incidentSeverity', width: 110 },
  { title: 'Последнее сообщение', key: 'lastMessageAt', width: 170 },
  { title: '', key: 'actions', sortable: false, width: 130 },
];

async function load() {
  loading.value = true;
  try {
    const params = {};
    if (status.value) params.status = status.value;
    if (incidentsOnly.value) params.incidents_only = 1;
    const { data } = await api.get('/support/desk', { params });
    kpi.value = data.kpi || {};
    tickets.value = data.tickets || [];
  } catch (e) {
    notify(e.response?.data?.message || 'Не удалось загрузить', 'error');
  }
  loading.value = false;
}

function openChat(item) {
  // Используем существующий staff-чат, открывая нужный тикет.
  router.push({ path: '/manage/chat', query: { id: item.id } });
}

const incidentDialog = ref(false);
const incidentTarget = ref(null);
const incidentForm = reactive({ severity: 'medium', note: '' });

function openIncidentDialog(item) {
  incidentTarget.value = item;
  incidentForm.severity = item.incidentSeverity || 'medium';
  incidentForm.note = '';
  incidentDialog.value = true;
}

async function saveIncident() {
  if (!incidentTarget.value) return;
  saving.value = true;
  try {
    const { data } = await api.post(`/chat/tickets/${incidentTarget.value.id}/incident`, {
      severity: incidentForm.severity,
      note: incidentForm.note || null,
    });
    notify(`${data.message}: ${data.incidentNo}`);
    incidentDialog.value = false;
    await load();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  saving.value = false;
}

async function resolveIncident(item) {
  saving.value = true;
  try {
    await api.post(`/chat/tickets/${item.id}/incident/resolve`);
    notify('Инцидент закрыт');
    await load();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  saving.value = false;
}

onMounted(load);
</script>
