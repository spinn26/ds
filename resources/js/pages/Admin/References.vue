<template>
  <div>
    <div class="d-flex align-center ga-2 mb-4">
      <v-icon size="32" color="primary">mdi-book-cog</v-icon>
      <h5 class="text-h5 font-weight-bold">Справочники</h5>
    </div>

    <v-card>
      <v-tabs v-model="activeTab" bg-color="transparent" show-arrows slider-color="primary">
        <v-tab v-for="c in catalogs" :key="c.key" :value="c.key">{{ c.label }}</v-tab>
      </v-tabs>
      <v-divider />

      <div class="pa-4">
        <div class="d-flex justify-space-between align-center mb-3">
          <div class="text-body-2 text-medium-emphasis">
            Записей: <strong>{{ total }}</strong>
          </div>
          <div class="d-flex align-center ga-2">
            <ColumnVisibilityMenu
              :headers="tableHeaders"
              v-model:visible="columnVisible"
              :storage-key="`references-${activeTab}-cols`" />
            <v-btn color="primary" prepend-icon="mdi-plus" :disabled="!currentCatalog" @click="openCreate">
              Добавить
            </v-btn>
          </div>
        </div>

        <v-data-table :items="items" :headers="visibleTableHeaders" :loading="loading"
          density="compact" hover no-data-text="Нет записей" :items-per-page="50">
          <template v-for="f in boolFields" #[`item.${f.key}`]="{ value }" :key="f.key">
            <v-icon size="small" :color="value ? 'success' : 'grey'">
              {{ value ? 'mdi-check-circle' : 'mdi-minus-circle' }}
            </v-icon>
          </template>
          <template v-for="f in fkFields" #[`item.${f.key}`]="{ item }" :key="f.key">
            <span>{{ item[f.key + 'Label'] || item[f.key] || '—' }}</span>
          </template>
          <template #item.actions="{ item }">
            <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openEdit(item)" />
            <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="confirmDelete(item)" />
          </template>
        </v-data-table>
      </div>
    </v-card>

    <!-- Create/Edit dialog -->
    <v-dialog v-model="dialog" max-width="600" persistent>
      <v-card v-if="currentCatalog">
        <v-card-title>
          {{ form.id ? 'Редактировать' : 'Новая' }} запись — {{ currentCatalog.label }}
        </v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col v-for="f in currentCatalog.fields" :key="f.key" cols="12">
              <v-text-field v-if="f.type === 'string'"
                v-model="form[f.key]" :label="f.label + (f.required ? ' *' : '')"
                variant="outlined" density="compact" :error-messages="errors[f.key]" />

              <v-textarea v-else-if="f.type === 'text'"
                v-model="form[f.key]" :label="f.label + (f.required ? ' *' : '')"
                variant="outlined" density="compact" rows="3"
                :error-messages="errors[f.key]" />

              <v-checkbox v-else-if="f.type === 'bool'"
                v-model="form[f.key]" :label="f.label" density="compact" hide-details />

              <v-autocomplete v-else-if="f.type === 'fkey'"
                v-model="form[f.key]"
                :items="fkOptions[f.refTable] || []"
                :item-title="f.refLabel"
                item-value="id"
                :label="f.label + (f.required ? ' *' : '')"
                variant="outlined" density="compact" clearable
                :error-messages="errors[f.key]" />
            </v-col>
          </v-row>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="dialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" :disabled="!canSave" @click="save">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete dialog -->
    <v-dialog v-model="deleteDialog" max-width="420">
      <v-card>
        <v-card-title>Удалить запись?</v-card-title>
        <v-card-text>{{ deleteTarget ? deleteLabel(deleteTarget) : '' }}</v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="deleteDialog = false">Отмена</v-btn>
          <v-btn color="error" :loading="saving" @click="doDelete">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import api from '../../api';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';

const catalogs = ref([]);
const activeTab = ref(null);
const items = ref([]);
const total = ref(0);
const loading = ref(false);
const saving = ref(false);

const dialog = ref(false);
const deleteDialog = ref(false);
const deleteTarget = ref(null);
const form = ref({});
const errors = ref({});

const fkOptions = ref({});

const currentCatalog = computed(() => catalogs.value.find(c => c.key === activeTab.value));

const boolFields = computed(() => currentCatalog.value?.fields.filter(f => f.type === 'bool') || []);
const fkFields = computed(() => currentCatalog.value?.fields.filter(f => f.type === 'fkey') || []);

