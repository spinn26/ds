<template>
  <div>
    <!-- Статус системы вынесен в шапку (SystemStatusChip в MainLayout)
         — он там виден на всех страницах с мигающим индикатором. -->

    <!-- DS hero — приветствие + текущая дата на полупрозрачном BrandWaves фоне.
         Соответствует ds-layouts.jsx::PartnerWorkspace hero. -->
    <v-card class="ds-hero mb-4" elevation="0">
      <BrandWaves shape="sheet" :width="1200" :height="180"
        preserveAspectRatio="xMidYMid slice"
        bg-color="transparent" stroke-color="#6EE87A" :stroke-opacity="0.2"
        class="ds-hero__bg" />
      <div class="ds-hero__content d-flex justify-space-between align-center flex-wrap ga-3">
        <div>
          <div class="ds-eyebrow ds-eyebrow--primary">{{ greetingEyebrow }}</div>
          <h1 class="ds-headline-l hero-title mt-1 d-flex align-center ga-2">
            <v-icon size="32" color="primary">mdi-hand-wave</v-icon>
            {{ greeting }}, {{ auth.user?.firstName }}!
          </h1>
          <div class="ds-body-l ds-muted mt-1">Рабочий стол DS Consulting</div>
        </div>
        <div class="ds-body-s ds-muted">
          {{ new Date().toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) }}
        </div>
      </div>
    </v-card>

    <!-- ===== ADMIN: hero «Админ-режим» + 6 quick-link плиток на системные
         разделы (Сверка / Аномалии / Когорты / Группы и права / etc.).
         Видно только при роли admin. ===== -->
    <template v-if="isAdmin">
      <v-card class="admin-hero mb-4 pa-4" elevation="0" variant="tonal" color="primary">
        <div class="d-flex align-center ga-3 flex-wrap">
          <v-avatar color="primary" size="56">
            <v-icon size="32" color="white">mdi-shield-crown</v-icon>
          </v-avatar>
          <div class="grow min-w-0">
            <div class="text-overline text-primary">Админ-режим</div>
            <h2 class="text-h5 font-weight-bold">Управление платформой</h2>
            <div class="text-body-2 text-medium-emphasis">
              Системные разделы и сквозная аналитика
            </div>
          </div>
        </div>
      </v-card>

      <v-row dense class="mb-4">
        <v-col v-for="card in adminQuickLinks" :key="card.to" cols="6" sm="4" md="2">
          <v-card class="admin-quick pa-3 h-100" :to="card.to" hover variant="outlined">
            <v-icon :color="card.color" size="28" class="mb-2">{{ card.icon }}</v-icon>
            <div class="text-body-2 font-weight-bold">{{ card.label }}</div>
            <div class="text-caption text-medium-emphasis mt-1">{{ card.sub }}</div>
          </v-card>
        </v-col>
      </v-row>
    </template>

    <!-- ===== STAFF: 6 counter-плиток (Реквизиты, Сообщения, Выплаты,
         Акцепт, Активация, Незакрытые периоды). Видно при любой staff-роли. ===== -->
    <v-row v-if="isStaff" dense class="mb-3">
      <v-col v-for="t in staffTiles" :key="t.key" cols="6" sm="4" md="2">
        <v-card :color="t.color" variant="tonal" class="pa-3 staff-tile"
          @click="t.to && $router.push(t.to)">
          <div class="d-flex align-center ga-2">
            <v-icon :color="t.color" size="24">{{ t.icon }}</v-icon>
            <div class="min-w-0">
              <div class="text-caption text-medium-emphasis text-truncate">{{ t.label }}</div>
              <div class="text-h6 font-weight-bold">{{ t.value }}</div>
            </div>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <!-- ===== STAFF: 6 task-cards с лентами задач (детали в каждом блоке).
         Видно при любой staff-роли. ===== -->
    <v-row v-if="isStaff" dense class="mb-4">
      <v-col cols="12" md="6">
        <TaskCard title="Акцепт документов ждут проверки" icon="mdi-file-document-check"
          :count="sections.pendingAcceptance?.count" to="/manage/acceptance" :loading="loading">
          <v-list density="compact">
            <v-list-item v-for="it in sections.pendingAcceptance?.items || []" :key="it.id"
              :title="it.personName" :subtitle="it.email"
              @click="$router.push('/manage/acceptance')">
              <template #append>
                <span class="text-caption text-medium-emphasis">{{ fmtDate(it.dateCreated) }}</span>
              </template>
            </v-list-item>
            <v-list-item v-if="!sections.pendingAcceptance?.items?.length" class="text-medium-emphasis">
              Нет задач
            </v-list-item>
          </v-list>
        </TaskCard>
      </v-col>

      <v-col cols="12" md="6">
        <TaskCard title="Активные контракты без транзакций (30+ дней)" icon="mdi-file-alert"
          :count="sections.newContractsNoTx?.count" to="/manage/contracts" :loading="loading">
          <v-list density="compact">
            <v-list-item v-for="it in sections.newContractsNoTx?.items || []" :key="it.id"
              :title="`№${it.number || it.id} · ${it.consultantName || '—'}`" :subtitle="it.statusName">
              <template #append>
                <span class="text-caption text-medium-emphasis">{{ fmtDate(it.openDate) }}</span>
              </template>
            </v-list-item>
            <v-list-item v-if="!sections.newContractsNoTx?.items?.length" class="text-medium-emphasis">
              Нет задач
            </v-list-item>
          </v-list>
        </TaskCard>
      </v-col>

      <v-col cols="12" md="6">
        <TaskCard title="Неудачные импорты транзакций" icon="mdi-database-alert"
          :count="sections.failedImports?.count" to="/manage/transactions/import" :loading="loading">
          <v-list density="compact">
            <v-list-item v-for="it in sections.failedImports?.items || []" :key="it.id"
              :title="`Импорт #${it.id}`" :subtitle="`${it.success_count ?? 0} ок · ${it.error_count ?? 0} ош.`">
              <template #append>
                <StatusChip :value="it.status" kind="import" size="x-small" />
              </template>
            </v-list-item>
            <v-list-item v-if="!sections.failedImports?.items?.length" class="text-medium-emphasis">
              Нет задач
            </v-list-item>
          </v-list>
        </TaskCard>
      </v-col>

      <v-col cols="12" md="6">
        <TaskCard title="«Прочие начисления» ждут утверждения" icon="mdi-cash-edit"
          :count="sections.pendingAccruals?.count" to="/manage/charges" :loading="loading">
          <v-list density="compact">
            <v-list-item v-for="it in sections.pendingAccruals?.items || []" :key="it.id"
              :title="it.consultantName || '—'" :subtitle="it.reason">
              <template #append>
                <MoneyCell :value="it.amount" currency="₽" />
              </template>
            </v-list-item>
            <v-list-item v-if="!sections.pendingAccruals?.items?.length" class="text-medium-emphasis">
              Нет задач
            </v-list-item>
          </v-list>
        </TaskCard>
      </v-col>

      <v-col cols="12" md="6">
        <TaskCard title="Активация истекает в ближайшие 7 дней" icon="mdi-clock-alert"
          :count="sections.activationExpiring?.count" to="/manage/partners/statuses" :loading="loading">
          <v-list density="compact">
            <v-list-item v-for="it in sections.activationExpiring?.items || []" :key="it.id"
              :title="it.personName" :subtitle="it.email">
              <template #append>
                <div class="text-right">
                  <div class="text-caption">ЛП: {{ fmt(it.personalVolume) }} / 500</div>
                  <div class="text-caption text-medium-emphasis">{{ daysLeft(it.dateCreated) }} дн</div>
                </div>
              </template>
            </v-list-item>
            <v-list-item v-if="!sections.activationExpiring?.items?.length" class="text-medium-emphasis">
              Нет задач
            </v-list-item>
          </v-list>
        </TaskCard>
      </v-col>

      <v-col cols="12" md="6">
        <TaskCard title="Незакрытые периоды" icon="mdi-calendar-lock"
          :count="sections.unclosedPeriods?.count" :loading="loading">
          <v-list density="compact">
            <v-list-item v-for="it in sections.unclosedPeriods?.items || []" :key="it.label"
              :title="it.label"
              @click="$router.push(`/manage/periods/${it.year}-${String(it.month).padStart(2,'0')}`)">
              <template #append>
                <v-btn size="x-small" color="primary" variant="tonal">Открыть</v-btn>
              </template>
            </v-list-item>
            <v-list-item v-if="!sections.unclosedPeriods?.items?.length" class="text-medium-emphasis">
              Все периоды закрыты
            </v-list-item>
          </v-list>
        </TaskCard>
      </v-col>
    </v-row>

    <v-row>
      <!-- Left column -->
      <v-col cols="12" md="8">
        <!-- Partner stats (consultants only) — DS-сетка KPI-плиток.
             Соответствует ds-screens-auth-partner.jsx::PartnerDashboard KPI grid. -->
        <div v-if="data.partnerStats" class="mb-4">
          <div class="text-subtitle-1 font-weight-bold mb-3 d-flex align-center ga-2">
            <v-icon size="20" color="primary">mdi-chart-line</v-icon> Мои показатели
          </div>
          <div class="stats-kpi-row">
            <div class="ds-kpi">
              <div class="ds-kpi__label">ЛП</div>
              <div class="ds-kpi__value text-success">{{ fmt(data.partnerStats.personalVolume) }}</div>
            </div>
            <div class="ds-kpi">
              <div class="ds-kpi__label">ГП</div>
              <div class="ds-kpi__value text-info">{{ fmt(data.partnerStats.groupVolume) }}</div>
            </div>
            <div class="ds-kpi">
              <div class="ds-kpi__label">НГП</div>
              <div class="ds-kpi__value text-warning">{{ fmt(data.partnerStats.groupVolumeCumulative) }}</div>
            </div>
            <div class="ds-kpi">
              <div class="ds-kpi__label">Квалификация</div>
              <div class="ds-kpi__value qual-name">{{ data.partnerStats.qualification }}</div>
              <div v-if="data.partnerStats.levelsDontMatch" class="ds-kpi__delta ds-kpi__delta--down">
                Расчёт: {{ data.partnerStats.calcQualification }} ({{ data.partnerStats.calcPercent }}%)
              </div>
              <div v-else class="ds-kpi__delta ds-kpi__delta--flat">{{ data.partnerStats.percent }}%</div>
            </div>
            <div class="ds-kpi">
              <div class="ds-kpi__label">Клиенты</div>
              <div class="ds-kpi__value">{{ data.partnerStats.clientCount }}</div>
            </div>
            <div class="ds-kpi">
              <div class="ds-kpi__label">Команда</div>
              <div class="ds-kpi__value">{{ data.partnerStats.teamCount }}</div>
            </div>
          </div>
        </div>

        <!-- Network Leader self-badge: partner is the root of the network -->
        <v-card v-if="data.isNetworkLeader" class="pa-4 mb-4" variant="tonal" color="secondary">
          <div class="d-flex align-center ga-3">
            <v-avatar color="secondary" size="48">
              <v-icon color="white">mdi-crown</v-icon>
            </v-avatar>
            <div>
              <div class="text-caption text-medium-emphasis">Статус</div>
              <div class="text-subtitle-1 font-weight-bold">Вы — Лидер сети</div>
              <div class="text-caption">Корень структуры, наставник не назначается</div>
            </div>
          </div>
        </v-card>

        <!-- Mentor & Network Leader -->
        <v-alert v-if="!data.isNetworkLeader && !data.mentor && data.networkLeader" type="info" variant="tonal" class="mb-4" density="compact">
          Наставника нет — указан ЛИДЕР СЕТИ
        </v-alert>
        <v-row v-if="!data.isNetworkLeader && (data.mentor || data.networkLeader)" class="mb-4">
          <v-col v-if="data.mentor" cols="12" :md="data.networkLeader ? 6 : 12">
            <v-card class="pa-4 h-100" variant="tonal" color="primary">
              <div class="d-flex align-center ga-3">
                <v-avatar color="primary" size="48">
                  <v-icon color="white">mdi-account-star</v-icon>
                </v-avatar>
                <div>
                  <div class="text-caption text-medium-emphasis">Наставник</div>
                  <div class="text-subtitle-1 font-weight-bold">{{ data.mentor.personName }}</div>
                  <div class="text-caption">{{ data.mentor.qualification }}</div>
                </div>
              </div>
              <div class="d-flex ga-3 mt-3 flex-wrap">
                <v-chip v-if="data.mentor.phone" size="small" variant="outlined" prepend-icon="mdi-phone" @click="copyText(data.mentor.phone)">
                  {{ data.mentor.phone }}
                </v-chip>
                <v-chip v-if="data.mentor.email" size="small" variant="outlined" prepend-icon="mdi-email" @click="copyText(data.mentor.email)">
                  {{ data.mentor.email }}
                </v-chip>
                <v-chip v-if="data.mentor.telegram" size="small" variant="outlined" prepend-icon="mdi-send" @click="openTelegram(data.mentor.telegram)">
                  {{ data.mentor.telegram }}
                </v-chip>
              </div>
            </v-card>
          </v-col>
          <v-col v-if="data.networkLeader" cols="12" :md="data.mentor ? 6 : 12">
            <v-card class="pa-4 h-100" variant="tonal" color="secondary">
              <div class="d-flex align-center ga-3">
                <v-avatar color="secondary" size="48">
                  <v-icon color="white">mdi-crown</v-icon>
                </v-avatar>
                <div>
                  <div class="text-caption text-medium-emphasis">Лидер сети</div>
                  <div class="text-subtitle-1 font-weight-bold">{{ data.networkLeader.personName }}</div>
                  <div class="text-caption">{{ data.networkLeader.qualification }}</div>
                </div>
              </div>
              <div class="d-flex ga-3 mt-3 flex-wrap">
                <v-chip v-if="data.networkLeader.phone" size="small" variant="outlined" prepend-icon="mdi-phone" @click="copyText(data.networkLeader.phone)">
                  {{ data.networkLeader.phone }}
                </v-chip>
                <v-chip v-if="data.networkLeader.email" size="small" variant="outlined" prepend-icon="mdi-email" @click="copyText(data.networkLeader.email)">
                  {{ data.networkLeader.email }}
                </v-chip>
                <v-chip v-if="data.networkLeader.telegram" size="small" variant="outlined" prepend-icon="mdi-send" @click="openTelegram(data.networkLeader.telegram)">
                  {{ data.networkLeader.telegram }}
                </v-chip>
              </div>
            </v-card>
          </v-col>
        </v-row>

        <!-- News -->
        <v-card class="mb-4 pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">
            <v-icon class="mr-1" size="20">mdi-newspaper</v-icon> Новости и объявления
          </div>
          <div v-if="!data.news?.length" class="text-center text-medium-emphasis pa-4">
            Нет новостей
          </div>
          <div v-for="news in data.news" :key="news.id" class="mb-3">
            <v-card variant="outlined" class="pa-3 news-item" :class="`news-${news.type || 'info'}`">
              <div class="d-flex justify-space-between align-center mb-1">
                <div class="d-flex align-center ga-2">
                  <v-icon size="16" :color="news.type === 'warning' ? 'warning' : news.type === 'success' ? 'success' : 'primary'">
                    {{ news.type === 'warning' ? 'mdi-alert-circle' : news.type === 'success' ? 'mdi-check-circle' : 'mdi-information' }}
                  </v-icon>
                  <div class="text-subtitle-2 font-weight-bold">{{ news.title }}</div>
                </div>
                <div class="text-caption text-medium-emphasis">{{ fmtDate(news.createdAt) }}</div>
              </div>
              <div class="text-body-2" style="white-space: pre-line">{{ news.content }}</div>
            </v-card>
          </div>
        </v-card>

        <!-- Team activity (consultants) -->
        <v-card v-if="data.teamActivity?.length" class="mb-4 pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">
            <v-icon class="mr-1" size="20">mdi-account-group</v-icon> Активность команды
          </div>
          <v-list density="compact">
            <v-list-item v-for="a in data.teamActivity" :key="a.date" class="px-0">
              <template #prepend>
                <v-avatar color="green" size="32">
                  <v-icon size="16" color="white">mdi-cash</v-icon>
                </v-avatar>
              </template>
              <v-list-item-title>{{ a.partnerName }}</v-list-item-title>
              <v-list-item-subtitle>
                {{ fmt(a.amount) }} ₽ · {{ fmt(a.personalVolume) }} баллов
              </v-list-item-subtitle>
              <template #append>
                <div class="text-caption text-medium-emphasis">{{ fmtDate(a.date) }}</div>
              </template>
            </v-list-item>
          </v-list>
        </v-card>
      </v-col>

      <!-- Right column -->
      <v-col cols="12" md="4">
        <!-- Мой день — статистика сотрудника за сегодня -->
        <MyDayWidget v-if="isStaff" class="mb-4" />

        <!-- Кто сейчас онлайн из коллег (только staff) -->
        <WhosOnlineWidget v-if="isStaff" class="mb-4" />

        <!-- Личные задачи: TODO-чек-лист с inline-формой добавления -->
        <MyTasksWidget class="mb-4" />

        <!-- Заметка-scratchpad с автосохранением -->
        <MyNoteWidget class="mb-4" />

        <!-- Quick actions -->
        <v-card class="mb-4 pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">
            <v-icon class="mr-1" size="20">mdi-lightning-bolt</v-icon> Быстрые действия
          </div>
          <div class="d-flex flex-column ga-2">
            <v-btn v-if="isConsultant" to="/finance/report" variant="tonal" prepend-icon="mdi-bank" block class="justify-start">
              Отчёт начислений
            </v-btn>
            <v-btn v-if="isConsultant" to="/finance/calculator" variant="tonal" prepend-icon="mdi-calculator" block class="justify-start">
              Калькулятор
            </v-btn>
            <v-btn v-if="isConsultant" to="/clients" variant="tonal" prepend-icon="mdi-account-group" block class="justify-start">
              Мои клиенты
            </v-btn>
            <v-btn to="/communication" variant="tonal" prepend-icon="mdi-chat" block class="justify-start">
              Обратная связь
              <v-badge v-if="data.unreadCount" :content="data.unreadCount" color="error" inline class="ml-2" />
            </v-btn>
            <v-btn to="/profile" variant="tonal" prepend-icon="mdi-account" block class="justify-start">
              Профиль
            </v-btn>
          </div>
        </v-card>

        <!-- Messages -->
        <v-card class="mb-4 pa-4">
          <div class="d-flex justify-space-between align-center mb-3">
            <div class="text-subtitle-1 font-weight-bold">
              <v-icon class="mr-1" size="20">mdi-chat</v-icon> Сообщения
            </div>
            <v-btn size="x-small" variant="text" to="/communication">Все →</v-btn>
          </div>
          <div v-if="!data.recentMessages?.length" class="text-center text-medium-emphasis pa-2 text-caption">
            Нет сообщений
          </div>
          <div v-for="msg in data.recentMessages" :key="msg.id" class="mb-2">
            <div class="d-flex align-center ga-2">
              <v-icon :color="msg.isIncoming ? 'primary' : 'success'" size="14">
                {{ msg.isIncoming ? 'mdi-arrow-down' : 'mdi-arrow-up' }}
              </v-icon>
              <div class="text-body-2 flex-grow-1" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                {{ msg.message }}
              </div>
              <v-chip v-if="msg.isIncoming && !msg.read" size="x-small" color="error">New</v-chip>
            </div>
          </div>
        </v-card>

        <!-- Events -->
        <v-card v-if="data.upcomingEvents?.length" class="mb-4 pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">
            <v-icon class="mr-1" size="20">mdi-calendar</v-icon> Ближайшие события
          </div>
          <div v-for="evt in data.upcomingEvents" :key="evt.id" class="mb-2">
            <router-link to="/contests" class="text-decoration-none">
              <div class="text-body-2 font-weight-medium">{{ evt.name }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ fmtDate(evt.start) }} — {{ fmtDate(evt.end) }}
              </div>
            </router-link>
          </div>
        </v-card>

        <!-- Staff tasks -->
        <v-card v-if="data.staffTasks" class="mb-4 pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">
            <v-icon class="mr-1" size="20">mdi-clipboard-check</v-icon> Задачи
          </div>
          <v-list density="compact">
            <v-list-item v-if="data.staffTasks.unverifiedRequisites > 0" to="/manage/requisites"
              prepend-icon="mdi-credit-card" :title="`Реквизиты на проверку: ${data.staffTasks.unverifiedRequisites}`" />
            <v-list-item v-if="data.staffTasks.unreadMessages > 0" to="/manage/communication"
              prepend-icon="mdi-email" :title="`Непрочитанных обращений: ${data.staffTasks.unreadMessages}`" />
            <v-list-item v-if="data.staffTasks.pendingPayments > 0" to="/manage/payments"
              prepend-icon="mdi-cash" :title="`Выплат в обработке: ${data.staffTasks.pendingPayments}`" />
            <div v-if="!data.staffTasks.unverifiedRequisites && !data.staffTasks.unreadMessages && !data.staffTasks.pendingPayments"
              class="text-center text-medium-emphasis pa-2 text-caption">
              Всё выполнено ✓
            </div>
          </v-list>
        </v-card>
      </v-col>
    </v-row>

    <v-progress-linear v-if="loading" indeterminate color="primary"
      style="position: fixed; top: 0; left: 0; right: 0; z-index: 9; height: 3px;" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useDisplay } from 'vuetify';
