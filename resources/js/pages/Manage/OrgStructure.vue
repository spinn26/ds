<template>
  <div>
    <PageHeader title="Структура компании" icon="mdi-sitemap-outline">
      <template #actions>
        <v-btn v-if="isAdmin" color="primary" size="small" prepend-icon="mdi-plus" @click="openCreate(null)">Отдел</v-btn>
      </template>
    </PageHeader>

    <div v-if="flatTree.length" class="org-tree">
      <div v-for="row in flatTree" :key="row.dept.id" class="org-node" :style="{ marginLeft: row.depth * 32 + 'px' }">
        <span v-if="row.depth" class="org-node__connector" />
        <v-card class="org-card" :class="{ 'org-card--root': !row.depth }">
          <div class="org-card__head">
            <v-btn :icon="expanded.has(row.dept.id) ? 'mdi-chevron-down' : 'mdi-chevron-right'"
              size="x-small" variant="text" :style="{ visibility: row.hasChildren ? 'visible' : 'hidden' }"
              @click="toggle(row.dept.id)" />
            <div class="org-card__icon"><v-icon size="18" color="primary">mdi-office-building-outline</v-icon></div>
            <span class="org-card__name">{{ row.dept.name }}</span>
            <v-chip size="x-small" variant="tonal" color="primary" class="ml-1">{{ row.dept.members.length }} чел.</v-chip>
            <v-spacer />
            <v-menu v-if="isAdmin">
              <template #activator="{ props }">
                <v-btn v-bind="props" icon="mdi-dots-vertical" size="x-small" variant="text" />
              </template>
              <v-list density="compact">
                <v-list-item title="Подотдел" prepend-icon="mdi-plus" @click="openCreate(row.dept.id)" />
                <v-list-item title="Сотрудники" prepend-icon="mdi-account-multiple-plus" @click="openMembers(row.dept)" />
                <v-list-item title="Редактировать" prepend-icon="mdi-pencil" @click="openEdit(row.dept)" />
                <v-list-item title="Удалить" prepend-icon="mdi-delete" @click="remove(row.dept)" />
              </v-list>
            </v-menu>
          </div>

          <div class="org-card__roles">
            <!-- Руководитель -->
            <div class="org-role">
              <div class="org-role__label">Руководитель</div>
              <button v-if="row.dept.head" type="button" class="org-person" @click="openEmployee(row.dept.head.id)">
                <v-avatar size="30" color="primary"><span class="text-caption">{{ initials(row.dept.head.name) }}</span></v-avatar>
                <span class="org-person__name">{{ row.dept.head.name }}</span>
              </button>
              <span v-else class="org-role__empty">—</span>
            </div>
            <!-- Заместитель -->
            <div class="org-role">
              <div class="org-role__label">Заместитель</div>
              <button v-if="row.dept.deputy" type="button" class="org-person" @click="openEmployee(row.dept.deputy.id)">
                <v-avatar size="30" color="secondary"><span class="text-caption">{{ initials(row.dept.deputy.name) }}</span></v-avatar>
                <span class="org-person__name">{{ row.dept.deputy.name }}</span>
              </button>
              <span v-else class="org-role__empty">—</span>
            </div>
            <!-- Сотрудники -->
            <div class="org-role org-role--members">
              <div class="org-role__label">Сотрудники</div>
              <div v-if="row.dept.members.length" class="d-flex align-center flex-wrap ga-1">
                <button v-for="m in row.dept.members.slice(0, 8)" :key="m.id" type="button" class="org-mini"
                  :title="m.name" @click="openEmployee(m.id)">
                  <v-avatar size="30" color="surface-variant"><span class="text-caption">{{ initials(m.name) }}</span></v-avatar>
                </button>
                <span v-if="row.dept.members.length > 8" class="text-caption text-medium-emphasis ml-1">+{{ row.dept.members.length - 8 }}</span>
                <v-btn v-if="isAdmin" icon="mdi-plus" size="x-small" variant="tonal" class="ml-1" @click="openMembers(row.dept)" />
              </div>
              <v-btn v-else-if="isAdmin" size="x-small" variant="text" prepend-icon="mdi-account-plus-outline"
                @click="openMembers(row.dept)">Добавить</v-btn>
              <span v-else class="org-role__empty">—</span>
            </div>
          </div>
        </v-card>
      </div>
    </div>
    <EmptyState v-else message="Отделы не созданы" />

    <!-- Создание/редактирование отдела -->
    <v-dialog v-model="dialog" max-width="540">
      <v-card>
        <v-card-title>{{ form.id ? 'Отдел' : 'Новый отдел' }}</v-card-title>
        <v-card-text>
          <v-text-field v-model="form.name" label="Название *" density="comfortable" autofocus />
          <v-textarea v-model="form.description" label="Описание" density="comfortable" rows="2" class="mt-1" />
          <v-select v-model="form.parent_id" :items="parentOptions" label="Вышестоящий отдел" density="comfortable"
            clearable class="mt-1" />
          <UserPicker v-model="form.head_id" label="Руководитель" class="mt-1" />
          <UserPicker v-model="form.deputy_id" label="Заместитель" class="mt-1" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="dialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" :disabled="!form.name.trim()" @click="save">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Сотрудники отдела -->
    <v-dialog v-model="membersDialog" max-width="520">
      <v-card>
        <v-card-title>Сотрудники: {{ membersDept?.name }}</v-card-title>
        <v-card-text>
          <UserPicker v-model="newMembers" multiple label="Добавить сотрудников" />
          <div class="mt-3 d-flex flex-wrap ga-2">
            <v-chip v-for="m in membersDept?.members || []" :key="m.id" size="small" closable
              @click:close="removeMember(membersDept, m)">{{ m.name }}</v-chip>
            <span v-if="!membersDept?.members?.length" class="text-caption text-medium-emphasis">Пока никого</span>
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="membersDialog = false">Закрыть</v-btn>
          <v-btn color="primary" :loading="savingMembers" :disabled="!newMembers.length" @click="saveMembers">Добавить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Мини-профиль сотрудника -->
    <v-dialog v-model="empDialog" max-width="420">
      <v-card v-if="emp">
        <v-card-text class="text-center pt-5">
          <v-avatar size="64" color="primary" class="mb-2"><span class="text-h6">{{ initials(emp.name) }}</span></v-avatar>
          <div class="text-h6">{{ emp.name }}</div>
          <div v-if="emp.position" class="text-body-2 text-medium-emphasis">{{ emp.position }}</div>
          <v-chip size="x-small" :color="emp.status === 'Активен' ? 'success' : (emp.status === 'Уволен' ? 'error' : 'warning')"
            variant="tonal" class="mt-1">{{ emp.status }}</v-chip>
          <v-list density="compact" class="mt-2 text-left">
            <v-list-item v-if="emp.email" :title="emp.email" prepend-icon="mdi-email-outline" :href="`mailto:${emp.email}`" />
            <v-list-item v-if="emp.phone" :title="emp.phone" prepend-icon="mdi-phone-outline" :href="`tel:${emp.phone}`" />
            <v-list-item v-if="emp.role" :title="roleLabel(emp.role)" prepend-icon="mdi-shield-account-outline" />
          </v-list>
          <div v-if="emp.departments?.length" class="text-left mt-2">
            <div class="text-caption text-medium-emphasis mb-1">Отделы</div>
            <div v-for="d in emp.departments" :key="d.id" class="text-body-2">
              {{ d.name }} <span class="text-caption text-medium-emphasis">· {{ d.role }}</span>
            </div>
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="empDialog = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="2500">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, EmptyState } from '../../components';
import UserPicker from '../../components/UserPicker.vue';
import { useAuthStore } from '../../stores/auth';

