<template>
  <div>
    <PageHeader title="Партнёры">
      <template #actions>
        <v-btn icon="mdi-refresh" size="small" variant="text" :loading="loading" @click="load" />
      </template>
    </PageHeader>

    <div class="search-bar">
      <v-text-field v-model="search" placeholder="ФИО / телефон / ID"
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
      <v-icon size="48">mdi-account-group-outline</v-icon>
      <div class="empty-state-text">{{ search ? 'Партнёры не найдены' : 'Список пуст' }}</div>
    </div>

    <div v-else class="list">
      <div v-for="p in items" :key="p.id" class="list-card">
        <v-avatar size="40" :color="statusColor(p.activity || p.activityId)" variant="tonal">
          <span class="text-caption font-weight-bold">{{ initials(p.personName) }}</span>
        </v-avatar>
        <div class="list-card-body">
          <div class="list-card-title">{{ p.personName }}</div>
          <div class="list-card-sub">{{ p.statusName || '—' }} · ID {{ p.id }}</div>
        </div>
        <div class="list-card-aside">
          <v-chip :color="statusColor(p.activity || p.activityId)" size="x-small" variant="tonal">
            {{ p.activityName || activityLabel(p.activity) }}
          </v-chip>
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

interface Partner {
  id: number;
  personName: string;
  statusName?: string;
  activity?: number | string;
  activityId?: number | string;
  activityName?: string;
}

const search = ref('');
const items = ref<Partner[]>([]);
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
function activityLabel(a?: number | string) {
  const m: Record<string, string> = { '1': 'Активен', '2': 'Заморозка', '3': 'Старт' };
  return m[String(a)] || '—';
}
function statusColor(a?: number | string) {
  const m: Record<string, string> = { '1': 'success', '2': 'grey', '3': 'warning' };
  return m[String(a)] || 'grey';
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const params: Record<string, any> = { per_page: 50 };
    if (search.value.trim()) params.search = search.value.trim();
    const { data } = await api.get('/admin/partners', { params });
    items.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>
