<template>
  <div class="pa-4">
    <PageHeader title="Качество кода"
      subtitle="Реестр находок аудита. Живёт в БД — статус меняется здесь, без релиза.">
      <template #actions>
        <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreate">Добавить</v-btn>
      </template>
    </PageHeader>

    <!-- Сводка -->
    <v-row class="mb-2" dense>
      <v-col cols="6" sm="3">
        <v-card variant="tonal" color="success" class="pa-3">
          <div class="text-h4 font-weight-bold">{{ counts.fixed }}</div>
          <div class="text-body-2">Исправлено</div>
        </v-card>
      </v-col>
      <v-col v-for="s in severityOrder" :key="s" cols="6" sm="3">
        <v-card variant="tonal" :color="sevColor(s)" class="pa-3">
          <div class="text-h4 font-weight-bold" style="font-variant-numeric:tabular-nums">{{ counts.openBySeverity[s] || 0 }}</div>
          <div class="text-body-2">Открыто · {{ sevLabel(s) }}</div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Автоматические инструменты -->
    <v-row class="mb-4" dense>
      <v-col cols="12" md="4">
        <v-card variant="outlined" class="pa-3 h-100">
          <div class="d-flex align-center ga-2 mb-1"><v-icon color="warning">mdi-code-braces</v-icon>
            <span class="text-subtitle-2 font-weight-bold">PHPStan / larastan</span></div>
          <div class="text-body-2 text-medium-emphasis">Baseline: <strong>230</strong> ошибок в 192 блоках (66 nullsafe.neverNull, 50 property.notFound, 18 nullCoalesce.offset — в основном косметика). Гейтит деплой. Отдельно: phpstan.neon глушит property.notFound и method.notFound глобально по app/ — шире baseline, см. INF-2.</div>
        </v-card>
      </v-col>
      <v-col cols="12" md="4">
        <v-card variant="outlined" class="pa-3 h-100">
          <div class="d-flex align-center ga-2 mb-1"><v-icon color="info">mdi-format-paint</v-icon>
            <span class="text-subtitle-2 font-weight-bold">Pint (стиль)</span></div>
          <div class="text-body-2 text-medium-emphasis"><strong>~306</strong> файлов с отклонениями стиля (косметика). В CI намеренно не включён. Фикс: <code>vendor/bin/pint</code> отдельным коммитом + <code>pint --test</code> в CI.</div>
        </v-card>
      </v-col>
      <v-col cols="12" md="4">
        <v-card variant="outlined" class="pa-3 h-100">
          <div class="d-flex align-center ga-2 mb-1"><v-icon color="success">mdi-test-tube</v-icon>
            <span class="text-subtitle-2 font-weight-bold">Тесты</span></div>
          <div class="text-body-2 text-medium-emphasis"><strong>436</strong> тест-методов, 11 480 строк (18% от кода). 41 characterization-тест закрывает денежные пути: каскад комиссий, пул, штрафы, статусы, импорт. Гейтит деплой наравне с PHPStan (<code>deploy: needs [quality, tests]</code>). Тестовая БД поднимается из <code>database/schema/pgsql-schema.sql</code>.</div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Фильтры -->
    <v-card class="mb-4 pa-3" variant="tonal">
      <div class="d-flex align-center flex-wrap ga-3">
        <v-btn-toggle v-model="view" mandatory density="comfortable" color="primary">
          <v-btn value="open" size="small">Открытые ({{ counts.open }})</v-btn>
          <v-btn value="fixed" size="small">Исправленные ({{ counts.fixed }})</v-btn>
        </v-btn-toggle>
        <v-select v-model="filterCategory" :items="categories" label="Категория" density="compact"
          variant="outlined" hide-details clearable multiple chips style="min-width:240px;max-width:420px"
          prepend-inner-icon="mdi-filter-variant" />
        <v-text-field v-model="search" placeholder="Поиск" density="compact" variant="outlined"
          hide-details clearable prepend-inner-icon="mdi-magnify" style="min-width:200px" />
        <v-spacer />
        <span class="text-caption text-medium-emphasis">Показано: {{ filtered.length }}</span>
      </div>
    </v-card>

    <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-4" />

    <!-- Топ-приоритет (только для открытых) -->
    <v-alert v-if="view === 'open' && !filterActive && topPriority.length" type="error" variant="tonal" class="mb-4"
      density="comfortable" icon="mdi-alert-octagon">
      <div class="font-weight-bold mb-1">Топ-приоритет (исправить в первую очередь)</div>
      <ul class="ms-4">
        <li v-for="f in topPriority" :key="f.id"><strong>{{ f.title }}</strong> — <span class="text-medium-emphasis">{{ f.file }}</span></li>
      </ul>
    </v-alert>

    <!-- Список по категориям -->
    <template v-for="cat in groupedCategories" :key="cat">
      <div class="d-flex align-center ga-2 mt-4 mb-2">
        <v-icon :icon="catIcon(cat)" size="20" />
        <span class="text-h6">{{ cat }}</span>
        <v-chip size="x-small" variant="tonal">{{ grouped[cat].length }}</v-chip>
      </div>
      <v-card variant="outlined" class="mb-2">
        <v-expansion-panels multiple variant="accordion">
          <v-expansion-panel v-for="f in grouped[cat]" :key="f.rowId">
            <v-expansion-panel-title>
              <div class="d-flex align-center ga-3 flex-wrap" style="width:100%">
                <v-chip :color="f.status === 'fixed' ? 'success' : sevColor(f.severity)" size="small" variant="flat" label>
                  <v-icon v-if="f.status === 'fixed'" start size="14">mdi-check</v-icon>{{ f.status === 'fixed' ? 'Исправлено' : sevLabel(f.severity) }}
                </v-chip>
                <code class="text-caption text-medium-emphasis">{{ f.id }}</code>
                <span class="font-weight-medium">{{ f.title }}</span>
                <v-spacer />
                <code class="text-caption text-medium-emphasis">{{ f.file }}</code>
              </div>
            </v-expansion-panel-title>
            <v-expansion-panel-text>
              <div class="mb-2"><div class="text-caption text-medium-emphasis mb-1">{{ f.status === 'fixed' ? 'Что было' : 'Проблема' }}</div>
                <div class="text-body-2">{{ f.problem }}</div></div>
              <div class="mb-3"><div class="text-caption text-medium-emphasis mb-1">{{ f.status === 'fixed' ? 'Как исправлено' : 'Рекомендация' }}</div>
                <div class="text-body-2" :class="f.status === 'fixed' ? 'text-success' : 'text-info'">{{ f.recommendation }}</div></div>
              <div class="d-flex align-center ga-2 flex-wrap">
                <v-btn size="small" variant="tonal"
                  :color="f.status === 'fixed' ? 'warning' : 'success'"
                  :prepend-icon="f.status === 'fixed' ? 'mdi-undo' : 'mdi-check'"
                  :loading="busyId === f.rowId" @click="toggle(f)">
                  {{ f.status === 'fixed' ? 'Вернуть в открытые' : 'Отметить исправленной' }}
                </v-btn>
                <v-btn size="small" variant="text" prepend-icon="mdi-pencil" @click="openEdit(f)">Править</v-btn>
                <v-btn size="small" variant="text" color="error" prepend-icon="mdi-delete" @click="askRemove(f)">Удалить</v-btn>
                <v-spacer />
                <span v-if="f.closedAt" class="text-caption text-medium-emphasis">Закрыто: {{ fmtDate(f.closedAt) }}</span>
              </div>
            </v-expansion-panel-text>
          </v-expansion-panel>
        </v-expansion-panels>
      </v-card>
    </template>

    <EmptyState v-if="!loading && !filtered.length" icon="mdi-clipboard-check-outline"
      title="Находок нет" text="По текущему фильтру ничего не найдено." />

    <div class="text-caption text-medium-emphasis mt-6">
      Реестр хранится в таблице <code>code_findings</code>. Стартовый снимок — <code>database/data/code-findings.json</code>.
      Денежные пункты помечены категорией — их правка требует подтверждения финансов.
    </div>

    <!-- Диалог создания/правки -->
    <v-dialog v-model="dialog" max-width="720" scrollable>
      <v-card>
        <v-card-title>{{ form.rowId ? 'Правка находки' : 'Новая находка' }}</v-card-title>
        <v-card-text>
          <div class="d-flex ga-3 flex-wrap">
            <v-text-field v-model="form.code" label="Код (SEC-1)" density="compact" variant="outlined"
              style="min-width:160px" :error-messages="errors.code" />
            <v-select v-model="form.severity" :items="severityOrder" label="Важность" density="compact"
              variant="outlined" style="min-width:160px" :error-messages="errors.severity" />
            <v-select v-model="form.status" :items="statusItems" label="Статус" density="compact"
              variant="outlined" style="min-width:160px" :error-messages="errors.status" />
          </div>
          <v-combobox v-model="form.category" :items="categories" label="Категория" density="compact"
            variant="outlined" class="mt-2" :error-messages="errors.category" />
          <v-text-field v-model="form.title" label="Заголовок" density="compact" variant="outlined"
            class="mt-2" :error-messages="errors.title" />
          <v-text-field v-model="form.file" label="Файл и строки (необязательно)" density="compact"
            variant="outlined" class="mt-2" :error-messages="errors.file" />
          <v-textarea v-model="form.problem" label="Проблема" rows="4" density="compact" variant="outlined"
            class="mt-2" :error-messages="errors.problem" />
          <v-textarea v-model="form.recommendation" label="Рекомендация" rows="3" density="compact"
            variant="outlined" class="mt-2" :error-messages="errors.recommendation" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="dialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" @click="save">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Подтверждение удаления -->
    <v-dialog v-model="removeDialog" max-width="460">
      <v-card>
        <v-card-title>Удалить находку?</v-card-title>
        <v-card-text>
          <strong>{{ pendingRemove?.id }}</strong> — {{ pendingRemove?.title }}.
          Действие необратимо.
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="removeDialog = false">Отмена</v-btn>
          <v-btn color="error" :loading="saving" @click="remove">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="3000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, EmptyState } from '../../components';

