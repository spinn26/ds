<template>
  <v-layout>
    <!-- Sidebar -->
    <v-navigation-drawer v-model="drawer" :permanent="!mobile" :temporary="mobile"
      :rail="rail && !mobile" :width="260" :rail-width="72"
      class="sidebar-drawer">
      <div class="sidebar-header d-flex align-center pa-4" :class="{ 'justify-center': rail }">
        <div v-if="!rail" class="flex-grow-1">
          <div class="d-flex align-center ga-1">
            <span class="text-h6 font-weight-black text-primary">DS</span>
            <span class="text-caption text-medium-emphasis">ПЛАТФОРМА</span>
          </div>
          <div v-if="cabinetName" class="text-caption" style="font-size: 0.6rem; letter-spacing: 1px; opacity: 0.6; margin-top: -2px">
            {{ cabinetName }}
          </div>
        </div>
        <v-btn v-if="mobile" icon="mdi-close" size="small" variant="text" density="comfortable"
          @click="drawer = false" />
        <v-btn v-else-if="!rail" icon="mdi-chevron-left" size="x-small" variant="text" density="comfortable"
          @click="toggleRail" />
        <v-btn v-else icon="mdi-chevron-right" size="x-small" variant="text" density="comfortable"
          @click="toggleRail" />
      </div>
      <v-divider />

      <v-list density="compact" nav class="main-nav-list">
        <template v-for="(item, i) in visibleMenu" :key="i">
          <v-list-subheader v-if="item.group && !rail"
            :class="[item.adminSection ? 'text-medium-emphasis font-weight-bold' : '', 'menu-group-header mt-2']">
            {{ item.group }}
          </v-list-subheader>
          <v-divider v-else-if="item.group && rail" class="my-1" />
          <!-- Regular item -->
          <v-list-item v-if="!item.group" :to="item.path || null" :prepend-icon="item.icon"
            :active="isActivePath(item.path)"
            :color="item.adminSection ? 'secondary' : 'primary'"
            :title="item.label"
            class="menu-item" @click="onMenuClick(item)">
            <template #append v-if="!rail">
              <v-badge v-if="item.path === '/chat' && chatUnread > 0" :content="chatUnread" color="error" inline />
              <v-badge v-if="item.path === '/manage/chat' && chatUnread > 0" :content="chatUnread" color="error" inline />
            </template>
          </v-list-item>
        </template>
      </v-list>

      <template #append>
        <v-divider />
        <v-list density="compact" nav class="main-nav-list">
          <v-list-item :prepend-icon="rail ? 'mdi-chevron-right' : 'mdi-chevron-left'"
            :title="rail ? '' : 'Свернуть меню'"
            color="grey" @click="toggleRail" />
        </v-list>
      </template>
    </v-navigation-drawer>

    <!-- Top bar -->
    <v-app-bar flat border="b" class="topbar">
      <v-app-bar-nav-icon v-if="mobile" @click="drawer = !drawer" />

      <v-spacer />

      <template v-if="!mobile">
        <!-- Referral link copy button (only for consultants with active status) -->
        <v-btn v-if="isConsultant && statusInfo?.canInvite && statusInfo?.referralCode" size="small" variant="tonal" color="primary"
          class="mr-2" prepend-icon="mdi-link-variant" :style="{ minWidth: '148px' }"
          @click="copyReferral">
          {{ copied ? 'Скопировано' : 'Реф. ссылка' }}
        </v-btn>

      </template>

      <!-- Theme toggle -->
      <v-btn :icon="isDark ? 'mdi-weather-sunny' : 'mdi-weather-night'" size="small"
        variant="text" class="mr-1" :title="isDark ? 'Светлая тема' : 'Тёмная тема'"
        @click="toggleTheme" />

      <!-- Notifications -->
      <v-menu min-width="360" max-height="480" :close-on-content-click="false">
        <template #activator="{ props }">
          <v-btn v-bind="props" icon size="small" class="mr-1">
            <v-badge v-if="notifCount > 0" :content="notifCount" color="error" floating>
              <v-icon>mdi-bell</v-icon>
            </v-badge>
            <v-icon v-else>mdi-bell-outline</v-icon>
          </v-btn>
        </template>
        <v-card>
          <div class="d-flex justify-space-between align-center pa-3 border-b">
            <span class="text-subtitle-2 font-weight-bold">Уведомления</span>
            <v-btn v-if="notifCount > 0" size="x-small" variant="text" color="primary" @click="markAllNotifRead">
              Прочитать все
            </v-btn>
          </div>
          <v-list v-if="notifications.length" density="compact" class="pa-0" style="max-height: 380px; overflow-y: auto">
            <v-list-item v-for="n in notifications" :key="n.id" :to="n.link || undefined"
              :class="n.read ? '' : 'bg-primary-lighten-5'" class="border-b" @click="markNotifRead(n)">
              <template #prepend>
                <v-avatar size="32" :color="n.color" variant="tonal">
                  <v-icon size="16">{{ n.icon }}</v-icon>
                </v-avatar>
              </template>
              <v-list-item-title class="text-body-2">{{ n.title }}</v-list-item-title>
              <v-list-item-subtitle class="text-caption">{{ n.message }}</v-list-item-subtitle>
              <template #append>
                <div class="text-caption text-medium-emphasis" style="font-size:0.6rem">{{ notifTimeAgo(n.createdAt) }}</div>
              </template>
            </v-list-item>
          </v-list>
          <div v-else class="text-center pa-6 text-medium-emphasis text-caption">
            Нет уведомлений
          </div>
        </v-card>
      </v-menu>

      <!-- User menu -->
      <v-menu min-width="280" :close-on-content-click="false">
        <template #activator="{ props }">
          <v-avatar v-bind="props" :color="auth.isAdmin ? 'secondary' : 'primary'" size="36" class="cursor-pointer ml-1">
            <v-img v-if="auth.user?.avatarUrl" :src="auth.user.avatarUrl" cover />
            <span v-else class="text-caption text-white font-weight-bold">{{ initials }}</span>
          </v-avatar>
        </template>
        <v-card rounded="lg" elevation="8">
          <v-card-text class="pa-4">
            <div class="d-flex align-center ga-3 mb-3">
              <div class="position-relative">
                <v-avatar :color="auth.isAdmin ? 'secondary' : 'primary'" size="56">
                  <v-img v-if="auth.user?.avatarUrl" :src="auth.user.avatarUrl" cover />
                  <span v-else class="text-h5 text-white font-weight-bold">{{ initials }}</span>
                </v-avatar>
                <v-btn icon size="x-small" color="primary" variant="flat"
                  class="position-absolute" style="bottom:-4px;right:-4px"
                  @click="$refs.avatarInput.click()">
                  <v-icon size="14">mdi-camera</v-icon>
                </v-btn>
                <input ref="avatarInput" type="file" accept="image/*" hidden @change="uploadAvatar" />
              </div>
              <div>
                <div class="text-subtitle-1 font-weight-bold">
                  {{ auth.user?.lastName }} {{ auth.user?.firstName }}
                </div>
                <div class="text-caption text-medium-emphasis">{{ auth.user?.email }}</div>
              </div>
            </div>
            <div v-if="isConsultant && statusInfo?.activityName" class="mb-3">
              <div class="d-flex align-center ga-2 flex-wrap">
                <span class="text-body-2 text-medium-emphasis">Статус</span>
                <v-chip size="x-small" :color="statusColor">
                  {{ statusInfo.activityName }}
                  <template v-if="statusInfo.yearPeriodEnd"> до {{ fmtShortDate(statusInfo.yearPeriodEnd) }}</template>
                </v-chip>
                <v-chip v-if="statusInfo?.daysRemaining != null && statusInfo.daysRemaining <= 90"
                  size="x-small" variant="tonal"
                  :color="statusInfo.daysRemaining <= 30 ? 'error' : 'warning'">
                  <v-icon start size="14">mdi-timer-outline</v-icon>
                  {{ statusInfo.daysRemaining }} дн.
                </v-chip>
              </div>
              <div v-if="statusInfo?.daysRemaining != null" class="text-caption text-medium-emphasis mt-1">
                Смена статуса {{ statusInfo.daysRemaining > 0
                  ? 'через ' + statusInfo.daysRemaining + ' дн.'
                  : 'просрочена' }}
              </div>
            </div>
            <v-divider class="mb-2" />
            <v-list density="compact" nav class="pa-0">
              <v-list-item to="/profile" prepend-icon="mdi-account-outline" title="Профиль" class="mb-1" />
              <v-list-item v-if="auth.isAdmin" to="/admin/dashboard"
                prepend-icon="mdi-shield-crown" title="Панель управления" class="mb-1" />
              <v-list-item @click="auth.logout(); $router.push('/login')"
                prepend-icon="mdi-logout" title="Выйти"
                base-color="error" />
            </v-list>
          </v-card-text>
        </v-card>
      </v-menu>
    </v-app-bar>

    <!-- Content -->
    <v-main class="content-main">
      <v-container fluid class="pa-4 pa-md-6">
        <router-view />
      </v-container>
    </v-main>

    <!-- Mobile bottom navigation -->
    <v-bottom-navigation v-if="mobile" :model-value="activeBottomNav" grow class="mobile-bottom-nav">
      <v-btn v-for="item in bottomNavItems" :key="item.key || item.path"
        :to="item.action ? undefined : item.path" :value="item.path"
        @click="item.action ? item.action() : null">
        <v-badge v-if="item.badge" :content="item.badge" color="error" floating>
          <v-icon>{{ item.icon }}</v-icon>
        </v-badge>
        <v-icon v-else>{{ item.icon }}</v-icon>
        <span class="text-caption">{{ item.label }}</span>
      </v-btn>
    </v-bottom-navigation>

    <!-- Quick-message dialog: "Написать основателю" / "Оставить кейс" from the menu -->
    <v-dialog v-model="quickMsg.open" max-width="560" :persistent="quickMsg.sending">
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="primary">{{ quickMsg.icon }}</v-icon>
          {{ quickMsg.subject }}
        </v-card-title>
        <v-card-text>
          <v-textarea v-model="quickMsg.message" label="Ваше сообщение"
            rows="6" auto-grow counter maxlength="5000" autofocus
            :disabled="quickMsg.sending" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="quickMsg.sending" @click="quickMsg.open = false">Отмена</v-btn>
          <v-btn color="primary" variant="flat"
            :loading="quickMsg.sending"
            :disabled="!quickMsg.message.trim()"
            @click="submitQuickMsg">
            Отправить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Onboarding questionnaire — required for consultants before anything else -->
    <OnboardingQuestionnaire
      v-model="showQuestionnaire"
      :identity-name="questionnaireIdentity.name"
      :identity-city="questionnaireIdentity.city"
      @completed="onQuestionnaireCompleted"
    />

  </v-layout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { useDisplay, useTheme } from 'vuetify';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useSnackbar } from '../composables/useSnackbar';
