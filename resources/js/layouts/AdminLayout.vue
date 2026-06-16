<template>
  <v-layout>
    <v-navigation-drawer v-model="drawer" :permanent="!mobile" :temporary="mobile"
      :rail="rail && !mobile" :width="280" :rail-width="72"
      color="grey-darken-4" theme="dark" class="admin-drawer">
      <div class="d-flex align-center pa-4 drawer-brand">
        <div v-if="!rail" class="flex-grow-1">
          <div class="d-flex align-center ga-1">
            <img v-if="design.logoUrl" :src="design.logoUrl" alt="logo" style="max-height: 24px" />
            <span v-else class="text-h6 font-weight-black text-white">{{ design.logoText }}</span>
            <span class="text-caption text-grey-lighten-1">УПРАВЛЕНИЕ</span>
          </div>
          <div style="font-size: 0.55rem; letter-spacing: 1.5px; color: rgba(255,255,255,0.35); margin-top: -2px">
            ПАНЕЛЬ УПРАВЛЕНИЯ
          </div>
        </div>
        <v-btn v-if="mobile" icon="mdi-close" size="small" variant="text" density="comfortable" color="grey-lighten-2"
          @click="drawer = false" />
        <v-btn v-else-if="!rail" icon="mdi-chevron-left" size="x-small" variant="text" density="comfortable" color="grey-lighten-2"
          @click="toggleRail" />
        <v-btn v-else icon="mdi-chevron-right" size="x-small" variant="text" density="comfortable" color="grey-lighten-2"
          @click="toggleRail" />
      </div>
      <v-divider />

      <v-list density="compact" nav class="admin-nav-list" open-strategy="multiple">
        <v-list-item to="/" prepend-icon="mdi-arrow-left" title="На сайт" color="grey-lighten-1" />
        <v-divider />

        <template v-for="item in menuItems" :key="item.title">
          <!-- Simple item -->
          <v-list-item v-if="!item.children"
            :to="item.to" :prepend-icon="item.icon" :title="item.title" color="secondary" />
          <!-- Expandable group -->
          <v-list-group v-else :value="item.title">
            <template #activator="{ props }">
              <v-list-item v-bind="props" :prepend-icon="item.icon" :title="item.title" color="secondary" />
            </template>
            <v-list-item v-for="child in item.children" :key="child.to"
              :to="child.to" :title="child.title"
              :prepend-icon="child.icon || 'mdi-circle-small'" color="secondary" class="ps-6" />
          </v-list-group>
        </template>
      </v-list>

      <template #append>
        <v-divider />
        <v-list density="compact" nav class="admin-nav-list">
          <v-list-item :prepend-icon="rail ? 'mdi-chevron-right' : 'mdi-chevron-left'"
            :title="rail ? '' : 'Свернуть меню'"
            color="grey-lighten-1"
            @click="toggleRail" />
        </v-list>
      </template>
    </v-navigation-drawer>

    <v-app-bar flat border="b" color="grey-darken-4" theme="dark">
      <v-app-bar-nav-icon v-if="mobile" @click="drawer = !drawer" />
      <v-toolbar-title class="text-body-1">
        <v-icon icon="mdi-shield-crown" color="secondary" class="mr-1" /> {{ design.logoText }} Управление
      </v-toolbar-title>
      <v-spacer />
      <span class="text-body-2 text-grey-lighten-1 mr-3">{{ auth.user?.firstName }} {{ auth.user?.lastName }}</span>
      <v-menu>
        <template #activator="{ props }">
          <v-avatar v-bind="props" color="secondary" size="32" class="cursor-pointer">
            <span class="text-caption">{{ initials }}</span>
          </v-avatar>
        </template>
        <v-list density="compact">
          <v-list-item to="/" prepend-icon="mdi-home" title="На сайт" />
          <v-list-item @click="auth.logout(); $router.push('/login')" prepend-icon="mdi-logout" title="Выйти" />
        </v-list>
      </v-menu>
    </v-app-bar>

    <v-main>
      <v-container fluid class="pa-4 pa-md-6">
        <router-view />
      </v-container>
    </v-main>

    <!-- Глобальный confirm-диалог -->
    <ConfirmDialog ref="confirmRef" />

    <!-- Глобальный snackbar -->
    <GlobalSnackbar />
  </v-layout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useDisplay, useTheme } from 'vuetify';
