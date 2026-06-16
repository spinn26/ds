<template>
  <div>
    <PageHeader title="Система" icon="mdi-server-network" />

    <v-row>
      <v-col cols="12" md="6">
        <v-card class="mb-4">
          <v-card-title class="text-subtitle-2">Управление кэшем</v-card-title>
          <v-card-text>
            <div class="text-caption text-medium-emphasis mb-3">
              Очистка кэшей платформы. «Кэш приложения» сбрасывает в т.ч. матрицу
              калькулятора, настройки и фиче-флаги.
            </div>
            <div class="d-flex flex-wrap ga-2">
              <v-btn size="small" color="primary" variant="tonal" prepend-icon="mdi-broom"
                :loading="busy === 'app'" @click="clearCache('app')">Кэш приложения</v-btn>
              <v-btn size="small" variant="tonal" :loading="busy === 'config'" @click="clearCache('config')">Config</v-btn>
              <v-btn size="small" variant="tonal" :loading="busy === 'route'" @click="clearCache('route')">Routes</v-btn>
              <v-btn size="small" variant="tonal" :loading="busy === 'view'" @click="clearCache('view')">Views</v-btn>
            </div>
            <pre v-if="cacheOut" class="ops-pre mt-3">{{ cacheOut }}</pre>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="6">
        <v-card class="mb-4">
          <v-card-title class="text-subtitle-2 d-flex align-center">
            Планировщик (cron)
            <v-spacer />
            <v-btn icon="mdi-refresh" size="x-small" variant="text" :loading="schedLoading" @click="loadScheduled" />
          </v-card-title>
          <v-card-text>
            <div class="text-caption text-medium-emphasis mb-2">
              Список задач Laravel-планировщика (read-only). Выполняются воркером
              <code>schedule:run</code> на сервере.
            </div>
            <pre v-if="scheduled" class="ops-pre">{{ scheduled }}</pre>
            <EmptyState v-else-if="!schedLoading" message="Нет данных" />
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="3500">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, EmptyState } from '../../components';

const busy = ref('');
const cacheOut = ref('');
const scheduled = ref('');
const schedLoading = ref(false);
const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

async function clearCache(target) {
  busy.value = target;
  try {
    const { data } = await api.post('/admin/ops/cache/clear', { target });
    cacheOut.value = data.output || '';
    notify(data.message || 'Готово');
  } catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
  busy.value = '';
}

async function loadScheduled() {
  schedLoading.value = true;
  try {
    const { data } = await api.get('/admin/ops/scheduled');
    scheduled.value = data.output || '';
  } catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
  schedLoading.value = false;
}

onMounted(loadScheduled);
</script>

<style scoped>
.ops-pre {
  background: rgba(var(--v-theme-on-surface), 0.04);
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 12px;
  line-height: 1.5;
  overflow-x: auto;
  white-space: pre;
}
</style>
