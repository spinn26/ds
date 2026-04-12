<template>
  <v-layout>
    <!-- Sidebar -->
    <v-navigation-drawer v-model="drawer" :permanent="!mobile" :temporary="mobile" width="260">
      <div class="d-flex align-center pa-4">
        <span class="text-h6 font-weight-black text-primary">DS</span>
        <span class="text-caption text-medium-emphasis ml-2">ПЛАТФОРМА</span>
        <v-icon v-if="auth.isAdmin" color="secondary" size="small" class="ml-1">mdi-shield-crown</v-icon>
      </div>
      <v-divider />

      <v-list density="compact" nav>
        <template v-for="(item, i) in visibleMenu" :key="i">
          <v-list-subheader v-if="item.group" :class="item.adminOnly ? 'text-secondary font-weight-bold' : ''">
            {{ item.group }}
          </v-list-subheader>
          <v-list-item v-else :to="item.path" :prepend-icon="item.icon"
            :title="item.label" :active="$route.path === item.path"
            :color="item.adminOnly ? 'secondary' : 'primary'"
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
  { group: 'Данные партнёров', adminOnly: true },
  { label: 'Менеджер контрактов', icon: 'mdi-file-document-edit', path: '/manage/contracts', adminOnly: true },
  { label: 'Партнёры', icon: 'mdi-account-search', path: '/manage/partners', adminOnly: true },
  { label: 'Статусы партнёров', icon: 'mdi-calendar-clock', path: '/manage/partners/statuses', adminOnly: true },
  { label: 'Клиенты', icon: 'mdi-account-group', path: '/manage/clients', adminOnly: true },
  { label: 'Акцепт документов', icon: 'mdi-check-circle', path: '/manage/acceptance', adminOnly: true },
  { label: 'Реквизиты', icon: 'mdi-credit-card', path: '/manage/requisites', adminOnly: true },
  { label: 'Перестановки', icon: 'mdi-history', path: '/manage/transfers', adminOnly: true },
  { group: 'Транзакции и объёмы', adminOnly: true },
  { label: 'Импорт транзакций', icon: 'mdi-upload', path: '/manage/transactions/import', adminOnly: true },
  { label: 'Транзакции', icon: 'mdi-swap-horizontal', path: '/manage/transactions', adminOnly: true },
  { label: 'Комиссии', icon: 'mdi-receipt', path: '/manage/commissions', adminOnly: true },
  { label: 'Пул', icon: 'mdi-cash-multiple', path: '/manage/pool', adminOnly: true },
  { label: 'Квалификации', icon: 'mdi-chart-bar', path: '/manage/qualifications', adminOnly: true },
  { group: 'Начисления и выплаты', adminOnly: true },
  { label: 'Прочие начисления', icon: 'mdi-bank', path: '/manage/charges', adminOnly: true },
  { label: 'Реестр выплат', icon: 'mdi-cash', path: '/manage/payments', adminOnly: true },
  { group: 'Отчёты и настройки', adminOnly: true },
  { label: 'Отчёты', icon: 'mdi-file-chart', path: '/manage/reports', adminOnly: true },
  { label: 'Валюты и НДС', icon: 'mdi-currency-usd', path: '/manage/currencies', adminOnly: true },
];

const visibleMenu = computed(() => menuItems.filter((item) => {
  if (item.adminOnly) return auth.isAdmin;
  if (item.requireRole === 'consultant') return auth.isConsultant || auth.isAdmin;
  return true;
}));
</script>
