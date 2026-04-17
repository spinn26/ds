<template>
  <div>
    <PageHeader title="Мои клиенты" icon="mdi-account-group" :count="total" />

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="filters.search" placeholder="Поиск по ФИО..."
          rounded prepend-inner-icon="mdi-magnify" clearable hide-details style="max-width:240px" @update:model-value="debouncedLoad" />
        <v-select v-model="filters.status" :items="statusOptions" label="Статус"
          clearable hide-details style="max-width:180px" @update:model-value="loadData" />
        <v-text-field v-model="filters.city" placeholder="Город"
          prepend-inner-icon="mdi-city" clearable hide-details style="max-width:200px" @update:model-value="debouncedLoad" />
        <v-text-field v-model="filters.email" placeholder="Email..."
          prepend-inner-icon="mdi-email" clearable hide-details style="max-width:220px" @update:model-value="debouncedLoad" />
        <v-text-field v-model="filters.birth_date_from" label="Дата рождения с" type="date"
          hide-details style="max-width:170px" @update:model-value="loadData" />
        <v-text-field v-model="filters.birth_date_to" label="Дата рождения по" type="date"
          hide-details style="max-width:170px" @update:model-value="loadData" />
        <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal" class="ml-1">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
        </v-chip>
        <v-btn v-if="activeFilterCount > 0" size="small" variant="text" color="secondary"
          prepend-icon="mdi-filter-remove" @click="resetFilters">Сбросить</v-btn>
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" :sort-by="sortBy"
      @update:options="onOptions">
      <template #item.birthDate="{ value }">
        {{ fmtDate(value) }}
      </template>
      <template #item.active="{ value }">
        <v-chip size="x-small" :color="value ? 'success' : 'grey'" variant="tonal">
          {{ value ? 'Активен' : 'Неактивен' }}
        </v-chip>
      </template>
      <template #no-data><EmptyState /></template>
    </v-data-table-server>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import { fmtDate } from '../../composables/useDesign';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const filters = ref({ search: '', status: null, city: '', email: '', birth_date_from: '', birth_date_to: '' });
const page = ref(1);
const sortBy = ref([]);

const statusOptions = [
  { title: 'Активен', value: 'active' },
  { title: 'Неактивен', value: 'inactive' },
];

const activeFilterCount = computed(() => {
  let c = 0;
  if (filters.value.search) c++;
  if (filters.value.status) c++;
  if (filters.value.city) c++;
  if (filters.value.email) c++;
  if (filters.value.birth_date_from) c++;
  if (filters.value.birth_date_to) c++;
  return c;
});

function resetFilters() {
  filters.value = { search: '', status: null, city: '', email: '', birth_date_from: '', birth_date_to: '' };
  loadData();
}

const headers = [
  { title: 'ФИО клиента', key: 'personName', sortable: true },
  { title: 'Статус', key: 'active', width: 120, sortable: false },
  { title: 'Дата рождения', key: 'birthDate', width: 140, sortable: true },
  { title: 'Место жительства', key: 'city', sortable: true },
  { title: 'Телефон', key: 'phone', width: 160, sortable: false },
  { title: 'Email', key: 'email', width: 220, sortable: false },
];

const { debounced: debouncedLoad } = useDebounce(loadData, 400);

function onOptions(opts) {
  page.value = opts.page;
  sortBy.value = opts.sortBy || [];
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value };
    if (filters.value.search) params.search = filters.value.search;
    if (filters.value.status) params.status = filters.value.status;
    if (filters.value.city) params.city = filters.value.city;
    if (filters.value.email) params.email = filters.value.email;
    if (filters.value.birth_date_from) params.birth_date_from = filters.value.birth_date_from;
    if (filters.value.birth_date_to) params.birth_date_to = filters.value.birth_date_to;
    if (sortBy.value.length) {
      params.sort_by = sortBy.value[0].key;
      params.sort_dir = sortBy.value[0].order || 'asc';
    }
    const { data } = await api.get('/clients', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