import OnboardingQuestionnaire from '../components/OnboardingQuestionnaire.vue';
import api from '../api';
function fmtShortDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const theme = useTheme();
const { mobile } = useDisplay();
const drawer = ref(true);

// Rail (minimalist) sidebar — persists across sessions
const rail = ref(localStorage.getItem('main-nav-rail') === '1');
function toggleRail() {
  rail.value = !rail.value;
  localStorage.setItem('main-nav-rail', rail.value ? '1' : '0');
}
const copied = ref(false);
const statusInfo = ref(null);

const isDark = computed(() => theme.global.current.value.dark);
const notifCount = ref(0);
const notifications = ref([]);
let unreadInterval = null;

const chatUnread = ref(0);

async function loadChatUnread() {
  try {
    const { data } = await api.get('/chat/unread-count');
    chatUnread.value = data.count || 0;
  } catch {}
}

async function loadNotifications() {
  try {
    const [listRes, countRes] = await Promise.all([
      api.get('/notifications'),
      api.get('/notifications/unread-count'),
    ]);
    notifications.value = listRes.data || [];
    notifCount.value = countRes.data.count || 0;
  } catch {}
}

async function markNotifRead(n) {
  if (!n.read) {
    try { await api.post(`/notifications/${n.id}/read`); } catch {}
    n.read = true;
    notifCount.value = Math.max(0, notifCount.value - 1);
  }
}

