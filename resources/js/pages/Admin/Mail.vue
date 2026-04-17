<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-email-fast</v-icon>
      <h5 class="text-h5 font-weight-bold">Почтовая рассылка</h5>
    </div>

    <v-card>
      <v-tabs v-model="tab" color="primary" show-arrows>
        <v-tab value="compose">Рассылка</v-tab>
        <v-tab value="templates">Шаблоны</v-tab>
        <v-tab value="settings">Настройки SMTP</v-tab>
        <v-tab value="log">Журнал</v-tab>
      </v-tabs>
      <v-divider />

      <v-tabs-window v-model="tab">
        <!-- COMPOSE TAB -->
        <v-tabs-window-item value="compose" class="pa-5">
          <v-row>
            <v-col cols="12" md="5">
              <div class="text-subtitle-1 font-weight-bold mb-3">Кому</div>
              <v-radio-group v-model="compose.audience" density="compact" @update:model-value="refreshPreview">
                <v-radio value="all" label="Все пользователи с email" />
                <v-radio value="active" label="Только активные партнёры" />
                <v-radio value="ids" label="Выбранные по ID" />
              </v-radio-group>

              <v-textarea v-if="compose.audience === 'ids'"
                v-model="compose.idsRaw" label="ID пользователей"
                hint="Через запятую или с новой строки"
                rows="3" variant="outlined" density="compact"
                @update:model-value="refreshPreview" />

              <v-alert type="info" variant="tonal" density="compact" class="mt-2">
                <v-icon class="mr-1">mdi-account-multiple</v-icon>
                Получателей: <strong>{{ audienceCount }}</strong>
              </v-alert>
            </v-col>

            <v-col cols="12" md="7">
              <div class="d-flex align-center ga-2 mb-3">
                <v-select v-model="selectedTemplateId" :items="templateOptions"
                  item-title="name" item-value="id"
                  label="Шаблон (необязательно)"
                  variant="outlined" density="compact" hide-details
                  clearable style="max-width:320px"
                  @update:model-value="applyTemplate" />
                <v-chip size="small" variant="tonal" color="info"
                  prepend-icon="mdi-code-braces" class="ml-2">
                  Подставим переменные
                </v-chip>
              </div>
              <v-text-field v-model="compose.subject" label="Тема письма *"
                variant="outlined" density="compact" class="mb-3" />
              <v-switch v-model="compose.is_html" label="HTML-формат"
                density="compact" color="primary" inset hide-details class="mb-3" />
              <v-textarea v-model="compose.body" label="Тело письма *"
                variant="outlined" rows="12" auto-grow
                :placeholder="compose.is_html ? '<p>Здравствуйте, {{firstName}}!</p>' : 'Здравствуйте, {{firstName}}!'" />
              <div class="d-flex flex-wrap ga-1 mt-1">
                <v-chip v-for="(label, key) in tokens" :key="key"
                  size="x-small" variant="outlined"
                  style="cursor:pointer"
                  @click="insertToken(key)">
                  {{ '{{' + key + '}}' }} · {{ label }}
                </v-chip>
              </div>

              <v-progress-linear v-if="broadcastId" :model-value="progressPercent"
                height="10" rounded color="primary" class="mt-3">
                <template #default>
                  <strong>{{ progress.sent + progress.failed }} / {{ broadcastTotal }}</strong>
                </template>
              </v-progress-linear>

              <v-alert v-if="composeMsg" :type="composeMsgType" density="compact" class="mt-2" closable @click:close="composeMsg = ''">
                {{ composeMsg }}
              </v-alert>
              <div class="d-flex justify-end mt-3">
                <v-btn color="primary" size="large" :loading="sending"
                  :disabled="!canSend"
                  prepend-icon="mdi-send" @click="confirmSend">
                  Отправить
                </v-btn>
              </div>
            </v-col>
          </v-row>
        </v-tabs-window-item>

        <!-- TEMPLATES TAB -->
        <v-tabs-window-item value="templates" class="pa-5">
          <div class="d-flex align-center mb-3">
            <div class="text-subtitle-1 font-weight-bold">Шаблоны писем</div>
            <v-spacer />
            <v-btn color="primary" prepend-icon="mdi-plus" @click="openTemplate(null)">
              Новый шаблон
            </v-btn>
          </div>
          <v-data-table :items="templates" :headers="templateHeaders" :loading="loadingTemplates"
            density="compact" hover no-data-text="Шаблонов пока нет" :items-per-page="25">
            <template #item.is_html="{ value }">
              <v-chip size="x-small" :color="value ? 'info' : 'grey'" variant="tonal">
                {{ value ? 'HTML' : 'Plain' }}
              </v-chip>
            </template>
            <template #item.actions="{ item }">
              <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openTemplate(item)" />
              <v-btn icon="mdi-content-copy" size="x-small" variant="text" color="primary"
                title="Загрузить в рассылку"
                @click="useTemplateInCompose(item)" />
              <v-btn icon="mdi-delete" size="x-small" variant="text" color="error"
                @click="deleteTemplate(item)" />
            </template>
          </v-data-table>
        </v-tabs-window-item>

        <!-- SETTINGS TAB -->
        <v-tabs-window-item value="settings" class="pa-5">
          <div class="text-subtitle-1 font-weight-bold mb-3">SMTP-подключение</div>
          <v-row>
            <v-col cols="12" sm="8">
              <v-text-field v-model="smtp.host" label="Хост *" variant="outlined" density="compact" class="mb-3" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model.number="smtp.port" type="number" label="Порт *" variant="outlined" density="compact" class="mb-3" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="smtp.username" label="Логин" variant="outlined" density="compact" class="mb-3" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="smtp.password" type="password" label="Пароль"
                :placeholder="smtp.hasPassword ? 'Оставьте пустым, чтобы не менять' : ''"
                variant="outlined" density="compact" class="mb-3" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-select v-model="smtp.encryption" :items="encOptions" label="Шифрование"
                variant="outlined" density="compact" class="mb-3" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="smtp.from_address" label="Отправитель — email *" variant="outlined" density="compact" class="mb-3" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="smtp.from_name" label="Отправитель — имя" variant="outlined" density="compact" class="mb-3" />
            </v-col>
          </v-row>

          <div class="d-flex align-center ga-2 flex-wrap">
            <v-btn color="primary" :loading="savingSmtp" prepend-icon="mdi-content-save" @click="saveSmtp">
              Сохранить
            </v-btn>
            <v-divider vertical class="mx-2" />
            <v-text-field v-model="testTo" label="Тест на email" variant="outlined" density="compact" hide-details style="max-width: 280px" />
            <v-btn variant="tonal" color="secondary" :loading="testing" prepend-icon="mdi-email-check" @click="sendTest">
              Проверить
            </v-btn>
          </div>
          <v-alert v-if="smtpMsg" :type="smtpMsgType" density="compact" class="mt-3" closable @click:close="smtpMsg = ''">
            {{ smtpMsg }}
          </v-alert>
          <div v-if="smtp.updated_at" class="text-caption text-medium-emphasis mt-3">
            Последнее обновление: {{ fmtDateTime(smtp.updated_at) }}
          </div>
        </v-tabs-window-item>

        <!-- LOG TAB -->
        <v-tabs-window-item value="log" class="pa-5">
          <div class="d-flex align-center ga-2 mb-3 flex-wrap">
            <v-text-field v-model="logFilter.search" placeholder="Поиск по email или теме..."
              variant="outlined" density="compact" hide-details style="max-width:320px"
              prepend-inner-icon="mdi-magnify" @update:model-value="debouncedLoadLog" />
            <v-select v-model="logFilter.status" :items="logStatusOptions" label="Статус"
              clearable hide-details density="compact" variant="outlined"
              style="max-width:200px" @update:model-value="loadLog" />
            <v-spacer />
            <v-btn variant="text" size="small" prepend-icon="mdi-refresh" @click="loadLog">Обновить</v-btn>
          </div>
          <v-data-table :items="log" :headers="logHeaders" :loading="loadingLog"
            density="compact" hover no-data-text="Журнал пуст" :items-per-page="50">
            <template #item.status="{ value }">
              <v-chip size="x-small" :color="value === 'sent' ? 'success' : 'error'">
                {{ value === 'sent' ? 'Отправлено' : 'Ошибка' }}
              </v-chip>
            </template>
            <template #item.created_at="{ value }">{{ fmtDateTime(value) }}</template>
            <template #item.error="{ value }">
              <span v-if="value" class="text-caption text-error">{{ value }}</span>
              <span v-else>—</span>
            </template>
          </v-data-table>
        </v-tabs-window-item>
      </v-tabs-window>
    </v-card>

    <!-- Template edit dialog -->
    <v-dialog v-model="templateDialog" max-width="720" persistent scrollable>
      <v-card>
        <v-card-title>{{ templateForm.id ? 'Редактировать шаблон' : 'Новый шаблон' }}</v-card-title>
        <v-card-text style="max-height:70vh">
          <v-text-field v-model="templateForm.name" label="Название шаблона *"
            variant="outlined" density="compact" class="mb-3" />
          <v-text-field v-model="templateForm.subject" label="Тема *"
            variant="outlined" density="compact" class="mb-3" />
          <v-switch v-model="templateForm.is_html" label="HTML-формат" color="primary"
            density="compact" inset hide-details class="mb-3" />
          <v-textarea v-model="templateForm.body" label="Тело письма *"
            variant="outlined" rows="10" auto-grow />
          <div class="d-flex flex-wrap ga-1 mt-2">
            <v-chip v-for="(label, key) in tokens" :key="key"
              size="x-small" variant="outlined" style="cursor:pointer"
              @click="insertTokenIntoTemplate(key)">
              {{ '{{' + key + '}}' }} · {{ label }}
            </v-chip>
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="templateDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="savingTemplate"
            :disabled="!templateForm.name || !templateForm.subject || !templateForm.body"
            @click="saveTemplate">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Confirm send dialog -->
    <v-dialog v-model="confirmDialog" max-width="420">
      <v-card>
        <v-card-title>Отправить рассылку?</v-card-title>
        <v-card-text>
          Будет отправлено писем: <strong>{{ audienceCount }}</strong>.<br>
          Тема: <em>{{ compose.subject }}</em>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="confirmDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="sending" @click="doSend">Отправить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import { fmtDateTime } from '../../composables/useDesign';

