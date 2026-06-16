<template>
  <div>
    <PageHeader title="Кастомные поля пользователей" icon="mdi-form-select">
      <template #actions>
        <v-btn color="primary" size="small" prepend-icon="mdi-plus" @click="openCreate">Добавить поле</v-btn>
      </template>
    </PageHeader>

    <v-alert type="info" variant="tonal" density="comfortable" class="mb-3">
      Поля показываются пользователю в профиле («Дополнительные сведения»).
      Обязательные поля нужно заполнить, чтобы сохранить.
    </v-alert>

    <v-card>
      <v-data-table :items="fields" :headers="headers" density="comfortable" hover :loading="loading">
        <template #item.type="{ value }"><v-chip size="x-small" variant="tonal">{{ typeLabel(value) }}</v-chip></template>
        <template #item.required="{ value }">
          <v-icon :color="value ? 'warning' : 'grey'" size="18">{{ value ? 'mdi-asterisk' : 'mdi-minus' }}</v-icon>
        </template>
        <template #item.active="{ value }">
          <v-icon :color="value ? 'success' : 'grey'" size="18">{{ value ? 'mdi-check-circle' : 'mdi-circle-outline' }}</v-icon>
        </template>
        <template #item.actions="{ item }">
          <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openEdit(item)" />
          <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="remove(item)" />
        </template>
        <template #no-data><EmptyState message="Полей пока нет" /></template>
      </v-data-table>
    </v-card>

    <v-dialog v-model="dialog" max-width="560" persistent>
      <v-card>
        <v-card-title>{{ form.id ? 'Редактировать' : 'Добавить' }} поле</v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col cols="12" sm="6"><v-text-field v-model="form.label" label="Название (для пользователя) *" density="compact" :error-messages="errs.label" /></v-col>
            <v-col cols="12" sm="6"><v-text-field v-model="form.key" label="Ключ (латиница) *" density="compact" :error-messages="errs.key" :disabled="!!form.id" hint="напр. inn, birth_city" persistent-hint /></v-col>
            <v-col cols="12" sm="6">
              <v-select v-model="form.type" :items="typeItems" item-title="title" item-value="value" label="Тип" density="compact" />
            </v-col>
            <v-col cols="12" sm="6"><v-text-field v-model.number="form.sort_order" type="number" label="Порядок" density="compact" /></v-col>
            <v-col v-if="form.type === 'select'" cols="12">
              <v-combobox v-model="form.options" label="Варианты (Enter для добавления)" multiple chips closable-chips density="compact" />
            </v-col>
            <v-col cols="12">
              <v-select v-model="form.roles" :items="roleOptions" item-title="title" item-value="value"
                label="Показывать ролям (пусто = всем)" multiple chips closable-chips density="compact"
                hint="Если выбраны роли — поле видят только пользователи с этими ролями" persistent-hint />
            </v-col>
            <v-col cols="12"><v-text-field v-model="form.description" label="Подсказка (необязательно)" density="compact" /></v-col>
            <v-col cols="6"><v-switch v-model="form.required" label="Обязательное" color="warning" density="compact" hide-details /></v-col>
            <v-col cols="6"><v-switch v-model="form.active" label="Активно" color="success" density="compact" hide-details /></v-col>
          </v-row>
          <div v-if="errs._" class="text-error text-caption">{{ errs._ }}</div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="dialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" @click="save">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="4000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, EmptyState } from '../../components';

const typeItems = [
  { value: 'text', title: 'Текст' },
  { value: 'textarea', title: 'Многострочный текст' },
  { value: 'number', title: 'Число' },
  { value: 'date', title: 'Дата' },
  { value: 'select', title: 'Список (выбор)' },
  { value: 'checkbox', title: 'Чекбокс (да/нет)' },
];
function typeLabel(v) { return typeItems.find(t => t.value === v)?.title || v; }

// Роли (зеркало списка из Admin/Partners.vue) для таргетинга полей.
const roleOptions = [
  { title: 'Администратор', value: 'admin' },
  { title: 'Бэкофис', value: 'backoffice' },
  { title: 'Техподдержка', value: 'support' },
  { title: 'Руководитель', value: 'head' },
  { title: 'Фин. менеджер', value: 'finance' },
  { title: 'Расчёты', value: 'calculations' },
  { title: 'Правки', value: 'corrections' },
  { title: 'Отдел обучения', value: 'education' },
  { title: 'Консультант', value: 'consultant' },
  { title: 'Зарегистрирован-Партнёр', value: 'registered' },
];

const headers = [
  { title: 'Название', key: 'label' },
  { title: 'Ключ', key: 'key', width: 160 },
  { title: 'Тип', key: 'type', width: 160 },
  { title: 'Обяз.', key: 'required', width: 80 },
  { title: 'Активно', key: 'active', width: 90 },
  { title: 'Порядок', key: 'sort_order', width: 90 },
  { title: '', key: 'actions', sortable: false, width: 100, align: 'end' },
];

const fields = ref([]);
const loading = ref(false);
const dialog = ref(false);
const saving = ref(false);
const errs = reactive({});
const snack = ref({ open: false, color: 'success', text: '' });
const form = reactive(emptyForm());

function emptyForm() {
  return { id: null, key: '', label: '', type: 'text', required: false, active: true, options: [], roles: [], description: '', sort_order: 0 };
}
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/custom-fields');
    fields.value = data.fields || [];
  } catch (e) { notify(e.response?.data?.message || 'Ошибка загрузки', 'error'); }
  loading.value = false;
}

function openCreate() {
  Object.assign(form, emptyForm());
  Object.keys(errs).forEach(k => delete errs[k]);
  dialog.value = true;
}
function openEdit(item) {
  Object.assign(form, { ...emptyForm(), ...item, options: item.options || [], roles: item.roles || [] });
  Object.keys(errs).forEach(k => delete errs[k]);
  dialog.value = true;
}

async function save() {
  saving.value = true;
  Object.keys(errs).forEach(k => delete errs[k]);
  try {
    const payload = { ...form, options: form.type === 'select' ? form.options : null };
    if (form.id) await api.put(`/admin/custom-fields/${form.id}`, payload);
    else await api.post('/admin/custom-fields', payload);
    dialog.value = false;
    await load();
    notify('Сохранено');
  } catch (e) {
    if (e.response?.status === 422) {
      const ve = e.response.data.errors || {};
      for (const [k, v] of Object.entries(ve)) errs[k] = v[0];
      errs._ = e.response.data.message;
    } else notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  saving.value = false;
}

async function remove(item) {
  if (!confirm(`Удалить поле «${item.label}»? Значения у пользователей будут удалены.`)) return;
  try {
    await api.delete(`/admin/custom-fields/${item.id}`);
    await load();
    notify('Поле удалено');
  } catch (e) { notify(e.response?.data?.message || 'Ошибка удаления', 'error'); }
}

onMounted(load);
</script>
