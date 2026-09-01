<template>
  <div>
    <PageHeader title="Квалификации" icon="mdi-chart-bar" :count="total">
      <template v-if="canCalc" #actions>
        <v-btn color="error" prepend-icon="mdi-cash-minus" :loading="recomputing"
          :title="`Расчёт удержаний по Отрыву (×0.5) и ОП (×0.8) + фиксация квалификаций за ${monthLabel || month}`"
          @click="recompute">Рассчитать удержания (отрыв + ОП)</v-btn>
      </template>
    </PageHeader>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <!-- Vuetify-меню с date-picker в режиме месяцев — нативный
             type="month" не открывается в Firefox и в части Chromium-сборок;
             меню работает одинаково везде. -->
        <v-menu v-model="monthMenu" :close-on-content-click="false"
          location="bottom start" offset="4">
          <template #activator="{ props }">
            <v-text-field v-bind="props" :model-value="monthLabel || month"
              placeholder="Месяц" readonly
              prepend-inner-icon="mdi-calendar"
              density="compact" variant="outlined" hide-details
              style="max-width: 180px; flex: 1 1 130px" />
          </template>
          <v-card>
            <v-date-picker :model-value="monthAsDate"
              view-mode="months" :show-adjacent-months="false"
              hide-header @update:model-value="onMonthPicked" />
          </v-card>
        </v-menu>
        <v-text-field v-model="search" placeholder="ФИО партнёра"
          density="compact" variant="outlined" hide-details rounded clearable
          prepend-inner-icon="mdi-magnify"
          style="max-width: 240px; flex: 1 1 200px"
          @update:model-value="debouncedLoad" />
        <v-select v-model="qualFilter" :items="qualOptions"
          density="compact" variant="outlined" hide-details clearable
          placeholder="Квалификация"
          style="max-width: 180px; flex: 1 1 140px"
          @update:model-value="loadData" />
        <v-select v-model="activityFilter" :items="activityOptions"
          density="compact" variant="outlined" hide-details clearable
          placeholder="Активность"
          style="max-width: 160px; flex: 1 1 120px"
          @update:model-value="loadData" />
        <v-checkbox v-model="nonZeroOnly" label="Ненулевые"
          density="compact" hide-details color="primary"
          style="flex: 0 0 auto"
          @update:model-value="loadData" />
        <v-spacer />
        <v-btn variant="text" size="small" prepend-icon="mdi-filter-remove" @click="resetFilters">
          Сбросить
        </v-btn>
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="qualifications-cols" />
      </div>
    </v-card>

    <v-data-table-server :items="filteredItems" :items-length="total" :loading="loading"
      :headers="visibleHeaders" :items-per-page="perPage"
      :items-per-page-options="[25, 50, 100, 200]" density="compact" hover
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

      <!-- Точка входа для присвоения — здесь, а не в карточке партнёра:
           месяц уже выбран фильтром страницы, а рядом видны ЛП/ГП/ОП/НГП
           за оба месяца, то есть всё основание для решения. -->
      <template #item.actions="{ item }">
        <div class="d-flex align-center">
          <v-btn icon="mdi-history" size="x-small" variant="text" :title="'История ' + item.consultantName"
            @click="openHistory(item)" />
          <v-btn v-if="isAdmin" icon="mdi-medal-outline" size="x-small" variant="text" color="primary"
            :title="'Присвоить квалификацию — ' + item.consultantName"
            @click="openAssign(item)" />
        </div>
      </template>

      <template #no-data><EmptyState message="Нет данных" /></template>
    </v-data-table-server>

    <v-navigation-drawer v-model="historyOpen" location="right" temporary width="540">
      <v-card flat>
        <v-card-title>
          История квалификаций {{ historyContext?.consultantName || '' }}
        </v-card-title>

        <!-- Вторая точка входа: оператор посмотрел помесячную динамику и
             решает менять уровень, не закрывая drawer. -->
        <v-card-text v-if="isAdmin" class="pb-0">
          <v-btn variant="tonal" color="primary" prepend-icon="mdi-medal-outline"
            @click="openAssign(historyContext)">Присвоить уровень</v-btn>
        </v-card-text>

        <v-card-text>
          <v-data-table :items="historyRows" :headers="historyHeaders" density="compact" :items-per-page="50">
            <template #item.level="{ item }">
              <div v-if="item.levelNum" class="d-flex flex-column ga-1 py-1">
                <div class="d-flex align-center ga-2 flex-wrap">
                  <span>{{ item.levelNum }} {{ item.levelTitle }}</span>
                  <!-- Ручное присвоение помечаем явно: иначе подменённый
                       уровень читается как результат расчёта, и расхождение
                       начинают искать в CommissionCalculator. -->
                  <v-chip v-if="item.manual" size="x-small" color="warning" variant="flat"
                    prepend-icon="mdi-alert-outline">вручную</v-chip>
                </div>
                <div v-if="item.manual && item.previousLevelNum" class="text-caption text-medium-emphasis">
                  было
                  <span class="text-decoration-line-through">{{ item.previousLevelNum }} {{ item.previousLevelTitle }}</span>
                  →
                  <span class="font-weight-medium">{{ item.levelNum }} {{ item.levelTitle }}</span>
                </div>
                <div v-if="item.manual && item.comment" class="text-caption text-medium-emphasis">
                  {{ item.comment }}
                </div>
              </div>
              <span v-else class="text-medium-emphasis">—</span>
            </template>
            <template #no-data>
              <div class="text-medium-emphasis text-center pa-4">Нет записей</div>
            </template>
          </v-data-table>
        </v-card-text>
      </v-card>
    </v-navigation-drawer>

    <!-- Ручное присвоение квалификации. Пишет открывающую строку
         qualificationLog за выбранный месяц — оттуда CommissionCalculator
         берёт ставку комиссии. Только admin, бэкенд дублирует гард. -->
    <v-dialog v-model="assignOpen" max-width="620" scrollable>
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="primary">mdi-medal-outline</v-icon>
          <span>Присвоить квалификацию</span>
        </v-card-title>

        <v-card-text>
          <div class="d-flex ga-4 flex-wrap mb-4">
            <v-text-field :model-value="assignForm.consultantName" label="Партнёр" readonly
              density="compact" variant="outlined" hide-details style="min-width:240px; flex:1 1 240px" />
            <v-text-field v-model="assignForm.month" label="Месяц" type="month"
              density="compact" variant="outlined" style="min-width:180px"
              hint="Подставлен из фильтра страницы" persistent-hint
              :error-messages="assignErrors.month" />
          </div>

          <v-select v-model="assignForm.level" :items="levelOptions" item-title="label" item-value="id"
            label="Квалификация" density="compact" variant="outlined"
            :error-messages="assignErrors.level" class="mb-4" />

          <v-textarea v-model="assignForm.comment" label="Комментарий" rows="3"
            density="compact" variant="outlined"
            hint="Попадёт в журнал аудита и в историю квалификаций" persistent-hint
            :error-messages="assignErrors.comment" class="mb-4" />

          <!-- Обязательное предупреждение: сервис возвращает recalcRequired,
               уже начисленные комиссии месяца остаются со старой ставкой. -->
          <v-alert type="warning" variant="tonal" density="comfortable" icon="mdi-alert-outline">
            <div class="font-weight-bold">Уже начисленные комиссии за месяц не пересчитываются</div>
            <div class="text-body-2">Новая ставка применится при следующем пересчёте — запустите его отдельно.</div>
          </v-alert>
        </v-card-text>

        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="assignOpen = false">Отмена</v-btn>
          <v-btn color="primary" :loading="assigning" @click="submitAssign">Присвоить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
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
import { usePermissions } from '../../composables/usePermissions';
import { useSnackbar } from '../../composables/useSnackbar';

