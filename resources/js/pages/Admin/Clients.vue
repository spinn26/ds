<template>
  <div>
    <PageHeader title="Клиенты" icon="mdi-account-group" :count="total">
      <template #actions>
        <v-btn v-if="canEdit('clients')" color="success" prepend-icon="mdi-plus" @click="openAddClient">
          Добавить клиента
        </v-btn>
      </template>
    </PageHeader>

    <v-tabs v-model="clientsTab" color="primary" class="mb-3" density="compact">
      <v-tab value="list" prepend-icon="mdi-account-group">Клиенты</v-tab>
      <v-tab value="history" prepend-icon="mdi-history">История перестановок</v-tab>
    </v-tabs>

    <v-window v-model="clientsTab">
      <v-window-item value="list">

    <!-- Компактный layout фильтров: основные поля в одной flex-строке,
         диапазон дат — за тогглом «Ещё». Без floating-label на полях
         типа date — на узких экранах (Mac Air ~1366px) лейбл резал
         outlined-рамку. -->
    <v-card class="mb-3 pa-3">
      <div class="d-flex flex-wrap ga-2 align-center">
        <v-text-field v-model="search" placeholder="ФИО клиента"
          density="compact" variant="outlined" hide-details clearable
          prepend-inner-icon="mdi-magnify" style="max-width: 240px; flex: 1 1 200px"
          @update:model-value="debouncedLoad" />
        <v-text-field v-model="filters.id" placeholder="ID клиента"
          density="compact" variant="outlined" hide-details clearable
          style="max-width: 130px"
          @update:model-value="debouncedLoad" />
        <v-text-field v-model="filters.consultantName" placeholder="ФИО консультанта"
          density="compact" variant="outlined" hide-details clearable
          style="max-width: 200px; flex: 1 1 160px"
          @update:model-value="debouncedLoad" />
        <v-select v-model="filters.consultantStatusId" :items="statusLevels"
          item-title="title" item-value="id"
          placeholder="Статус наставника (ФК…)"
          density="compact" variant="outlined" hide-details clearable
          style="max-width: 220px; flex: 1 1 180px"
          @update:model-value="loadData" />
        <v-text-field v-model="filters.comment" placeholder="Комментарий"
          density="compact" variant="outlined" hide-details clearable
          style="max-width: 180px; flex: 1 1 140px"
          @update:model-value="debouncedLoad" />

        <v-spacer />

        <v-btn :variant="advancedOpen ? 'tonal' : 'text'" size="small"
          :prepend-icon="advancedOpen ? 'mdi-chevron-up' : 'mdi-tune'"
          @click="advancedOpen = !advancedOpen">
          Ещё
          <v-chip v-if="advancedActiveCount > 0" size="x-small" color="info"
            variant="elevated" class="ms-1">{{ advancedActiveCount }}</v-chip>
        </v-btn>
        <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
        </v-chip>
        <v-btn v-if="activeFilterCount > 0" size="small" variant="text" color="secondary"
          prepend-icon="mdi-filter-remove" @click="resetFilters">Сбросить</v-btn>
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="clients-cols" />
      </div>

      <v-expand-transition>
        <div v-show="advancedOpen" class="d-flex flex-wrap ga-3 mt-3">
          <SmartRangeFilter label="Заведён" kind="date"
            v-model:from="filters.created_from"
            v-model:to="filters.created_to"
            @update:from="loadData" @update:to="loadData" />
        </div>
      </v-expand-transition>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="visibleHeaders" :items-per-page="perPage"
      :items-per-page-options="[25, 50, 100, 200]" @update:options="onOptions">
      <template #item.products="{ value }">
        <v-chip v-for="p in (value || [])" :key="p" size="x-small" class="mr-1" color="primary" variant="outlined">{{ p }}</v-chip>
      </template>
      <template #item.chat="{ item }">
        <StartChatButton :partner-id="item.consultantId || item.consultant" :partner-name="item.consultantName"
          context-type="Клиент" :context-id="item.id" :context-label="item.personName || '#' + item.id" />
      </template>
      <template #item.birthDate="{ value }">
        {{ fmtDate(value) }}
      </template>
      <!-- Счётчик контрактов кликабелен → Менеджер контрактов, отфильтрованный
           по этому клиенту. При 0 — просто «—», без ссылки. -->
      <template #item.contractCount="{ item }">
        <a v-if="item.contractCount" href="#" class="contract-count-link"
          title="Открыть контракты клиента"
          @click.prevent.stop="goToContracts(item)">
          {{ item.contractCount }}
        </a>
        <span v-else class="text-medium-emphasis">—</span>
      </template>
      <template #item.isPartner="{ item }">
        <v-chip :color="item.isPartner ? 'success' : 'default'" size="x-small"
          :variant="item.isPartner ? 'tonal' : 'text'">
          {{ item.isPartner ? 'Да' : 'Нет' }}
        </v-chip>
      </template>
      <template #item.actions="{ item }">
        <v-btn v-if="canEdit('clients')" icon="mdi-pencil" size="x-small" variant="text" color="primary"
          title="Редактировать" @click.stop="openEditClient(item)" />
        <v-btn v-if="canFull('clients')" icon="mdi-delete" size="x-small" variant="text" color="error"
          title="Удалить" @click.stop="confirmDeleteClient(item)" />
      </template>
      <template #no-data><EmptyState /></template>
    </v-data-table-server>

      </v-window-item>
      <v-window-item value="history">
        <ReassignmentPanel subject="client" />
      </v-window-item>
    </v-window>

    <DialogShell
      v-model="deleteDialogOpen"
      title="Удалить клиента?"
      :max-width="500"
      :loading="deleting"
      confirm-text="Удалить"
      confirm-color="error"
      @confirm="performDeleteClient"
    >
      <p class="mb-2">
        <strong>{{ deleteTarget?.personName }}</strong> (ID {{ deleteTarget?.id }})
      </p>
      <p class="text-body-2 text-medium-emphasis mb-3">
        Soft-delete (<code>dateDeleted</code>). Блокируется если у клиента
        есть активные контракты — сначала закрой их.
      </p>
      <v-textarea v-model="deleteReason" label="Причина (для аудита)"
        variant="outlined" density="comfortable" rows="2" />
    </DialogShell>

    <!-- Двухшаг «Добавить клиента» per spec ✅Клиенты §3 -->
    <v-dialog v-model="addOpen" max-width="640" persistent>
      <v-card>
        <v-card-title class="d-flex align-center">
          <v-icon class="me-2">{{ editingId ? 'mdi-account-edit' : 'mdi-account-plus' }}</v-icon>
          {{ editingId
              ? 'Редактирование клиента'
              : (addStep === 1 ? 'Шаг 1: проверка на дубли' : 'Шаг 2: новый клиент') }}
          <v-spacer />
          <v-btn icon="mdi-close" size="small" variant="text" @click="addOpen = false" />
        </v-card-title>

        <v-card-text v-if="addStep === 1 && !editingId">
          <div class="text-body-2 mb-3">
            Введите фамилию или email — система найдёт совпадения, чтобы избежать дубля.
          </div>
          <v-text-field v-model="addSearch" label="Фамилия / email / телефон"
            variant="outlined" density="comfortable"
            prepend-inner-icon="mdi-magnify" autofocus
            @update:model-value="searchAddCandidates" />
          <v-progress-linear v-if="addSearching" indeterminate class="mt-2" />
          <v-list v-if="addCandidates.length" density="compact" class="mt-2">
            <v-list-item v-for="c in addCandidates" :key="c.id"
              :title="c.personName"
              :subtitle="`${c.email || c.phone || '—'} · ID ${c.id}${c.consultantName ? ' · ' + c.consultantName : ''}`">
              <template #prepend><v-icon>mdi-account-circle</v-icon></template>
            </v-list-item>
          </v-list>
          <v-alert v-else-if="addSearch.length >= 2 && !addSearching"
            type="info" variant="tonal" density="compact" class="mt-2">
            Совпадений не найдено.
          </v-alert>
        </v-card-text>

        <v-card-text v-else>
          <v-form v-model="formValid" @submit.prevent="saveNewClient">
            <v-row dense>
              <v-col cols="12" sm="4"><v-text-field v-model="addForm.lastName"
                :rules="cyrillicRequiredRules" label="Фамилия *"
                variant="outlined" density="comfortable"
                @update:model-value="checkDuplicatesDebounced" /></v-col>
              <v-col cols="12" sm="4"><v-text-field v-model="addForm.firstName"
                :rules="cyrillicRequiredRules" label="Имя *"
                variant="outlined" density="comfortable"
                @update:model-value="checkDuplicatesDebounced" /></v-col>
              <v-col cols="12" sm="4"><v-text-field v-model="addForm.patronymic"
                :rules="cyrillicOptionalRules" label="Отчество"
                variant="outlined" density="comfortable" /></v-col>
              <v-col v-if="dupWarnings.length" cols="12">
                <v-alert type="warning" variant="tonal" density="compact" icon="mdi-alert">
                  <div class="font-weight-medium mb-1">
                    Внимание: клиент с таким ФИО уже есть в базе ({{ dupWarnings.length }}). Проверьте на дубль.
                  </div>
                  <div v-for="d in dupWarnings" :key="d.id" class="text-caption d-block">
                    <strong>{{ d.personName }}</strong> (ID {{ d.id }}) —
                    {{ d.email || '—' }}, {{ d.phone || '—' }}<span v-if="d.consultantName">, наставник: {{ d.consultantName }}</span>
                  </div>
                </v-alert>
              </v-col>
              <v-col cols="12" sm="6"><v-text-field v-model="addForm.email"
                :rules="emailRules" label="Email" type="email"
                variant="outlined" density="comfortable" /></v-col>
              <v-col cols="12" sm="6">
                <!-- Жёсткая RU-маска: запрещаем выбор другой страны и
                     вставку «сырых» номеров. Per request 2026-05-23
                     «нельзя просто вставить скопированный номер»:
                     valid-characters-only режет нецифровые при paste,
                     :default-country='ru' + only-countries=['ru'] +
                     disabled-fetching-country запрещают смену кода. -->
                <label class="text-caption text-medium-emphasis d-block mb-1">
                  Телефон <span class="text-medium-emphasis">(необязательно)</span>
                </label>
                <vue-tel-input
                  v-model="addForm.phone"
                  default-country="ru"
                  :only-countries="['ru']"
                  :disabled-fetching-country="true"
                  :auto-default-country="false"
                  valid-characters-only
                  mode="international"
                  :input-options="{ placeholder: '+7 (___) ___-__-__', maxlength: 18 }"
                  :dropdown-options="{ disabled: true, showFlags: false, showDialCodeInList: false }"
                />
              </v-col>
              <v-col cols="12" sm="6"><v-text-field v-model="addForm.birthDate"
                label="Дата рождения" type="date"
                variant="outlined" density="comfortable" /></v-col>
              <v-col cols="12" sm="6"><v-text-field v-model="addForm.city"
                :rules="cyrillicOptionalRules" label="Город"
                variant="outlined" density="comfortable" /></v-col>
              <v-col cols="12" sm="6"><v-autocomplete v-model="addForm.consultant"
                :items="consultantOptions" item-title="personName" item-value="id"
                :loading="searchingConsultants"
                @update:search="searchConsultants"
                @update:model-value="loadConsultantChain"
                :rules="[v => !!v || 'Выберите наставника']"
                label="Консультант *" placeholder="Начните вводить ФИО"
                variant="outlined" density="comfortable"
                hint="Партнёр-наставник" persistent-hint
                no-data-text="Начните вводить ФИО"
                :no-filter="true" hide-no-data clearable /></v-col>
              <v-col v-if="consultantChain.length > 1" cols="12">
                <v-card variant="tonal" color="info" class="pa-3">
                  <div class="text-caption text-medium-emphasis mb-1">
                    <v-icon size="14">mdi-account-tree</v-icon>
                    Цепочка наставников (вверх по структуре)
                  </div>
                  <div class="d-flex align-center flex-wrap ga-1">
                    <template v-for="(p, i) in consultantChain" :key="p.id">
                      <v-chip size="x-small"
                        :color="i === 0 ? 'primary' : undefined"
                        :variant="i === 0 ? 'flat' : 'tonal'">
                        {{ p.personName }}
                        <span v-if="p.level" class="text-caption ml-1 opacity-70">· {{ p.level }}</span>
                      </v-chip>
                      <v-icon v-if="i < consultantChain.length - 1" size="14" color="medium-emphasis">
                        mdi-arrow-right
                      </v-icon>
                    </template>
                  </div>
                </v-card>
              </v-col>
              <v-col cols="12"><v-textarea v-model="addForm.comment"
                label="Комментарий" variant="outlined" density="comfortable" rows="2" /></v-col>
            </v-row>
            <v-alert v-if="addError" type="error" density="compact" class="mt-2">{{ addError }}</v-alert>
          </v-form>
        </v-card-text>

        <v-card-actions>
          <v-btn v-if="addStep === 2 && !editingId" variant="text" prepend-icon="mdi-arrow-left"
            @click="addStep = 1">Назад</v-btn>
          <v-spacer />
          <v-btn v-if="addStep === 1 && !editingId" variant="text" @click="addOpen = false">Отмена</v-btn>
          <v-btn v-if="editingId" variant="text" @click="addOpen = false">Отмена</v-btn>
          <v-btn v-if="addStep === 1 && !editingId" color="success" prepend-icon="mdi-plus"
            :disabled="!addSearch || addSearch.length < 2" @click="gotoNewClientStep">
            + Добавить нового клиента
          </v-btn>
          <v-btn v-if="addStep === 2" color="success" prepend-icon="mdi-content-save"
            :loading="addSaving"
            :disabled="!formValid"
            @click="saveNewClient">
            {{ editingId ? 'Сохранить' : 'Создать клиента' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import StartChatButton from '../../components/StartChatButton.vue';
import DialogShell from '../../components/DialogShell.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import SmartRangeFilter from '../../components/SmartRangeFilter.vue';
import ReassignmentPanel from '../../components/ReassignmentPanel.vue';
import { useConfirm } from '../../composables/useConfirm';

const confirm = useConfirm();
const columnVisible = ref({});
const visibleHeaders = computed(() => headers.filter(h => columnVisible.value[h.key] !== false));
import { useSnackbar } from '../../composables/useSnackbar';
import { fmtDate } from '../../composables/useDesign';
import { usePermissions } from '../../composables/usePermissions';
import { cyrillicRequiredRules, cyrillicOptionalRules, emailRules } from '../../composables/useFormRules';

const { canEdit, canFull } = usePermissions();

const clientsTab = ref('list');

const route = useRoute();
const router = useRouter();

// Переход в Менеджер контрактов, отфильтрованный по клиенту (точно по id).
// Контекст сохраняем: из /admin/clients → /admin/contracts, иначе → /manage/contracts.
function goToContracts(item) {
  if (!item?.contractCount) return;
  const base = route.path.startsWith('/admin/') ? '/admin/contracts' : '/manage/contracts';
  router.push({ path: base, query: { client: item.id, client_name: item.personName || '' } });
}

const { showSuccess, showError } = useSnackbar();
const deleteDialogOpen = ref(false);
const deleteTarget = ref(null);
const deleteReason = ref('');
const deleting = ref(false);

function confirmDeleteClient(item) {
  deleteTarget.value = item;
  deleteReason.value = '';
  deleteDialogOpen.value = true;
}

async function performDeleteClient() {
  if (!deleteTarget.value?.id) return;
  deleting.value = true;
  try {
    await api.delete(`/admin/clients/${deleteTarget.value.id}`, {
      data: { reason: deleteReason.value },
    });
    showSuccess('Клиент удалён');
    deleteDialogOpen.value = false;
    loadData();
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось удалить');
  }
  deleting.value = false;
}

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const page = ref(1);
const perPage = ref(25);
const sortBy = ref('');
const sortDir = ref('desc');
const filters = ref({ id: '', consultantName: '', consultantStatusId: null, comment: '', created_from: '', created_to: '' });
const advancedOpen = ref(false);
// 10-уровневая матрица квалификации — для фильтра «Статус наставника»
// per memory project_commission_spec. Загружаем разово из GET /status-levels.
const statusLevels = ref([]);

const activeFilterCount = computed(() => {
  let c = 0;
  if (search.value) c++;
  Object.values(filters.value).forEach(v => { if (v) c++; });
  return c;
});

// Активные диапазоны (спрятаны за «Ещё») — нужно подсветить чипом, чтобы
// пользователь не пропустил, что фильтр уже работает.
const advancedActiveCount = computed(() => {
  let c = 0;
  if (filters.value.created_from) c++;
  if (filters.value.created_to) c++;
  return c;
});

function resetFilters() {
  search.value = '';
  filters.value = { id: '', consultantName: '', consultantStatusId: null, comment: '', created_from: '', created_to: '' };
  loadData();
}

async function loadStatusLevels() {
  try {
    const { data } = await api.get('/status-levels');
    // /status-levels отдаёт массив { id, level, title, percent, … }.
    // Префиксуем title порядковым level, чтобы пользователь видел иерархию.
    statusLevels.value = (data?.data || data || []).map(l => ({
      id: l.id,
      title: `${l.level} ${l.title}`,
    }));
  } catch {}
}

const headers = [
  { title: 'ID', key: 'id', width: 80 },
  { title: 'ID DS', key: 'dsId', width: 100 },
  { title: 'ФИО', key: 'personName' },
  { title: 'Email', key: 'email' },
  { title: 'Телефон', key: 'phone' },
  { title: 'Дата рождения', key: 'birthDate', width: 130 },
  { title: 'Город', key: 'city' },
  { title: 'Работаем с', key: 'workSince', width: 130 },
  { title: 'Контракты', key: 'contractCount', width: 110, align: 'end' },
  { title: 'Партнёр', key: 'consultantName' },
  { title: 'Статус партнёра', key: 'consultantStatus', width: 160 },
  // Per spec ✅Клиенты §2 — признак «клиент сам является партнёром (Да/Нет)».
  { title: 'Клиент-партнёр', key: 'isPartner', width: 130, align: 'center' },
  { title: 'Комментарий', key: 'comment' },
  { title: 'Продукты', key: 'products', sortable: false },
  { title: '', key: 'chat', sortable: false, width: 50 },
  { title: '', key: 'actions', sortable: false, width: 50 },
];

const { debounced: debouncedLoad } = useDebounce(loadData, 400);

function onOptions(opts) {
  page.value = opts.page;
  if (opts.itemsPerPage) perPage.value = opts.itemsPerPage;
  if (Array.isArray(opts.sortBy) && opts.sortBy.length) {
    sortBy.value = opts.sortBy[0].key;
    sortDir.value = opts.sortBy[0].order || 'desc';
  } else {
    sortBy.value = '';
    sortDir.value = 'desc';
  }
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value };
    if (search.value) params.search = search.value;
    if (filters.value.id) params.id = filters.value.id;
    if (filters.value.consultantName) params.consultant_name = filters.value.consultantName;
    if (filters.value.consultantStatusId) params.consultant_status_id = filters.value.consultantStatusId;
    if (filters.value.comment) params.comment = filters.value.comment;
    if (filters.value.created_from) params.created_from = filters.value.created_from;
    if (filters.value.created_to) params.created_to = filters.value.created_to;
    if (sortBy.value) {
      params.sort_by = sortBy.value;
      params.sort_dir = sortDir.value;
    }
    const { data } = await api.get('/admin/clients', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

// Двухшаг «Добавить клиента» per spec ✅Клиенты §3.
// Тот же диалог используем и для редактирования: editingId != null →
// шаг 1 (поиск дублей) скрываем, save идёт через PUT.
const addOpen = ref(false);
const addStep = ref(1);
const addSearch = ref('');
const addCandidates = ref([]);
const addSearching = ref(false);
const addSaving = ref(false);
const addError = ref('');
const editingId = ref(null);
const formValid = ref(false);
const addForm = ref({
  firstName: '', lastName: '', patronymic: '',
  email: '', phone: '', birthDate: '',
  city: '', consultant: null, comment: '',
});
let addSearchTimer;

// Autocomplete партнёра-наставника. Поиск идёт по существующему
// /admin/partners?search=… (паттерн как в Charges.vue).
// При редактировании в consultantOptions пред-кладём текущего наставника,
// чтобы autocomplete отобразил его ФИО до того, как юзер начнёт искать.
const consultantOptions = ref([]);
const searchingConsultants = ref(false);
let consultantTimer;

// Цепочка наставников выбранного консультанта (вверх по структуре).
// Показывается под селектом «Консультант» в виде хлебных крошек.
const consultantChain = ref([]);
async function loadConsultantChain(id) {
  if (!id) { consultantChain.value = []; return; }
  try {
    const { data } = await api.get(`/admin/consultants/${id}/chain`);
    consultantChain.value = data.chain || [];
  } catch { consultantChain.value = []; }
}

// Антидубль на шаге 2 — debounce-запрос по firstName/lastName.
// Шаг 1 ищет по любому полю (фамилия/email/телефон); шаг 2
// дополнительно подсвечивает тёзок по ФИО даже если оператор пропустил
// первый шаг или поправил имя.
const dupWarnings = ref([]);
let dupCheckTimer;
function checkDuplicatesDebounced() {
  clearTimeout(dupCheckTimer);
  const fn = (addForm.value.firstName || '').trim();
  const ln = (addForm.value.lastName || '').trim();
  if (fn.length < 2 || ln.length < 2) { dupWarnings.value = []; return; }
  dupCheckTimer = setTimeout(async () => {
    try {
      const { data } = await api.get('/admin/clients/check-duplicates', {
        params: { firstName: fn, lastName: ln, excludeId: editingId.value || undefined },
      });
      dupWarnings.value = data.duplicates || [];
    } catch { dupWarnings.value = []; }
  }, 400);
}
async function searchConsultants(q) {
  clearTimeout(consultantTimer);
  if (!q || q.length < 2) return;
  consultantTimer = setTimeout(async () => {
    searchingConsultants.value = true;
    try {
      const { data } = await api.get('/admin/partners', { params: { search: q, per_page: 20 } });
      // Сохраняем текущий выбор (если он не попал в результаты поиска),
      // иначе Vuetify покажет в инпуте сырой ID вместо ФИО.
      const currentId = addForm.value.consultant;
      const current = currentId
        ? consultantOptions.value.find(o => o.id === currentId)
        : null;
      const next = data.data || [];
      consultantOptions.value = current && !next.find(o => o.id === currentId)
        ? [current, ...next]
        : next;
    } catch {}
    searchingConsultants.value = false;
  }, 300);
}

function resetAddForm() {
  addForm.value = {
    firstName: '', lastName: '', patronymic: '',
    email: '', phone: '', birthDate: '',
    city: '', consultant: null, comment: '',
  };
  consultantOptions.value = [];
  consultantChain.value = [];
  dupWarnings.value = [];
}

function openAddClient() {
  editingId.value = null;
  addOpen.value = true;
  addStep.value = 1;
  addSearch.value = '';
  addCandidates.value = [];
  addError.value = '';
  resetAddForm();
}

function openEditClient(item) {
  editingId.value = item.id;
  addOpen.value = true;
  addStep.value = 2;
  addError.value = '';
  // Раскладываем personName → ФИО, если firstName/lastName на ответе нет.
  // /admin/clients сейчас отдаёт только personName + поля person.*, поэтому
  // делаем split «Фамилия Имя Отчество».
  const parts = (item.personName || '').trim().split(/\s+/);
  addForm.value = {
    lastName: parts[0] || '',
    firstName: parts[1] || '',
    patronymic: parts.slice(2).join(' ') || '',
    email: item.email || '',
    phone: item.phone || '',
    birthDate: item.birthDate ? String(item.birthDate).slice(0, 10) : '',
    city: item.city || '',
    consultant: item.consultantId || null,
    comment: item.comment || '',
  };
  // Подготовим опцию autocomplete, чтобы текущий наставник отображался
  // до того, как пользователь начнёт искать.
  consultantOptions.value = item.consultantId
    ? [{ id: item.consultantId, personName: item.consultantName || `ID ${item.consultantId}` }]
    : [];
  // При открытии существующего клиента — сразу подгружаем цепочку ФК.
  consultantChain.value = [];
  dupWarnings.value = [];
  if (item.consultantId) loadConsultantChain(item.consultantId);
  checkDuplicatesDebounced();
}

function searchAddCandidates(q) {
  clearTimeout(addSearchTimer);
  if (!q || q.length < 2) {
    addCandidates.value = [];
    return;
  }
  addSearchTimer = setTimeout(async () => {
    addSearching.value = true;
    try {
      const { data } = await api.get('/admin/clients', { params: { search: q, per_page: 10 } });
      addCandidates.value = data.data || [];
    } catch {}
    addSearching.value = false;
  }, 300);
}

function gotoNewClientStep() {
  const parts = addSearch.value.trim().split(/\s+/);
  if (parts[0]) addForm.value.lastName = parts[0];
  if (parts[1]) addForm.value.firstName = parts[1];
  if (parts[2]) addForm.value.patronymic = parts[2];
  addStep.value = 2;
  addError.value = '';
}

async function saveNewClient() {
  addSaving.value = true;
  addError.value = '';
  try {
    if (editingId.value) {
      await api.put(`/admin/clients/${editingId.value}`, addForm.value);
      showSuccess('Клиент обновлён');
    } else {
      await api.post('/admin/clients', addForm.value);
      showSuccess('Клиент создан');
    }
    addOpen.value = false;
    await loadData();
  } catch (e) {
    // Сервер блокирует создание клиента с уже существующим ФИО. Это может быть
    // настоящий однофамилец — показываем найденные карточки и даём подтвердить.
    if (e.response?.status === 422 && e.response?.data?.code === 'duplicate_client') {
      const found = (e.response.data.existing || [])
        .map(c => `#${c.id} — ${c.consultantName || 'без партнёра'}${c.phone ? ', ' + c.phone : ''}`)
        .join('\n');
      const ok = await confirm.ask({
        title: 'Клиент с таким ФИО уже есть',
        message: `Найдено:\n${found}\n\nЕсли это тот же человек — закройте окно и работайте с существующей карточкой. Создавать вторую стоит только для настоящего однофамильца.`,
        confirmText: 'Всё равно создать', confirmColor: 'warning', icon: 'mdi-account-multiple',
      });
      if (ok) {
        try {
          await api.post('/admin/clients', { ...addForm.value, force: true });
          showSuccess('Клиент создан');
          addOpen.value = false;
          await loadData();
        } catch (e2) {
          addError.value = e2.response?.data?.message || 'Ошибка сохранения';
        }
      }
      addSaving.value = false;
      return;
    }
    addError.value = e.response?.data?.message || 'Ошибка сохранения';
  }
  addSaving.value = false;
}

onMounted(() => {
  loadData();
  loadStatusLevels();
});
</script>

<style scoped>
/* Компактный диапазон: подпись и два узких инпута в одну строку.
   Не используем floating-label на полях type=date — на узких экранах
   лейбл «с»/«по» резал бы outlined-рамку. */
.filter-range {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 220px;
}
.filter-range :deep(.v-field) {
  min-width: 100px;
}
.contract-count-link {
  color: rgb(var(--v-theme-primary));
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
}
.contract-count-link:hover {
  text-decoration: underline;
}
</style>