const severityOrder = ['critical', 'high', 'medium', 'low'];
const statusItems = [
  { title: 'Открыта', value: 'open' },
  { title: 'Исправлена', value: 'fixed' },
];

const sevLabels = { critical: 'Критично', high: 'Высокий', medium: 'Средний', low: 'Низкий' };
const sevColors = { critical: 'error', high: 'error', medium: 'warning', low: 'info' };
const sevLabel = (s) => sevLabels[s] || s;
const sevColor = (s) => sevColors[s] || 'grey';

const catIcons = {
  'Безопасность': 'mdi-shield-alert',
  'Контроллеры / API': 'mdi-api',
  'Бизнес-логика (деньги)': 'mdi-cash-multiple',
  'Frontend (Vue)': 'mdi-language-javascript',
  'БД и модели': 'mdi-database',
  'Деньги · ждут финансов': 'mdi-account-cash',
  'Импорт транзакций': 'mdi-file-import',
  'Инфраструктура': 'mdi-server',
  'Данные · каталог продуктов': 'mdi-package-variant',
};
const catIcon = (c) => catIcons[c] || 'mdi-alert-circle-outline';

const findings = ref([]);
const categories = ref([]);
const counts = ref({ open: 0, fixed: 0, openBySeverity: {} });
const loading = ref(false);
const saving = ref(false);
const busyId = ref(null);

