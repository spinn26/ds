<template>
  <v-layout>
    <!-- Sidebar -->
    <v-navigation-drawer v-model="drawer" :permanent="!mobile" :temporary="mobile" width="260"
      class="sidebar-drawer">
      <div class="sidebar-header d-flex align-center pa-4">
        <v-icon color="primary" size="28" class="mr-2">mdi-cube-outline</v-icon>
        <span class="text-h6 font-weight-black text-primary">DS</span>
        <span class="text-caption text-medium-emphasis ml-2">ПЛАТФОРМА</span>
        <v-icon v-if="isStaff" color="secondary" size="small" class="ml-1">mdi-shield-crown</v-icon>
      </div>
      <v-chip v-if="cabinetName" size="x-small" color="primary" variant="outlined" class="mx-4 mb-2">{{ cabinetName }}</v-chip>
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
            rounded="lg" class="mb-1 menu-item" @click="mobile && (drawer = false)" />
        </template>
      </v-list>
    </v-navigation-drawer>

    <!-- Top bar -->
    <v-app-bar flat border="b" class="topbar">
      <v-app-bar-nav-icon v-if="mobile" @click="drawer = !drawer" />
      <v-spacer />

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

      <!-- Admin button — only for role 'admin' -->
      <v-btn v-if="auth.isAdmin" to="/admin/users" color="secondary" variant="flat" size="small"
        prepend-icon="mdi-account-cog" class="mr-2">
        Пользователи
      </v-btn>

      <!-- Theme toggle -->
      <v-btn :icon="isDark ? 'mdi-weather-sunny' : 'mdi-weather-night'" size="small" variant="text"
        class="mr-1" @click="toggleTheme" />

      <!-- User name -->
      <span v-if="!mobile" class="text-body-2 text-medium-emphasis mr-2">
        {{ auth.user?.firstName }} {{ auth.user?.lastName }}
      </span>

      <!-- Notifications -->
      <v-btn icon="mdi-bell-outline" size="small" class="mr-1" />

      <!-- User menu -->
      <v-menu>
        <template #activator="{ props }">
          <v-avatar v-bind="props" :color="auth.isAdmin ? 'secondary' : 'primary'" size="32" class="cursor-pointer">
            <span class="text-caption text-white">{{ initials }}</span>
          </v-avatar>
        </template>
        <v-list density="compact">
          <v-list-item to="/profile" prepend-icon="mdi-account" title="Профиль" />
          <v-list-item @click="auth.logout(); $router.push('/login')" prepend-icon="mdi-logout" title="Выйти" />
        </v-list>
      </v-menu>
    </v-app-bar>

    <!-- Content -->
    <v-main class="content-main">
      <v-container fluid class="pa-4 pa-md-6">
        <router-view />
      </v-container>
    </v-main>
  </v-layout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useDisplay, useTheme } from 'vuetify';
import { useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import api from '../api';

const auth = useAuthStore();
const route = useRoute();
const theme = useTheme();
const { mobile } = useDisplay();
const drawer = ref(true);
const copied = ref(false);
const statusInfo = ref(null);

const isDark = computed(() => theme.global.current.value.dark);

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
});

const statusColor = computed(() => {
  const id = statusInfo.value?.activityId;
  if (id === 1) return 'success';   // Активен
  if (id === 4) return 'info';      // Зарегистрирован
  if (id === 3) return 'warning';   // Терминирован
  if (id === 5) return 'error';     // Исключен
  return 'default';
});

function copyReferral() {
  if (statusInfo.value?.referralLink) {
    navigator.clipboard.writeText(statusInfo.value.referralLink);
    copied.value = true;
    setTimeout(() => copied.value = false, 2000);
  }
}

function isActivePath(path) {
  if (path === '/') return route.path === '/';
  return route.path.startsWith(path);
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
  { label: 'Дашборд', icon: 'mdi-view-dashboard', path: '/', partner: true },
  { label: 'Отчёт начислений', icon: 'mdi-bank', path: '/finance/report', partner: true },
  { label: 'Калькулятор объёмов', icon: 'mdi-calculator', path: '/finance/calculator', partner: true },
  { label: 'Список моих клиентов', icon: 'mdi-account-group', path: '/clients', partner: true },
  { label: 'Контракты моих клиентов', icon: 'mdi-file-document', path: '/contracts', partner: true },
  { label: 'Контракты моей команды', icon: 'mdi-folder-account', path: '/contracts/team', partner: true },
  { label: 'Структура', icon: 'mdi-sitemap', path: '/structure', partner: true },
  { label: 'Обучение', icon: 'mdi-school', path: '/education', partner: true },
  { label: 'Продукты', icon: 'mdi-package-variant', path: '/products', partner: true },
  { label: 'Список конкурсов', icon: 'mdi-trophy', path: '/contests', partner: true },
  { label: 'Обратная связь', icon: 'mdi-chat', path: '/communication', partner: true },

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
  { label: 'Коммуникация', icon: 'mdi-chat', path: '/manage/communication', adminSection: 'communication' },
  { label: 'Отчёты', icon: 'mdi-file-chart', path: '/manage/reports', adminSection: 'reports' },
  { label: 'Валюты и НДС', icon: 'mdi-currency-usd', path: '/manage/currencies', adminSection: 'currencies' },
];

const isConsultant = computed(() => userRoles.value.includes('consultant'));

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
</style>
