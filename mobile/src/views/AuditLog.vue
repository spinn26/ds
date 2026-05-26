<template>
  <div>
    <PageHeader title="Журнал действий" back>
      <template #actions>
        <v-btn icon="mdi-refresh" size="small" variant="text" :loading="loading" @click="load" />
      </template>
    </PageHeader>

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>

    <div class="search-bar">
      <v-text-field v-model="search" placeholder="Поиск: действие / сущность / e-mail"
        density="compact" variant="outlined" hide-details rounded clearable
        prepend-inner-icon="mdi-magnify"
        @update:model-value="debouncedLoad" />
    </div>

    <div class="chip-row">
      <v-chip v-for="f in actionFilters" :key="f.value"
        :color="actionFilter === f.value ? 'primary' : undefined"
        :variant="actionFilter === f.value ? 'flat' : 'tonal'"
        size="small" label @click="actionFilter = f.value; load()">
        {{ f.label }}
      </v-chip>
    </div>

    <div v-if="loading" class="text-center py-8">
      <v-progress-circular indeterminate color="primary" size="32" />
    </div>

    <div v-else-if="!items.length" class="empty-state">
      <v-icon size="48">mdi-history</v-icon>
      <div class="empty-state-text">{{ accessDenied ? 'Журнал доступен только администраторам' : 'Записей нет' }}</div>
    </div>

    <div v-else>
      <div v-for="(group, gi) in grouped" :key="gi">
        <div class="day-label">{{ group.label }}</div>
        <div v-for="row in group.items" :key="row.id" class="audit-card" @click="toggle(row.id)">
          <v-avatar size="36" :color="actionColor(row.action)" variant="tonal">
            <v-icon size="18">{{ actionIcon(row.action) }}</v-icon>
          </v-avatar>
          <div class="audit-body">
            <div class="audit-title">
              <strong>{{ actionLabel(row.action) }}</strong>
              <span class="text-medium-emphasis"> · {{ entityLabel(row.entity) }}{{ row.entity_id ? ' #' + row.entity_id : '' }}</span>
            </div>
            <div class="audit-sub">
              <span class="audit-actor">{{ row.user_email || row.user_name || 'Система' }}</span>
              <span class="audit-time">· {{ formatDate(row.created_at) }}</span>
            </div>
            <!-- Сводка изменений в одной строке -->
            <div v-if="summary(row)" class="audit-summary">{{ summary(row) }}</div>
            <!-- Подробности по клику -->
            <div v-if="expanded.has(row.id) && details(row).length" class="audit-details">
              <div v-for="d in details(row)" :key="d.key" class="detail-pair">
                <span class="dp-key">{{ d.key }}</span>
                <span class="dp-val">{{ d.value }}</span>
              </div>
            </div>
          </div>
          <v-icon v-if="details(row).length" size="16" color="grey-lighten-1" class="chev"
            :class="{ rotate: expanded.has(row.id) }">
            mdi-chevron-down
          </v-icon>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

interface AuditRow {
  id: number;
  action: string;
  entity?: string;
  entity_id?: string;
  user_email?: string;
  user_name?: string;
  created_at?: string;
  diff?: any;
  payload?: any;
}

