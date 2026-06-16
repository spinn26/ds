<template>
  <div>
    <PageHeader title="Объявления" icon="mdi-bullhorn">
      <template #actions>
        <v-btn color="primary" size="small" prepend-icon="mdi-plus" @click="openCreate">Добавить</v-btn>
      </template>
    </PageHeader>

    <v-alert type="info" variant="tonal" density="comfortable" class="mb-3">
      Объявления показываются пользователям баннером в шапке. Можно ограничить
      ролями и периодом показа.
    </v-alert>

    <v-card>
      <v-data-table :items="items" :headers="headers" density="comfortable" hover :loading="loading">
        <template #item.type="{ value }">
          <v-chip size="x-small" :color="typeColor(value)" variant="tonal">{{ typeLabel(value) }}</v-chip>
        </template>
        <template #item.active="{ value }">
          <v-icon :color="value ? 'success' : 'grey'" size="18">{{ value ? 'mdi-check-circle' : 'mdi-circle-outline' }}</v-icon>
        </template>
        <template #item.roles="{ value }">
          <span v-if="value && value.length" class="text-caption">{{ value.join(', ') }}</span>
          <span v-else class="text-medium-emphasis text-caption">все</span>
        </template>
        <template #item.period="{ item }">
          <span class="text-caption">{{ fmtPeriod(item) }}</span>
        </template>
        <template #item.actions="{ item }">
          <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openEdit(item)" />
          <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="remove(item)" />
        </template>
        <template #no-data><EmptyState message="Объявлений нет" /></template>
      </v-data-table>
    </v-card>

    <v-dialog v-model="dialog" max-width="600" persistent>
      <v-card>
        <v-card-title>{{ form.id ? 'Редактировать' : 'Добавить' }} объявление</v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col cols="12"><v-text-field v-model="form.title" label="Заголовок *" density="compact" :error-messages="errs.title" /></v-col>
            <v-col cols="12"><v-textarea v-model="form.body" label="Текст" rows="3" auto-grow density="compact" /></v-col>
            <v-col cols="12" sm="6">
              <v-select v-model="form.type" :items="typeItems" item-title="title" item-value="value" label="Тип" density="compact" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-select v-model="form.roles" :items="roleOptions" item-title="title" item-value="value"
                label="Роли (пусто = всем)" multiple chips closable-chips density="compact" />
            </v-col>
            <v-col cols="12" sm="6"><v-text-field v-model="form.starts_at" type="datetime-local" label="Показывать с" density="compact" /></v-col>
            <v-col cols="12" sm="6"><v-text-field v-model="form.ends_at" type="datetime-local" label="Показывать до" density="compact" /></v-col>
            <v-col cols="6"><v-switch v-model="form.active" label="Активно" color="success" density="compact" hide-details /></v-col>
            <v-col cols="6"><v-switch v-model="form.dismissible" label="Можно закрыть" color="primary" density="compact" hide-details /></v-col>
          </v-row>
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
  { value: 'info', title: 'Инфо' },
  { value: 'success', title: 'Успех' },
  { value: 'warning', title: 'Предупреждение' },
  { value: 'error', title: 'Ошибка' },
];
function typeLabel(v) { return typeItems.find(t => t.value === v)?.title || v; }
function typeColor(v) { return ({ info: 'info', success: 'success', warning: 'warning', error: 'error' })[v] || 'info'; }

const roleOptions = [
  { title: 'Администратор', value: 'admin' }, { title: 'Бэкофис', value: 'backoffice' },
  { title: 'Техподдержка', value: 'support' }, { title: 'Руководитель', value: 'head' },
  { title: 'Фин. менеджер', value: 'finance' }, { title: 'Расчёты', value: 'calculations' },
  { title: 'Правки', value: 'corrections' }, { title: 'Отдел обучения', value: 'education' },
  { title: 'Консультант', value: 'consultant' }, { title: 'Зарегистрирован-Партнёр', value: 'registered' },
];

const headers = [
  { title: 'Заголовок', key: 'title' },
  { title: 'Тип', key: 'type', width: 140 },
  { title: 'Роли', key: 'roles', width: 200, sortable: false },
  { title: 'Период', key: 'period', width: 220, sortable: false },
  { title: 'Активно', key: 'active', width: 90 },
  { title: '', key: 'actions', sortable: false, width: 100, align: 'end' },
];

const items = ref([]);
const loading = ref(false);
const dialog = ref(false);
const saving = ref(false);
const errs = reactive({});
const snack = ref({ open: false, color: 'success', text: '' });
const form = reactive(emptyForm());

function emptyForm() {
  return { id: null, title: '', body: '', type: 'info', roles: [], active: true, dismissible: true, starts_at: '', ends_at: '' };
}
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }
function fmtDt(v) { return v ? String(v).slice(0, 16) : ''; }
function fmtPeriod(a) {
  const f = a.starts_at ? new Date(a.starts_at).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' }) : '—';
  const t = a.ends_at ? new Date(a.ends_at).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' }) : '∞';
  return `${f} → ${t}`;
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/announcements');
    items.value = data.announcements || [];
  } catch (e) { notify(e.response?.data?.message || 'Ошибка загрузки', 'error'); }
  loading.value = false;
}

function openCreate() {
  Object.assign(form, emptyForm());
  Object.keys(errs).forEach(k => delete errs[k]);
  dialog.value = true;
}
function openEdit(item) {
  Object.assign(form, {
    ...emptyForm(), ...item, roles: item.roles || [],
    starts_at: fmtDt(item.starts_at), ends_at: fmtDt(item.ends_at),
  });
  Object.keys(errs).forEach(k => delete errs[k]);
  dialog.value = true;
}

async function save() {
  saving.value = true;
  Object.keys(errs).forEach(k => delete errs[k]);
  try {
    const payload = { ...form, starts_at: form.starts_at || null, ends_at: form.ends_at || null };
    if (form.id) await api.put(`/admin/announcements/${form.id}`, payload);
    else await api.post('/admin/announcements', payload);
    dialog.value = false;
    await load();
    notify('Сохранено');
  } catch (e) {
    if (e.response?.status === 422) {
      const ve = e.response.data.errors || {};
      for (const [k, v] of Object.entries(ve)) errs[k] = v[0];
    } else notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  saving.value = false;
}

async function remove(item) {
  if (!confirm(`Удалить объявление «${item.title}»?`)) return;
  try {
    await api.delete(`/admin/announcements/${item.id}`);
    await load();
    notify('Удалено');
  } catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
}

onMounted(load);
</script>
