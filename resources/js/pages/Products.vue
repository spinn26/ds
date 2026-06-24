<template>
  <div>
    <PageHeader title="Перечень продуктов" icon="mdi-package-variant" />

    <template v-if="accessChecked">
      <!-- Filters -->
      <v-card class="ds-card mb-3 pa-3" elevation="0">
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
          <v-card class="ds-card ds-card--hover pa-4 d-flex flex-column" height="100%" elevation="0" :class="!product.available ? 'locked-card' : ''">
            <!-- Hero image / лого / плэйсхолдер.
                 Раньше hero-картинка рисовалась через CSS `background: url()`,
                 и при битом URL (нет симлинки `/storage/`, файл удалён, etc.)
                 в карточке оставалось чёрное пятно без визуальной обратной
                 связи. Теперь используем v-img — у него есть `#error` слот,
                 в котором показываем fallback (иконка + бренд). -->
            <div class="rounded mb-3 product-hero"
              :style="!product.heroImage && !product.imageUrl
                ? 'background: linear-gradient(135deg, #1a1f2e 0%, #2d3748 100%);' : ''">
              <v-img v-if="product.heroImage" :src="product.heroImage"
                cover height="140" class="rounded">
                <template #error>
                  <div class="hero-fallback">
                    <v-img v-if="product.imageUrl" :src="product.imageUrl"
                      height="80" contain>
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
                <v-img v-if="product.imageUrl" :src="product.imageUrl"
                  height="80" contain>
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
          <span>Шаг 1: Юридические реквизиты</span>
          <v-spacer />
          <v-btn icon="mdi-close" size="small" variant="text"
            title="Закрыть и вернуться позже" @click="reqDialog = false" />
        </v-card-title>
        <v-card-text>
          <v-alert type="warning" variant="tonal" density="compact" class="mb-3" prepend-icon="mdi-alert">
            <strong>Только ИП на УСН.</strong> Иное юр. лицо —
            <a href="https://t.me/DS_Helpdesk" target="_blank" rel="noopener" class="text-primary">@DS_Helpdesk</a>.
          </v-alert>
          <p class="text-body-2 mb-3">
            Заполните ИНН — остальные данные ИП подтянутся из ЕГРИП
            автоматически. После сохранения реквизиты уйдут на
            <strong>ручную проверку</strong> финменеджеру; до верификации
            подписание документов и продажа продуктов недоступны.
          </p>
          <v-text-field
            v-model="inn"
            label="ИНН ИП"
            placeholder="12 цифр"
            variant="outlined" density="comfortable"
            :loading="innLookup"
            @blur="lookupInn"
            @keyup.enter="lookupInn"
          />
          <v-alert v-if="innResult" :type="innResult.found ? 'info' : 'warning'"
            variant="tonal" density="compact" class="mb-3">
            <div class="font-weight-medium">{{ innResult.name || 'Не найдено' }}</div>
            <div v-if="innResult.fioCheck" class="text-caption">
              <template v-if="innMatch">
                ✓ ФИО совпадает с профилем.
              </template>
              <template v-else>
                ⚠ ФИО в ИП: {{ innResult.fioCheck.actual }} · В профиле: {{ innResult.fioCheck.expected }}.
              </template>
            </div>
            <div class="text-caption mt-1">
              Реквизиты будут отправлены на ручную проверку финменеджеру —
              автоматическое подтверждение режима УСН недоступно.
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

    <!-- Pending-плашка: реквизиты заполнены, но Катя ещё не верифицировала.
         Показываем вместо диалога подписания — акцепт доступен только
         после ручной верификации (решение от 2026-05-27). -->
    <v-dialog v-model="pendingDialog" max-width="520" persistent>
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="warning">mdi-clock-outline</v-icon>
          <span>Ожидайте проверки документов</span>
          <v-spacer />
          <v-btn icon="mdi-close" size="small" variant="text"
            title="Закрыть и вернуться позже" @click="pendingDialog = false" />
        </v-card-title>
        <v-card-text>
          <v-alert type="info" variant="tonal" density="compact" class="mb-3">
            Ваши реквизиты получены и переданы финансовому менеджеру.
          </v-alert>
          <p class="text-body-2 mb-2">
            Сейчас идёт <strong>ручная проверка</strong> данных ИП и режима УСН.
            После верификации откроются: подписание документов, продажа продуктов
            и финансовые операции.
          </p>
          <p class="text-caption text-medium-emphasis mb-0">
            Если проверка затягивается — напишите в техподдержку
            <a href="https://t.me/DS_Helpdesk" target="_blank" rel="noopener" class="text-primary">@DS_Helpdesk</a>.
          </p>
        </v-card-text>
        <v-card-actions class="pa-3">
          <v-spacer />
          <v-btn variant="text" @click="pendingDialog = false">Понятно</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>


    <!-- Программы продукта — открывается при клике «Открыть продукт»,
         если у продукта есть привязанные программы. -->
    <v-dialog v-model="programsDialog" max-width="720" scrollable>
      <v-card v-if="selectedProduct">
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="primary">mdi-package-variant</v-icon>
          <span>Программы продукта «{{ selectedProduct.name }}»</span>
          <v-spacer />
          <v-btn icon="mdi-close" size="small" variant="text" @click="programsDialog = false" />
        </v-card-title>
        <v-divider />
        <v-card-text class="pa-3">
          <div v-if="!selectedProduct.programs?.length" class="text-center text-medium-emphasis py-6">
            У продукта пока нет программ.
          </div>
          <v-list v-else density="comfortable">
            <v-list-item v-for="p in selectedProduct.programs" :key="p.id"
              class="program-row mb-2 pa-3 rounded">
              <div class="d-flex align-center ga-3 flex-wrap w-100">
                <div class="flex-grow-1 min-w-0">
                  <div class="text-body-1 font-weight-medium text-truncate">{{ p.name }}</div>
                  <div class="text-caption text-medium-emphasis d-flex ga-2 flex-wrap mt-1">
                    <span v-if="p.providerName">{{ p.providerName }}</span>
                    <span v-if="p.categoryName">· {{ p.categoryName }}</span>
                    <v-chip v-if="p.currencySymbol" size="x-small" variant="outlined" color="primary">
                      {{ p.currencySymbol }}
                    </v-chip>
                  </div>
                </div>
                <v-btn v-if="p.formLink" size="small" color="primary" variant="flat"
                  :href="p.formLink" target="_blank" rel="noopener" prepend-icon="mdi-open-in-new">
                  Открыть
                </v-btn>
                <v-chip v-else size="small" variant="tonal" color="grey">Ссылка не указана</v-chip>
              </div>
            </v-list-item>
          </v-list>
        </v-card-text>
        <v-card-actions class="pa-3">
          <v-spacer />
          <v-btn variant="text" @click="programsDialog = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-progress-linear v-if="loading" indeterminate color="primary"
      style="position:fixed;top:0;left:0;right:0;z-index:2000" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api';
