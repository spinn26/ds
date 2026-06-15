<template>
  <div>
    <PageHeader title="Справочники для отчётов руководителей" icon="mdi-chart-line" />

    <v-card class="mb-3">
      <v-card-text class="text-body-2 text-medium-emphasis">
        Курсы валют для расчёта отчётов руководителей компании. Не влияют на
        транзакции и комиссии ФК — только на управленческую отчётность.
        Автоматически копируются с прошлого месяца 1-го числа, затем
        сотрудник проставляет нужные значения.
      </v-card-text>
    </v-card>

    <v-card>
      <v-card-title class="text-subtitle-1 d-flex align-center ga-2 pa-4 pb-2">
        <v-icon size="20">mdi-currency-rub</v-icon>
        Курсы валют (управленческий справочник)
        <v-chip size="x-small" color="primary" variant="tonal">{{ rates.length }}</v-chip>
        <v-spacer />
        <v-btn v-if="canEdit('currencies')" variant="outlined" color="secondary" size="small"
          prepend-icon="mdi-content-copy" class="mr-2" @click="copyDialogOpen = true">
          Скопировать из основного
        </v-btn>
        <v-btn v-if="canEdit('currencies')" color="primary" size="small"
          prepend-icon="mdi-plus" @click="openAdd">Добавить курс</v-btn>
      </v-card-title>

      <v-data-table :items="rates" :headers="headers" density="compact"
        :items-per-page="25" :loading="loading">
        <template #item.period="{ item }">{{ formatPeriod(item.period) }}</template>
        <template #item.symbol="{ item }">
          <v-chip size="x-small" variant="tonal">{{ item.symbol || item.currencyId }}</v-chip>
        </template>
        <template #item.rate="{ item }">{{ fmtRate(item.rate) }} ₽</template>
        <template #item.actions="{ item }">
          <v-btn icon="mdi-pencil" size="x-small" variant="text" color="success"
            title="Изменить курс" @click="openEdit(item)" />
        </template>
        <template #no-data><EmptyState message="Нет курсов. Добавьте вручную или скопируйте из основного справочника." /></template>
      </v-data-table>
    </v-card>

    <!-- Диалог: скопировать из основного справочника -->
    <v-dialog v-model="copyDialogOpen" max-width="400">
      <v-card>
        <v-card-title>Скопировать из основного справочника</v-card-title>
        <v-card-text>
          <p class="text-body-2 text-medium-emphasis mb-4">
            Берёт последние известные курсы за выбранный период из основного справочника
            (используемого для расчёта транзакций) и записывает их в управленческий.
            Существующие записи не перезаписываются.
          </p>
          <v-text-field v-model="copyPeriod" type="month"
            label="Период *" variant="outlined" density="comfortable"
            hint="Выберите месяц и год" persistent-hint />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="copyDialogOpen = false">Отмена</v-btn>
          <v-btn color="primary" :loading="copying" @click="copyFromMain">Скопировать</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Диалог редактирования / добавления -->
    <v-dialog v-model="dialogOpen" max-width="440">
      <v-card>
        <v-card-title>
          {{ editTarget ? `Курс ${editTarget.symbol} — ${formatPeriod(editTarget.period)}` : 'Добавить курс' }}
        </v-card-title>
        <v-card-text>
          <template v-if="!editTarget">
            <v-select v-model="form.currency" :items="currencyList" item-title="label" item-value="id"
              label="Валюта *" variant="outlined" density="comfortable" class="mb-3" />
            <v-text-field v-model="form.date" type="month"
              label="Период *" variant="outlined" density="comfortable" class="mb-3"
              hint="Месяц и год" persistent-hint />
          </template>
          <v-text-field v-model.number="form.rate" type="number" step="0.0001"
            label="Курс к рублю *" variant="outlined" density="comfortable" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="dialogOpen = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" @click="save">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="4000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, EmptyState } from '../../components';
import { usePermissions } from '../../composables/usePermissions';

const { canEdit } = usePermissions();

const loading  = ref(true);
const saving   = ref(false);
const copying  = ref(false);
const rates    = ref([]);
const allCurrencies = ref([]);

const headers = [
  { title: 'Период',  key: 'period',  width: 140 },
  { title: 'Валюта',  key: 'symbol',  width: 100 },
  { title: 'Курс',    key: 'rate',    width: 180 },
  { title: '',        key: 'actions', sortable: false, width: 60 },
];

const currencyList = computed(() =>
  allCurrencies.value.map(c => ({ id: c.id, label: `${c.symbol} — ${c.name}` }))
);

const monthNames = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
function formatPeriod(p) {
  if (!p) return '';
  const [y, m] = p.split('-');
  return `${monthNames[Number(m) - 1]} ${y}`;
}
const fmtRate = (n) => Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 4 });

const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

// --- Copy from main ---
const copyDialogOpen = ref(false);
const copyPeriod     = ref(new Date().toISOString().slice(0, 7));

async function copyFromMain() {
  if (!copyPeriod.value) { notify('Выберите период', 'warning'); return; }
  copying.value = true;
  try {
    const { data } = await api.post('/admin/currencies/management-rates/copy-from-main', { period: copyPeriod.value });
    copyDialogOpen.value = false;
    await loadData();
    notify(data.message || 'Курсы скопированы');
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  copying.value = false;
}

// --- Add / edit ---
const dialogOpen  = ref(false);
const editTarget  = ref(null);
const form        = ref({ currency: null, date: '', rate: 0 });

function openAdd() {
  editTarget.value = null;
  form.value = {
    currency: null,
    date: new Date().toISOString().slice(0, 7),
    rate: 1,
  };
  dialogOpen.value = true;
}

function openEdit(item) {
  editTarget.value = item;
  form.value = { rate: item.rate };
  dialogOpen.value = true;
}

async function save() {
  saving.value = true;
  try {
    if (editTarget.value) {
      await api.patch(`/admin/currencies/management-rates/${editTarget.value.id}`, { rate: form.value.rate });
    } else {
      await api.post('/admin/currencies/management-rates', {
        currency: form.value.currency,
        rate: form.value.rate,
        date: form.value.date + '-01',
      });
    }
    dialogOpen.value = false;
    await loadData();
    notify('Курс сохранён');
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  saving.value = false;
}

async function loadData() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/currencies/management-rates');
    rates.value = data.currencyRates || [];
    allCurrencies.value = data.currencies || [];
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
