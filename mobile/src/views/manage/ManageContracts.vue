<template>
  <div>
    <PageHeader title="Контракты" />

    <div class="search-bar">
      <v-text-field v-model="search" placeholder="№ / клиент / партнёр"
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
      <v-icon size="48">mdi-file-document-outline</v-icon>
      <div class="empty-state-text">Контрактов нет</div>
    </div>

    <div v-else class="list">
      <div v-for="c in items" :key="c.id" class="list-card">
        <div class="list-card-avatar avatar-warning">
          <v-icon color="warning" size="22">mdi-file-document-outline</v-icon>
        </div>
        <div class="list-card-body">
          <div class="list-card-title">{{ c.number || `#${c.id}` }}</div>
          <div class="list-card-sub">{{ c.clientName || '—' }} · {{ c.consultantName || '—' }}</div>
        </div>
        <div class="list-card-aside">
          <div class="list-card-amount">{{ formatAmount(c) }}</div>
          <div class="list-card-meta">{{ formatDate(c.openDate || c.dateOpen) }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

interface Contract {
  id: number;
  number?: string;
  clientName?: string;
  consultantName?: string;
  amount?: number;
  currencySymbol?: string;
  openDate?: string;
  dateOpen?: string;
}

const search = ref('');
const items = ref<Contract[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

let debounceTimer: ReturnType<typeof setTimeout> | null = null;
function debouncedLoad() {
  if (debounceTimer) clearTimeout(debounceTimer);
  debounceTimer = setTimeout(load, 350);
}

function formatAmount(c: Contract) {
  if (c.amount == null) return '—';
  return `${c.amount.toLocaleString('ru-RU', { maximumFractionDigits: 2 })} ${c.currencySymbol || ''}`.trim();
}
function formatDate(iso?: string) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' });
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const params: Record<string, any> = { per_page: 50 };
    if (search.value.trim()) params.search = search.value.trim();
    const { data } = await api.get('/admin/contracts', { params });
    items.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>
