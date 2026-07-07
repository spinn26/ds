<template>
  <div>
    <PageHeader title="Скрытые клиенты с живыми контрактами" icon="mdi-account-off">
      <template #actions>
        <v-btn variant="text" prepend-icon="mdi-refresh" :loading="loading" @click="load">Обновить</v-btn>
      </template>
    </PageHeader>

    <!-- Почему так: контекст и логика разбора -->
    <v-card variant="tonal" color="info" class="mb-4">
      <v-card-text class="text-body-2">
        <div class="d-flex align-center mb-2">
          <v-icon size="20" class="me-2">mdi-information-outline</v-icon>
          <span class="text-subtitle-2 font-weight-bold">Что это за раздел и почему так</span>
          <v-spacer />
          <v-btn variant="text" size="small" @click="showWhy = !showWhy">
            {{ showWhy ? 'Свернуть' : 'Подробнее' }}
            <v-icon end>{{ showWhy ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
          </v-btn>
        </div>
        <p class="mb-1">
          Здесь — клиенты, которые <b>помечены удалёнными</b> (soft-delete: запись цела в базе,
          но скрыта из всех списков), при этом на них всё ещё числятся <b>действующие контракты</b>.
          Такие контракты работают (ФИО клиента продублировано прямо в контракте, комиссия идёт по
          консультанту), но при переходе «контракт → карточка клиента» клиент не находится.
        </p>
        <v-expand-transition>
          <div v-show="showWhy">
            <p class="mb-1 mt-2">
              Это <b>не дубли</b> и не следствие чистки — «хвост» качества данных после консолидации.
              Скрывали клиентов в разное время (2024–2026), большинство — осознанно. Поэтому раздел
              read-only: возвращать вслепую нельзя, решение по каждой записи — за оператором.
            </p>
            <div class="text-subtitle-2 font-weight-bold mt-3 mb-1">Как читать категории</div>
            <ul class="ps-4 mb-2">
              <li><b>внутр.</b> — служебные записи (Сидоров/Тарасенко, «сам себе клиент»). Скрыты намеренно (Сидоров исключён из реестра выплат). <b>Не трогать.</b></li>
              <li><b>тест/мусор</b> — «Тест», «gggg», «ГЕТКУРС ТЕСТ», пустые ФИО, абракадабра. Скрыты правильно, оставить.</li>
              <li><b>на&nbsp;проверку</b> — нормальное ФИО с реальным консультантом. Решение: разскрыть (если скрыт по ошибке) или перепривязать контракт на верного клиента.</li>
              <li><b>двойник</b> — на того же человека есть живая запись → контракт просто перепривязать на неё.</li>
            </ul>
            <div class="text-subtitle-2 font-weight-bold mt-2 mb-1">Флаги</div>
            <ul class="ps-4 mb-0">
              <li><v-icon size="16" color="warning">mdi-calendar-alert</v-icon> <b>дата ↯</b> — контракт создан <b>позже</b>, чем клиента скрыли. Значит, скорее всего сам контракт привязан не к тому клиенту (ошибка привязки), а не клиента зря удалили → перепривязывать, а не разскрывать.</li>
              <li><v-icon size="16" color="info">mdi-account-arrow-right</v-icon> <b>есть живой двойник</b> — контракт можно перенести на действующую запись.</li>
            </ul>
          </div>
        </v-expand-transition>
      </v-card-text>
    </v-card>

    <!-- Сводка -->
    <v-row dense class="mb-3">
      <v-col v-for="t in tiles" :key="t.key" cols="6" sm="4" md="2">
        <v-card :color="t.color" variant="tonal" class="pa-3">
          <div class="text-caption text-medium-emphasis">{{ t.label }}</div>
          <div class="text-h5 font-weight-bold tabnum">{{ t.value }}</div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Фильтр по категории -->
    <v-chip-group v-model="filter" selected-class="text-primary" class="mb-2" mandatory>
      <v-chip value="all" size="small" variant="outlined">Все · {{ items.length }}</v-chip>
      <v-chip value="review" size="small" variant="outlined" color="warning">На проверку · {{ countBy('review') }}</v-chip>
      <v-chip value="repoint" size="small" variant="outlined" color="info">Двойник · {{ countBy('repoint') }}</v-chip>
      <v-chip value="misattached" size="small" variant="outlined" color="warning">Дата&nbsp;↯ · {{ misattachedCount }}</v-chip>
      <v-chip value="internal" size="small" variant="outlined">Внутр. · {{ countBy('internal') }}</v-chip>
      <v-chip value="test" size="small" variant="outlined">Тест/мусор · {{ countBy('test') }}</v-chip>
    </v-chip-group>

    <v-card>
      <v-data-table
        :items="filtered"
        :headers="headers"
        :loading="loading"
        density="comfortable"
        :items-per-page="25"
        :sort-by="[{ key: 'contracts', order: 'desc' }]">
        <template #item.name="{ item }">
          <span class="font-weight-medium">{{ item.name }}</span>
          <v-tooltip v-if="item.misattached" text="Контракт создан позже, чем клиента скрыли — вероятно ошибка привязки">
            <template #activator="{ props }">
              <v-icon v-bind="props" size="16" color="warning" class="ms-1">mdi-calendar-alert</v-icon>
            </template>
          </v-tooltip>
          <v-tooltip v-if="item.hasLiveTwin" text="Есть живая запись того же человека">
            <template #activator="{ props }">
              <v-icon v-bind="props" size="16" color="info" class="ms-1">mdi-account-arrow-right</v-icon>
            </template>
          </v-tooltip>
        </template>
        <template #item.contracts="{ value }">
          <span class="tabnum">{{ value }}</span>
        </template>
        <template #item.deleted="{ value }">{{ fmtDate(value) }}</template>
        <template #item.span="{ item }">
          <span class="text-caption">{{ dateSpan(item) }}</span>
        </template>
        <template #item.category="{ value }">
          <v-chip :color="catColor(value)" size="x-small" variant="tonal" label>{{ catLabel(value) }}</v-chip>
        </template>
        <template #no-data>
          <div class="pa-6 text-center text-medium-emphasis">Скрытых клиентов с живыми контрактами нет.</div>
        </template>
      </v-data-table>
    </v-card>

    <p class="text-caption text-medium-emphasis mt-3">
      Только чтение. Классификация («тест / внутр.») — эвристика по ФИО и владельцу, требует сверки.
      Действия (разскрыть / перепривязать) выполняются отдельно после подтверждения.
    </p>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { PageHeader } from '../../components';
import { fmtDate } from '../../composables/useDesign';
import { useSnackbar } from '../../composables/useSnackbar';

const { showError } = useSnackbar();
const loading = ref(false);
const showWhy = ref(false);
const filter = ref('all');
const summary = ref({});
const items = ref([]);

const CAT = {
  internal: { label: 'внутр.', color: 'grey' },
  test:     { label: 'тест/мусор', color: 'grey' },
  review:   { label: 'на проверку', color: 'warning' },
  repoint:  { label: 'двойник', color: 'info' },
};
const catLabel = (c) => CAT[c]?.label ?? c;
const catColor = (c) => CAT[c]?.color ?? 'grey';

const headers = [
  { title: 'ID', key: 'id', width: 90 },
  { title: 'Клиент', key: 'name' },
  { title: 'Владелец (консультант)', key: 'owner' },
  { title: 'Контр.', key: 'contracts', align: 'end', width: 90 },
  { title: 'Скрыт', key: 'deleted', width: 130 },
  { title: 'Даты контрактов', key: 'span', sortable: false },
  { title: 'Тип', key: 'category', width: 130 },
];

const tiles = computed(() => {
  const s = summary.value || {};
  return [
    { key: 'contracts', label: 'Контрактов-«сирот»', value: s.contracts ?? 0, color: 'primary' },
    { key: 'clients', label: 'Скрытых клиентов', value: s.clients ?? 0, color: 'surface' },
    { key: 'internal', label: 'Внутренние', value: s.internal ?? 0, color: 'surface' },
    { key: 'test', label: 'Тест/мусор', value: s.test ?? 0, color: 'surface' },
    { key: 'review', label: 'На проверку', value: s.review ?? 0, color: 'warning' },
    { key: 'mis', label: 'Дата ↯', value: s.misattached ?? 0, color: 'warning' },
  ];
});

const misattachedCount = computed(() => items.value.filter((i) => i.misattached).length);
const countBy = (c) => items.value.filter((i) => i.category === c).length;

const filtered = computed(() => {
  if (filter.value === 'all') return items.value;
  if (filter.value === 'misattached') return items.value.filter((i) => i.misattached);
  return items.value.filter((i) => i.category === filter.value);
});

function dateSpan(i) {
  if (!i.firstContract) return '—';
  return i.firstContract === i.lastContract
    ? fmtDate(i.firstContract)
    : `${fmtDate(i.firstContract)} … ${fmtDate(i.lastContract)}`;
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/hidden-clients');
    summary.value = data.summary || {};
    items.value = data.items || [];
  } catch (e) {
    showError('Не удалось загрузить список скрытых клиентов');
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.tabnum { font-variant-numeric: tabular-nums; }
</style>
