<template>
  <div>
    <PageHeader title="Настройки системы" icon="mdi-cog" />

    <v-alert type="warning" variant="tonal" class="mb-3">
      Большинство констант сейчас зашиты в коде. Редактирование через UI требует
      таблицы <code>system_settings</code> и передачи значений в сервисы. Страница
      показывает текущие значения для инспекции.
    </v-alert>

    <v-card>
      <v-data-table :items="settings" :headers="headers" density="comfortable" hover>
        <template #item.editable="{ value }">
          <BooleanCell :value="!!value" :tooltip="{ on: 'Можно менять', off: 'Только код' }" />
        </template>
        <template #item.source="{ value }"><code class="text-caption">{{ value }}</code></template>
      </v-data-table>
    </v-card>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, BooleanCell } from '../../components';

const settings = ref([]);

const headers = [
  { title: 'Параметр', key: 'label' },
  { title: 'Значение', key: 'value', width: 150 },
  { title: 'Источник', key: 'source' },
  { title: 'Меняется', key: 'editable', width: 100 },
];

async function load() {
  try { const { data } = await api.get('/admin/ops/settings'); settings.value = data.settings || []; } catch {}
}
onMounted(load);
</script>
