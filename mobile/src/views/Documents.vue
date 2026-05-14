<template>
  <div>
    <PageHeader title="Документы" back />

    <div class="chip-row">
      <v-chip v-for="t in tabs" :key="t.value"
        :color="tab === t.value ? 'primary' : undefined"
        :variant="tab === t.value ? 'flat' : 'tonal'"
        size="small" label @click="tab = t.value">
        {{ t.label }}
      </v-chip>
    </div>

    <div v-for="doc in docs" :key="doc.id" class="doc-card">
      <v-icon :color="iconColor(doc.type)" size="32">{{ icon(doc.type) }}</v-icon>
      <div class="doc-body">
        <div class="doc-title">{{ doc.title }}</div>
        <div class="doc-meta">{{ doc.size }} · {{ doc.date }}</div>
      </div>
      <v-btn icon variant="text" size="small">
        <v-icon>mdi-download</v-icon>
      </v-btn>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

const tab = ref('contracts');
const tabs = [
  { value: 'contracts', label: 'Договоры' },
  { value: 'acts', label: 'Акты' },
  { value: 'reports', label: 'Отчёты' },
  { value: 'other', label: 'Прочие' },
];

const docs = [
  { id: 1, title: 'Партнёрский договор', type: 'pdf', size: '380 KB', date: '12.04.2024' },
  { id: 2, title: 'Акт сверки за апрель 2026', type: 'pdf', size: '120 KB', date: '02.05.2026' },
  { id: 3, title: 'Отчёт по структуре Q1 2026', type: 'xlsx', size: '54 KB', date: '04.04.2026' },
];

function icon(t: string) {
  return ({ pdf: 'mdi-file-pdf-box', xlsx: 'mdi-microsoft-excel', docx: 'mdi-file-word-box' } as Record<string, string>)[t] || 'mdi-file-outline';
}
function iconColor(t: string) {
  return ({ pdf: 'error', xlsx: 'success', docx: 'info' } as Record<string, string>)[t] || 'grey';
}
</script>

<style scoped>
.doc-card {
  display: flex;
  align-items: center;
  gap: 12px;
  background: #fff;
  border-radius: 14px;
  padding: 12px 14px;
  margin-bottom: 8px;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
}
.doc-body { flex: 1; min-width: 0; }
.doc-title {
  font-size: 14px;
  font-weight: 600;
  color: #1b1b1b;
}
.doc-meta {
  font-size: 11px;
  color: rgba(0, 0, 0, 0.5);
}
</style>
