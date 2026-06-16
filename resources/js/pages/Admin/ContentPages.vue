<template>
  <div>
    <PageHeader title="Контент-страницы" icon="mdi-file-document-edit">
      <template #actions>
        <v-btn color="primary" size="small" prepend-icon="mdi-plus" @click="openCreate">Добавить страницу</v-btn>
      </template>
    </PageHeader>

    <v-alert type="info" variant="tonal" density="comfortable" class="mb-3">
      Страницы доступны пользователю по адресу <code>/page/&lt;slug&gt;</code>
      (правила, FAQ, оферта и т.п.). Текст — HTML/Markdown-разметка.
    </v-alert>

    <v-card>
      <v-data-table :items="pages" :headers="headers" density="comfortable" hover :loading="loading">
        <template #item.slug="{ value }"><code class="text-caption">/page/{{ value }}</code></template>
        <template #item.active="{ value }">
          <v-icon :color="value ? 'success' : 'grey'" size="18">{{ value ? 'mdi-check-circle' : 'mdi-circle-outline' }}</v-icon>
        </template>
        <template #item.actions="{ item }">
          <v-btn icon="mdi-open-in-new" size="x-small" variant="text" :href="`/page/${item.slug}`" target="_blank" />
          <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openEdit(item)" />
          <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="remove(item)" />
        </template>
        <template #no-data><EmptyState message="Страниц нет" /></template>
      </v-data-table>
    </v-card>

    <v-dialog v-model="dialog" max-width="760" persistent>
      <v-card>
        <v-card-title>{{ form.id ? 'Редактировать' : 'Добавить' }} страницу</v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col cols="12" sm="8"><v-text-field v-model="form.title" label="Заголовок *" density="compact" :error-messages="errs.title" /></v-col>
            <v-col cols="12" sm="4"><v-text-field v-model="form.slug" label="Slug (латиница) *" density="compact" :error-messages="errs.slug" :disabled="!!form.id" /></v-col>
            <v-col cols="12">
              <v-textarea v-model="form.body" label="Содержимое (HTML)" rows="14" variant="outlined"
                hide-details class="body-editor" />
            </v-col>
            <v-col cols="12"><v-switch v-model="form.active" label="Опубликована" color="success" density="compact" hide-details /></v-col>
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
  { title: 'Заголовок', key: 'title' },
  { title: 'Адрес', key: 'slug', width: 240 },
  { title: 'Публикация', key: 'active', width: 110 },
  { title: '', key: 'actions', sortable: false, width: 130, align: 'end' },
];

const pages = ref([]);
const loading = ref(false);
const dialog = ref(false);
const saving = ref(false);
const errs = reactive({});
const snack = ref({ open: false, color: 'success', text: '' });
const form = reactive(emptyForm());
function emptyForm() { return { id: null, slug: '', title: '', body: '', active: true }; }
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

async function load() {
  loading.value = true;
  try { const { data } = await api.get('/admin/content-pages'); pages.value = data.pages || []; }
  catch (e) { notify(e.response?.data?.message || 'Ошибка загрузки', 'error'); }
  loading.value = false;
}
function openCreate() { Object.assign(form, emptyForm()); Object.keys(errs).forEach(k => delete errs[k]); dialog.value = true; }
function openEdit(item) { Object.assign(form, { ...emptyForm(), ...item }); Object.keys(errs).forEach(k => delete errs[k]); dialog.value = true; }

async function save() {
  saving.value = true; Object.keys(errs).forEach(k => delete errs[k]);
  try {
    if (form.id) await api.put(`/admin/content-pages/${form.id}`, form);
    else await api.post('/admin/content-pages', form);
    dialog.value = false; await load(); notify('Сохранено');
  } catch (e) {
    if (e.response?.status === 422) { const ve = e.response.data.errors || {}; for (const [k, v] of Object.entries(ve)) errs[k] = v[0]; }
    else notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  saving.value = false;
}
async function remove(item) {
  if (!confirm(`Удалить страницу «${item.title}»?`)) return;
  try { await api.delete(`/admin/content-pages/${item.id}`); await load(); notify('Удалено'); }
  catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
}
onMounted(load);
</script>

<style scoped>
.body-editor :deep(textarea) { font-family: var(--ds-font-mono, monospace); font-size: 13px; line-height: 1.5; }
</style>
