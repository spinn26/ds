<template>
  <div>
    <PageHeader title="Группы и права" icon="mdi-shield-account">
      <template #actions>
        <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreate">
          Добавить группу
        </v-btn>
      </template>
    </PageHeader>

    <v-alert type="info" variant="tonal" density="compact" class="mb-3" icon="mdi-information-outline">
      <strong>Уровни:</strong>
      <v-chip size="x-small" color="info" variant="flat" class="mx-1">view</v-chip>только просмотр,
      <v-chip size="x-small" color="warning" variant="flat" class="mx-1">edit</v-chip>добавление/редактирование,
      <v-chip size="x-small" color="success" variant="flat" class="mx-1">full</v-chip>+ удаление и системные действия.
      «—» = доступа нет, раздел в меню скрыт.
      Системные группы (admin, backoffice, …) удалить нельзя — их ключи завязаны на код.
    </v-alert>

    <v-card v-if="!loading">
      <div style="overflow-x: auto" class="permissions-wrap">
        <v-table density="compact" class="permissions-table">
          <thead>
            <tr>
              <th class="th-group">Группа</th>
              <th v-for="s in sections" :key="s.key" class="th-section">
                <div class="text-no-wrap">{{ s.label }}</div>
                <div class="text-caption text-medium-emphasis text-no-wrap">{{ s.key }}</div>
              </th>
              <th class="th-actions">—</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="g in groups" :key="g.id" :class="{ 'tr-system': g.isSystem }">
              <td class="td-group">
                <div class="d-flex align-center ga-2">
                  <v-icon size="16" :color="g.isSystem ? 'secondary' : 'primary'">
                    {{ g.isSystem ? 'mdi-shield-lock' : 'mdi-shield' }}
                  </v-icon>
                  <div style="min-width:0; flex:1">
                    <div class="font-weight-medium text-truncate" :title="g.name">{{ g.name }}</div>
                    <div class="text-caption text-medium-emphasis font-mono">{{ g.key }}</div>
                  </div>
                  <v-btn icon="mdi-pencil" size="x-small" variant="text" title="Редактировать имя/ключ"
                    @click="openEdit(g)" />
                </div>
                <div v-if="g.description" class="text-caption text-medium-emphasis mt-1"
                  :title="g.description">{{ g.description }}</div>
              </td>

              <!-- Если это admin (системный with all-full) — рисуем «full»
                   во всех ячейках без возможности правки. -->
              <td v-for="s in sections" :key="s.key" class="td-cell">
                <template v-if="g.key === 'admin'">
                  <v-chip size="x-small" color="success" variant="flat">full</v-chip>
                </template>
                <template v-else>
                  <v-select :model-value="g.permissions[s.key] || ''"
                    :items="levelOptions" item-title="label" item-value="value"
                    density="compact" variant="plain" hide-details
                    :menu-props="{ maxWidth: 180 }"
                    style="min-width: 96px"
                    @update:model-value="v => onLevelChange(g, s.key, v)" />
                </template>
              </td>

              <td class="td-actions">
                <v-btn v-if="!g.isSystem" icon="mdi-trash-can-outline" size="x-small" variant="text"
                  color="error" title="Удалить группу" @click="confirmDelete(g)" />
                <v-icon v-else size="14" color="grey" title="Системная — удалить нельзя">mdi-lock</v-icon>
              </td>
            </tr>
          </tbody>
        </v-table>
      </div>
    </v-card>

    <div v-else class="d-flex justify-center pa-6">
      <v-progress-circular indeterminate />
    </div>

    <!-- Add / Edit dialog (только мета-инфо: ключ/имя/описание) -->
    <v-dialog v-model="dialogOpen" max-width="520" persistent>
      <v-card>
        <v-card-title>
          {{ dialogMode === 'create' ? 'Новая группа' : 'Редактировать группу' }}
        </v-card-title>
        <v-card-text>
          <v-text-field v-model="form.key" label="Ключ *"
            hint="lower-snake-case, должен совпадать с ролью в WebUser.role"
            persistent-hint :disabled="dialogMode === 'edit' && form.isSystem"
            class="mb-3" />
          <v-text-field v-model="form.name" label="Название *" class="mb-3" />
          <v-textarea v-model="form.description" label="Описание" rows="2" auto-grow />
          <v-alert v-if="formError" type="error" variant="tonal" density="compact" class="mt-3">
            {{ formError }}
          </v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="dialogOpen = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" @click="saveDialog">
            {{ dialogMode === 'create' ? 'Создать' : 'Сохранить' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="3500">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import { useConfirm } from '../../composables/useConfirm';
import { useDebounce } from '../../composables/useDebounce';

const confirm = useConfirm();
const loading = ref(true);
const groups = ref([]);
const sections = ref([]);
const levels = ref([]);

const levelOptions = computed(() => [
  { label: '—', value: '' },
  ...levels.value.map(l => ({ label: l, value: l })),
]);

const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/permissions/groups');
    groups.value = data.groups || [];
    sections.value = data.sections || [];
    levels.value = data.levels || ['view', 'edit', 'full'];
  } catch (e) {
    notify(e.response?.data?.message || 'Не удалось загрузить', 'error');
  }
  loading.value = false;
}

