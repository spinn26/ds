<template>
  <div>
    <PageHeader title="Статусы партнёров" icon="mdi-calendar-clock" />

    <!-- Summary cards: compact horizontal strip. Tap a tile to filter. -->
    <v-row class="mb-3" dense>
      <v-col v-for="s in summary" :key="s.id" cols="6" sm="3">
        <v-card
          class="pa-3 d-flex align-center ga-3 summary-tile ds-hover-lift"
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

    <!-- Компактный фильтр-бар: основное (поиск + статус) в одной строке.
         Все 4 диапазона дат — за тогглом «Диапазоны дат», каждый диапазон
         как одно поле «с — по» с общей подписью сверху. Убрали FilterBar
         (он завязан на comfortable density и не даёт нужный layout). -->
    <v-card class="mb-4 pa-3">
      <div class="d-flex flex-wrap ga-2 align-center">
        <v-text-field v-model="search" placeholder="ФИО партнёра…"
          density="compact" variant="outlined" hide-details clearable
          prepend-inner-icon="mdi-magnify" style="max-width: 280px; flex: 1 1 200px"
          @update:model-value="debouncedLoad" />
        <v-select v-model="activityFilter" :items="activityOptions" placeholder="Статус"
          density="compact" variant="outlined" hide-details clearable
          style="max-width: 220px; flex: 1 1 180px"
          @update:model-value="loadData" />

        <v-spacer />

        <v-btn :variant="showAdvancedDates ? 'tonal' : 'text'" size="small"
          :prepend-icon="showAdvancedDates ? 'mdi-chevron-up' : 'mdi-calendar-range'"
          @click="showAdvancedDates = !showAdvancedDates">
          Диапазоны дат
          <v-chip v-if="advancedDatesActiveCount > 0" size="x-small" color="info"
            variant="elevated" class="ms-1">{{ advancedDatesActiveCount }}</v-chip>
        </v-btn>
        <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
        </v-chip>
        <v-btn v-if="activeFilterCount > 0" variant="text" size="small" color="secondary"
          prepend-icon="mdi-filter-off-outline" @click="resetFilters">Сбросить</v-btn>
        <v-btn size="small" variant="tonal" color="success" prepend-icon="mdi-microsoft-excel"
          :loading="exporting" @click="exportEmails" title="Выгрузить в Excel (с учётом фильтров)">
          Экспорт
        </v-btn>
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible"
          storage-key="partner-statuses-cols" />
      </div>

      <!-- 4 диапазона дат per spec ✅Статусы партнеров §1, через
           SmartRangeFilter — общий компонент с переключателем режима
           (Диапазон / Дата / Месяц / Квартал / Год). -->
      <v-expand-transition>
        <div v-show="showAdvancedDates" class="d-flex flex-wrap ga-3 mt-3">
          <SmartRangeFilter label="Регистрация" kind="date"
            v-model:from="dateFilters.created_from"
            v-model:to="dateFilters.created_to"
            @update:from="loadData" @update:to="loadData" />
          <SmartRangeFilter label="Активность" kind="date"
            v-model:from="dateFilters.activity_from"
            v-model:to="dateFilters.activity_to"
            @update:from="loadData" @update:to="loadData" />
          <SmartRangeFilter label="План. терминация" kind="date"
            v-model:from="dateFilters.plan_from"
            v-model:to="dateFilters.plan_to"
            @update:from="loadData" @update:to="loadData" />
          <SmartRangeFilter label="Факт. терминация" kind="date"
            v-model:from="dateFilters.term_from"
            v-model:to="dateFilters.term_to"
            @update:from="loadData" @update:to="loadData" />
        </div>
      </v-expand-transition>
    </v-card>

    <!-- Detail table -->
    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="visibleHeaders" :items-per-page="perPage"
      :items-per-page-options="[25, 50, 100, 200]" @update:options="onOptions">
      <template #item.activityName="{ item }">
        <StatusChip :value="item.activityId" kind="activity" size="x-small" :text="item.activityName" />
      </template>
      <template #item.dateActivity="{ value }">
        {{ fmtDate(value) }}
      </template>
      <template #item.dateCreated="{ value }">
        {{ value ? fmtDate(value) : '—' }}
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
      <template #item.lpFromActivation="{ value }">
        {{ fmt(value) }}
      </template>
      <template #item.terminationCount="{ value }">
        <v-chip v-if="value > 0" size="x-small" :color="value >= 3 ? 'error' : 'warning'">{{ value }}/3</v-chip>
        <span v-else>—</span>
      </template>
      <template #item.actions="{ item }">
        <v-btn icon="mdi-pencil" size="x-small" variant="text" title="Управление статусом"
          @click="openOverride(item)" />
      </template>
      <template #no-data><EmptyState /></template>
    </v-data-table-server>

    <!-- Модалка «Управление статусом» (ручной override) -->
    <v-dialog v-model="overrideOpen" max-width="540" persistent>
      <v-card v-if="overrideTarget">
        <v-card-title>Управление статусом — {{ overrideTarget.personName }}</v-card-title>
        <v-card-text>
          <v-alert type="warning" variant="tonal" density="compact" class="mb-3" icon="mdi-shield-alert">
            Ручной override обходит бизнес-правила и пишется в аудит-лог.
            Используется для корректировок задним числом или индивидуальных решений руководства.
          </v-alert>

          <v-select v-model="overrideForm.activity" :items="activityChoices" label="Новый статус *"
            variant="outlined" density="comfortable" class="mb-3" />
          <v-text-field v-model="overrideForm.date" type="date" label="Фактическая дата *"
            variant="outlined" density="comfortable" class="mb-3"
            hint="Можно задним числом или будущим" persistent-hint />
          <v-textarea v-model="overrideForm.comment" label="Комментарий *"
            variant="outlined" density="comfortable" rows="3"
            placeholder="Например: «Перенос даты терминации на 1-е по согласованию с фин. директором»"
            :error-messages="overrideError" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="overrideOpen = false">Отмена</v-btn>
          <v-btn color="warning" :loading="overrideSaving"
            :disabled="!overrideForm.activity || !overrideForm.date || (overrideForm.comment?.length || 0) < 3"
            @click="saveOverride">
            Сохранить изменения
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="4000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import { useTableSort } from '../../composables/useTableSort';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import StatusChip from '../../components/StatusChip.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import SmartRangeFilter from '../../components/SmartRangeFilter.vue';
import { fmt, fmtDate, getActivityColor } from '../../composables/useDesign';
import { exportToXlsx } from '../../composables/useExport';
import { useSnackbar } from '../../composables/useSnackbar';

