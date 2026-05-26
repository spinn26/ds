<template>
  <div>
    <PageHeader title="Транзакции">
      <template #actions>
        <v-btn icon="mdi-refresh" size="small" variant="text" :loading="loading" @click="load" />
      </template>
    </PageHeader>

    <div class="search-bar">
      <v-text-field v-model="search" placeholder="Клиент / контракт / партнёр"
        density="compact" variant="outlined" hide-details rounded clearable
        prepend-inner-icon="mdi-magnify"
        @update:model-value="debouncedLoad" />
    </div>

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>

    <div v-if="loading" class="text-center py-8">
      <v-progress-circular indeterminate color="warning" size="32" />
    </div>

    <div v-else-if="!items.length" class="empty-state">
      <v-icon size="48">mdi-database-search-outline</v-icon>
      <div class="empty-state-text">Транзакций нет</div>
    </div>

    <div v-else class="list">
      <div v-for="tx in items" :key="tx.id" class="list-card">
        <div class="list-card-avatar avatar-warning">
          <v-icon color="warning" size="22">mdi-swap-horizontal</v-icon>
        </div>
        <div class="list-card-body">
          <div class="list-card-title">{{ tx.clientName || '—' }}</div>
          <div class="list-card-sub">
            {{ tx.contractNumber || '—' }}
            <span v-if="tx.consultantName"> · {{ tx.consultantName }}</span>
            <span v-if="tx.date"> · {{ formatDate(tx.date) }}</span>
          </div>
        </div>
        <div class="list-card-aside">
          <div class="list-card-amount">{{ formatAmount(tx) }}</div>
          <div v-if="tx.incomeDS != null" class="list-card-meta">ДС {{ Math.round(tx.incomeDS).toLocaleString('ru-RU') }} ₽</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

interface Tx {
  id: number;
  clientName?: string;
  consultantName?: string;
  contractNumber?: string;
  amount?: number;
  currencySymbol?: string;
  amountRUB?: number;
  incomeDS?: number;
  date?: string;
}

const search = ref('');
const items = ref<Tx[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

let debounceTimer: ReturnType<typeof setTimeout> | null = null;
function debouncedLoad() {
  if (debounceTimer) clearTimeout(debounceTimer);
  debounceTimer = setTimeout(load, 350);
}

function formatAmount(t: Tx) {
  if (t.amount != null) return `${t.amount.toLocaleString('ru-RU', { maximumFractionDigits: 2 })} ${t.currencySymbol || ''}`.trim();
  if (t.amountRUB != null) return `${t.amountRUB.toLocaleString('ru-RU', { maximumFractionDigits: 0 })} ₽`;
  return '—';
}
function formatDate(iso?: string) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return iso;
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const params: Record<string, any> = { per_page: 50 };
    if (search.value.trim()) params.search = search.value.trim();
    const { data } = await api.get('/admin/transactions', { params });
    items.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>