const tab = ref('compose');

// ========== SMTP SETTINGS ==========
const smtp = ref({
  host: '', port: 587, username: '', password: '',
  encryption: 'tls', from_address: '', from_name: '',
  hasPassword: false, updated_at: null,
});
const savingSmtp = ref(false);
const testing = ref(false);
const testTo = ref('');
const smtpMsg = ref('');
const smtpMsgType = ref('success');

const encOptions = [
  { title: 'TLS', value: 'tls' },
  { title: 'SSL', value: 'ssl' },
  { title: 'Без шифрования', value: 'null' },
];

async function loadSmtp() {
  try {
    const { data } = await api.get('/admin/mail/settings');
    smtp.value = {
      ...smtp.value,
      ...data,
      password: '',
      encryption: data.encryption ?? 'null',
    };
  } catch {}
}

async function saveSmtp() {
  savingSmtp.value = true;
  smtpMsg.value = '';
  try {
    await api.put('/admin/mail/settings', {
      host: smtp.value.host,
      port: smtp.value.port,
      username: smtp.value.username,
      password: smtp.value.password,
      encryption: smtp.value.encryption,
      from_address: smtp.value.from_address,
      from_name: smtp.value.from_name,
    });
    smtpMsg.value = 'Настройки сохранены';
    smtpMsgType.value = 'success';
    await loadSmtp();
  } catch (e) {
    smtpMsg.value = e.response?.data?.message || 'Ошибка сохранения';
    smtpMsgType.value = 'error';
  }
  savingSmtp.value = false;
}