// Сохранение ячейки — debounce, чтобы быстрые клики по дропдауну
// не порождали 10 PATCH'ей подряд.
const saveTimers = {};
function onLevelChange(group, sectionKey, value) {
  // Локально сразу применяем — UI отзывчивый.
  const next = { ...group.permissions };
  if (!value) delete next[sectionKey];
  else next[sectionKey] = value;
  group.permissions = next;

  clearTimeout(saveTimers[group.id]);
  saveTimers[group.id] = setTimeout(() => savePermissions(group), 300);
}

async function savePermissions(group) {
  try {
    await api.patch(`/admin/permissions/groups/${group.id}`, {
      permissions: group.permissions,
    });
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка сохранения', 'error');
    await load();   // откат к серверному состоянию
  }
}

// === Add / Edit dialog ===
const dialogOpen = ref(false);
const dialogMode = ref('create');   // 'create' | 'edit'
const form = ref({ key: '', name: '', description: '', isSystem: false });
const editingId = ref(null);
const saving = ref(false);
const formError = ref('');

function openCreate() {
  dialogMode.value = 'create';
  form.value = { key: '', name: '', description: '', isSystem: false };
  formError.value = '';
  editingId.value = null;
  dialogOpen.value = true;
}
function openEdit(g) {
  dialogMode.value = 'edit';
  form.value = {
    key: g.key, name: g.name, description: g.description || '',
    isSystem: g.isSystem,
  };
  formError.value = '';
  editingId.value = g.id;
  dialogOpen.value = true;
}

async function saveDialog() {
  formError.value = '';
  if (!form.value.key || !form.value.name) {
    formError.value = 'Ключ и название обязательны';
    return;
  }
  saving.value = true;
  try {
    if (dialogMode.value === 'create') {
      const payload = {
        key: form.value.key,
        name: form.value.name,
        description: form.value.description || null,
        permissions: {},
      };
      await api.post('/admin/permissions/groups', payload);
      notify('Группа создана');
    } else {
      const payload = { name: form.value.name, description: form.value.description || null };
      // Ключ системной не правим (бэк всё равно отклонит).
      if (!form.value.isSystem) payload.key = form.value.key;
      await api.patch(`/admin/permissions/groups/${editingId.value}`, payload);
      notify('Сохранено');
    }
    dialogOpen.value = false;
    await load();
  } catch (e) {
    formError.value = e.response?.data?.message
      || Object.values(e.response?.data?.errors || {})[0]?.[0]
      || 'Ошибка';
  }
  saving.value = false;
}

async function confirmDelete(g) {
  if (!await confirm.ask({
    title: 'Удалить группу?',
    message: `Группа «${g.name}» (${g.key}) будет удалена. Пользователи, у которых эта роль прописана в WebUser.role, потеряют доступ. Действие необратимо.`,
    confirmText: 'Удалить', confirmColor: 'error', icon: 'mdi-trash-can',
  })) return;
  try {
    await api.delete(`/admin/permissions/groups/${g.id}`);
    notify('Группа удалена');
    await load();
  } catch (e) {
    notify(e.response?.data?.message || 'Не удалось удалить', 'error');
  }
}

onMounted(load);
</script>

<style scoped>
.permissions-wrap {
  max-height: calc(100vh - 240px);
  overflow: auto;
}
.permissions-table {
  /* Sticky-первая колонка — чтобы при горизонтальном скролле имена групп
     оставались видны. */
  border-collapse: separate;
  border-spacing: 0;
}
.permissions-table :deep(thead th) {
  position: sticky;
  top: 0;
  background: rgb(var(--v-theme-surface));
  z-index: 2;
  white-space: nowrap;
}
.permissions-table .th-group,
.permissions-table .td-group {
  position: sticky;
  left: 0;
  background: rgb(var(--v-theme-surface));
  z-index: 1;
  min-width: 280px;
  max-width: 320px;
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
.permissions-table .th-group {
  z-index: 3;   /* поверх и top, и left sticky */
}
.permissions-table .th-section {
  text-align: center;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  padding: 4px 6px !important;
  min-width: 110px;
}
.permissions-table .td-cell {
  padding: 2px 4px !important;
  text-align: center;
}
.permissions-table .th-actions,
.permissions-table .td-actions {
  width: 40px;
  text-align: center;
  position: sticky;
  right: 0;
  background: rgb(var(--v-theme-surface));
  border-left: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
.permissions-table .tr-system {
  background: rgba(var(--v-theme-surface-variant), 0.25);
}
.font-mono { font-family: 'SFMono-Regular', Consolas, monospace; }
</style>
