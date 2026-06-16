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
            <input ref="fileInput" type="file" accept="application/json" class="d-none" @change="onImportFile" />
          </div>
        </v-card>
      </v-col>

      <!-- Редактор -->
      <v-col cols="12" md="9">
        <v-card class="mb-3 pa-4">
          <div class="d-flex align-center ga-3 mb-3">
            <v-text-field v-model="form.name" label="Название шаблона" density="compact" hide-details
              style="max-width: 320px" />
            <v-spacer />
            <v-btn v-if="!isActive" color="success" variant="tonal" size="small"
              prepend-icon="mdi-check-circle" :loading="activating" @click="activate">
              Активировать
            </v-btn>
            <v-chip v-else color="success" variant="tonal" size="small">Активный шаблон</v-chip>
          </div>

          <div class="text-subtitle-2 mb-2">Логотип и бренд</div>
          <v-row dense>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.config.brandName" label="Название бренда" density="compact" hide-details />
            </v-col>
            <v-col cols="12" sm="3">
              <v-text-field v-model="form.config.logoText" label="Текст лого (если нет картинки)"
                density="compact" hide-details />
            </v-col>
            <v-col cols="12" sm="5">
              <v-text-field v-model="form.config.logoUrl" label="URL логотипа (png/svg)"
                density="compact" hide-details prepend-inner-icon="mdi-image" />
            </v-col>
          </v-row>
          <div v-if="form.config.logoUrl" class="mt-2">
            <img :src="form.config.logoUrl" alt="logo" style="max-height: 48px" />
          </div>
        </v-card>

        <v-card class="mb-3">
          <v-tabs v-model="colorTab" color="primary">
            <v-tab value="light">Светлая тема</v-tab>
            <v-tab value="dark">Тёмная тема</v-tab>
          </v-tabs>
          <v-divider />
          <v-card-text>
            <v-row dense>
              <v-col v-for="key in colorKeys" :key="key" cols="12" sm="6" md="4">
                <div class="color-row">
                  <input type="color" class="color-swatch"
                    :value="normalizeHex(form.config.colors[colorTab][key])"
                    @input="setColor(key, $event.target.value)" />
                  <v-text-field :model-value="form.config.colors[colorTab][key]"
                    @update:model-value="v => setColor(key, v)"
                    :label="colorLabels[key] || key" density="compact" hide-details
                    class="flex-grow-1" />
                </div>
              </v-col>
            </v-row>
            <div class="text-caption text-medium-emphasis mt-2">
              Изменения видны на «Предпросмотр». «Сохранить» фиксирует шаблон,
              «Активировать» применяет его всем пользователям.
            </div>
          </v-card-text>
        </v-card>

        <v-card>
          <v-card-title class="text-subtitle-2">Кастомный CSS</v-card-title>
          <v-card-text>
            <v-textarea v-model="form.config.customCss" rows="10" variant="outlined"
              hide-details class="css-editor" placeholder=".my-class { color: ... }" />
            <div class="text-caption text-medium-emphasis mt-1">
              Подключается глобально (после стилей платформы). Используйте theme-токены:
              <code>rgb(var(--v-theme-primary))</code>.
            </div>
          </v-card-text>
        </v-card>
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

const colorKeys = ['primary', 'secondary', 'tertiary', 'success', 'warning', 'error', 'info', 'background', 'surface', 'brand'];
const colorLabels = {
  primary: 'Основной', secondary: 'Вторичный', tertiary: 'Третичный',
  success: 'Успех', warning: 'Предупреждение', error: 'Ошибка', info: 'Инфо',
  background: 'Фон', surface: 'Поверхность', brand: 'Бренд (мята)',
};

const themes = ref([]);
const selectedId = ref(null);
const colorTab = ref('light');
const saving = ref(false);
const activating = ref(false);
const fileInput = ref(null);
const snack = ref({ open: false, color: 'success', text: '' });

const form = reactive({
  name: '',
  config: { brandName: '', logoText: '', logoUrl: '', colors: { light: {}, dark: {} }, customCss: '' },
});

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
  form.config = {
    brandName: c.brandName || '',
    logoText: c.logoText || '',
    logoUrl: c.logoUrl || '',
    colors: { light: { ...(c.colors?.light || {}) }, dark: { ...(c.colors?.dark || {}) } },
    customCss: c.customCss || '',
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
    const text = await file.text();
    const parsed = JSON.parse(text);
    const payload = {
      name: (parsed.name || 'Импортированный') + '',
      config: parsed.config || parsed,
    };
    const { data } = await api.post('/admin/design/themes', payload);
    await load();
    selectTemplate(data.theme);
    notify('Шаблон импортирован');
  } catch (err) {
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
  border-radius: 8px;
  padding: 0;
  background: none;
  cursor: pointer;
  flex-shrink: 0;
}
.css-editor :deep(textarea) {
  font-family: var(--ds-font-mono, monospace);
  font-size: 13px;
  line-height: 1.5;
}
</style>