async function markAllNotifRead() {
  try { await api.post('/notifications/read-all'); } catch {}
  notifications.value.forEach(n => n.read = true);
  notifCount.value = 0;
}

function notifTimeAgo(d) {
  if (!d) return '';
  const diff = Math.floor((Date.now() - new Date(d).getTime()) / 1000);
  if (diff < 60) return 'сейчас';
  if (diff < 3600) return `${Math.floor(diff / 60)}м`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}ч`;
  return `${Math.floor(diff / 86400)}д`;
}

function toggleTheme() {
  const newTheme = isDark.value ? 'light' : 'dark';
  theme.global.name.value = newTheme;
  localStorage.setItem('theme', newTheme);
}

const initials = computed(() =>
  `${auth.user?.firstName?.[0] || ''}${auth.user?.lastName?.[0] || ''}`.toUpperCase()
);

// Onboarding questionnaire — shown to consultants who haven't filled it yet.
// Block navigation via router guard below (persistent dialog already blocks UI).
const showQuestionnaire = ref(false);
const questionnaireIdentity = ref({ name: '', city: '' });

async function onQuestionnaireCompleted() {
  if (auth.user) auth.user.questionnaireCompleted = true;
  // Refresh user so role upgrade (registered → registered,consultant) is picked up
  // and the partner menu items become visible without a page reload.
  await auth.fetchUser();
}

// Load status info for TopBar
onMounted(async () => {
  try {
    const { data } = await api.get('/profile');
    statusInfo.value = {
      ...data.statusInfo,
      referralCode: data.referral?.referralCode,
      referralLink: data.referral?.referralLink,
      canInvite: data.referral?.canInvite,
    };
    // Prefill identity fields for the onboarding questionnaire
    const u = data.user || {};
    const fullName = [u.lastName, u.firstName, u.patronymic].filter(Boolean).join(' ');
    questionnaireIdentity.value = {
      name: fullName,
      city: data.location?.city || '',
    };
    // Show the onboarding dialog for any non-staff user without a filled questionnaire.
    // This covers both 'registered' (right after sign-up) and 'consultant' roles.
    if (!isStaff.value && auth.user && auth.user.questionnaireCompleted === false) {
      showQuestionnaire.value = true;
    }
  } catch {}
  loadNotifications();
  loadChatUnread();

  // Polling с учётом visibility — если вкладка скрыта/свёрнута, таймер
  // не работает. При возврате в видимое состояние — сразу дёргаем
  // свежие данные и перезапускаем интервал. Это фиксит зависание UI
  // после 5+ минут idle (DB pool / PHP-FPM / Socket засыпают, первый
  // запрос уходит в таймаут).
  const POLL_MS = 30000;
  const startPolling = () => {
    if (unreadInterval) clearInterval(unreadInterval);
    unreadInterval = setInterval(() => {
      if (document.visibilityState !== 'visible') return;
      loadNotifications();
      loadChatUnread();
    }, POLL_MS);
  };
  startPolling();

  let lastHiddenAt = null;
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      // Если пауза > 60 сек — принудительно перезапрашиваем всё сразу
      // и реконнектим сокет (если он отвалился при спящей вкладке).
      const paused = lastHiddenAt ? (Date.now() - lastHiddenAt) : 0;
      lastHiddenAt = null;
      if (paused > 60000) {
        loadNotifications();
        loadChatUnread();
        if (window.__notifSocket && !window.__notifSocket.connected) {
          try { window.__notifSocket.connect(); } catch {}
        }
      }
    } else {
      lastHiddenAt = Date.now();
    }
  });

  // Real-time notifications via Socket.IO
  try {
    const { io } = await import('socket.io-client');
    // Priority: explicit override -> local dev on :3001 -> same-origin (nginx proxy on prod)
    const isLocal = ['localhost', '127.0.0.1'].includes(location.hostname);
    const defaultHost = isLocal
      ? `ws://${location.hostname}:3001`
      : `${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}`;
    const host = window.__SOCKET_URL__ || defaultHost;
    const token = auth.token;
    if (!token) return;
    const notifSocket = io(host, {
      auth: { token },
      transports: ['websocket', 'polling'],
      reconnection: true,
      reconnectionDelay: 2000,
      reconnectionAttempts: Infinity,
    });
    window.__notifSocket = notifSocket;
    notifSocket.on('notification', (data) => {
      // Add to list in real-time
      notifications.value.unshift({
        id: Date.now(),
        title: data.title || 'Уведомление',
        message: data.message || '',
        icon: data.icon || 'mdi-bell',
        color: data.color || 'primary',
        link: data.link,
        read: false,
        createdAt: new Date().toISOString(),
      });
      notifCount.value++;
    });
  } catch {}
});

