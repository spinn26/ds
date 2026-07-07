<template>
  <v-layout>
    <!-- Sidebar -->
    <v-navigation-drawer v-model="drawer" :permanent="!mobile" :temporary="mobile"
      :rail="rail && !mobile" :width="260" :rail-width="72"
      class="sidebar-drawer">
      <div class="sidebar-header d-flex align-center pa-4" :class="{ 'justify-center': rail }">
        <div v-if="!rail" class="flex-grow-1">
          <div class="d-flex align-center ga-1">
            <img v-if="design.logoUrl" :src="design.logoUrl" alt="logo" style="max-height: 26px" />
            <template v-else>
              <span class="text-h6 font-weight-black text-primary">{{ design.logoText }}</span>
              <span class="text-caption text-medium-emphasis">{{ brandSuffix }}</span>
            </template>
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
            :color="item.adminSection ? 'brand' : 'primary'"
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

      <!-- Статус активности партнёра — слева в topbar. Только для
           consultant'ов и только на desktop (на mobile места нет). -->
      <v-chip v-if="!mobile && isConsultant && statusInfo?.activityName"
        size="small" variant="tonal" :color="statusColor"
        class="ml-2 status-topbar-chip"
        :title="statusInfo?.daysRemaining != null
          ? 'Смена статуса ' + (statusInfo.daysRemaining > 0
              ? 'через ' + statusInfo.daysRemaining + ' дн.'
              : 'просрочена')
          : ''">
        <v-icon start size="14">mdi-shield-check</v-icon>
        <span class="font-weight-medium">{{ statusInfo.activityName }}</span>
        <template v-if="statusInfo.yearPeriodEnd">
          <span class="mx-1 text-medium-emphasis">·</span>
          <span class="text-medium-emphasis">до {{ fmtShortDate(statusInfo.yearPeriodEnd) }}</span>
        </template>
        <template v-if="statusInfo?.daysRemaining != null && statusInfo.daysRemaining <= 90">
          <span class="mx-1 text-medium-emphasis">·</span>
          <v-icon size="13" class="me-1"
            :color="statusInfo.daysRemaining <= 30 ? 'error' : 'warning'">mdi-timer-outline</v-icon>
          <span :class="statusInfo.daysRemaining <= 30 ? 'text-error' : 'text-warning'">
            {{ statusInfo.daysRemaining }} дн.
          </span>
        </template>
      </v-chip>

      <!-- Баланс по комиссионным — в верхней строке рядом со статусом.
           Зелёный — к выплате партнёру, красный — переплата/долг. Клик ведёт
           в отчёт начислений. Только для consultant'ов на desktop. -->
      <v-chip v-if="!mobile && isConsultant && statusInfo?.commissionBalance != null"
        size="small" variant="tonal"
        :color="statusInfo.commissionBalance >= 0 ? 'success' : 'error'"
        class="ml-2 balance-topbar-chip" to="/finance/report"
        title="Текущий баланс по комиссионным. Зелёный — к выплате, красный — переплата/долг.">
        <v-icon start size="14">mdi-wallet-outline</v-icon>
        <span class="text-medium-emphasis me-1">Баланс:</span>
        <span class="font-weight-medium">{{ fmtMoney(statusInfo.commissionBalance) }}</span>
      </v-chip>

      <v-spacer />

      <!-- Статус системы — мигающий кружок + лейбл; на всех страницах
           всем пользователям, кликом ведёт на /status. -->
      <SystemStatusChip class="mr-2" />

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
          <v-divider />
          <div class="d-flex align-center justify-space-between pa-2 pl-3">
            <span class="text-caption">Звук уведомлений</span>
            <v-switch v-model="notifSoundOn" hide-details density="compact" color="primary"
              @update:model-value="onSoundToggle" />
          </div>
        </v-card>
      </v-menu>

      <!-- User menu -->
      <v-menu min-width="280" :close-on-content-click="false">
        <template #activator="{ props }">
          <v-avatar v-bind="props" :color="auth.isAdmin ? 'secondary' : 'primary'" size="36" class="cursor-pointer ml-1">
            <v-img v-if="auth.user?.avatarUrl" :src="auth.user.avatarUrl" cover />
            <span v-else class="text-caption font-weight-bold">{{ initials }}</span>
          </v-avatar>
        </template>
        <v-card rounded="lg" elevation="8">
          <v-card-text class="pa-4">
            <div class="d-flex align-center ga-3 mb-3">
              <div class="position-relative">
                <v-avatar :color="auth.isAdmin ? 'secondary' : 'primary'" size="56">
                  <v-img v-if="auth.user?.avatarUrl" :src="auth.user.avatarUrl" cover />
                  <span v-else class="text-h5 font-weight-bold">{{ initials }}</span>
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
    <v-main class="content-main" :class="{ 'content-main--full-bleed': isFullBleedRoute }">
      <v-container fluid :class="isFullBleedRoute ? 'pa-0' : 'pa-5 pa-md-8'">
        <!-- Системные объявления (админ → /admin/announcements). -->
        <v-alert v-for="a in visibleAnnouncements" :key="a.id"
          :type="a.type" variant="tonal" density="comfortable" class="mb-3"
          :closable="a.dismissible" @click:close="dismissAnnouncement(a.id)">
          <div class="font-weight-bold">{{ a.title }}</div>
          <div v-if="a.body" class="text-body-2" style="white-space: pre-line">{{ a.body }}</div>
        </v-alert>

        <!-- Глобальный баннер: реквизиты на ручной проверке.
             Скрываем на /profile и /education — там партнёр и так знает.
             Скрываем на чате (full-bleed) — не ломаем layout. -->
        <v-alert
          v-if="showRequisitesPendingBanner"
          type="warning" variant="tonal" density="compact"
          class="mb-3"
          icon="mdi-clock-outline"
          closable
        >
          <div class="text-body-2">
            <strong>Ожидайте проверки документов.</strong>
            Финменеджер вручную проверяет ваши реквизиты ИП (УСН).
            Подписание документов, продажа продуктов и финансовые операции
            будут доступны после верификации.
          </div>
          <div class="text-caption mt-1">
            <router-link to="/profile?tab=requisites" class="text-primary">
              Открыть реквизиты
            </router-link>
            · Затягивается?
            <a href="https://t.me/DS_Helpdesk" target="_blank" rel="noopener" class="text-primary">@DS_Helpdesk</a>
          </div>
        </v-alert>

        <!-- Глобальный баннер: ОТКАЗ в верификации реквизитов. Красный,
             не закрываемый, на всех страницах (кроме full-bleed чата). Причина:
             текст финменеджера / ИП не на своё имя / режим не УСН. -->
        <v-alert
          v-if="showRequisitesRejectedBanner"
          type="error" variant="tonal" density="compact"
          class="mb-3"
          icon="mdi-close-octagon-outline"
        >
          <div class="text-body-2">
            <strong>Вам отказано в верификации реквизитов.</strong>
            <template v-if="requisitesRejectionReason"> Причина: {{ requisitesRejectionReason }}</template>
          </div>
          <div class="text-caption mt-1">
            <router-link to="/profile?tab=requisites" class="text-primary">
              Исправить реквизиты
            </router-link>
            · Вопросы —
            <a href="https://t.me/DS_Helpdesk" target="_blank" rel="noopener" class="text-primary">@DS_Helpdesk</a>
          </div>
        </v-alert>

        <!-- Глобальный баннер: выплаты приостановлены в связи со сменой
             реквизитов (по просьбе партнёра / решению финменеджера). -->
        <v-alert
          v-if="showPaymentsSuspendedBanner"
          type="warning" variant="tonal" density="compact"
          class="mb-3"
          icon="mdi-pause-circle-outline"
        >
          <div class="text-body-2">
            <strong>Выплаты приостановлены в связи с вашей просьбой по смене реквизитов.</strong>
            Они будут возобновлены после проверки новых реквизитов финменеджером.
          </div>
        </v-alert>

        <!-- Глобальный баннер: профиль активного ФК не заполнен полностью.
             Не закрываемый — напоминание держится, пока партнёр не заполнит
             личные данные. Реквизиты ИП/банк в это требование НЕ входят. -->
        <v-alert
          v-if="showProfileIncompleteBanner"
          type="warning" variant="tonal" density="compact"
          class="mb-3"
          icon="mdi-account-alert-outline"
        >
          <div class="text-body-2">
            <strong>Заполните профиль.</strong>
            Для активных партнёров обязательны личные данные.
            <template v-if="profileMissingLabels">
              Не хватает: {{ profileMissingLabels }}.
            </template>
          </div>
          <div class="text-caption mt-1">
            <router-link :to="profileFixLink" class="text-primary">
              Заполнить профиль
            </router-link>
          </div>
        </v-alert>

        <router-view />
      </v-container>
    </v-main>

    <!-- Глобальный confirm-диалог (per useConfirm()). Mount-once-per-app. -->
    <ConfirmDialog ref="confirmRef" />

    <!-- Глобальный snackbar (per useSnackbar()). Все .showError/.showSuccess
         из любого компонента отрисуются здесь. -->
    <GlobalSnackbar />

    <!-- Блокирующий акцепт Оферты. Появляется только когда финменеджер
         верифицировал реквизиты ИП, а Оферта ещё не подписана. -->
    <OfferAcceptanceDialog :open="showOfferDialog" @accepted="onOfferAccepted" />

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

    <!-- Per spec ✅Написать ген.директору: форма с чекбоксом «анонимно»,
         сообщение уходит в Telegram-группу через POST /api/v1/founder-message. -->
    <v-dialog v-model="quickMsg.open" max-width="560" :persistent="quickMsg.sending">
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="primary">{{ quickMsg.icon }}</v-icon>
          {{ quickMsg.subject }}
        </v-card-title>
        <v-card-text>
          <v-checkbox v-model="quickMsg.anonymous" label="Отправить анонимно"
            density="compact" hide-details color="primary" class="mb-2"
            hint="Если включено — Ваши ФИО и email не передаются основателю"
            persistent-hint />
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

    <!-- Глобальный поиск (Ctrl+K). Слушает keydown глобально, можно
         открыть программно через ref. -->
    <GlobalSearch />

    <!-- Плавающий виджет «Мои чаты» снизу-справа.
         Скрывается на самой странице чата. -->
    <ChatLauncher />

  </v-layout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { useDisplay, useTheme } from 'vuetify';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useSnackbar } from '../composables/useSnackbar';
