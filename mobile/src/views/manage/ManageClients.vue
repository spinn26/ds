<template>
  <div>
    <PageHeader title="Клиенты" />

    <div class="search-bar">
      <v-text-field v-model="search" placeholder="ФИО / email / телефон / ID"
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
      <v-icon size="48">mdi-account-multiple-outline</v-icon>
      <div class="empty-state-text">{{ search ? 'Клиенты не найдены' : 'Список пуст' }}</div>
    </div>

    <div v-else class="list">
      <div v-for="c in items" :key="c.id" class="list-card">
        <v-avatar size="40" color="info" variant="tonal">
          <span class="text-caption font-weight-bold">{{ initials(c.personName) }}</span>
        </v-avatar>
        <div class="list-card-body">
          <div class="list-card-title">{{ c.personName }}</div>
          <div class="list-card-sub">{{ c.consultantName || '—' }} · {{ c.phone || c.email || '—' }}</div>
        </div>
        <div class="list-card-aside">
          <v-icon size="16" color="grey-lighten-1">mdi-chevron-right</v-icon>
        </div>
      </div>
    </div>

    <v-btn class="fab" color="warning" icon="mdi-account-plus" size="large" elevation="6" />
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

interface Client {
  id: number;
  personName: string;
  consultantName?: string;
  phone?: string;
  email?: string;
}

const search = ref('');
const items = ref<Client[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

let debounceTimer: ReturnType<typeof setTimeout> | null = null;
function debouncedLoad() {
  if (debounceTimer) clearTimeout(debounceTimer);
  debounceTimer = setTimeout(load, 350);
}

function initials(name?: string) {
  return (name || '?').split(' ').slice(0, 2).map((s) => s[0] || '').join('').toUpperCase();
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const params: Record<string, any> = { per_page: 50 };
    if (search.value.trim()) params.search = search.value.trim();
    const { data } = await api.get('/admin/clients', { params });
    items.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>
