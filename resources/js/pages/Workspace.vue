<template>
  <div>
    <!-- Welcome header -->
    <div class="d-flex justify-space-between align-center mb-4 flex-wrap ga-2">
      <div>
        <h4 :class="mobile ? 'text-h6' : 'text-h4'" class="font-weight-bold d-flex align-center ga-2">
          <v-icon size="32" color="primary">mdi-hand-wave</v-icon>
          {{ greeting }}, {{ auth.user?.firstName }}!
        </h4>
        <div class="text-body-1 text-medium-emphasis">Рабочий стол DS Consulting</div>
      </div>
      <div class="text-body-2 text-medium-emphasis">
        {{ new Date().toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) }}
      </div>
    </div>

    <v-row>
      <!-- Left column -->
      <v-col cols="12" md="8">
        <!-- Partner stats (consultants only) -->
        <v-card v-if="data.partnerStats" class="mb-4 pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">
            <v-icon class="mr-1" size="20">mdi-chart-line</v-icon> Мои показатели
          </div>
          <v-row dense>
            <v-col cols="6" sm="4" md="2">
              <div class="text-center">
                <div class="text-caption text-medium-emphasis">ЛП</div>
                <div class="text-subtitle-1 font-weight-bold text-green" style="white-space:nowrap">{{ fmt(data.partnerStats.personalVolume) }}</div>
              </div>
            </v-col>
            <v-col cols="6" sm="4" md="2">
              <div class="text-center">
                <div class="text-caption text-medium-emphasis">ГП</div>
                <div class="text-subtitle-1 font-weight-bold text-blue" style="white-space:nowrap">{{ fmt(data.partnerStats.groupVolume) }}</div>
              </div>
            </v-col>
            <v-col cols="6" sm="4" md="2">
              <div class="text-center">
                <div class="text-caption text-medium-emphasis">НГП</div>
                <div class="text-subtitle-1 font-weight-bold text-orange" style="white-space:nowrap">{{ fmt(data.partnerStats.groupVolumeCumulative) }}</div>
              </div>
            </v-col>
            <v-col cols="6" sm="4" md="3">
              <div class="text-center">
                <div class="text-caption text-medium-emphasis">Квалификация</div>
                <div class="text-body-2 font-weight-bold" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ data.partnerStats.qualification }}</div>
                <div v-if="data.partnerStats.levelsDontMatch" class="text-caption text-warning" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                  Расчёт: {{ data.partnerStats.calcQualification }} ({{ data.partnerStats.calcPercent }}%)
                </div>
                <div v-else class="text-caption text-medium-emphasis">{{ data.partnerStats.percent }}%</div>
              </div>
            </v-col>
            <v-col cols="6" sm="4" md="1">
              <div class="text-center">
                <div class="text-caption text-medium-emphasis">Клиенты</div>
                <div class="text-subtitle-1 font-weight-bold">{{ data.partnerStats.clientCount }}</div>
              </div>
            </v-col>
            <v-col cols="6" sm="4" md="2">
              <div class="text-center">
                <div class="text-caption text-medium-emphasis">Команда</div>
                <div class="text-subtitle-1 font-weight-bold">{{ data.partnerStats.teamCount }}</div>
              </div>
            </v-col>
          </v-row>
        </v-card>

        <!-- Mentor & Network Leader -->
        <v-alert v-if="!data.mentor && data.networkLeader" type="info" variant="tonal" class="mb-4" density="compact">
          Наставника нет — указан ЛИДЕР СЕТИ
        </v-alert>
        <v-row v-if="data.mentor || data.networkLeader" class="mb-4">
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
            <v-card variant="tonal" :color="news.type === 'warning' ? 'warning' : news.type === 'success' ? 'success' : 'primary'" class="pa-3">
              <div class="d-flex justify-space-between align-center mb-1">
                <div class="text-subtitle-2 font-weight-bold">{{ news.title }}</div>
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

    <v-overlay v-model="loading" class="align-center justify-center" persistent>
      <v-progress-circular indeterminate size="64" />
    </v-overlay>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useDisplay } from 'vuetify';
import { useAuthStore } from '../stores/auth';
import api from '../api';

const { mobile } = useDisplay();
import { fmt, fmtDate } from '../composables/useDesign';

const auth = useAuthStore();
const loading = ref(true);
const data = ref({});

const isConsultant = computed(() => auth.user?.role?.includes('consultant'));

const greeting = computed(() => {
  const h = new Date().getHours();
  if (h < 6) return 'Доброй ночи';
  if (h < 12) return 'Доброе утро';
  if (h < 18) return 'Добрый день';
  return 'Добрый вечер';
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
