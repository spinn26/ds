<template>
  <div>
    <PageHeader title="Клиенты" />

    <div class="search-bar">
      <v-text-field v-model="search" placeholder="ФИО / email / телефон"
        density="compact" variant="outlined" hide-details rounded clearable
        prepend-inner-icon="mdi-magnify" />
    </div>

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>

    <div v-if="loading" class="text-center py-8">
      <v-progress-circular indeterminate color="primary" size="32" />
    </div>

    <div v-else-if="!filtered.length" class="empty-state">
      <v-icon size="48">mdi-account-group-outline</v-icon>
      <div class="empty-state-text">{{ search ? 'Клиенты не найдены' : 'Клиентов пока нет' }}</div>
    </div>

    <div v-else class="list">
      <div v-for="c in filtered" :key="c.id" class="list-card">
        <v-avatar size="40" color="primary" variant="tonal">
          <span class="text-caption font-weight-bold">{{ initials(c.personName) }}</span>
        </v-avatar>
        <div class="list-card-body">
          <div class="list-card-title">{{ c.personName }}</div>
          <div class="list-card-sub">{{ c.email || c.phone || '—' }}</div>
        </div>
        <div class="list-card-aside">
          <v-icon size="16" color="grey-lighten-1">mdi-chevron-right</v-icon>
        </div>
      </div>
    </div>

    <v-btn class="fab" color="primary" icon="mdi-account-plus" size="large" elevation="6" />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

interface Client {
  id: number;
  personName: string;
  email?: string;
  phone?: string;
}

const search = ref('');
const items = ref<Client[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  if (!q) return items.value;
  return items.value.filter((c) =>
    (c.personName || '').toLowerCase().includes(q) ||
    (c.email || '').toLowerCase().includes(q) ||
    (c.phone || '').toLowerCase().includes(q),
  );
});

function initials(name: string) {
  return name.split(' ').slice(0, 2).map((s) => s[0]).join('').toUpperCase();
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const { data } = await api.get('/clients');
    items.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить клиентов';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>
