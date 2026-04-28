<template>
  <div>
    <PageHeader title="Акцепт документов" icon="mdi-check-circle" :count="total" />

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="ФИО партнёра"
          density="compact" variant="outlined" hide-details rounded clearable
          prepend-inner-icon="mdi-magnify" style="max-width:280px"
          @update:model-value="debouncedLoad" />
        <v-select v-model="acceptedFilter" :items="acceptedOptions" placeholder="Статус"
          density="compact" variant="outlined" clearable hide-details
          style="max-width:200px" @update:model-value="loadData" />
        <v-select v-model="documentTypeFilter" :items="documentOptions"
          item-title="name" item-value="id"
          placeholder="Вид документа" density="compact" variant="outlined"
          clearable hide-details style="max-width:280px"
          @update:model-value="loadData" />
        <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal" class="ml-1">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
        </v-chip>
        <v-btn v-if="activeFilterCount > 0" size="small" variant="text" color="secondary"
          prepend-icon="mdi-filter-remove" @click="resetFilters">Сбросить</v-btn>
        <v-spacer />
        <span class="text-caption text-medium-emphasis">
          Стр. {{ page }} из {{ Math.max(1, Math.ceil(total / perPage)) }} · Всего: {{ total }}
        </span>
      </div>
    </v-card>

    <v-data-table :items="items" :loading="loading" :headers="headers"
      :items-per-page="perPage" density="compact" hover
      v-model:expanded="expanded" item-value="id" show-expand>
      <template #item.signedCount="{ item }">
        <v-chip size="x-small" :color="item.fullyAccepted ? 'success' : 'warning'" variant="tonal">
          {{ item.signedCount }} из {{ item.totalCount }}
        </v-chip>
      </template>
      <template #expanded-row="{ columns, item }">
        <tr>
          <td :colspan="columns.length" class="pa-0">
            <v-table density="compact" class="acceptance-detail">
              <thead>
                <tr>
                  <th>Вид документа</th>
                  <th class="text-center" style="width:200px">Документы акцептованы</th>
                  <th style="width:200px">Дата акцепта</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="d in item.documents" :key="'d-' + item.id + '-' + d.id">
                  <td>
                    <a :href="d.link" target="_blank" class="text-decoration-none">{{ d.name }}</a>
                  </td>
                  <td class="text-center">
                    <v-icon :color="d.accepted ? 'success' : 'grey'" size="22">
                      {{ d.accepted ? 'mdi-checkbox-marked' : 'mdi-checkbox-blank-outline' }}
                    </v-icon>
                  </td>
                  <td>{{ d.dateAccepted ? fmtDateTime(d.dateAccepted) : '—' }}</td>
                </tr>
              </tbody>
            </v-table>
          </td>
        </tr>
      </template>
      <template #no-data><EmptyState /></template>
    </v-data-table>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const acceptedFilter = ref(null);
const documentTypeFilter = ref(null);
const documentOptions = ref([]);
const expanded = ref([]);
const page = ref(1);
const perPage = ref(25);

const activeFilterCount = computed(() => {
  let c = 0;
  if (search.value) c++;
  if (acceptedFilter.value) c++;
  if (documentTypeFilter.value) c++;
  return c;
});

function resetFilters() {
  search.value = '';
  acceptedFilter.value = null;
  documentTypeFilter.value = null;
  loadData();
}

const acceptedOptions = [
  { title: 'Акцептовано (всё)', value: 'true' },
  { title: 'Не акцептовано', value: 'false' },
];

const headers = [
  { title: 'Партнёр', key: 'personName' },
  { title: 'Статус акцепта', key: 'signedCount', width: 200, align: 'center' },
  { title: '', key: 'data-table-expand', sortable: false, width: 50 },
];

const { debounced: debouncedLoad } = useDebounce(loadData, 400);

function fmtDateTime(d) {
  if (!d) return '—';
  return new Date(d).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value };
    if (search.value) params.search = search.value;
    if (acceptedFilter.value) params.accepted = acceptedFilter.value;
    if (documentTypeFilter.value) params.document_type = documentTypeFilter.value;
    const { data } = await api.get('/admin/acceptance', { params });
    items.value = data.data;
    total.value = data.total;
    if (data.documents) documentOptions.value = data.documents;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>

<style scoped>
.acceptance-detail :deep(td) { vertical-align: middle; }
.acceptance-detail :deep(th) {
  background: rgba(var(--v-theme-surface-variant), 0.4);
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
}
</style>