const auth = useAuthStore();
const isAdmin = computed(() => auth.isAdmin);

const departments = ref([]);
const loading = ref(false);
const expanded = ref(new Set());
const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }
function initials(name) { return (name || '?').split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase(); }

// Дерево → плоский список с глубиной (учёт свёрнутых).
const childrenOf = computed(() => {
  const map = {};
  for (const d of departments.value) (map[d.parent_id || 0] ||= []).push(d);
  return map;
});
const flatTree = computed(() => {
  const out = [];
  const walk = (parentId, depth) => {
    for (const d of (childrenOf.value[parentId || 0] || [])) {
      const hasChildren = !!(childrenOf.value[d.id] || []).length;
      out.push({ dept: d, depth, hasChildren });
      if (hasChildren && expanded.value.has(d.id)) walk(d.id, depth + 1);
    }
  };
  walk(0, 0);
  return out;
});
const parentOptions = computed(() => departments.value
  .filter((d) => d.id !== form.id)
  .map((d) => ({ title: d.name, value: d.id })));

function toggle(id) {
  const s = new Set(expanded.value);
  s.has(id) ? s.delete(id) : s.add(id);
  expanded.value = s;
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/org/departments');
    departments.value = data.departments || [];
    // По умолчанию раскрываем верхний уровень.
    if (!expanded.value.size) expanded.value = new Set(departments.value.filter((d) => !d.parent_id).map((d) => d.id));
  } catch (e) { notify(e.response?.data?.message || 'Ошибка загрузки', 'error'); }
  loading.value = false;
}

// CRUD отдела
const dialog = ref(false);
const saving = ref(false);
const form = reactive({ id: null, name: '', description: '', parent_id: null, head_id: null, deputy_id: null });
function emptyForm() { return { id: null, name: '', description: '', parent_id: null, head_id: null, deputy_id: null }; }
function openCreate(parentId) { Object.assign(form, emptyForm(), { parent_id: parentId }); dialog.value = true; }
function openEdit(d) { Object.assign(form, { id: d.id, name: d.name, description: d.description || '', parent_id: d.parent_id, head_id: d.head?.id ?? null, deputy_id: d.deputy?.id ?? null }); dialog.value = true; }
async function save() {
  saving.value = true;
  try {
    if (form.id) await api.put(`/org/departments/${form.id}`, form);
    else await api.post('/org/departments', form);
    dialog.value = false; await load(); notify('Сохранено');
  } catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
  saving.value = false;
}
async function remove(d) {
  if (!confirm(`Удалить отдел «${d.name}»? Подотделы и сотрудники перейдут на уровень выше.`)) return;
  try { await api.delete(`/org/departments/${d.id}`); await load(); notify('Удалено'); }
  catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
}

