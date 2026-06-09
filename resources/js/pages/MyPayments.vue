<template>
  <div>
    <PageHeader title="Реестр выплат" icon="mdi-cash-register" />

    <!-- Period selector -->
    <v-card class="ds-card mb-3 pa-3" elevation="0">
      <div class="d-flex align-center ga-2 flex-wrap">
        <v-btn icon size="small" variant="text" @click="prevMonth">
          <v-icon>mdi-chevron-left</v-icon>
        </v-btn>
        <span class="text-body-1 font-weight-medium" style="min-width:140px; text-align:center">
          {{ monthLabel }}
        </span>
        <v-btn icon size="small" variant="text" :disabled="isCurrentMonth" @click="nextMonth">
          <v-icon>mdi-chevron-right</v-icon>
        </v-btn>
      </div>
    </v-card>

    <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-2" />

    <template v-if="summary">
      <!-- Summary cards -->
      <v-row class="mb-3" dense>
        <v-col cols="6" sm="4" md="2">
          <v-card class="ds-card pa-3 text-center" elevation="0">
            <div class="text-caption text-medium-emphasis mb-1">Сальдо</div>
            <div class="text-body-1 font-weight-bold" style="font-variant-numeric:tabular-nums">
              {{ fmt(summary.balance) }} ₽
            </div>
          </v-card>
        </v-col>
        <v-col cols="6" sm="4" md="2">
          <v-card class="ds-card pa-3 text-center" elevation="0">
            <div class="text-caption text-medium-emphasis mb-1">Начислено</div>
            <div class="text-body-1 font-weight-bold" style="font-variant-numeric:tabular-nums">
              {{ fmt(summary.accrued) }} ₽
            </div>
          </v-card>
        </v-col>
        <v-col cols="6" sm="4" md="2">
          <v-card class="ds-card pa-3 text-center" elevation="0">
            <div class="text-caption text-medium-emphasis mb-1">Пул</div>
            <div class="text-body-1 font-weight-bold" style="font-variant-numeric:tabular-nums">
              {{ fmt(summary.pool) }} ₽
            </div>
          </v-card>
        </v-col>
        <v-col cols="6" sm="4" md="2">
          <v-card class="ds-card pa-3 text-center" elevation="0">
            <div class="text-caption text-medium-emphasis mb-1">Прочее</div>
            <div class="text-body-1 font-weight-bold" style="font-variant-numeric:tabular-nums">
              {{ fmt(summary.other) }} ₽
            </div>
          </v-card>
        </v-col>
        <v-col cols="6" sm="4" md="2">
          <v-card class="ds-card pa-3 text-center" elevation="0">
            <div class="text-caption text-medium-emphasis mb-1">К оплате</div>
            <div class="text-body-1 font-weight-bold text-primary" style="font-variant-numeric:tabular-nums">
              {{ fmt(summary.totalPayable) }} ₽
            </div>
          </v-card>
        </v-col>
        <v-col cols="6" sm="4" md="2">
          <v-card class="ds-card pa-3 text-center" elevation="0">
            <div class="text-caption text-medium-emphasis mb-1">Остаток</div>
            <div class="text-body-1 font-weight-bold"
              :class="summary.remaining > 0 ? 'text-warning' : 'text-success'"
              style="font-variant-numeric:tabular-nums">
              {{ fmt(summary.remaining) }} ₽
            </div>
          </v-card>
        </v-col>
      </v-row>

      <!-- Status chip -->
      <div class="mb-3" v-if="summary.status">
        <v-chip size="small" :color="statusColor(summary.status)" variant="tonal">
          {{ summary.status }}
        </v-chip>
      </div>

      <!-- Payment history table -->
      <v-card class="ds-card" elevation="0">
        <v-card-title class="text-body-1 font-weight-medium pa-4 pb-2">
          История выплат за {{ monthLabel }}
        </v-card-title>
        <v-table density="compact" v-if="payments.length">
          <thead>
            <tr>
              <th>Дата</th>
              <th class="text-right">Сумма</th>
              <th>Статус</th>
              <th>Комментарий</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in payments" :key="p.id">
              <td style="white-space:nowrap">{{ formatDate(p.paymentDate) }}</td>
              <td class="text-right" style="font-variant-numeric:tabular-nums;white-space:nowrap">
                {{ fmt(p.amount) }} ₽
              </td>
              <td>
                <v-chip size="x-small" :color="paymentStatusColor(p.status)" variant="tonal">
                  {{ p.statusName ?? '—' }}
                </v-chip>
              </td>
              <td class="text-medium-emphasis">{{ p.comment ?? '—' }}</td>
            </tr>
          </tbody>
        </v-table>
        <div v-else class="pa-4">
          <EmptyState text="Выплат за этот период нет" />
        </div>
      </v-card>

      <!-- History by periods -->
      <v-card class="ds-card mt-3" elevation="0">
        <v-card-title class="text-body-1 font-weight-medium pa-4 pb-2">
          История по периодам
        </v-card-title>
        <v-table density="compact" v-if="history.length">
          <thead>
            <tr>
              <th>Период</th>
              <th class="text-right">Начислено</th>
              <th class="text-right">Пул</th>
              <th class="text-right">Прочее</th>
              <th class="text-right">Оплачено</th>
              <th class="text-right">Остаток</th>
              <th>Статус</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="h in history" :key="h.dateMonth"
              :class="{ 'font-weight-bold': h.dateMonth === currentDm }">
              <td style="white-space:nowrap">{{ fmtMonth(h.dateMonth) }}</td>
              <td class="text-right" style="font-variant-numeric:tabular-nums">{{ fmt(h.accrued) }} ₽</td>
              <td class="text-right" style="font-variant-numeric:tabular-nums">{{ fmt(h.pool) }} ₽</td>
              <td class="text-right" style="font-variant-numeric:tabular-nums">{{ fmt(h.other) }} ₽</td>
              <td class="text-right" style="font-variant-numeric:tabular-nums">{{ fmt(h.payed) }} ₽</td>
              <td class="text-right" style="font-variant-numeric:tabular-nums">
                <span :class="h.remaining > 0 ? 'text-warning' : ''">{{ fmt(h.remaining) }} ₽</span>
              </td>
              <td>
                <v-chip v-if="h.status" size="x-small" :color="statusColor(h.status)" variant="tonal">
                  {{ h.status }}
                </v-chip>
                <span v-else class="text-medium-emphasis">—</span>
              </td>
            </tr>
          </tbody>
        </v-table>
        <div v-else class="pa-4">
          <EmptyState text="История выплат пуста" />
        </div>
      </v-card>
    </template>

    <EmptyState v-else-if="!loading" text="Данные за выбранный период отсутствуют" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api';