onUnmounted(() => {
  if (unreadInterval) clearInterval(unreadInterval);
});

const statusColor = computed(() => {
  const id = statusInfo.value?.activityId;
  if (id === 1) return 'success';   // Активен
  if (id === 4) return 'info';      // Зарегистрирован
  if (id === 3) return 'warning';   // Терминирован
  if (id === 5) return 'error';     // Исключен
  return 'default';
});

async function uploadAvatar(event) {
  const file = event.target.files?.[0];
  if (!file) return;
  const formData = new FormData();
  formData.append('avatar', file);
  try {
    const { data } = await api.post('/profile/avatar', formData);
    if (data.avatarUrl) {
      auth.user.avatarUrl = data.avatarUrl;
    }
  } catch {}
}

function copyReferral() {
  if (statusInfo.value?.referralLink) {
    navigator.clipboard.writeText(statusInfo.value.referralLink);
    copied.value = true;
    setTimeout(() => copied.value = false, 2000);
  }
}

function onMenuClick(item) {
  if (mobile.value) drawer.value = false;
  if (typeof item.action === 'function') {
    item.action();
    return;
  }
  // For query-based routes (like /tickets?to=owner), force navigation even if already on /tickets
  if (item.path && item.path.includes('?')) {
    const [path, qs] = item.path.split('?');
    const params = Object.fromEntries(new URLSearchParams(qs));
    router.push({ path, query: params });
  }
}

