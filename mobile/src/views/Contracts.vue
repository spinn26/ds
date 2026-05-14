<template>
  <div>
    <PageHeader title="Контракты" />

    <div class="search-bar">
      <v-text-field v-model="search" placeholder="№ контракта / ФИО клиента"
        density="compact" variant="outlined" hide-details rounded clearable
        prepend-inner-icon="mdi-magnify" />
    </div>

    <div class="chip-row">
      <v-chip v-for="t in tabs" :key="t.value"
        :color="tab === t.value ? 'primary' : undefined"
        :variant="tab === t.value ? 'flat' : 'tonal'"
        size="small" label @click="tab = t.value; load()">
        {{ t.label }}
      </v-chip>
    </div>

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>

    <div v-if="loading" class="text-center py-8">
      <v-progress-circular indeterminate color="primary" size="32" />
    </div>

    <div v-else-if="!filtered.length" class="empty-state">
      <v-icon size="48">mdi-file-document-outline</v-icon>
      <div class="empty-state-text">{{ search ? 'Контракты не найдены' : 'Контрактов пока нет' }}</div>
    </div>

    <div v-else class="list">
      <div v-for="c in filtered" :key="c.id" class="list-card">
        <div class="list-card-avatar" style="background: rgba(46,125,50,0.10)">
          <v-icon color="primary" size="20">mdi-file-document-outline</v-icon>
        </div>
        <div class="list-card-body">
          <div class="list-card-title">{{ c.number || `Контракт #${c.id}` }}</div>
          <div class="list-card-sub">{{ c.clientName || '—' }} · {{ c.productName || '—' }}</div>
        </div>
        <div class="list-card-aside">
          <div class="list-card-amount">{{ formatAmount(c) }}</div>
          <div class="list-card-meta">{{ formatDate(c.openDate || c.dateOpen) }}</div>
        </div>
      </div>
    </div>

    <v-btn class="fab" color="primary" icon="mdi-plus" size="large" elevation="6" />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

interface Contract {
  id: number;
  number?: string;
  clientName?: string;
  productName?: string;
  amount?: number;
  currencySymbol?: string;
  openDate?: string;
  dateOpen?: string;
}

const search = ref('');
const tab = ref<'my' | 'team'>('my');
const tabs = [
  { value: 'my' as const, label: 'Мои' },
  { value: 'team' as const, label: 'Команда' },
];
const items = ref<Contract[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  if (!q) return items.value;
  return items.value.filter((c) =>
    (c.number || '').toLowerCase().includes(q) ||
    (c.clientName || '').toLowerCase().includes(q),
  );
});

function formatAmount(c: Contract) {
  if (c.amount == null) return '—';
  const n = c.amount.toLocaleString('ru-RU', { maximumFractionDigits: 2 });
  return `${n} ${c.currencySymbol || ''}`.trim();
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
    const path = tab.value === 'team' ? '/contracts/team' : '/contracts/my';
    const { data } = await api.get(path);
    items.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить контракты';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>
