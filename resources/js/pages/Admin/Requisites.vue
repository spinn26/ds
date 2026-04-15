<template>
  <div>
    <PageHeader title="Реквизиты партнёров" icon="mdi-credit-card" :count="total" />

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по ФИО, ИНН..."
          rounded prepend-inner-icon="mdi-magnify" clearable hide-details style="max-width:300px" @update:model-value="debouncedLoad" />
        <v-select v-model="statusFilter" :items="verifyOptions" label="Статус верификации"
          clearable hide-details style="max-width:220px" @update:model-value="loadData" />
        <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal" class="ml-1">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
        </v-chip>
        <v-btn v-if="activeFilterCount > 0" size="small" variant="text" color="secondary"
          prepend-icon="mdi-filter-remove" @click="resetFilters">Сбросить</v-btn>
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" @update:options="onOptions">
      <template #item.verificationStatus="{ item }">
        <v-chip size="x-small" :color="verifyColor(item.verificationStatus)">
          {{ verifyLabel(item.verificationStatus) }}
        </v-chip>
      </template>
      <template #item.actions="{ item }">
        <v-btn icon="mdi-eye" size="x-small" variant="text" color="primary"
          title="Просмотреть" @click="openDrawer(item)" />
        <v-btn v-if="item.verificationStatus !== 'verified'" icon="mdi-check" size="x-small" variant="text" color="success"
          title="Подтвердить" :loading="item._verifying" @click="verify(item)" />
        <v-btn v-if="item.verificationStatus !== 'rejected'" icon="mdi-close" size="x-small" variant="text" color="error"
          title="Отклонить" :loading="item._rejecting" @click="reject(item)" />
        
      </template>
      <template #no-data><EmptyState /></template>
    </v-data-table-server>

    <!-- Detail Drawer -->
    <v-navigation-drawer v-model="drawerOpen" location="right" temporary width="480">
      <template v-if="selectedItem">
        <div class="pa-4">
          <div class="d-flex align-center justify-space-between mb-4">
            <div class="text-h6">{{ selectedItem.partnerName }}</div>
            <v-btn icon="mdi-close" size="small" variant="text" @click="drawerOpen = false" />
          </div>
          <v-chip size="small" :color="verifyColor(selectedItem.verificationStatus)" class="mb-4">
            {{ verifyLabel(selectedItem.verificationStatus) }}
          </v-chip>

          <!-- IP Requisites -->
          <div class="text-subtitle-2 font-weight-bold mb-2">Реквизиты ИП</div>
          <v-table density="compact" class="mb-4">
            <tbody>
              <tr><td class="text-medium-emphasis">Наименование ИП</td><td>{{ selectedItem.individualEntrepreneur || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">ИНН</td><td>{{ selectedItem.inn || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">ОГРН</td><td>{{ selectedItem.ogrn || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">Адрес</td><td>{{ selectedItem.address || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">Email</td><td>{{ selectedItem.email || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">Телефон</td><td>{{ selectedItem.phone || '—' }}</td></tr>
            </tbody>
          </v-table>

          <!-- Bank Requisites -->
          <div class="text-subtitle-2 font-weight-bold mb-2">Банковские реквизиты</div>
          <v-table density="compact" class="mb-4">
            <tbody>
              <tr><td class="text-medium-emphasis">Банк</td><td>{{ selectedItem.bankName || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">БИК</td><td>{{ selectedItem.bankBik || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">Расчётный счёт</td><td>{{ selectedItem.accountNumber || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">Корр. счёт</td><td>{{ selectedItem.correspondentAccount || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">Получатель</td><td>{{ selectedItem.beneficiaryName || '—' }}</td></tr>
            </tbody>
          </v-table>

          <!-- Documents -->
          <div class="text-subtitle-2 font-weight-bold mb-2">Документы</div>
          <v-list density="compact" class="mb-4">
            <v-list-item v-for="doc in drawerDocuments" :key="doc.type">
              <template #prepend>
                <v-icon :color="doc.uploaded ? 'success' : 'grey'" size="20">
                  {{ doc.uploaded ? 'mdi-check-circle' : 'mdi-circle-outline' }}
                </v-icon>
              </template>
              <v-list-item-title>{{ doc.label }}</v-list-item-title>
              <v-list-item-subtitle>{{ doc.uploaded ? 'Загружен' : 'Не загружен' }}</v-list-item-subtitle>
            </v-list-item>
          </v-list>

          <!-- Actions -->
          <div class="d-flex ga-2">
            <v-btn v-if="selectedItem.verificationStatus !== 'verified'" color="success" variant="flat"
              prepend-icon="mdi-check" :loading="drawerVerifying" @click="drawerVerify">
              Верифицировать
            </v-btn>
            <v-btn v-if="selectedItem.verificationStatus !== 'rejected'" color="error" variant="flat"
              prepend-icon="mdi-close" @click="rejectDialogOpen = true">
              Отклонить
            </v-btn>
          </div>
        </div>
      </template>
    </v-navigation-drawer>

    <!-- Reject Comment Dialog -->
    <v-dialog v-model="rejectDialogOpen" max-width="480">
      <v-card class="pa-4">
        <v-card-title>Отклонение реквизитов</v-card-title>
        <v-card-text>
          <v-textarea v-model="rejectComment" label="Причина отклонения" rows="3" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="rejectDialogOpen = false">Отмена</v-btn>
          <v-btn color="error" variant="flat" :loading="drawerRejecting" :disabled="!rejectComment"
            @click="drawerReject">Отклонить</v-btn>
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

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const statusFilter = ref(null);

const activeFilterCount = computed(() => {
  let c = 0;
  if (search.value) c++;
  if (statusFilter.value) c++;
  return c;
});

function resetFilters() {
  search.value = '';
  statusFilter.value = null;
  loadData();
}
const page = ref(1);
const drawerOpen = ref(false);
const selectedItem = ref(null);
const drawerDocuments = ref([]);
const drawerVerifying = ref(false);
const drawerRejecting = ref(false);
const rejectDialogOpen = ref(false);
const rejectComment = ref('');

const documentTypeLabels = {
  passportPage1: 'Паспорт (разворот с фото)',
  passportPage2: 'Паспорт (регистрация)',
  applicationForPayment: 'Заявление на получение выплат',
};

async function openDrawer(item) {
  selectedItem.value = item;
  drawerOpen.value = true;
  try {
    const { data } = await api.get(`/admin/requisites/${item.id}/documents`);
    const uploadedTypes = (data || []).map(d => d.type);
    drawerDocuments.value = Object.entries(documentTypeLabels).map(([type, label]) => ({
      type, label, uploaded: uploadedTypes.includes(type),
    }));
  } catch {
    drawerDocuments.value = Object.entries(documentTypeLabels).map(([type, label]) => ({
      type, label, uploaded: false,
    }));
  }
}

async function drawerVerify() {
  if (!selectedItem.value) return;
  drawerVerifying.value = true;
  try {
    await api.post(`/admin/requisites/${selectedItem.value.id}/verify`, { action: 'verify' });
    drawerOpen.value = false;
    loadData();
  } catch {}
  drawerVerifying.value = false;
}

async function drawerReject() {
  if (!selectedItem.value) return;
  drawerRejecting.value = true;
  try {
    await api.post(`/admin/requisites/${selectedItem.value.id}/verify`, { action: 'reject', comment: rejectComment.value });
    rejectDialogOpen.value = false;
    rejectComment.value = '';
    drawerOpen.value = false;
    loadData();
  } catch {}
  drawerRejecting.value = false;
}

const verifyOptions = [
  { title: 'На проверке', value: 'pending' },
  { title: 'Подтверждено', value: 'verified' },
  { title: 'Отклонено', value: 'rejected' },
];

const headers = [
  { title: 'ID', key: 'id', width: 60 },
  { title: 'Партнёр', key: 'partnerName' },
  { title: 'ИП', key: 'individualEntrepreneur' },
  { title: 'ИНН', key: 'inn', width: 130 },
  { title: 'Банк', key: 'bankName' },
  { title: 'Счёт', key: 'accountNumber', width: 200 },
  { title: 'Статус', key: 'verificationStatus', width: 130 },
  { title: 'Действия', key: 'actions', sortable: false, width: 100 },
];

function verifyColor(s) {
  if (s === 'verified') return 'success';
  if (s === 'rejected') return 'error';
  return 'warning';
}

function verifyLabel(s) {
  if (s === 'verified') return 'Подтверждено';
  if (s === 'rejected') return 'Отклонено';
  return 'На проверке';
}

const { debounced: debouncedLoad } = useDebounce(loadData, 400);

function onOptions(opts) {
  page.value = opts.page;
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value };
    if (search.value) params.search = search.value;
    if (statusFilter.value) params.status = statusFilter.value;
    const { data } = await api.get('/admin/requisites', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

async function verify(item) {
  item._verifying = true;
  try {
    await api.post(`/admin/requisites/${item.id}/verify`);
    loadData();
  } catch {}
  item._verifying = false;
}

async function reject(item) {
  item._rejecting = true;
  try {
    await api.post(`/admin/requisites/${item.id}/reject`);
    loadData();
  } catch {}
  item._rejecting = false;
}

onMounted(loadData);
</script>
