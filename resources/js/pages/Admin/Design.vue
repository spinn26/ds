<template>
  <div>
    <PageHeader title="Дизайн" icon="mdi-palette">
      <template #actions>
        <v-btn variant="tonal" size="small" prepend-icon="mdi-eye" class="mr-2" @click="preview">
          Предпросмотр
        </v-btn>
        <v-btn color="primary" size="small" prepend-icon="mdi-content-save" :loading="saving" @click="save">
          Сохранить
        </v-btn>
      </template>
    </PageHeader>

    <v-row>
      <!-- Шаблоны -->
      <v-col cols="12" md="3">
        <v-card>
          <v-card-title class="text-subtitle-2 d-flex align-center">
            Шаблоны
            <v-spacer />
            <v-btn icon="mdi-plus" size="x-small" variant="text" title="Создать копию" @click="createCopy" />
          </v-card-title>
          <v-list density="compact" nav>
            <v-list-item v-for="t in themes" :key="t.id" :active="t.id === selectedId"
              @click="selectTemplate(t)">
              <v-list-item-title>{{ t.name }}</v-list-item-title>
              <template #append>
                <v-chip v-if="t.is_active" size="x-small" color="success" variant="tonal">активен</v-chip>
              </template>
            </v-list-item>
          </v-list>
          <v-divider />
          <div class="d-flex flex-wrap ga-1 pa-2">
            <v-btn size="x-small" variant="text" prepend-icon="mdi-import" @click="triggerImport">Импорт</v-btn>
            <v-btn size="x-small" variant="text" prepend-icon="mdi-export" @click="exportTpl">Экспорт</v-btn>
            <v-btn v-if="selectedId && !isActive" size="x-small" variant="text" color="error"
              prepend-icon="mdi-delete" @click="remove">Удалить</v-btn>
            <input ref="fileInput" type="file" accept="application/json" class="d-none" @change="onImportFile" />
          </div>
        </v-card>
      </v-col>

      <!-- Редактор -->
      <v-col cols="12" md="9">
        <v-card class="mb-3 pa-3">
          <div class="d-flex align-center ga-3">
            <v-text-field v-model="form.name" label="Название шаблона" density="compact" hide-details
              style="max-width: 320px" />
            <v-spacer />
            <v-btn v-if="!isActive" color="success" variant="tonal" size="small"
              prepend-icon="mdi-check-circle" :loading="activating" @click="activate">
              Активировать
            </v-btn>
            <v-chip v-else color="success" variant="tonal" size="small">Активный шаблон</v-chip>
          </div>
        </v-card>

        <v-expansion-panels v-model="openPanels" multiple class="design-panels">
          <!-- Бренд и основные -->
          <v-expansion-panel value="brand">
            <v-expansion-panel-title>
              <v-icon start size="20">mdi-image-text</v-icon> Бренд и основные настройки
            </v-expansion-panel-title>
            <v-expansion-panel-text>
              <v-row dense>
                <v-col cols="12" sm="6"><v-text-field v-model="form.config.brandName" label="Название бренда" density="compact" hide-details /></v-col>
                <v-col cols="12" sm="6"><v-text-field v-model="form.config.logoText" label="Текст лого (если нет картинки)" density="compact" hide-details /></v-col>
                <v-col cols="12" sm="6"><v-text-field v-model="form.config.logoUrl" label="URL логотипа (png/svg)" density="compact" hide-details prepend-inner-icon="mdi-image" /></v-col>
                <v-col cols="12" sm="6"><v-text-field v-model="form.config.faviconUrl" label="URL фавикона" density="compact" hide-details prepend-inner-icon="mdi-web" /></v-col>
                <v-col cols="12"><v-text-field v-model="form.config.loginTitle" label="Заголовок страницы входа" density="compact" hide-details placeholder="напр. «Партнёрская платформа DS»" /></v-col>
              </v-row>
              <div v-if="form.config.logoUrl" class="mt-2">
                <span class="text-caption text-medium-emphasis mr-2">Лого:</span>
                <img :src="form.config.logoUrl" alt="logo" style="max-height: 40px; vertical-align: middle" />
              </div>
            </v-expansion-panel-text>
          </v-expansion-panel>

          <!-- Цвета -->
          <v-expansion-panel value="colors">
            <v-expansion-panel-title>
              <v-icon start size="20">mdi-palette</v-icon> Цвета тем
            </v-expansion-panel-title>
            <v-expansion-panel-text>
              <v-tabs v-model="colorTab" color="primary" class="mb-2">
                <v-tab value="light">Светлая тема</v-tab>
                <v-tab value="dark">Тёмная тема</v-tab>
              </v-tabs>
              <v-row dense>
                <v-col v-for="key in colorKeys" :key="key" cols="12" sm="6" md="4">
                  <div class="color-row">
                    <input type="color" class="color-swatch"
                      :value="normalizeHex(form.config.colors[colorTab][key])"
                      @input="setColor(key, $event.target.value)" />
                    <v-text-field :model-value="form.config.colors[colorTab][key]"
                      @update:model-value="v => setColor(key, v)"
                      :label="colorLabels[key] || key" density="compact" hide-details class="flex-grow-1" />
                  </div>
                </v-col>
              </v-row>
            </v-expansion-panel-text>
          </v-expansion-panel>

          <!-- Типографика -->
          <v-expansion-panel value="typography">
            <v-expansion-panel-title>
              <v-icon start size="20">mdi-format-font</v-icon> Типографика
            </v-expansion-panel-title>
            <v-expansion-panel-text>
              <v-row dense>
                <v-col cols="12" sm="8">
                  <v-combobox v-model="form.config.typography.fontFamily" :items="fontPresets"
                    label="Семейство шрифтов (CSS font-family)" density="compact" hide-details />
                </v-col>
                <v-col cols="12" sm="4">
                  <v-text-field v-model.number="form.config.typography.baseSize" type="number"
                    label="Базовый размер (px)" density="compact" hide-details suffix="px" />
                </v-col>
              </v-row>
              <div class="preview-type mt-3" :style="{ fontFamily: form.config.typography.fontFamily || undefined, fontSize: (form.config.typography.baseSize || 14) + 'px' }">
                Пример текста — The quick brown fox. Съешь ещё этих мягких булок. 0123456789
              </div>
            </v-expansion-panel-text>
          </v-expansion-panel>

          <!-- Скругления -->
          <v-expansion-panel value="radius">
            <v-expansion-panel-title>
              <v-icon start size="20">mdi-rounded-corner</v-icon> Скругления (радиусы)
            </v-expansion-panel-title>
            <v-expansion-panel-text>
              <v-row dense>
                <v-col v-for="k in ['sm','md','lg','xl']" :key="k" cols="6" sm="3">
                  <v-text-field v-model.number="form.config.radius[k]" type="number"
                    :label="'radius ' + k" density="compact" hide-details suffix="px" />
                </v-col>
              </v-row>
              <div class="d-flex ga-3 mt-3">
                <div v-for="k in ['sm','md','lg','xl']" :key="k" class="radius-demo"
                  :style="{ borderRadius: (form.config.radius[k] || 0) + 'px' }">{{ k }}</div>
              </div>
              <div class="text-caption text-medium-emphasis mt-2">
                Применяется к карточкам (lg), кнопкам и полям (md) и DS-токенам --ds-radius-*.
              </div>
            </v-expansion-panel-text>
          </v-expansion-panel>

          <!-- Кастомный CSS -->
          <v-expansion-panel value="css">
            <v-expansion-panel-title>
              <v-icon start size="20">mdi-language-css3</v-icon> Кастомный CSS
            </v-expansion-panel-title>
            <v-expansion-panel-text>
              <v-textarea v-model="form.config.customCss" rows="10" variant="outlined" hide-details
                class="css-editor" placeholder=".my-class { color: rgb(var(--v-theme-primary)); }" />
              <div class="text-caption text-medium-emphasis mt-1">
                Подключается глобально (после стилей платформы). Используйте theme-токены:
                <code>rgb(var(--v-theme-primary))</code>, <code>var(--ds-radius-md)</code>.
              </div>
            </v-expansion-panel-text>
          </v-expansion-panel>
        </v-expansion-panels>

        <div class="text-caption text-medium-emphasis mt-3">
          «Предпросмотр» применяет изменения без сохранения. «Сохранить» фиксирует шаблон,
          «Активировать» применяет его всем пользователям.
        </div>
      </v-col>
    </v-row>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="4000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useTheme } from 'vuetify';
