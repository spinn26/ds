<template>
  <div>
    <PageHeader title="Аудит-лог" icon="mdi-history" :count="total" />

    <v-card class="mb-3 pa-3">
      <div class="d-flex flex-wrap ga-2 align-center">
        <v-text-field v-model="filters.search" placeholder="Поиск: email / IP / entity_id"
          density="compact" variant="outlined" hide-details clearable rounded
          prepend-inner-icon="mdi-magnify" style="max-width: 280px"
          @update:model-value="debounced" />
        <v-select v-model="filters.entity" :items="entityItems" placeholder="Сущность"
          density="compact" variant="outlined" hide-details clearable
          style="max-width: 200px" @update:model-value="reload" />
        <v-select v-model="filters.action" :items="actionItems" placeholder="Действие"
          density="compact" variant="outlined" hide-details clearable
          style="max-width: 200px" @update:model-value="reload" />
        <v-text-field v-model="filters.from" type="date" placeholder="с" density="compact"
          variant="outlined" hide-details style="max-width: 160px" @update:model-value="reload" />
        <v-text-field v-model="filters.to" type="date" placeholder="по" density="compact"
          variant="outlined" hide-details style="max-width: 160px" @update:model-value="reload" />
      </div>
    </v-card>

    <v-card>
      <v-data-table-server :items="rows" :items-length="total" :loading="loading"
        :headers="headers" :items-per-page="perPage" v-model:page="page"
        :items-per-page-options="[25, 50, 100]" density="comfortable" hover
        @update:options="onOptions">
        <template #item.createdAt="{ value }"><span class="text-caption">{{ fmt(value) }}</span></template>
        <template #item.who="{ item }">
          <div v-if="item.userName || item.userEmail">
            <div v-if="item.userName" class="text-body-2">{{ item.userName }}</div>
            <div v-if="item.userEmail" class="text-caption text-medium-emphasis">{{ item.userEmail }}</div>
          </div>
          <span v-else class="text-disabled">—</span>
          <v-chip v-if="item.userRole" size="x-small" variant="tonal" class="ml-1" :color="roleColor(item.userRole)">{{ item.userRole }}</v-chip>
        </template>
        <template #item.action="{ value }">
          <v-chip size="x-small" variant="tonal" :color="actionColor(value)">{{ actionLabel(value) }}</v-chip>
        </template>
        <template #item.entity="{ item }">
          <span>{{ entityLabel(item.entity) }}</span>
          <span v-if="item.subject" class="text-medium-emphasis text-caption"> · {{ item.subject }}</span>
          <span v-if="item.entityId" class="text-medium-emphasis text-caption"> #{{ item.entityId }}</span>
        </template>
        <template #item.description="{ item }">
          <span class="text-caption">{{ describe(item) }}</span>
        </template>
        <template #item.payload="{ item }">
          <v-btn v-if="item.payload" size="x-small" variant="text" @click="show(item)">payload</v-btn>
          <span v-else class="text-disabled">—</span>
        </template>
        <template #no-data><EmptyState message="Записей нет" /></template>
      </v-data-table-server>
    </v-card>

    <v-dialog v-model="dialog" max-width="640">
      <v-card>
        <v-card-title class="text-subtitle-2 d-flex align-center">
          Payload
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" size="small" @click="dialog = false" />
        </v-card-title>
        <v-card-text><pre class="ops-pre">{{ payloadText }}</pre></v-card-text>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, EmptyState } from '../../components';
import { useDebounce } from '../../composables/useDebounce';

const headers = [
  { title: 'Время', key: 'createdAt', width: 150, sortable: false },
  { title: 'Кто', key: 'who', width: 220, sortable: false },
  { title: 'Действие', key: 'action', width: 170, sortable: false },
  { title: 'Сущность', key: 'entity', width: 230, sortable: false },
  { title: 'Описание', key: 'description', sortable: false },
  { title: 'IP', key: 'ip', width: 130, sortable: false },
  { title: '', key: 'payload', width: 80, sortable: false },
];

