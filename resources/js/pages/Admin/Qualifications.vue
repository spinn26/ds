<template>
  <div>
    <PageHeader title="Квалификации" icon="mdi-chart-bar" :count="total" />

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="month" type="month" label="Отчётный месяц"
          density="compact" variant="outlined" hide-details style="max-width:200px"
          @update:model-value="loadData" />
        <v-text-field v-model="search" placeholder="ФИО партнёра"
          density="compact" variant="outlined" hide-details rounded clearable
          prepend-inner-icon="mdi-magnify" style="max-width:280px"
          @update:model-value="debouncedLoad" />
        <v-select v-model="qualFilter" :items="qualOptions"
          density="compact" variant="outlined" hide-details clearable
          placeholder="Квалификация" style="max-width:180px"
          @update:model-value="loadData" />
        <v-select v-model="activityFilter" :items="activityOptions"
          density="compact" variant="outlined" hide-details clearable
          placeholder="Активность" style="max-width:180px"
          @update:model-value="loadData" />
        <v-checkbox v-model="nonZeroOnly" label="Только ненулевые"
          density="compact" hide-details color="primary"
          @update:model-value="loadData" />
        <v-spacer />
        <v-btn variant="text" size="small" prepend-icon="mdi-filter-remove" @click="resetFilters">
          Очистить фильтры
        </v-btn>
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="qualifications-cols" />
      </div>
    </v-card>

    <v-data-table-server :items="filteredItems" :items-length="total" :loading="loading"
      :headers="visibleHeaders" :items-per-page="25" density="compact" hover
      @update:options="onOptions">

      <template #item.activity="{ value }">
        <v-chip size="x-small" :color="activityColor(value)" variant="tonal">{{ activityLabel(value) }}</v-chip>
      </template>

      <template #item.prev.level="{ item }">
        <span v-if="item.previous?.levelNum">
          {{ item.previous.levelNum }} {{ item.previous.levelTitle }}
        </span>
        <span v-else class="text-medium-emphasis">—</span>
      </template>
      <template #item.prev.lp="{ item }">{{ fmt(item.previous?.personalVolume) }}</template>
      <template #item.prev.gp="{ item }">{{ fmt(item.previous?.groupVolume) }}</template>
      <template #item.prev.op="{ item }">
        <span v-if="item.previous?.mandatoryGP">
          {{ fmt(item.previous.groupVolume) }} / {{ fmt(item.previous.mandatoryGP) }}
        </span>
        <span v-else class="text-medium-emphasis">—</span>
      </template>
      <template #item.prev.ngp="{ item }">{{ fmt(item.previous?.groupVolumeCumulative) }}</template>

      <template #item.cur.level="{ item }">
        <span v-if="item.current?.levelNum">
          {{ item.current.levelNum }} {{ item.current.levelTitle }}
        </span>
        <span v-else class="text-medium-emphasis">—</span>
      </template>
      <template #item.cur.lp="{ item }">{{ fmt(item.current?.personalVolume) }}</template>
      <template #item.cur.gp="{ item }">{{ fmt(item.current?.groupVolume) }}</template>
      <template #item.cur.op="{ item }">
        <span v-if="item.current?.mandatoryGP">
          {{ fmt(item.current.groupVolume) }} / {{ fmt(item.current.mandatoryGP) }}
        </span>
        <span v-else class="text-medium-emphasis">—</span>
      </template>
      <template #item.cur.ngp="{ item }">{{ fmt(item.current?.groupVolumeCumulative) }}</template>

      <template #item.actions="{ item }">
        <v-btn icon="mdi-history" size="x-small" variant="text" :title="'История ' + item.consultantName"
          @click="openHistory(item)" />
      </template>

      <template #no-data><EmptyState message="Нет данных" /></template>
    </v-data-table-server>

    <v-navigation-drawer v-model="historyOpen" location="right" temporary width="540">
      <v-card flat>
        <v-card-title>
          История квалификаций {{ historyContext?.consultantName || '' }}
        </v-card-title>
        <v-card-text>
          <v-data-table :items="historyRows" :headers="historyHeaders" density="compact" :items-per-page="50">
            <template #item.level="{ item }">
              <span v-if="item.levelNum">{{ item.levelNum }} {{ item.levelTitle }}</span>
              <span v-else class="text-medium-emphasis">—</span>
            </template>
            <template #no-data>
              <div class="text-medium-emphasis text-center pa-4">Нет записей</div>
            </template>
          </v-data-table>
        </v-card-text>
      </v-card>
    </v-navigation-drawer>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import { fmt2 as fmt } from '../../composables/useDesign';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const month = ref(new Date().toISOString().slice(0, 7));
