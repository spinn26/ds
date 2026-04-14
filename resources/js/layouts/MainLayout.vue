<template>
  <v-layout>
    <!-- Sidebar -->
    <v-navigation-drawer v-model="drawer" :permanent="!mobile" :temporary="mobile" width="260"
      class="sidebar-drawer">
      <div class="sidebar-header d-flex align-center pa-4">
        <v-icon color="primary" size="28" class="mr-2">mdi-cube-outline</v-icon>
        <div>
          <div class="d-flex align-center ga-1">
            <span class="text-h6 font-weight-black text-primary">DS</span>
            <span class="text-caption text-medium-emphasis">ПЛАТФОРМА</span>
          </div>
          <div v-if="cabinetName" class="text-caption" style="font-size: 0.6rem; letter-spacing: 1px; opacity: 0.6; margin-top: -2px">
            {{ cabinetName }}
          </div>
        </div>
      </div>
      <v-divider />

      <v-list density="compact" nav class="px-2">
        <template v-for="(item, i) in visibleMenu" :key="i">
          <v-list-subheader v-if="item.group"
            :class="[item.adminSection ? 'text-secondary font-weight-bold' : '', 'menu-group-header mt-2']">
            {{ item.group }}
          </v-list-subheader>
          <v-list-item v-else :to="item.path" :prepend-icon="item.icon"
            :title="item.label" :active="isActivePath(item.path)"
            :color="item.adminSection ? 'secondary' : 'primary'"
            rounded="lg" class="mb-1 menu-item" @click="mobile && (drawer = false)">
            <template #append v-if="item.path === '/tickets' && unreadCount > 0">
              <v-badge :content="unreadCount" color="error" inline />
            </template>
          </v-list-item>
        </template>
      </v-list>
    </v-navigation-drawer>

    <!-- Top bar -->
    <v-app-bar flat border="b" class="topbar">
      <v-app-bar-nav-icon v-if="mobile" @click="drawer = !drawer" />
      <v-spacer />

      <template v-if="!mobile">
        <!-- Referral link copy button (only for consultants with active status) -->
        <v-btn v-if="isConsultant && statusInfo?.canInvite && statusInfo?.referralCode" size="small" variant="tonal" color="primary"
          class="mr-2" prepend-icon="mdi-link-variant" @click="copyReferral">
          {{ copied ? 'Скопировано!' : 'Реф. ссылка' }}
        </v-btn>

        <!-- Status chip (only for consultants) -->
        <v-chip v-if="isConsultant && statusInfo?.activityName" :color="statusColor" size="small" variant="outlined" class="mr-2">
          {{ statusInfo.activityName }}
        </v-chip>

        <!-- Countdown to status change (consultants only) -->
        <v-chip v-if="isConsultant && statusInfo?.daysRemaining != null && statusInfo.daysRemaining <= 90"
          :color="statusInfo.daysRemaining <= 30 ? 'error' : 'warning'" size="small" variant="tonal" class="mr-2">
          <v-icon start size="14">mdi-timer-outline</v-icon>
          {{ statusInfo.daysRemaining }} дн.
        </v-chip>

        <!-- Admin panel — only for role 'admin' -->
        <v-btn v-if="auth.isAdmin" to="/admin/dashboard" color="secondary" variant="flat" size="small"
          prepend-icon="mdi-shield-crown" class="mr-2">
          Управление
        </v-btn>
      </template>

      <!-- Theme toggle -->
      <v-btn :icon="isDark ? 'mdi-weather-sunny' : 'mdi-weather-night'" size="small" variant="text"
        class="mr-1" @click="toggleTheme" />

      <!-- User name -->
      <span v-if="!mobile" class="text-body-2 text-medium-emphasis mr-2">
        {{ auth.user?.firstName }} {{ auth.user?.lastName }}
      </span>

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
            <div v-if="statusInfo?.activityName" class="mb-3">
              <div class="d-flex align-center ga-2">
                <span class="text-body-2 text-medium-emphasis">Статус</span>
                <v-chip size="x-small" :color="statusColor">{{ statusInfo.activityName }}</v-chip>
              </div>
              <div v-if="statusInfo?.daysRemaining != null" class="text-caption text-medium-emphasis mt-1">
                Смена статуса {{ statusInfo.daysRemaining > 0
                  ? 'через ' + statusInfo.daysRemaining + ' дн.'
                  : 'просрочена' }}
              </div>
            </div>
            <v-divider class="mb-2" />
            <v-list density="compact" nav class="pa-0">
              <v-list-item to="/profile" prepend-icon="mdi-account-outline" title="Профиль"
                rounded="lg" class="mb-1" />
              <v-list-item @click="auth.logout(); $router.push('/login')"
                prepend-icon="mdi-logout" title="Выйти" rounded="lg"
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

    <ChatWidget v-if="isConsultant && !route.path.includes('/tickets')" />
  </v-layout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useDisplay, useTheme } from 'vuetify';
import { useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import api from '../api';
import ChatWidget from '../components/ChatWidget.vue';

const auth = useAuthStore();
const route = useRoute();
const theme = useTheme();
const { mobile } = useDisplay();
const drawer = ref(true);
const copied = ref(false);
const statusInfo = ref(null);

const isDark = computed(() => theme.global.current.value.dark);
const unreadCount = ref(0);
const notifCount = ref(0);
const notifications = ref([]);
let unreadInterval = null;

async function loadUnreadCount() {
  try {
    const { data } = await api.get('/tickets/unread-count');
    unreadCount.value = data.count || 0;
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
  } catch {}
  loadUnreadCount();
  loadNotifications();
  unreadInterval = setInterval(() => { loadUnreadCount(); loadNotifications(); }, 60000);
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

function isActivePath(path) {
  if (!path) return false;
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
  admin: ['calculator', 'structure', 'partners', 'statuses', 'clients', 'contracts', 'upload', 'acceptance', 'requisites', 'transfers', 'transactions', 'import', 'commissions', 'pool', 'qualifications', 'charges', 'payments', 'products', 'contests', 'communication', 'reports', 'currencies'],
  backoffice: ['calculator', 'structure', 'partners', 'statuses', 'clients', 'contracts', 'upload', 'acceptance', 'requisites', 'transfers', 'products', 'contests', 'communication', 'reports'],
  support: ['partners', 'statuses', 'structure', 'clients', 'contracts', 'acceptance', 'products', 'communication', 'calculator'],
  head: ['calculator', 'structure', 'partners', 'statuses', 'clients', 'contracts', 'acceptance', 'transfers', 'products', 'contests', 'communication', 'reports'],
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
  { label: 'Рабочий стол', icon: 'mdi-view-dashboard-outline', path: '/' },
  { label: 'Дашборд', icon: 'mdi-view-dashboard', path: '/dashboard', partner: true },
  { label: 'Отчёт начислений', icon: 'mdi-bank', path: '/finance/report', partner: true },
  { label: 'Калькулятор объёмов', icon: 'mdi-calculator', path: '/finance/calculator', partner: true },
  { label: 'Список моих клиентов', icon: 'mdi-account-group', path: '/clients', partner: true },
  { label: 'Контракты моих клиентов', icon: 'mdi-file-document', path: '/contracts', partner: true },
  { label: 'Контракты моей команды', icon: 'mdi-folder-account', path: '/contracts/team', partner: true },
  { label: 'Структура', icon: 'mdi-sitemap', path: '/structure', partner: true },
  { label: 'Обучение', icon: 'mdi-school', path: '/education', partner: true },
  { label: 'Продукты', icon: 'mdi-package-variant', path: '/products', partner: true },
  { label: 'Список конкурсов', icon: 'mdi-trophy', path: '/contests', partner: true },
  { label: 'Обратная связь', icon: 'mdi-chat', path: '/tickets', partner: true },

  // ---- Staff sections (grouped per spec) ----
  // Инструменты
  { group: 'Инструменты', adminSection: 'calculator' },
  { label: 'Калькулятор объёмов', icon: 'mdi-calculator', path: '/finance/calculator', adminSection: 'calculator' },
  { label: 'Структура', icon: 'mdi-sitemap', path: '/structure', adminSection: 'structure' },

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
  { label: 'Тикеты', icon: 'mdi-ticket-confirmation', path: '/manage/tickets', adminSection: 'communication' },
  { label: 'Отчёты', icon: 'mdi-file-chart', path: '/manage/reports', adminSection: 'reports' },
  { label: 'Валюты и НДС', icon: 'mdi-currency-usd', path: '/manage/currencies', adminSection: 'currencies' },
];

const isConsultant = computed(() => userRoles.value.includes('consultant'));

// Mobile bottom navigation
const bottomNavItems = computed(() => {
  if (isConsultant.value) {
    return [
      { label: 'Главная', icon: 'mdi-view-dashboard-outline', path: '/' },
      { label: 'Клиенты', icon: 'mdi-account-group', path: '/clients' },
      { label: 'Структура', icon: 'mdi-sitemap', path: '/structure' },
      { label: 'Тикеты', icon: 'mdi-chat', path: '/tickets', badge: unreadCount.value > 0 ? unreadCount.value : null },
      { label: 'Профиль', icon: 'mdi-account-circle', path: '/profile' },
    ];
  }
  // Staff bottom nav
  return [
    { label: 'Главная', icon: 'mdi-view-dashboard-outline', path: '/' },
    { label: 'Партнёры', icon: 'mdi-account-search', path: '/manage/partners' },
    { label: 'Тикеты', icon: 'mdi-ticket-confirmation', path: '/manage/tickets', badge: unreadCount.value > 0 ? unreadCount.value : null },
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
  transition: all 0.3s ease;
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
  transition: transform 0.15s ease, background-color 0.2s ease;
}

.menu-item:hover {
  transform: scale(1.02);
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