import { useAuthStore } from '../stores/auth';
import api from '../api';
import MyTasksWidget from '../components/MyTasksWidget.vue';
import MyNoteWidget from '../components/MyNoteWidget.vue';
import MyDayWidget from '../components/MyDayWidget.vue';
import WhosOnlineWidget from '../components/WhosOnlineWidget.vue';
import BrandWaves from '../components/BrandWaves.vue';
import { StatusChip, MoneyCell } from '../components';
import TaskCard from './Admin/WorkspaceTaskCard.vue';

const { mobile } = useDisplay();
import { fmt, fmtDate } from '../composables/useDesign';

const auth = useAuthStore();
const loading = ref(true);
const data = ref({});

const isConsultant = computed(() => auth.isConsultant);
const isStaff = computed(() =>
  /admin|backoffice|support|head|finance|calculations|corrections|education/i.test(auth.user?.role || '')
);
// admin-роль: показываем admin-hero + quick-links на системные разделы.
const isAdmin = computed(() => /(^|,)\s*admin\s*(,|$)/i.test(auth.user?.role || ''));

// Staff tasks секции — те же, что раньше отдавал /workspace API в staffTasks.
const sections = computed(() => data.value.staffTasks || {});

// Counter tiles наверху для staff — 6 ключевых счётчиков (Реквизиты,
// Сообщения, Выплаты, Акцепт, Активация, Незакрытые периоды). Каждая
// кликабельна → на соответствующий раздел.
const staffTiles = computed(() => [
  { key: 'requisites', label: 'Реквизиты на проверке', value: sections.value.unverifiedRequisites ?? 0,
    color: 'warning', icon: 'mdi-card-account-details', to: '/manage/requisites' },
  { key: 'messages',   label: 'Непрочитанные сообщения', value: sections.value.unreadMessages ?? 0,
    color: 'info', icon: 'mdi-email', to: '/manage/chat' },
  { key: 'payments',   label: 'Выплаты в обработке', value: sections.value.pendingPayments ?? 0,
    color: 'primary', icon: 'mdi-cash-clock', to: '/manage/payments' },
  { key: 'acceptance', label: 'Акцепт ждёт', value: sections.value.pendingAcceptance?.count ?? 0,
    color: 'warning', icon: 'mdi-file-document-check', to: '/manage/acceptance' },
  { key: 'activation', label: 'Активация истекает', value: sections.value.activationExpiring?.count ?? 0,
    color: 'error', icon: 'mdi-clock-alert', to: '/manage/partners/statuses' },
  { key: 'unclosed',   label: 'Незакрытые периоды', value: sections.value.unclosedPeriods?.count ?? 0,
    color: 'error', icon: 'mdi-calendar-lock' },
]);