const monthLabel = ref('');
const prevMonthLabel = ref('');
const qualFilter = ref(null);
const activityFilter = ref(null);
const nonZeroOnly = ref(false);
const page = ref(1);
const perPage = ref(25);
const defaultMonth = new Date().toISOString().slice(0, 7);

const qualOptions = [
  { title: '1 Start', value: 1 }, { title: '2 Pro', value: 2 },
  { title: '3 Expert', value: 3 }, { title: '4 FC', value: 4 },
  { title: '5 Master FC', value: 5 }, { title: '6 TOP FC', value: 6 },
  { title: '7 Silver DS', value: 7 }, { title: '8 Gold DS', value: 8 },
  { title: '9 Platinum DS', value: 9 }, { title: '10 Co-founder DS', value: 10 },
];

const activityOptions = [
  { title: 'Активный', value: 'active' },
  { title: 'Зарегистрирован', value: 'registered' },
  { title: 'Терминирован', value: 'terminated' },
  { title: 'Исключён', value: 'excluded' },
];

function activityColor(v) {
  return { active: 'success', registered: 'info', terminated: 'error', excluded: 'grey' }[v] || 'default';
}
function activityLabel(v) {
  return { active: 'Активный', registered: 'Зарегистрирован', terminated: 'Терминирован', excluded: 'Исключён' }[v] || v;
}

const headers = computed(() => ([
  { title: 'Партнёр', key: 'consultantName' },
  { title: 'Активность', key: 'activity', width: 130 },
  { title: `Кв. ${prevMonthLabel.value || 'пред.'}`, key: 'prev.level', width: 140 },
  { title: 'ЛП пред.', key: 'prev.lp', align: 'end', width: 90 },
  { title: 'ГП пред.', key: 'prev.gp', align: 'end', width: 90 },
  { title: 'ОП пред.', key: 'prev.op', align: 'end', width: 130 },
  { title: 'НГП пред.', key: 'prev.ngp', align: 'end', width: 110 },
  { title: `Кв. ${monthLabel.value || 'тек.'}`, key: 'cur.level', width: 140 },
  { title: 'ЛП', key: 'cur.lp', align: 'end', width: 90 },
  { title: 'ГП', key: 'cur.gp', align: 'end', width: 90 },
  { title: 'ОП', key: 'cur.op', align: 'end', width: 130 },
  { title: 'НГП', key: 'cur.ngp', align: 'end', width: 110 },
  { title: '', key: 'actions', sortable: false, width: 50 },
]));

const columnVisible = ref({});
const visibleHeaders = computed(() => headers.value.filter(h => columnVisible.value[h.key] !== false));

const filteredItems = computed(() => {
  let arr = items.value;
  // qualFilter оставляем client-side: квалификация хранится в logs, не на
  // consultant-таблице — фильтрация на server-side слишком дорого.
  // activityFilter теперь идёт на server (см. loadData) — пагинация и
  // total остаются консистентными.
  if (qualFilter.value) {
    arr = arr.filter(i => i.current?.levelNum === qualFilter.value || i.previous?.levelNum === qualFilter.value);
  }
  return arr;
});

function resetFilters() {
  search.value = '';
  month.value = defaultMonth;
  qualFilter.value = null;
  activityFilter.value = null;
  nonZeroOnly.value = false;
  loadData();
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
    const params = { page: page.value, per_page: perPage.value, month: month.value };
    if (search.value) params.search = search.value;
    if (activityFilter.value) params.activity = activityFilter.value;
    if (nonZeroOnly.value) params.non_zero_only = 1;
    const { data } = await api.get('/admin/qualifications', { params });
    items.value = data.data || [];
    total.value = data.total || 0;
    monthLabel.value = data.monthLabel || month.value;
    prevMonthLabel.value = data.prevMonthLabel || '';
  } catch {}
  loading.value = false;
}

const historyOpen = ref(false);
const historyContext = ref(null);
const historyRows = ref([]);
const historyHeaders = [
  { title: 'Месяц', key: 'date', width: 120 },
  { title: 'ЛП', key: 'personalVolume', align: 'end', width: 90 },
  { title: 'ГП', key: 'groupVolume', align: 'end', width: 90 },
  { title: 'НГП', key: 'groupVolumeCumulative', align: 'end', width: 110 },
  { title: 'Квалификация', key: 'level' },
];

async function openHistory(item) {
  historyContext.value = item;
  historyRows.value = [];
  historyOpen.value = true;
  try {
    const { data } = await api.get(`/admin/qualifications/history/${item.consultant}`);
    historyRows.value = data.data || [];
  } catch {}
}

onMounted(loadData);
</script>
