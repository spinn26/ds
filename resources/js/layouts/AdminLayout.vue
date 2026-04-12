<template>
  <v-layout>
    <v-navigation-drawer v-model="drawer" :permanent="!mobile" :temporary="mobile" width="280" color="grey-darken-4" theme="dark">
      <div class="d-flex align-center pa-4">
        <span class="text-h6 font-weight-black text-white">DS</span>
        <span class="text-caption text-grey-lighten-1 ml-2">АДМИН</span>
        <v-chip color="red" size="x-small" class="ml-2">ADM</v-chip>
      </div>
      <v-divider />

      <v-list density="compact" nav>
        <v-list-item to="/" prepend-icon="mdi-arrow-left" title="На сайт" color="grey-lighten-1" rounded="lg" class="mb-2" />
        <v-divider class="mb-2" />

        <v-list-item to="/admin/users" prepend-icon="mdi-account-cog" title="Пользователи" color="secondary" rounded="lg" class="mb-1" />

        <v-list-subheader class="text-secondary font-weight-bold mt-2">Данные партнёров</v-list-subheader>
        <v-list-item to="/admin/partners" prepend-icon="mdi-account-search" title="Партнёры" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/partners/statuses" prepend-icon="mdi-calendar-clock" title="Статусы партнёров" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/clients" prepend-icon="mdi-account-group" title="Клиенты" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/contracts" prepend-icon="mdi-file-document-edit" title="Менеджер контрактов" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/acceptance" prepend-icon="mdi-check-circle" title="Акцепт документов" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/requisites" prepend-icon="mdi-credit-card" title="Реквизиты" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/transfers" prepend-icon="mdi-history" title="Перестановки" color="secondary" rounded="lg" class="mb-1" />

        <v-list-subheader class="text-secondary font-weight-bold mt-2">Транзакции и объёмы</v-list-subheader>
        <v-list-item to="/admin/transactions/import" prepend-icon="mdi-upload" title="Импорт транзакций" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/transactions" prepend-icon="mdi-swap-horizontal" title="Транзакции" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/commissions" prepend-icon="mdi-receipt" title="Комиссии" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/pool" prepend-icon="mdi-cash-multiple" title="Пул" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/qualifications" prepend-icon="mdi-chart-bar" title="Квалификации" color="secondary" rounded="lg" class="mb-1" />

        <v-list-subheader class="text-secondary font-weight-bold mt-2">Начисления и выплаты</v-list-subheader>
        <v-list-item to="/admin/charges" prepend-icon="mdi-bank" title="Прочие начисления" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/payments" prepend-icon="mdi-cash" title="Реестр выплат" color="secondary" rounded="lg" class="mb-1" />

        <v-list-subheader class="text-secondary font-weight-bold mt-2">Контент</v-list-subheader>
        <v-list-item to="/admin/products" prepend-icon="mdi-package-variant" title="Продукты" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/education" prepend-icon="mdi-school" title="Обучение" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/contests" prepend-icon="mdi-trophy" title="Конкурсы" color="secondary" rounded="lg" class="mb-1" />

        <v-list-subheader class="text-secondary font-weight-bold mt-2">Настройки</v-list-subheader>
        <v-list-item to="/admin/reports" prepend-icon="mdi-file-chart" title="Отчёты" color="secondary" rounded="lg" class="mb-1" />
        <v-list-item to="/admin/currencies" prepend-icon="mdi-currency-usd" title="Валюты и НДС" color="secondary" rounded="lg" class="mb-1" />
      </v-list>
    </v-navigation-drawer>

    <v-app-bar flat border="b" color="grey-darken-4" theme="dark">
      <v-app-bar-nav-icon v-if="mobile" @click="drawer = !drawer" />
      <v-toolbar-title class="text-body-1">
        <v-icon icon="mdi-shield-crown" color="secondary" class="mr-1" /> Панель администратора
      </v-toolbar-title>
      <v-spacer />
      <span class="text-body-2 text-grey-lighten-1 mr-3">{{ auth.user?.firstName }} {{ auth.user?.lastName }}</span>
      <v-menu>
        <template #activator="{ props }">
          <v-avatar v-bind="props" color="secondary" size="32" class="cursor-pointer">
            <span class="text-caption text-white">{{ initials }}</span>
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
</script>
