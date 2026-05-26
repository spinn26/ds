<template>
  <div>
    <PageHeader title="Реквизиты" back />

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>

    <div v-if="loading" class="text-center py-8">
      <v-progress-circular indeterminate color="primary" size="32" />
    </div>

    <template v-else>
      <v-card class="detail-card" elevation="0">
        <div class="text-overline text-medium-emphasis mb-2">Банковская карта</div>
        <div class="bank-card">
          <div class="bc-bank">{{ data?.bankName || 'Банк не указан' }}</div>
          <div class="bc-num">{{ maskCard(data?.cardNumber || data?.accountNumber) }}</div>
          <div class="bc-row">
            <span>{{ data?.cardHolder || holderFromUser() }}</span>
            <span v-if="data?.cardExpiry">{{ data.cardExpiry }}</span>
          </div>
        </div>
        <v-btn variant="text" color="primary" size="small" prepend-icon="mdi-pencil" class="mt-2">
          Изменить
        </v-btn>
      </v-card>

      <v-card class="detail-card mt-3" elevation="0">
        <div class="text-overline text-medium-emphasis mb-2">Паспортные данные</div>
        <div class="detail-row">
          <span class="detail-row-key">ФИО</span>
          <span class="detail-row-val">{{ data?.passportFio || '—' }}</span>
        </div>
        <div class="detail-row">
          <span class="detail-row-key">Серия / номер</span>
          <span class="detail-row-val">{{ data?.passportSeries }} {{ data?.passportNumber || '—' }}</span>
        </div>
        <div class="detail-row">
          <span class="detail-row-key">Выдан</span>
          <span class="detail-row-val">{{ data?.passportIssued || '—' }}</span>
        </div>
        <div class="detail-row">
          <span class="detail-row-key">Код подразделения</span>
          <span class="detail-row-val">{{ data?.passportCode || '—' }}</span>
        </div>
      </v-card>

      <v-card class="detail-card mt-3" elevation="0">
        <div class="text-overline text-medium-emphasis mb-2">Налоговые</div>
        <div class="detail-row">
          <span class="detail-row-key">ИНН</span>
          <span class="detail-row-val">{{ data?.inn || '—' }}</span>
        </div>
        <div class="detail-row">
          <span class="detail-row-key">Статус</span>
          <span class="detail-row-val">
            <v-chip v-if="data?.taxStatus" size="x-small" color="success" variant="tonal">
              {{ taxLabel(data.taxStatus) }}
            </v-chip>
            <span v-else>—</span>
          </span>
        </div>
      </v-card>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const data = ref<Record<string, any> | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

function maskCard(num?: string) {
  if (!num) return 'не указана';
  const cleaned = String(num).replace(/\s+/g, '');
  if (cleaned.length < 4) return cleaned;
  return '•••• •••• •••• ' + cleaned.slice(-4);
}
function holderFromUser() {
  const u = auth.user;
  if (!u) return '';
  return [u.lastName, u.firstName].filter(Boolean).join(' ').toUpperCase();
}
function taxLabel(s: string) {
  return ({ 'self-employed': 'Самозанятый', ip: 'ИП', individual: 'Физлицо' } as Record<string, string>)[s] || s;
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const { data: d } = await api.get('/profile');
    data.value = d?.profile || d?.user || d || {};
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.detail-card { background: #fff; border-radius: 14px; padding: 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.bank-card { background: linear-gradient(135deg, #0A2B10 0%, #2E7D32 100%); color: #fff; border-radius: 14px; padding: 16px; box-shadow: 0 4px 16px rgba(46,125,50,0.15); }
.bc-bank { font-size: 11px; opacity: 0.7; letter-spacing: 0.5px; text-transform: uppercase; }
.bc-num { font-size: 17px; letter-spacing: 2px; margin: 10px 0 14px; font-variant-numeric: tabular-nums; }
.bc-row { display: flex; justify-content: space-between; font-size: 11px; opacity: 0.85; }
.detail-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; font-size: 13px; border-bottom: 1px solid rgba(0,0,0,0.04); }
.detail-row:last-child { border-bottom: 0; }
.detail-row-key { color: rgba(0,0,0,0.55); }
.detail-row-val { color: #1b1b1b; font-weight: 500; text-align: right; }
</style>
