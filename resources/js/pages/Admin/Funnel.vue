<template>
  <div>
    <PageHeader title="Воронка нового партнёра" icon="mdi-filter-variant" />

    <v-card class="mb-3">
      <v-card-text>
        Путь от регистрации до лидерской квалификации. Показывает, где партнёры
        теряются, чтобы можно было точечно работать со слабыми конверсиями.
      </v-card-text>
    </v-card>

    <v-card>
      <v-card-text>
        <div v-for="(s, i) in steps" :key="s.key" class="mb-4">
          <div class="d-flex align-center mb-1">
            <span class="text-body-1 font-weight-medium">{{ s.label }}</span>
            <v-spacer />
            <span class="text-body-1 font-weight-bold">
              {{ s.count.toLocaleString('ru-RU') }}
            </span>
            <span v-if="i > 0" class="text-caption text-medium-emphasis ms-3" style="min-width: 80px; text-align:right">
              {{ s.rate }}% от пред.
            </span>
          </div>
          <v-progress-linear
            :model-value="widthOf(s)"
            :color="s.negative ? 'error' : 'primary'"
            height="28"
          >
            <span class="text-caption text-white px-2">{{ widthOf(s).toFixed(1) }}% от {{ totalEver.toLocaleString('ru-RU') }} зарег.</span>
          </v-progress-linear>
        </div>
      </v-card-text>
    </v-card>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { PageHeader } from '../../components';

const steps = ref([]);
const totalEver = ref(0);

function widthOf(s) {
  if (!totalEver.value) return 0;
  return (s.count / totalEver.value) * 100;
}

async function load() {
  try {
    const { data } = await api.get('/admin/analytics/funnel');
    steps.value = data.steps || [];
    totalEver.value = data.totalEverRegistered || 0;
  } catch {}
}
onMounted(load);
</script>
