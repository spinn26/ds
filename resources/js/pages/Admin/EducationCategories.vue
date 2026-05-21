<template>
  <div>
    <PageHeader title="Категории курсов" icon="mdi-folder-multiple" :count="total">
      <template #actions>
        <v-btn v-if="canEdit('education-categories')" color="primary"
          prepend-icon="mdi-plus" @click="openCreate">
          Добавить категорию
        </v-btn>
      </template>
    </PageHeader>

    <v-card>
      <v-data-table
        :headers="headers"
        :items="items"
        :loading="loading"
        density="comfortable"
        hover
        no-data-text="Категорий пока нет"
      >
        <template #item.active="{ item }">
          <StatusChip
            :color="item.active ? 'success' : 'grey'"
            :text="item.active ? 'Активна' : 'Скрыта'"
            size="x-small"
          />
        </template>
        <template #item.courseCount="{ value }">
          <v-chip size="x-small" variant="tonal" :color="value > 0 ? 'primary' : 'grey'">
            {{ value }}
          </v-chip>
        </template>
        <template #item.actions="{ item }">
          <v-btn icon="mdi-pencil" size="x-small" variant="text"
            @click.stop="openEdit(item)" />
          <v-btn v-if="canFull('education-categories')"
            icon="mdi-delete" size="x-small" variant="text" color="error"
            @click.stop="confirmDelete(item)" />
        </template>
      </v-data-table>
    </v-card>

    <!-- Создать / Изменить -->
    <v-dialog v-model="editDialog" max-width="480" persistent>
      <v-card>
        <v-card-title>{{ editItem?.id ? 'Изменить' : 'Добавить' }} категорию</v-card-title>
        <v-card-text>
          <v-text-field v-model="editItem.name" label="Название *"
            variant="outlined" density="comfortable"
            :rules="[v => !!v?.trim() || 'Обязательное поле']" />
          <v-text-field v-model.number="editItem.sort_order" type="number"
            label="Сортировка" variant="outlined" density="comfortable"
            hint="Меньшее значение — выше в списке" persistent-hint class="mb-2" />
          <v-checkbox v-model="editItem.active" label="Активна" density="compact" hide-details />
        </v-card-text>
        <v-card-actions class="pa-3">
          <v-spacer />
          <v-btn @click="editDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving"
            :disabled="!editItem.name?.trim()" @click="save">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Удалить -->
    <v-dialog v-model="deleteDialog" max-width="420">
      <v-card>
        <v-card-title>Удалить категорию?</v-card-title>
        <v-card-text>
          «{{ deleteTarget?.name }}» будет удалена. Привязанные курсы
          останутся, но потеряют группу — их нужно будет переназначить вручную.
        </v-card-text>
        <v-card-actions class="pa-3">
          <v-spacer />
          <v-btn @click="deleteDialog = false">Отмена</v-btn>
          <v-btn color="error" :loading="deleting" @click="doDelete">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="3500">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import StatusChip from '../../components/StatusChip.vue';
import { usePermissions } from '../../composables/usePermissions';

const { canEdit, canFull } = usePermissions();

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const saving = ref(false);
const deleting = ref(false);
const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

const headers = [
  { title: 'Название', key: 'name' },
  { title: 'Сортировка', key: 'sort_order', width: 120, align: 'end' },
  { title: 'Курсов', key: 'courseCount', width: 100, align: 'end' },
  { title: 'Статус', key: 'active', width: 130 },
  { title: '', key: 'actions', sortable: false, width: 100, align: 'end' },
];

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/education/categories');
    items.value = data.data || [];
    total.value = data.total ?? items.value.length;
  } catch {}
  loading.value = false;
}

const editDialog = ref(false);
const editItem = ref({ name: '', sort_order: 0, active: true });

function openCreate() {
  editItem.value = { name: '', sort_order: 0, active: true };
  editDialog.value = true;
}
function openEdit(item) {
  editItem.value = { ...item };
  editDialog.value = true;
}

async function save() {
  if (!editItem.value.name?.trim()) return;
  saving.value = true;
  try {
    if (editItem.value.id) {
      await api.put(`/admin/education/categories/${editItem.value.id}`, editItem.value);
      notify('Категория обновлена');
    } else {
      await api.post('/admin/education/categories', editItem.value);
      notify('Категория создана');
    }
    editDialog.value = false;
    await load();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка сохранения', 'error');
  }
  saving.value = false;
}

const deleteDialog = ref(false);
const deleteTarget = ref(null);
function confirmDelete(item) {
  deleteTarget.value = item;
  deleteDialog.value = true;
}
async function doDelete() {
  deleting.value = true;
  try {
    await api.delete(`/admin/education/categories/${deleteTarget.value.id}`);
    notify('Категория удалена');
    deleteDialog.value = false;
    await load();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка удаления', 'error');
  }
  deleting.value = false;
}

onMounted(load);
</script>
