<template>
  <div>
    <PageHeader title="Продукты и программы" icon="mdi-package-variant-closed">
      <template #actions>
        <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreateProduct">Добавить продукт</v-btn>
      </template>
    </PageHeader>

    <!-- Filters -->
    <v-card class="mb-4 pa-3">
      <v-row dense>
        <v-col cols="12" sm="4">
          <v-text-field v-model="filters.search" label="Поиск по названию" prepend-inner-icon="mdi-magnify"
            rounded clearable hide-details @update:model-value="debouncedLoad" />
        </v-col>
        <v-col cols="12" sm="3">
          <v-select v-model="filters.active" label="Статус" :items="activeOptions"
            clearable hide-details @update:model-value="loadProducts" />
        </v-col>
        <v-col cols="12" sm="2" class="d-flex align-center ga-1">
          <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal">
            {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
          </v-chip>
          <v-btn v-if="activeFilterCount > 0" size="small" variant="text" color="secondary"
            prepend-icon="mdi-filter-remove" @click="resetProductFilters">Сбросить</v-btn>
        </v-col>
      </v-row>
    </v-card>

    <!-- Products Table -->
    <v-card>
      <v-data-table-server
        :headers="headers"
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
          <v-chip :color="item.active ? 'success' : 'grey'" size="x-small">
            {{ item.active ? 'Активен' : 'Неактивен' }}
          </v-chip>
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
        <template #item.actions="{ item }">
          <v-btn icon="mdi-pencil" size="x-small" variant="text" @click.stop="openEditProduct(item)" />
          <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click.stop="confirmDeleteProduct(item)" />
        </template>

        <!-- Expanded row: Programs -->
        <template #expanded-row="{ columns, item }">
          <tr>
            <td :colspan="columns.length" class="pa-4 bg-grey-lighten-5">
              <div class="d-flex justify-space-between align-center mb-2">
                <span class="text-subtitle-2 font-weight-bold">Программы продукта «{{ item.name }}»</span>
                <v-btn size="small" color="primary" prepend-icon="mdi-plus" variant="tonal"
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
                  <v-chip :color="prog.active ? 'success' : 'grey'" size="x-small">
                    {{ prog.active ? 'Активна' : 'Неактивна' }}
                  </v-chip>
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
              <v-text-field v-model="editProduct.imageUrl" label="URL картинки"
                prepend-inner-icon="mdi-image" hint="Ссылка на изображение продукта" persistent-hint />
            </v-col>
            <v-col cols="12" v-if="editProduct.imageUrl">
              <v-img :src="editProduct.imageUrl" height="120" class="rounded border" contain />
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
    <v-dialog v-model="programDialog" max-width="600" persistent>
      <v-card>
        <v-card-title>{{ editProgram.id ? 'Редактировать' : 'Добавить' }} программу</v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col cols="12">
              <v-text-field v-model="editProgram.name" label="Название *" :rules="[v => !!v || 'Обязательное поле']" />
            </v-col>
            <v-col cols="6">
              <v-text-field v-model="editProgram.term" label="Срок" />
            </v-col>
            <v-col cols="6">
              <v-select v-model="editProgram.currency" label="Валюта" :items="currencyOptions" />
            </v-col>
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
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';

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
  { title: 'Статус', key: 'active', width: 120 },
  { title: 'Партнёр', key: 'visibleToResident', width: 100 },
  { title: 'Калькулятор', key: 'visibleToCalculator', width: 110 },
  { title: 'Программ', key: 'programCount', width: 100 },
  { title: 'Действия', key: 'actions', sortable: false, width: 100 },
];

const programHeaders = [
  { title: 'Название', key: 'name' },
  { title: 'Статус', key: 'active', width: 120 },
  { title: 'Срок', key: 'term', width: 100 },
  { title: 'Валюта', key: 'currency', width: 100 },
  { title: 'Калькулятор', key: 'visibleToCalculator', width: 110 },
  { title: 'Действия', key: 'actions', sortable: false, width: 100 },
];

const currencyOptions = ['RUB', 'USD', 'EUR', 'KZT', 'UZS'];

// Product dialog
const productDialog = ref(false);
const productError = ref('');
const editProduct = ref({});

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

let debounceTimer;
function debouncedLoad() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(loadProducts, 400);
}

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
function openCreateProduct() {
  editProduct.value = { name: '', description: '', imageUrl: '', educationUrl: '', instructionUrl: '', openProductUrl: '', active: true, noComission: false, visibleToResident: true, visibleToCalculator: true };
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
  editProgram.value = { name: '', term: '', currency: 'RUB', active: true, visibleToResident: true, visibleToCalculator: true };
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

onMounted(loadProducts);
</script>
