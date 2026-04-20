<template>
  <div>
    <PageHeader :title="cfg?.label || 'Справочник'" icon="mdi-book-cog">
      <template #actions>
        <v-btn variant="text" prepend-icon="mdi-arrow-left" @click="$router.push('/admin/references')">К списку</v-btn>
        <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreate">Добавить</v-btn>
      </template>
    </PageHeader>

    <v-card>
      <v-data-table :items="items" :loading="loading" :headers="headers" density="comfortable" hover>
        <template v-for="f in boolFields" :key="f.key" #[`item.${f.key}`]="{ value }">
          <BooleanCell :value="!!value" />
        </template>
        <template #item.actions="{ item }">
          <ActionsCell @edit="openEdit(item)" @delete="confirmDelete(item)" />
        </template>
      </v-data-table>
    </v-card>

    <DialogShell
      v-model="editDialog"
      :title="editForm.id ? 'Редактировать' : 'Добавить'"
      :max-width="560"
      persistent
      :loading="saving"
      :confirm-disabled="!formValid"
      @confirm="save"
    >
      <FormErrors :errors="editErrors" :message="editMessage" />
      <v-row dense>
        <v-col v-for="f in cfg?.fields || []" :key="f.key" :cols="f.type === 'text' ? 12 : 12" :sm="f.type === 'bool' ? 12 : 6">
          <!-- bool -->
          <v-checkbox v-if="f.type === 'bool'" v-model="editForm[f.key]"
            :label="f.label" density="compact" hide-details />
          <!-- fkey -->
          <v-select v-else-if="f.type === 'fkey'" v-model="editForm[f.key]"
            :label="f.label" :items="fkOptions[f.key] || []"
            item-title="label" item-value="id"
            variant="outlined" density="comfortable" clearable
            :error-messages="fieldErr(f.key)" />
          <!-- text (long) -->
          <v-textarea v-else-if="f.type === 'text'" v-model="editForm[f.key]"
            :label="f.label" variant="outlined" density="comfortable" rows="3"
            :error-messages="fieldErr(f.key)" />
          <!-- string (short) -->
          <v-text-field v-else v-model="editForm[f.key]"
            :label="f.label + (f.required ? ' *' : '')" variant="outlined" density="comfortable"
            :error-messages="fieldErr(f.key)" />
        </v-col>
      </v-row>
    </DialogShell>

    <DialogShell
      v-model="deleteDialog"
      title="Удалить запись?"
      :max-width="400"
      :loading="saving"
      confirm-text="Удалить"
      confirm-color="error"
      @confirm="remove"
    >
      {{ deleteTarget?.[cfg?.primaryLabel] || `#${deleteTarget?.id}` }}
    </DialogShell>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import api from '../../api';
import { PageHeader, DialogShell, ActionsCell, BooleanCell, FormErrors } from '../../components';
import { useSnackbar } from '../../composables/useSnackbar';

const route = useRoute();
const { showSuccess, showError } = useSnackbar();

// Static registry — mirrors AdminReferenceController::CATALOGS keys.
// We fetch /admin/references once to get actual labels + fields per catalog.
const catalogs = ref([]);
const catalogKey = computed(() => route.params.catalog);
const cfg = computed(() => catalogs.value.find(c => c.key === catalogKey.value));

const items = ref([]);
const loading = ref(false);
const saving = ref(false);

const editDialog = ref(false);
const editForm = ref({});
const editErrors = ref({});
const editMessage = ref('');

const deleteDialog = ref(false);
const deleteTarget = ref(null);

const fkOptions = ref({});

const boolFields = computed(() => (cfg.value?.fields || []).filter(f => f.type === 'bool'));

const headers = computed(() => {
  if (!cfg.value) return [];
  return [
    { title: 'ID', key: 'id', width: 80 },
    ...cfg.value.fields.map(f => ({ title: f.label, key: f.type === 'fkey' ? `${f.key}Label` : f.key })),
    { title: '', key: 'actions', sortable: false, width: 100 },
  ];
});

const formValid = computed(() => {
  if (!cfg.value) return false;
  return cfg.value.fields.every(f => !f.required || !!editForm.value[f.key]);
});

function fieldErr(k) {
  const v = editErrors.value?.[k];
  return Array.isArray(v) ? v[0] : (v || '');
}

async function loadCatalogs() {
  try {
    const { data } = await api.get('/admin/references');
    catalogs.value = data || [];
  } catch { catalogs.value = []; }
}

async function loadItems() {
  if (!catalogKey.value) return;
  loading.value = true;
  try {
    const { data } = await api.get(`/admin/references/${catalogKey.value}`);
    items.value = data.items || [];
  } catch (e) { showError(e.response?.data?.message || 'Не удалось загрузить справочник'); }
  loading.value = false;

  // Load FK lookups if any
  fkOptions.value = {};
  for (const f of (cfg.value?.fields || [])) {
    if (f.type === 'fkey' && f.refTable) {
      try {
        const { data } = await api.get(`/admin/references/${f.refTable}`);
        fkOptions.value[f.key] = (data.items || []).map(r => ({
          id: r.id,
          label: r[f.refLabel] || `#${r.id}`,
        }));
      } catch {}
    }
  }
}

function openCreate() {
  editForm.value = {};
  for (const f of cfg.value?.fields || []) {
    editForm.value[f.key] = f.type === 'bool' ? false : null;
  }
  editErrors.value = {};
  editMessage.value = '';
  editDialog.value = true;
}

function openEdit(item) {
  editForm.value = { ...item };
  editErrors.value = {};
  editMessage.value = '';
  editDialog.value = true;
}

async function save() {
  saving.value = true;
  editErrors.value = {};
  editMessage.value = '';
  const payload = {};
  for (const f of cfg.value?.fields || []) {
    payload[f.key] = editForm.value[f.key];
  }
  try {
    if (editForm.value.id) {
      await api.put(`/admin/references/${catalogKey.value}/${editForm.value.id}`, payload);
      showSuccess('Сохранено');
    } else {
      await api.post(`/admin/references/${catalogKey.value}`, payload);
      showSuccess('Создано');
    }
    editDialog.value = false;
    await loadItems();
  } catch (e) {
    const r = e.response?.data;
    editErrors.value = r?.errors || {};
    editMessage.value = r?.message || 'Ошибка сохранения';
  }
  saving.value = false;
}

function confirmDelete(item) {
  deleteTarget.value = item;
  deleteDialog.value = true;
}

async function remove() {
  if (!deleteTarget.value?.id) return;
  saving.value = true;
  try {
    await api.delete(`/admin/references/${catalogKey.value}/${deleteTarget.value.id}`);
    showSuccess('Удалено');
    deleteDialog.value = false;
    deleteTarget.value = null;
    await loadItems();
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось удалить');
  }
  saving.value = false;
}

watch(() => route.params.catalog, () => { if (cfg.value) loadItems(); });

onMounted(async () => {
  await loadCatalogs();
  await loadItems();
});
</script>
