<template>
  <div>
    <div class="d-flex justify-space-between align-center mb-4">
      <div class="d-flex align-center ga-2">
        <v-icon size="32" color="primary">mdi-newspaper</v-icon>
        <h5 class="text-h5 font-weight-bold">Новости и объявления</h5>
      </div>
      <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreate">Добавить</v-btn>
    </div>

    <v-card :loading="loading">
      <v-data-table :items="items" :headers="headers" density="compact" hover no-data-text="Нет новостей">
        <template #item.type="{ value }">
          <v-chip size="x-small" :color="value === 'warning' ? 'warning' : value === 'success' ? 'success' : 'primary'">
            {{ { info: 'Инфо', warning: 'Важно', success: 'Успех' }[value] || value }}
          </v-chip>
        </template>
        <template #item.active="{ value }">
          <v-icon :color="value ? 'success' : 'grey'" size="small">{{ value ? 'mdi-check-circle' : 'mdi-minus-circle' }}</v-icon>
        </template>
        <template #item.createdAt="{ value }">{{ fmtDate(value) }}</template>
        <template #item.content="{ value }">
          <span class="text-body-2">{{ value?.length > 80 ? value.slice(0, 80) + '...' : value }}</span>
        </template>
        <template #item.actions="{ item }">
          <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openEdit(item)" />
          <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="confirmDelete(item)" />
        </template>
      </v-data-table>
    </v-card>

    <!-- Create/Edit dialog -->
    <v-dialog v-model="dialog" max-width="820" persistent scrollable>
      <v-card>
        <v-card-title>{{ form.id ? 'Редактировать' : 'Новая' }} новость</v-card-title>
        <v-card-text style="max-height:70vh">
          <v-text-field v-model="form.title" label="Заголовок *" variant="outlined" density="compact" class="mb-3" />
          <div class="text-caption text-medium-emphasis mb-1">Содержание *</div>
          <RichTextEditor v-model="form.content" min-height="260px" />
          <v-select v-model="form.type" :items="typeOptions" label="Тип" variant="outlined" density="compact" class="mt-3 mb-3" />
          <v-checkbox v-model="form.active" label="Активна (видна всем)" density="compact" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="dialog = false">Отмена</v-btn>
          <v-btn color="primary" @click="save" :loading="saving" :disabled="!form.title || !form.content">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete dialog -->
    <v-dialog v-model="deleteDialog" max-width="400">
      <v-card>
        <v-card-title>Удалить новость?</v-card-title>
        <v-card-text>{{ deleteTarget?.title }}</v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="deleteDialog = false">Отмена</v-btn>
          <v-btn color="error" @click="doDelete" :loading="saving">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';
import RichTextEditor from '../../components/RichTextEditor.vue';

const items = ref([]);
const loading = ref(true);
const saving = ref(false);
const dialog = ref(false);
const deleteDialog = ref(false);
const deleteTarget = ref(null);
const form = ref({});

const typeOptions = [
  { title: 'Информация', value: 'info' },
  { title: 'Важное', value: 'warning' },
  { title: 'Успех', value: 'success' },
];

const headers = [
  { title: 'Заголовок', key: 'title' },
  { title: 'Содержание', key: 'content' },
  { title: 'Тип', key: 'type', width: 100 },
  { title: 'Активна', key: 'active', width: 90 },
  { title: 'Дата', key: 'created_at', width: 120 },
  { title: '', key: 'actions', sortable: false, width: 80 },
];

function fmtDate(d) { if (!d) return '—'; try { return new Date(d).toLocaleDateString('ru-RU'); } catch { return d; } }

async function loadData() {
  loading.value = true;
  try { const { data } = await api.get('/admin/news'); items.value = data; } catch {}
  loading.value = false;
}

function openCreate() {
  form.value = { title: '', content: '', type: 'info', active: true };
  dialog.value = true;
}

function openEdit(item) {
  form.value = { ...item };
  dialog.value = true;
}

async function save() {
  saving.value = true;
  try {
    if (form.value.id) {
      await api.put(`/admin/news/${form.value.id}`, form.value);
    } else {
      await api.post('/admin/news', form.value);
    }
    dialog.value = false;
    loadData();
  } catch {}
  saving.value = false;
}

function confirmDelete(item) { deleteTarget.value = item; deleteDialog.value = true; }

async function doDelete() {
  saving.value = true;
  try {
    await api.delete(`/admin/news/${deleteTarget.value.id}`);
    deleteDialog.value = false;
    loadData();
  } catch {}
  saving.value = false;
}

onMounted(loadData);
</script>
