<template>
  <div>
    <PageHeader title="Группы и права" icon="mdi-shield-account" :count="groups.length">
      <template #actions>
        <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreate">
          Добавить группу
        </v-btn>
      </template>
    </PageHeader>

    <!-- Легенда + поиск + статистика -->
    <v-card class="mb-3 pa-3 legend-card" elevation="0">
      <div class="d-flex flex-wrap align-center ga-3">
        <div class="d-flex align-center ga-2">
          <span class="text-caption text-medium-emphasis">Уровни:</span>
          <v-chip size="x-small" :color="levelColor('view')" variant="tonal" label>Просмотр</v-chip>
          <span class="text-caption text-medium-emphasis">read-only</span>
          <v-chip size="x-small" :color="levelColor('edit')" variant="tonal" label>Правка</v-chip>
          <span class="text-caption text-medium-emphasis">+ добавление/редактирование</span>
          <v-chip size="x-small" :color="levelColor('full')" variant="tonal" label>Полный</v-chip>
          <span class="text-caption text-medium-emphasis">+ удаление / системные действия</span>
        </div>
        <v-divider vertical class="mx-2" />
        <v-text-field v-model="filterText" placeholder="Поиск группы или раздела"
          density="compact" variant="outlined" hide-details clearable
          prepend-inner-icon="mdi-magnify"
          style="max-width: 280px; flex: 1 1 200px" />
        <v-spacer />
        <div class="text-caption text-medium-emphasis">
          <strong>{{ groups.length }}</strong> групп
          · <strong>{{ filteredSections.length }}</strong> разделов
          · <strong>{{ totalGrants }}</strong> правил доступа
        </div>
      </div>
    </v-card>

    <v-card v-if="!loading" class="permissions-card">
      <div class="permissions-wrap">
        <table class="permissions-grid">
          <thead>
            <tr>
              <th class="th-group">
                <div class="d-flex align-center ga-1">
                  <v-icon size="14">mdi-account-multiple</v-icon>
                  Группа
                </div>
              </th>
              <th v-for="s in filteredSections" :key="s.key" class="th-section" :title="s.key">
                <div class="th-section__title">{{ s.label }}</div>
                <code class="th-section__key">{{ s.key }}</code>
              </th>
              <th class="th-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="g in filteredGroups" :key="g.id"
              :class="{ 'tr-system': g.isSystem, 'tr-admin': g.key === 'admin' }">
              <!-- Группа -->
              <td class="td-group">
                <div class="d-flex align-center ga-2">
                  <v-avatar :color="g.isSystem ? 'secondary' : 'primary'" size="32" variant="tonal">
                    <v-icon size="16">
                      {{ g.isSystem ? 'mdi-shield-lock' : 'mdi-shield-account' }}
                    </v-icon>
                  </v-avatar>
                  <div style="min-width:0; flex:1">
                    <div class="td-group__name text-truncate" :title="g.name">{{ g.name }}</div>
                    <div class="td-group__meta d-flex align-center ga-1">
                      <code class="td-group__key">{{ g.key }}</code>
                      <v-chip v-if="g.isSystem" size="x-small" color="secondary" variant="tonal" class="ml-1">
                        системная
                      </v-chip>
                    </div>
                  </div>
                  <v-menu location="bottom end">
                    <template #activator="{ props }">
                      <v-btn v-bind="props" icon="mdi-dots-vertical" size="x-small" variant="text" />
                    </template>
                    <v-list density="compact">
                      <v-list-item prepend-icon="mdi-pencil-outline" @click="openEdit(g)">
                        <v-list-item-title>Редактировать</v-list-item-title>
                      </v-list-item>
                      <v-list-item v-if="g.key !== 'admin'" prepend-icon="mdi-eye-outline"
                        @click="bulkSet(g, 'view')">
                        <v-list-item-title>Все разделы → Просмотр</v-list-item-title>
                      </v-list-item>
                      <v-list-item v-if="g.key !== 'admin'" prepend-icon="mdi-close-circle-outline"
                        @click="bulkSet(g, '')">
                        <v-list-item-title>Сбросить все права</v-list-item-title>
                      </v-list-item>
                      <v-divider v-if="!g.isSystem" />
                      <v-list-item v-if="!g.isSystem" prepend-icon="mdi-trash-can-outline"
                        @click="confirmDelete(g)">
                        <v-list-item-title class="text-error">Удалить группу</v-list-item-title>
                      </v-list-item>
                    </v-list>
                  </v-menu>
                </div>
                <div v-if="g.description" class="td-group__desc"
                  :title="g.description">{{ g.description }}</div>
              </td>

              <!-- Ячейки прав -->
              <td v-for="s in filteredSections" :key="s.key" class="td-cell">
                <v-chip v-if="g.key === 'admin'" size="small"
                  :color="levelColor('full')" variant="flat" label>
                  <v-icon size="12" start>mdi-check-bold</v-icon>{{ levelLabel('full') }}
                </v-chip>
                <v-menu v-else location="bottom" :close-on-content-click="true">
                  <template #activator="{ props }">
                    <v-chip v-bind="props"
                      :color="g.permissions[s.key] ? levelColor(g.permissions[s.key]) : undefined"
                      :variant="g.permissions[s.key] ? 'flat' : 'outlined'"
                      size="small" label class="cell-chip"
                      :class="{ 'cell-chip--empty': !g.permissions[s.key], 'cell-chip--saving': savingCells[`${g.id}:${s.key}`] }">
                      <v-progress-circular v-if="savingCells[`${g.id}:${s.key}`]"
                        size="10" width="2" indeterminate class="me-1" />
                      <span>{{ levelLabel(g.permissions[s.key]) }}</span>
                      <v-icon size="12" end>mdi-chevron-down</v-icon>
                    </v-chip>
                  </template>
                  <v-list density="compact" min-width="240">
                    <v-list-item v-for="opt in cellOptions" :key="opt.value"
                      :active="(g.permissions[s.key] || '') === opt.value"
                      @click="onLevelChange(g, s.key, opt.value)">
                      <template #prepend>
                        <v-chip v-if="opt.value" size="x-small" :color="levelColor(opt.value)"
                          variant="flat" label class="me-2">{{ levelLabel(opt.value) }}</v-chip>
                        <span v-else class="text-medium-emphasis me-2"
                          style="display:inline-block;min-width:64px;text-align:center">—</span>
                      </template>
                      <v-list-item-title class="text-body-2">{{ opt.label }}</v-list-item-title>
                    </v-list-item>
                  </v-list>
                </v-menu>
              </td>

              <td class="td-actions">
                <v-icon v-if="g.isSystem" size="14" color="grey"
                  title="Системная группа — удалить нельзя">mdi-lock</v-icon>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </v-card>

    <div v-else class="d-flex justify-center pa-12">
      <v-progress-circular indeterminate size="40" color="primary" />
    </div>

    <!-- Add / Edit dialog -->
    <v-dialog v-model="dialogOpen" max-width="540" persistent>
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon :color="dialogMode === 'create' ? 'primary' : 'secondary'">
            {{ dialogMode === 'create' ? 'mdi-shield-plus' : 'mdi-shield-edit' }}
          </v-icon>
          {{ dialogMode === 'create' ? 'Новая группа' : 'Редактировать группу' }}
        </v-card-title>
        <v-card-text>
          <v-text-field v-model="form.key" label="Ключ *"
            hint="lower-snake-case (a-z, 0-9, _, -). Должен совпадать с ролью в WebUser.role"
            persistent-hint :disabled="dialogMode === 'edit' && form.isSystem"
            prepend-inner-icon="mdi-key" class="mb-3" />
          <v-text-field v-model="form.name" label="Название *"
            prepend-inner-icon="mdi-label-outline" class="mb-3" />
          <v-textarea v-model="form.description" label="Описание"
            prepend-inner-icon="mdi-text-box-outline" rows="2" auto-grow />
          <v-alert v-if="form.isSystem" type="info" variant="tonal" density="compact"
            icon="mdi-shield-lock" class="mt-3">
            Системная группа — ключ менять нельзя, удалить через UI тоже нельзя.
          </v-alert>
          <v-alert v-if="formError" type="error" variant="tonal" density="compact" class="mt-3">
            {{ formError }}
          </v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="dialogOpen = false">Отмена</v-btn>
          <v-btn color="primary" variant="flat" :loading="saving" @click="saveDialog">
            {{ dialogMode === 'create' ? 'Создать' : 'Сохранить' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="2500" location="bottom right">
      <v-icon class="me-2">{{ snack.icon }}</v-icon>
      {{ snack.text }}
    </v-snackbar>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import { useConfirm } from '../../composables/useConfirm';

const confirm = useConfirm();
const loading = ref(true);
const groups = ref([]);
const sections = ref([]);
const filterText = ref('');
const savingCells = ref({});

const cellOptions = [
  { value: '',     label: 'Нет доступа — раздел скрыт в меню' },
  { value: 'view', label: 'Только просмотр (read-only)' },
  { value: 'edit', label: 'Правка: добавление и редактирование' },
  { value: 'full', label: 'Полный доступ + удаление и системные действия' },
];

function levelColor(level) {
  return { view: 'info', edit: 'warning', full: 'success' }[level] || 'default';
}

// Отображаемое название уровня (рус). Сами значения в БД и API
// остаются английскими (view/edit/full) — так короче в коде, в JSON
// и не зависит от локали при импорте/экспорте между средами.
function levelLabel(level) {
  return { view: 'Просмотр', edit: 'Правка', full: 'Полный' }[level] || '—';
}

const filteredGroups = computed(() => {
  if (!filterText.value) return groups.value;
  const t = filterText.value.toLowerCase();
  return groups.value.filter(g =>
    g.name.toLowerCase().includes(t) ||
    g.key.toLowerCase().includes(t) ||
    (g.description || '').toLowerCase().includes(t)
  );
});

const filteredSections = computed(() => {
  if (!filterText.value) return sections.value;
  const t = filterText.value.toLowerCase();
  // Если запрос совпадает с группой — показываем все секции; иначе фильтруем секции.
  const matchesGroup = groups.value.some(g =>
    g.name.toLowerCase().includes(t) || g.key.toLowerCase().includes(t)
  );
  if (matchesGroup) return sections.value;
  return sections.value.filter(s =>
    s.label.toLowerCase().includes(t) || s.key.toLowerCase().includes(t)
  );
});

const totalGrants = computed(() =>
  groups.value.reduce((acc, g) => {
    if (g.key === 'admin') return acc + sections.value.length;
    return acc + Object.keys(g.permissions || {}).length;
  }, 0)
);

const snack = ref({ open: false, color: 'success', text: '', icon: 'mdi-check-circle' });
function notify(text, color = 'success', icon = 'mdi-check-circle') {
  snack.value = { open: true, color, text, icon };
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/permissions/groups');
    groups.value = data.groups || [];
    sections.value = data.sections || [];
  } catch (e) {
    notify(e.response?.data?.message || 'Не удалось загрузить', 'error', 'mdi-alert');
  }
  loading.value = false;
}

const saveTimers = {};
function onLevelChange(group, sectionKey, value) {
  const next = { ...group.permissions };
  if (!value) delete next[sectionKey];
  else next[sectionKey] = value;
  group.permissions = next;

  const key = `${group.id}:${sectionKey}`;
  savingCells.value = { ...savingCells.value, [key]: true };

  clearTimeout(saveTimers[group.id]);
  saveTimers[group.id] = setTimeout(() => savePermissions(group, key), 250);
}

async function savePermissions(group, savingKey) {
  try {
    await api.patch(`/admin/permissions/groups/${group.id}`, {
      permissions: group.permissions,
    });
    notify(`Сохранено: ${group.name}`, 'success', 'mdi-check-circle');
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка сохранения', 'error', 'mdi-alert');
    await load();
  } finally {
    if (savingKey) {
      const next = { ...savingCells.value };
      delete next[savingKey];
      savingCells.value = next;
    }
  }
}

async function bulkSet(group, level) {
  const action = level
    ? `выставить «${levelLabel(level)}» для всех разделов`
    : 'сбросить все права';
  if (!await confirm.ask({
    title: 'Массовое изменение',
    message: `Группа «${group.name}» — ${action}? Действие применяется ко всем ${sections.value.length} разделам.`,
    confirmText: 'Применить', confirmColor: level ? 'primary' : 'error',
    icon: level ? 'mdi-flash' : 'mdi-close-circle',
  })) return;

  const next = {};
  if (level) for (const s of sections.value) next[s.key] = level;
  group.permissions = next;
  await savePermissions(group);
}

// === Add / Edit ===
const dialogOpen = ref(false);
const dialogMode = ref('create');
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
      await api.post('/admin/permissions/groups', {
        key: form.value.key,
        name: form.value.name,
        description: form.value.description || null,
        permissions: {},
      });
      notify('Группа создана', 'success', 'mdi-shield-plus');
    } else {
      const payload = { name: form.value.name, description: form.value.description || null };
      if (!form.value.isSystem) payload.key = form.value.key;
      await api.patch(`/admin/permissions/groups/${editingId.value}`, payload);
      notify('Сохранено', 'success', 'mdi-check-circle');
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
    notify('Группа удалена', 'success', 'mdi-trash-can-outline');
    await load();
  } catch (e) {
    notify(e.response?.data?.message || 'Не удалось удалить', 'error', 'mdi-alert');
  }
}

