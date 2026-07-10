<template>
  <div class="pa-4">
    <PageHeader title="Разбор привязок клиентов"
      subtitle="Контракты, где ФИО в контракте не совпадает с привязанной карточкой (наследие дедуп-склеек). Однозначные — в один клик, спорные — вручную." />

    <v-row class="mb-2" dense>
      <v-col cols="6" sm="3">
        <v-card variant="tonal" color="warning" class="pa-3">
          <div class="text-h4 font-weight-bold" style="font-variant-numeric:tabular-nums">{{ stats.total }}</div>
          <div class="text-body-2">Всего расхождений</div>
        </v-card>
      </v-col>
      <v-col cols="6" sm="3">
        <v-card variant="tonal" color="success" class="pa-3">
          <div class="text-h4 font-weight-bold" style="font-variant-numeric:tabular-nums">{{ stats.unique }}</div>
          <div class="text-body-2">Однозначных (1 кандидат)</div>
        </v-card>
      </v-col>
      <v-col cols="6" sm="3">
        <v-card variant="tonal" color="info" class="pa-3">
          <div class="text-h4 font-weight-bold" style="font-variant-numeric:tabular-nums">{{ stats.ambiguous }}</div>
          <div class="text-body-2">Спорных (2+ тёзки)</div>
        </v-card>
      </v-col>
      <v-col cols="6" sm="3">
        <v-card variant="tonal" color="grey" class="pa-3">
          <div class="text-h4 font-weight-bold" style="font-variant-numeric:tabular-nums">{{ stats.noMatch }}</div>
          <div class="text-body-2">Без карточки</div>
        </v-card>
      </v-col>
    </v-row>

    <v-card class="mb-4 pa-3" variant="tonal">
      <div class="d-flex align-center flex-wrap ga-3">
        <v-btn-toggle v-model="filter" mandatory density="comfortable" color="primary">
          <v-btn value="all" size="small">Все ({{ stats.total }})</v-btn>
          <v-btn value="unique" size="small">Однозначные ({{ stats.unique }})</v-btn>
          <v-btn value="ambiguous" size="small">Спорные ({{ stats.ambiguous }})</v-btn>
          <v-btn value="noMatch" size="small">Без карточки ({{ stats.noMatch }})</v-btn>
        </v-btn-toggle>
        <v-text-field v-model="search" placeholder="Поиск по ФИО / номеру" density="compact" variant="outlined"
          hide-details clearable prepend-inner-icon="mdi-magnify" style="min-width:220px" />
        <v-spacer />
        <v-btn v-if="stats.unique > 0" color="success" size="small" variant="flat"
          prepend-icon="mdi-auto-fix" :loading="bulkBusy" @click="relinkAllUnique">
          Перепривязать все однозначные ({{ stats.unique }})
        </v-btn>
        <v-btn v-if="stats.noMatch > 0" color="primary" size="small" variant="flat"
          prepend-icon="mdi-account-multiple-plus" :loading="bulkBusy" @click="createAllNoMatch">
          Завести карточки для всех без карточки ({{ stats.noMatch }})
        </v-btn>
      </div>
    </v-card>

    <v-card variant="outlined">
      <v-data-table :headers="headers" :items="filtered" :loading="loading"
        density="comfortable" :items-per-page="25" item-value="id">
        <template #item.contract="{ item }">
          <div class="font-weight-medium">{{ item.number || ('#' + item.id) }}</div>
          <div class="text-caption text-medium-emphasis">{{ item.productName || '—' }}</div>
        </template>
        <template #item.clientName="{ item }">
          <span class="font-weight-medium">{{ item.clientName }}</span>
        </template>
        <template #item.currentClientName="{ item }">
          <div class="d-flex align-center ga-1">
            <v-icon size="16" color="error">mdi-account-alert</v-icon>
            <span>{{ item.currentClientName }}</span>
            <span class="text-caption text-medium-emphasis">#{{ item.currentClientId }}</span>
          </div>
        </template>
        <template #item.action="{ item }">
          <!-- Однозначный: одна кнопка -->
          <v-btn v-if="item.candidateCount === 1" color="success" size="small" variant="tonal"
            :loading="busyId === item.id" prepend-icon="mdi-link-variant"
            @click="relink(item, item.candidates[0].id)">
            → {{ item.candidates[0].personName }} #{{ item.candidates[0].id }}
          </v-btn>
          <!-- Спорный: выбор из кандидатов -->
          <div v-else-if="item.candidateCount > 1" class="d-flex align-center ga-2">
            <v-select :items="item.candidates.map(c => ({ title: c.personName + ' #' + c.id + (c.email ? ' · ' + c.email : ''), value: c.id }))"
              v-model="pick[item.id]" placeholder="Выбрать карточку" density="compact" variant="outlined"
              hide-details style="min-width:260px" />
            <v-btn color="success" size="small" variant="tonal" :disabled="!pick[item.id]"
              :loading="busyId === item.id" icon="mdi-check" @click="relink(item, pick[item.id])" />
          </div>
          <!-- Нет карточки — заводим из ФИО контракта -->
          <v-btn v-else color="primary" size="small" variant="tonal"
            :loading="busyId === item.id" prepend-icon="mdi-account-plus"
            @click="createCard(item)">
            Завести карточку «{{ item.clientName }}»
          </v-btn>
        </template>
      </v-data-table>
    </v-card>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import PageHeader from '../../components/PageHeader.vue';
