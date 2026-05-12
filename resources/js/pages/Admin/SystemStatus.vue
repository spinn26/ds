<template>
  <div>
    <PageHeader title="Управление статусом системы" icon="mdi-monitor-dashboard">
      <template #actions>
        <v-btn variant="text" prepend-icon="mdi-eye" to="/status">Публичная страница</v-btn>
      </template>
    </PageHeader>

    <v-tabs v-model="tab" class="mb-3">
      <v-tab value="components">Компоненты</v-tab>
      <v-tab value="incidents">Инциденты</v-tab>
    </v-tabs>

    <v-tabs-window v-model="tab">
      <!-- COMPONENTS -->
      <v-tabs-window-item value="components">
        <v-card class="pa-3">
          <div class="d-flex align-center mb-3">
            <div class="text-subtitle-1 font-weight-bold flex-grow-1">Компоненты</div>
            <v-btn color="primary" prepend-icon="mdi-plus" @click="openComponent()">Добавить</v-btn>
          </div>
          <v-data-table :items="components" :headers="componentHeaders" density="compact" hover>
            <template #item.status="{ item }">
              <v-chip :color="statusColor(item.status)" size="small" variant="tonal">
                {{ statusLabel(item.status) }}
              </v-chip>
            </template>
            <template #item.active="{ item }">
              <v-icon :color="item.active ? 'success' : 'grey'" size="20">
                {{ item.active ? 'mdi-check-circle' : 'mdi-minus-circle' }}
              </v-icon>
            </template>
            <template #item.actions="{ item }">
              <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openComponent(item)" />
              <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="deleteComponent(item)" />
            </template>
          </v-data-table>
        </v-card>
      </v-tabs-window-item>

      <!-- INCIDENTS -->
      <v-tabs-window-item value="incidents">
        <v-card class="pa-3">
          <div class="d-flex align-center mb-3">
            <div class="text-subtitle-1 font-weight-bold flex-grow-1">Инциденты</div>
            <v-btn color="primary" prepend-icon="mdi-plus" @click="openIncident()">Создать инцидент</v-btn>
          </div>
          <v-data-table :items="allIncidents" :headers="incidentHeaders" density="compact" hover>
            <template #item.severity="{ item }">
              <v-chip :color="severityColor(item.severity)" size="x-small" variant="flat">
                {{ severityLabel(item.severity) }}
              </v-chip>
            </template>
            <template #item.status="{ item }">
              <v-chip :color="incidentStatusColor(item.status)" size="small" variant="tonal">
                {{ incidentStatusLabel(item.status) }}
              </v-chip>
            </template>
            <template #item.started_at="{ value }">{{ fmtDateTime(value) }}</template>
            <template #item.resolved_at="{ value }">{{ value ? fmtDateTime(value) : '—' }}</template>
            <template #item.actions="{ item }">
              <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openIncident(item)" />
              <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="deleteIncident(item)" />
            </template>
          </v-data-table>
        </v-card>
      </v-tabs-window-item>
    </v-tabs-window>

    <!-- Component Dialog -->
    <v-dialog v-model="componentDialog" max-width="560" persistent>
      <v-card>
        <v-card-title>{{ componentForm.id ? 'Редактировать' : 'Добавить' }} компонент</v-card-title>
        <v-card-text>
          <v-text-field v-model="componentForm.name" label="Название *" />
          <v-textarea v-model="componentForm.description" label="Описание" rows="2" />
          <v-select v-model="componentForm.status" :items="statusOptions" label="Статус" />
          <v-text-field v-model.number="componentForm.sort_order" label="Порядок" type="number" />
          <v-checkbox v-model="componentForm.active" label="Активен" density="compact" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="componentDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" @click="saveComponent">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Incident Dialog -->
    <v-dialog v-model="incidentDialog" max-width="640" persistent>
      <v-card>
        <v-card-title>{{ incidentForm.id ? 'Редактировать' : 'Создать' }} инцидент</v-card-title>
        <v-card-text>
          <v-text-field v-model="incidentForm.title" label="Заголовок *" />
          <v-textarea v-model="incidentForm.description" label="Описание" rows="3" />
          <v-row dense>
            <v-col cols="6">
              <v-select v-model="incidentForm.severity" :items="severityOptions" label="Серьёзность" />
            </v-col>
            <v-col cols="6">
              <v-select v-model="incidentForm.status" :items="incidentStatusOptions" label="Статус" />
            </v-col>
            <v-col cols="12">
              <v-select v-model="incidentForm.component_id" :items="componentOptions"
                item-title="name" item-value="id" label="Компонент (необязательно)" clearable />
            </v-col>
          </v-row>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="incidentDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" @click="saveIncident">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import { useConfirm } from '../../composables/useConfirm';
import { useSnackbar } from '../../composables/useSnackbar';

const confirm = useConfirm();
const { showSuccess, showError } = useSnackbar();

const tab = ref('components');
const components = ref([]);
const active = ref([]);
const history = ref([]);
const allIncidents = computed(() => [...active.value, ...history.value]);

const saving = ref(false);
const componentDialog = ref(false);
const componentForm = ref({});
const incidentDialog = ref(false);
const incidentForm = ref({});