onMounted(load);
</script>

<style scoped>
.legend-card {
  background: rgba(var(--v-theme-surface-variant), 0.4);
  border: 1px solid rgba(var(--v-theme-on-surface), 0.06);
}

.permissions-card {
  overflow: hidden;
}
.permissions-wrap {
  max-height: calc(100vh - 280px);
  overflow: auto;
}

/* Своя сетка вместо v-table — больше контроля над sticky/typography. */
.permissions-grid {
  border-collapse: separate;
  border-spacing: 0;
  width: 100%;
}
.permissions-grid thead th {
  position: sticky;
  top: 0;
  z-index: 2;
  background: rgb(var(--v-theme-surface));
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  font-weight: 600;
  color: rgba(var(--v-theme-on-surface), 0.72);
  padding: 10px 12px;
  border-bottom: 2px solid rgba(var(--v-theme-on-surface), 0.08);
  white-space: nowrap;
  text-align: left;
}
.th-section {
  text-align: center !important;
  min-width: 116px;
  padding: 8px 6px !important;
}
.th-section__title {
  font-size: 10.5px;
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 130px;
}
.th-section__key {
  display: block;
  margin-top: 2px;
  font-size: 9px;
  font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  color: rgba(var(--v-theme-on-surface), 0.45);
  text-transform: lowercase;
  letter-spacing: 0;
}

