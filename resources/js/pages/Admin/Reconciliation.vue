<template>
  <div>
    <PageHeader title="Реконсиляция балансов" icon="mdi-scale-balance">
      <template #actions>
        <v-btn variant="text" prepend-icon="mdi-refresh" :loading="loading" @click="load">Пересчитать</v-btn>
      </template>
    </PageHeader>

    <v-card class="mb-3">
      <v-card-text class="pa-3">
        <div class="d-flex ga-2 align-center flex-wrap">
          <v-text-field v-model.number="year" label="Год" type="number" variant="outlined"
            density="comfortable" hide-details style="max-width:120px" @change="load" />
          <v-select v-model="month" :items="monthOptions" label="Месяц" variant="outlined"
            density="comfortable" hide-details style="max-width:200px" @update:model-value="load" />
          <v-spacer />
          <v-chip v-if="data.total" :color="data.passed === data.total ? 'success' : 'warning'" variant="flat">
            {{ data.passed }} / {{ data.total }} проверок пройдено
          </v-chip>
        </div>
      </v-card-text>
    </v-card>

    <!-- Aggregates -->
    <v-row dense class="mb-3">
      <v-col v-for="a in aggTiles" :key="a.key" cols="6" sm="4" md="3">
        <v-card variant="tonal" class="pa-3">
          <div class="text-caption text-medium-emphasis">{{ a.label }}</div>
          <div class="text-h6 font-weight-bold">
            <MoneyCell :value="a.value" currency="₽" />
          </div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Checks -->
    <v-card>
      <v-list>
        <v-list-item v-for="(c, i) in data.checks || []" :key="i">
          <template #prepend>
            <v-icon :color="c.pass ? 'success' : 'error'">
              {{ c.pass ? 'mdi-check-circle' : 'mdi-alert-circle' }}
            </v-icon>
          </template>
          <v-list-item-title>{{ c.label }}</v-list-item-title>
          <v-list-item-subtitle>{{ c.note }}</v-list-item-subtitle>
          <template #append>
            <div class="text-right">
              <div class="text-caption">
                <MoneyCell :value="c.a" currency="₽" /> vs <MoneyCell :value="c.b" currency="₽" />
              </div>
              <div class="text-caption" :class="Math.abs(c.delta) < 1 ? 'text-success' : 'text-error'">
                Δ = <MoneyCell :value="c.delta" currency="₽" :colored="true" :signed="true" />
              </div>
            </div>
          </template>
        </v-list-item>
      </v-list>
    </v-card>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, MoneyCell } from '../../components';
import { useSnackbar } from '../../composables/useSnackbar';

const { showError } = useSnackbar();
const now = new Date();
const year = ref(now.getFullYear());
const month = ref(now.getMonth() + 1);
const data = ref({});
const loading = ref(false);

const monthOptions = Array.from({ length: 12 }, (_, i) => ({
  title: new Date(2000, i, 1).toLocaleDateString('ru-RU', { month: 'long' }),
  value: i + 1,
}));

const aggTiles = computed(() => {
  const a = data.value.aggregates || {};
  return [
    { key: 'gross', label: 'Оборот клиентов', value: a.transactionsGross },
    { key: 'net', label: 'Выручка ДС', value: a.transactionsNet },
    { key: 'commSum', label: 'Σ commission.amountRUB', value: a.commissionSum },
    { key: 'poolSum', label: 'Σ poolLog', value: a.poolSum },
    { key: 'balAccrTx', label: 'Балансы: начислено tx', value: a.balanceAccruedTx },
    { key: 'balAccrPool', label: 'Балансы: пул', value: a.balanceAccruedPool },
    { key: 'balPayable', label: 'К оплате (всего)', value: a.balanceTotalPayable },
    { key: 'balPayed', label: 'Оплачено', value: a.balancePayed },
  ];
});

async function load() {
  loading.value = true;
  try {
    const { data: d } = await api.get('/admin/analytics/reconciliation', { params: { year: year.value, month: month.value } });
    data.value = d;
  } catch (e) { showError(e.response?.data?.message || 'Не удалось выполнить реконсиляцию'); }
  loading.value = false;
}

onMounted(load);
</script>