import api from '../../api';
import { useSnackbar } from '../../composables/useSnackbar';

const { showSuccess, showError, showInfo } = useSnackbar();
function notify(text, color = 'success') {
  if (color === 'error') showError(text);
  else if (color === 'warning' || color === 'info') showInfo(text);
  else showSuccess(text);
}

const rows = ref([]);
const stats = ref({ total: 0, unique: 0, ambiguous: 0, noMatch: 0 });
const loading = ref(false);
const busyId = ref(null);
const bulkBusy = ref(false);
const filter = ref('all');
const search = ref('');
const pick = ref({});

const headers = [
  { title: 'Контракт', key: 'contract', sortable: false },
  { title: 'ФИО в контракте (верное)', key: 'clientName' },
  { title: 'Сейчас привязан (чужой)', key: 'currentClientName', sortable: false },
  { title: 'Партнёр', key: 'consultantName' },
  { title: 'Действие', key: 'action', sortable: false, width: 320 },
];

const filtered = computed(() => {
  const q = (search.value || '').toLowerCase().trim();
  return rows.value.filter(r => {
    if (filter.value === 'unique' && r.candidateCount !== 1) return false;
    if (filter.value === 'ambiguous' && r.candidateCount <= 1) return false;
    if (filter.value === 'noMatch' && r.candidateCount !== 0) return false;
    if (q && !(`${r.clientName} ${r.number} ${r.currentClientName} ${r.consultantName}`.toLowerCase().includes(q))) return false;
    return true;
  });
});

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/contracts/client-mismatches');
    rows.value = data.data || [];
    stats.value = {
      total: data.total || 0, unique: data.unique || 0,
      ambiguous: data.ambiguous || 0, noMatch: data.noMatch || 0,
    };
  } catch (e) {
    notify('Не удалось загрузить список', 'error');
  }
  loading.value = false;
}

async function relink(item, clientId) {
  busyId.value = item.id;
  try {
    await api.post(`/admin/contracts/${item.id}/relink-client`, { client: clientId });
    notify(`Контракт ${item.number || '#' + item.id} перепривязан`, 'success');
    rows.value = rows.value.filter(r => r.id !== item.id);
    stats.value.total--;
    if (item.candidateCount === 1) stats.value.unique--;
    else stats.value.ambiguous--;
  } catch (e) {
    notify(e?.response?.data?.message || 'Ошибка перепривязки', 'error');
  }
  busyId.value = null;
}

async function relinkAllUnique() {
  bulkBusy.value = true;
  const uniques = rows.value.filter(r => r.candidateCount === 1);
  let ok = 0;
  for (const item of uniques) {
    try {
      await api.post(`/admin/contracts/${item.id}/relink-client`, { client: item.candidates[0].id });
      ok++;
    } catch { /* пропускаем сбойный, продолжаем */ }
  }
  notify(`Перепривязано ${ok} из ${uniques.length}`, ok ? 'success' : 'warning');
  bulkBusy.value = false;
  await load();
}

async function createCard(item) {
  busyId.value = item.id;
  try {
    await api.post(`/admin/contracts/${item.id}/create-client`, {});
    notify(`Заведена карточка «${item.clientName}» и привязана`, 'success');
    rows.value = rows.value.filter(r => r.id !== item.id);
    stats.value.total--;
    stats.value.noMatch--;
  } catch (e) {
    notify(e?.response?.data?.message || 'Ошибка создания карточки', 'error');
  }
  busyId.value = null;
}

async function createAllNoMatch() {
  bulkBusy.value = true;
  const noMatch = rows.value.filter(r => r.candidateCount === 0);
  let ok = 0;
  for (const item of noMatch) {
    try {
      await api.post(`/admin/contracts/${item.id}/create-client`, {});
      ok++;
    } catch { /* пропускаем сбойный, продолжаем */ }
  }
  notify(`Заведено карточек ${ok} из ${noMatch.length}`, ok ? 'success' : 'warning');
  bulkBusy.value = false;
  await load();
}

onMounted(load);
</script>