// Quick-message dialog (founder / case)
const { showSuccess, showError } = useSnackbar();
const quickMsg = ref({ open: false, subject: '', icon: 'mdi-email-edit', message: '', sending: false });

function openQuickMsg(subject, icon = 'mdi-email-edit') {
  quickMsg.value = { open: true, subject, icon, message: '', sending: false };
}

async function submitQuickMsg() {
  const msg = quickMsg.value.message.trim();
  if (!msg) return;
  quickMsg.value.sending = true;
  try {
    await api.post('/chat/tickets', {
      subject: quickMsg.value.subject,
      department: 'general',
      priority: 'medium',
      message: msg,
    });
    quickMsg.value.open = false;
    showSuccess('Сообщение отправлено');
    loadChatUnread();
  } catch (e) {
    showError(e?.response?.data?.message || 'Не удалось отправить сообщение');
  } finally {
    quickMsg.value.sending = false;
  }
}

function isActivePath(path) {
  if (!path) return false;
  // Exact match including query params
  if (path.includes('?')) {
    return route.fullPath === path;
  }
  return route.path === path;
}

// Parse user roles
const userRoles = computed(() => {
  const role = auth.user?.role || '';
  return role.split(',').map(r => r.trim()).filter(Boolean);
});

const isStaff = computed(() =>
  userRoles.value.some(r => ['admin', 'backoffice', 'support', 'finance', 'head', 'calculations', 'corrections'].includes(r))
);

// Cabinet sections per role — exact mapping from spec docs
const cabinetSections = {
  admin: ['calculator', 'structure', 'partners', 'statuses', 'clients', 'contracts', 'upload', 'acceptance', 'requisites', 'transfers', 'transactions', 'import', 'commissions', 'pool', 'qualifications', 'charges', 'payments', 'products', 'contests', 'communication', 'chat-analytics', 'reports', 'currencies'],
  backoffice: ['calculator', 'structure', 'partners', 'statuses', 'clients', 'contracts', 'upload', 'acceptance', 'requisites', 'transfers', 'products', 'contests', 'communication', 'chat-analytics', 'reports'],
  support: ['partners', 'statuses', 'structure', 'clients', 'contracts', 'acceptance', 'products', 'communication', 'calculator'],
  head: ['calculator', 'structure', 'partners', 'statuses', 'clients', 'contracts', 'acceptance', 'transfers', 'products', 'contests', 'communication', 'chat-analytics', 'reports', 'owner-dashboard', 'reconciliation', 'anomalies', 'funnel', 'cohorts'],
  finance: ['calculator', 'requisites', 'charges', 'payments', 'reports', 'communication'],
  calculations: ['calculator', 'commissions', 'qualifications', 'pool', 'transactions', 'import', 'products', 'reports', 'currencies'],
  corrections: ['calculator', 'clients', 'contracts', 'partners'],
};

