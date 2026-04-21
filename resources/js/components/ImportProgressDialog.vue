<template>
  <v-dialog :model-value="modelValue" persistent max-width="600">
    <v-card>
      <v-card-title class="d-flex align-center pa-4">
        <v-icon class="me-2" :color="iconColor">{{ iconName }}</v-icon>
        {{ title }}
        <v-spacer />
        <v-btn v-if="finished" icon="mdi-close" variant="text" size="small"
          @click="$emit('update:modelValue', false)" />
      </v-card-title>
      <v-divider />
      <v-card-text class="pa-4">
        <!-- Startup / running -->
        <template v-if="!finished">
          <div class="mb-3 d-flex justify-space-between align-center">
            <div class="text-body-2">
              {{ progress.status === 'starting' ? 'Подключение…'
                 : `Обработано: ${progress.processed} / ${progress.total || '—'}` }}
            </div>
            <div class="text-h6 font-weight-bold">{{ percent }}%</div>
          </div>
          <v-progress-linear
            :model-value="percent"
            :indeterminate="progress.total === 0 || progress.status === 'starting'"
            color="primary"
            height="10"
            rounded
          />
          <v-row dense class="mt-3">
            <v-col cols="6">
              <div class="text-caption text-medium-emphasis">Успешно</div>
              <div class="text-h6 text-success">{{ progress.success || 0 }}</div>
            </v-col>
            <v-col cols="6">
              <div class="text-caption text-medium-emphasis">Ошибок</div>
              <div class="text-h6" :class="(progress.errors || 0) > 0 ? 'text-error' : ''">
                {{ progress.errors || 0 }}
              </div>
            </v-col>
          </v-row>
        </template>

        <!-- Done -->
        <template v-else>
          <v-alert
            :type="result?.errors === 0 ? 'success' : result?.success > 0 ? 'warning' : 'error'"
            variant="tonal"
            density="comfortable"
          >
            <div class="font-weight-medium">{{ result?.message }}</div>
            <div class="text-body-2 mt-1">
              Создано: <b>{{ result?.success ?? 0 }}</b> ·
              Ошибок: <b>{{ result?.errors ?? 0 }}</b>
              <template v-if="result?.skipped"> · Пропущено дублей: <b>{{ result.skipped }}</b></template>
            </div>
          </v-alert>

          <v-expansion-panels v-if="result?.errorsList?.length || result?.errorDetails?.length" class="mt-3">
            <v-expansion-panel>
              <v-expansion-panel-title>
                Ошибки ({{ (result.errorsList || result.errorDetails).length }})
              </v-expansion-panel-title>
              <v-expansion-panel-text>
                <div v-for="(err, i) in (result.errorsList || result.errorDetails)" :key="i"
                  class="text-body-2 mb-1">
                  <v-icon size="14" color="error" class="me-1">mdi-alert</v-icon>{{ err }}
                </div>
              </v-expansion-panel-text>
            </v-expansion-panel>
          </v-expansion-panels>
        </template>
      </v-card-text>
      <v-divider />
      <v-card-actions class="pa-3">
        <v-spacer />
        <v-btn v-if="!finished" variant="text" disabled>Идёт импорт…</v-btn>
        <v-btn v-else variant="flat" color="primary" @click="$emit('update:modelValue', false)">
          Закрыть
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import api from '../api';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  tracker: { type: String, default: null },
  title: { type: String, default: 'Импорт' },
  result: { type: Object, default: null },
  finished: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);

const progress = ref({ total: 0, processed: 0, success: 0, errors: 0, status: 'starting' });
let pollTimer = null;

const percent = computed(() => {
  if (!progress.value.total) return 0;
  return Math.min(100, Math.round((progress.value.processed / progress.value.total) * 100));
});

const iconName = computed(() => {
  if (!props.finished) return 'mdi-progress-upload';
  if (!props.result) return 'mdi-check-circle';
  return props.result.errors === 0 ? 'mdi-check-circle' : 'mdi-alert-circle';
});
const iconColor = computed(() => {
  if (!props.finished) return 'primary';
  return props.result?.errors === 0 ? 'success' : 'warning';
});

function startPolling() {
  if (!props.tracker) return;
  stopPolling();
  pollTimer = setInterval(async () => {
    try {
      const { data } = await api.get('/admin/import-progress', { params: { tracker: props.tracker } });
      progress.value = { ...progress.value, ...data };
      if (data.status === 'done') stopPolling();
    } catch {}
  }, 500);
}
function stopPolling() {
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}

watch(() => props.modelValue, (v) => {
  if (v) {
    progress.value = { total: 0, processed: 0, success: 0, errors: 0, status: 'starting' };
    startPolling();
  } else {
    stopPolling();
  }
});
watch(() => props.finished, (v) => { if (v) stopPolling(); });

onBeforeUnmount(stopPolling);
</script>
