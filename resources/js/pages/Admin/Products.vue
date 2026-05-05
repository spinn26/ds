<template>
  <div>
    <PageHeader title="Продукты и программы" icon="mdi-package-variant-closed">
      <template #actions>
        <v-btn v-if="!auth.isEducationOnly" color="primary" prepend-icon="mdi-plus" @click="openCreateProduct">Добавить продукт</v-btn>
      </template>
    </PageHeader>

    <FilterBar
      :search="filters.search"
      search-placeholder="Поиск по названию"
      :search-cols="4"
      :show-reset="activeFilterCount > 0"
      @update:search="v => { filters.search = v ?? ''; debouncedLoad(); }"
      @reset="resetProductFilters"
    >
      <v-col cols="12" md="3">
        <v-select v-model="filters.active" label="Статус" :items="activeOptions"
          variant="outlined" density="comfortable"
          clearable hide-details @update:model-value="loadProducts" />
      </v-col>
      <v-col v-if="activeFilterCount > 0" cols="auto" class="d-flex align-center">
        <v-chip size="small" color="info" variant="tonal">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
        </v-chip>
      </v-col>
      <v-col cols="auto" class="d-flex align-center ms-auto">
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="products-cols" />
      </v-col>
    </FilterBar>

    <!-- Products Table -->
    <v-card>
      <v-data-table-server
        :headers="visibleHeaders"
        :items="products"
        :items-length="total"
        :loading="loading"
        :items-per-page="25"
        :expanded="expanded"
        show-expand
        @update:page="page = $event; loadProducts()"
        @click:row="(e, { item }) => toggleExpand(item)"
      >
        <template #item.active="{ item }">
          <StatusChip
            :color="item.active ? 'success' : 'grey'"
            :text="item.active ? 'Активен' : 'Неактивен'"
            size="x-small"
          />
        </template>
        <template #item.visibleToResident="{ item }">
          <v-icon :color="item.visibleToResident ? 'success' : 'grey'" size="small">
            {{ item.visibleToResident ? 'mdi-eye' : 'mdi-eye-off' }}
          </v-icon>
        </template>
        <template #item.visibleToCalculator="{ item }">
          <v-icon :color="item.visibleToCalculator ? 'success' : 'grey'" size="small">
            {{ item.visibleToCalculator ? 'mdi-calculator' : 'mdi-calculator-variant' }}
          </v-icon>
        </template>
        <template #item.publishStatus="{ item }">
          <v-chip size="x-small"
            :color="item.publishStatus === 'published' ? 'success' : 'warning'"
            :variant="item.publishStatus === 'published' ? 'flat' : 'outlined'">
            {{ item.publishStatus === 'published' ? 'Опубликован' : 'Черновик' }}
          </v-chip>
        </template>
        <template #item.actions="{ item }">
          <v-btn
            :icon="item.publishStatus === 'published' ? 'mdi-arrow-down-bold-circle' : 'mdi-rocket-launch'"
            size="x-small" variant="text"
            :color="item.publishStatus === 'published' ? 'grey' : 'success'"
            :title="item.publishStatus === 'published' ? 'Снять с публикации' : 'Опубликовать'"
            :loading="publishingId === item.id"
            @click.stop="togglePublish(item)" />
          <v-btn icon="mdi-pencil" size="x-small" variant="text" @click.stop="openEditProduct(item)" />
          <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click.stop="confirmDeleteProduct(item)" />
        </template>

        <!-- Expanded row: Programs -->
        <template #expanded-row="{ columns, item }">
          <tr>
            <td :colspan="columns.length" class="pa-4 bg-grey-lighten-5">
              <div class="d-flex justify-space-between align-center mb-2">
                <span class="text-subtitle-2 font-weight-bold">Программы продукта «{{ item.name }}»</span>
                <v-btn v-if="!auth.isEducationOnly" size="small" color="primary" prepend-icon="mdi-plus" variant="tonal"
                  @click="openCreateProgram(item)">Добавить программу</v-btn>
              </div>
              <v-data-table
                :headers="programHeaders"
                :items="programsByProduct[item.id] || []"
                :loading="programsLoading[item.id]"
                density="compact"
                hover
                no-data-text="Нет программ"
                hide-default-footer
              >
                <template #item.active="{ item: prog }">
                  <StatusChip
                    :color="prog.active ? 'success' : 'grey'"
                    :text="prog.active ? 'Активна' : 'Неактивна'"
                    size="x-small"
                  />
                </template>
                <template #item.visibleToCalculator="{ item: prog }">
                  <v-icon :color="prog.visibleToCalculator ? 'success' : 'grey'" size="small">
                    {{ prog.visibleToCalculator ? 'mdi-calculator' : 'mdi-calculator-variant' }}
                  </v-icon>
                </template>
                <template #item.actions="{ item: prog }">
                  <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openEditProgram(item, prog)" />
                  <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="confirmDeleteProgram(item, prog)" />
                </template>
              </v-data-table>
            </td>
          </tr>
        </template>
        <template #no-data><EmptyState /></template>
      </v-data-table-server>
    </v-card>

    <!-- Product Dialog -->
    <v-dialog v-model="productDialog" max-width="600" persistent>
      <v-card>
        <v-card-title>{{ editProduct.id ? 'Редактировать' : 'Добавить' }} продукт</v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col cols="12">
              <v-text-field v-model="editProduct.name" label="Название *" :rules="[v => !!v || 'Обязательное поле']" />
            </v-col>
            <v-col cols="12">
              <v-textarea v-model="editProduct.description" label="Краткое описание" rows="3" auto-grow
                hint="Отображается в карточке продукта" persistent-hint />
            </v-col>
            <v-col cols="12">
              <v-text-field v-model="editProduct.imageUrl" label="URL логотипа / иконки"
                prepend-inner-icon="mdi-image" hint="Маленькое изображение (логотип поставщика). Можно вставить URL или загрузить файл ниже."
                persistent-hint />
            </v-col>
            <v-col cols="12" v-if="editProduct.id">
              <v-file-input v-model="logoFile" label="Загрузить логотип" density="compact"
                accept="image/*" prepend-icon="" prepend-inner-icon="mdi-upload" show-size hide-details
                @update:model-value="uploadImage('image')" :loading="uploadingLogo" />
            </v-col>
            <v-col cols="12" v-if="editProduct.imageUrl">
              <v-img :src="editProduct.imageUrl" height="80" class="rounded border" contain />
            </v-col>
            <v-col cols="12">
              <v-text-field v-model="editProduct.heroImage" label="URL баннера карточки (hero-image)"
                prepend-inner-icon="mdi-image-area" hint="Большое изображение сверху карточки на витрине"
                persistent-hint />
            </v-col>
            <v-col cols="12" v-if="editProduct.id">
              <v-file-input v-model="heroFile" label="Загрузить hero-баннер" density="compact"
                accept="image/*" prepend-icon="" prepend-inner-icon="mdi-upload" show-size hide-details
                @update:model-value="uploadImage('hero')" :loading="uploadingHero" />
            </v-col>
            <v-col cols="12" v-if="editProduct.heroImage">
              <v-img :src="editProduct.heroImage" height="140" class="rounded border" />
            </v-col>
            <v-col cols="12">
              <v-text-field v-model="editProduct.educationUrl" label="Ссылка на обучение"
                prepend-inner-icon="mdi-school" hint="Кнопка 'Перейти к обучению'" persistent-hint />
            </v-col>
            <v-col cols="12">
              <v-text-field v-model="editProduct.instructionUrl" label="Инструкция по открытию"
                prepend-inner-icon="mdi-file-document" hint="Ссылка на инструкцию по открытию продукта" persistent-hint />
            </v-col>
            <v-col cols="12">
              <v-text-field v-model="editProduct.openProductUrl" label="Ссылка 'Открыть продукт'"
                prepend-inner-icon="mdi-open-in-new" hint="Куда ведёт кнопка 'Открыть продукт' (форма БЭКа)" persistent-hint />
            </v-col>
            <v-col cols="12">
              <v-select v-model="editProduct.publishStatus"
                :items="[{title: 'Черновик', value: 'draft'}, {title: 'Опубликован', value: 'published'}]"
                label="Статус публикации" hint="Партнёры видят только опубликованные" persistent-hint />
            </v-col>
            <v-col cols="6">
              <v-checkbox v-model="editProduct.active" label="Активен" density="compact" />
            </v-col>
            <v-col cols="6">
              <v-checkbox v-model="editProduct.noComission" label="Без комиссии" density="compact" />
            </v-col>
            <v-col cols="6">
              <v-checkbox v-model="editProduct.visibleToResident" label="Виден партнёру" density="compact" />
            </v-col>
            <v-col cols="6">
              <v-checkbox v-model="editProduct.visibleToCalculator" label="Виден в калькуляторе" density="compact" />
            </v-col>
            <v-col cols="12">
              <div class="text-caption text-medium-emphasis mb-1">
                Релевантные параметры (показываются в формах транзакций)
              </div>
            </v-col>
            <v-col cols="6" md="4">
              <v-checkbox v-model="editProduct.hasProperty"
                label="«Свойство»" density="compact"
                hint="commissionCalcProperty (стандарт / 1 год / 2 год …)"
                persistent-hint />
            </v-col>
            <v-col cols="6" md="4">
              <v-checkbox v-model="editProduct.hasTerm"
                label="«Срок контракта»" density="compact"
                hint="число лет действия программы"
                persistent-hint />
            </v-col>
            <v-col cols="6" md="4">
              <v-checkbox v-model="editProduct.hasYearKv"
                label="«Год КВ»" density="compact"
                hint="указывается за какой год контракта выплата"
                persistent-hint />
            </v-col>
          </v-row>
          <v-alert v-if="productError" type="error" density="compact" class="mt-2">{{ productError }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="productDialog = false">Отмена</v-btn>
          <v-btn color="primary" @click="saveProduct" :loading="saving">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Program Dialog -->
    <v-dialog v-model="programDialog" max-width="720" persistent scrollable>
      <v-card>
        <v-card-title>{{ editProgram.id ? 'Редактировать' : 'Добавить' }} программу</v-card-title>
        <v-card-text style="max-height: 72vh">
          <v-row dense>
            <v-col cols="12">
              <v-text-field v-model="editProgram.name" label="Название *" :rules="[v => !!v || 'Обязательное поле']" />
            </v-col>
            <v-col cols="6">
              <!-- Per spec ✅Продукты §2 — поле «Поставщик». -->
              <v-text-field v-model="editProgram.providerName" label="Поставщик"
                hint="DS / Axevil / БКС / Insmart …" persistent-hint />
            </v-col>
            <v-col cols="6">
              <!-- Per spec ✅Продукты §2 — поле «Свойство продукта». -->
              <v-text-field v-model="editProgram.vendorName" label="Свойство продукта"
                hint="Например: «МФ», «Standard», «Apfront»" persistent-hint />
            </v-col>
            <v-col cols="6">
              <v-text-field v-model="editProgram.term" label="Срок контракта, лет" type="number" />
            </v-col>
            <v-col cols="6">
              <v-select v-model="editProgram.currency" label="Валюта"
                :items="currencyOptions" item-title="label" item-value="id" />
            </v-col>
          </v-row>

          <v-divider class="my-3" />
          <div class="text-subtitle-2 font-weight-bold mb-2">Параметры расчёта</div>
          <v-row dense>
            <v-col cols="6">
              <v-text-field v-model.number="editProgram.dsPercent" label="% DS" type="number"
                hint="Комиссия платформы от суммы сделки" persistent-hint />
            </v-col>
            <v-col cols="6">
              <v-text-field v-model.number="editProgram.fixedCost" label="Фикс. стоимость"
                type="number" hint="Для продуктов с фикс-ценой (образовательные)" persistent-hint />
            </v-col>
            <v-col cols="12">
              <v-select v-model="editProgram.pointsMethod" label="Методика расчёта баллов"
                :items="pointsMethodOptions" item-title="title" item-value="value" clearable
                hint="Как считать ЛП от сделки по этой программе" persistent-hint />
            </v-col>
            <v-col cols="12">
              <v-text-field v-model="editProgram.pointsFormula" label="Формула (текст)"
                placeholder="Стоимость продукта / 100"
                hint="Подсказка для сотрудника — что делает расчёт. На вычисления не влияет."
                persistent-hint />
            </v-col>
            <v-col cols="4">
              <v-text-field v-model.number="editProgram.pointsMin" label="Баллы от" type="number" />
            </v-col>
            <v-col cols="4">
              <v-text-field v-model.number="editProgram.pointsMax" label="Баллы до" type="number" />
            </v-col>
            <v-col cols="4">
              <v-text-field v-model.number="editProgram.kvPayoutYear" label="Год выплаты КВ"
                type="number" hint="Для регулярных взносов" persistent-hint />
            </v-col>
            <v-col cols="12">
              <v-textarea v-model="editProgram.calcComment" label="Комментарий к расчёту"
                rows="2" auto-grow hint="Какие нюансы учитывать при ручном расчёте" persistent-hint />
            </v-col>
          </v-row>

          <v-divider class="my-3" />
          <v-row dense>
            <v-col cols="4">
              <v-checkbox v-model="editProgram.active" label="Активна" density="compact" />
            </v-col>
            <v-col cols="4">
              <v-checkbox v-model="editProgram.visibleToResident" label="Виден партнёру" density="compact" />
            </v-col>
            <v-col cols="4">
              <v-checkbox v-model="editProgram.visibleToCalculator" label="В калькуляторе" density="compact" />
            </v-col>
          </v-row>
          <v-alert v-if="programError" type="error" density="compact" class="mt-2">{{ programError }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="programDialog = false">Отмена</v-btn>
          <v-btn color="primary" @click="saveProgram" :loading="saving">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete Product Confirm -->
    <v-dialog v-model="deleteProductDialog" max-width="400">
      <v-card>
        <v-card-title>Деактивировать продукт?</v-card-title>
        <v-card-text>{{ deleteProductTarget?.name }}</v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="deleteProductDialog = false">Отмена</v-btn>
          <v-btn color="error" @click="deleteProduct" :loading="saving">Деактивировать</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete Program Confirm -->
    <v-dialog v-model="deleteProgramDialog" max-width="400">
      <v-card>
        <v-card-title>Деактивировать программу?</v-card-title>
        <v-card-text>{{ deleteProgramTarget?.name }}</v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="deleteProgramDialog = false">Отмена</v-btn>
          <v-btn color="error" @click="deleteProgram" :loading="saving">Деактивировать</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import StatusChip from '../../components/StatusChip.vue';
import FilterBar from '../../components/FilterBar.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import { useAuthStore } from '../../stores/auth';

const auth = useAuthStore();

const loading = ref(false);
const saving = ref(false);
const products = ref([]);
const total = ref(0);
const page = ref(1);
const expanded = ref([]);

const filters = ref({ search: '', active: null });

const activeFilterCount = computed(() => {
  let c = 0;
  if (filters.value.search) c++;
  if (filters.value.active) c++;
  return c;
});

function resetProductFilters() {
  filters.value = { search: '', active: null };
  loadProducts();
}
const activeOptions = [
  { title: 'Активные', value: 'true' },
  { title: 'Неактивные', value: 'false' },
];

const headers = [
  { title: 'Название', key: 'name' },
  { title: 'Публикация', key: 'publishStatus', width: 130 },
  { title: 'Статус', key: 'active', width: 110 },
  { title: 'Партнёр', key: 'visibleToResident', width: 90 },
  { title: 'Калькулятор', key: 'visibleToCalculator', width: 100 },
  { title: 'Программ', key: 'programCount', width: 90 },
  { title: 'Действия', key: 'actions', sortable: false, width: 140 },
];

const columnVisible = ref({});
const visibleHeaders = computed(() => headers.filter(h => columnVisible.value[h.key] !== false));

const programHeaders = [
  { title: 'Название', key: 'name' },
  { title: 'Статус', key: 'active', width: 120 },
  { title: 'Срок', key: 'term', width: 100 },
  { title: 'Валюта', key: 'currencyName', width: 100 },
  { title: 'Калькулятор', key: 'visibleToCalculator', width: 110 },
  { title: 'Действия', key: 'actions', sortable: false, width: 100 },
];

// Методики расчёта баллов — синхронизировано с backend
// CalculatorController::computePoints / CommissionCalculator::computePointsForProgram.
const pointsMethodOptions = [
  { value: 'amount_times_ds', title: 'Сумма × %ДС / 10000 (страховые/инвест)' },
  { value: 'cost_div_100',    title: 'Стоимость продукта / 100 (образовательные)' },
  { value: 'amount_div_100',  title: 'Сумма контракта / 100 (оборот)' },
  { value: 'fixed',           title: 'Фиксировано (из "Баллы от")' },
];

// Селектор валют — только рабочие 4 (RUB/USD/EUR/GBP). Управляется
// через currency.selectable в БД, эндпоинт /currencies/selectable.
const currencyOptions = ref([]);
async function loadCurrencies() {
  try {
    const { data } = await api.get('/currencies/selectable');
    currencyOptions.value = (data.items || []).map(c => ({
      id: c.id,
      label: c.label || (c.symbol ? `${c.name} (${c.symbol})` : c.name),
    }));
  } catch {}
}

// Product dialog
const productDialog = ref(false);
const productError = ref('');
const editProduct = ref({});

// Загрузка логотипа / hero-баннера. Работает только для уже сохранённого
// продукта (нужен id) — для нового сначала жмём «Сохранить», потом
// переоткрываем форму на редактирование и грузим картинки.
const logoFile = ref(null);
const heroFile = ref(null);
const uploadingLogo = ref(false);
const uploadingHero = ref(false);

async function uploadImage(kind) {
  const fileRef = kind === 'image' ? logoFile : heroFile;
  const loadingRef = kind === 'image' ? uploadingLogo : uploadingHero;
  const file = Array.isArray(fileRef.value) ? fileRef.value[0] : fileRef.value;
  if (!file || !editProduct.value.id) return;
  loadingRef.value = true;
  try {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('kind', kind);
    const { data } = await api.post(`/admin/products/${editProduct.value.id}/image`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    if (kind === 'image') editProduct.value.imageUrl = data.url;
    else editProduct.value.heroImage = data.url;
    fileRef.value = null;
  } catch (e) {
    productError.value = e.response?.data?.message || 'Ошибка загрузки файла';
  }
  loadingRef.value = false;
}

// Program dialog
const programDialog = ref(false);
const programError = ref('');
const editProgram = ref({});
const editProgramProductId = ref(null);

// Delete dialogs
const deleteProductDialog = ref(false);
const deleteProductTarget = ref(null);
const deleteProgramDialog = ref(false);
const deleteProgramTarget = ref(null);
const deleteProgramProductId = ref(null);

// Programs per product
const programsByProduct = reactive({});
const programsLoading = reactive({});

const { debounced: debouncedLoad } = useDebounce(loadProducts, 400);

async function loadProducts() {
  loading.value = true;
  try {
    const params = { page: page.value };
    if (filters.value.search) params.search = filters.value.search;
    if (filters.value.active) params.active = filters.value.active;
    const { data } = await api.get('/admin/products', { params });
    products.value = data.data || data;
    total.value = data.total || products.value.length;
  } catch {}
  loading.value = false;
}

function toggleExpand(item) {
  const idx = expanded.value.findIndex(e => e === item);
  if (idx >= 0) {
    expanded.value.splice(idx, 1);
  } else {
    expanded.value.push(item);
    loadPrograms(item.id);
  }
}

async function loadPrograms(productId) {
  programsLoading[productId] = true;
  try {
    const { data } = await api.get(`/admin/products/${productId}/programs`);
    programsByProduct[productId] = data.data || data;
  } catch {}
  programsLoading[productId] = false;
}

// Product CRUD
const publishingId = ref(null);

async function togglePublish(product) {
  publishingId.value = product.id;
  try {
    const { data } = await api.post(`/admin/products/${product.id}/toggle-publish`);
    product.publishStatus = data.publishStatus;
  } catch {}
  publishingId.value = null;
}

function openCreateProduct() {
  editProduct.value = {
    name: '', description: '', imageUrl: '', heroImage: '',
    educationUrl: '', instructionUrl: '', openProductUrl: '',
    active: true, noComission: false, visibleToResident: true, visibleToCalculator: true,
    hasProperty: false, hasTerm: false, hasYearKv: false,
    publishStatus: 'draft',
  };
  productError.value = '';
  productDialog.value = true;
}

function openEditProduct(product) {
  editProduct.value = { ...product };
  productError.value = '';
  productDialog.value = true;
}

async function saveProduct() {
  if (!editProduct.value.name) {
    productError.value = 'Название обязательно';
    return;
  }
  saving.value = true;
  productError.value = '';
  try {
    if (editProduct.value.id) {
      await api.put(`/admin/products/${editProduct.value.id}`, editProduct.value);
    } else {
      await api.post('/admin/products', editProduct.value);
    }
    productDialog.value = false;
    loadProducts();
  } catch (e) {
    productError.value = e.response?.data?.message || 'Ошибка сохранения';
  }
  saving.value = false;
}

function confirmDeleteProduct(product) {
  deleteProductTarget.value = product;
  deleteProductDialog.value = true;
}

async function deleteProduct() {
  saving.value = true;
  try {
    await api.delete(`/admin/products/${deleteProductTarget.value.id}`);
    deleteProductDialog.value = false;
    loadProducts();
  } catch {}
  saving.value = false;
}

// Program CRUD
function openCreateProgram(product) {
  editProgramProductId.value = product.id;
  editProgram.value = { name: '', term: '', currency: null, active: true, visibleToResident: true, visibleToCalculator: true };
  programError.value = '';
  programDialog.value = true;
}

function openEditProgram(product, program) {
  editProgramProductId.value = product.id;
  editProgram.value = { ...program };
  programError.value = '';
  programDialog.value = true;
}

async function saveProgram() {
  if (!editProgram.value.name) {
    programError.value = 'Название обязательно';
    return;
  }
  saving.value = true;
  programError.value = '';
  const productId = editProgramProductId.value;
  try {
    if (editProgram.value.id) {
      await api.put(`/admin/products/${productId}/programs/${editProgram.value.id}`, editProgram.value);
    } else {
      await api.post(`/admin/products/${productId}/programs`, editProgram.value);
    }
    programDialog.value = false;
    loadPrograms(productId);
    loadProducts();
  } catch (e) {
    programError.value = e.response?.data?.message || 'Ошибка сохранения';
  }
  saving.value = false;
}

function confirmDeleteProgram(product, program) {
  deleteProgramProductId.value = product.id;
  deleteProgramTarget.value = program;
  deleteProgramDialog.value = true;
}

async function deleteProgram() {
  saving.value = true;
  const productId = deleteProgramProductId.value;
  try {
    await api.delete(`/admin/products/${productId}/programs/${deleteProgramTarget.value.id}`);
    deleteProgramDialog.value = false;
    loadPrograms(productId);
    loadProducts();
  } catch {}
  saving.value = false;
}

onMounted(() => {
  loadProducts();
  loadCurrencies();
});
</script>