import PageHeader from '../components/PageHeader.vue';
import { useSnackbar } from '../composables/useSnackbar';

const router = useRouter();

const { showError, showSuccess } = useSnackbar();

const loading = ref(true);
const products = ref([]);
const search = ref('');
const category = ref(null);
const currency = ref(null);
const allCategories = ref([]);
// Только используемые в данный момент категории (хотя бы один активный
// product опубликован и виден партнёру). Иначе фильтр забивается пустыми
// разделами.
const categoryOptions = computed(() => {
  const usedIds = new Set(products.value.map(p => p.category?.id).filter(Boolean));
  return allCategories.value
    .filter(c => usedIds.has(c.value))
    .sort((a, b) => a.title.localeCompare(b.title));
});
const currencyOptions = ref([]);
const access = ref({ testsPassed: false, requisitesVerified: false, requisitesSubmitted: false, documentsAccepted: false });
const accessChecked = ref(false);
const reqDialog = ref(false);
const pendingDialog = ref(false);

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

// Акцепт Оферты вынесен в глобальную модалку OfferAcceptanceDialog
// (MainLayout). Она показывается persistent, пока offerAccepted=false
// у партнёра с verified-реквизитами — продукты при этом физически
// недоступны (UI закрыт оверлеем).

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
    list = list.filter(p => (p.currencies || []).some(c => (c.symbol || c.nameRu) === currency.value));
  }
  return list;
});