const availableSections = computed(() => {
  const sections = new Set();
  for (const role of userRoles.value) {
    const s = cabinetSections[role];
    if (s) s.forEach(sec => sections.add(sec));
  }
  return sections;
});

const cabinetName = computed(() => {
  if (userRoles.value.includes('admin')) return 'Администратор';
  if (userRoles.value.includes('backoffice')) return 'Кабинет БЭК';
  if (userRoles.value.includes('support')) return 'Техподдержка';
  if (userRoles.value.includes('head')) return 'Руководитель';
  if (userRoles.value.includes('finance')) return 'Фин. менеджер';
  if (userRoles.value.includes('calculations')) return 'Расчёты';
  if (userRoles.value.includes('corrections')) return 'Правки';
  return null;
});

// === MENU ITEMS ===
// Partner menu — exact per spec (role: consultant)
// Staff sections — grouped per spec, no education editing
const menuItems = [
  // ---- Partner menu (consultant) ----
  // Shown to everyone (partner and staff) — leads to Workspace
  { label: 'Главная', icon: 'mdi-home-outline', path: '/' },

  { group: 'Обзор', partner: true },
  { label: 'Дашборд', icon: 'mdi-view-dashboard-outline', path: '/dashboard', partner: true },
  { label: 'Отчёт начислений', icon: 'mdi-bank-outline', path: '/finance/report', partner: true },

  { group: 'Работа', partner: true },
  { label: 'Калькулятор объёмов', icon: 'mdi-calculator', path: '/finance/calculator', partner: true },
  { label: 'Мои клиенты', icon: 'mdi-account-group-outline', path: '/clients', partner: true },
  { label: 'Контракты клиентов', icon: 'mdi-file-document-outline', path: '/contracts', partner: true },
  { label: 'Контракты команды', icon: 'mdi-folder-account-outline', path: '/contracts/team', partner: true },
  { label: 'Структура', icon: 'mdi-sitemap-outline', path: '/structure', partner: true },

  { group: 'Развитие', partner: true },
  { label: 'Обучение', icon: 'mdi-school-outline', path: '/education', partner: true },
  { label: 'Продукты', icon: 'mdi-package-variant-closed', path: '/products', partner: true },
  { label: 'Конкурсы и события', icon: 'mdi-trophy-outline', path: '/contests', partner: true },

  { group: 'Связь', partner: true },
  { label: 'Обратная связь', icon: 'mdi-chat-outline', path: '/chat', partner: true },
  { label: 'Написать основателю', icon: 'mdi-email-edit-outline', path: '', partner: true, action: () => openQuickMsg('Сообщение основателю', 'mdi-email-edit-outline') },
  { label: 'Оставить кейс', icon: 'mdi-briefcase-plus-outline', path: '', partner: true, action: () => openQuickMsg('Кейс', 'mdi-briefcase-plus-outline') },

  // ---- Staff sections (grouped per spec) ----
  // Инструменты
  { group: 'Инструменты', adminSection: 'calculator' },
  { label: 'Калькулятор объёмов', icon: 'mdi-calculator', path: '/finance/calculator', adminSection: 'calculator' },
  { label: 'Структура', icon: 'mdi-sitemap', path: '/structure', adminSection: 'structure' },

  // Staff workspace (staff-only)
  { label: 'Рабочий стол', icon: 'mdi-view-dashboard-variant', path: '/manage/workspace', adminSection: 'workspace', staffOnly: true },

  // Данные
  { group: 'Данные', adminSection: 'partners' },
  { label: 'Партнёры', icon: 'mdi-account-search', path: '/manage/partners', adminSection: 'partners' },
  { label: 'Статусы партнёров', icon: 'mdi-calendar-clock', path: '/manage/partners/statuses', adminSection: 'statuses' },
  { label: 'Клиенты', icon: 'mdi-account-group', path: '/manage/clients', adminSection: 'clients' },
  { label: 'Менеджер контрактов', icon: 'mdi-file-document-edit', path: '/manage/contracts', adminSection: 'contracts' },
  { label: 'Загрузка контрактов', icon: 'mdi-upload', path: '/manage/contracts/upload', adminSection: 'upload' },
  { label: 'Акцепт документов', icon: 'mdi-check-circle', path: '/manage/acceptance', adminSection: 'acceptance' },
  { label: 'Реквизиты', icon: 'mdi-credit-card', path: '/manage/requisites', adminSection: 'requisites' },
  { label: 'Перестановки', icon: 'mdi-history', path: '/manage/transfers', adminSection: 'transfers' },

  // Финансы
  { group: 'Финансы', adminSection: 'import' },
  { label: 'Импорт транзакций', icon: 'mdi-upload', path: '/manage/transactions/import', adminSection: 'import' },
  { label: 'Ручной ввод транзакций', icon: 'mdi-cash-plus', path: '/manage/transactions/manual', adminSection: 'import' },
  { label: 'Транзакции', icon: 'mdi-swap-horizontal', path: '/manage/transactions', adminSection: 'transactions' },
  { label: 'Комиссии', icon: 'mdi-receipt', path: '/manage/commissions', adminSection: 'commissions' },
  { label: 'Пул', icon: 'mdi-cash-multiple', path: '/manage/pool', adminSection: 'pool' },
  { label: 'Квалификации', icon: 'mdi-chart-bar', path: '/manage/qualifications', adminSection: 'qualifications' },

  // Выплаты
  { group: 'Выплаты', adminSection: 'charges' },
  { label: 'Начисления', icon: 'mdi-bank', path: '/manage/charges', adminSection: 'charges' },
  { label: 'Выплаты', icon: 'mdi-cash', path: '/manage/payments', adminSection: 'payments' },

  // Прочее
  { group: 'Прочее', adminSection: 'products' },
  { label: 'Продукты', icon: 'mdi-package-variant-closed', path: '/manage/products', adminSection: 'products' },
  { label: 'Конкурсы', icon: 'mdi-trophy', path: '/manage/contests', adminSection: 'contests' },
  { label: 'Чат / Тикеты', icon: 'mdi-chat-processing', path: '/manage/chat', adminSection: 'communication' },
  { label: 'Аналитика чата', icon: 'mdi-chart-box-outline', path: '/manage/chat/analytics', adminSection: 'chat-analytics' },
  { label: 'Отчёты', icon: 'mdi-file-chart', path: '/manage/reports', adminSection: 'reports' },
  { label: 'Валюты и НДС', icon: 'mdi-currency-usd', path: '/manage/currencies', adminSection: 'currencies' },

  // Аналитика — для руководителя / админа
  { group: 'Аналитика', adminSection: 'owner-dashboard' },
  { label: 'Дашборд руководителя', icon: 'mdi-crown', path: '/manage/owner-dashboard', adminSection: 'owner-dashboard' },
  { label: 'Реконсиляция', icon: 'mdi-scale-balance', path: '/manage/reconciliation', adminSection: 'reconciliation' },
  { label: 'Аномалии', icon: 'mdi-alert-decagram', path: '/manage/anomalies', adminSection: 'anomalies' },
  { label: 'Воронка партнёров', icon: 'mdi-filter-variant', path: '/manage/funnel', adminSection: 'funnel' },
  { label: 'Когорты', icon: 'mdi-chart-line', path: '/manage/cohorts', adminSection: 'cohorts' },
];