import api from '../../api';
import { PageHeader } from '../../components';
import { useDesignStore } from '../../stores/design';

const theme = useTheme();
const design = useDesignStore();

const colorKeys = [
  'primary', 'secondary', 'tertiary', 'success', 'warning', 'error', 'info',
  'background', 'surface', 'on-surface', 'on-surface-variant', 'surface-variant',
  'outline', 'outline-variant', 'brand', 'brand-ink',
];
const colorLabels = {
  primary: 'Основной', secondary: 'Вторичный', tertiary: 'Третичный',
  success: 'Успех', warning: 'Предупреждение', error: 'Ошибка', info: 'Инфо',
  background: 'Фон', surface: 'Поверхность',
  'on-surface': 'Текст на поверхности', 'on-surface-variant': 'Текст вторичный',
  'surface-variant': 'Поверхность-вариант', outline: 'Обводка',
  'outline-variant': 'Обводка-вариант', brand: 'Бренд (мята)', 'brand-ink': 'Бренд-текст',
};
const fontPresets = [
  "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, system-ui, sans-serif",
  "'Roboto', system-ui, sans-serif",
  "'Open Sans', system-ui, sans-serif",
  "'Montserrat', system-ui, sans-serif",
  "Georgia, 'Times New Roman', serif",
  "'JetBrains Mono', monospace",
];

const themes = ref([]);
const selectedId = ref(null);
const colorTab = ref('light');
const openPanels = ref(['brand', 'colors']);
const saving = ref(false);
const activating = ref(false);
const fileInput = ref(null);
const snack = ref({ open: false, color: 'success', text: '' });