const programsDialog = ref(false);
const selectedProduct = ref(null);

function openProduct(product) {
  // Реквизиты/верификация больше НЕ блокируют открытие продукта (решение
  // владельца 24.06.2026). Окно «Шаг 1: Юридические реквизиты» осталось лишь
  // подсказкой для сбора ИП (нужно для выплат) — показывается один раз при
  // входе и закрывается, но клик «Открыть продукт» теперь всегда открывает
  // продукт, а не возвращает форму. Акцепт Оферты живёт в глобальной
  // OfferAcceptanceDialog (MainLayout) и здесь не проверяется.
  // Internal route (openProductUrl начинается с «/») — открываем
  // во SPA через router.push. Используется, например, для InSmart-виджета.
  if (product.url && /^\/(?!\/)/.test(product.url)) {
    router.push(product.url);
    return;
  }
  // Если у продукта есть программы — открываем модалку со списком
  // и ссылками. Иначе fallback на старое поведение (product.url).
  if (product.programs?.length) {
    selectedProduct.value = product;
    programsDialog.value = true;
    return;
  }
  if (product.url) window.open(product.url, '_blank');
}

/** Blocking gate (2026-05-27):
 *  - submitted=false → форма реквизитов (reqDialog)
 *  - submitted=true, verified=false → pending-плашка (Катя проверяет)
 *  - verified=true, accepted=false → блокирующая модалка Оферты живёт
 *    в MainLayout (OfferAcceptanceDialog), здесь ничего не делаем.
 *  Иначе — доступ открыт. */
function gateIfNeeded() {
  if (!access.value.requisitesSubmitted) {
    reqDialog.value = true;
  } else if (!access.value.requisitesVerified) {
    pendingDialog.value = true;
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
    // Backend сам проверяет ИНН через ФНС (DaData → ЕГРИП/ЕГРЮЛ) и
    // решает auto-verify. fioMatched оставляем как hint, но окончательное
    // решение принимает сервер.
    const { data } = await api.post('/requisites', {
      inn: inn.value.replace(/\D/g, ''),
      bankName: bankName.value, bankBik: bankBik.value, accountNumber: accountNumber.value,
      fioMatched: innMatch.value === true,
    });
    access.value.requisitesSubmitted = true;
    // Доступ открываем сразу по факту отправки — ручную верификацию
    // финменеджера ждать не нужно (решение владельца 2026-06-23). Дальше —
    // только акцепт Оферты (глобальная OfferAcceptanceDialog в MainLayout).
    access.value.requisitesVerified = true;
    reqDialog.value = false;
    if (data?.message) showSuccess(data.message);
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось сохранить реквизиты');
  }
  savingReq.value = false;
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
      if (data.categories) allCategories.value = data.categories.map(c => ({ title: c.name, value: c.id }));
    }
    // Валюты — уникальные коды из всех продуктов. Каталог-валюты приходят
    // строками (id=null, nameRu=symbol="USD"), поэтому дедупим/фильтруем по
    // коду, а не по null-id (он схлопывал список в одну валюту), и не дублируем
    // лейбл «USD (USD)».
    const seen = new Set();
    currencyOptions.value = products.value
      .flatMap(p => p.currencies || [])
      .map(c => c.symbol || c.nameRu)
      .filter(code => code && !seen.has(code) && seen.add(code))
      .map(code => ({ id: code, label: code }));

    accessChecked.value = true;
    // После загрузки и определения access — запускаем gate
    gateIfNeeded();
  } catch {}
  loading.value = false;
}

onMounted(() => { loadProducts(); });
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
.product-hero {
  height: 140px;
  overflow: hidden;
  position: relative;
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
  text-align: center;
}
.program-row {
  background: rgba(var(--v-theme-surface-variant), 0.4);
  transition: background 0.15s;
}
.program-row:hover {
  background: rgba(var(--v-theme-primary), 0.08);
}
</style>