const { showSuccess, showError } = useSnackbar();
const exporting = ref(false);
const loading = ref(true);
const summary = ref([]);
const items = ref([]);
const total = ref(0);
const page = ref(1);
const perPage = ref(25);
const search = ref('');
const activityFilter = ref(null);
const showAdvancedDates = ref(false);
const dateFilters = ref({
  created_from: '', created_to: '',
  activity_from: '', activity_to: '',
  plan_from: '', plan_to: '',
  term_from: '', term_to: '',
});

const activeFilterCount = computed(() => {
  let c = 0;
  if (search.value) c++;
  if (activityFilter.value) c++;
  Object.values(dateFilters.value).forEach(v => { if (v) c++; });
  return c;
});

// Сколько активных диапазонов спрятано в «Диапазоны дат» — чип-счётчик
// на тогле, чтобы оператор не пропустил активный фильтр после сворачивания.
const advancedDatesActiveCount = computed(() => {
  let c = 0;
  Object.values(dateFilters.value).forEach(v => { if (v) c++; });
  return c;
});

function resetFilters() {
  search.value = '';
  activityFilter.value = null;
  dateFilters.value = {
    created_from: '', created_to: '',
    activity_from: '', activity_to: '',
    plan_from: '', plan_to: '',
    term_from: '', term_to: '',
  };
  loadData();
}

const activityOptions = computed(() =>
  summary.value.map(s => ({ title: `${s.name} (${s.count})`, value: s.id }))
);

