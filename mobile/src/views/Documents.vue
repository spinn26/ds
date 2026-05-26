<template>
  <div>
    <PageHeader title="Документы" back />

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>

    <div v-if="loading" class="text-center py-8">
      <v-progress-circular indeterminate color="primary" size="32" />
    </div>

    <div v-else-if="!items.length" class="empty-state">
      <v-icon size="48">mdi-file-document-outline</v-icon>
      <div class="empty-state-text">Документов нет</div>
    </div>

    <div v-else>
      <div v-for="doc in items" :key="doc.id" class="doc-card">
        <v-icon :color="iconColor(doc.type || extOf(doc.name))" size="32">
          {{ icon(doc.type || extOf(doc.name)) }}
        </v-icon>
        <div class="doc-body">
          <div class="doc-title">{{ doc.title || doc.name }}</div>
          <div class="doc-meta">{{ doc.size ? formatSize(doc.size) : '' }} {{ doc.date ? '· ' + formatDate(doc.date) : '' }}</div>
        </div>
        <v-btn icon variant="text" size="small" :href="doc.url" target="_blank">
          <v-icon>mdi-download</v-icon>
        </v-btn>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

interface Doc {
  id: number;
  title?: string;
  name?: string;
  type?: string;
  size?: number;
  date?: string;
  url?: string;
}

const items = ref<Doc[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

function extOf(name?: string) {
  if (!name) return '';
  return name.split('.').pop()?.toLowerCase() || '';
}
function icon(t: string) {
  return ({ pdf: 'mdi-file-pdf-box', xlsx: 'mdi-microsoft-excel', xls: 'mdi-microsoft-excel', docx: 'mdi-file-word-box', doc: 'mdi-file-word-box', png: 'mdi-file-image', jpg: 'mdi-file-image', jpeg: 'mdi-file-image' } as Record<string, string>)[t] || 'mdi-file-outline';
}
function iconColor(t: string) {
  return ({ pdf: 'error', xlsx: 'success', xls: 'success', docx: 'info', doc: 'info', png: 'warning', jpg: 'warning', jpeg: 'warning' } as Record<string, string>)[t] || 'grey';
}
function formatSize(n: number) {
  if (n < 1024) return n + ' B';
  if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
  return (n / 1024 / 1024).toFixed(1) + ' MB';
}
function formatDate(iso?: string) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return iso;
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' });
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const { data } = await api.get('/documents');
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
.doc-card { display: flex; align-items: center; gap: 12px; background: #fff; border-radius: 14px; padding: 12px 14px; margin-bottom: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.doc-body { flex: 1; min-width: 0; }
.doc-title { font-size: 14px; font-weight: 600; color: #1b1b1b; }
.doc-meta { font-size: 11px; color: rgba(0,0,0,0.5); }
.empty-state { padding: 60px 24px; text-align: center; }
.empty-state .v-icon { color: rgba(0,0,0,0.2); margin-bottom: 12px; }
.empty-state-text { font-size: 14px; color: rgba(0,0,0,0.5); }
</style>
