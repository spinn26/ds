<template>
  <div>
    <PageHeader title="История перестановок" icon="mdi-history" :count="total" />

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по партнёру..."
          rounded prepend-inner-icon="mdi-magnify" clearable hide-details style="max-width:300px" @update:model-value="debouncedLoad" />
        <v-chip v-if="search" size="small" color="info" variant="tonal" class="ml-1">1 фильтр</v-chip>
        <v-btn v-if="search" size="small" variant="text" color="secondary"
          prepend-icon="mdi-filter-remove" @click="search = ''; loadData()">Сбросить</v-btn>
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" @update:options="onOptions">
      <template #item.dateCreated="{ value }">
        {{ fmtDate(value) }}
      </template>
      <template #no-data><EmptyState /></template>
    </v-data-table-server>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import { fmtDate } from '../../composables/useDesign';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const page = ref(1);

const headers = [
  { title: 'Дата', key: 'dateCreated', width: 180 },
  { title: 'Партнёр', key: 'consultantName' },
  { title: 'Прежний наставник', key: 'inviterOldName' },
  { title: 'Новый наставник', key: 'inviterNewName' },
];

const { debounced: debouncedLoad } = useDebounce(loadData, 400);

function onOptions(opts) {
  page.value = opts.page;
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value };
    if (search.value) params.search = search.value;
    const { data } = await api.get('/admin/transfers', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