// Человекочитаемые ярлыки действий
const ACTION_LABELS = {
  login: 'Вход', login_blocked: 'Вход заблокирован', login_2fa_challenge: '2FA-запрос',
  logout: 'Выход', password_reset: 'Сброс пароля', password_change: 'Смена пароля',
  role_change: 'Смена роли', create: 'Создание', update: 'Изменение', delete: 'Удаление',
  restore: 'Восстановление', export: 'Экспорт', import: 'Импорт', impersonate: 'Вход под пользователем',
  block: 'Блокировка', unblock: 'Разблокировка', verify: 'Верификация', settings_update: 'Изменение настроек',
};
const ACTION_COLORS = {
  login: 'success', login_blocked: 'error', login_2fa_challenge: 'info', logout: 'grey',
  password_reset: 'warning', password_change: 'warning', role_change: 'warning',
  create: 'success', update: 'info', delete: 'error', restore: 'success',
  export: 'info', import: 'info', impersonate: 'warning', block: 'error', unblock: 'success',
};
const ENTITY_LABELS = {
  WebUser: 'Пользователь', person: 'Контакт', contract: 'Контракт', client: 'Клиент',
  transaction: 'Транзакция', commission: 'Комиссия', requisites: 'Реквизиты',
  chat_ticket: 'Тикет', product: 'Продукт', system_setting: 'Настройка', webhook: 'Вебхук',
  announcement: 'Объявление', course: 'Курс', test: 'Тест',
};
const ROLE_COLORS = { admin: 'error', calculations: 'warning', head: 'primary', support: 'info', education: 'teal' };
function actionLabel(a) { return ACTION_LABELS[a] || a; }
function actionColor(a) { return ACTION_COLORS[a] || 'default'; }
function entityLabel(e) { return ENTITY_LABELS[e] || e; }
function roleColor(r) { return ROLE_COLORS[r] || 'default'; }

// Короткое описание из payload (что именно изменилось)
function describe(item) {
  const p = item.payload;
  if (!p || typeof p !== 'object') return '';
  if (p.from && p.to) return `${p.from} → ${p.to}`;
  if (p.changes && typeof p.changes === 'object') return Object.keys(p.changes).join(', ');
  if (p.key) return `${p.key}${p.value !== undefined ? ' = ' + p.value : ''}`;
  if (p.email && !item.userEmail) return p.email;
  const keys = Object.keys(p).filter((k) => k !== 'email');
  if (keys.length) return keys.slice(0, 3).map((k) => `${k}: ${shorten(p[k])}`).join('; ');
  return '';
}
function shorten(v) {
  const s = typeof v === 'object' ? JSON.stringify(v) : String(v);
  return s.length > 40 ? s.slice(0, 40) + '…' : s;
}

const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const page = ref(1);
const perPage = ref(25);
const entities = ref([]);
const actions = ref([]);
const dialog = ref(false);
const payloadText = ref('');
const filters = reactive({ search: '', entity: null, action: null, from: '', to: '' });

const entityItems = computed(() => entities.value.map((e) => ({ title: entityLabel(e), value: e })));
const actionItems = computed(() => actions.value.map((a) => ({ title: actionLabel(a), value: a })));

function fmt(s) { if (!s) return '—'; const d = new Date(s); return isNaN(d) ? s : d.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' }); }
function show(item) { payloadText.value = JSON.stringify(item.payload, null, 2); dialog.value = true; }

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/audit-log', {
      params: {
        page: page.value, per_page: perPage.value,
        search: filters.search || undefined, entity: filters.entity || undefined,
        action: filters.action || undefined, from: filters.from || undefined, to: filters.to || undefined,
      },
    });
    rows.value = data.data || [];
    total.value = data.total || 0;
    if (data.entities) entities.value = data.entities;
    if (data.actions) actions.value = data.actions;
  } catch { /* ignore */ }
  loading.value = false;
}
function reload() { page.value = 1; load(); }
function onOptions(opts) { page.value = opts.page; if (opts.itemsPerPage) perPage.value = opts.itemsPerPage; load(); }
const { debounced } = useDebounce(reload, 350);

onMounted(load);
</script>

<style scoped>
.ops-pre { font-size: 12px; line-height: 1.5; white-space: pre-wrap; word-break: break-word; }
</style>