const componentHeaders = [
  { title: 'Название', key: 'name' },
  { title: 'Описание', key: 'description' },
  { title: 'Статус', key: 'status', width: 200 },
  { title: 'Активен', key: 'active', width: 100 },
  { title: 'Порядок', key: 'sort_order', width: 100 },
  { title: '', key: 'actions', width: 100, sortable: false },
];
const incidentHeaders = [
  { title: 'Заголовок', key: 'title' },
  { title: 'Серьёзность', key: 'severity', width: 130 },
  { title: 'Статус', key: 'status', width: 170 },
  { title: 'Начало', key: 'started_at', width: 160 },
  { title: 'Завершено', key: 'resolved_at', width: 160 },
  { title: '', key: 'actions', width: 100, sortable: false },
];

const statusOptions = [
  { title: 'Работает', value: 'operational' },
  { title: 'Тех. работы', value: 'maintenance' },
  { title: 'Замедление', value: 'degraded' },
  { title: 'Частичный сбой', value: 'partial_outage' },
  { title: 'Серьёзный сбой', value: 'major_outage' },
];
const severityOptions = [
  { title: 'Незначительно', value: 'minor' },
  { title: 'Серьёзно', value: 'major' },
  { title: 'Критично', value: 'critical' },
  { title: 'Тех. работы', value: 'maintenance' },
];
const incidentStatusOptions = [
  { title: 'Расследуется', value: 'investigating' },
  { title: 'Причина найдена', value: 'identified' },
  { title: 'Мониторинг', value: 'monitoring' },
  { title: 'Решено', value: 'resolved' },
  { title: 'Запланировано', value: 'scheduled' },
  { title: 'В процессе', value: 'in_progress' },
  { title: 'Завершено', value: 'completed' },
];
const componentOptions = computed(() => components.value.map(c => ({ id: c.id, name: c.name })));

function statusColor(s) {
  return { operational: 'success', maintenance: 'info', degraded: 'warning',
    partial_outage: 'orange', major_outage: 'error' }[s] || 'grey';
}
function statusLabel(s) {
  return statusOptions.find(o => o.value === s)?.title || s;
}
function severityColor(s) {
  return { minor: 'warning', major: 'orange', critical: 'error', maintenance: 'info' }[s] || 'grey';
}
function severityLabel(s) {
  return severityOptions.find(o => o.value === s)?.title || s;
}
function incidentStatusColor(s) {
  return { resolved: 'success', completed: 'success',
    investigating: 'warning', identified: 'orange', monitoring: 'info',
    scheduled: 'info', in_progress: 'warning' }[s] || 'grey';
}
function incidentStatusLabel(s) {
  return incidentStatusOptions.find(o => o.value === s)?.title || s;
}
function fmtDateTime(v) {
  if (!v) return '—';
  try { return new Date(v).toLocaleString('ru-RU'); } catch { return v; }
}

async function load() {
  try {
    const { data } = await api.get('/system-status');
    components.value = data.components || [];
    active.value = data.active || [];
    history.value = data.history || [];
  } catch (e) { showError(e.response?.data?.message || 'Ошибка загрузки'); }
}

function openComponent(item) {
  componentForm.value = item
    ? { ...item }
    : { name: '', description: '', status: 'operational', sort_order: 0, active: true };
  componentDialog.value = true;
}
async function saveComponent() {
  if (!componentForm.value.name) { showError('Название обязательно'); return; }
  saving.value = true;
  try {
    if (componentForm.value.id) {
      await api.put(`/system-status/components/${componentForm.value.id}`, componentForm.value);
    } else {
      await api.post('/system-status/components', componentForm.value);
    }
    componentDialog.value = false;
    showSuccess('Сохранено');
    load();
  } catch (e) { showError(e.response?.data?.message || 'Ошибка'); }
  saving.value = false;
}
async function deleteComponent(item) {
  if (!await confirm.ask({ title: 'Удалить компонент?', message: item.name, confirmColor: 'error' })) return;
  try {
    await api.delete(`/system-status/components/${item.id}`);
    showSuccess('Удалено');
    load();
  } catch (e) { showError(e.response?.data?.message || 'Ошибка'); }
}

function openIncident(item) {
  incidentForm.value = item
    ? { ...item }
    : { title: '', description: '', status: 'investigating', severity: 'minor', component_id: null };
  incidentDialog.value = true;
}
async function saveIncident() {
  if (!incidentForm.value.title) { showError('Заголовок обязателен'); return; }
  saving.value = true;
  try {
    if (incidentForm.value.id) {
      await api.put(`/system-status/incidents/${incidentForm.value.id}`, incidentForm.value);
    } else {
      await api.post('/system-status/incidents', incidentForm.value);
    }
    incidentDialog.value = false;
    showSuccess('Сохранено');
    load();
  } catch (e) { showError(e.response?.data?.message || 'Ошибка'); }
  saving.value = false;
}
async function deleteIncident(item) {
  if (!await confirm.ask({ title: 'Удалить инцидент?', message: item.title, confirmColor: 'error' })) return;
  try {
    await api.delete(`/system-status/incidents/${item.id}`);
    showSuccess('Удалено');
    load();
  } catch (e) { showError(e.response?.data?.message || 'Ошибка'); }
}

onMounted(load);
</script>