const form = reactive({
  name: '',
  config: emptyConfig(),
});

function emptyConfig() {
  return {
    brandName: '', logoText: '', logoUrl: '', faviconUrl: '', loginTitle: '',
    colors: { light: {}, dark: {} },
    typography: { fontFamily: '', baseSize: 14 },
    radius: { sm: 6, md: 8, lg: 12, xl: 16 },
    customCss: '',
  };
}

const isActive = computed(() => themes.value.find(t => t.id === selectedId.value)?.is_active);

function notify(text, color = 'success') { snack.value = { open: true, color, text }; }
function normalizeHex(v) { return /^#[0-9a-fA-F]{6}$/.test(v || '') ? v : '#000000'; }

function setColor(key, val) {
  if (!form.config.colors[colorTab.value]) form.config.colors[colorTab.value] = {};
  form.config.colors[colorTab.value][key] = val;
}

function fillForm(t) {
  form.name = t.name;
  const c = t.config || {};
  const base = emptyConfig();
  form.config = {
    ...base,
    ...c,
    colors: { light: { ...(c.colors?.light || {}) }, dark: { ...(c.colors?.dark || {}) } },
    typography: { ...base.typography, ...(c.typography || {}) },
    radius: { ...base.radius, ...(c.radius || {}) },
  };
}

function selectTemplate(t) {
  selectedId.value = t.id;
  fillForm(t);
}

function preview() {
  design.applyConfig({ theme }, form.config);
  notify('Предпросмотр применён (без сохранения)');
}

async function load() {
  try {
    const { data } = await api.get('/admin/design/themes');
    themes.value = data.themes || [];
    const active = themes.value.find(t => t.is_active) || themes.value[0];
    if (active) selectTemplate(active);
  } catch (e) { notify(e.response?.data?.message || 'Ошибка загрузки', 'error'); }
}

async function save() {
  saving.value = true;
  try {
    if (selectedId.value) {
      await api.put(`/admin/design/themes/${selectedId.value}`, { name: form.name, config: form.config });
    } else {
      const { data } = await api.post('/admin/design/themes', { name: form.name, config: form.config });
      selectedId.value = data.theme.id;
    }
    await load();
    notify('Шаблон сохранён');
  } catch (e) { notify(e.response?.data?.message || 'Ошибка сохранения', 'error'); }
  saving.value = false;
}

async function activate() {
  activating.value = true;
  try {
    await save();
    await api.post(`/admin/design/themes/${selectedId.value}/activate`);
    design.config = { ...design.config, ...form.config };
    design.applyConfig({ theme }, form.config);
    await load();
    notify('Шаблон активирован');
  } catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
  activating.value = false;
}

async function createCopy() {
  saving.value = true;
  try {
    const { data } = await api.post('/admin/design/themes', {
      name: (form.name || 'Шаблон') + ' (копия)', config: form.config,
    });
    await load();
    selectTemplate(data.theme);
    notify('Создан новый шаблон');
  } catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
  saving.value = false;
}

async function remove() {
  if (!selectedId.value) return;
  try {
    await api.delete(`/admin/design/themes/${selectedId.value}`);
    selectedId.value = null;
    await load();
    notify('Шаблон удалён');
  } catch (e) { notify(e.response?.data?.message || 'Ошибка удаления', 'error'); }
}

function exportTpl() {
  const blob = new Blob([JSON.stringify({ name: form.name, config: form.config }, null, 2)],
    { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `design-${(form.name || 'template').replace(/\s+/g, '-')}.json`;
  a.click();
  URL.revokeObjectURL(url);
}

function triggerImport() { fileInput.value?.click(); }

async function onImportFile(e) {
  const file = e.target.files?.[0];
  if (!file) return;
  try {
    const parsed = JSON.parse(await file.text());
    const { data } = await api.post('/admin/design/themes', {
      name: (parsed.name || 'Импортированный') + '',
      config: parsed.config || parsed,
    });
    await load();
    selectTemplate(data.theme);
    notify('Шаблон импортирован');
  } catch {
    notify('Не удалось импортировать (неверный JSON)', 'error');
  }
  e.target.value = '';
}

onMounted(load);
</script>

<style scoped>
.color-row { display: flex; align-items: center; gap: 8px; }
.color-swatch {
  width: 38px; height: 38px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.2);
  border-radius: 8px; padding: 0; background: none; cursor: pointer; flex-shrink: 0;
}
.css-editor :deep(textarea) {
  font-family: var(--ds-font-mono, monospace); font-size: 13px; line-height: 1.5;
}
.preview-type {
  padding: 14px 16px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 8px;
  background: rgba(var(--v-theme-on-surface), 0.02);
}
.radius-demo {
  width: 64px; height: 64px;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; color: rgb(var(--v-theme-on-surface));
  background: rgba(var(--v-theme-primary), 0.12);
  border: 1px solid rgba(var(--v-theme-primary), 0.3);
}
.design-panels :deep(.v-expansion-panel-title) { font-weight: 600; }
</style>
