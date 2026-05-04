<template>
  <div>
    <PageHeader title="Закрытие отчётного месяца" icon="mdi-calendar-month" :count="rows.length">
      <template #subtitle>Доступность отчётов на платформе для Партнёров</template>
    </PageHeader>

    <v-alert type="info" variant="tonal" density="compact" class="mb-3" icon="mdi-information">
      Доступность управляет видимостью отчёта партнёрам. Закрытие периода — финальная
      заморозка: транзакции/пересчёты блокируются навсегда (per spec ✅Доступность отчётов).
    </v-alert>

    <DataTableWrapper
      :items="rows"
      :headers="headers"
      :loading="loading"
      :items-per-page="50"
      empty-icon="mdi-calendar-blank"
      empty-message="Реестр периодов пуст"
    >
      <template #item.period="{ item }">
        <span class="font-weight-medium">{{ formatPeriod(item.year, item.month) }}</span>
      </template>
      <template #item.visible="{ item }">
        <v-icon v-if="item.isVisibleToPartners" color="success" title="Отчёты видны партнёрам">mdi-check-circle</v-icon>
        <v-icon v-else color="error" title="Отчёты скрыты от партнёров">mdi-minus-circle</v-icon>
      </template>
      <template #item.visibilityToggle="{ item }">
        <v-btn v-if="!item.isFrozen && !item.isVisibleToPartners"
          size="x-small" color="success" variant="tonal" prepend-icon="mdi-eye"
          @click="toggleVisibility(item, true)">
          Сделать доступным
        </v-btn>
        <v-btn v-else-if="!item.isFrozen && item.isVisibleToPartners"
          size="x-small" color="error" variant="tonal" prepend-icon="mdi-eye-off"
          @click="toggleVisibility(item, false)">
          Сделать недоступным
        </v-btn>
        <span v-else class="text-caption text-medium-emphasis">—</span>
      </template>
      <template #item.frozen="{ item }">
        <v-chip v-if="item.isFrozen" size="x-small" color="error" variant="flat" prepend-icon="mdi-lock">
          Период закрыт
        </v-chip>
        <v-btn v-else size="x-small" color="success" variant="tonal" prepend-icon="mdi-lock"
          @click="confirmClose(item)">
          Закрыть период
        </v-btn>
      </template>
      <template #item.actions="{ item }">
        <v-btn icon="mdi-card-account-details-outline" size="x-small" variant="text"
          color="primary" title="Карточка периода"
          :to="`/manage/periods/${item.year}-${String(item.month).padStart(2, '0')}`" />
        <v-btn v-if="item.isFrozen" icon="mdi-lock-open" size="x-small" variant="text"
          color="warning" title="Переоткрыть период (только в исключительных случаях)"
          @click="confirmReopen(item)" />
      </template>
    </DataTableWrapper>

    <!-- Close period dialog -->
    <v-dialog v-model="closeDialog" max-width="480">
      <v-card v-if="target">
        <v-card-title>Закрыть период {{ formatPeriod(target.year, target.month) }}?</v-card-title>
        <v-card-text>
          <v-alert type="warning" variant="tonal" density="compact" class="mb-3" icon="mdi-shield-alert">
            После закрытия транзакции и пересчёты в этом периоде будут заблокированы.
            Правки — только через «Прочие начисления».
          </v-alert>
          <v-textarea v-model="closeNote" label="Комментарий" rows="2"
            variant="outlined" density="comfortable" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="closeDialog = false">Отмена</v-btn>
          <v-btn color="warning" :loading="saving" @click="doClose">Закрыть период</v-btn>
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
import DataTableWrapper from '../../components/DataTableWrapper.vue';
import { useConfirm } from '../../composables/useConfirm';

const confirm = useConfirm();

const rows = ref([]);
const loading = ref(false);
const saving = ref(false);

const target = ref(null);
const closeDialog = ref(false);
const closeNote = ref('');

const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

const monthNames = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
  'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

function formatPeriod(y, m) {
  return `${monthNames[m - 1]} ${y}`;
}
function fmtDateTime(v) {
  if (!v) return '—';
  return new Date(v).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}

const headers = [
  { title: 'Период', key: 'period', width: 180 },
  { title: 'Доступность', key: 'visible', width: 120, align: 'center' },
  { title: 'Изменить доступность', key: 'visibilityToggle', sortable: false, width: 200 },
  { title: 'Закрыть период', key: 'frozen', sortable: false, width: 200 },
  { title: 'Комментарий', key: 'note' },
  { title: '', key: 'actions', sortable: false, width: 90 },
];

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/periods');
    rows.value = data.data || [];
  } catch (e) {
    notify(e.response?.data?.message || 'Не удалось загрузить', 'error');
  }
  loading.value = false;
}

function confirmClose(item) {
  target.value = item;
  closeNote.value = '';
  closeDialog.value = true;
}

async function doClose() {
  saving.value = true;
  try {
    await api.post('/admin/periods/close', {
      year: target.value.year,
      month: target.value.month,
      note: closeNote.value || null,
    });
    closeDialog.value = false;
    notify('Период закрыт');
    await load();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  saving.value = false;
}

async function toggleVisibility(item, visible) {
  saving.value = true;
  try {
    await api.post('/admin/periods/visibility', {
      year: item.year, month: item.month, visible,
    });
    notify(visible
      ? `Отчёты за ${formatPeriod(item.year, item.month)} стали доступны партнёрам`
      : `Отчёты за ${formatPeriod(item.year, item.month)} скрыты от партнёров`);
    await load();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  saving.value = false;
}

async function confirmReopen(item) {
  if (! await confirm.ask({
    title: `Переоткрыть период ${formatPeriod(item.year, item.month)}?`,
    message: 'Закрытие будет отменено. Будьте осторожны с последующими правками.',
    confirmText: 'Переоткрыть', confirmColor: 'success',
  })) return;
  saving.value = true;
  try {
    await api.post('/admin/periods/reopen', { year: item.year, month: item.month });
    notify('Период открыт');
    await load();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  saving.value = false;
}

onMounted(load);
</script>