// Admin-only quick-links — системные разделы без аналога в обычном меню.
const adminQuickLinks = [
  { to: '/manage/owner-dashboard', icon: 'mdi-crown',             color: 'primary',   label: 'Дашборд руководителя', sub: 'Сводка по платформе' },
  { to: '/manage/reconciliation',  icon: 'mdi-scale-balance',     color: 'info',      label: 'Сверка балансов',      sub: 'Snapshot vs live' },
  { to: '/manage/anomalies',       icon: 'mdi-alert-decagram',    color: 'warning',   label: 'Аномалии',             sub: 'Подозрительные данные' },
  { to: '/manage/cohorts',         icon: 'mdi-chart-line',        color: 'success',   label: 'Когорты',              sub: 'Retention heat-map' },
  { to: '/manage/permissions',     icon: 'mdi-shield-account',    color: 'secondary', label: 'Группы и права',       sub: 'Матрица доступа' },
  { to: '/manage/system-status',   icon: 'mdi-monitor-dashboard', color: 'error',     label: 'Статус системы',       sub: 'Сервисы и интеграции' },
];

function daysLeft(createdAt) {
  if (!createdAt) return 0;
  const expires = new Date(createdAt);
  expires.setDate(expires.getDate() + 90);
  const diff = Math.ceil((expires - Date.now()) / (1000 * 60 * 60 * 24));
  return Math.max(0, diff);
}

