<template>
  <div>
    <PageHeader title="Почтовая рассылка" icon="mdi-email-fast" />

    <v-card>
      <v-tabs v-model="tab" color="primary" show-arrows>
        <v-tab value="compose">Рассылка</v-tab>
        <v-tab value="templates">Шаблоны</v-tab>
        <v-tab value="mailboxes">Ящики</v-tab>
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
              <div class="d-flex align-center ga-2 mb-3 flex-wrap">
                <v-select v-model="selectedTemplateId" :items="templateOptions"
                  item-title="name" item-value="id"
                  label="Шаблон (необязательно)"
                  variant="outlined" density="compact" hide-details
                  clearable style="max-width:300px; flex:1 1 220px"
                  @update:model-value="applyTemplate" />
                <!-- Выбор ящика для рассылки. null = ящик по умолчанию.
                     Показываем только если в системе больше одного ящика. -->
                <v-select v-if="mailboxes.length > 1"
                  v-model="compose.mailbox_id" :items="mailboxOptions"
                  item-title="title" item-value="id"
                  label="Отправить с ящика"
                  variant="outlined" density="compact" hide-details
                  prepend-inner-icon="mdi-email-outline"
                  style="max-width:280px; flex:1 1 200px" />
              </div>
              <v-text-field v-model="compose.subject" label="Тема письма *"
                variant="outlined" density="compact" class="mb-3" />
              <v-switch v-model="compose.is_html" label="HTML-формат (используется визуальный редактор)"
                density="compact" color="primary" inset hide-details class="mb-3" />

              <div v-if="compose.is_html" class="mb-2">
                <div class="text-caption text-medium-emphasis mb-1">Тело письма *</div>
                <RichTextEditor v-model="compose.body" min-height="260px" ref="composeEditor" />
              </div>
              <v-textarea v-else v-model="compose.body" label="Тело письма *"
                variant="outlined" rows="12" auto-grow
                placeholder="Здравствуйте, {{firstName}}!" />

              <div class="d-flex flex-wrap ga-1 mt-2">
                <v-chip v-for="(label, key) in tokens" :key="key"
                  size="x-small" variant="outlined"
                  style="cursor:pointer"
                  @click="insertToken(key)">
                  {{ tokenTag(key) }} · {{ label }}
                </v-chip>
              </div>

              <v-progress-linear v-if="broadcastId" :model-value="progressPercent"
                height="10" rounded color="primary" class="mt-3">
                <template #default>
                  <strong>{{ progressDone }} / {{ broadcastTotal }}</strong>
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
            <ColumnVisibilityMenu
              :headers="templateHeaders"
              v-model:visible="templateColumnVisible"
              storage-key="mail-templates-cols" />
            <v-btn color="primary" prepend-icon="mdi-plus" @click="openTemplate(null)">
              Новый шаблон
            </v-btn>
          </div>
          <v-data-table :items="templates" :headers="visibleTemplateHeaders" :loading="loadingTemplates"
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

        <!-- MAILBOXES TAB — несколько SMTP-ящиков, default-ящик помечен -->
        <v-tabs-window-item value="mailboxes" class="pa-5">
          <div class="d-flex align-center mb-3">
            <div class="text-subtitle-1 font-weight-bold">SMTP-ящики</div>
            <v-spacer />
            <v-btn color="primary" prepend-icon="mdi-plus" @click="openMailbox(null)">
              Добавить ящик
            </v-btn>
          </div>
          <v-data-table :items="mailboxes" :headers="mailboxHeaders" :loading="loadingMailboxes"
            density="compact" hover no-data-text="Ящиков пока нет. Добавьте первый — он автоматически станет основным."
            :items-per-page="25">
            <template #item.is_default="{ value }">
              <v-chip v-if="value" size="x-small" color="success" variant="tonal" prepend-icon="mdi-star">
                По умолчанию
              </v-chip>
            </template>
            <template #item.encryption="{ value }">
              <v-chip size="x-small" variant="outlined">{{ value || 'без шифрования' }}</v-chip>
            </template>
            <template #item.actions="{ item }">
              <v-btn icon="mdi-email-check" size="x-small" variant="text" color="secondary"
                title="Отправить тестовое письмо" @click="openTestDialog(item)" />
              <v-btn v-if="!item.is_default" icon="mdi-star-outline" size="x-small" variant="text"
                title="Сделать ящиком по умолчанию" @click="setDefaultMailbox(item)" />
              <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openMailbox(item)" />
              <v-btn icon="mdi-delete" size="x-small" variant="text" color="error"
                @click="deleteMailbox(item)" />
            </template>
          </v-data-table>
        </v-tabs-window-item>

        <!-- LOG TAB -->
        <v-tabs-window-item value="log" class="pa-5">
          <!-- Сводка по статусам — chip'ы кликабельны, фильтруют таблицу. -->
          <div class="d-flex align-center ga-2 mb-3 flex-wrap">
            <v-chip-group v-model="logFilter.deliveryStatus" mandatory selected-class="text-primary"
              @update:model-value="loadLog">
              <v-chip filter value="">Все <span class="ml-1 text-medium-emphasis">·&nbsp;{{ summaryTotal }}</span></v-chip>
              <v-chip filter value="pending" color="grey">
                <v-icon start size="14">mdi-clock-outline</v-icon>
                В очереди <span class="ml-1">·&nbsp;{{ summary.pending || 0 }}</span>
              </v-chip>
              <v-chip filter value="sent" color="success">
                <v-icon start size="14">mdi-email-check-outline</v-icon>
                Отправлено <span class="ml-1">·&nbsp;{{ summary.sent || 0 }}</span>
              </v-chip>
              <v-chip filter value="delivered" color="info">
                <v-icon start size="14">mdi-email-open-outline</v-icon>
                Открыто <span class="ml-1">·&nbsp;{{ summary.delivered || 0 }}</span>
              </v-chip>
              <v-chip filter value="failed" color="error">
                <v-icon start size="14">mdi-alert-circle-outline</v-icon>
                Не отправлено <span class="ml-1">·&nbsp;{{ summary.failed || 0 }}</span>
              </v-chip>
              <v-chip filter value="bounced" color="warning">
                <v-icon start size="14">mdi-keyboard-return</v-icon>
                Отклонено <span class="ml-1">·&nbsp;{{ summary.bounced || 0 }}</span>
              </v-chip>
            </v-chip-group>
          </div>

          <div class="d-flex align-center ga-2 mb-3 flex-wrap">
            <v-text-field v-model="logFilter.search" placeholder="Поиск по email / теме / Message-ID…"
              variant="outlined" density="compact" hide-details
              prepend-inner-icon="mdi-magnify"
              style="max-width: 360px; flex: 1 1 240px"
              @update:model-value="debouncedLoadLog" />
            <v-select v-model="logFilter.mailType" :items="mailTypeOptions" placeholder="Тип письма"
              clearable hide-details density="compact" variant="outlined"
              style="max-width: 200px; flex: 1 1 140px"
              @update:model-value="loadLog" />
            <v-spacer />
            <ColumnVisibilityMenu
              :headers="logHeaders"
              v-model:visible="logColumnVisible"
              storage-key="mail-log-cols" />
            <v-btn variant="text" size="small" prepend-icon="mdi-refresh" @click="loadLog">Обновить</v-btn>
          </div>

          <v-data-table :items="log" :headers="visibleLogHeaders" :loading="loadingLog"
            density="compact" hover no-data-text="Журнал пуст" :items-per-page="50"
            show-expand v-model:expanded="logExpanded">
            <template #item.delivery_status="{ item }">
              <v-tooltip :text="deliveryStatusTooltip(item)" location="top">
                <template #activator="{ props }">
                  <v-chip v-bind="props" size="x-small" :color="deliveryStatusColor(item)" variant="flat">
                    <v-icon start size="12">{{ deliveryStatusIcon(item) }}</v-icon>
                    {{ deliveryStatusLabel(item) }}
                  </v-chip>
                </template>
              </v-tooltip>
            </template>
            <template #item.mail_type="{ value }">
              <span class="text-caption">{{ mailTypeLabel(value) }}</span>
            </template>
            <template #item.from_address="{ value }">
              <span class="text-caption">{{ value || '—' }}</span>
            </template>
            <template #item.recipient_email="{ item }">
              <div class="font-weight-medium">
                {{ item.recipient_name || item.recipient_email }}
              </div>
              <div v-if="item.recipient_name" class="text-caption text-medium-emphasis">
                {{ item.recipient_email }}
              </div>
              <div v-if="item.from_address" class="text-caption text-medium-emphasis">
                от {{ item.from_address }}
              </div>
            </template>
            <template #item.subject="{ item }">
              {{ item.subject }}
              <div v-if="item.broadcast_id" class="text-caption text-medium-emphasis">
                <v-icon size="11">mdi-bullhorn-outline</v-icon>
                рассылка {{ item.broadcast_id.substring(0, 8) }}
              </div>
            </template>
            <template #item.opens="{ item }">
              <span v-if="item.opens_count > 0" class="text-info">
                <v-icon size="14">mdi-eye-outline</v-icon> {{ item.opens_count }}
              </span>
              <span v-else class="text-medium-emphasis">—</span>
            </template>
            <template #item.clicks="{ item }">
              <span v-if="item.clicks_count > 0" class="text-primary">
                <v-icon size="14">mdi-cursor-default-click-outline</v-icon> {{ item.clicks_count }}
              </span>
              <span v-else class="text-medium-emphasis">—</span>
            </template>
            <template #item.attempts="{ value }">
              <span :class="value > 1 ? 'text-warning' : ''">{{ value || 0 }}</span>
            </template>
            <template #item.created_at="{ value }">
              <span class="text-caption">{{ fmtDateTime(value) }}</span>
            </template>

            <!-- Развёрнутая строка — все детали диагностики. -->
            <template #expanded-row="{ columns, item }">
              <tr>
                <td :colspan="columns.length" class="pa-4" style="background:rgba(var(--v-theme-surface-variant), 0.4);">
                  <v-row dense>
                    <v-col cols="12" md="6">
                      <div v-if="item.message_id">
                        <div class="text-caption text-medium-emphasis">Message-ID</div>
                        <div class="text-body-2 font-mono">{{ item.message_id }}</div>
                      </div>
                      <div v-if="item.tracking_id" class="mt-2">
                        <div class="text-caption text-medium-emphasis">Tracking ID</div>
                        <div class="text-body-2 font-mono">{{ item.tracking_id }}</div>
                      </div>
                      <div v-if="item.sent_at" class="mt-2">
                        <div class="text-caption text-medium-emphasis">Отправлено в SMTP</div>
                        <div class="text-body-2">{{ fmtDateTime(item.sent_at) }}</div>
                      </div>
                      <div v-if="item.opened_at" class="mt-2">
                        <div class="text-caption text-medium-emphasis">Первое открытие</div>
                        <div class="text-body-2">{{ fmtDateTime(item.opened_at) }} ({{ item.opens_count }} раз)</div>
                      </div>
                      <div v-if="item.clicked_at" class="mt-2">
                        <div class="text-caption text-medium-emphasis">Первый клик</div>
                        <div class="text-body-2">{{ fmtDateTime(item.clicked_at) }} ({{ item.clicks_count }} раз)</div>
                        <div v-if="item.last_click_url" class="text-caption mt-1" style="word-break: break-all;">
                          → <a :href="item.last_click_url" target="_blank" rel="noopener">{{ item.last_click_url }}</a>
                        </div>
                      </div>
                    </v-col>
                    <v-col cols="12" md="6">
                      <div v-if="item.error">
                        <div class="text-caption text-error font-weight-bold">Ошибка отправки</div>
                        <div class="text-body-2 font-mono" style="white-space: pre-wrap; word-break: break-word;">{{ item.error }}</div>
                      </div>
                      <div v-if="item.smtp_response" class="mt-2">
                        <div class="text-caption text-medium-emphasis">Ответ SMTP-сервера</div>
                        <div class="text-body-2 font-mono" style="white-space: pre-wrap; word-break: break-word;">{{ item.smtp_response }}</div>
                      </div>
                      <div v-if="item.bounced_at" class="mt-2">
                        <div class="text-caption text-warning font-weight-bold">Возврат от провайдера получателя</div>
                        <div class="text-body-2">{{ fmtDateTime(item.bounced_at) }}<span v-if="item.bounce_code"> · код {{ item.bounce_code }}</span></div>
                        <div v-if="item.bounce_reason" class="text-body-2 font-mono mt-1" style="white-space: pre-wrap; word-break: break-word;">{{ item.bounce_reason }}</div>
                      </div>
                      <div v-if="!item.error && !item.smtp_response && !item.bounce_reason" class="text-caption text-medium-emphasis">
                        Дополнительной диагностики нет — письмо ушло чисто.
                      </div>
                    </v-col>
                  </v-row>
                </td>
              </tr>
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
          <v-switch v-model="templateForm.is_html" label="HTML-формат (визуальный редактор)" color="primary"
            density="compact" inset hide-details class="mb-3" />

          <div v-if="templateForm.is_html">
            <div class="text-caption text-medium-emphasis mb-1">Тело письма *</div>
            <RichTextEditor v-model="templateForm.body" min-height="260px" ref="templateEditor" />
          </div>
          <v-textarea v-else v-model="templateForm.body" label="Тело письма *"
            variant="outlined" rows="10" auto-grow />
          <div class="d-flex flex-wrap ga-1 mt-2">
            <v-chip v-for="(label, key) in tokens" :key="key"
              size="x-small" variant="outlined" style="cursor:pointer"
              @click="insertTokenIntoTemplate(key)">
              {{ tokenTag(key) }} · {{ label }}
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

    <!-- Mailbox create/edit dialog -->
    <v-dialog v-model="mailboxDialog" max-width="720" persistent>
      <v-card>
        <v-card-title>{{ mailboxForm.id ? 'Редактировать ящик' : 'Новый SMTP-ящик' }}</v-card-title>
        <v-card-text>
          <v-text-field v-model="mailboxForm.name" label="Название ящика *"
            placeholder="Например: Системные уведомления / Маркетинг / Поддержка"
            variant="outlined" density="compact" class="mb-3" />
          <v-row dense>
            <v-col cols="12" sm="8">
              <v-text-field v-model="mailboxForm.host" label="Хост *"
                variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model.number="mailboxForm.port" type="number" label="Порт *"
                variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="mailboxForm.username" label="Логин"
                variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="mailboxForm.password" type="password" label="Пароль"
                :placeholder="mailboxForm.hasPassword ? 'Оставьте пустым, чтобы не менять' : ''"
                variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-select v-model="mailboxForm.encryption" :items="encOptions" label="Шифрование"
                variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="mailboxForm.from_address" label="Email отправителя *"
                variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="mailboxForm.from_name" label="Имя отправителя"
                variant="outlined" density="compact" />
            </v-col>
          </v-row>
          <v-alert v-if="mailboxMsg" type="error" density="compact" class="mt-2">
            {{ mailboxMsg }}
          </v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="mailboxDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="savingMailbox"
            :disabled="!mailboxForm.name || !mailboxForm.host || !mailboxForm.from_address"
            @click="saveMailbox">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Test send dialog (per-mailbox) -->
    <v-dialog v-model="testDialog" max-width="480">
      <v-card>
        <v-card-title>Тестовое письмо</v-card-title>
        <v-card-text>
          <div class="text-body-2 text-medium-emphasis mb-3">
            Ящик: <strong>{{ testTargetMailbox?.name }}</strong>
            ({{ testTargetMailbox?.from_address }})
          </div>
          <v-text-field v-model="testTo" label="Email получателя *" type="email"
            variant="outlined" density="compact" autofocus />
          <v-alert v-if="testMsg" :type="testMsgType" density="compact" class="mt-2">
            {{ testMsg }}
          </v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="testDialog = false">Закрыть</v-btn>
          <v-btn color="primary" :loading="testing" :disabled="!testTo"
            prepend-icon="mdi-email-check" @click="sendTest">Отправить</v-btn>
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
import { useConfirm } from '../../composables/useConfirm';
import { fmtDateTime } from '../../composables/useDesign';
import PageHeader from '../../components/PageHeader.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';