const view = ref('open');
const filterCategory = ref([]);
const search = ref('');

const dialog = ref(false);
const removeDialog = ref(false);
const pendingRemove = ref(null);
const errors = reactive({});
const form = reactive({
  rowId: null, code: '', severity: 'medium', category: '',
  title: '', file: '', problem: '', recommendation: '', status: 'open',
});

const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

function fmtDate(v) {
  if (!v) return '';
  const d = new Date(v);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString('ru-RU');
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/code-findings');
    findings.value = data.data || [];
    categories.value = data.categories || [];
    counts.value = data.counts || { open: 0, fixed: 0, openBySeverity: {} };
  } catch (e) {
    notify(e.response?.data?.message || 'Не удалось загрузить реестр', 'error');
  }
  loading.value = false;
}

const filterActive = computed(() => filterCategory.value.length || (search.value || '').trim());

const filtered = computed(() => {
  const q = (search.value || '').trim().toLowerCase();
  return findings.value.filter((f) => {
    if (f.status !== view.value) return false;
    if (filterCategory.value.length && !filterCategory.value.includes(f.category)) return false;
    if (!q) return true;
    return [f.id, f.title, f.file, f.problem, f.recommendation]
      .some((v) => (v || '').toLowerCase().includes(q));
  });
});

const grouped = computed(() => {
  const g = {};
  for (const f of filtered.value) (g[f.category] ||= []).push(f);
  return g;
});