import OnboardingQuestionnaire from '../components/OnboardingQuestionnaire.vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import GlobalSnackbar from '../components/GlobalSnackbar.vue';
import OfferAcceptanceDialog from '../components/OfferAcceptanceDialog.vue';
import GlobalSearch from '../components/GlobalSearch.vue';
import SystemStatusChip from '../components/SystemStatusChip.vue';
import ChatLauncher from '../components/ChatLauncher.vue';
import { provideConfirm } from '../composables/useConfirm';
import { useNotificationSound } from '../composables/useNotificationSound';
import api from '../api';
import { useDesignStore } from '../stores/design';

const design = useDesignStore();
// Суффикс бренда в шапке = brandName без короткого logoText («ПЛАТФОРМА»).
const brandSuffix = computed(() => {
  const name = (design.brandName || '').trim();
  const mark = (design.logoText || '').trim();
  const suffix = mark && name.startsWith(mark) ? name.slice(mark.length).trim() : name;
  return suffix || 'ПЛАТФОРМА';
});

// Системные объявления (баннеры). Закрытые помним в localStorage по id.
const announcements = ref([]);
const dismissedAnnouncements = ref(loadDismissed());
function loadDismissed() {
  try { return JSON.parse(localStorage.getItem('dismissed-announcements') || '[]'); } catch { return []; }
}
const visibleAnnouncements = computed(() =>
  announcements.value.filter(a => !dismissedAnnouncements.value.includes(a.id))
);
async function loadAnnouncements() {
  try {
    const { data } = await api.get('/announcements/active');
    announcements.value = data.announcements || [];
  } catch { /* ignore */ }
}
function dismissAnnouncement(id) {
  if (!dismissedAnnouncements.value.includes(id)) {
    dismissedAnnouncements.value.push(id);
    try { localStorage.setItem('dismissed-announcements', JSON.stringify(dismissedAnnouncements.value)); } catch {}
  }
}