// Сотрудники
const membersDialog = ref(false);
const membersDept = ref(null);
const newMembers = ref([]);
const savingMembers = ref(false);
function openMembers(d) { membersDept.value = d; newMembers.value = []; membersDialog.value = true; }
async function saveMembers() {
  savingMembers.value = true;
  try {
    await api.post(`/org/departments/${membersDept.value.id}/members`, { user_ids: newMembers.value });
    newMembers.value = []; await load();
    membersDept.value = departments.value.find((x) => x.id === membersDept.value.id);
    notify('Добавлено');
  } catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
  savingMembers.value = false;
}
async function removeMember(d, m) {
  try {
    await api.delete(`/org/departments/${d.id}/members/${m.id}`); await load();
    membersDept.value = departments.value.find((x) => x.id === d.id);
  } catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
}

// Русские подписи ролей (роль в WebUser может быть CSV).
const ROLE_LABELS = {
  admin: 'Администратор', head: 'Руководитель', backoffice: 'Бэк-офис', finance: 'Финансы',
  support: 'Поддержка', calculations: 'Расчёты', corrections: 'Корректировки',
  education: 'Обучение', consultant: 'Партнёр', manager: 'Менеджер',
};
function roleLabel(role) {
  return String(role || '').split(/[,\s]+/).filter(Boolean)
    .map((r) => ROLE_LABELS[r] || r).join(', ') || '—';
}

// Мини-профиль (только просмотр; должность задаётся в админке пользователей)
const empDialog = ref(false);
const emp = ref(null);
async function openEmployee(id) {
  emp.value = null;
  empDialog.value = true;
  try {
    const { data } = await api.get(`/org/employees/${id}`);
    emp.value = data.employee;
  } catch { /* ignore */ }
}

onMounted(load);
</script>

<style scoped>
.org-tree { display: flex; flex-direction: column; gap: 12px; }
.org-node { position: relative; }
/* Соединительная линия от родителя к подотделу. */
.org-node__connector { position: absolute; left: -18px; top: -12px; bottom: 50%; width: 18px;
  border-left: 2px solid rgba(var(--v-theme-primary), 0.25); border-bottom: 2px solid rgba(var(--v-theme-primary), 0.25);
  border-bottom-left-radius: 10px; }
.org-card { border-radius: 14px !important; border: 1px solid rgba(var(--v-border-color), 0.08) !important;
  box-shadow: 0 1px 2px rgba(15,30,15,0.03) !important; padding: 4px 8px 12px; transition: box-shadow .15s ease, border-color .15s ease; }
.org-card:hover { box-shadow: 0 6px 18px rgba(15,30,15,0.08) !important; }
.org-card--root { border-color: rgba(var(--v-theme-primary), 0.25) !important;
  border-left: 3px solid rgb(var(--v-theme-primary)) !important; }
.org-card__head { display: flex; align-items: center; gap: 6px; padding: 6px 6px 2px; }
.org-card__icon { width: 30px; height: 30px; border-radius: 8px; display: grid; place-items: center;
  background: rgba(var(--v-theme-primary), 0.1); flex-shrink: 0; }
.org-card__name { font-weight: 700; font-size: 0.95rem; letter-spacing: -0.01em; }
.org-card__roles { display: flex; flex-wrap: wrap; gap: 10px 28px; padding: 8px 10px 4px 12px; }
.org-role { min-width: 0; }
.org-role--members { flex: 1; min-width: 200px; }
.org-role__label { font-size: 0.66rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;
  color: rgba(var(--v-theme-on-surface), 0.45); margin-bottom: 4px; }
.org-role__empty { color: rgba(var(--v-theme-on-surface), 0.35); font-size: 0.85rem; }
.org-person { display: inline-flex; align-items: center; gap: 8px; border: 0; background: transparent; cursor: pointer;
  padding: 2px 6px 2px 2px; border-radius: 8px; transition: background .12s ease; max-width: 220px; }
.org-person:hover { background: rgba(var(--v-theme-primary), 0.08); }
.org-person__name { font-size: 0.84rem; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.org-mini { border: 0; background: transparent; cursor: pointer; padding: 0; }
.org-mini :deep(.v-avatar) { border: 2px solid rgb(var(--v-theme-surface)); margin-left: -6px; transition: transform .12s ease; }
.org-mini:first-child :deep(.v-avatar) { margin-left: 0; }
.org-mini:hover :deep(.v-avatar) { transform: translateY(-2px); }
</style>