// Пересчёт доступен только роли расчётов (calculations) / admin — как и
// прочие кнопки финализации (Транзакции/Пул/КарточкаПериода).
const { canCalc, userRoles } = usePermissions();
const { showSuccess, showError } = useSnackbar();
const recomputing = ref(false);

// Запускает финализацию за выбранный месяц (тот же /admin/finalize/apply,
// что и другие кнопки расчёта): пересчитывает объёмы и пишет снимок
// qualificationLog (ЛП/ГП/НГП/уровень). Гард historical-cutoff на бэке.
async function recompute() {
  const [y, m] = (month.value || '').split('-').map(Number);
  if (!y || !m) return;
  recomputing.value = true;
  try {
    const { data } = await api.post('/admin/finalize/apply', { year: y, month: m });
    showSuccess(data?.message || 'Пересчёт выполнен');
    await loadData();
  } catch (e) {
    const d = e.response?.data;
    showError(d?.frozen
      ? 'Период закрыт для пересчёта (исторические данные < 2026-06-01 неизменны).'
      : (d?.message || 'Не удалось пересчитать'));
  } finally {
    recomputing.value = false;
  }
}

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const month = ref(new Date().toISOString().slice(0, 7));
const monthLabel = ref('');
const prevMonthLabel = ref('');