const confirm = useConfirm();
import RichTextEditor from '../../components/RichTextEditor.vue';

const tab = ref('compose');

// ========== MAILBOXES (multi-SMTP) ==========
// Список SMTP-ящиков. Заказчик хотел возможность держать несколько
// ящиков (system / маркетинг / поддержка) и переключаться между ними
// в рассылке. Один помечен is_default=true — используется при тесте
// и при рассылке, если не выбран другой.
const mailboxes = ref([]);
const loadingMailboxes = ref(false);
const mailboxDialog = ref(false);
const savingMailbox = ref(false);
const mailboxMsg = ref('');
const mailboxForm = ref({
  id: null, name: '', host: '', port: 587, username: '', password: '',
  encryption: 'tls', from_address: '', from_name: '',
  hasPassword: false,
});

// Test send dialog: спрашиваем email и шлём с конкретного ящика.
const testDialog = ref(false);
const testTargetMailbox = ref(null);
const testing = ref(false);
const testTo = ref('');
const testMsg = ref('');
const testMsgType = ref('success');

const encOptions = [
  { title: 'TLS', value: 'tls' },
  { title: 'SSL', value: 'ssl' },
  { title: 'Без шифрования', value: 'null' },
];

const mailboxHeaders = [
  { title: 'Название', key: 'name' },
  { title: 'Хост', key: 'host' },
  { title: 'Отправитель', key: 'from_address' },
  { title: 'Шифрование', key: 'encryption', width: 130 },
  { title: '', key: 'is_default', width: 150, sortable: false },
  { title: '', key: 'actions', sortable: false, width: 160 },
];

