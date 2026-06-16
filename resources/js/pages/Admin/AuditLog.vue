<template>
  <div>
    <PageHeader title="Аудит-лог" icon="mdi-history" :count="total" />

    <v-card class="mb-3 pa-3">
      <div class="d-flex flex-wrap ga-2 align-center">
        <v-text-field v-model="filters.search" placeholder="Поиск: email / IP / entity_id"
          density="compact" variant="outlined" hide-details clearable rounded
          prepend-inner-icon="mdi-magnify" style="max-width: 280px"
          @update:model-value="debounced" />
        <v-select v-model="filters.entity" :items="entities" placeholder="Сущность"
          density="compact" variant="outlined" hide-details clearable
          style="max-width: 200px" @update:model-value="reload" />
        <v-select v-model="filters.action" :items="actions" placeholder="Действие"
          density="compact" variant="outlined" hide-details clearable
          style="max-width: 200px" @update:model-value="reload" />
        <v-text-field v-model="filters.from" type="date" placeholder="с" density="compact"
          variant="outlined" hide-details style="max-width: 160px" @update:model-value="reload" />
        <v-text-field v-model="filters.to" type="date" placeholder="по" density="compact"
          variant="outlined" hide-details style="max-width: 160px" @update:model-value="reload" />
      </div>
    </v-card>

    <v-card>
      <v-data-table-server :items="rows" :items-length="total" :loading="loading"
        :headers="headers" :items-per-page="perPage" v-model:page="page"
        :items-per-page-options="[25, 50, 100]" density="comfortable" hover
        @update:options="onOptions">
        <template #item.createdAt="{ value }"><span class="text-caption">{{ fmt(value) }}</span></template>
        <template #item.action="{ value }"><v-chip size="x-small" variant="tonal">{{ value }}</v-chip></template>
        <template #item.entity="{ item }">
          <span>{{ item.entity }}</span>
          <span v-if="item.entityId" class="text-medium-emphasis text-caption"> #{{ item.entityId }}</span>
        </template>
        <template #item.payload="{ item }">
          <v-btn v-if="item.payload" size="x-small" variant="text" @click="show(item)">payload</v-btn>
          <span v-else class="text-disabled">—</span>
        </template>
        <template #no-data><EmptyState message="Записей нет" /></template>
      </v-data-table-server>
    </v-card>

    <v-dialog v-model="dialog" max-width="640">
      <v-card>
        <v-card-title class="text-subtitle-2 d-flex align-center">
          Payload
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" size="small" @click="dialog = false" />
        </v-card-title>
        <v-card-text><pre class="ops-pre">{{ payloadText }}</pre></v-card-text>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, EmptyState } from '../../components';
import { useDebounce } from '../../composables/useDebounce';

const headers = [
  { title: 'Время', key: 'createdAt', width: 160, sortable: false },
  { title: 'Кто', key: 'userEmail', sortable: false },
  { title: 'Действие', key: 'action', width: 150, sortable: false },
  { title: 'Сущность', key: 'entity', width: 200, sortable: false },
  { title: 'IP', key: 'ip', width: 140, sortable: false },
  { title: '', key: 'payload', width: 90, sortable: false },
];

const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const page = ref(1);
const perPage = ref(25);
const entities = ref([]);
const actions = ref([]);
const dialog = ref(false);
const payloadText = ref('');
const filters = reactive({ search: '', entity: null, action: null, from: '', to: '' });

function fmt(s) { if (!s) return '—'; const d = new Date(s); return isNaN(d) ? s : d.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' }); }
function show(item) { payloadText.value = JSON.stringify(item.payload, null, 2); dialog.value = true; }

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/audit-log', {
      params: {
        page: page.value, per_page: perPage.value,
        search: filters.search || undefined, entity: filters.entity || undefined,
        action: filters.action || undefined, from: filters.from || undefined, to: filters.to || undefined,
      },
    });
    rows.value = data.data || [];
    total.value = data.total || 0;
    if (data.entities) entities.value = data.entities;
    if (data.actions) actions.value = data.actions;
  } catch { /* ignore */ }
  loading.value = false;
}
function reload() { page.value = 1; load(); }
function onOptions(opts) { page.value = opts.page; if (opts.itemsPerPage) perPage.value = opts.itemsPerPage; load(); }
const { debounced } = useDebounce(reload, 350);

onMounted(load);
</script>

<style scoped>
.ops-pre { font-size: 12px; line-height: 1.5; white-space: pre-wrap; word-break: break-word; }
</style>
