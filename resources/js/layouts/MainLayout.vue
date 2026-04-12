<template>
  <v-layout>
    <!-- Sidebar -->
    <v-navigation-drawer v-model="drawer" :permanent="!mobile" :temporary="mobile" width="260">
      <div class="d-flex align-center pa-4">
        <span class="text-h6 font-weight-black text-primary">DS</span>
        <span class="text-caption text-medium-emphasis ml-2">ПЛАТФОРМА</span>
        <v-icon v-if="auth.isAdmin" color="secondary" size="small" class="ml-1">mdi-shield-crown</v-icon>
      </div>
      <v-chip v-if="cabinetName" size="x-small" color="primary" variant="outlined" class="mx-4 mb-2">{{ cabinetName }}</v-chip>
      <v-divider />

      <v-list density="compact" nav>
        <template v-for="(item, i) in visibleMenu" :key="i">
          <v-list-subheader v-if="item.group" :class="item.adminSection ? 'text-secondary font-weight-bold' : ''">
            {{ item.group }}
          </v-list-subheader>
          <v-list-item v-else :to="item.path" :prepend-icon="item.icon"
            :title="item.label" :active="$route.path === item.path"
            :color="item.adminSection ? 'secondary' : 'primary'"
            rounded="lg" class="mb-1" @click="mobile && (drawer = false)" />
        </template>
      </v-list>
    </v-navigation-drawer>

    <!-- Top bar -->
    <v-app-bar flat border="b" :style="{ background: '#fff' }">
      <v-app-bar-nav-icon v-if="mobile" @click="drawer = !drawer" />
      <v-spacer />
      <v-btn v-if="auth.isAdmin" to="/admin/users" color="secondary" variant="flat" size="small" prepend-icon="mdi-account-cog" class="mr-2">
        Пользователи
      </v-btn>
      <v-chip color="success" size="small" variant="outlined" class="mr-2">Активный</v-chip>
      <span v-if="!mobile" class="text-body-2 text-medium-emphasis mr-2">
        {{ auth.user?.firstName }} {{ auth.user?.lastName }}
      </span>
      <v-btn icon="mdi-bell-outline" size="small" class="mr-1" />
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
import { ref, computed } from 'vue';
import { useDisplay } from 'vuetify';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const { mobile } = useDisplay();
const drawer = ref(true);

const initials = computed(() =>
  `${auth.user?.firstName?.[0] || ''}${auth.user?.lastName?.[0] || ''}`.toUpperCase()
);

// Parse user roles
const userRoles = computed(() => {
  const role = auth.user?.role || '';
  return role.split(',').map(r => r.trim()).filter(Boolean);
});

// Cabinet sections available per role
const cabinetSections = {
  admin: ['partners', 'statuses', 'clients', 'contracts', 'acceptance', 'requisites', 'transfers', 'transactions', 'import', 'commissions', 'pool', 'qualifications', 'charges', 'payments', 'reports', 'currencies'],
  backoffice: ['partners', 'statuses', 'clients', 'contracts', 'acceptance', 'requisites', 'transfers', 'transactions', 'import', 'commissions', 'pool', 'qualifications', 'charges', 'payments', 'reports', 'currencies'],
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
  if (userRoles.value.includes('backoffice')) return 'Бэкофис';
  return null;
});

const menuItems = [
  { label: 'Обучение', icon: 'mdi-school', path: '/education' },
  { label: 'Дашборд', icon: 'mdi-view-dashboard', path: '/', requireRole: 'consultant' },
  { label: 'Рефералки', icon: 'mdi-share-variant', path: '/referrals', requireRole: 'consultant' },
  { group: 'Финансы', requireRole: 'consultant' },
  { label: 'Отчёт начислений', icon: 'mdi-bank', path: '/finance/report', requireRole: 'consultant' },
  { label: 'Калькулятор объёмов', icon: 'mdi-calculator', path: '/finance/calculator', requireRole: 'consultant' },
  { group: 'Клиенты', requireRole: 'consultant' },
  { label: 'Список клиентов', icon: 'mdi-account-group', path: '/clients', requireRole: 'consultant' },
  { group: 'Контракты', requireRole: 'consultant' },
  { label: 'Контракты клиентов', icon: 'mdi-file-document', path: '/contracts', requireRole: 'consultant' },
  { label: 'Контракты команды', icon: 'mdi-folder-account', path: '/contracts/team', requireRole: 'consultant' },
  { group: 'Структура', requireRole: 'consultant' },
  { label: 'Структура команды', icon: 'mdi-sitemap', path: '/structure', requireRole: 'consultant' },
  { label: 'Продукты', icon: 'mdi-package-variant', path: '/products', requireRole: 'consultant' },
  { label: 'Инсмарт', icon: 'mdi-shield-check', path: '/inssmart', requireRole: 'consultant' },
  { group: 'Конкурсы', requireRole: 'consultant' },
  { label: 'Список конкурсов', icon: 'mdi-trophy', path: '/contests', requireRole: 'consultant' },
  { group: 'Помощь' },
  { label: 'Инструкции', icon: 'mdi-help-circle', path: '/help' },
  { label: 'Коммуникация', icon: 'mdi-chat', path: '/communication' },
  // Admin sections (in partner layout)
  { group: 'Данные партнёров', adminSection: 'partners' },
  { label: 'Менеджер контрактов', icon: 'mdi-file-document-edit', path: '/manage/contracts', adminSection: 'contracts' },
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
  if (item.requireRole === 'consultant') return auth.isConsultant || auth.isAdmin;
  return true;
}));
</script>
