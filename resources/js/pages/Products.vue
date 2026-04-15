<template>
  <div>
    <PageHeader title="Перечень продуктов" icon="mdi-package-variant" />

    <!-- Condition 1: Tests not passed -->
    <v-alert v-if="accessChecked && !access.testsPassed" type="warning" variant="tonal" class="mb-4">
      <div class="font-weight-bold mb-1">Пройдите обучение</div>
      <div class="text-body-2">Для доступа к продуктам необходимо пройти обучение и сдать тесты.</div>
      <v-btn color="warning" variant="flat" size="small" to="/education" class="mt-2" prepend-icon="mdi-school">
        Перейти к обучению
      </v-btn>
    </v-alert>

    <template v-if="access.testsPassed">
      <!-- Filters -->
      <v-card class="mb-3 pa-3">
        <div class="d-flex ga-2 flex-wrap align-center">
          <v-text-field v-model="search" placeholder="Поиск по названию..."
            prepend-inner-icon="mdi-magnify" hide-details style="max-width:300px" @update:model-value="debouncedLoad" />
          <v-select v-model="category" :items="categoryOptions" label="Категория"
            clearable hide-details style="max-width:240px" @update:model-value="filterProducts" />
        </div>
      </v-card>

      <!-- Product cards -->
      <v-row>
        <v-col v-for="product in filteredProducts" :key="product.id" cols="12" sm="6" md="4" lg="3">
          <v-card class="pa-4 d-flex flex-column" height="100%">
            <!-- Image placeholder -->
            <v-img v-if="product.image" :src="product.image" height="140" cover class="rounded mb-3" />
            <div v-else class="bg-grey-lighten-3 rounded d-flex align-center justify-center mb-3" style="height:140px">
              <v-icon size="48" color="grey-lighten-1">mdi-image-outline</v-icon>
            </div>

            <div class="d-flex justify-space-between align-center mb-2">
              <v-chip size="x-small" :color="product.category?.color || 'grey'" variant="outlined">
                {{ product.category?.name || 'Без категории' }}
              </v-chip>
              <v-icon v-if="!product.available" color="grey" size="20">mdi-lock</v-icon>
              <v-icon v-else color="success" size="20">mdi-lock-open</v-icon>
            </div>

            <div class="text-subtitle-1 font-weight-bold mb-1">{{ product.name }}</div>
            <div class="text-body-2 text-medium-emphasis flex-grow-1 mb-3">{{ product.description }}</div>

            <div class="d-flex ga-2 flex-wrap">
              <v-btn variant="outlined" size="small" color="primary" to="/education" prepend-icon="mdi-school">
                Перейти к обучению
              </v-btn>
              <v-btn v-if="product.available" variant="flat" size="small" color="primary"
                prepend-icon="mdi-open-in-new" @click="openProduct(product)">
                Открыть продукт
              </v-btn>
              <v-btn v-else variant="flat" size="small" color="grey" disabled prepend-icon="mdi-lock">
                Открыть продукт
              </v-btn>
            </div>
          </v-card>
        </v-col>
      </v-row>

      <div v-if="!filteredProducts.length && !loading" class="text-center text-medium-emphasis pa-6">
        Продукты не найдены
      </div>
    </template>

    <!-- Condition 2: Requisites not verified -->
    <v-dialog v-model="reqDialog" max-width="420" persistent>
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="warning">mdi-alert-circle</v-icon>
          Реквизиты не подтверждены
        </v-card-title>
        <v-card-text>
          Для доступа к данному продукту необходимо заполнить и подтвердить реквизиты в профиле.
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="reqDialog = false">Закрыть</v-btn>
          <v-btn color="primary" to="/profile" prepend-icon="mdi-account-cog">Перейти в профиль</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Condition 3: Documents not accepted -->
    <v-dialog v-model="acceptDialog" max-width="420" persistent>
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="warning">mdi-file-alert</v-icon>
          Документы не акцептованы
        </v-card-title>
        <v-card-text>
          Для доступа к данному продукту необходимо принять условия и акцептовать документы.
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="acceptDialog = false">Закрыть</v-btn>
          <v-btn color="primary" @click="acceptDocuments" :loading="accepting" prepend-icon="mdi-check">
            Принять документы
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-overlay v-model="loading" class="align-center justify-center" persistent>
      <v-progress-circular indeterminate size="64" />
    </v-overlay>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api';
import { useDebounce } from '../composables/useDebounce';
import PageHeader from '../components/PageHeader.vue';

const loading = ref(true);
const products = ref([]);
const search = ref('');
const category = ref(null);
const categoryOptions = ref([]);
const access = ref({ testsPassed: false, requisitesVerified: false, documentsAccepted: false });
const accessChecked = ref(false);
const reqDialog = ref(false);
const acceptDialog = ref(false);
const accepting = ref(false);
const pendingProduct = ref(null);

const filteredProducts = computed(() => {
  let list = products.value;
  if (search.value) {
    const q = search.value.toLowerCase();
    list = list.filter(p => p.name?.toLowerCase().includes(q));
  }
  if (category.value) {
    list = list.filter(p => p.category?.id === category.value);
  }
  return list;
});

// debouncedLoad not needed — filtering is client-side via computed

function openProduct(product) {
  if (!access.value.requisitesVerified) {
    pendingProduct.value = product;
    reqDialog.value = true;
    return;
  }
  if (!access.value.documentsAccepted) {
    pendingProduct.value = product;
    acceptDialog.value = true;
    return;
  }
  if (product.url) {
    window.open(product.url, '_blank');
  }
}

async function acceptDocuments() {
  accepting.value = true;
  try {
    await api.post('/products/accept-documents');
    access.value.documentsAccepted = true;
    acceptDialog.value = false;
    if (pendingProduct.value?.url) {
      window.open(pendingProduct.value.url, '_blank');
    }
  } catch {}
  accepting.value = false;
}

async function loadProducts() {
  loading.value = true;
  try {
    const { data } = await api.get('/products');
    if (Array.isArray(data)) {
      products.value = data;
    } else {
      products.value = data.products || data.data || [];
      if (data.accessCheck) access.value = data.accessCheck;
      else if (data.access) access.value = data.access;
      if (data.categories) categoryOptions.value = data.categories.map(c => ({ title: c.name, value: c.id }));
    }
    accessChecked.value = true;
  } catch {}
  loading.value = false;
}

onMounted(loadProducts);
</script>