const greeting = computed(() => {
  const h = new Date().getHours();
  if (h < 6) return 'Доброй ночи';
  if (h < 12) return 'Доброе утро';
  if (h < 18) return 'Добрый день';
  return 'Добрый вечер';
});

const greetingEyebrow = computed(() => {
  const h = new Date().getHours();
  if (h < 6) return 'НОЧНАЯ СМЕНА';
  if (h < 12) return 'УТРО · РАБОЧИЙ ДЕНЬ';
  if (h < 18) return 'ДЕНЬ · В РАБОТЕ';
  return 'ВЕЧЕР · ЗАКРЫВАЕМ ДЕНЬ';
});

function copyText(text) { navigator.clipboard.writeText(text); }
function openTelegram(nick) {
  const clean = nick.replace('@', '');
  window.open(`https://t.me/${clean}`, '_blank');
}

onMounted(async () => {
  try {
    const { data: d } = await api.get('/workspace');
    data.value = d;
  } catch {}
  loading.value = false;
});
</script>

<style scoped>
/* DS hero — приветствие на полупрозрачном BrandWaves фоне.
   Соответствует ds-layouts.jsx::PartnerWorkspace hero. */
.ds-hero {
  border: 1px solid var(--ds-outline-variant, rgba(0, 0, 0, 0.06));
}
.hero-title {
  font: var(--ds-type-headline-l);
  letter-spacing: -0.01em;
  margin: 0;
}