const tableHeaders = computed(() => {
  if (!currentCatalog.value) return [];
  const headers = [{ title: 'ID', key: 'id', width: 80 }];
  for (const f of currentCatalog.value.fields) {
    const h = { title: f.label, key: f.key };
    if (f.type === 'bool') h.width = 140;
    headers.push(h);
  }
  headers.push({ title: '', key: 'actions', sortable: false, width: 100 });
  return headers;
});

// Per-tab visibility state — reset on tab change so that each catalog's
// localStorage key (set via :storage-key) re-loads its own saved layout.
const columnVisible = ref({});
const visibleTableHeaders = computed(() =>
  tableHeaders.value.filter(h => columnVisible.value[h.key] !== false)
);

const canSave = computed(() => {
  if (!currentCatalog.value) return false;
  for (const f of currentCatalog.value.fields) {
    if (f.required && !form.value[f.key]) return false;
  }
  return true;
});

function deleteLabel(item) {
  const cat = currentCatalog.value;
  if (!cat) return `ID ${item.id}`;
  const labelField = cat.fields.find(f => f.type === 'string')?.key;
  return labelField ? item[labelField] : `ID ${item.id}`;
}

async function loadCatalogs() {
  try {
    const { data } = await api.get('/admin/references');
    catalogs.value = data;
    if (!activeTab.value && data.length) activeTab.value = data[0].key;
  } catch {}
}

async function loadData() {
  if (!activeTab.value) return;
  loading.value = true;
  try {
    const { data } = await api.get(`/admin/references/${activeTab.value}`);
    items.value = data.items || [];
    total.value = data.total || 0;
  } catch {}
  loading.value = false;
}

async function loadFkOptions() {
  if (!currentCatalog.value) return;
  for (const f of currentCatalog.value.fields) {
    if (f.type === 'fkey' && f.refTable && !fkOptions.value[f.refTable]) {
      try {
        const { data } = await api.get(`/admin/references/${f.refTable}`);
        fkOptions.value[f.refTable] = data.items || [];
      } catch {}
    }
  }
}

function openCreate() {
  if (!currentCatalog.value) return;
  const empty = {};
  for (const f of currentCatalog.value.fields) {
    empty[f.key] = f.type === 'bool' ? false : null;
  }
  form.value = empty;
  errors.value = {};
  dialog.value = true;
  loadFkOptions();
}

function openEdit(item) {
  if (!currentCatalog.value) return;
  const copy = { id: item.id };
  for (const f of currentCatalog.value.fields) {
    copy[f.key] = item[f.key] ?? (f.type === 'bool' ? false : null);
  }
  form.value = copy;
  errors.value = {};
  dialog.value = true;
  loadFkOptions();
}

async function save() {
  saving.value = true;
  errors.value = {};
  try {
    const payload = { ...form.value };
    for (const k of Object.keys(payload)) {
      if (payload[k] === '') payload[k] = null;
    }
    const id = payload.id;
    delete payload.id;
    if (id) {
      await api.put(`/admin/references/${activeTab.value}/${id}`, payload);
    } else {
      await api.post(`/admin/references/${activeTab.value}`, payload);
    }
    dialog.value = false;
    await loadData();
  } catch (e) {
    if (e.response?.status === 422) {
      const raw = e.response.data?.errors || {};
      const mapped = {};
      for (const k of Object.keys(raw)) mapped[k] = raw[k][0];
      errors.value = mapped;
    }
  }
  saving.value = false;
}

function confirmDelete(item) {
  deleteTarget.value = item;
  deleteDialog.value = true;
}

async function doDelete() {
  saving.value = true;
  try {
    await api.delete(`/admin/references/${activeTab.value}/${deleteTarget.value.id}`);
    deleteDialog.value = false;
    await loadData();
  } catch {}
  saving.value = false;
}

watch(activeTab, (key) => {
  items.value = [];
  total.value = 0;
  // Re-hydrate column visibility from this tab's localStorage slot.
  try {
    const raw = localStorage.getItem(`cols:references-${key}-cols`);
    columnVisible.value = raw ? JSON.parse(raw) : {};
  } catch { columnVisible.value = {}; }
  loadData();
});

onMounted(async () => {
  await loadCatalogs();
  loadData();
});
</script>
