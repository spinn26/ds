<template>
  <div>
    <PageHeader title="Конкурсы">
      <template #actions>
        <v-btn icon="mdi-refresh" size="small" variant="text" :loading="loading" @click="load" />
      </template>
    </PageHeader>

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>

    <div v-if="loading" class="text-center py-8">
      <v-progress-circular indeterminate color="warning" size="32" />
    </div>

    <div v-else-if="!items.length" class="empty-state">
      <v-icon size="48">mdi-trophy-outline</v-icon>
      <div class="empty-state-text">Конкурсов нет</div>
    </div>

    <div v-else class="list">
      <div v-for="c in items" :key="c.id" class="contest-card">
        <div class="cc-head">
          <div>
            <div class="cc-title">{{ c.name || c.title }}</div>
            <div class="cc-meta">{{ formatDate(c.startDate || c.dateFrom) }} — {{ formatDate(c.endDate || c.dateTo) }}</div>
          </div>
          <v-chip :color="statusColor(c.status)" size="x-small" variant="flat">{{ statusLabel(c.status) }}</v-chip>
        </div>
        <div v-if="c.description" class="cc-desc">{{ c.description }}</div>
        <div class="cc-foot">
          <v-icon size="14">mdi-account-group</v-icon>
          <span>{{ c.participantsCount ?? 0 }}</span>
          <v-icon size="14" class="ml-2">mdi-gift-outline</v-icon>
          <span>{{ c.prize || '—' }}</span>
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

interface Contest {
  id: number;
  name?: string;
  title?: string;
  description?: string;
  status?: string;
  startDate?: string;
  endDate?: string;
  dateFrom?: string;
  dateTo?: string;
  participantsCount?: number;
  prize?: string;
}

const items = ref<Contest[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

function formatDate(iso?: string) {
  if (!iso) return '—';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return iso;
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
}
function statusColor(s?: string) {
  return ({ active: 'success', planned: 'info', finished: 'grey' } as Record<string, string>)[s || ''] || 'grey';
}
function statusLabel(s?: string) {
  return ({ active: 'идёт', planned: 'скоро', finished: 'завершён' } as Record<string, string>)[s || ''] || (s || '—');
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const { data } = await api.get('/admin/contests');
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
.contest-card { background: #fff; border-radius: 14px; padding: 14px; margin-bottom: 10px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.cc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
.cc-title { font-size: 14px; font-weight: 700; }
.cc-meta { font-size: 11px; color: rgba(0,0,0,0.55); margin-top: 2px; }
.cc-desc { font-size: 12px; color: rgba(0,0,0,0.65); margin: 8px 0; }
.cc-foot { display: flex; align-items: center; gap: 4px; font-size: 11px; color: rgba(0,0,0,0.55); margin-top: 6px; }
.empty-state { padding: 60px 24px; text-align: center; }
.empty-state .v-icon { color: rgba(0,0,0,0.2); margin-bottom: 12px; }
.empty-state-text { font-size: 14px; color: rgba(0,0,0,0.5); }
</style>
