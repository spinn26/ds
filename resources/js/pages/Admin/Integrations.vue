<template>
  <div>
    <PageHeader title="Статус интеграций" icon="mdi-cloud-sync">
      <template #actions>
        <v-btn variant="text" prepend-icon="mdi-refresh" :loading="loading" @click="load">Перепроверить</v-btn>
      </template>
    </PageHeader>

    <v-row dense>
      <v-col v-for="s in services" :key="s.key" cols="12" sm="6" md="4">
        <v-card :color="statusColor(s.status)" variant="tonal" class="pa-3">
          <div class="d-flex align-center mb-2">
            <v-icon size="32" :color="statusColor(s.status)">{{ statusIcon(s.status) }}</v-icon>
            <div class="ms-3">
              <div class="text-body-1 font-weight-bold">{{ s.label }}</div>
              <div class="text-caption text-medium-emphasis">{{ s.host }}</div>
            </div>
            <v-spacer />
            <v-chip :color="statusColor(s.status)" size="small" variant="flat">{{ statusLabel(s.status) }}</v-chip>
          </div>
          <div class="text-body-2">{{ s.details }}</div>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';
import { PageHeader } from '../../components';

const services = ref([]);
const loading = ref(false);

function statusColor(s) {
  return { up: 'success', down: 'error', disabled: 'grey' }[s] || 'warning';
}
function statusIcon(s) {
  return { up: 'mdi-check-circle', down: 'mdi-close-circle', disabled: 'mdi-minus-circle' }[s] || 'mdi-help-circle';
}
function statusLabel(s) {
  return { up: 'Работает', down: 'Недоступен', disabled: 'Отключён' }[s] || s;
}

async function load() {
  loading.value = true;
  try { const { data } = await api.get('/admin/ops/integrations'); services.value = data.services || []; } catch {}
  loading.value = false;
}

onMounted(load);
</script>
