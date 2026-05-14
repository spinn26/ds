<template>
  <div>
    <PageHeader title="Реестр выплат">
      <template #actions>
        <v-btn icon="mdi-refresh" size="small" variant="text" :loading="loading" @click="load" />
      </template>
    </PageHeader>

    <div class="search-bar">
      <v-text-field v-model="search" placeholder="ФИО партнёра"
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
      <v-icon size="48">mdi-cash-multiple</v-icon>
      <div class="empty-state-text">Выплат пока нет</div>
    </div>

    <div v-else class="list">
      <div v-for="p in items" :key="p.id" class="list-card">
        <v-avatar size="40" color="success" variant="tonal">
          <v-icon size="20">mdi-cash</v-icon>
        </v-avatar>
        <div class="list-card-body">
          <div class="list-card-title">{{ p.consultantName || '—' }}</div>
          <div class="list-card-sub">
            <span v-if="p.period">{{ p.period }}</span>
            <span v-if="p.method"> · {{ p.method }}</span>
          </div>
        </div>
        <div class="list-card-aside">
          <div class="list-card-amount">{{ formatAmount(p.amount) }} ₽</div>
          <v-chip v-if="p.status" :color="statusColor(p.status)" size="x-small" variant="tonal" class="mt-1">
            {{ statusLabel(p.status) }}
          </v-chip>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

interface Payment {
  id: number;
  consultantName?: string;
  amount?: number;
  status?: string;
  period?: string;
  method?: string;
}

const search = ref('');
const items = ref<Payment[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

let debounceTimer: ReturnType<typeof setTimeout> | null = null;
function debouncedLoad() {
  if (debounceTimer) clearTimeout(debounceTimer);
  debounceTimer = setTimeout(load, 350);
}

function formatAmount(n?: number) {
  return (n ?? 0).toLocaleString('ru-RU', { maximumFractionDigits: 2 });
}
function statusColor(s: string) {
  return ({ paid: 'success', queued: 'warning', failed: 'error', pending: 'warning' } as Record<string, string>)[s] || 'grey';
}
function statusLabel(s: string) {
  return ({ paid: 'оплачено', queued: 'в очереди', failed: 'ошибка', pending: 'ожидает' } as Record<string, string>)[s] || s;
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const params: Record<string, any> = { per_page: 50 };
    if (search.value.trim()) params.search = search.value.trim();
    const { data } = await api.get('/admin/payments', { params });
    items.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>
