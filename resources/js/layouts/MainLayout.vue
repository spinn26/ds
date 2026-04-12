<template>
  <v-layout>
    <!-- Sidebar -->
    <v-navigation-drawer v-model="drawer" :permanent="!mobile" :temporary="mobile" width="260">
      <div class="d-flex align-center pa-4">
        <span class="text-h6 font-weight-black text-primary">DS</span>
        <span class="text-caption text-medium-emphasis ml-2">ПЛАТФОРМА</span>
        <v-icon v-if="isStaff" color="secondary" size="small" class="ml-1">mdi-shield-crown</v-icon>
      </div>
      <v-chip v-if="cabinetName" size="x-small" color="primary" variant="outlined" class="mx-4 mb-2">{{ cabinetName }}</v-chip>
      <v-divider />

      <v-list density="compact" nav>
        <template v-for="(item, i) in visibleMenu" :key="i">
          <v-list-subheader v-if="item.group" :class="item.adminSection ? 'text-secondary font-weight-bold' : ''">
            {{ item.group }}
          </v-list-subheader>
          <v-list-item v-else :to="item.path" :prepend-icon="item.icon"
            :title="item.label" :active="isActivePath(item.path)"
            :color="item.adminSection ? 'secondary' : 'primary'"
            rounded="lg" class="mb-1" @click="mobile && (drawer = false)" />
        </template>
      </v-list>
    </v-navigation-drawer>

    <!-- Top bar -->
    <v-app-bar flat border="b" :style="{ background: '#fff' }">
      <v-app-bar-nav-icon v-if="mobile" @click="drawer = !drawer" />
      <v-spacer />

      <!-- Referral link copy button (only for active partners) -->
      <v-btn v-if="statusInfo?.canInvite && statusInfo?.referralCode" size="small" variant="tonal" color="primary"
        class="mr-2" prepend-icon="mdi-link-variant" @click="copyReferral">
        {{ copied ? 'Скопировано!' : 'Реф. ссылка' }}
      </v-btn>

      <!-- Status chip with activity -->
      <v-chip :color="statusColor" size="small" variant="outlined" class="mr-2">
        {{ statusInfo?.activityName || 'Загрузка...' }}
      </v-chip>

      <!-- Countdown to status change -->
      <v-chip v-if="statusInfo?.daysRemaining != null && statusInfo.daysRemaining <= 90"
        :color="statusInfo.daysRemaining <= 30 ? 'error' : 'warning'" size="small" variant="tonal" class="mr-2">
        <v-icon start size="14">mdi-timer-outline</v-icon>
        {{ statusInfo.daysRemaining }} дн.
      </v-chip>

      <!-- Admin button -->
      <v-btn v-if="auth.isAdmin" to="/admin/users" color="secondary" variant="flat" size="small"
        prepend-icon="mdi-account-cog" class="mr-2">
        Пользователи
      </v-btn>

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
    <v-main>
      <v-container fluid class="pa-4 pa-md-6">
        <router-view />
      </v-container>
    </v-main>
  </v-layout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useDisplay } from 'vuetify';
import { useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import api from '../api';

const auth = useAuthStore();
const route = useRoute();
const { mobile } = useDisplay();
const drawer = ref(true);
const copied = ref(false);
const statusInfo = ref(null);

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

// Cabinet sections per role
const cabinetSections = {
  admin: ['partners', 'statuses', 'clients', 'contracts', 'upload', 'acceptance', 'requisites', 'transfers', 'transactions', 'import', 'commissions', 'pool', 'qualifications', 'charges', 'payments', 'reports', 'currencies', 'communication'],
  backoffice: ['partners', 'statuses', 'clients', 'contracts', 'upload', 'acceptance', 'requisites', 'transfers', 'transactions', 'import', 'commissions', 'pool', 'qualifications', 'charges', 'payments', 'reports', 'currencies', 'communication'],
  support: ['partners', 'statuses', 'clients', 'contracts', 'acceptance', 'communication'],
  head: ['partners', 'statuses', 'clients', 'contracts', 'acceptance', 'transfers', 'reports', 'communication'],
  finance: ['requisites', 'charges', 'payments', 'reports', 'communication'],
  calculations: ['commissions', 'qualifications', 'pool', 'transactions', 'import', 'currencies'],
  corrections: ['clients', 'contracts', 'partners'],
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

// Menu items — ORDER per spec "Кабинет партнера"
const menuItems = [
  // Partner menu — order per spec
  { label: 'Дашборд', icon: 'mdi-view-dashboard', path: '/', partner: true },
  { group: 'Финансы', partner: true },
  { label: 'Отчёт начислений', icon: 'mdi-bank', path: '/finance/report', partner: true },
  { label: 'Калькулятор объёмов', icon: 'mdi-calculator', path: '/finance/calculator', partner: true },
  { group: 'Клиенты', partner: true },
  { label: 'Список моих клиентов', icon: 'mdi-account-group', path: '/clients', partner: true },
  { group: 'Контракты', partner: true },
  { label: 'Контракты моих клиентов', icon: 'mdi-file-document', path: '/contracts', partner: true },
  { label: 'Контракты моей команды', icon: 'mdi-folder-account', path: '/contracts/team', partner: true },
  { label: 'Структура', icon: 'mdi-sitemap', path: '/structure', partner: true },
  { label: 'Обучение', icon: 'mdi-school', path: '/education' },
  { label: 'Продукты', icon: 'mdi-package-variant', path: '/products', partner: true },
  { group: 'Конкурсы и события', partner: true },
  { label: 'Список конкурсов', icon: 'mdi-trophy', path: '/contests', partner: true },
  { group: 'Помощь' },
  { label: 'Обратная связь', icon: 'mdi-chat', path: '/communication' },

  // Admin/staff sections
  { group: 'Данные партнёров', adminSection: 'partners' },
  { label: 'Менеджер контрактов', icon: 'mdi-file-document-edit', path: '/manage/contracts', adminSection: 'contracts' },
  { label: 'Загрузка контрактов', icon: 'mdi-upload', path: '/manage/contracts/upload', adminSection: 'upload' },
  { label: 'Партнёры', icon: 'mdi-account-search', path: '/manage/partners', adminSection: 'partners' },
  { label: 'Статусы партнёров', icon: 'mdi-calendar-clock', path: '/manage/partners/statuses', adminSection: 'statuses' },
  { label: 'Клиенты', icon: 'mdi-account-group', path: '/manage/clients', adminSection: 'clients' },
  { label: 'Акцепт документов', icon: 'mdi-check-circle', path: '/manage/acceptance', adminSection: 'acceptance' },
  { label: 'Реквизиты', icon: 'mdi-credit-card', path: '/manage/requisites', adminSection: 'requisites' },
  { label: 'Перестановки', icon: 'mdi-history', path: '/manage/transfers', adminSection: 'transfers' },
  { group: 'Транзакции и объёмы', adminSection: 'transactions' },
  { label: 'Импорт транзакций', icon: 'mdi-upload', path: '/manage/transactions/import', adminSection: 'import' },
  { label: 'Транзакции', icon: 'mdi-swap-horizontal', path: '/manage/transactions', adminSection: 'transactions' },
  { label: 'Комиссии', icon: 'mdi-receipt', path: '/manage/commissions', adminSection: 'commissions' },
  { label: 'Пул', icon: 'mdi-cash-multiple', path: '/manage/pool', adminSection: 'pool' },
  { label: 'Квалификации', icon: 'mdi-chart-bar', path: '/manage/qualifications', adminSection: 'qualifications' },
  { group: 'Начисления и выплаты', adminSection: 'charges' },
  { label: 'Прочие начисления', icon: 'mdi-bank', path: '/manage/charges', adminSection: 'charges' },
  { label: 'Реестр выплат', icon: 'mdi-cash', path: '/manage/payments', adminSection: 'payments' },
  { group: 'Отчёты и настройки', adminSection: 'reports' },
  { label: 'Отчёты', icon: 'mdi-file-chart', path: '/manage/reports', adminSection: 'reports' },
  { label: 'Валюты и НДС', icon: 'mdi-currency-usd', path: '/manage/currencies', adminSection: 'currencies' },
];

const visibleMenu = computed(() => menuItems.filter((item) => {
  if (item.adminSection) return availableSections.value.has(item.adminSection);
  if (item.partner) return auth.isConsultant || auth.isAdmin;
  return true;
}));
</script>