async function sendTest() {
  if (!testTo.value) {
    smtpMsg.value = 'Укажите email для теста';
    smtpMsgType.value = 'error';
    return;
  }
  testing.value = true;
  smtpMsg.value = '';
  try {
    await api.post('/admin/mail/test', { to: testTo.value });
    smtpMsg.value = 'Тестовое письмо отправлено';
    smtpMsgType.value = 'success';
  } catch (e) {
    smtpMsg.value = e.response?.data?.message || 'Ошибка отправки';
    smtpMsgType.value = 'error';
  }
  testing.value = false;
}

// ========== COMPOSE ==========
const compose = ref({
  audience: 'active',
  idsRaw: '',
  subject: '',
  body: '',
  is_html: true,
});
const audienceCount = ref(0);
const sending = ref(false);
const composeMsg = ref('');
const composeMsgType = ref('success');
const confirmDialog = ref(false);

function parseIds() {
  return compose.value.idsRaw
    .split(/[,\s\n]+/).map(s => parseInt(s.trim(), 10))
    .filter(n => Number.isFinite(n) && n > 0);
}

const canSend = computed(() => compose.value.subject.trim() && compose.value.body.trim() && audienceCount.value > 0);

async function refreshPreview() {
  try {
    const payload = { audience: compose.value.audience };
    if (compose.value.audience === 'ids') payload.ids = parseIds();
    const { data } = await api.post('/admin/mail/audience-preview', payload);
    audienceCount.value = data.count || 0;
  } catch {
    audienceCount.value = 0;
  }
}