/* KPI-сетка «Мои показатели» — 6 плиток через .ds-kpi (см. ds-tokens.css).
   На десктопе — 6 колонок, на планшете — 3, на мобилке — 2. */
.stats-kpi-row {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 12px;
}
@media (max-width: 1100px) {
  .stats-kpi-row { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 600px) {
  .stats-kpi-row { grid-template-columns: repeat(2, 1fr); }
}
.qual-name {
  font-size: 14px !important;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* DS news-item: цветной accent + DS surface
   (см. ds-patterns.jsx — паттерн alert/info-tile). */
.news-item {
  border-left: 4px solid transparent;
  border-radius: var(--ds-radius-md, 8px);
}
.news-warning { border-left-color: rgb(var(--v-theme-warning)); }
.news-success { border-left-color: rgb(var(--v-theme-success)); }
.news-info { border-left-color: rgb(var(--v-theme-info)); }

/* tabular-nums на партнёрских KPI */
:deep(.text-subtitle-1), :deep(.text-h4), :deep(.text-h6) {
  font-variant-numeric: tabular-nums;
}

/* ===== Admin hero + quick-link плитки (видны только админу) ===== */
.admin-hero {
  border-radius: var(--ds-radius-lg, 12px) !important;
  border: 1px solid rgba(var(--v-theme-primary), 0.18);
}
.grow { flex-grow: 1; }
.min-w-0 { min-width: 0; }

.admin-quick {
  cursor: pointer;
  border-radius: var(--ds-radius-lg, 12px) !important;
  transition: transform var(--ds-dur-fast, 120ms) var(--ds-ease-standard, ease),
              box-shadow var(--ds-dur-fast, 120ms) var(--ds-ease-standard, ease),
              border-color var(--ds-dur-fast, 120ms) var(--ds-ease-standard, ease);
}
.admin-quick:hover {
  transform: translateY(-2px);
  box-shadow: var(--ds-shadow-2, 0 4px 12px rgba(0,0,0,0.10));
  border-color: rgb(var(--v-theme-primary));
}

/* ===== Staff KPI-плитки (Реквизиты / Сообщения / Выплаты / etc.) ===== */
.staff-tile {
  cursor: pointer;
  border-radius: var(--ds-radius-lg, 12px) !important;
  transition: transform var(--ds-dur-fast, 120ms) var(--ds-ease-standard, ease),
              box-shadow var(--ds-dur-fast, 120ms) var(--ds-ease-standard, ease);
}
.staff-tile:hover {
  transform: translateY(-1px);
  box-shadow: var(--ds-shadow-2, 0 2px 8px rgba(0,0,0,0.08));
}
</style>