const headers = [
  { title: 'Партнёр', key: 'personName' },
  { title: 'Email', key: 'email', width: 220 },
  { title: 'Статус', key: 'activityName', width: 150 },
  { title: 'Зарегистрирован', key: 'dateCreated', width: 140 },
  { title: 'Активен с', key: 'dateActivity', width: 130 },
  { title: 'Будет терминирован', key: 'willTerminate', width: 170 },
  { title: 'Терминирован', key: 'dateDeterministic', width: 140 },
  { title: 'ЛП от активации', key: 'lpFromActivation', align: 'end', width: 150 },
  { title: 'Терминаций', key: 'terminationCount', width: 110 },
  { title: '', key: 'actions', sortable: false, width: 50 },
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

// === Manual override modal ===
const overrideOpen = ref(false);
const overrideTarget = ref(null);
const overrideSaving = ref(false);
const overrideError = ref('');
const overrideForm = ref({ activity: null, date: '', comment: '' });

const activityChoices = [
  { title: 'Зарегистрирован', value: 4 },
  { title: 'Активен', value: 1 },
  { title: 'Терминирован', value: 3 },
  { title: 'Исключён', value: 5 },
];

const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

function openOverride(item) {
  overrideTarget.value = item;
  overrideForm.value = {
    activity: item.activityId,
    date: (item.dateActivity || item.dateCreated || new Date().toISOString()).slice(0, 10),
    comment: '',
  };
  overrideError.value = '';
  overrideOpen.value = true;
}

async function saveOverride() {
  if (!overrideTarget.value) return;
  overrideSaving.value = true;
  overrideError.value = '';
  try {
    await api.post(`/admin/partners/${overrideTarget.value.id}/status-override`, overrideForm.value);
    overrideOpen.value = false;
    await loadData();
    notify('Статус обновлён, изменение в аудит-логе');
  } catch (e) {
    overrideError.value = e.response?.data?.message || 'Ошибка сохранения';
    notify(overrideError.value, 'error');
  }
  overrideSaving.value = false;
}

const { debounced: debouncedLoad } = useDebounce(loadData, 400);
const { applyOptions, applyParams } = useTableSort('personName', 'asc');

function onOptions(opts) {
  page.value = opts.page;
  if (opts.itemsPerPage) perPage.value = opts.itemsPerPage;
  applyOptions(opts);
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value };
    applyParams(params);
    if (search.value) params.search = search.value;
    if (activityFilter.value) params.activity = activityFilter.value;
    Object.entries(dateFilters.value).forEach(([k, v]) => {
      if (v) params[k] = v;
    });
    const { data } = await api.get('/admin/partner-statuses', { params });
    summary.value = data.summary || [];
    items.value = data.data || [];
    total.value = data.total || 0;
  } catch {}
  loading.value = false;
}

// Выгрузка ВСЕХ партнёров (с учётом текущих фильтров) в Excel — постранично,
// т.к. бэкенд ограничивает per_page. Колонки: ФИО, Email, статус, даты.
async function exportEmails() {
  exporting.value = true;
  try {
    const base = {};
    base.sort_by = 'personName';
    base.sort_dir = 'asc';
    if (search.value) base.search = search.value;
    if (activityFilter.value) base.activity = activityFilter.value;
    Object.entries(dateFilters.value).forEach(([k, v]) => { if (v) base[k] = v; });

    const all = [];
    let p = 1;
    let lastPage = 1;
    do {
      const { data } = await api.get('/admin/partner-statuses', {
        params: { ...base, page: p, per_page: 100 },
      });
      all.push(...(data.data || []));
      lastPage = Math.max(1, Math.ceil((data.total || 0) / 100));
      p++;
    } while (p <= lastPage && p <= 500);

    if (!all.length) { showError('Нет данных для выгрузки'); return; }

    await exportToXlsx(all, [
      { title: 'Партнёр', key: 'personName' },
      { title: 'Email', key: 'email' },
      { title: 'Статус', key: 'activityName' },
      { title: 'Зарегистрирован', key: 'dateCreated' },
      { title: 'Активен с', key: 'dateActivity' },
    ], 'partner_statuses');
    showSuccess(`Выгружено партнёров: ${all.length}`);
  } catch (e) {
    showError('Ошибка выгрузки');
  } finally {
    exporting.value = false;
  }
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
/* Компактный диапазон дат: общая подпись сверху + два узких инпута
   «с»/«по» в одну строку. Без floating-label на полях type=date —
   на узких экранах (Mac Air ~1366px) лейблы режут outlined-рамку. */
.filter-range {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 240px;
}
.filter-range :deep(.v-field) {
  min-width: 110px;
}
</style>
