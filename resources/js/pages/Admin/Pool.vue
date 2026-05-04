<template>
  <div>
    <PageHeader title="Комиссии пула" icon="mdi-cash-multiple" />

    <!-- 1.1 Фильтры -->
    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по ФИО"
          density="compact" variant="outlined" hide-details rounded clearable
          prepend-inner-icon="mdi-magnify" style="max-width:300px" />
        <v-text-field v-model="month" type="month" label="Отчётный месяц"
          density="compact" variant="outlined" hide-details style="max-width:200px"
          @update:model-value="loadParticipants" />
        <v-spacer />
        <v-btn variant="text" size="small" prepend-icon="mdi-filter-remove" @click="resetFilters">
          Очистить фильтры
        </v-btn>
      </div>
    </v-card>

    <!-- 1.2 Список модерации -->
    <v-card class="mb-3">
      <v-card-title class="text-subtitle-1 d-flex align-center ga-2">
        <v-icon size="20">mdi-account-multiple-check</v-icon>
        Участники пула на {{ monthLabel }}
        <v-chip v-if="filteredParticipants.length" size="x-small" color="primary" variant="tonal">
          {{ filteredParticipants.length }}
        </v-chip>
        <v-spacer />
        <ColumnVisibilityMenu
          :headers="participantHeaders"
          v-model:visible="participantColumnVisible"
          storage-key="pool-participants-cols" />
      </v-card-title>

      <v-data-table :items="filteredParticipants" :headers="visibleParticipantHeaders"
        :items-per-page="50" density="compact" hover :loading="loadingParticipants">
        <template #item.participates="{ item }">
          <v-checkbox :model-value="item.participates" hide-details density="compact" color="success"
            :loading="toggling[item.id]"
            @update:model-value="v => toggleParticipates(item, v)" />
        </template>
        <template #item.level="{ item }">
          <v-chip size="x-small" :color="levelColor(item.level)" variant="tonal">
            {{ item.level }} {{ item.levelName }}
          </v-chip>
        </template>
        <template #item.eligibility="{ item }">
          <v-chip v-if="item.eligible !== false" size="x-small" color="success" variant="tonal" prepend-icon="mdi-check">
            ОК
          </v-chip>
          <v-tooltip v-else location="top" :text="item.disqualifyReason || 'Исключён'">
            <template #activator="{ props }">
              <v-chip v-bind="props" size="x-small" color="error" variant="tonal" prepend-icon="mdi-alert">
                {{ item.disqualifyReason || 'Исключён' }}
              </v-chip>
            </template>
          </v-tooltip>
        </template>
        <template #no-data>
          <EmptyState message="Нет партнёров уровня 6+ за этот месяц" />
        </template>
      </v-data-table>

      <v-card-actions>
        <v-btn color="success" prepend-icon="mdi-account-multiple-plus" size="large"
          :disabled="!filteredParticipants.length" :loading="calcing"
          @click="calcPool">
          Рассчитать пул
        </v-btn>
        <v-btn v-if="result" color="primary" prepend-icon="mdi-content-save" size="large" variant="outlined"
          :loading="applying" @click="applyPool">
          Применить (записать в poolLog)
        </v-btn>
        <v-spacer />
        <span v-if="result" class="text-caption text-medium-emphasis">
          Выручка ДС без НДС: <strong>{{ fmt2(result.revenue) }} ₽</strong>
          · Фонд на уровень: <strong>{{ fmt2(result.fund) }} ₽</strong>
          · К выплате: <strong class="text-success">{{ fmt2(result.totalPaid) }} ₽</strong>
          · Остаётся ДС: <strong class="text-warning">{{ fmt2(result.totalForfeited) }} ₽</strong>
        </span>
      </v-card-actions>
    </v-card>

    <!-- 1.4 Таблица начислений (всегда видна — auto-preview после загрузки) -->
    <v-card>
      <v-card-title class="text-subtitle-1">
        <v-icon size="20" class="mr-1">mdi-calculator</v-icon>
        Начисления пула
        <v-chip v-if="result" size="x-small" color="info" variant="tonal" class="ml-2">
          preview
        </v-chip>
      </v-card-title>

      <div v-if="!result && !calcing" class="pa-6 text-center text-medium-emphasis">
        <v-icon size="48" color="grey">mdi-calculator-variant-outline</v-icon>
        <div class="mt-2">Нажмите «Рассчитать пул», чтобы увидеть детализацию.</div>
      </div>

      <div v-else-if="!result" class="pa-6 text-center">
        <v-progress-circular indeterminate color="primary" />
      </div>

      <v-table v-if="result" density="compact" class="pool-results">
        <thead>
          <tr>
            <th>Период</th>
            <th>Партнёр</th>
            <th>Квалификация</th>
            <th class="text-end">Групповой бонус</th>
            <th v-for="lvl in [6,7,8,9,10]" :key="lvl" class="text-end">{{ lvl }} кв.</th>
            <th class="text-end">Комиссия пула</th>
          </tr>
        </thead>
        <tbody>
          <!-- Итого по уровням -->
          <tr class="pool-total-row">
            <td colspan="3" class="font-weight-bold text-success">ИТОГО (фонд × #голов)</td>
            <td class="text-end">—</td>
            <td v-for="lvl in [6,7,8,9,10]" :key="'tot-'+lvl" class="text-end font-weight-bold text-success">
              {{ fmt2(result.shareValues?.[lvl] || 0) }}
            </td>
            <td class="text-end font-weight-bold text-success">{{ fmt2(result.totalPaid) }}</td>
          </tr>

          <tr v-for="p in payoutRows" :key="'pay-' + p.id">
            <td>{{ monthLabel }}</td>
            <td>{{ p.personName }}</td>
            <td>
              <v-chip size="x-small" :color="levelColor(p.level)" variant="tonal">
                {{ p.level }} {{ p.levelName }}
              </v-chip>
            </td>
            <td class="text-end text-medium-emphasis">{{ fmt2(p.groupBonusRub || 0) }}</td>
            <td v-for="lvl in [6,7,8,9,10]" :key="'r-' + p.id + '-' + lvl" class="text-end">
              <span :class="p.byLevel[lvl] > 0 ? 'text-success' : 'text-medium-emphasis'">
                {{ fmt2(p.byLevel[lvl] || 0) }}
              </span>
            </td>
            <td class="text-end font-weight-bold">{{ fmt2(p.payoutRub) }}</td>
          </tr>

          <tr v-if="!payoutRows.length">
            <td :colspan="9" class="text-center text-medium-emphasis pa-4">
              Никому не начислено (никто не подтвердил квалификацию или все исключены оператором)
            </td>
          </tr>
        </tbody>
      </v-table>
    </v-card>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="4000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import { fmt2 } from '../../composables/useDesign';
import { useConfirm } from '../../composables/useConfirm';

const confirm = useConfirm();

const month = ref(new Date().toISOString().slice(0, 7));
const defaultMonth = month.value;
const search = ref('');
const participants = ref([]);
const result = ref(null);
const loadingParticipants = ref(false);
const calcing = ref(false);
const applying = ref(false);
const toggling = ref({});

const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

const monthLabel = computed(() => {
  if (!month.value) return '';
  const [y, m] = month.value.split('-');
  return `${m}.${y}`;
});

const participantHeaders = [
  { title: 'Участвует', key: 'participates', width: 110, sortable: false },
  { title: 'Партнёр', key: 'personName' },
  { title: 'Квалификация', key: 'level', width: 200 },
  { title: 'Условие выплаты', key: 'eligibility', width: 220, sortable: false },
];

const participantColumnVisible = ref({});
const visibleParticipantHeaders = computed(() =>
  participantHeaders.filter(h => participantColumnVisible.value[h.key] !== false)
);

function levelColor(lvl) {
  const map = { 6: 'amber-darken-1', 7: 'grey', 8: 'orange', 9: 'blue', 10: 'purple' };
  return map[lvl] || 'default';
}

const filteredParticipants = computed(() => {
  if (!search.value) return participants.value;
  const term = search.value.toLowerCase();
  return participants.value.filter(p =>
    (p.personName || '').toLowerCase().includes(term)
  );
});

// Per-partner матрёшка breakdown by level: для уровня L он получает share(6)+share(7)+...+share(L).
const payoutRows = computed(() => {
  if (!result.value) return [];
  const shares = result.value.shareValues || {};
  return result.value.participants
    .filter(p => p.participates && p.payoutRub > 0)
    .map(p => {
      const byLevel = { 6: 0, 7: 0, 8: 0, 9: 0, 10: 0 };
      if (p.participates) {
        for (let lvl = 6; lvl <= p.level; lvl++) {
          byLevel[lvl] = shares[lvl] || 0;
        }
      }
      return {
        id: p.id,
        personName: p.personName,
        level: p.level,
        levelName: p.levelName,
        byLevel,
        payoutRub: p.payoutRub,
        groupBonusRub: p.groupBonusRub,
      };
    });
});

function resetFilters() {
  search.value = '';
  month.value = defaultMonth;
  loadParticipants();
}

async function loadParticipants() {
  if (!month.value) return;
  loadingParticipants.value = true;
  try {
    const [y, m] = month.value.split('-');
    const { data } = await api.get('/admin/pool/participants', {
      params: { year: Number(y), month: Number(m) },
    });
    participants.value = data.participants || [];
    result.value = null;
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка загрузки участников', 'error');
  }
  loadingParticipants.value = false;

  // Автоматически считаем preview, чтобы таблица расчёта была видна сразу
  // без нажатия кнопки. Запись в poolLog не делается — нужно явное «Применить».
  if (participants.value.length) {
    autoPreview();
  }
}

async function autoPreview() {
  if (!month.value) return;
  try {
    const [y, m] = month.value.split('-');
    const { data } = await api.post('/admin/pool/preview', {
      year: Number(y), month: Number(m),
    });
    result.value = data;
  } catch {} // молча — кнопка «Рассчитать пул» доступна для ручного перезапуска
}

async function toggleParticipates(item, value) {
  toggling.value[item.id] = true;
  try {
    const [y, m] = month.value.split('-');
    await api.put('/admin/pool/participants', {
      year: Number(y), month: Number(m),
      consultant: item.id, participates: !!value,
    });
    item.participates = !!value;
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  toggling.value[item.id] = false;
}

async function calcPool() {
  if (!month.value) return;
  calcing.value = true;
  try {
    const [y, m] = month.value.split('-');
    const { data } = await api.post('/admin/pool/preview', {
      year: Number(y), month: Number(m),
    });
    result.value = data;
    notify('Пул рассчитан (preview, без записи)');
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка расчёта', 'error');
  }
  calcing.value = false;
}

async function applyPool() {
  if (!month.value || !result.value) return;
  if (!await confirm.ask({
    title: 'Применить пул?',
    message: 'Рассчитанный пул будет записан в poolLog. Прошлая запись за этот месяц будет переписана.',
    confirmText: 'Применить', confirmColor: 'primary', icon: 'mdi-content-save',
  })) return;
  applying.value = true;
  try {
    const [y, m] = month.value.split('-');
    const { data } = await api.post('/admin/pool/apply', {
      year: Number(y), month: Number(m),
    });
    result.value = data;
    notify(`Пул применён: ${data.written} записей в poolLog`);
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка применения', 'error');
  }
  applying.value = false;
}

onMounted(loadParticipants);
</script>

<style scoped>
.pool-results :deep(td) { vertical-align: middle; }
.pool-total-row td {
  background: rgba(76, 175, 80, 0.12) !important;
  border-top: 2px solid rgba(76, 175, 80, 0.4) !important;
  border-bottom: 2px solid rgba(76, 175, 80, 0.4) !important;
}
</style>