function confirmSend() {
  if (!canSend.value) return;
  confirmDialog.value = true;
}

async function doSend() {
  sending.value = true;
  composeMsg.value = '';
  broadcastId.value = null;
  progress.value = { sent: 0, failed: 0 };
  try {
    const payload = {
      audience: compose.value.audience,
      subject: compose.value.subject,
      body: compose.value.body,
      is_html: compose.value.is_html,
    };
    if (compose.value.audience === 'ids') payload.ids = parseIds();
    const { data } = await api.post('/admin/mail/broadcast', payload);
    composeMsg.value = data.message;
    composeMsgType.value = 'info';
    confirmDialog.value = false;
    broadcastId.value = data.broadcast_id;
    broadcastTotal.value = data.total || 0;
    if (progressTimer) clearInterval(progressTimer);
    progressTimer = setInterval(pollProgress, 1500);
  } catch (e) {
    composeMsg.value = e.response?.data?.message || 'Ошибка рассылки';
    composeMsgType.value = 'error';
  }
  sending.value = false;
}

// ========== LOG ==========
const log = ref([]);
const loadingLog = ref(false);
const logFilter = ref({ search: '', status: null });
const logStatusOptions = [
  { title: 'Отправлено', value: 'sent' },
  { title: 'Ошибка', value: 'failed' },
];
const logHeaders = [
  { title: 'Дата', key: 'created_at', width: 160 },
  { title: 'Кому', key: 'recipient_email' },
  { title: 'Тема', key: 'subject' },
  { title: 'Статус', key: 'status', width: 120 },
  { title: 'Ошибка', key: 'error' },
];

async function loadLog() {
  loadingLog.value = true;
  try {
    const params = {};
    if (logFilter.value.search) params.search = logFilter.value.search;
    if (logFilter.value.status) params.status = logFilter.value.status;
    const { data } = await api.get('/admin/mail/log', { params });
    log.value = data.data || [];
  } catch {}
  loadingLog.value = false;
}

const { debounced: debouncedLoadLog } = useDebounce(loadLog, 400);

