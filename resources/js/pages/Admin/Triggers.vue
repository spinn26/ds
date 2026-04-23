<template>
  <div>
    <PageHeader title="Триггеры уведомлений" icon="mdi-robot" />

    <v-alert type="info" variant="tonal" class="mb-3">
      Каталог событий, на которые система может отправлять уведомления партнёрам
      и staff. Полная активация требует создания таблицы <code>notification_triggers</code>
      и фоновых слушателей событий.
    </v-alert>

    <v-card>
      <div class="d-flex justify-end px-3 pt-2">
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="triggers-cols" />
      </div>
      <v-data-table :items="catalog" :headers="visibleHeaders" density="comfortable" hover>
        <template #item.channels="{ value }">
          <v-chip v-for="c in value || []" :key="c" size="x-small" class="mr-1"
            :color="chipColor(c)">
            {{ c }}
          </v-chip>
        </template>
        <template #item.enabled="{ value }">
          <BooleanCell :value="!!value" :tooltip="{ on: 'Активен', off: 'Выключен' }" />
        </template>
      </v-data-table>
    </v-card>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, BooleanCell, ColumnVisibilityMenu } from '../../components';

const catalog = ref([]);

const headers = [
  { title: 'Событие', key: 'event', width: 280 },
  { title: 'Описание', key: 'label' },
  { title: 'Каналы', key: 'channels', width: 200 },
  { title: 'Активен', key: 'enabled', width: 100 },
];

const columnVisible = ref({});
const visibleHeaders = computed(() => headers.filter(h => columnVisible.value[h.key] !== false));

function chipColor(c) {
  return { email: 'info', tg: 'primary', inApp: 'warning' }[c] || 'grey';
}

async function load() {
  try { const { data } = await api.get('/admin/ops/triggers'); catalog.value = data.catalog || []; } catch {}
}
onMounted(load);
</script>
