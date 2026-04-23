<template>
  <div>
    <PageHeader title="Перечень продуктов" icon="mdi-package-variant" />

    <template v-if="accessChecked">
      <!-- Filters -->
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

      <!-- Product cards -->
      <v-row>
        <v-col v-for="product in filteredProducts" :key="product.id" cols="12" sm="6" md="4" lg="3">
          <v-card class="pa-4 d-flex flex-column" height="100%" :class="!product.available ? 'locked-card' : ''">
            <!-- Hero image если есть, иначе placeholder -->
            <div v-if="product.heroImage" class="rounded mb-3"
              :style="`height:140px; background: url(${product.heroImage}) center/cover`" />
            <div v-else class="rounded d-flex align-center justify-center mb-3"
              style="height:140px; background: linear-gradient(135deg, #1a1f2e 0%, #2d3748 100%)">
              <div class="text-center">
                <v-img v-if="product.imageUrl" :src="product.imageUrl" height="80" contain />
                <template v-else>
                  <v-icon size="48" color="primary" class="mb-2">mdi-package-variant</v-icon>
                  <div class="text-caption text-white">DS Consulting</div>
                </template>
              </div>
            </div>

            <div class="d-flex justify-space-between align-center mb-2">
              <v-chip size="x-small" :color="product.category?.color || 'grey'" variant="outlined">
                {{ product.category?.name || 'Без категории' }}
              </v-chip>
              <v-icon v-if="!product.available" color="grey" size="20">mdi-lock</v-icon>
              <v-icon v-else color="success" size="20">mdi-lock-open</v-icon>
            </div>

            <div class="text-subtitle-1 font-weight-bold mb-1">{{ product.name }}</div>
            <div class="text-body-2 text-medium-emphasis flex-grow-1 mb-2">{{ product.description }}</div>

            <!-- Currencies this product supports -->
            <div v-if="product.currencies?.length" class="mb-2">
              <v-chip v-for="c in product.currencies" :key="c.id"
                size="x-small" class="me-1" color="primary" variant="outlined">
                {{ c.symbol || c.nameRu }}
              </v-chip>
            </div>

            <!-- Locked: show which courses are still missing -->
            <div v-if="!product.available && product.requiredCourses?.length" class="mb-3">
              <div class="text-caption text-medium-emphasis mb-1">Для доступа пройдите:</div>
              <div class="d-flex flex-wrap ga-1">
                <v-chip v-for="c in product.requiredCourses" :key="c.id"
                  size="x-small" :color="c.completed ? 'success' : 'warning'"
                  :variant="c.completed ? 'tonal' : 'outlined'"
                  :prepend-icon="c.completed ? 'mdi-check' : 'mdi-school'">
                  {{ c.title }}
                </v-chip>
              </div>
            </div>

            <!-- Secondary actions: always visible (обучение + инструкция) -->
            <div v-if="product.educationUrl || product.instructionUrl" class="d-flex ga-1 mb-2 flex-wrap">
              <v-btn v-if="product.educationUrl" size="x-small" variant="text" color="info"
                :href="product.educationUrl" target="_blank" prepend-icon="mdi-school">
                Обучение
              </v-btn>
              <v-btn v-if="product.instructionUrl" size="x-small" variant="text" color="secondary"
                :href="product.instructionUrl" target="_blank" prepend-icon="mdi-file-document">
                Инструкция
              </v-btn>
            </div>

            <div class="d-flex ga-2 flex-wrap mt-auto">
              <v-btn v-if="!product.available && product.requiredCourses?.length"
                variant="tonal" size="small" color="primary" to="/education" prepend-icon="mdi-school">
                К обучению
              </v-btn>
              <v-btn v-if="product.available" variant="flat" size="small" color="primary"
                prepend-icon="mdi-open-in-new" @click="openProduct(product)">
                Открыть продукт
              </v-btn>
              <v-btn v-else-if="!product.requiredCourses?.length"
                variant="flat" size="small" color="grey" disabled prepend-icon="mdi-lock">
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

    <!-- Blocking dialog #1: Requisites — показывается сразу при входе,
         если реквизиты не верифицированы. Пока не заполнены — не даёт
         листать витрину. -->
    <v-dialog v-model="reqDialog" max-width="560" persistent>
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="warning">mdi-shield-account</v-icon>
          Шаг 1: Юридические реквизиты
        </v-card-title>
        <v-card-text>
          <p class="text-body-2 mb-3">
            Чтобы открыть раздел «Продукты», необходимо заполнить данные
            вашего ИП и банковские реквизиты. Заполните ИНН — остальные данные
            подтянутся из реестров автоматически.
          </p>
          <v-text-field
            v-model="inn"
            label="ИНН ИП"
            placeholder="10 или 12 цифр"
            variant="outlined" density="comfortable"
            :loading="innLookup"
            @blur="lookupInn"
            @keyup.enter="lookupInn"
          />
          <v-alert v-if="innResult" :type="innMatch ? 'success' : 'warning'"
            variant="tonal" density="compact" class="mb-3">
            <div class="font-weight-medium">{{ innResult.name || 'Не найдено' }}</div>
            <div v-if="innResult.fioCheck" class="text-caption">
              <template v-if="innMatch">
                ✓ ФИО совпадает с профилем — будет авто-верификация
              </template>
              <template v-else>
                ⚠ ФИО в ИП: {{ innResult.fioCheck.actual }} · В профиле: {{ innResult.fioCheck.expected }}.
                Будет создан тикет финменеджеру на ручную проверку.
              </template>
            </div>
          </v-alert>
          <v-text-field v-model="bankName" label="Банк" variant="outlined" density="comfortable" class="mb-2" />
          <v-text-field v-model="bankBik" label="БИК" variant="outlined" density="comfortable" class="mb-2" />
          <v-text-field v-model="accountNumber" label="Расчётный счёт" variant="outlined" density="comfortable" />
        </v-card-text>
        <v-card-actions class="pa-3">
          <v-spacer />
          <v-btn color="primary" :loading="savingReq"
            :disabled="!canSaveReq" @click="saveRequisites"
            prepend-icon="mdi-content-save">
            Сохранить и продолжить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Blocking dialog #2: Documents — показывается после реквизитов,
         если документы не акцептованы. Все галки обязательны. -->
    <v-dialog v-model="acceptDialog" max-width="560" persistent>
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="warning">mdi-file-check</v-icon>
          Шаг 2: Акцепт документов
        </v-card-title>
        <v-card-text>
          <p class="text-body-2 mb-3">
            Перед началом работы с продуктами необходимо ознакомиться с
            документами и принять условия.
          </p>
          <v-checkbox v-for="d in requiredDocs" :key="d.key"
            v-model="acceptedDocs[d.key]" density="compact" hide-details>
            <template #label>
              <span>{{ d.title }}</span>
              <a v-if="d.url" :href="d.url" target="_blank" class="text-primary ms-2">
                <v-icon size="14">mdi-open-in-new</v-icon> открыть
              </a>
            </template>
          </v-checkbox>
        </v-card-text>
        <v-card-actions class="pa-3">
          <v-spacer />
          <v-btn color="primary" :loading="accepting"
            :disabled="!allDocsAccepted"
            @click="acceptDocuments" prepend-icon="mdi-check">
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
import PageHeader from '../components/PageHeader.vue';

