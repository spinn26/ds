<template>
  <div>
    <PageHeader title="Все разделы" />

    <div v-for="group in groups" :key="group.label">
      <div class="group-label">{{ group.label }}</div>
      <div class="menu-group">
        <div v-for="item in group.items" :key="item.to" class="menu-cell" @click="$router.push(item.to)">
          <v-icon :color="item.color">{{ item.icon }}</v-icon>
          <div class="menu-cell-title">{{ item.label }}</div>
          <v-chip v-if="item.badge" size="x-small" :color="item.badgeColor" variant="tonal">{{ item.badge }}</v-chip>
          <v-icon class="menu-cell-arrow">mdi-chevron-right</v-icon>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue';

const groups = [
  {
    label: 'Аналитика',
    items: [
      { label: 'Дашборд', icon: 'mdi-view-dashboard-outline', color: 'primary', to: '/manage/dashboard' },
      { label: 'Отчёты', icon: 'mdi-file-chart-outline', color: 'info', to: '/manage/reports' },
      { label: 'Тех. поддержка', icon: 'mdi-lifebuoy', color: 'error', to: '/manage/support', badge: '2', badgeColor: 'error' },
    ],
  },
  {
    label: 'Партнёры и клиенты',
    items: [
      { label: 'Партнёры', icon: 'mdi-account-multiple-outline', color: 'primary', to: '/manage/partners' },
      { label: 'Клиенты', icon: 'mdi-account-group-outline', color: 'info', to: '/manage/clients' },
      { label: 'Структура', icon: 'mdi-account-tree', color: 'success', to: '/app/structure' },
    ],
  },
  {
    label: 'Сделки и деньги',
    items: [
      { label: 'Контракты', icon: 'mdi-file-document-multiple-outline', color: 'primary', to: '/manage/contracts' },
      { label: 'Транзакции', icon: 'mdi-swap-horizontal', color: 'warning', to: '/manage/transactions' },
      { label: 'Прочие начисления', icon: 'mdi-cash-plus', color: 'success', to: '/manage/charges' },
      { label: 'Выплаты', icon: 'mdi-cash-multiple', color: 'success', to: '/manage/payments' },
      { label: 'Реквизиты', icon: 'mdi-bank-outline', color: 'grey', to: '/manage/requisites' },
    ],
  },
  {
    label: 'Расчёты',
    items: [
      { label: 'Квалификации', icon: 'mdi-trophy-outline', color: 'warning', to: '/manage/qualifications' },
      { label: 'Лидерский пул', icon: 'mdi-crown-outline', color: 'amber', to: '/manage/pool' },
    ],
  },
  {
    label: 'Контент и события',
    items: [
      { label: 'Обучение / LMS', icon: 'mdi-school-outline', color: 'info', to: '/manage/education' },
      { label: 'Конкурсы и события', icon: 'mdi-trophy-variant-outline', color: 'warning', to: '/manage/contests' },
      { label: 'Чаты / тикеты', icon: 'mdi-message-outline', color: 'primary', to: '/manage/chat' },
    ],
  },
  {
    label: 'Аккаунт',
    items: [
      { label: 'Профиль', icon: 'mdi-account-circle-outline', color: 'primary', to: '/manage/profile' },
      { label: 'Перейти в кабинет партнёра', icon: 'mdi-swap-horizontal-bold', color: 'success', to: '/app/home' },
    ],
  },
];
</script>

<style scoped>
.group-label {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  color: rgba(0, 0, 0, 0.5);
  margin: 14px 4px 6px;
}
</style>
