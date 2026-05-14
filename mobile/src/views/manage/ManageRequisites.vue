<template>
  <div>
    <PageHeader title="Реквизиты" />

    <div class="search-bar">
      <v-text-field v-model="search" placeholder="ФИО / ИНН / банк"
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
      <v-icon size="48">mdi-bank-outline</v-icon>
      <div class="empty-state-text">Реквизитов нет</div>
    </div>

    <div v-else class="list">
      <div v-for="r in items" :key="r.id" class="list-card">
        <v-avatar size="40" color="primary" variant="tonal">
          <v-icon size="20">mdi-bank-outline</v-icon>
        </v-avatar>
        <div class="list-card-body">
          <div class="list-card-title">{{ r.consultantName || '—' }}</div>
          <div class="list-card-sub">ИНН {{ r.inn || '—' }} · {{ r.bankName || r.bank || '—' }}</div>
        </div>
        <div class="list-card-aside">
          <v-chip v-if="r.verified" color="success" size="x-small" variant="tonal">проверены</v-chip>
          <v-chip v-else color="warning" size="x-small" variant="tonal">не проверены</v-chip>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

interface Requisite {
  id: number;
  consultantName?: string;
  inn?: string;
  bankName?: string;
  bank?: string;
  verified?: boolean;
}

const search = ref('');
const items = ref<Requisite[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

let debounceTimer: ReturnType<typeof setTimeout> | null = null;
function debouncedLoad() {
  if (debounceTimer) clearTimeout(debounceTimer);
  debounceTimer = setTimeout(load, 350);
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const params: Record<string, any> = { per_page: 50 };
    if (search.value.trim()) params.search = search.value.trim();
    const { data } = await api.get('/admin/requisites', { params });
    items.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>
