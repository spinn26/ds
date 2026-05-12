<template>
  <v-card v-if="show" :color="bannerColor" variant="flat" class="pa-3 mb-4 status-banner"
    @click="goToStatus">
    <div class="d-flex align-center ga-3">
      <v-icon size="24" color="white">{{ statusIcon }}</v-icon>
      <div class="text-white flex-grow-1 min-w-0">
        <div class="font-weight-medium text-truncate">{{ overall.label }}</div>
        <div v-if="firstIncident" class="text-caption text-truncate" style="opacity: 0.9">
          {{ firstIncident.title }}{{ activeCount > 1 ? ` (и ещё ${activeCount - 1})` : '' }}
        </div>
      </div>
      <v-icon color="white" size="18">mdi-chevron-right</v-icon>
    </div>
  </v-card>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api';

const router = useRouter();
const overall = ref({ status: 'operational', label: '' });
const active = ref([]);
let timer = null;

// Если всё ОК — баннер не показываем, чтобы не загромождать главную.
const show = computed(() => overall.value.status && overall.value.status !== 'operational');
const activeCount = computed(() => active.value.length);
const firstIncident = computed(() => active.value[0] || null);

const bannerColor = computed(() => ({
  maintenance: 'info',
  degraded: 'warning',
  partial_outage: 'orange',
  major_outage: 'error',
}[overall.value.status] || 'grey'));

const statusIcon = computed(() => ({
  maintenance: 'mdi-tools',
  degraded: 'mdi-alert',
  partial_outage: 'mdi-alert-octagon',
  major_outage: 'mdi-close-octagon',
}[overall.value.status] || 'mdi-information'));

async function load() {
  try {
    const { data } = await api.get('/system-status');
    overall.value = data.overall || { status: 'operational', label: '' };
    active.value = data.active || [];
  } catch {}
}

function goToStatus() {
  router.push('/status');
}

onMounted(() => {
  load();
  // Обновляем раз в 2 минуты — на главной не нужна real-time.
  timer = setInterval(load, 120000);
});
onUnmounted(() => clearInterval(timer));
</script>

<style scoped>
.status-banner {
  cursor: pointer;
  transition: filter 0.15s;
}
.status-banner:hover {
  filter: brightness(1.08);
}
</style>