const { showNotification } = useSnackbar();
const { play: playNotifSound, isEnabled: soundEnabled, setEnabled: setSoundEnabled } = useNotificationSound();

const notifSoundOn = ref(soundEnabled());
function onSoundToggle(value) {
  setSoundEnabled(!!value);
  if (value) playNotifSound(); // короткий пример звука при включении
}

const confirmRef = ref(null);
provideConfirm(confirmRef);
function fmtShortDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

// Деньги для topbar-чипа баланса: «1 234 ₽» (без копеек, разряды по-русски).
function fmtMoney(v) {
  const n = Number(v) || 0;
  return n.toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' ₽';
}

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const theme = useTheme();
const { mobile } = useDisplay();
const drawer = ref(true);

// Чат-страницы должны занимать всю доступную высоту v-main без отступов
// контейнера, иначе при низком вьюпорте поле ввода уходит ниже экрана
// (жалоба Джабиевой 2026-05-13).
const isFullBleedRoute = computed(() => {
  return route.path === '/chat' || route.path === '/manage/chat';
});

// Баннер ручной проверки реквизитов. Показываем партнёру (не staff),
// у которого статус verificationStatus = 'pending'. Скрыт на /profile,
// /education* и full-bleed чате, чтобы не дублировать локальные плашки
// и не ломать layout.
const showRequisitesPendingBanner = computed(() => {
  if (auth.isStaff) return false;
  if (auth.user?.requisitesVerificationStatus !== 'pending') return false;
  if (isFullBleedRoute.value) return false;
  const path = route.path || '';
  if (path.startsWith('/profile')) return false;
  if (path.startsWith('/education')) return false;
  return true;
});