// ========== TEMPLATES ==========
const templates = ref([]);
const tokens = ref({});
const loadingTemplates = ref(false);
const templateDialog = ref(false);
const templateForm = ref({ id: null, name: '', subject: '', body: '', is_html: true });
const savingTemplate = ref(false);
const selectedTemplateId = ref(null);

const templateHeaders = [
  { title: 'Название', key: 'name' },
  { title: 'Тема', key: 'subject' },
  { title: 'Формат', key: 'is_html', width: 100 },
  { title: '', key: 'actions', sortable: false, width: 120 },
];

const templateOptions = computed(() => templates.value.map(t => ({ id: t.id, name: t.name })));

async function loadTemplates() {
  loadingTemplates.value = true;
  try {
    const { data } = await api.get('/admin/mail/templates');
    templates.value = data.data || [];
    tokens.value = data.tokens || {};
  } catch {}
  loadingTemplates.value = false;
}

function openTemplate(item) {
  templateForm.value = item
    ? { id: item.id, name: item.name, subject: item.subject, body: item.body, is_html: !!item.is_html }
    : { id: null, name: '', subject: '', body: '', is_html: true };
  templateDialog.value = true;
}

async function saveTemplate() {
  savingTemplate.value = true;
  try {
    const payload = {
      name: templateForm.value.name,
      subject: templateForm.value.subject,
      body: templateForm.value.body,
      is_html: templateForm.value.is_html,
    };
    if (templateForm.value.id) {
      await api.put(`/admin/mail/templates/${templateForm.value.id}`, payload);
    } else {
      await api.post('/admin/mail/templates', payload);
    }
    templateDialog.value = false;
    await loadTemplates();
  } catch {}
  savingTemplate.value = false;
}

async function deleteTemplate(item) {
  if (!confirm(`Удалить шаблон «${item.name}»?`)) return;
  try {
    await api.delete(`/admin/mail/templates/${item.id}`);
    await loadTemplates();
  } catch {}
}

function applyTemplate(id) {
  if (!id) return;
  const t = templates.value.find(x => x.id === id);
  if (!t) return;
  compose.value.subject = t.subject;
  compose.value.body = t.body;
  compose.value.is_html = !!t.is_html;
}

function useTemplateInCompose(item) {
  selectedTemplateId.value = item.id;
  applyTemplate(item.id);
  tab.value = 'compose';
}

function insertToken(key) {
  compose.value.body += ` {{${key}}}`;
}

function insertTokenIntoTemplate(key) {
  templateForm.value.body += ` {{${key}}}`;
}

// ========== PROGRESS ==========
const broadcastId = ref(null);
const broadcastTotal = ref(0);
const progress = ref({ sent: 0, failed: 0 });
let progressTimer = null;

const progressPercent = computed(() => {
  if (!broadcastTotal.value) return 0;
  return Math.round(((progress.value.sent + progress.value.failed) / broadcastTotal.value) * 100);
});

async function pollProgress() {
  if (!broadcastId.value) return;
  try {
    const { data } = await api.get(`/admin/mail/broadcast/${broadcastId.value}/progress`);
    progress.value = { sent: data.sent || 0, failed: data.failed || 0 };
    if (progress.value.sent + progress.value.failed >= broadcastTotal.value) {
      clearInterval(progressTimer);
      progressTimer = null;
      composeMsg.value = `Рассылка завершена. Отправлено: ${progress.value.sent}, ошибки: ${progress.value.failed}`;
      composeMsgType.value = progress.value.failed > 0 ? 'warning' : 'success';
    }
  } catch {}
}

// ========== LIFECYCLE ==========
watch(tab, (v) => {
  if (v === 'log') loadLog();
  if (v === 'compose') refreshPreview();
  if (v === 'templates') loadTemplates();
});

onMounted(() => {
  loadSmtp();
  loadTemplates();
  refreshPreview();
});
</script>