const isConsultant = computed(() => userRoles.value.includes('consultant'));

// Mobile bottom navigation
const bottomNavItems = computed(() => {
  if (isConsultant.value) {
    return [
      { label: 'Главная', icon: 'mdi-view-dashboard-outline', path: '/' },
      { label: 'Клиенты', icon: 'mdi-account-group', path: '/clients' },
      { label: 'Структура', icon: 'mdi-sitemap', path: '/structure' },
      { label: 'Продукты', icon: 'mdi-package-variant', path: '/products' },
      { label: 'Профиль', icon: 'mdi-account-circle', path: '/profile' },
    ];
  }
  // Staff bottom nav
  return [
    { label: 'Главная', icon: 'mdi-view-dashboard-outline', path: '/' },
    { label: 'Партнёры', icon: 'mdi-account-search', path: '/manage/partners' },
    { label: 'Отчёты', icon: 'mdi-file-chart', path: '/manage/reports' },
    { key: 'more', label: 'Ещё', icon: 'mdi-menu', path: '', action: () => { drawer.value = !drawer.value; } },
  ];
});

const activeBottomNav = computed(() => {
  return bottomNavItems.value.find(i => i.path && route.path === i.path)?.path || '';
});

const visibleMenu = computed(() => menuItems.filter((item) => {
  if (item.adminSection) return isStaff.value && availableSections.value.has(item.adminSection);
  if (item.partner) return isConsultant.value;
  return true;
}));
</script>