// Плашка ОТКАЗА в верификации — на всех страницах (кроме full-bleed чата,
// чтобы не ломать layout). В отличие от pending показываем и на /profile —
// клиент просил «на всех страницах».
const requisitesRejectionReason = computed(() => auth.user?.requisitesRejectionReason || '');
const showRequisitesRejectedBanner = computed(() => {
  if (auth.isStaff) return false;
  if (auth.user?.requisitesVerificationStatus !== 'rejected') return false;
  if (isFullBleedRoute.value) return false;
  return true;
});

// Выплаты приостановлены в связи со сменой реквизитов (авто при подаче запроса
// на смену или вручную финменеджером). Баннер на всех страницах партнёра.
const showPaymentsSuspendedBanner = computed(() => {
  if (auth.isStaff) return false;
  if (auth.user?.paymentsSuspended !== true) return false;
  if (isFullBleedRoute.value) return false;
  return true;
});

// Единое блокирующее окно акцепта документов при входе (2026-06-02). Больше
// НЕ завязано на верификацию реквизитов — показывается любому партнёру/
// консультанту, у кого acceptance=false (offerAccepted), пока он не примет
// все документы. Скрыто для staff.
const showOfferDialog = computed(() => {
  if (auth.isStaff) return false;
  return auth.user?.offerAccepted === false;
});

async function onOfferAccepted() {
  // Перечитываем профиль, чтобы offerAccepted переключился в true и
  // модалка закрылась. fetchUser обновляет auth.user из /auth/me.
  try { await auth.fetchUser(); } catch {}
}

