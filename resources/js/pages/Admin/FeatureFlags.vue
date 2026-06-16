<template>
  <div>
    <PageHeader title="Фиче-флаги" icon="mdi-flag-variant">
      <template #actions>
        <v-btn color="primary" size="small" prepend-icon="mdi-plus" @click="openCreate">Добавить флаг</v-btn>
      </template>
    </PageHeader>

    <v-alert type="info" variant="tonal" density="comfortable" class="mb-3">
      Включение/выключение функционала. В коде проверяется
      <code>FeatureFlag::enabled('key', $user)</code>, на фронте — через <code>/features</code>.
    </v-alert>

    <v-card>
      <v-data-table :items="flags" :headers="headers" density="comfortable" hover :loading="loading">
        <template #item.enabled="{ item }">
          <v-switch :model-value="item.enabled" color="success" density="compact" hide-details inset
            @update:model-value="v => toggle(item, v)" />
        </template>
        <template #item.roles="{ value }">
          <span v-if="value && value.length" class="text-caption">{{ value.join(', ') }}</span>
          <span v-else class="text-medium-emphasis text-caption">все</span>
        </template>
        <template #item.actions="{ item }">
          <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openEdit(item)" />
          <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="remove(item)" />
        </template>
        <template #no-data><EmptyState message="Флагов нет" /></template>
      </v-data-table>
    </v-card>

    <v-dialog v-model="dialog" max-width="520" persistent>
      <v-card>
        <v-card-title>{{ form.id ? 'Редактировать' : 'Добавить' }} флаг</v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col cols="12" sm="6"><v-text-field v-model="form.label" label="Название *" density="compact" :error-messages="errs.label" /></v-col>
            <v-col cols="12" sm="6"><v-text-field v-model="form.key" label="Ключ (латиница) *" density="compact" :error-messages="errs.key" :disabled="!!form.id" /></v-col>
            <v-col cols="12"><v-text-field v-model="form.description" label="Описание" density="compact" /></v-col>
            <v-col cols="12">
              <v-select v-model="form.roles" :items="roleOptions" item-title="title" item-value="value"
                label="Роли (пусто = всем)" multiple chips closable-chips density="compact" />
            </v-col>
            <v-col cols="12"><v-switch v-model="form.enabled" label="Включён" color="success" density="compact" hide-details /></v-col>
          </v-row>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="dialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" @click="save">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="3500">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, EmptyState } from '../../components';

const roleOptions = [
  { title: 'Администратор', value: 'admin' }, { title: 'Бэкофис', value: 'backoffice' },
  { title: 'Техподдержка', value: 'support' }, { title: 'Руководитель', value: 'head' },
  { title: 'Фин. менеджер', value: 'finance' }, { title: 'Расчёты', value: 'calculations' },
  { title: 'Правки', value: 'corrections' }, { title: 'Отдел обучения', value: 'education' },
  { title: 'Консультант', value: 'consultant' }, { title: 'Зарегистрирован-Партнёр', value: 'registered' },
];
const headers = [
  { title: 'Название', key: 'label' },
  { title: 'Ключ', key: 'key', width: 180 },
  { title: 'Роли', key: 'roles', width: 220, sortable: false },
  { title: 'Включён', key: 'enabled', width: 110 },
  { title: '', key: 'actions', sortable: false, width: 100, align: 'end' },
];

const flags = ref([]);
const loading = ref(false);
const dialog = ref(false);
const saving = ref(false);
const errs = reactive({});
const snack = ref({ open: false, color: 'success', text: '' });
const form = reactive(emptyForm());
function emptyForm() { return { id: null, key: '', label: '', description: '', enabled: false, roles: [] }; }
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

async function load() {
  loading.value = true;
  try { const { data } = await api.get('/admin/feature-flags'); flags.value = data.flags || []; }
  catch (e) { notify(e.response?.data?.message || 'Ошибка загрузки', 'error'); }
  loading.value = false;
}
function openCreate() { Object.assign(form, emptyForm()); Object.keys(errs).forEach(k => delete errs[k]); dialog.value = true; }
function openEdit(item) { Object.assign(form, { ...emptyForm(), ...item, roles: item.roles || [] }); Object.keys(errs).forEach(k => delete errs[k]); dialog.value = true; }

async function save() {
  saving.value = true; Object.keys(errs).forEach(k => delete errs[k]);
  try {
    if (form.id) await api.put(`/admin/feature-flags/${form.id}`, form);
    else await api.post('/admin/feature-flags', form);
    dialog.value = false; await load(); notify('Сохранено');
  } catch (e) {
    if (e.response?.status === 422) { const ve = e.response.data.errors || {}; for (const [k, v] of Object.entries(ve)) errs[k] = v[0]; }
    else notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  saving.value = false;
}
async function toggle(item, v) {
  try { await api.put(`/admin/feature-flags/${item.id}`, { ...item, enabled: v }); item.enabled = v; notify('Сохранено'); }
  catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
}
async function remove(item) {
  if (!confirm(`Удалить флаг «${item.label}»?`)) return;
  try { await api.delete(`/admin/feature-flags/${item.id}`); await load(); notify('Удалено'); }
  catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
}
onMounted(load);
</script>