const loading = ref(true);
const products = ref([]);
const search = ref('');
const category = ref(null);
const currency = ref(null);
const categoryOptions = ref([]);
const currencyOptions = ref([]);
const access = ref({ testsPassed: false, requisitesVerified: false, documentsAccepted: false });
const accessChecked = ref(false);
const reqDialog = ref(false);
const acceptDialog = ref(false);
const accepting = ref(false);

// Requisites form state
const inn = ref('');
const innLookup = ref(false);
const innResult = ref(null);
const innMatch = computed(() => innResult.value?.fioCheck?.match ?? null);
const bankName = ref('');
const bankBik = ref('');
const accountNumber = ref('');
const savingReq = ref(false);
const canSaveReq = computed(() =>
  inn.value.replace(/\D/g, '').length >= 10 &&
  bankName.value.trim() && bankBik.value.trim() && accountNumber.value.trim()
);

// Document acceptance
const requiredDocs = [
  { key: 'agency', title: 'Агентский договор', url: null },
  { key: 'privacy', title: 'Согласие на обработку персональных данных', url: null },
  { key: 'offer',   title: 'Публичная оферта', url: null },
];
const acceptedDocs = ref(Object.fromEntries(requiredDocs.map(d => [d.key, false])));
const allDocsAccepted = computed(() => requiredDocs.every(d => acceptedDocs.value[d.key]));