// Принудительное заполнение профиля для активных ФК (2026-06-02). Бэк в
// /auth/me отдаёт profileComplete=false + profileMissing[] для активного
// партнёра с незаполненными личными данными (реквизиты ИП/банк в гейт НЕ
// входят). При входе кидаем на /profile (см. onMounted), а баннер держим
// как напоминание на всех страницах, пока профиль не заполнен. Скрыт для
// staff и на full-bleed чате.
const showProfileIncompleteBanner = computed(() => {
  if (auth.isStaff) return false;
  if (auth.user?.profileComplete !== false) return false;
  if (isFullBleedRoute.value) return false;
  return true;
});
const profileMissingLabels = computed(() =>
  (auth.user?.profileMissing || []).map(m => m.label).join(', ')
);
// Deep-link на нужную вкладку профиля: ведём в первую секцию с пропусками
// (личные данные приоритетнее реквизитов). Явный ?tab= обязателен — без него
// клик из баннера, когда партнёр уже на /profile, не переключал бы вкладку.
const profileFixLink = computed(() => {
  const missing = auth.user?.profileMissing || [];
  const tab = missing.length === 0 || missing.some(m => m.section === 'personal')
    ? 'info'
    : 'requisites';
  return { path: '/profile', query: { tab } };
});

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
  loadAnnouncements();
  try {
    const { data } = await api.get('/profile');
    statusInfo.value = {
      ...data.statusInfo,
      referralCode: data.referral?.referralCode,
      referralLink: data.referral?.referralLink,
      canInvite: data.referral?.canInvite,
      commissionBalance: data.commissionBalance,
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
    // Принудительно отправляем активного ФК с незаполненным профилем на
    // /profile при входе. Дальше навигация свободна — баннер остаётся.
    if (!isStaff.value && auth.user?.profileComplete === false
        && !route.path.startsWith('/profile')) {
      router.replace(profileFixLink.value);
    }
  } catch {}
  loadNotifications();
  loadChatUnread();

  // Polling с учётом visibility — если вкладка скрыта/свёрнута, таймер
  // не работает. При возврате в видимое состояние — сразу дёргаем
  // свежие данные и перезапускаем интервал. Это фиксит зависание UI
  // после 5+ минут idle (DB pool / PHP-FPM / Socket засыпают, первый
  // запрос уходит в таймаут).
  // 15 сек (раньше было 30) — fallback на случай, если сокет отвалился
  // и события `chat:new-message` не доходят: жалоба Богдановой
  // «не всегда срабатывает счётчик» 2026-05-21.
  const POLL_MS = 15000;
  // Heartbeat для виджета «Кто онлайн»: бэк ставит WebUser.last_seen_at = now().
  const sendHeartbeat = () => {
    if (document.visibilityState !== 'visible') return;
    api.put('/me/heartbeat').catch(() => {});
  };
  sendHeartbeat();
  const startPolling = () => {
    if (unreadInterval) clearInterval(unreadInterval);
    unreadInterval = setInterval(() => {
      if (document.visibilityState !== 'visible') return;
      loadNotifications();
      loadChatUnread();
      sendHeartbeat();
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

      // Чат-уведомление: обновляем счётчик с сервера (без локального
      // оптимистичного инкремента — раньше счётчик «не всегда срабатывал»
      // из-за рассинхрона между inc и polling-refresh), играем звук,
      // показываем всплывашку с кнопкой «Открыть».
      if (data.type === 'chat') {
        loadChatUnread();
        playNotifSound();
        const text = data.title
          ? `${data.title}${data.message ? ': ' + data.message : ''}`
          : (data.message || 'Новое сообщение в чате');
        showNotification(text, {
          label: 'Открыть',
          to: data.link || '/chat',
        });
      } else {
        // Любое другое уведомление — звук тише и без всплывашки.
        playNotifSound();
      }
    });

    // Дополнительный live-канал на случай, если backend будущих фич
    // эмитит чат-события без NotificationController (e.g. групповой
    // тикет — partner не уведомляется через create, а только через
    // chat:new-message). Слушаем и тут — server-side фильтрация по
    // комнатам гарантирует, что чужие сообщения не прилетят.
    // Если до этого пришёл `notification` (type=chat) — звук/тоаст уже
    // были, дебаунс внутри useNotificationSound и таймаут snackbar
    // предотвратят дубликат.
    notifSocket.on('chat:new-message', () => {
      loadChatUnread();
      playNotifSound();
    });

    // Глобальный broadcast «unread поменялся» (ChatController::sendMessage
    // эмитит на каждое отправленное сообщение). Каждый онлайн-клиент
    // через debounce дёргает /chat/unread-count и обновляет свой бейдж.
    // Это фиксит кейс, когда NotificationController::create не вызывался
    // (тикет в общей категории без recipient_id) — раньше счётчик
    // обновлялся только через 30-сек polling или после refresh.
    let unreadDebounce = null;
    const refreshUnread = () => {
      clearTimeout(unreadDebounce);
      unreadDebounce = setTimeout(loadChatUnread, 200);
    };
    notifSocket.on('chat:unread-changed', refreshUnread);

    // После (re)connect — подтянуть свежий счётчик, т.к. в offline-период
    // мы пропустили все события. Без этого после короткого разрыва WiFi
    // индикатор оставался устаревшим до следующего polling.
    notifSocket.on('connect', () => {
      loadChatUnread();
      loadNotifications();
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
  if (id === 3) return 'error';     // Терминирован — per spec ✅Статусы партнеров §2 col.2 (красная)
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

// Quick-message dialog «Написать собственику» per spec ✅Написать собственику.
// Отправляется в Telegram-группу через POST /founder-message с флагом anonymous.
const { showSuccess, showError } = useSnackbar();
const quickMsg = ref({ open: false, subject: '', icon: 'mdi-email-edit', message: '', anonymous: false, sending: false });

function openQuickMsg(subject, icon = 'mdi-email-edit') {
  quickMsg.value = { open: true, subject, icon, message: '', anonymous: false, sending: false };
}

async function submitQuickMsg() {
  const msg = quickMsg.value.message.trim();
  if (!msg) return;
  quickMsg.value.sending = true;
  try {
    await api.post('/founder-message', {
      message: msg,
      anonymous: quickMsg.value.anonymous,
    });
    quickMsg.value.open = false;
    showSuccess('Сообщение отправлено основателю');
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
  userRoles.value.some(r => ['admin', 'backoffice', 'support', 'finance', 'head', 'calculations', 'corrections', 'education', 'invest'].includes(r))
);

// Видимость секций — единый источник auth-store.permissions (из БД через
// GET /auth/me/permissions, кэш в localStorage → доступен и на холодном
// старте до ответа сервера). admin получает все секции из резолвера
// (config/permissions.sections → full), поэтому спец-обработка не нужна.
const availableSections = computed(() => new Set(Object.keys(auth.permissions || {})));

const cabinetName = computed(() => {
  if (userRoles.value.includes('admin')) return 'Администратор';
  if (userRoles.value.includes('backoffice')) return 'Кабинет БЭК';
  if (userRoles.value.includes('support')) return 'Техподдержка';
  if (userRoles.value.includes('head')) return 'Руководитель';
  if (userRoles.value.includes('finance')) return 'Фин. менеджер';
  if (userRoles.value.includes('calculations')) return 'Расчёты';
  if (userRoles.value.includes('corrections')) return 'Правки';
  if (userRoles.value.includes('education')) return 'Куратор обучения';
  if (userRoles.value.includes('invest')) return 'Инвест департамент';
  return null;
});

// === MENU ITEMS ===
// Partner menu — exact per spec (role: consultant)
// Staff sections — grouped per spec, no education editing
const menuItems = [
  // ---- Partner menu (consultant) ----
  // Shown to everyone (partner and staff) — leads to Workspace
  { label: 'Главная', icon: 'mdi-home-outline', path: '/' },
  // Задачи и проекты — только для сотрудников (admin/staff), не для партнёров.
  { label: 'Задачи', icon: 'mdi-checkbox-marked-outline', path: '/tasks', adminSection: 'tasks' },

  { group: 'Обзор', partner: true },
  { label: 'Дашборд', icon: 'mdi-view-dashboard-outline', path: '/dashboard', partner: true },
  { label: 'Отчёт начислений', icon: 'mdi-bank-outline', path: '/finance/report', partner: true },
  { label: 'Реестр выплат', icon: 'mdi-cash-register', path: '/my-payments', partner: true },

  { group: 'Работа', partner: true },
  { label: 'Калькулятор объёмов', icon: 'mdi-calculator', path: '/finance/calculator', partner: true },
  { label: 'Мои клиенты', icon: 'mdi-account-group-outline', path: '/clients', partner: true },
  { label: 'Контракты клиентов', icon: 'mdi-file-document-outline', path: '/contracts', partner: true },
  { label: 'Контракты команды', icon: 'mdi-folder-account-outline', path: '/contracts/team', partner: true },
  { label: 'Структура', icon: 'mdi-sitemap-outline', path: '/structure', partner: true },

  { group: 'Развитие', partner: true },
  { label: 'Обучение', icon: 'mdi-school-outline', path: '/education', partner: true },
  { label: 'База знаний', icon: 'mdi-book-education-outline', path: '/education/kb', partner: true },
  { label: 'Инструкции', icon: 'mdi-book-open-variant', path: '/instructions', partner: true },
  { label: 'Статус системы', icon: 'mdi-monitor-dashboard', path: '/status', partner: true },
  { label: 'Продукты', icon: 'mdi-package-variant-closed', path: '/products', partner: true },
  // Внешний сервис «ФинРывок» — открывается в новой вкладке.
  { label: 'ФинРывок', icon: 'mdi-rocket-launch-outline', path: '', partner: true,
    action: () => window.open('https://ds.igron.games/auth/login', '_blank', 'noopener') },
  // Конкурсы скрыты у всех кабинетов по запросу 2026-05-05.
  // { label: 'Конкурсы и события', icon: 'mdi-trophy-outline', path: '/contests', partner: true },

  { group: 'Связь', partner: true },
  // Общий вход в раздел тикетов — посмотреть все свои переписки.
  { label: 'Мои обращения', icon: 'mdi-chat-outline', path: '/chat', partner: true },
  // Тех.поддержка вынесена в Telegram (общая команда DS Helpdesk). Решено на встрече 2026-05-26:
  // до доработки маршрутизации тикетов на платформе техвопросы идут в TG,
  // а на платформе остаются только профильные чаты (бэк-офис по контрактам
  // + верификация реквизитов с Катей).
  { label: 'Тех. поддержка', icon: 'mdi-lifebuoy', path: '', partner: true,
    action: () => window.open('https://t.me/DS_Helpdesk', '_blank', 'noopener') },
  // Поддержка по продукту = бэк-офис (контракты, клиенты, продукты).
  { label: 'Поддержка по продукту', icon: 'mdi-package-variant', path: '/chat?new=backoffice', partner: true },
  // Верификация реквизитов = категория «Начисления и выплаты» (Катя Богданова).
  { label: 'Верификация реквизитов', icon: 'mdi-credit-card-check', path: '/chat?new=accruals', partner: true },
  { label: 'Написать ген.директору', icon: 'mdi-email-edit-outline', path: '', partner: true,
    action: () => openQuickMsg('Сообщение ген.директору', 'mdi-email-edit-outline') },
  // Per spec ✅Оставить кейс — внешняя ссылка, без диалога.
  { label: 'Оставить кейс', icon: 'mdi-briefcase-plus-outline', path: '', partner: true,
    action: () => window.open('https://dsconsalting.academy/bankcases', '_blank', 'noopener') },

  // ---- Staff sections (grouped per spec) ----
  // Инструменты
  { group: 'Инструменты', adminSection: 'calculator' },
  { label: 'Калькулятор объёмов', icon: 'mdi-calculator', path: '/finance/calculator', adminSection: 'calculator' },
  { label: 'Структура', icon: 'mdi-sitemap', path: '/structure', adminSection: 'structure' },

  // «Рабочий стол» убран из меню 2026-05-26: /manage/workspace теперь
  // ведёт на тот же Workspace.vue, что и «Главная» (/). Дублирование
  // пункта путало админов. Сам роут оставлен на случай старых букмарков.

  // Компания (оргструктура) — всем staff
  { group: 'Компания', adminSection: 'org-structure' },
  { label: 'Структура компании', icon: 'mdi-sitemap-outline', path: '/manage/org-structure', adminSection: 'org-structure' },

  // Данные
  { group: 'Данные', adminSection: 'partners' },
  { label: 'Партнёры', icon: 'mdi-account-search', path: '/manage/partners', adminSection: 'partners' },
  { label: 'Статусы партнёров', icon: 'mdi-calendar-clock', path: '/manage/partners/statuses', adminSection: 'statuses' },
  { label: 'Клиенты', icon: 'mdi-account-group', path: '/manage/clients', adminSection: 'clients' },
  { label: 'Менеджер контрактов', icon: 'mdi-file-document-edit', path: '/manage/contracts', adminSection: 'contracts' },
  { label: 'Загрузка контрактов', icon: 'mdi-upload', path: '/manage/contracts/upload', adminSection: 'upload' },
  { label: 'Акцепт документов', icon: 'mdi-check-circle', path: '/manage/acceptance', adminSection: 'acceptance' },
  { label: 'Реквизиты', icon: 'mdi-credit-card', path: '/manage/requisites', adminSection: 'requisites' },
  { label: 'Смена реквизитов', icon: 'mdi-bank-transfer', path: '/manage/bank-changes', adminSection: 'bank-changes' },
  { label: 'Перестановки', icon: 'mdi-history', path: '/manage/transfers', adminSection: 'transfers' },

  // Финансы
  { group: 'Финансы', adminSection: 'import' },
  { label: 'Импорт транзакций', icon: 'mdi-upload', path: '/manage/transactions/import', adminSection: 'import' },
  { label: 'Транзакции', icon: 'mdi-swap-horizontal', path: '/manage/transactions', adminSection: 'transactions' },
  { label: 'Комиссии', icon: 'mdi-receipt', path: '/manage/commissions', adminSection: 'commissions' },
  { label: 'Пул', icon: 'mdi-cash-multiple', path: '/manage/pool', adminSection: 'pool' },
  { label: 'Квалификации', icon: 'mdi-chart-bar', path: '/manage/qualifications', adminSection: 'qualifications' },
  // Периоды — закрытие месяца, перерасчёт штрафов (§5).
  // adminSection='reports-access' = scope из cabinetPermissions, выдан
  // только admin (sentinel '*') и calculations (Богданова Е.).
  { label: 'Периоды', icon: 'mdi-calendar-range', path: '/manage/periods', adminSection: 'reports-access' },

  // Выплаты
  { group: 'Выплаты', adminSection: 'charges' },
  { label: 'Прочие начисления', icon: 'mdi-bank', path: '/manage/charges', adminSection: 'charges' },
  { label: 'Реестр выплат', icon: 'mdi-cash', path: '/manage/payments', adminSection: 'payments' },

  // Обучение (LMS) — конструктор курсов и куратор обучения партнёров
  { group: 'Обучение', adminSection: 'education' },
  { label: 'Конструктор курсов', icon: 'mdi-school', path: '/manage/education', adminSection: 'education' },
  { label: 'База знаний', icon: 'mdi-book-open-variant', path: '/manage/kb', adminSection: 'kb' },
  { label: 'Проверка домашек', icon: 'mdi-clipboard-edit-outline', path: '/manage/homework', adminSection: 'homework' },
  { label: 'Категории курсов', icon: 'mdi-folder-multiple', path: '/manage/education/categories', adminSection: 'education-categories' },
  { label: 'Статистика обучения', icon: 'mdi-chart-line', path: '/manage/education/analytics', adminSection: 'education-analytics' },
  { label: 'Анкеты партнёров', icon: 'mdi-clipboard-account', path: '/manage/partner-questionnaires', adminSection: 'partner-questionnaires' },

  // Прочее
  { group: 'Прочее', adminSection: 'products' },
  { label: 'Продукты', icon: 'mdi-package-variant-closed', path: '/manage/products', adminSection: 'products' },
  { label: 'Конкурсы и события', icon: 'mdi-trophy', path: '/manage/contests', adminSection: 'contests' },
  { label: 'Аналитика чата', icon: 'mdi-chart-box-outline', path: '/manage/chat/analytics', adminSection: 'chat-analytics' },
  { label: 'Отчёты', icon: 'mdi-file-chart', path: '/manage/reports', adminSection: 'reports' },
  { label: 'Справочники для расчёта', icon: 'mdi-currency-usd', path: '/manage/currencies', adminSection: 'currencies' },
  { label: 'Инструкции', icon: 'mdi-book-edit-outline', path: '/manage/instructions', adminSection: 'instructions' },

  // Помощь — общая группа для чатов, тех-обращений и статуса системы.
  { group: 'Помощь', adminSection: 'communication' },
  { label: 'Чат / Тикеты', icon: 'mdi-chat-processing', path: '/manage/chat', adminSection: 'communication' },
  // «Тех. проблема» — staff-side кнопка «написать о технической проблеме».
  // Открывает PartnerChat.vue с предзаполненной формой category=support;
  // тикет создаётся от имени staff-аккаунта и попадает в
  // /manage/support desk, где его обрабатывают admin/support/head.
  { label: 'Тех. проблема', icon: 'mdi-bug', path: '/chat?new=support', adminSection: 'communication' },
  // Рабочий стол техподдержки: KPI, тикеты department=support и инциденты
  // (любой категории). Доступен только admin (см. tab-fix 2026-05-10).
  { label: 'Тех. поддержка', icon: 'mdi-lifebuoy', path: '/manage/support', adminSection: 'support-desk', adminOnly: true },
  { label: 'Статус системы', icon: 'mdi-monitor-dashboard', path: '/manage/system-status', adminSection: 'support-desk', adminOnly: true },
  // Управление группами и правами — только admin (раздел 'permissions'
  // присутствует только в его карте прав через FULL-сентинел).
  { label: 'Группы и права', icon: 'mdi-shield-account', path: '/manage/permissions', adminSection: 'permissions' },

  // Аналитика — для руководителя / админа
  { group: 'Аналитика', adminSection: 'owner-dashboard' },
  { label: 'Дашборд руководителя', icon: 'mdi-crown', path: '/manage/owner-dashboard', adminSection: 'owner-dashboard' },
  { label: 'Матрица продаж', icon: 'mdi-table-large', path: '/manage/reports/sales-matrix', adminSection: 'sales-matrix' },
  { label: 'Курсы для отчётов', icon: 'mdi-currency-rub', path: '/manage/management-currencies', adminSection: 'management-currencies' },
  { label: 'Сверка балансов', icon: 'mdi-scale-balance', path: '/manage/reconciliation', adminSection: 'reconciliation' },
  { label: 'Аномалии', icon: 'mdi-alert-decagram', path: '/manage/anomalies', adminSection: 'anomalies' },
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
  if (item.adminOnly) return auth.isAdmin;
  if (item.staffOnly) return isStaff.value;
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

/* Topbar status-chip — фон полупрозрачный, чтобы blur topbar'а
   просвечивал через чип (apple-style). */
.status-topbar-chip {
  background: rgba(var(--v-theme-on-surface), 0.04) !important;
}

.content-main {
  background: rgba(var(--v-theme-background), 1);
}

/* Чат-страницы: v-main как flex-контейнер с фиксированной высотой viewport,
   чтобы дочерний .chat-wrap мог занять всё доступное пространство через
   height:100% без жёсткого calc(100vh - X), который ломается при разных
   высотах AppBar/bottom-nav. */
.content-main--full-bleed {
  display: flex !important;
  flex-direction: column;
}
.content-main--full-bleed > :deep(.v-container) {
  flex: 1 1 0;
  min-height: 0;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

/* DS nav items: rounded-md, mint-soft active state с primary-цветом
   текста и иконки. См. desing/ds-primitives.jsx .ds-nav-item. */
.menu-item {
  transition: background-color var(--ds-dur-fast, 120ms) ease,
              color var(--ds-dur-fast, 120ms) ease;
  border-radius: var(--ds-radius-md, 8px) !important;
  margin: 2px 8px !important;
  font-weight: 500;
}

.menu-item:hover {
  background-color: var(--ds-overlay, rgba(var(--v-theme-on-surface), 0.04));
}

/* Active item — macOS-style: subtle mint fill + читаемый on-surface
   текст. Без зелёного шрифта (низкий контраст на mint fone). */
.main-nav-list :deep(.v-list-item--active) {
  background: rgba(var(--v-theme-primary), 0.1);
  color: rgb(var(--v-theme-on-surface));
  font-weight: 600;
}
.main-nav-list :deep(.v-list-item--active .v-icon) {
  color: rgb(var(--v-theme-primary));
}
.main-nav-list :deep(.v-list-item--active .v-list-item-title) {
  font-weight: 600 !important;
}

/* Section headers: тонкие подписи UPPERCASE по DS spec
   (см. desing/ds-primitives.jsx .ds-nav-section). */
.main-nav-list :deep(.v-list-subheader.menu-group-header) {
  min-height: 28px !important;
  padding-top: 14px !important;
  padding-bottom: 4px !important;
  font-size: 11px !important;
  font-weight: 600 !important;
  letter-spacing: 1.2px;
  text-transform: uppercase;
  color: var(--ds-on-surface-muted, rgba(var(--v-theme-on-surface), 0.55));
  opacity: 1;
}

.sidebar-drawer :deep(.v-navigation-drawer) {
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