import { useAuthStore } from '../stores/auth';
import { useDesignStore } from '../stores/design';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import GlobalSnackbar from '../components/GlobalSnackbar.vue';
import { provideConfirm } from '../composables/useConfirm';

const confirmRef = ref(null);
provideConfirm(confirmRef);

const auth = useAuthStore();
const design = useDesignStore();
const { mobile } = useDisplay();
const theme = useTheme();

// Admin always dark
let prevTheme = '';
onMounted(() => {
  prevTheme = theme.global.name.value;
  theme.global.name.value = 'dark';
});
onUnmounted(() => {
  theme.global.name.value = prevTheme || localStorage.getItem('theme') || 'dark';
});
const drawer = ref(true);

// Rail (minimalist) mode — persists across sessions
const rail = ref(localStorage.getItem('admin-nav-rail') === '1');
function toggleRail() {
  rail.value = !rail.value;
  localStorage.setItem('admin-nav-rail', rail.value ? '1' : '0');
}

// Bitrix-style разбивка админ-консоли на верхнеуровневые категории.
// Каждая категория — раскрывающаяся группа (v-list-group) с пунктами.
const menuItems = [
  {
    title: 'Рабочий стол', icon: 'mdi-view-dashboard',
    children: [
      { to: '/admin/dashboard', title: 'Дашборд', icon: 'mdi-chart-areaspline' },
      { to: '/admin/owner-dashboard', title: 'Дашборд руководителя', icon: 'mdi-crown' },
    ],
  },
  {
    title: 'Пользователи и клиенты', icon: 'mdi-account-group',
    children: [
      { to: '/admin/users', title: 'Пользователи', icon: 'mdi-account-cog' },
      { to: '/admin/partners', title: 'Партнёры', icon: 'mdi-account-group' },
      { to: '/admin/clients', title: 'Клиенты', icon: 'mdi-account-multiple' },
      { to: '/admin/custom-fields', title: 'Кастомные поля', icon: 'mdi-form-select' },
      { to: '/admin/login-log', title: 'Журнал входов', icon: 'mdi-login-variant' },
      { to: '/admin/activity', title: 'Активность', icon: 'mdi-account-multiple-check' },
    ],
  },
  {
    title: 'Контент и продукты', icon: 'mdi-package-variant',
    children: [
      { to: '/admin/products', title: 'Продукты', icon: 'mdi-package-variant' },
      { to: '/admin/contests', title: 'Конкурсы и события', icon: 'mdi-trophy' },
      { to: '/admin/news', title: 'Новости', icon: 'mdi-newspaper' },
      { to: '/admin/content-pages', title: 'Контент-страницы', icon: 'mdi-file-document-edit' },
      { to: '/admin/media', title: 'Медиа-библиотека', icon: 'mdi-folder-multiple-image' },
      { to: '/admin/roadmap', title: 'Роадмап', icon: 'mdi-map-marker-path' },
    ],
  },
  {
    title: 'Финансы и контроль', icon: 'mdi-scale-balance',
    children: [
      { to: '/admin/reconciliation', title: 'Сверка балансов', icon: 'mdi-scale-balance' },
      { to: '/admin/anomalies', title: 'Аномалии', icon: 'mdi-alert-decagram' },
      { to: '/admin/cohorts', title: 'Когорты', icon: 'mdi-chart-line' },
    ],
  },
  {
    title: 'Операции', icon: 'mdi-cogs',
    children: [
      { to: '/admin/calendar', title: 'Календарь операций', icon: 'mdi-calendar-check' },
      { to: '/admin/bulk-ops', title: 'Массовые операции', icon: 'mdi-format-list-bulleted-square' },
      { to: '/admin/export-center', title: 'Центр экспорта', icon: 'mdi-database-export' },
      { to: '/admin/system', title: 'Система (кэш/планировщик)', icon: 'mdi-server-network' },
    ],
  },
  {
    title: 'Маркетинг и уведомления', icon: 'mdi-bullhorn',
    children: [
      { to: '/admin/announcements', title: 'Объявления', icon: 'mdi-bullhorn' },
      { to: '/admin/mail', title: 'Почтовая рассылка', icon: 'mdi-email-fast' },
      { to: '/admin/triggers', title: 'Триггеры уведомлений', icon: 'mdi-robot' },
    ],
  },
  {
    title: 'Справочники', icon: 'mdi-book-cog',
    children: [
      { to: '/admin/references/productCategory',     title: 'Категории продуктов',    icon: 'mdi-tag-multiple' },
      { to: '/admin/references/currency',            title: 'Валюты',                 icon: 'mdi-currency-usd' },
      { to: '/admin/references/contractStatus',      title: 'Статусы контрактов',     icon: 'mdi-file-check' },
      { to: '/admin/references/status',              title: 'Статусы партнёров',      icon: 'mdi-account-check' },
      { to: '/admin/references/directory_of_activities', title: 'Статусы активности', icon: 'mdi-lightning-bolt' },
      { to: '/admin/references/type_contest',        title: 'Типы конкурсов',         icon: 'mdi-trophy-variant' },
      { to: '/admin/references/status_contest',      title: 'Статусы конкурсов',      icon: 'mdi-trophy-outline' },
      { to: '/admin/references/criterion',           title: 'Критерии конкурсов',     icon: 'mdi-target' },
      { to: '/admin/references/communicationCategory', title: 'Категории обращений',  icon: 'mdi-message-text' },
      { to: '/admin/references/title',               title: 'Титулы / звания',        icon: 'mdi-medal' },
      { to: '/admin/references/occupation',          title: 'Род деятельности',       icon: 'mdi-briefcase' },
      { to: '/admin/references/meetingType',         title: 'Типы встреч',            icon: 'mdi-calendar-account' },
    ],
  },
  {
    title: 'Настройки', icon: 'mdi-cog',
    children: [
      { to: '/admin/design', title: 'Дизайн', icon: 'mdi-palette' },
      { to: '/admin/settings', title: 'Настройки системы', icon: 'mdi-tune' },
      { to: '/admin/feature-flags', title: 'Фиче-флаги', icon: 'mdi-flag-variant' },
      { to: '/admin/integrations', title: 'Интеграции', icon: 'mdi-cloud-sync' },
      { to: '/admin/monitoring', title: 'Мониторинг', icon: 'mdi-pulse' },
    ],
  },
];

