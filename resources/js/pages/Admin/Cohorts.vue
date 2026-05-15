<template>
  <div>
    <PageHeader title="Когорты и retention" icon="mdi-chart-line" />

    <!-- Единый блок-описание над таблицей: коротко зачем + развёрнутое
         объяснение для нетехнических читателей. -->
    <v-card class="mb-3" variant="tonal" color="info">
      <v-card-title class="d-flex align-center ga-2 pa-3">
        <v-icon>mdi-help-circle-outline</v-icon>
        Что такое когорта и как читать эту таблицу
      </v-card-title>
      <v-card-text class="pb-4">
        <p class="mb-3">
          Партнёры сгруппированы по месяцу регистрации. Видно, сколько из каждой когорты
          сейчас активно и сколько терминировано.
        </p>
        <p class="mb-3">
          <strong>Когорта</strong> — это группа партнёров, которые подключились в один и тот же месяц.
          Например, «когорта апреля 2026» — все, кто зарегистрировался в апреле 2026 года.
          Когорты помогают понять, какая доля партнёров остаётся в работе через 1, 3, 6 или 12 месяцев — это и есть «retention» (удержание).
        </p>

        <div class="text-subtitle-2 font-weight-bold mb-2">Колонки</div>
        <ul class="mb-3 ps-4">
          <li class="mb-1"><strong>Месяц регистрации</strong> — месяц, когда партнёры из когорты пришли в компанию.</li>
          <li class="mb-1"><strong>Размер когорты</strong> — сколько всего человек зарегистрировалось в этом месяце.</li>
          <li class="mb-1"><strong>Активно сейчас</strong> — сколько из них до сих пор в статусе «Активен».</li>
          <li class="mb-1"><strong>Терминировано</strong> — сколько было исключено по решению компании (терминированы за нарушения, неактивность и т.&nbsp;п.).</li>
          <li class="mb-1">
            <strong>Retention</strong> — процент удержания: «Активно сейчас» ÷ «Размер когорты».
            Чем выше — тем лучше когорта прижилась.
          </li>
          <li class="mb-1">
            <strong>Отвалилось</strong> — доля терминированных от размера когорты. Чем ниже — тем лучше.
          </li>
        </ul>

        <div class="text-subtitle-2 font-weight-bold mb-2">Цвета</div>
        <ul class="mb-3 ps-4">
          <li class="mb-1"><span class="text-success font-weight-bold">Зелёный retention (70%+)</span> — когорта здоровая, большинство партнёров в работе.</li>
          <li class="mb-1"><span class="text-warning">Жёлтый (40–70%)</span> — половина выбыла, повод разбираться.</li>
          <li class="mb-1"><span class="text-error font-weight-bold">Красный (&lt;40%)</span> — критично, в когорте что-то пошло не так (плохой набор, слабое сопровождение, проблемный месяц).</li>
        </ul>

        <div class="text-subtitle-2 font-weight-bold mb-2">Как пользоваться</div>
        <ul class="ps-4">
          <li class="mb-1">
            <strong>Свежие когорты (последние 1–2 месяца)</strong> обычно показывают 100% — партнёры просто не успели уйти. Это нормально.
          </li>
          <li class="mb-1">
            <strong>Когорты 3–6 месяцев</strong> — самый показательный диапазон. Если retention высокий — значит модель сопровождения работает.
          </li>
          <li class="mb-1">
            <strong>Сравнение между когортами</strong> помогает увидеть, улучшается или ухудшается удержание со временем. Если из месяца в месяц retention падает — пора менять подход к онбордингу.
          </li>
        </ul>
      </v-card-text>
    </v-card>

    <v-card>
      <div class="d-flex justify-end px-3 pt-2 pb-2">
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="cohorts-cols" />
      </div>
      <v-data-table :items="rows" :headers="visibleHeaders" density="comfortable" hover>
        <template #item.cohort_month="{ value }">{{ formatMonth(value) }}</template>
        <template #item.retention_pct="{ item }">
          <span :class="retentionClass(item)">{{ retention(item) }}%</span>
        </template>
        <template #item.termination_pct="{ item }">
          <span :class="terminationClass(item)">{{ termination(item) }}%</span>
        </template>
        <template #no-data>
          <EmptyState message="Нет данных за последние 12 месяцев" icon="mdi-chart-bell-curve" />
        </template>
      </v-data-table>
    </v-card>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, ColumnVisibilityMenu, EmptyState } from '../../components';

const rows = ref([]);

const headers = [
  { title: 'Месяц регистрации', key: 'cohort_month', width: 180 },
  { title: 'Размер когорты', key: 'cohort_size', align: 'end', width: 140 },
  { title: 'Активно сейчас', key: 'active_now', align: 'end', width: 140 },
  { title: 'Терминировано', key: 'terminated', align: 'end', width: 140 },
  { title: 'Retention', key: 'retention_pct', align: 'end', width: 120 },
  { title: 'Отвалилось', key: 'termination_pct', align: 'end', width: 120 },
];

const columnVisible = ref({});
const visibleHeaders = computed(() => headers.filter(h => columnVisible.value[h.key] !== false));

function formatMonth(v) {
  if (!v) return '';
  const d = new Date(v);
  return d.toLocaleDateString('ru-RU', { year: 'numeric', month: 'long' });
}
function retention(r) { return r.cohort_size ? Math.round(r.active_now * 100 / r.cohort_size) : 0; }
function termination(r) { return r.cohort_size ? Math.round(r.terminated * 100 / r.cohort_size) : 0; }
function retentionClass(r) {
  const v = retention(r);
  if (v >= 70) return 'text-success font-weight-bold';
  if (v >= 40) return 'text-warning';
  return 'text-error';
}
function terminationClass(r) {
  const v = termination(r);
  if (v >= 30) return 'text-error font-weight-bold';
  if (v >= 10) return 'text-warning';
  return 'text-medium-emphasis';
}

async function load() {
  try {
    const { data } = await api.get('/admin/analytics/cohorts');
    rows.value = data.cohorts || [];
  } catch {}
}
onMounted(load);
</script>
