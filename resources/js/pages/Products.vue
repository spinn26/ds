<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-package-variant</v-icon>
      <h5 class="text-h5 font-weight-bold">Перечень продуктов</h5>
    </div>

    <!-- Access Checks -->
    <v-alert v-if="accessChecked && !access.testsPassed" type="warning" variant="tonal" class="mb-4">
      <div class="font-weight-bold">Тесты не пройдены</div>
      <div class="text-body-2">Для доступа к продуктам необходимо пройти обучение и тестирование.</div>
      <v-btn color="warning" variant="flat" size="small" to="/education" class="mt-2">Перейти к обучению</v-btn>
    </v-alert>

    <template v-if="access.testsPassed">
      <v-card class="mb-3 pa-3">
        <div class="d-flex ga-2 flex-wrap align-center">
          <v-text-field v-model="search" placeholder="Поиск продукта..." density="compact" variant="outlined"
            prepend-inner-icon="mdi-magnify" hide-details style="max-width:300px" @update:model-value="debouncedLoad" />
          <v-select v-model="category" :items="categoryOptions" label="Категория" density="compact" variant="outlined"
            clearable hide-details style="max-width:240px" @update:model-value="loadProducts" />
        </div>
      </v-card>

      <v-row>
        <v-col v-for="product in products" :key="product.id" cols="12" sm="6" md="4" lg="3">
          <v-card class="pa-4 d-flex flex-column" height="100%" @click="onProductClick(product)">
            <div class="d-flex justify-space-between align-center mb-2">
              <v-chip size="x-small" :color="product.category?.color || 'grey'" variant="outlined">
                {{ product.category?.name || 'Без категории' }}
              </v-chip>
              <v-icon v-if="!product.available" color="grey">mdi-lock</v-icon>
              <v-icon v-else color="success">mdi-lock-open</v-icon>
            </div>
            <div class="text-subtitle-1 font-weight-bold mb-1">{{ product.name }}</div>
            <div class="text-body-2 text-medium-emphasis flex-grow-1">{{ product.description }}</div>
            <div v-if="product.commission" class="text-body-2 mt-2">
              Комиссия: <strong>{{ product.commission }}%</strong>
            </div>
          </v-card>
        </v-col>
      </v-row>

      <div v-if="!products.length && !loading" class="text-center text-medium-emphasis pa-6">
        Продукты не найдены
      </div>
    </template>

    <!-- Requisites blocking dialog -->
    <v-dialog v-model="reqDialog" max-width="400">
      <v-card>
        <v-card-title>Реквизиты не подтверждены</v-card-title>
        <v-card-text>Для доступа к данному продукту необходимо заполнить и подтвердить реквизиты.</v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="reqDialog = false">Закрыть</v-btn>
          <v-btn color="primary" to="/profile">Перейти в профиль</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Acceptance blocking dialog -->
    <v-dialog v-model="acceptDialog" max-width="400">
      <v-card>
        <v-card-title>Документы не акцептованы</v-card-title>
        <v-card-text>Для доступа к данному продукту необходимо акцептовать документы.</v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="acceptDialog = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-overlay v-model="loading" class="align-center justify-center" persistent>
      <v-progress-circular indeterminate size="64" />
    </v-overlay>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../api';

const loading = ref(true);
const products = ref([]);
const search = ref('');
const category = ref(null);
const categoryOptions = ref([]);
const access = ref({ testsPassed: false, requisitesVerified: false, documentsAccepted: false });
const accessChecked = ref(false);
const reqDialog = ref(false);
const acceptDialog = ref(false);

let debounceTimer;
function debouncedLoad() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(loadProducts, 400);
}

function onProductClick(product) {
  if (!product.available) {
    if (!access.value.requisitesVerified) {
      reqDialog.value = true;
    } else if (!access.value.documentsAccepted) {
      acceptDialog.value = true;
    }
  }
}

async function loadProducts() {
  loading.value = true;
  try {
    const params = {};
    if (search.value) params.search = search.value;
    if (category.value) params.category = category.value;
    const { data } = await api.get('/products', { params });
    if (Array.isArray(data)) {
      products.value = data;
    } else {
      products.value = data.data || [];
      if (data.access) access.value = data.access;
      if (data.categories) categoryOptions.value = data.categories.map(c => ({ title: c.name, value: c.id }));
    }
    accessChecked.value = true;
  } catch {}
  loading.value = false;
}

onMounted(loadProducts);
</script>
