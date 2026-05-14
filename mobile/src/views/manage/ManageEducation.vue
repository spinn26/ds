<template>
  <div>
    <PageHeader title="Обучение">
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
      <v-icon size="48">mdi-school-outline</v-icon>
      <div class="empty-state-text">Курсов нет</div>
    </div>

    <div v-else class="list">
      <div v-for="c in items" :key="c.id" class="course-card">
        <div class="cc-head">
          <v-icon :color="c.color || 'primary'" size="22">{{ c.icon || 'mdi-school' }}</v-icon>
          <div class="cc-title">{{ c.title || c.name }}</div>
          <v-chip :color="statusColor(c.status)" size="x-small" variant="tonal">
            {{ statusLabel(c.status) }}
          </v-chip>
        </div>
        <div class="cc-meta">
          <span>{{ c.lessonsCount ?? c.lessons ?? 0 }} уроков</span>
          <span v-if="c.studentsCount != null"> · {{ c.studentsCount }} учеников</span>
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

interface Course {
  id: number;
  title?: string;
  name?: string;
  lessonsCount?: number;
  lessons?: number;
  studentsCount?: number;
  status?: string;
  icon?: string;
  color?: string;
}

const items = ref<Course[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

function statusColor(s?: string) {
  return ({ published: 'success', draft: 'warning', archived: 'grey' } as Record<string, string>)[s || ''] || 'grey';
}
function statusLabel(s?: string) {
  return ({ published: 'опубликован', draft: 'черновик', archived: 'архив' } as Record<string, string>)[s || ''] || (s || '—');
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const { data } = await api.get('/admin/education/courses');
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
.course-card { background: #fff; border-radius: 14px; padding: 14px; margin-bottom: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.cc-head { display: flex; align-items: center; gap: 8px; }
.cc-title { flex: 1; font-size: 14px; font-weight: 600; }
.cc-meta { font-size: 12px; color: rgba(0,0,0,0.55); margin-top: 6px; }
.empty-state { padding: 60px 24px; text-align: center; }
.empty-state .v-icon { color: rgba(0,0,0,0.2); margin-bottom: 12px; }
.empty-state-text { font-size: 14px; color: rgba(0,0,0,0.5); }
</style>
