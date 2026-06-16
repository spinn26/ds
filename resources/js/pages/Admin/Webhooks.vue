<template>
  <div>
    <PageHeader title="Вебхуки" icon="mdi-webhook">
      <template #actions>
        <v-btn color="primary" size="small" prepend-icon="mdi-plus" @click="openCreate">Добавить</v-btn>
      </template>
    </PageHeader>

    <v-alert type="info" variant="tonal" density="comfortable" class="mb-3">
      Исходящие вебхуки: при наступлении события платформа шлёт POST на ваш URL
      (тело подписывается HMAC-SHA256 в заголовке <code>X-DS-Signature</code>, если
      задан секрет). «Тест» отправляет пробное событие.
    </v-alert>

    <v-card>
      <v-data-table :items="webhooks" :headers="headers" density="comfortable" hover :loading="loading">
        <template #item.events="{ value }">
          <span v-if="value && value.length" class="text-caption">{{ value.join(', ') }}</span>
          <span v-else class="text-medium-emphasis text-caption">все</span>
        </template>
        <template #item.active="{ value }">
          <v-icon :color="value ? 'success' : 'grey'" size="18">{{ value ? 'mdi-check-circle' : 'mdi-circle-outline' }}</v-icon>
        </template>
        <template #item.actions="{ item }">
          <v-btn icon="mdi-send" size="x-small" variant="text" title="Тест" :loading="testing === item.id" @click="test(item)" />
          <v-btn icon="mdi-history" size="x-small" variant="text" title="Доставки" @click="showDeliveries(item)" />
          <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openEdit(item)" />
          <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="remove(item)" />
        </template>
        <template #no-data><EmptyState message="Вебхуков нет" /></template>
      </v-data-table>
    </v-card>

    <!-- Создание/редактирование -->
    <v-dialog v-model="dialog" max-width="600" persistent>
      <v-card>
        <v-card-title>{{ form.id ? 'Редактировать' : 'Добавить' }} вебхук</v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col cols="12" sm="5"><v-text-field v-model="form.name" label="Название *" density="compact" :error-messages="errs.name" /></v-col>
            <v-col cols="12" sm="7"><v-text-field v-model="form.url" label="URL *" density="compact" :error-messages="errs.url" placeholder="https://..." /></v-col>
            <v-col cols="12">
              <v-select v-model="form.events" :items="eventItems" item-title="title" item-value="value"
                label="События (пусто = все)" multiple chips closable-chips density="compact" />
            </v-col>
            <v-col cols="12" sm="8"><v-text-field v-model="form.secret" label="Секрет (для подписи)" density="compact" /></v-col>
            <v-col cols="12" sm="4"><v-switch v-model="form.active" label="Активен" color="success" density="compact" hide-details /></v-col>
          </v-row>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="dialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" @click="save">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Лог доставок -->
    <v-dialog v-model="deliveriesDialog" max-width="720">
      <v-card>
        <v-card-title class="d-flex align-center">
          Доставки
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" size="small" @click="deliveriesDialog = false" />
        </v-card-title>
        <v-card-text>
          <v-table density="compact">
            <thead><tr><th>Время</th><th>Событие</th><th>HTTP</th><th>Статус</th></tr></thead>
            <tbody>
              <tr v-for="d in deliveries" :key="d.id">
                <td class="text-caption">{{ fmt(d.created_at) }}</td>
                <td class="text-caption">{{ d.event }}</td>
                <td>{{ d.status_code ?? '—' }}</td>
                <td><v-icon size="16" :color="d.success ? 'success' : 'error'">{{ d.success ? 'mdi-check' : 'mdi-close' }}</v-icon></td>
              </tr>
              <tr v-if="!deliveries.length"><td colspan="4" class="text-center text-medium-emphasis py-3">Нет доставок</td></tr>
            </tbody>
          </v-table>
        </v-card-text>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="3500">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, EmptyState } from '../../components';

const headers = [
  { title: 'Название', key: 'name' },
  { title: 'URL', key: 'url' },
  { title: 'События', key: 'events', width: 200, sortable: false },
  { title: 'Активен', key: 'active', width: 90 },
  { title: '', key: 'actions', sortable: false, width: 160, align: 'end' },
];

const webhooks = ref([]);
const eventsCatalog = ref({});
const eventItems = computed(() => Object.entries(eventsCatalog.value).map(([value, title]) => ({ value, title })));
const loading = ref(false);
const dialog = ref(false);
const deliveriesDialog = ref(false);
const deliveries = ref([]);
const saving = ref(false);
const testing = ref(null);
const errs = reactive({});
const snack = ref({ open: false, color: 'success', text: '' });
const form = reactive(emptyForm());
function emptyForm() { return { id: null, name: '', url: '', events: [], secret: '', active: true }; }
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }
function fmt(s) { if (!s) return '—'; const d = new Date(s); return isNaN(d) ? s : d.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' }); }

async function load() {
  loading.value = true;
  try { const { data } = await api.get('/admin/webhooks'); webhooks.value = data.webhooks || []; eventsCatalog.value = data.events || {}; }
  catch (e) { notify(e.response?.data?.message || 'Ошибка загрузки', 'error'); }
  loading.value = false;
}
function openCreate() { Object.assign(form, emptyForm()); Object.keys(errs).forEach(k => delete errs[k]); dialog.value = true; }
function openEdit(item) { Object.assign(form, { ...emptyForm(), ...item, events: item.events || [] }); Object.keys(errs).forEach(k => delete errs[k]); dialog.value = true; }

async function save() {
  saving.value = true; Object.keys(errs).forEach(k => delete errs[k]);
  try {
    if (form.id) await api.put(`/admin/webhooks/${form.id}`, form);
    else await api.post('/admin/webhooks', form);
    dialog.value = false; await load(); notify('Сохранено');
  } catch (e) {
    if (e.response?.status === 422) { const ve = e.response.data.errors || {}; for (const [k, v] of Object.entries(ve)) errs[k] = v[0]; }
    else notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  saving.value = false;
}
async function test(item) {
  testing.value = item.id;
  try { const { data } = await api.post(`/admin/webhooks/${item.id}/test`); notify(data.message, data.delivery?.success ? 'success' : 'error'); }
  catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
  testing.value = null;
}
async function showDeliveries(item) {
  deliveries.value = [];
  deliveriesDialog.value = true;
  try { const { data } = await api.get(`/admin/webhooks/${item.id}/deliveries`); deliveries.value = data.deliveries || []; } catch {}
}
async function remove(item) {
  if (!confirm(`Удалить вебхук «${item.name}»?`)) return;
  try { await api.delete(`/admin/webhooks/${item.id}`); await load(); notify('Удалено'); }
  catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
}
onMounted(load);
</script>