const groupedCategories = computed(() => Object.keys(grouped.value).sort());

const topPriority = computed(() => findings.value
  .filter((f) => f.status === 'open' && (f.severity === 'high' || f.severity === 'critical'))
  .slice(0, 8));

function resetErrors() { Object.keys(errors).forEach((k) => delete errors[k]); }

function openCreate() {
  resetErrors();
  Object.assign(form, {
    rowId: null, code: '', severity: 'medium', category: '',
    title: '', file: '', problem: '', recommendation: '', status: 'open',
  });
  dialog.value = true;
}

function openEdit(f) {
  resetErrors();
  Object.assign(form, {
    rowId: f.rowId, code: f.id, severity: f.severity, category: f.category,
    title: f.title, file: f.file || '', problem: f.problem,
    recommendation: f.recommendation, status: f.status,
  });
  dialog.value = true;
}

async function save() {
  saving.value = true;
  resetErrors();
  const payload = {
    code: form.code,
    severity: form.severity,
    category: form.category,
    title: form.title,
    file: form.file || null,
    problem: form.problem,
    recommendation: form.recommendation,
    status: form.status,
  };
  try {
    if (form.rowId) await api.put(`/admin/code-findings/${form.rowId}`, payload);
    else await api.post('/admin/code-findings', payload);
    dialog.value = false;
    notify('Сохранено');
    await load();
  } catch (e) {
    // 422 — показываем ошибки прямо у полей, остальное в снекбар.
    if (e.response?.status === 422) {
      const bag = e.response.data?.errors || {};
      Object.keys(bag).forEach((k) => { errors[k] = bag[k]; });
      notify('Проверьте поля формы', 'error');
    } else {
      notify(e.response?.data?.message || 'Ошибка сохранения', 'error');
    }
  }
  saving.value = false;
}

function askRemove(f) {
  pendingRemove.value = f;
  removeDialog.value = true;
}

async function remove() {
  saving.value = true;
  try {
    await api.delete(`/admin/code-findings/${pendingRemove.value.rowId}`);
    removeDialog.value = false;
    notify('Удалено');
    await load();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка удаления', 'error');
  }
  saving.value = false;
}

async function toggle(f) {
  busyId.value = f.rowId;
  try {
    await api.post(`/admin/code-findings/${f.rowId}/toggle`);
    await load();
  } catch (e) {
    notify(e.response?.data?.message || 'Не удалось изменить статус', 'error');
  }
  busyId.value = null;
}

onMounted(load);
</script>
