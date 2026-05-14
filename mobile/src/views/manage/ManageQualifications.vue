<template>
  <div>
    <PageHeader title="Квалификации" />

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
      <v-icon size="48">mdi-trophy-outline</v-icon>
      <div class="empty-state-text">Данных нет</div>
    </div>

    <div v-else class="list">
      <div v-for="p in items" :key="p.id" class="list-card">
        <v-avatar size="40" color="warning" variant="tonal">
          <span class="text-caption font-weight-bold">{{ p.level || '?' }}</span>
        </v-avatar>
        <div class="list-card-body">
          <div class="list-card-title">{{ p.consultantName || '—' }}</div>
          <div class="list-card-sub">НГП {{ fmtNum(p.groupVolume) }} · ставка {{ p.percent ?? 0 }}%</div>
        </div>
        <div class="list-card-aside">
          <v-chip color="warning" size="x-small" variant="tonal">{{ p.qualName || p.title || '—' }}</v-chip>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

interface QualRow {
  id: number;
  consultantName?: string;
  qualName?: string;
  title?: string;
  level?: number;
  percent?: number;
  groupVolume?: number;
}

const search = ref('');
const items = ref<QualRow[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

let debounceTimer: ReturnType<typeof setTimeout> | null = null;
function debouncedLoad() {
  if (debounceTimer) clearTimeout(debounceTimer);
  debounceTimer = setTimeout(load, 350);
}

function fmtNum(n?: number) { return (n ?? 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 }); }

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const params: Record<string, any> = { per_page: 50 };
    if (search.value.trim()) params.search = search.value.trim();
    const { data } = await api.get('/admin/qualifications', { params });
    items.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>