const search = ref('');
const actionFilter = ref<string>('all');
const items = ref<AuditRow[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const accessDenied = ref(false);
const expanded = ref<Set<number>>(new Set());

const actionFilters = [
  { value: 'all', label: 'Все' },
  { value: 'login', label: 'Входы' },
  { value: 'update', label: 'Изменения' },
  { value: 'create', label: 'Создания' },
  { value: 'delete', label: 'Удаления' },
];

let debounceTimer: ReturnType<typeof setTimeout> | null = null;
function debouncedLoad() {
  if (debounceTimer) clearTimeout(debounceTimer);
  debounceTimer = setTimeout(load, 350);
}

// === Переводы / визуальная семантика ===

const ACTION_LABELS: Record<string, string> = {
  login: 'Вход в систему',
  login_2fa_challenge: 'Запрошен код 2FA',
  login_2fa: 'Подтверждение 2FA',
  logout: 'Выход',
  register: 'Регистрация',
  create: 'Создание',
  update: 'Изменение',
  edit: 'Изменение',
  delete: 'Удаление',
  restore: 'Восстановление',
  activate: 'Активация',
  deactivate: 'Деактивация',
  terminate: 'Терминирование',
  exclude: 'Исключение',
  re_register: 'Перерегистрация',
  block: 'Блокировка',
  unblock: 'Разблокировка',
  pay: 'Выплата',
  approve: 'Подтверждение',
  reject: 'Отклонение',
  upload: 'Загрузка файла',
  download: 'Скачивание',
  password_change: 'Смена пароля',
  '2fa_enable': 'Включение 2FA',
  '2fa_disable': 'Отключение 2FA',
};

const ENTITY_LABELS: Record<string, string> = {
  WebUser: 'Пользователь',
  webuser: 'Пользователь',
  consultant: 'Партнёр',
  partner: 'Партнёр',
  client: 'Клиент',
  contract: 'Контракт',
  transaction: 'Транзакция',
  commission: 'Комиссия',
  payment: 'Выплата',
  charge: 'Начисление',
  ticket: 'Тикет чата',
  message: 'Сообщение',
  document: 'Документ',
  requisite: 'Реквизиты',
  product: 'Продукт',
  program: 'Программа',
  qualification: 'Квалификация',
  pool: 'Пул',
  contest: 'Конкурс',
  course: 'Курс',
  lesson: 'Урок',
  role: 'Роль',
  setting: 'Настройка',
  setting_global: 'Системная настройка',
};

const FIELD_LABELS: Record<string, string> = {
  email: 'E-mail',
  phone: 'Телефон',
  password: 'Пароль',
  firstName: 'Имя',
  first_name: 'Имя',
  lastName: 'Фамилия',
  last_name: 'Фамилия',
  patronymic: 'Отчество',
  personName: 'ФИО',
  fullName: 'ФИО',
  consultant: 'Партнёр (id)',
  inviter: 'Наставник (id)',
  client: 'Клиент (id)',
  contract: 'Контракт (id)',
  status: 'Статус',
  active: 'Активен',
  activity: 'Активность',
  role: 'Роль',
  amount: 'Сумма',
  amountRUB: 'Сумма, ₽',
  amountUSD: 'Сумма, $',
  currency: 'Валюта',
  comment: 'Комментарий',
  subject: 'Тема',
  birthDate: 'Дата рождения',
  city: 'Город',
  ip: 'IP-адрес',
  userAgent: 'User Agent',
  user_agent: 'User Agent',
};

function actionLabel(a?: string) {
  if (!a) return '—';
  return ACTION_LABELS[a] || a.charAt(0).toUpperCase() + a.slice(1).replace(/_/g, ' ');
}
function entityLabel(e?: string) {
  if (!e) return '';
  return ENTITY_LABELS[e] || e;
}
function fieldLabel(k: string) {
  return FIELD_LABELS[k] || k;
}
function actionColor(a?: string) {
  if (!a) return 'grey';
  if (/login|logout/i.test(a)) return 'info';
  if (/delete|terminate|exclude|reject|block/i.test(a)) return 'error';
  if (/update|edit|change|password/i.test(a)) return 'warning';
  if (/create|add|register|approve|activate/i.test(a)) return 'success';
  return 'primary';
}
function actionIcon(a?: string) {
  if (!a) return 'mdi-history';
  if (/login/i.test(a)) return 'mdi-login';
  if (/logout/i.test(a)) return 'mdi-logout';
  if (/delete|exclude|terminate/i.test(a)) return 'mdi-trash-can-outline';
  if (/create|add|register/i.test(a)) return 'mdi-plus';
  if (/update|edit|change/i.test(a)) return 'mdi-pencil-outline';
  if (/password/i.test(a)) return 'mdi-key-variant';
  if (/2fa/i.test(a)) return 'mdi-shield-key-outline';
  if (/pay/i.test(a)) return 'mdi-cash';
  if (/block/i.test(a)) return 'mdi-lock';
  if (/unblock/i.test(a)) return 'mdi-lock-open';
  return 'mdi-history';
}

// === Парсинг payload/diff в человекочитаемый вид ===

function getPayload(row: AuditRow): Record<string, any> | null {
  const raw = row.diff || row.payload;
  if (!raw) return null;
  if (typeof raw === 'string') {
    try { return JSON.parse(raw); } catch { return null; }
  }
  return typeof raw === 'object' ? raw : null;
}

function details(row: AuditRow): { key: string; value: string }[] {
  const p = getPayload(row);
  if (!p) return [];
  const out: { key: string; value: string }[] = [];
  for (const k of Object.keys(p)) {
    const v = p[k];
    if (v == null) continue;
    out.push({ key: fieldLabel(k), value: formatValue(k, v) });
  }
  return out;
}

function formatValue(key: string, v: any): string {
  if (key === 'password' || key === 'new_password' || key === 'current_password') return '••••••';
  if (typeof v === 'boolean') return v ? 'Да' : 'Нет';
  if (typeof v === 'number') return v.toLocaleString('ru-RU');
  if (typeof v === 'string') return v;
  if (Array.isArray(v)) return v.length ? v.join(', ') : '—';
  try { return JSON.stringify(v); } catch { return String(v); }
}

function summary(row: AuditRow): string {
  const d = details(row);
  if (!d.length) return '';
  // Берём первое поле и его значение для одной строки превью
  const f = d[0];
  const rest = d.length > 1 ? ` и ещё ${d.length - 1}` : '';
  return `${f.key}: ${truncate(f.value)}${rest}`;
}

function truncate(s: string) {
  return s.length > 40 ? s.slice(0, 40) + '…' : s;
}

function toggle(id: number) {
  if (expanded.value.has(id)) expanded.value.delete(id);
  else expanded.value.add(id);
  // Триггерим реактивность Set'a
  expanded.value = new Set(expanded.value);
}

function formatDate(iso?: string) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return iso;
  return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

const grouped = computed(() => {
  const today = new Date();
  const yest = new Date(today); yest.setDate(yest.getDate() - 1);
  const buckets = [
    { label: 'Сегодня', items: [] as AuditRow[] },
    { label: 'Вчера', items: [] as AuditRow[] },
    { label: 'Ранее', items: [] as AuditRow[] },
  ];
  for (const row of items.value) {
    const d = row.created_at ? new Date(row.created_at) : null;
    if (!d || isNaN(d.getTime())) { buckets[2].items.push(row); continue; }
    if (d.toDateString() === today.toDateString()) buckets[0].items.push(row);
    else if (d.toDateString() === yest.toDateString()) buckets[1].items.push(row);
    else buckets[2].items.push(row);
  }
  return buckets.filter((b) => b.items.length);
});

async function load() {
  loading.value = true;
  error.value = null;
  accessDenied.value = false;
  try {
    const params: Record<string, any> = { page: 1 };
    if (search.value.trim()) params.search = search.value.trim();
    if (actionFilter.value !== 'all') params.action = actionFilter.value;
    const { data } = await api.get('/audit-log', { params });
    items.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
  } catch (e: any) {
    if (e?.response?.status === 403) {
      accessDenied.value = true;
    } else {
      error.value = e?.response?.data?.message || 'Не удалось загрузить';
    }
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.day-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.6px; color: rgba(0,0,0,0.45); margin: 14px 4px 6px; }
.audit-card {
  display: flex; gap: 10px;
  padding: 12px 14px;
  background: #fff;
  border-radius: 14px;
  margin-bottom: 8px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.04);
  cursor: pointer;
  align-items: flex-start;
}
.audit-body { flex: 1; min-width: 0; }
.audit-title {
  font-size: 14px;
  color: #1b1b1b;
  line-height: 1.3;
}
.audit-title strong { font-weight: 700; }
.audit-sub {
  font-size: 11px;
  color: rgba(0,0,0,0.55);
  margin-top: 2px;
  display: flex; gap: 4px; flex-wrap: wrap;
}
.audit-actor { font-weight: 600; color: rgba(0,0,0,0.7); }
.audit-time { white-space: nowrap; }
.audit-summary {
  font-size: 12px;
  color: rgba(0,0,0,0.65);
  margin-top: 6px;
  background: rgba(0,0,0,0.03);
  padding: 4px 8px;
  border-radius: 6px;
  display: inline-block;
  max-width: 100%;
}
.audit-details {
  margin-top: 8px;
  background: rgba(0,0,0,0.02);
  border-radius: 8px;
  padding: 8px 10px;
}
.detail-pair {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 3px 0;
  font-size: 12px;
  border-top: 1px solid rgba(0,0,0,0.04);
}
.detail-pair:first-child { border-top: 0; }
.dp-key { color: rgba(0,0,0,0.55); flex-shrink: 0; }
.dp-val { color: #1b1b1b; font-weight: 500; text-align: right; word-break: break-word; }
.chev { transition: transform 0.15s ease; margin-top: 6px; flex-shrink: 0; }
.chev.rotate { transform: rotate(180deg); }
.empty-state { padding: 60px 24px; text-align: center; }
.empty-state .v-icon { color: rgba(0,0,0,0.2); margin-bottom: 12px; }
.empty-state-text { font-size: 14px; color: rgba(0,0,0,0.5); }
</style>
