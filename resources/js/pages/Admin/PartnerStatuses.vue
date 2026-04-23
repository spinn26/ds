<template>
  <div>
    <PageHeader title="Статусы партнёров" icon="mdi-calendar-clock" />

    <!-- Summary cards: compact horizontal strip. Tap a tile to filter. -->
    <v-row class="mb-3" dense>
      <v-col v-for="s in summary" :key="s.id" cols="6" sm="3">
        <v-card
          class="pa-3 d-flex align-center ga-3 summary-tile"
          :class="{ 'summary-tile--active': activityFilter === s.id }"
          :color="getActivityColor(s.id)" variant="tonal"
          @click="filterByActivity(s.id)"
        >
          <v-icon size="28" class="summary-tile__icon">{{ summaryIcon(s.id) }}</v-icon>
          <div class="flex-grow-1">
            <div class="text-caption text-medium-emphasis">{{ s.name }}</div>
            <div class="text-h5 font-weight-bold">{{ s.count }}</div>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <FilterBar
      :search="search"
      search-placeholder="ФИО партнёра..."
      :search-cols="3"
      :show-reset="activeFilterCount > 0"
      @update:search="v => { search = v ?? ''; debouncedLoad(); }"
      @reset="resetFilters"
    >
      <v-col cols="12" md="3">
        <v-select v-model="activityFilter" :items="activityOptions" label="Статус"
          variant="outlined" density="comfortable"
          clearable hide-details @update:model-value="loadData" />
      </v-col>
      <v-col v-if="activeFilterCount > 0" cols="auto" class="d-flex align-center">
        <v-chip size="small" color="info" variant="tonal">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
        </v-chip>
      </v-col>
      <template #actions>
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="partner-statuses-cols" />
      </template>
    </FilterBar>

    <!-- Detail table -->
    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="visibleHeaders" :items-per-page="25" @update:options="onOptions">
      <template #item.activityName="{ item }">
        <StatusChip :value="item.activityId" kind="activity" size="x-small" :text="item.activityName" />
      </template>
      <template #item.dateActivity="{ value }">
        {{ fmtDate(value) }}
      </template>
      <template #item.willTerminate="{ value }">
        <span v-if="value" :class="isExpiringSoon(value) ? 'text-error font-weight-bold' : ''">
          {{ fmtDate(value) }}
        </span>
        <span v-else>—</span>
      </template>
      <template #item.dateDeterministic="{ value }">
        {{ value ? fmtDate(value) : '—' }}
      </template>
      <template #item.personalVolume="{ value }">
        {{ fmt(value) }}
      </template>
      <template #item.terminationCount="{ value }">
        <v-chip v-if="value > 0" size="x-small" :color="value >= 3 ? 'error' : 'warning'">{{ value }}/3</v-chip>
        <span v-else>—</span>
      </template>
      <template #no-data><EmptyState /></template>
    </v-data-table-server>

    <v-overlay v-model="loading" class="align-center justify-center" persistent>
      <v-progress-circular indeterminate size="64" />
    </v-overlay>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import StatusChip from '../../components/StatusChip.vue';
import FilterBar from '../../components/FilterBar.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import { fmt, fmtDate, getActivityColor } from '../../composables/useDesign';

const loading = ref(true);
const summary = ref([]);
const items = ref([]);
const total = ref(0);
const page = ref(1);
const perPage = ref(25);
const search = ref('');
const activityFilter = ref(null);

const activeFilterCount = computed(() => {
  let c = 0;
  if (search.value) c++;
  if (activityFilter.value) c++;
  return c;
});

function resetFilters() {
  search.value = '';
  activityFilter.value = null;
  loadData();
}

const activityOptions = computed(() =>
  summary.value.map(s => ({ title: `${s.name} (${s.count})`, value: s.id }))
);

const headers = [
  { title: 'Партнёр', key: 'personName' },
  { title: 'Статус', key: 'activityName', width: 150 },
  { title: 'Активен с', key: 'dateActivity', width: 130 },
  { title: 'Будет терминирован', key: 'willTerminate', width: 170 },
  { title: 'Терминирован', key: 'dateDeterministic', width: 140 },
  { title: 'ЛП', key: 'personalVolume', align: 'end', width: 100 },
  { title: 'Терминаций', key: 'terminationCount', width: 110 },
];

const columnVisible = ref({});
const visibleHeaders = computed(() => headers.filter(h => columnVisible.value[h.key] !== false));

function isExpiringSoon(d) {
  if (!d) return false;
  const diff = (new Date(d) - new Date()) / (1000 * 60 * 60 * 24);
  return diff <= 60 && diff > 0;
}

function filterByActivity(id) {
  // Toggle off if the same tile is clicked again
  activityFilter.value = activityFilter.value === id ? null : id;
  loadData();
}

function summaryIcon(id) {
  return {
    1: 'mdi-account-check',
    3: 'mdi-account-cancel',
    4: 'mdi-account-clock',
    5: 'mdi-account-remove',
  }[id] || 'mdi-account';
}

const { debounced: debouncedLoad } = useDebounce(loadData, 400);

function onOptions(opts) {
  page.value = opts.page;
  if (opts.itemsPerPage) perPage.value = opts.itemsPerPage;
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value };
    if (search.value) params.search = search.value;
    if (activityFilter.value) params.activity = activityFilter.value;
    const { data } = await api.get('/admin/partner-statuses', { params });
    summary.value = data.summary || [];
    items.value = data.data || [];
    total.value = data.total || 0;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>

<style scoped>
.summary-tile {
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.summary-tile:hover {
  transform: translateY(-1px);
}
.summary-tile--active {
  box-shadow: 0 0 0 2px rgb(var(--v-theme-primary));
}
.summary-tile__icon {
  opacity: 0.7;
}
</style>