const filteredProducts = computed(() => {
  let list = products.value;
  if (search.value) {
    const q = search.value.toLowerCase();
    list = list.filter(p => p.name?.toLowerCase().includes(q));
  }
  if (category.value) {
    list = list.filter(p => String(p.category?.id) === String(category.value));
  }
  if (currency.value) {
    list = list.filter(p => (p.currencies || []).some(c => c.id === currency.value));
  }
  return list;
});

function openProduct(product) {
  // Реквизиты/акцепт проверены ещё на входе (блокирующие окна), но
  // оставляем защиту на случай если пользователь дошёл до карточки с
  // частично-выполненными шагами.
  if (!access.value.requisitesVerified) { reqDialog.value = true; return; }
  if (!access.value.documentsAccepted)  { acceptDialog.value = true; return; }
  if (product.url) window.open(product.url, '_blank');
}

/** Blocking gate: открыть соответствующее окно сразу после загрузки. */
function gateIfNeeded() {
  if (!access.value.requisitesVerified) {
    reqDialog.value = true;
  } else if (!access.value.documentsAccepted) {
    acceptDialog.value = true;
  }
}

async function lookupInn() {
  const clean = inn.value.replace(/\D/g, '');
  if (clean.length !== 10 && clean.length !== 12) return;
  innLookup.value = true;
  try {
    const { data } = await api.post('/requisites/check-inn', { inn: clean });
    innResult.value = data;
    if (data.found) {
      // Если в ответе пришли юрданные (адрес, наименование) — показываем
      // в alert, реальное сохранение произойдёт в saveRequisites.
    }
  } catch (e) {
    innResult.value = { found: false, error: e.response?.data?.message || 'Не удалось проверить ИНН' };
  }
  innLookup.value = false;
}

async function saveRequisites() {
  savingReq.value = true;
  try {
    await api.post('/requisites', {
      inn: inn.value.replace(/\D/g, ''),
      bankName: bankName.value, bankBik: bankBik.value, accountNumber: accountNumber.value,
      // сервер сам решает auto-verify если ФИО совпали
      fioMatched: innMatch.value === true,
    });
    access.value.requisitesVerified = true;
    reqDialog.value = false;
    // Сразу переходим к следующему шагу
    if (!access.value.documentsAccepted) acceptDialog.value = true;
  } catch (e) {
    alert(e.response?.data?.message || 'Не удалось сохранить реквизиты');
  }
  savingReq.value = false;
}

async function acceptDocuments() {
  accepting.value = true;
  try {
    await api.post('/products/accept-documents', {
      documents: Object.keys(acceptedDocs.value).filter(k => acceptedDocs.value[k]),
    });
    access.value.documentsAccepted = true;
    acceptDialog.value = false;
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
    // Валюты — пересечение из всех продуктов (dedupe by id)
    const seen = new Set();
    currencyOptions.value = products.value
      .flatMap(p => p.currencies || [])
      .filter(c => { if (seen.has(c.id)) return false; seen.add(c.id); return true; })
      .map(c => ({ id: c.id, label: c.symbol ? `${c.nameRu} (${c.symbol})` : c.nameRu }));

    accessChecked.value = true;
    // После загрузки и определения access — запускаем gate
    gateIfNeeded();
  } catch {}
  loading.value = false;
}

onMounted(loadProducts);
</script>

<style scoped>
.locked-card {
  filter: grayscale(0.6);
  opacity: 0.75;
}
.locked-card:hover {
  filter: grayscale(0);
  opacity: 1;
}
</style>
