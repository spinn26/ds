<template>
  <div>
    <PageHeader title="Смена реквизитов" icon="mdi-bank-transfer" class="mb-4" />

    <v-card class="mb-3 pa-2">
      <div class="d-flex ga-2 align-center flex-wrap">
        <v-btn-toggle v-model="status" density="compact" variant="outlined" mandatory color="primary"
          @update:model-value="loadData">
          <v-btn value="pending" size="small">Ожидают</v-btn>
          <v-btn value="accepted" size="small">Принятые</v-btn>
          <v-btn value="rejected" size="small">Отклонённые</v-btn>
        </v-btn-toggle>
        <v-spacer />
        <span class="text-caption text-medium-emphasis">Всего: {{ total }}</span>
      </div>
    </v-card>

    <v-card v-if="!items.length && !loading" class="pa-8 text-center">
      <v-icon size="40" color="grey">mdi-inbox-outline</v-icon>
      <div class="text-body-2 text-medium-emphasis mt-2">Запросов нет</div>
    </v-card>

    <v-card v-for="r in items" :key="r.id" class="mb-3">
      <v-card-text>
        <div class="d-flex align-center ga-2 mb-3 flex-wrap">
          <v-icon color="primary">mdi-account</v-icon>
          <span class="text-subtitle-1 font-weight-bold">{{ r.partnerName }}</span>
          <v-chip size="x-small" :color="statusColor(r.status)" variant="tonal">{{ statusLabel(r.status) }}</v-chip>
          <v-spacer />
          <span class="text-caption text-medium-emphasis">{{ fmtDate(r.createdAt) }}</span>
        </div>

        <v-row dense>
          <v-col cols="12" md="6">
            <div class="text-caption text-medium-emphasis mb-1">Было</div>
            <v-table density="compact" class="diff-table">
              <tbody>
                <tr><td class="lbl">Банк</td><td>{{ r.old.bankName || '—' }}</td></tr>
                <tr><td class="lbl">БИК</td><td>{{ r.old.bankBik || '—' }}</td></tr>
                <tr><td class="lbl">Счёт</td><td>{{ r.old.accountNumber || '—' }}</td></tr>
                <tr><td class="lbl">Корр. счёт</td><td>{{ r.old.correspondentAccount || '—' }}</td></tr>
              </tbody>
            </v-table>
          </v-col>
          <v-col cols="12" md="6">
            <div class="text-caption text-primary mb-1">Стало</div>
            <v-table density="compact" class="diff-table diff-new">
              <tbody>
                <tr><td class="lbl">Банк</td><td :class="{ chg: r.old.bankName !== r.new.bankName }">{{ r.new.bankName || '—' }}</td></tr>
                <tr><td class="lbl">БИК</td><td :class="{ chg: r.old.bankBik !== r.new.bankBik }">{{ r.new.bankBik || '—' }}</td></tr>
                <tr><td class="lbl">Счёт</td><td :class="{ chg: r.old.accountNumber !== r.new.accountNumber }">{{ r.new.accountNumber || '—' }}</td></tr>
                <tr><td class="lbl">Корр. счёт</td><td :class="{ chg: r.old.correspondentAccount !== r.new.correspondentAccount }">{{ r.new.correspondentAccount || '—' }}</td></tr>
              </tbody>
            </v-table>
          </v-col>
        </v-row>

        <v-alert v-if="r.status === 'rejected' && r.rejectionReason" type="error" variant="tonal"
          density="compact" class="mt-3">Причина отказа: {{ r.rejectionReason }}</v-alert>
      </v-card-text>
      <v-card-actions v-if="r.status === 'pending'">
        <v-spacer />
        <v-btn color="error" variant="text" prepend-icon="mdi-close" :loading="busy === r.id" @click="reject(r)">Отклонить</v-btn>
        <v-btn color="success" variant="flat" prepend-icon="mdi-check" :loading="busy === r.id" @click="accept(r)">Принять</v-btn>
      </v-card-actions>
    </v-card>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import { useSnackbar } from '../../composables/useSnackbar';

const { showSuccess, showError } = useSnackbar();
const items = ref([]);
const total = ref(0);
const loading = ref(false);
const busy = ref(null);
const status = ref('pending');

async function loadData() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/bank-change-requests', { params: { status: status.value } });
    items.value = data.data || [];
    total.value = data.total || 0;
  } catch { items.value = []; }
  loading.value = false;
}

async function accept(r) {
  busy.value = r.id;
  try {
    await api.post(`/admin/bank-change-requests/${r.id}/accept`);
    showSuccess('Запрос принят, реквизиты обновлены');
    await loadData();
  } catch (e) { showError(e?.response?.data?.message || 'Ошибка'); }
  busy.value = null;
}

async function reject(r) {
  const comment = window.prompt('Причина отказа (необязательно):', '') ?? '';
  busy.value = r.id;
  try {
    await api.post(`/admin/bank-change-requests/${r.id}/reject`, { comment });
    showSuccess('Запрос отклонён');
    await loadData();
  } catch (e) { showError(e?.response?.data?.message || 'Ошибка'); }
  busy.value = null;
}

function statusColor(s) { return s === 'accepted' ? 'success' : s === 'rejected' ? 'error' : 'warning'; }
function statusLabel(s) { return s === 'accepted' ? 'Принят' : s === 'rejected' ? 'Отклонён' : 'Ожидает'; }
function fmtDate(d) { return d ? new Date(d).toLocaleString('ru-RU') : '—'; }

onMounted(loadData);
</script>

<style scoped>
.diff-table .lbl { width: 120px; color: rgba(var(--v-theme-on-surface), 0.6); }
.diff-new { border: 1px solid rgba(var(--v-theme-primary), 0.3); border-radius: 8px; }
.diff-table td.chg { color: rgb(var(--v-theme-primary)); font-weight: 700; }
</style>
