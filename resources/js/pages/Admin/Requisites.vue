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
        <v-spacer />
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="requisites-cols" />
      </div>
    </v-card>

    <!-- Bulk actions -->
    <v-slide-y-transition>
      <v-card v-if="selected.length" class="mb-3 pa-3" color="primary" variant="tonal">
        <div class="d-flex align-center flex-wrap ga-2">
          <v-chip color="primary" variant="flat">
            <v-icon start size="16">mdi-checkbox-multiple-marked</v-icon>
            Выбрано: {{ selected.length }}
          </v-chip>
          <v-btn size="small" variant="tonal" color="success"
            prepend-icon="mdi-check" @click="bulkRun('verify')">Верифицировать</v-btn>
          <v-btn size="small" variant="tonal" color="error"
            prepend-icon="mdi-close" @click="bulkRun('reject')">Отклонить</v-btn>
          <v-spacer />
          <v-btn size="small" variant="text" prepend-icon="mdi-close" @click="selected = []">Снять выбор</v-btn>
        </div>
        <v-alert v-if="bulkMsg" :type="bulkMsgType" density="compact" class="mt-2" closable @click:close="bulkMsg = ''">
          {{ bulkMsg }}
        </v-alert>
      </v-card>
    </v-slide-y-transition>

    <v-data-table-server v-model="selected" show-select return-object item-value="id"
      :items="items" :items-length="total" :loading="loading"
      :headers="visibleHeaders" :items-per-page="25" @update:options="onOptions">
      <template #item.hasBankRequisites="{ item }">
        <v-icon v-if="item.hasBankRequisites" color="success" size="18">mdi-check-circle</v-icon>
        <v-icon v-else color="grey" size="18">mdi-minus-circle-outline</v-icon>
      </template>
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
        <StartChatButton :partner-id="item.consultantId || item.consultant" :partner-name="item.consultantName || item.personName"
          context-type="Реквизиты" :context-id="item.id" :context-label="item.individualEntrepreneur || '#' + item.id" />
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

          <!-- 1. Partner data -->
          <div class="text-subtitle-2 font-weight-bold mb-2">Данные партнёра</div>
          <v-table density="compact" class="mb-4">
            <tbody>
              <tr><td class="text-medium-emphasis" style="width:50%">ФИО</td>
                <td>{{ partnerFullName || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">Email</td>
                <td>{{ partnerInfo?.email || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">Телефон</td>
                <td>{{ partnerInfo?.phone || '—' }}</td></tr>
              <tr v-if="partnerInfo?.telegram"><td class="text-medium-emphasis">Telegram</td>
                <td>{{ partnerInfo.telegram }}</td></tr>
              <tr><td class="text-medium-emphasis">Квалификация</td>
                <td>
                  {{ partnerInfo?.qualification || '—' }}
                  <span v-if="partnerInfo?.percent" class="text-medium-emphasis">· {{ partnerInfo.percent }}%</span>
                </td></tr>
              <tr><td class="text-medium-emphasis">Активность</td>
                <td>{{ partnerInfo?.activity || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">Дата регистрации</td>
                <td>{{ fmtDate(partnerInfo?.dateCreated) }}</td></tr>
            </tbody>
          </v-table>

          <!-- 2. IP Requisites -->
          <div class="text-subtitle-2 font-weight-bold mb-2">Реквизиты ИП</div>
          <v-table density="compact" class="mb-4">
            <tbody>
              <tr><td class="text-medium-emphasis">Наименование ИП</td><td>{{ selectedItem.individualEntrepreneur || '—' }}</td></tr>
              <tr>
                <td class="text-medium-emphasis">ИНН</td>
                <td class="d-flex align-center ga-2">
                  <span>{{ selectedItem.inn || '—' }}</span>
                  <v-btn v-if="selectedItem.inn" size="x-small" variant="tonal" color="info"
                    prepend-icon="mdi-magnify" :loading="innChecking" @click="checkInn">
                    Проверить ИНН
                  </v-btn>
                </td>
              </tr>
              <tr><td class="text-medium-emphasis">ОГРН</td><td>{{ selectedItem.ogrn || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">Адрес</td><td>{{ selectedItem.address || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">Email</td><td>{{ selectedItem.email || '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">Телефон</td><td>{{ selectedItem.phone || '—' }}</td></tr>
            </tbody>
          </v-table>

          <!-- 3. Bank Requisites -->
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

          <!-- 4. Documents -->
          <div class="text-subtitle-2 font-weight-bold mb-2">Документы</div>
          <v-list density="compact" class="mb-4">
            <v-list-item v-for="doc in drawerDocuments" :key="doc.type" :lines="doc.uploaded ? 'two' : 'one'">
              <template #prepend>
                <v-icon :color="doc.uploaded ? 'success' : 'grey'" size="20">
                  {{ doc.uploaded ? 'mdi-check-circle' : 'mdi-circle-outline' }}
                </v-icon>
              </template>
              <v-list-item-title>{{ doc.label }}</v-list-item-title>
              <v-list-item-subtitle>
                <template v-if="doc.uploaded">
                  <a href="#" class="text-primary text-decoration-none"
                    @click.prevent="openLightbox(doc)">
                    <v-icon size="14">mdi-eye-outline</v-icon> Открыть
                  </a>
                  <span class="text-medium-emphasis mx-1">·</span>
                  <a :href="doc.url" :download="docFilename(doc)" class="text-primary text-decoration-none">
                    <v-icon size="14">mdi-download</v-icon> Скачать
                  </a>
                  <span v-if="doc.filename" class="text-caption text-medium-emphasis ms-2">{{ doc.filename }}</span>
                </template>
                <span v-else class="text-medium-emphasis">Не загружен</span>
              </v-list-item-subtitle>
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

    <!-- Lightbox для документов (per spec ✅Реквизиты партнеров §1.4) -->
    <v-dialog v-model="lightboxOpen" fullscreen transition="dialog-bottom-transition">
      <v-card v-if="lightboxDoc" class="lightbox-card">
        <v-toolbar density="compact" color="rgba(0,0,0,0.85)" theme="dark">
          <v-toolbar-title>{{ lightboxDoc.label }}</v-toolbar-title>
          <v-spacer />
          <v-btn icon="mdi-rotate-left" variant="text" title="Повернуть влево" @click="rotateLeft" />
          <v-btn icon="mdi-rotate-right" variant="text" title="Повернуть вправо" @click="rotateRight" />
          <v-divider vertical class="mx-2" />
          <v-btn icon="mdi-magnify-minus" variant="text" title="Уменьшить" @click="zoomOut" />
          <span class="text-caption mx-2">{{ Math.round(lightboxZoom * 100) }}%</span>
          <v-btn icon="mdi-magnify-plus" variant="text" title="Увеличить" @click="zoomIn" />
          <v-btn icon="mdi-restore" variant="text" title="Сброс" @click="resetTransform" />
          <v-divider vertical class="mx-2" />
          <v-btn icon="mdi-download" variant="text" :href="lightboxDoc.url" :download="docFilename(lightboxDoc)" title="Скачать" />
          <v-btn icon="mdi-close" variant="text" @click="lightboxOpen = false" />
        </v-toolbar>
        <v-card-text class="lightbox-stage d-flex align-center justify-center pa-0">
          <img v-if="isImage(lightboxDoc.url)" :src="lightboxDoc.url" :style="lightboxStyle" alt="" />
          <iframe v-else :src="lightboxDoc.url" class="lightbox-pdf"
            :style="{ transform: `scale(${lightboxZoom})` }" />
        </v-card-text>
      </v-card>
    </v-dialog>

    <!-- INN check dialog -->
    <v-dialog v-model="innDialog" max-width="560" scrollable>
      <v-card :loading="innChecking">
        <v-card-title class="d-flex align-center pa-4">
          <v-icon class="me-2" :color="innIconColor">{{ innIcon }}</v-icon>
          Проверка по ИНН {{ selectedItem?.inn || '' }}
          <v-spacer />
          <v-btn icon="mdi-close" size="small" variant="text" @click="innDialog = false" />
        </v-card-title>
        <v-divider />
        <v-card-text class="pa-4">
          <template v-if="innChecking">
            <div class="d-flex align-center justify-center pa-4">
              <v-progress-circular indeterminate color="info" />
              <span class="ms-3">Запрос в DaData…</span>
            </div>
          </template>
          <template v-else-if="innResult && !innResult.found">
            <v-alert type="warning" variant="tonal" density="compact">
              <v-icon size="18" class="me-1">mdi-alert-circle</v-icon>
              {{ innResult.error || 'Не удалось проверить ИНН' }}
            </v-alert>
          </template>
          <template v-else-if="innResult && innResult.found">
            <v-alert v-if="innResult.autoVerified" type="success" variant="flat"
              density="comfortable" class="mb-3" icon="mdi-check-decagram">
              <div class="font-weight-bold">Реквизиты автоматически верифицированы</div>
              <div class="text-body-2">ФИО совпадает с DaData, ИП действующий — статус переведён в «Подтверждено».</div>
            </v-alert>

            <!-- FIO check prominent at top -->
            <v-alert v-if="innResult.fioCheck" :type="innResult.fioCheck.match ? 'success' : 'error'"
              variant="tonal" density="comfortable" class="mb-3">
              <div class="font-weight-bold text-body-1">
                {{ innResult.fioCheck.match ? '✓ ФИО совпадает' : '✗ ФИО НЕ совпадает' }}
              </div>
              <div class="text-body-2 mt-2">
                <div><span class="text-medium-emphasis">По ИНН:</span> <b>{{ innResult.fioCheck.actual || '—' }}</b></div>
                <div><span class="text-medium-emphasis">В профиле:</span> <b>{{ innResult.fioCheck.expected || '—' }}</b></div>
              </div>
            </v-alert>

            <!-- Full data -->
            <v-table density="compact">
              <tbody>
                <tr><td class="text-medium-emphasis" style="width:40%">Наименование</td>
                  <td class="font-weight-medium">{{ innResult.name }}</td></tr>
                <tr><td class="text-medium-emphasis">Тип</td>
                  <td>{{ typeLabel(innResult.type) }}</td></tr>
                <tr><td class="text-medium-emphasis">Статус</td>
                  <td>
                    <v-chip size="x-small" :color="statusColor(innResult.status)">
                      {{ statusLabel(innResult.status) }}
                    </v-chip>
                  </td></tr>
                <tr v-if="innResult.registrationDate">
                  <td class="text-medium-emphasis">Дата регистрации</td>
                  <td>{{ innResult.registrationDate }}</td></tr>
                <tr v-if="innResult.ogrn">
                  <td class="text-medium-emphasis">ОГРН</td>
                  <td>{{ innResult.ogrn }}</td></tr>
                <tr v-if="innResult.okved">
                  <td class="text-medium-emphasis">ОКВЭД</td>
                  <td>{{ innResult.okved }}</td></tr>
                <tr v-if="innResult.address">
                  <td class="text-medium-emphasis">Адрес</td>
                  <td>{{ innResult.address }}</td></tr>
              </tbody>
            </v-table>
          </template>
        </v-card-text>
        <v-divider />
        <v-card-actions class="pa-3">
          <v-spacer />
          <v-btn variant="text" @click="innDialog = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

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
import { useConfirm } from '../../composables/useConfirm';

const confirm = useConfirm();
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import StartChatButton from '../../components/StartChatButton.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';

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
const perPage = ref(25);
const selected = ref([]);
const bulkMsg = ref('');
const bulkMsgType = ref('success');
const drawerOpen = ref(false);
const selectedItem = ref(null);
const drawerDocuments = ref([]);
const drawerVerifying = ref(false);
const drawerRejecting = ref(false);
const rejectDialogOpen = ref(false);
const rejectComment = ref('');
const innChecking = ref(false);
const innResult = ref(null);
const innDialog = ref(false);
const partnerInfo = ref(null);

// Lightbox для документов (per spec ✅Реквизиты партнеров §1.4)
const lightboxOpen = ref(false);
const lightboxDoc = ref(null);
const lightboxRotation = ref(0);
const lightboxZoom = ref(1);

function openLightbox(doc) {
  lightboxDoc.value = doc;
  lightboxRotation.value = 0;
  lightboxZoom.value = 1;
  lightboxOpen.value = true;
}
function rotateLeft() { lightboxRotation.value = (lightboxRotation.value - 90) % 360; }
function rotateRight() { lightboxRotation.value = (lightboxRotation.value + 90) % 360; }
function zoomIn() { lightboxZoom.value = Math.min(lightboxZoom.value + 0.25, 4); }
function zoomOut() { lightboxZoom.value = Math.max(lightboxZoom.value - 0.25, 0.25); }
function resetTransform() { lightboxRotation.value = 0; lightboxZoom.value = 1; }
const lightboxStyle = computed(() => ({
  transform: `rotate(${lightboxRotation.value}deg) scale(${lightboxZoom.value})`,
  transition: 'transform 0.18s ease',
  maxWidth: '100%',
  maxHeight: '85vh',
}));
function isImage(url) {
  if (!url) return false;
  return /\.(jpe?g|png|gif|bmp|webp|tiff)(\?|$)/i.test(url);
}

const partnerFullName = computed(() => {
  const p = partnerInfo.value;
  if (!p) return selectedItem.value?.partnerName || '';
  return [p.lastName, p.firstName, p.patronymic].filter(Boolean).join(' ') || p.personName || '';
});

const innIcon = computed(() => {
  if (!innResult.value || !innResult.value.found) return 'mdi-alert-circle-outline';
  return innResult.value.fioCheck?.match ? 'mdi-check-decagram' : 'mdi-alert-decagram';
});
const innIconColor = computed(() => {
  if (!innResult.value || !innResult.value.found) return 'warning';
  return innResult.value.fioCheck?.match ? 'success' : 'error';
});

function typeLabel(t) {
  return { INDIVIDUAL: 'Индивидуальный предприниматель', LEGAL: 'Юридическое лицо' }[t] || (t || '—');
}
function statusLabel(s) {
  return { ACTIVE: 'Действующий', LIQUIDATING: 'Ликвидируется',
    LIQUIDATED: 'Ликвидирован', BANKRUPT: 'Банкрот', REORGANIZING: 'Реорганизация' }[s] || (s || '—');
}
function statusColor(s) {
  return { ACTIVE: 'success', LIQUIDATING: 'warning',
    LIQUIDATED: 'error', BANKRUPT: 'error', REORGANIZING: 'warning' }[s] || 'grey';
}
function fmtDate(d) {
  if (!d) return '—';
  try { return new Date(d).toLocaleDateString('ru-RU'); } catch { return d; }
}

function docFilename(doc) {
  const base = (doc.path || doc.url || '').split('/').pop() || doc.type;
  return base;
}

async function checkInn() {
  if (!selectedItem.value?.id) return;
  innDialog.value = true;
  innChecking.value = true;
  innResult.value = null;
  try {
    const { data } = await api.post(`/admin/requisites/${selectedItem.value.id}/check-inn`);
    innResult.value = data;
    if (data.autoVerified) {
      selectedItem.value.verificationStatus = 'verified';
      loadData();
    }
  } catch (e) {
    innResult.value = { found: false, error: e.response?.data?.message || 'Не удалось проверить' };
  }
  innChecking.value = false;
}

const documentTypeLabels = {
  passportPage1: 'Паспорт (разворот с фото)',
  passportPage2: 'Паспорт (регистрация)',
};

async function openDrawer(item) {
  selectedItem.value = item;
  drawerOpen.value = true;
  innResult.value = null;
  partnerInfo.value = null;

  // Загружаем параллельно: документы + сводка партнёра
  const [docsRes, partnerRes] = await Promise.allSettled([
    api.get(`/admin/requisites/${item.id}/documents`),
    api.get(`/admin/requisites/${item.id}/partner`),
  ]);

  if (docsRes.status === 'fulfilled') {
    const byType = Object.fromEntries((docsRes.value.data || []).map(d => [d.type, d]));
    drawerDocuments.value = Object.entries(documentTypeLabels).map(([type, label]) => ({
      type,
      label,
      uploaded: !!byType[type],
      url: byType[type]?.url || null,
      path: byType[type]?.path || null,
      filename: byType[type]?.filename || null,
    }));
  } else {
    drawerDocuments.value = Object.entries(documentTypeLabels).map(([type, label]) => ({
      type, label, uploaded: false, url: null, path: null, filename: null,
    }));
  }

  partnerInfo.value = partnerRes.status === 'fulfilled' ? partnerRes.value.data : null;
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

async function bulkRun(action) {
  const ids = selected.value.map(x => (typeof x === 'object' ? x.id : x));
  if (!ids.length) return;
  const label = action === 'verify' ? 'верифицировать' : 'отклонить';
  if (!await confirm.ask({
    title: `Массовая ${label.toLowerCase()}?`,
    message: `${ids.length} записей будет переведено в статус "${label}".`,
    confirmText: label, confirmColor: action === 'verify' ? 'success' : 'error',
  })) return;

  let comment = '';
  if (action === 'reject') {
    comment = window.prompt('Комментарий (необязательно):', '') || '';
  }

  try {
    const { data } = await api.post('/admin/requisites/bulk', { ids, action, comment });
    bulkMsg.value = data.message;
    bulkMsgType.value = data.fail > 0 ? 'warning' : 'success';
    selected.value = [];
    loadData();
  } catch (e) {
    bulkMsg.value = e.response?.data?.message || 'Ошибка массового действия';
    bulkMsgType.value = 'error';
  }
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
  { title: 'Партнёр', key: 'consultantName' },
  { title: 'ИП', key: 'individualEntrepreneur' },
  { title: 'ИНН', key: 'inn', width: 130 },
  { title: 'Банк реквизиты', key: 'hasBankRequisites', width: 120 },
  { title: 'Статус', key: 'verificationStatus', width: 130 },
  { title: 'Действия', key: 'actions', sortable: false, width: 100 },
];

const columnVisible = ref({});
const visibleHeaders = computed(() => headers.filter(h => columnVisible.value[h.key] !== false));

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
  if (opts.itemsPerPage) perPage.value = opts.itemsPerPage;
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value };
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

<style scoped>
.lightbox-card {
  background: rgba(20, 20, 20, 0.96) !important;
}
.lightbox-stage {
  height: calc(100vh - 64px);
  overflow: auto;
  background: #1a1a1a;
}
.lightbox-pdf {
  width: 90vw;
  height: 88vh;
  border: none;
  background: white;
}
</style>
