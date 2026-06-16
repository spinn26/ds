<template>
  <div>
    <PageHeader title="Переводы интерфейса" icon="mdi-translate">
      <template #actions>
        <v-btn color="primary" size="small" prepend-icon="mdi-plus" @click="openCreate">Добавить</v-btn>
      </template>
    </PageHeader>

    <v-alert type="info" variant="tonal" density="comfortable" class="mb-3">
      Переопределение строк интерфейса. Ключ — путь из словаря (например
      <code>auth.login</code>), значение применяется поверх встроенного перевода
      для выбранной локали. Изменения видны после перезагрузки страницы.
    </v-alert>

    <v-card>
      <v-data-table :items="overrides" :headers="headers" density="comfortable" hover :loading="loading">
        <template #item.locale="{ value }"><v-chip size="x-small" variant="tonal">{{ value }}</v-chip></template>
        <template #item.actions="{ item }">
          <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openEdit(item)" />
          <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="remove(item)" />
        </template>
        <template #no-data><EmptyState message="Переопределений нет" /></template>
      </v-data-table>
    </v-card>

    <v-dialog v-model="dialog" max-width="560" persistent>
      <v-card>
        <v-card-title>{{ form.id ? 'Редактировать' : 'Добавить' }} перевод</v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col cols="12" sm="4">
              <v-select v-model="form.locale" :items="['ru', 'en']" label="Локаль" density="compact" :disabled="!!form.id" />
            </v-col>
            <v-col cols="12" sm="8">
              <v-text-field v-model="form.key" label="Ключ (dot-path) *" density="compact"
                :error-messages="errs.key" :disabled="!!form.id" placeholder="auth.login" />
            </v-col>
            <v-col cols="12">
              <v-textarea v-model="form.value" label="Значение" rows="3" auto-grow density="compact" />
            </v-col>
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

const headers = [
  { title: 'Локаль', key: 'locale', width: 100 },
  { title: 'Ключ', key: 'key', width: 280 },
  { title: 'Значение', key: 'value' },
  { title: '', key: 'actions', sortable: false, width: 100, align: 'end' },
];

const overrides = ref([]);
const loading = ref(false);
const dialog = ref(false);
const saving = ref(false);
const errs = reactive({});
const snack = ref({ open: false, color: 'success', text: '' });
const form = reactive(emptyForm());
function emptyForm() { return { id: null, locale: 'ru', key: '', value: '' }; }
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

async function load() {
  loading.value = true;
  try { const { data } = await api.get('/admin/translations'); overrides.value = data.overrides || []; }
  catch (e) { notify(e.response?.data?.message || 'Ошибка загрузки', 'error'); }
  loading.value = false;
}
function openCreate() { Object.assign(form, emptyForm()); Object.keys(errs).forEach(k => delete errs[k]); dialog.value = true; }
function openEdit(item) { Object.assign(form, { ...emptyForm(), ...item }); Object.keys(errs).forEach(k => delete errs[k]); dialog.value = true; }

async function save() {
  saving.value = true; Object.keys(errs).forEach(k => delete errs[k]);
  try {
    await api.post('/admin/translations', { locale: form.locale, key: form.key, value: form.value });
    dialog.value = false; await load(); notify('Сохранено (обновите страницу для применения)');
  } catch (e) {
    if (e.response?.status === 422) { const ve = e.response.data.errors || {}; for (const [k, v] of Object.entries(ve)) errs[k] = v[0]; }
    else notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  saving.value = false;
}
async function remove(item) {
  if (!confirm(`Удалить перевод «${item.key}»?`)) return;
  try { await api.delete(`/admin/translations/${item.id}`); await load(); notify('Удалено'); }
  catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
}
onMounted(load);
</script>
