<template>
  <div>
    <PageHeader title="Массовые операции" icon="mdi-format-list-bulleted-square" />

    <v-alert type="warning" variant="tonal" class="mb-3">
      Массовые операции затрагивают тысячи строк. Всегда сначала запускайте dry-run.
    </v-alert>

    <v-row dense>
      <v-col v-for="a in actions" :key="a.key" cols="12" md="6">
        <v-card>
          <v-card-title class="pa-3 d-flex align-center">
            <v-icon :color="a.color" class="me-2">mdi-format-list-bulleted-square</v-icon>
            {{ a.label }}
            <v-spacer />
            <v-chip v-if="a.targets !== null" size="small" :color="a.targets > 0 ? a.color : 'grey'">
              {{ a.targets }} целей
            </v-chip>
          </v-card-title>
          <v-card-text class="pa-3">
            <p class="text-body-2 text-medium-emphasis">{{ a.hint }}</p>

            <template v-if="a.needsPeriod">
              <div class="d-flex ga-2 mt-2">
                <v-text-field v-model.number="year" label="Год" type="number" variant="outlined"
                  density="comfortable" hide-details style="max-width:120px" />
                <v-select v-model="month" :items="monthOptions" label="Месяц" variant="outlined"
                  density="comfortable" hide-details style="max-width:180px" />
              </div>
            </template>
          </v-card-text>
          <v-card-actions class="pa-3">
            <v-spacer />
            <v-btn variant="tonal" :color="a.color" @click="runAction(a, true)" :loading="busyKey === a.key + ':dry'">
              Dry-run
            </v-btn>
            <v-btn variant="flat" :color="a.color" @click="runAction(a, false)" :loading="busyKey === a.key + ':apply'"
              :disabled="a.targets === 0">
              Применить
            </v-btn>
          </v-card-actions>

          <v-alert v-if="results[a.key]" :type="results[a.key].type" density="compact" class="ma-3">
            {{ results[a.key].text }}
          </v-alert>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';
import { PageHeader } from '../../components';

const actions = ref([]);
const busyKey = ref(null);
const results = ref({});

const now = new Date();
const year = ref(now.getFullYear());
const month = ref(now.getMonth() + 1);
const monthOptions = Array.from({ length: 12 }, (_, i) => ({
  title: new Date(2000, i, 1).toLocaleDateString('ru-RU', { month: 'long' }),
  value: i + 1,
}));

async function load() {
  try { const { data } = await api.get('/admin/ops/bulk'); actions.value = data.actions || []; } catch {}
}

async function runAction(a, dryRun) {
  busyKey.value = a.key + (dryRun ? ':dry' : ':apply');
  const payload = { dryRun };
  if (a.needsPeriod) { payload.year = year.value; payload.month = month.value; }
  try {
    const { data } = await api.post(`/admin/ops/bulk/${a.key}`, payload);
    results.value[a.key] = {
      type: dryRun ? 'info' : 'success',
      text: data.message || `Затронуто: ${data.count ?? '—'} (dryRun=${data.dryRun})`,
    };
    if (!dryRun) await load();
  } catch (e) {
    results.value[a.key] = { type: 'error', text: e.response?.data?.message || 'Ошибка' };
  }
  busyKey.value = null;
}

onMounted(load);
</script>
