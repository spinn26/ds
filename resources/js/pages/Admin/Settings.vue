<template>
  <div>
    <PageHeader title="Настройки системы" icon="mdi-cog">
      <template #actions>
        <v-btn color="primary" prepend-icon="mdi-content-save" :loading="saving"
          :disabled="!dirty" @click="save">Сохранить</v-btn>
      </template>
    </PageHeader>

    <v-alert type="info" variant="tonal" density="comfortable" class="mb-3">
      Изменения применяются сразу после сохранения. Каждый параметр читается
      сервисами с фолбэком на прежнее значение, поэтому пустые/некорректные
      значения безопасны. Денежные правила (комиссии/пул/штрафы) появятся здесь
      в следующей фазе.
    </v-alert>

    <MaintenanceControl class="mb-4" />

    <v-card>
      <v-tabs v-model="tab" color="primary" show-arrows>
        <v-tab v-for="g in groups" :key="g.category" :value="g.category">
          {{ g.title }}
        </v-tab>
      </v-tabs>

      <v-divider />

      <v-window v-model="tab">
        <v-window-item v-for="g in groups" :key="g.category" :value="g.category">
          <v-card-text>
            <div v-for="item in g.items" :key="item.key" class="setting-row">
              <div class="setting-meta">
                <div class="text-body-2 font-weight-medium">{{ item.label || item.key }}</div>
                <div v-if="item.description" class="text-caption text-medium-emphasis">
                  {{ item.description }}
                </div>
                <code class="text-caption text-disabled">{{ item.key }}</code>
              </div>
              <div class="setting-control">
                <v-switch v-if="item.type === 'bool'" v-model="form[item.key]"
                  color="primary" density="compact" hide-details inset
                  @update:model-value="markDirty" />
                <v-text-field v-else-if="item.type === 'int' || item.type === 'float'"
                  v-model.number="form[item.key]" type="number" density="compact"
                  variant="outlined" hide-details class="ctl-num"
                  @update:model-value="markDirty" />
                <v-textarea v-else-if="item.type === 'json'" v-model="form[item.key]"
                  rows="3" auto-grow density="compact" variant="outlined" hide-details
                  class="ctl-wide" @update:model-value="markDirty" />
                <v-text-field v-else v-model="form[item.key]" density="compact"
                  variant="outlined" hide-details class="ctl-wide"
                  :placeholder="item.isSecret ? '•••••• (скрыто)' : ''"
                  @update:model-value="markDirty" />
              </div>
            </div>
            <EmptyState v-if="!g.items.length" message="Нет параметров в этой категории" />
          </v-card-text>
        </v-window-item>
      </v-window>

      <div v-if="loading" class="pa-6 d-flex justify-center">
        <v-progress-circular indeterminate color="primary" />
      </div>
    </v-card>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="4000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, EmptyState } from '../../components';
import MaintenanceControl from '../../components/MaintenanceControl.vue';

const groups = ref([]);
const form = reactive({});
const tab = ref(null);
const loading = ref(true);
const saving = ref(false);
const dirty = ref(false);
const snack = ref({ open: false, color: 'success', text: '' });

function notify(text, color = 'success') { snack.value = { open: true, color, text }; }
function markDirty() { dirty.value = true; }

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/settings');
    // Категория «maintenance» управляется отдельной карточкой сверху — прячем из вкладок.
    groups.value = (data.groups || []).filter((g) => g.category !== 'maintenance');
    for (const g of groups.value) {
      for (const item of g.items) {
        form[item.key] = item.value;
      }
    }
    if (groups.value.length) tab.value = groups.value[0].category;
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка загрузки', 'error');
  }
  loading.value = false;
}

async function save() {
  saving.value = true;
  try {
    const payload = {};
    for (const g of groups.value) {
      for (const item of g.items) {
        // Скрытые поля не отправляем пустыми (чтобы не затирать секрет).
        if (item.isSecret && (form[item.key] === null || form[item.key] === '')) continue;
        payload[item.key] = form[item.key];
      }
    }
    await api.put('/admin/settings', { settings: payload });
    dirty.value = false;
    notify('Настройки сохранены');
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка сохранения', 'error');
  }
  saving.value = false;
}

onMounted(load);
</script>

<style scoped>
.setting-row {
  display: flex;
  align-items: flex-start;
  gap: 24px;
  padding: 14px 0;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.06);
}
.setting-row:last-child { border-bottom: none; }
.setting-meta { flex: 1 1 auto; min-width: 0; }
.setting-meta code { display: block; margin-top: 2px; }
/* Фикс ширины: без явной ширины v-text-field в flex:0 0 контейнере
   схлопывался до контента («ekat»). Задаём предсказуемую ширину. */
.setting-control { flex: 0 0 auto; display: flex; align-items: center; justify-content: flex-end; }
.setting-control .ctl-wide { width: 360px; }
.setting-control .ctl-num { width: 200px; }
@media (max-width: 700px) {
  .setting-row { flex-direction: column; gap: 8px; }
  .setting-control { justify-content: flex-start; }
  .setting-control .ctl-wide, .setting-control .ctl-num { width: 100%; }
}
</style>