const monthMenu = ref(false);
// JS Date для v-date-picker (день берём 1-й — режим months показывает сетку
// месяцев). Возвращается тоже Date — конвертируем обратно в 'YYYY-MM'.
const monthAsDate = computed(() => {
  const [y, m] = (month.value || '').split('-').map(Number);
  if (!y || !m) return new Date();
  return new Date(y, m - 1, 1);
});
function onMonthPicked(val) {
  if (!val) return;
  const d = val instanceof Date ? val : new Date(val);
  month.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
  monthMenu.value = false;
  loadData();
}
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
  // 72, а не 50: в колонке две иконки — история и ручное присвоение.
  { title: '', key: 'actions', sortable: false, width: 72 },
]));

const columnVisible = ref({});
const visibleHeaders = computed(() => headers.value.filter(h => columnVisible.value[h.key] !== false));

const filteredItems = computed(() => {
  let arr = items.value;
  // qualFilter оставляем client-side: квалификация хранится в logs, не на
  // consultant-таблице — фильтрация на server-side слишком дорого.
  // activityFilter теперь идёт на server (см. loadData) — пагинация и
  // total остаются консистентными.
  if (qualFilter.value != null) {
    const lvl = Number(qualFilter.value);
    arr = arr.filter(i =>
      Number(i.current?.levelNum) === lvl || Number(i.previous?.levelNum) === lvl
    );
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

// ── Ручное присвоение квалификации ──────────────────────────────────
// Роут на бэке в группе role:admin; здесь тот же гард, чтобы не
// показывать кнопку тем, кто получит 403.
const isAdmin = computed(() => userRoles.value.includes('admin'));

const assignOpen = ref(false);
const assigning = ref(false);
const assignErrors = ref({});
const assignForm = ref({ consultant: null, consultantName: '', month: '', level: null, comment: '' });

const levels = ref([]);
const levelOptions = computed(() => levels.value.map(l => ({
  id: l.id,
  // Процент в подписи обязателен: без него оператор не видит, на сколько
  // меняет ставку комиссии.
  label: `${l.level} ${l.title} — ${l.percent}%`,
})));

async function loadLevels() {
  if (levels.value.length) return;
  try {
    const { data } = await api.get('/status-levels');
    levels.value = Array.isArray(data) ? data : (data.data || []);
  } catch {
    showError('Не удалось загрузить список квалификаций');
  }
}

function openAssign(item) {
  if (!item) return;
  assignErrors.value = {};
  assignForm.value = {
    consultant: item.consultant,
    consultantName: item.consultantName || '',
    // Месяц берём из фильтра страницы — присвоение всегда привязано к
    // месяцу, и подставлять «сегодня» здесь было бы ловушкой.
    month: month.value,
    level: null,
    comment: '',
  };
  loadLevels();
  assignOpen.value = true;
}

async function submitAssign() {
  assigning.value = true;
  assignErrors.value = {};
  try {
    const { data } = await api.post(`/admin/qualifications/${assignForm.value.consultant}/assign`, {
      level: assignForm.value.level,
      month: assignForm.value.month,
      comment: assignForm.value.comment || null,
    });
    assignOpen.value = false;
    showSuccess(`Присвоено: ${data.level} ${data.title} (${data.percent}%) за ${data.month}`);
    await loadData();
    // Drawer открыт на этом же партнёре — обновляем, чтобы правка была
    // видна сразу, вместе с пометкой «вручную».
    if (historyOpen.value && historyContext.value?.consultant === assignForm.value.consultant) {
      await openHistory(historyContext.value);
    }
  } catch (e) {
    if (e.response?.status === 422 && e.response.data?.errors) {
      assignErrors.value = e.response.data.errors;
      showError('Проверьте поля формы');
    } else {
      showError(e.response?.data?.message || 'Не удалось присвоить квалификацию');
    }
  }
  assigning.value = false;
}

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
