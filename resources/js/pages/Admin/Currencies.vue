<template>
  <div>
    <PageHeader title="Валюты и НДС" icon="mdi-currency-usd" />

    <v-row>
      <!-- Курсы валют -->
      <v-col cols="12" md="7">
        <v-card>
          <v-card-title class="text-subtitle-1 d-flex align-center ga-2">
            <v-icon size="20">mdi-currency-rub</v-icon>
            Курсы валют
            <v-chip size="x-small" color="primary" variant="tonal">{{ rates.length }}</v-chip>
          </v-card-title>
          <v-data-table :items="rates" :headers="rateHeaders" density="compact"
            :items-per-page="20" :loading="loading">
            <template #item.period="{ item }">{{ formatPeriod(item.period) }}</template>
            <template #item.symbol="{ item }">
              <v-chip size="x-small" variant="tonal">{{ item.symbol || item.currencyId }}</v-chip>
            </template>
            <template #item.rate="{ item }">{{ fmtRate(item.rate) }} ₽</template>
            <template #item.actions="{ item }">
              <v-btn icon="mdi-pencil" size="x-small" variant="text" color="success"
                title="Изменить курс" @click="openEditRate(item)" />
            </template>
            <template #no-data><EmptyState message="Курсы не найдены" /></template>
          </v-data-table>
        </v-card>
      </v-col>

      <!-- НДС -->
      <v-col cols="12" md="5">
        <v-card>
          <v-card-title class="text-subtitle-1 d-flex align-center ga-2">
            <v-icon size="20">mdi-percent</v-icon>
            Ставки НДС
            <v-spacer />
            <v-btn color="primary" size="small" prepend-icon="mdi-plus"
              @click="openAddVat">Добавить ставку</v-btn>
          </v-card-title>
          <v-data-table :items="vat" :headers="vatHeaders" density="compact" hover>
            <template #item.dateFrom="{ value }">{{ fmtPeriodDate(value) }}</template>
            <template #item.dateTo="{ item }">
              <span v-if="item.isCurrent" class="text-success font-weight-bold">настоящее время</span>
              <span v-else>{{ fmtPeriodDate(item.dateTo) }}</span>
            </template>
            <template #item.value="{ value }">{{ value }}%</template>
            <template #no-data><EmptyState message="Нет ставок" /></template>
          </v-data-table>
        </v-card>
      </v-col>
    </v-row>

    <!-- Edit rate modal -->
    <v-dialog v-model="editRateOpen" max-width="420">
      <v-card v-if="editRateTarget">
        <v-card-title>Курс {{ editRateTarget.symbol }} — {{ formatPeriod(editRateTarget.period) }}</v-card-title>
        <v-card-text>
          <v-text-field v-model.number="editRateForm.rate" type="number" step="0.0001"
            label="Курс к рублю *" variant="outlined" density="comfortable" />
          <v-alert type="warning" variant="tonal" density="compact" class="mt-2">
            При сохранении система пересчитает рублёвые эквиваленты валютных
            транзакций за этот период.
          </v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="editRateOpen = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" @click="saveRate">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Add VAT modal -->
    <v-dialog v-model="addVatOpen" max-width="420">
      <v-card>
        <v-card-title>Добавить ставку НДС</v-card-title>
        <v-card-text>
          <v-text-field v-model.number="addVatForm.value" type="number" step="0.01"
            label="Ставка, % *" variant="outlined" density="comfortable" class="mb-3" />
          <v-text-field v-model="addVatForm.dateFrom" type="month"
            label="Действует с *" variant="outlined" density="comfortable"
            hint="Месяц/год начала действия" persistent-hint />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="addVatOpen = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" @click="saveVat">Добавить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="4000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';

const loading = ref(true);
const saving = ref(false);
const rates = ref([]);
const vat = ref([]);

const rateHeaders = [
  { title: 'Период', key: 'period', width: 130 },
  { title: 'Валюта', key: 'symbol', width: 100 },
  { title: 'Курс', key: 'rate', width: 160 },
  { title: '', key: 'actions', sortable: false, width: 60 },
];

const vatHeaders = [
  { title: 'Период с', key: 'dateFrom', width: 130 },
  { title: 'Период по', key: 'dateTo' },
  { title: 'Ставка', key: 'value', width: 100 },
];

const fmtRate = (n) => Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 4 });
const monthNames = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

function formatPeriod(p) {
  if (!p) return '';
  const [y, m] = p.split('-');
  return `${monthNames[Number(m) - 1]} ${y}`;
}
function fmtPeriodDate(d) {
  if (!d) return '—';
  const dt = new Date(d);
  return `${monthNames[dt.getMonth()]} ${dt.getFullYear()}`;
}

const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

const editRateOpen = ref(false);
const editRateTarget = ref(null);
const editRateForm = ref({ rate: 0 });
function openEditRate(item) {
  editRateTarget.value = item;
  editRateForm.value = { rate: item.rate };
  editRateOpen.value = true;
}
async function saveRate() {
  saving.value = true;
  try {
    await api.patch(`/admin/currencies/rates/${editRateTarget.value.id}`, editRateForm.value);
    editRateOpen.value = false;
    await loadData();
    notify('Курс обновлён');
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  saving.value = false;
}

const addVatOpen = ref(false);
const addVatForm = ref({ value: 0, dateFrom: '' });
function openAddVat() {
  addVatForm.value = { value: 5, dateFrom: new Date().toISOString().slice(0, 7) };
  addVatOpen.value = true;
}
async function saveVat() {
  saving.value = true;
  try {
    await api.post('/admin/currencies/vat', {
      value: addVatForm.value.value,
      dateFrom: addVatForm.value.dateFrom + '-01',
    });
    addVatOpen.value = false;
    await loadData();
    notify('Ставка добавлена');
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  saving.value = false;
}

async function loadData() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/currencies');
    rates.value = data.currencyRates || [];
    vat.value = data.vat || [];
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