import PageHeader from '../components/PageHeader.vue';
import EmptyState from '../components/EmptyState.vue';
import { fmt } from '../composables/useDesign';

const loading = ref(false);
const summary = ref(null);
const payments = ref([]);
const history = ref([]);

const now = new Date();
const year = ref(now.getFullYear());
const month = ref(now.getMonth() + 1);

const currentDm = computed(() => `${year.value}-${String(month.value).padStart(2, '0')}`);

const isCurrentMonth = computed(() =>
  year.value === now.getFullYear() && month.value === now.getMonth() + 1
);

const monthNames = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
  'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

const monthLabel = computed(() => `${monthNames[month.value - 1]} ${year.value}`);

function prevMonth() {
  if (month.value === 1) { month.value = 12; year.value--; }
  else month.value--;
  loadData();
}

function nextMonth() {
  if (isCurrentMonth.value) return;
  if (month.value === 12) { month.value = 1; year.value++; }
  else month.value++;
  loadData();
}

async function loadData() {
  loading.value = true;
  summary.value = null;
  payments.value = [];
  history.value = [];
  try {
    const { data } = await api.get('/my-payments', { params: { year: year.value, month: month.value } });
    summary.value = data.summary;
    payments.value = data.payments ?? [];
    history.value = data.history ?? [];
  } catch {}
  loading.value = false;
}

function formatDate(val) {
  if (!val) return '—';
  const d = new Date(val);
  if (isNaN(d.getTime())) return val;
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function fmtMonth(dm) {
  if (!dm) return '—';
  const [y, m] = dm.split('-');
  return `${monthNames[parseInt(m, 10) - 1]} ${y}`;
}

function statusColor(status) {
  if (!status) return 'default';
  if (status.toLowerCase().includes('полностью')) return 'success';
  if (status.toLowerCase().includes('частично')) return 'warning';
  if (status.toLowerCase().includes('обработ')) return 'info';
  return 'default';
}

function paymentStatusColor(status) {
  if (status === 2) return 'success';
  if (status === 1) return 'info';
  if (status === 3) return 'error';
  return 'default';
}

onMounted(loadData);
</script>
