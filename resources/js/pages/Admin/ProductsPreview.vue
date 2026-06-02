<template>
  <div>
    <v-alert type="info" variant="tonal" density="comfortable" class="mb-3">
      <div class="d-flex align-center">
        <div>
          <div class="font-weight-medium">Предпросмотр витрины партнёра</div>
          <div class="text-caption">
            Все плашки активны, реквизиты и документы игнорируются — для QA/верификации содержимого.
            Переход к реальному управлению продуктами — в
            <router-link to="/admin/products" class="text-primary">/admin/products</router-link>.
          </div>
        </div>
      </div>
    </v-alert>

    <PageHeader title="Витрина продуктов (preview)" icon="mdi-package-variant" />

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по названию..."
          prepend-inner-icon="mdi-magnify" hide-details style="max-width:300px" clearable />
        <v-select v-model="category" :items="categoryOptions" label="Категория"
          clearable hide-details style="max-width:240px" />
        <v-select v-model="currency" :items="currencyOptions"
          item-title="label" item-value="id"
          label="Валюта" clearable hide-details style="max-width:180px" />
      </div>
    </v-card>

    <v-row>
      <v-col v-for="product in filteredProducts" :key="product.id" cols="12" sm="6" md="4" lg="3">
        <v-card class="pa-4 d-flex flex-column" height="100%">
          <!-- Hero: heroImage → imageUrl (логотип) → DS-плейсхолдер.
               Та же логика, что на партнёрской витрине Products.vue —
               раньше тут был захардкожен placeholder, из-за чего preview
               как ФК показывал «DS Consulting» поверх всех продуктов
               вне зависимости от загруженных картинок. -->
          <div class="rounded mb-3 product-hero"
            :style="!product.heroImage && !product.imageUrl
              ? 'background: linear-gradient(135deg, #1a1f2e 0%, #2d3748 100%);' : ''">
            <v-img v-if="product.heroImage" :src="product.heroImage"
              cover height="140" class="rounded">
              <template #error>
                <div class="hero-fallback">
                  <v-img v-if="product.imageUrl" :src="product.imageUrl" height="80" contain>
                    <template #error>
                      <div class="hero-placeholder">
                        <v-icon size="48" color="primary" class="mb-2">mdi-package-variant</v-icon>
                        <div class="text-caption text-white">DS Consulting</div>
                      </div>
                    </template>
                  </v-img>
                  <div v-else class="hero-placeholder">
                    <v-icon size="48" color="primary" class="mb-2">mdi-package-variant</v-icon>
                    <div class="text-caption text-white">DS Consulting</div>
                  </div>
                </div>
              </template>
            </v-img>
            <div v-else class="hero-fallback">
              <v-img v-if="product.imageUrl" :src="product.imageUrl" height="80" contain>
                <template #error>
                  <div class="hero-placeholder">
                    <v-icon size="48" color="primary" class="mb-2">mdi-package-variant</v-icon>
                    <div class="text-caption text-white">DS Consulting</div>
                  </div>
                </template>
              </v-img>
              <div v-else class="hero-placeholder">
                <v-icon size="48" color="primary" class="mb-2">mdi-package-variant</v-icon>
                <div class="text-caption text-white">DS Consulting</div>
              </div>
            </div>
          </div>
          <div class="d-flex justify-space-between align-center mb-2">
            <v-chip size="x-small" :color="product.category?.color || 'grey'" variant="outlined">
              {{ product.category?.name || 'Без категории' }}
            </v-chip>
            <v-icon color="success" size="20" title="Preview: всегда доступен">mdi-eye</v-icon>
          </div>
          <div class="text-subtitle-1 font-weight-bold mb-1">{{ product.name }}</div>
          <div class="text-body-2 text-medium-emphasis flex-grow-1 mb-3">{{ product.description }}</div>
          <div v-if="product.currencies?.length" class="mb-2">
            <v-chip v-for="c in product.currencies" :key="c.id" size="x-small" class="me-1"
              color="primary" variant="outlined">
              {{ c.symbol || c.nameRu }}
            </v-chip>
          </div>
          <div v-if="product.requiredCourses?.length" class="mb-2">
            <div class="text-caption text-medium-emphasis mb-1">Требует курсы:</div>
            <div class="d-flex flex-wrap ga-1">
              <v-chip v-for="c in product.requiredCourses" :key="c.id" size="x-small" variant="outlined">
                {{ c.title }}
              </v-chip>
            </div>
          </div>
          <div class="d-flex ga-2 flex-wrap mt-auto">
            <v-btn v-if="product.educationUrl" size="small" variant="tonal" color="info"
              :href="product.educationUrl" target="_blank" prepend-icon="mdi-school">
              Обучение
            </v-btn>
            <v-btn v-if="product.instructionUrl" size="small" variant="tonal"
              :href="product.instructionUrl" target="_blank" prepend-icon="mdi-file-document">
              Инструкция
            </v-btn>
            <v-btn v-if="product.url" size="small" variant="flat" color="primary"
              :href="product.url" target="_blank" prepend-icon="mdi-open-in-new">
              Открыть
            </v-btn>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <div v-if="!filteredProducts.length && !loading" class="text-center text-medium-emphasis pa-6">
      Продукты не найдены
    </div>

    <v-progress-linear v-if="loading" indeterminate color="primary"
      style="position:fixed;top:0;left:0;right:0;z-index:2000" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';

const loading = ref(true);
const products = ref([]);
const search = ref('');
const category = ref(null);
const currency = ref(null);
const categoryOptions = ref([]);
const currencyOptions = ref([]);

const filteredProducts = computed(() => {
  let list = products.value;
  if (search.value) {
    const q = search.value.toLowerCase();
    list = list.filter(p => p.name?.toLowerCase().includes(q));
  }
  if (category.value) list = list.filter(p => String(p.category?.id) === String(category.value));
  if (currency.value) list = list.filter(p => (p.currencies || []).some(c => (c.symbol || c.nameRu) === currency.value));
  return list;
});

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/products');
    products.value = data.products || data.data || [];
    if (data.categories) categoryOptions.value = data.categories.map(c => ({ title: c.name, value: c.id }));
    // Каталог-валюты приходят строками-кодами (id=null, nameRu=symbol="USD").
    // Дедупим и фильтруем по коду, иначе дедуп по null-id схлопывал список в
    // одну валюту, а лейбл получался дублем «USD (USD)».
    const seen = new Set();
    currencyOptions.value = products.value.flatMap(p => p.currencies || [])
      .map(c => c.symbol || c.nameRu)
      .filter(code => code && !seen.has(code) && seen.add(code))
      .map(code => ({ id: code, label: code }));
  } catch {}
  loading.value = false;
}

onMounted(load);
</script>

<style scoped>
/* Те же стили hero-блока, что в resources/js/pages/Products.vue —
   чтобы preview как ФК выглядел один-в-один с реальной витриной. */
.product-hero {
  height: 140px;
  overflow: hidden;
  position: relative;
  border-radius: var(--ds-radius-md, 8px);
}
.hero-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #1a1f2e 0%, #2d3748 100%);
}
.hero-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
</style>