// Опции для селекта в рассылке: явный «По умолчанию» (null) + все ящики.
const mailboxOptions = computed(() => [
  { id: null, title: 'По умолчанию' },
  ...mailboxes.value.map(m => ({
    id: m.id,
    title: m.name + (m.is_default ? ' (default)' : ''),
  })),
]);

async function loadMailboxes() {
  loadingMailboxes.value = true;
  try {
    const { data } = await api.get('/admin/mail/mailboxes');
    mailboxes.value = data.data || [];
  } catch {}
  loadingMailboxes.value = false;
}

function openMailbox(item) {
  mailboxMsg.value = '';
  mailboxForm.value = item
    ? {
        id: item.id, name: item.name, host: item.host, port: item.port,
        username: item.username || '', password: '',
        encryption: item.encryption ?? 'null',
        from_address: item.from_address, from_name: item.from_name || '',
        hasPassword: !!item.hasPassword,
      }
    : {
        id: null, name: '', host: '', port: 587, username: '', password: '',
        encryption: 'tls', from_address: '', from_name: '',
        hasPassword: false,
      };
  mailboxDialog.value = true;
}

async function saveMailbox() {
  savingMailbox.value = true;
  mailboxMsg.value = '';
  try {
    const payload = {
      name: mailboxForm.value.name,
      host: mailboxForm.value.host,
      port: mailboxForm.value.port,
      username: mailboxForm.value.username,
      password: mailboxForm.value.password,
      encryption: mailboxForm.value.encryption,
      from_address: mailboxForm.value.from_address,
      from_name: mailboxForm.value.from_name,
    };
    if (mailboxForm.value.id) {
      await api.put(`/admin/mail/mailboxes/${mailboxForm.value.id}`, payload);
    } else {
      await api.post('/admin/mail/mailboxes', payload);
    }
    mailboxDialog.value = false;
    await loadMailboxes();
  } catch (e) {
    mailboxMsg.value = e.response?.data?.message || 'Ошибка сохранения';
  }
  savingMailbox.value = false;
}