<style scoped>
.sidebar-drawer {
  background: linear-gradient(180deg, rgba(var(--v-theme-surface), 1) 0%, rgba(var(--v-theme-surface), 0.97) 100%) !important;
  box-shadow: 2px 0 12px rgba(0, 0, 0, 0.06);
  /* Avoid transitioning `transform` — Vuetify owns the slide-in animation
     for the temporary (mobile) drawer; a custom transition breaks it. */
  transition: background-color 0.3s ease, box-shadow 0.3s ease;
}

.sidebar-header {
  min-height: 56px;
}

.topbar {
  backdrop-filter: blur(12px);
  background: rgba(var(--v-theme-surface), 0.85) !important;
}

.content-main {
  background: rgba(var(--v-theme-background), 1);
}

.menu-item {
  transition: background-color 0.15s ease;
  border-radius: 0 !important;
}

.menu-item:hover {
  background-color: rgba(var(--v-theme-primary), 0.06);
}

/* Square-edge items in the sidebar — no Vuetify rounding */
.sidebar-drawer :deep(.v-list-item),
.sidebar-drawer :deep(.v-list),
.sidebar-drawer :deep(.v-navigation-drawer__content),
.sidebar-drawer :deep(.v-list-item__overlay),
.sidebar-drawer :deep(.v-list-item__underlay),
.sidebar-drawer :deep(.v-list-item::before),
.sidebar-drawer :deep(.v-list-item::after) {
  border-radius: 0 !important;
}
.sidebar-drawer :deep(.v-navigation-drawer) {
  border-radius: 0 !important;
}
.main-nav-list :deep(.v-list-item),
.main-nav-list :deep(.v-list-item__overlay) {
  border-radius: 0 !important;
}

/* Rail mode: compress header to the brand mark alone */
.sidebar-drawer :deep(.v-navigation-drawer--rail) .sidebar-header {
  padding: 16px 0 !important;
}
.sidebar-drawer :deep(.v-navigation-drawer--rail) .brand-mark {
  margin-right: 0 !important;
}

.topbar-brand {
  text-decoration: none;
  opacity: 0.85;
  transition: opacity 0.15s ease;
}

.topbar-brand:hover {
  opacity: 1;
}

.brand-mark {
  width: 32px;
  height: 32px;
  overflow: hidden;
  flex-shrink: 0;
  box-shadow: 0 0 0 2px rgba(var(--v-theme-brand), 0.25);
}
.brand-mark-sm {
  width: 24px;
  height: 24px;
  box-shadow: 0 0 0 1.5px rgba(var(--v-theme-brand), 0.3);
}

.menu-group-header {
  letter-spacing: 0.5px;
  font-size: 0.7rem;
  text-transform: uppercase;
  opacity: 0.7;
}

/* Mobile bottom navigation */
.mobile-bottom-nav {
  position: fixed !important;
  bottom: 0;
  left: 0;
  right: 0;
  z-index: 1000;
  backdrop-filter: blur(16px);
  background: rgba(var(--v-theme-surface), 0.92) !important;
  border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  padding-bottom: env(safe-area-inset-bottom, 0px);
  height: calc(56px + env(safe-area-inset-bottom, 0px)) !important;
}

.mobile-bottom-nav .v-btn {
  min-width: 0 !important;
  font-size: 0.6rem !important;
}

.mobile-bottom-nav .v-btn .text-caption {
  font-size: 0.6rem !important;
  margin-top: 2px;
}

/* Add bottom padding to main content on mobile so it doesn't hide behind bottom nav */
@media (max-width: 959px) {
  .content-main {
    padding-bottom: calc(56px + env(safe-area-inset-bottom, 0px)) !important;
  }
}
</style>
