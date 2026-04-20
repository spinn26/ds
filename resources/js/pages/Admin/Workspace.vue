<template>
  <div>
    <PageHeader title="Рабочий стол" icon="mdi-view-dashboard-variant">
      <template #actions>
        <v-btn variant="text" prepend-icon="mdi-refresh" :loading="loading" @click="load">Обновить</v-btn>
      </template>
    </PageHeader>

    <!-- Counters row -->
    <v-row dense class="mb-3">
      <v-col v-for="t in tiles" :key="t.key" cols="6" sm="4" md="2">
        <v-card :color="t.color" variant="tonal" class="pa-3 tile"
          @click="t.to && $router.push(t.to)">
          <div class="d-flex align-center ga-2">
            <v-icon :color="t.color" size="24">{{ t.icon }}</v-icon>
            <div style="min-width:0">
              <div class="text-caption text-medium-emphasis text-truncate">{{ t.label }}</div>
              <div class="text-h6 font-weight-bold">{{ t.value }}</div>
            </div>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Task feed -->
    <v-row dense>
      <!-- Акцепт документов -->
      <v-col cols="12" md="6">
        <TaskCard
          title="Акцепт документов ждут проверки"
          icon="mdi-file-document-check"
          :count="sections.pendingAcceptance?.count"
          to="/manage/acceptance"
          :loading="loading"
        >
          <v-list density="compact">
            <v-list-item
              v-for="it in sections.pendingAcceptance?.items || []"
              :key="it.id"
              :title="it.personName"
              :subtitle="it.email"
              @click="$router.push(`/manage/acceptance`)"
            >
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

      <!-- Контракты без транзакций -->
      <v-col cols="12" md="6">
        <TaskCard
          title="Активные контракты без транзакций (30+ дней)"
          icon="mdi-file-alert"
          :count="sections.newContractsNoTx?.count"
          to="/manage/contracts"
          :loading="loading"
        >
          <v-list density="compact">
            <v-list-item
              v-for="it in sections.newContractsNoTx?.items || []"
              :key="it.id"
              :title="`№${it.number || it.id} · ${it.consultantName || '—'}`"
              :subtitle="it.statusName"
            >
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

      <!-- Ошибки импорта -->
      <v-col cols="12" md="6">
        <TaskCard
          title="Неудачные импорты транзакций"
          icon="mdi-database-alert"
          :count="sections.failedImports?.count"
          to="/manage/transactions/import"
          :loading="loading"
        >
          <v-list density="compact">
            <v-list-item
              v-for="it in sections.failedImports?.items || []"
              :key="it.id"
              :title="`Импорт #${it.id}`"
              :subtitle="`${it.success_count ?? 0} ок · ${it.error_count ?? 0} ош.`"
            >
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

      <!-- Прочие начисления — ждут утверждения -->
      <v-col cols="12" md="6">
        <TaskCard
          title="«Прочие начисления» ждут утверждения"
          icon="mdi-cash-edit"
          :count="sections.pendingAccruals?.count"
          to="/manage/charges"
          :loading="loading"
        >
          <v-list density="compact">
            <v-list-item
              v-for="it in sections.pendingAccruals?.items || []"
              :key="it.id"
              :title="it.consultantName || '—'"
              :subtitle="it.reason"
            >
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

      <!-- 90-дневная активация истекает -->
      <v-col cols="12" md="6">
        <TaskCard
          title="Активация истекает в ближайшие 7 дней"
          icon="mdi-clock-alert"
          :count="sections.activationExpiring?.count"
          to="/manage/partners/statuses"
          :loading="loading"
        >
          <v-list density="compact">
            <v-list-item
              v-for="it in sections.activationExpiring?.items || []"
              :key="it.id"
              :title="it.personName"
              :subtitle="it.email"
            >
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

      <!-- Незакрытые периоды -->
      <v-col cols="12" md="6">
        <TaskCard
          title="Незакрытые периоды"
          icon="mdi-calendar-lock"
          :count="sections.unclosedPeriods?.count"
          :loading="loading"
        >
          <v-list density="compact">
            <v-list-item
              v-for="it in sections.unclosedPeriods?.items || []"
              :key="it.label"
              :title="it.label"
              @click="$router.push(`/manage/periods/${it.year}-${String(it.month).padStart(2,'0')}`)"
            >
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
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, StatusChip, MoneyCell } from '../../components';
import { fmt, fmtDate } from '../../composables/useDesign';
import TaskCard from './WorkspaceTaskCard.vue';

const loading = ref(false);
const data = ref({});

const sections = computed(() => data.value.staffTasks || {});

const tiles = computed(() => [
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

function daysLeft(createdAt) {
  if (!createdAt) return 0;
  const expires = new Date(createdAt);
  expires.setDate(expires.getDate() + 90);
  const diff = Math.ceil((expires - Date.now()) / (1000 * 60 * 60 * 24));
  return Math.max(0, diff);
}

async function load() {
  loading.value = true;
  try {
    const { data: d } = await api.get('/workspace');
    data.value = d;
  } catch {}
  loading.value = false;
}

onMounted(load);
</script>

<style scoped>
.tile { cursor: pointer; transition: transform 0.1s; }
.tile:hover { transform: translateY(-1px); }
</style>
