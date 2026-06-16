<template>
  <div>
    <PageHeader title="Журнал входов" icon="mdi-login-variant" />

    <v-card class="mb-3 pa-3">
      <v-text-field v-model="search" placeholder="Поиск по ФИО / e-mail / IP"
        density="compact" variant="outlined" hide-details clearable rounded
        prepend-inner-icon="mdi-magnify" style="max-width: 360px"
        @update:model-value="debounced" />
    </v-card>

    <v-card>
      <v-data-table :items="rows" :headers="headers" density="comfortable" hover :loading="loading">
        <template #item.action="{ value }">
          <v-chip size="x-small" :color="value === 'login' ? 'success' : 'warning'" variant="tonal">
            {{ value === 'login' ? 'вход' : '2FA-запрос' }}
          </v-chip>
        </template>
        <template #item.createdAt="{ value }">
          <span class="text-caption">{{ fmt(value) }}</span>
        </template>
        <template #item.userAgent="{ value }">
          <span class="text-caption text-medium-emphasis" :title="value">{{ shortUa(value) }}</span>
        </template>
        <template #no-data><EmptyState message="Записей нет" /></template>
      </v-data-table>
    </v-card>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, EmptyState } from '../../components';
import { useDebounce } from '../../composables/useDebounce';

const headers = [
  { title: 'Время', key: 'createdAt', width: 170 },
  { title: 'Пользователь', key: 'name' },
  { title: 'Событие', key: 'action', width: 120 },
  { title: 'IP', key: 'ip', width: 150 },
  { title: 'Устройство', key: 'userAgent' },
];

const rows = ref([]);
const loading = ref(false);
const search = ref('');

function fmt(s) { if (!s) return '—'; const d = new Date(s); return isNaN(d) ? s : d.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' }); }
function shortUa(ua) {
  if (!ua) return '—';
  const m = ua.match(/(Chrome|Firefox|Safari|Edg|YaBrowser|OPR)\/[\d.]+/);
  const os = /Windows/.test(ua) ? 'Windows' : /Mac/.test(ua) ? 'macOS' : /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iOS' : /Linux/.test(ua) ? 'Linux' : '';
  return [m ? m[0] : ua.slice(0, 24), os].filter(Boolean).join(' · ');
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/login-log', { params: { search: search.value || undefined } });
    rows.value = data.data || [];
  } catch { /* ignore */ }
  loading.value = false;
}
const { debounced } = useDebounce(load, 350);

onMounted(load);
</script>