async function deleteMailbox(item) {
  if (!await confirm.ask({
    title: 'Удалить ящик?',
    message: `«${item.name}» будет удалён. Если это default — default'ом станет другой ящик.`,
    confirmText: 'Удалить', confirmColor: 'error', icon: 'mdi-trash-can',
  })) return;
  try {
    await api.delete(`/admin/mail/mailboxes/${item.id}`);
    await loadMailboxes();
  } catch {}
}

async function setDefaultMailbox(item) {
  try {
    await api.post(`/admin/mail/mailboxes/${item.id}/default`);
    await loadMailboxes();
  } catch {}
}

function openTestDialog(item) {
  testTargetMailbox.value = item;
  testTo.value = '';
  testMsg.value = '';
  testDialog.value = true;
}

async function sendTest() {
  if (!testTo.value || !testTargetMailbox.value) return;
  testing.value = true;
  testMsg.value = '';
  try {
    await api.post('/admin/mail/test', {
      to: testTo.value,
      mailbox_id: testTargetMailbox.value.id,
    });
    testMsg.value = 'Тестовое письмо отправлено';
    testMsgType.value = 'success';
  } catch (e) {
    testMsg.value = e.response?.data?.message || 'Ошибка отправки';
    testMsgType.value = 'error';
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
  mailbox_id: null,   // null = ящик по умолчанию
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
      mailbox_id: compose.value.mailbox_id,
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
const summary = ref({});
const loadingLog = ref(false);
const logExpanded = ref([]);
// deliveryStatus = '' → все; иначе один из pending/sent/delivered/failed/bounced.
const logFilter = ref({ search: '', deliveryStatus: '', mailType: null });

const mailTypeOptions = [
  { title: 'Сброс пароля', value: 'password_reset' },
  { title: 'Рассылка', value: 'broadcast' },
  { title: 'Тест SMTP', value: 'smtp_test' },
  { title: 'Уведомление', value: 'notification' },
];

const logHeaders = [
  { title: 'Статус', key: 'delivery_status', width: 130 },
  { title: 'Дата', key: 'created_at', width: 150 },
  { title: 'Тип', key: 'mail_type', width: 110 },
  { title: 'Кому', key: 'recipient_email' },
  { title: 'Тема', key: 'subject' },
  { title: '👁', key: 'opens', width: 60, sortable: false },
  { title: '🖱', key: 'clicks', width: 60, sortable: false },
  { title: 'Попыток', key: 'attempts', width: 90 },
  { title: '', key: 'data-table-expand', width: 40, sortable: false },
];

const logColumnVisible = ref({
  // По умолчанию скрытые — компактный вид; включаются в ColumnVisibilityMenu.
  mail_type: true,
  opens: true,
  clicks: true,
  attempts: false,
});
const visibleLogHeaders = computed(() =>
  logHeaders.filter(h => logColumnVisible.value[h.key] !== false)
);

const summaryTotal = computed(() =>
  Object.values(summary.value).reduce((a, b) => a + (Number(b) || 0), 0)
);

function deliveryStatusColor(item) {
  const st = item.delivery_status || item.status;
  return ({
    pending: 'grey', sent: 'success', delivered: 'info',
    failed: 'error', bounced: 'warning',
  })[st] || 'grey';
}
function deliveryStatusIcon(item) {
  const st = item.delivery_status || item.status;
  return ({
    pending: 'mdi-clock-outline',
    sent: 'mdi-email-check-outline',
    delivered: 'mdi-email-open-outline',
    failed: 'mdi-alert-circle-outline',
    bounced: 'mdi-keyboard-return',
  })[st] || 'mdi-help-circle-outline';
}
function deliveryStatusLabel(item) {
  const st = item.delivery_status || item.status;
  return ({
    pending: 'В очереди',
    sent: 'Отправлено',
    delivered: 'Открыто',
    failed: 'Ошибка',
    bounced: 'Возврат',
  })[st] || st || '—';
}
function deliveryStatusTooltip(item) {
  const st = item.delivery_status || item.status;
  return ({
    pending: 'Письмо построено, ждёт подтверждения от SMTP-сервера',
    sent: 'SMTP-сервер Yandex принял письмо для доставки',
    delivered: 'Получатель открыл письмо (зафиксировано tracking pixel)',
    failed: 'Письмо не ушло — SMTP-ошибка',
    bounced: 'Провайдер получателя отверг письмо (NDR)',
  })[st] || '';
}
function mailTypeLabel(v) {
  return ({
    password_reset: 'Сброс пароля',
    broadcast: 'Рассылка',
    smtp_test: 'Тест SMTP',
    notification: 'Уведомление',
  })[v] || v || '—';
}

async function loadLog() {
  loadingLog.value = true;
  try {
    const params = {};
    if (logFilter.value.search) params.search = logFilter.value.search;
    if (logFilter.value.deliveryStatus) params.delivery_status = logFilter.value.deliveryStatus;
    if (logFilter.value.mailType) params.mail_type = logFilter.value.mailType;
    const { data } = await api.get('/admin/mail/log', { params });
    log.value = data.data || [];
    summary.value = data.summary || {};
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

const templateColumnVisible = ref({});
const visibleTemplateHeaders = computed(() =>
  templateHeaders.filter(h => templateColumnVisible.value[h.key] !== false)
);

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
  if (!await confirm.ask({
    title: 'Удалить шаблон?',
    message: `«${item.name}» будет удалён без возможности восстановления.`,
    confirmText: 'Удалить', confirmColor: 'error', icon: 'mdi-trash-can',
  })) return;
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

function tokenTag(key) {
  return '{' + '{' + key + '}' + '}';
}

function insertToken(key) {
  compose.value.body += ' ' + tokenTag(key);
}

function insertTokenIntoTemplate(key) {
  templateForm.value.body += ' ' + tokenTag(key);
}

// ========== PROGRESS ==========
const broadcastId = ref(null);
const broadcastTotal = ref(0);
const progress = ref({ sent: 0, failed: 0, bounced: 0, pending: 0 });
let progressTimer = null;

const progressDone = computed(() =>
  (progress.value.sent || 0) + (progress.value.failed || 0) + (progress.value.bounced || 0)
);

const progressPercent = computed(() => {
  if (!broadcastTotal.value) return 0;
  return Math.round((progressDone.value / broadcastTotal.value) * 100);
});

async function pollProgress() {
  if (!broadcastId.value) return;
  try {
    const { data } = await api.get(`/admin/mail/broadcast/${broadcastId.value}/progress`);
    progress.value = {
      sent: data.sent || 0,
      failed: data.failed || 0,
      bounced: data.bounced || 0,
      pending: data.pending || 0,
    };
    if (progressDone.value >= broadcastTotal.value && progress.value.pending === 0) {
      clearInterval(progressTimer);
      progressTimer = null;
      const parts = [`отправлено: ${progress.value.sent}`];
      if (progress.value.failed) parts.push(`ошибки: ${progress.value.failed}`);
      if (progress.value.bounced) parts.push(`возвраты: ${progress.value.bounced}`);
      composeMsg.value = `Рассылка завершена. ${parts.join(', ')}.`;
      composeMsgType.value = (progress.value.failed + progress.value.bounced) > 0 ? 'warning' : 'success';
    }
  } catch {}
}

// ========== LIFECYCLE ==========
watch(tab, (v) => {
  if (v === 'log') loadLog();
  if (v === 'compose') refreshPreview();
  if (v === 'templates') loadTemplates();
  if (v === 'mailboxes') loadMailboxes();
});

onMounted(() => {
  loadMailboxes();
  loadTemplates();
  refreshPreview();
});
</script>

<style scoped>
/* DS polish: section sub-titles inside CRUD-tabs (Кому / Шаблоны / Ящики)
   приведены к ds-title-l из desing/ds-primitives.jsx — единый ритм
   с Profile.vue / ManageProfile.vue. Сами тексты и логика не тронуты. */
:deep(.text-subtitle-1.font-weight-bold) {
  font: var(--ds-type-title-l) !important;
  letter-spacing: -0.01em;
}
</style>