.th-group, .td-group {
  position: sticky;
  left: 0;
  background: rgb(var(--v-theme-surface));
  z-index: 1;
  min-width: 280px;
  max-width: 320px;
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  /* Тень-«край» — видно что контент уезжает под sticky-колонку при
     горизонтальном скролле. Без этого пользователь не понимает где
     заканчивается фиксированная часть и начинается прокручиваемая. */
  box-shadow: 6px 0 8px -4px rgba(0, 0, 0, 0.1);
}
.th-group { z-index: 3; }   /* пересечение sticky-row и sticky-col */

.permissions-grid tbody td {
  padding: 12px 6px;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.04);
  vertical-align: middle;
}
.td-group {
  padding: 14px 12px !important;
}
.td-group__name {
  font-size: 14px;
  font-weight: 500;
  line-height: 1.25;
}
.td-group__meta {
  margin-top: 2px;
}
.td-group__key {
  font-size: 11px;
  font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  color: rgba(var(--v-theme-on-surface), 0.55);
  background: rgba(var(--v-theme-on-surface), 0.06);
  padding: 1px 6px;
  border-radius: 4px;
}
.td-group__desc {
  font-size: 11px;
  line-height: 1.3;
  color: rgba(var(--v-theme-on-surface), 0.6);
  margin-top: 6px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.td-cell {
  text-align: center;
}
.cell-chip {
  cursor: pointer;
  min-width: 76px;
  justify-content: center;
  transition: transform 0.1s ease, box-shadow 0.15s ease;
}
.cell-chip:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
}
.cell-chip--empty {
  opacity: 0.55;
}
.cell-chip--empty:hover {
  opacity: 1;
}
.cell-chip--saving {
  opacity: 0.7;
}

.tr-system .td-group {
  background: rgba(var(--v-theme-surface-variant), 0.18);
}
.tr-admin .td-group {
  background: linear-gradient(90deg,
    rgba(var(--v-theme-secondary), 0.1),
    rgba(var(--v-theme-surface-variant), 0.18)
  );
}

.th-actions, .td-actions {
  width: 32px;
  position: sticky;
  right: 0;
  z-index: 1;
  background: rgb(var(--v-theme-surface));
  border-left: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  text-align: center;
  /* Аналогичная тень с правой стороны — симметрично левому sticky. */
  box-shadow: -6px 0 8px -4px rgba(0, 0, 0, 0.1);
}
.th-actions { z-index: 3; }

/* Тёмная тема — тени контрастнее, чтобы не сливались с фоном. */
:global(.v-theme--dark) .th-group,
:global(.v-theme--dark) .td-group {
  box-shadow: 6px 0 12px -4px rgba(0, 0, 0, 0.4) !important;
}
:global(.v-theme--dark) .th-actions,
:global(.v-theme--dark) .td-actions {
  box-shadow: -6px 0 12px -4px rgba(0, 0, 0, 0.4) !important;
}
</style>
