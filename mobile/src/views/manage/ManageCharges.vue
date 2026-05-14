<template>
  <div>
    <PageHeader title="Прочие начисления">
      <template #actions>
        <v-btn icon="mdi-refresh" size="small" variant="text" :loading="loading" @click="load" />
      </template>
    </PageHeader>

    <div class="search-bar">
      <v-text-field v-model="search" placeholder="ФИО партнёра / комментарий"
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
      <v-icon size="48">mdi-cash-remove</v-icon>
      <div class="empty-state-text">Начислений нет</div>
    </div>

    <div v-else class="list">
      <div v-for="c in items" :key="c.id" class="list-card">
        <div class="list-card-avatar" :style="{ background: bgFor(c) }">
          <v-icon :color="colorFor(c)" size="20">mdi-cash</v-icon>
        </div>
        <div class="list-card-body">
          <div class="list-card-title">{{ c.consultantName || '—' }}</div>
          <div class="list-card-sub">{{ c.type || '—' }} · {{ c.comment || '' }} · {{ formatDate(c.accrualDate) }}</div>
        </div>
        <div class="list-card-aside">
          <div class="list-card-amount" :class="{ negative: (c.amount || 0) < 0 }">
            {{ formatAmount(c) }}
          </div>
        </div>
      </div>
    </div>

    <v-btn class="fab" color="warning" icon="mdi-plus" size="large" elevation="6" />
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

interface Charge {
  id: number;
  consultantName?: string;
  type?: string;
  amount?: number;
  points?: number;
  comment?: string;
  accrualDate?: string;
}

const search = ref('');
const items = ref<Charge[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

let debounceTimer: ReturnType<typeof setTimeout> | null = null;
function debouncedLoad() {
  if (debounceTimer) clearTimeout(debounceTimer);
  debounceTimer = setTimeout(load, 350);
}

function bgFor(c: Charge) {
  return (c.amount || 0) >= 0 ? 'rgba(67,160,71,0.10)' : 'rgba(229,57,53,0.10)';
}
function colorFor(c: Charge) {
  return (c.amount || 0) >= 0 ? 'success' : 'error';
}
function formatAmount(c: Charge) {
  if (c.amount != null) {
    const sign = c.amount >= 0 ? '+' : '';
    return `${sign}${c.amount.toLocaleString('ru-RU', { maximumFractionDigits: 2 })} ₽`;
  }
  if (c.points != null) {
    const sign = c.points >= 0 ? '+' : '';
    return `${sign}${c.points.toLocaleString('ru-RU')} баллов`;
  }
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
    const { data } = await api.get('/admin/charges', { params });
    items.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.list-card-amount { color: rgb(var(--v-theme-success)); }
.list-card-amount.negative { color: rgb(var(--v-theme-error)); }
</style>