const initials = computed(() =>
  `${auth.user?.firstName?.[0] || ''}${auth.user?.lastName?.[0] || ''}`.toUpperCase()
);
</script>

<style scoped>
.admin-brand-mark {
  width: 32px;
  height: 32px;
  overflow: hidden;
  flex-shrink: 0;
  box-shadow: 0 0 0 2px rgba(var(--v-theme-brand), 0.35);
}

/* DS nav items: rounded-md + mint-tint active state.
   В тёмной теме --ds-primary-soft = rgba(110,232,122,0.10). */
.admin-nav-list :deep(.v-list-item) {
  border-radius: var(--ds-radius-md, 8px) !important;
  margin: 2px 8px !important;
  font-weight: 500;
}
.admin-nav-list :deep(.v-list-item:hover) {
  background: var(--ds-overlay, rgba(255, 255, 255, 0.04));
}
.admin-nav-list :deep(.v-list-item--active) {
  background: var(--ds-primary-soft, rgba(var(--v-theme-primary), 0.12));
  color: rgb(var(--v-theme-primary));
}
.admin-nav-list :deep(.v-list-item--active .v-icon) {
  color: rgb(var(--v-theme-primary));
}

.admin-drawer :deep(.v-navigation-drawer) {
  border-radius: 0 !important;
}

/* Rail mode: center icon, hide label gap */
.admin-drawer :deep(.v-navigation-drawer--rail) .drawer-brand {
  justify-content: center;
  padding: 16px 0 !important;
}
.admin-drawer :deep(.v-navigation-drawer--rail) .admin-brand-mark {
  margin-right: 0 !important;
}
</style>
