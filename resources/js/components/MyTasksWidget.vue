<template>
  <v-card class="pa-4">
    <div class="d-flex align-center justify-space-between mb-3">
      <div class="text-subtitle-1 font-weight-bold">
        <v-icon class="mr-1" size="20" color="primary">mdi-checkbox-marked-circle-outline</v-icon>
        Мои задачи
        <span v-if="counts.todo" class="text-caption text-medium-emphasis ms-1">
          · {{ counts.todo }} в работе
        </span>
      </div>
      <v-btn size="x-small" variant="tonal" color="primary"
        prepend-icon="mdi-plus" @click="openCreate">Добавить</v-btn>
    </div>

    <!-- Inline-форма для быстрой добавки -->
    <div v-if="creating" class="mt-2 mb-3 pa-3 rounded create-form">
      <v-text-field v-model="newTask.title" placeholder="Что нужно сделать?"
        variant="outlined" density="compact" hide-details autofocus
        @keyup.enter="saveCreate" @keyup.esc="creating = false" class="mb-2" />
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-select v-model="newTask.priority" :items="PRIORITIES"
          item-title="label" item-value="value" placeholder="Приоритет"
          variant="outlined" density="compact" hide-details clearable
          style="max-width: 160px" />
        <v-text-field v-model="newTask.due_date" type="date"
          variant="outlined" density="compact" hide-details
          style="max-width: 170px" />
        <v-spacer />
        <v-btn size="small" variant="text" @click="creating = false">Отмена</v-btn>
        <v-btn size="small" color="primary" :loading="saving"
          :disabled="!newTask.title.trim()" @click="saveCreate">Сохранить</v-btn>
      </div>
    </div>

    <div v-if="loading" class="text-center py-4">
      <v-progress-circular indeterminate size="24" />
    </div>
    <div v-else-if="!tasks.length && !creating" class="text-center text-medium-emphasis pa-4">
      <v-icon size="36" color="grey-lighten-1">mdi-clipboard-text-outline</v-icon>
      <div class="text-body-2 mt-2">Пока ничего не запланировано</div>
      <v-btn class="mt-3" size="small" variant="tonal" color="primary"
        prepend-icon="mdi-plus" @click="openCreate">Добавить первую</v-btn>
    </div>
    <v-list v-else density="compact" class="pa-0">
      <v-list-item v-for="t in tasks" :key="t.id" class="task-row"
        :class="{ done: t.is_done, overdue: isOverdue(t) }">
        <template #prepend>
          <v-checkbox-btn :model-value="t.is_done"
            color="primary" @update:model-value="toggleDone(t)" />
        </template>
        <v-list-item-title class="text-body-2"
          :class="{ 'text-decoration-line-through text-medium-emphasis': t.is_done }">
          {{ t.title }}
        </v-list-item-title>
        <v-list-item-subtitle v-if="t.due_date || t.priority" class="text-caption mt-1">
          <span v-if="t.due_date" class="me-2">
            <v-icon size="11" class="me-1">mdi-calendar</v-icon>{{ fmtDate(t.due_date) }}
          </span>
          <v-chip v-if="t.priority" size="x-small" :color="priorityColor(t.priority)" variant="tonal">
            {{ priorityLabel(t.priority) }}
          </v-chip>
        </v-list-item-subtitle>
        <template #append>
          <v-btn icon="mdi-delete" size="x-small" variant="text"
            color="error" title="Удалить" @click="removeTask(t)" />
        </template>
      </v-list-item>
    </v-list>
  </v-card>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api';
import { useSnackbar } from '../composables/useSnackbar';

const { showError } = useSnackbar();

const PRIORITIES = [
  { value: 'high',   label: 'Высокий' },
  { value: 'medium', label: 'Средний' },
  { value: 'low',    label: 'Низкий' },
];
function priorityColor(p) { return { high: 'error', medium: 'warning', low: 'success' }[p] || 'grey'; }
function priorityLabel(p) { return PRIORITIES.find(x => x.value === p)?.label || p; }

const loading = ref(true);
const tasks = ref([]);
const creating = ref(false);
const saving = ref(false);
const newTask = ref({ title: '', priority: null, due_date: null });

const counts = computed(() => ({
  todo: tasks.value.filter(t => !t.is_done).length,
  done: tasks.value.filter(t => t.is_done).length,
}));

function fmtDate(iso) {
  if (!iso) return '';
  return new Date(iso).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
}
function isOverdue(t) {
  if (!t.due_date || t.is_done) return false;
  return new Date(t.due_date) < new Date(new Date().toDateString());
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/my-tasks');
    tasks.value = data || [];
  } catch {}
  loading.value = false;
}

function openCreate() {
  creating.value = true;
  newTask.value = { title: '', priority: null, due_date: null };
}

async function saveCreate() {
  if (!newTask.value.title.trim()) return;
  saving.value = true;
  try {
    await api.post('/my-tasks', {
      title: newTask.value.title.trim(),
      priority: newTask.value.priority || null,
      due_date: newTask.value.due_date || null,
    });
    creating.value = false;
    await load();
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось сохранить');
  }
  saving.value = false;
}

async function toggleDone(t) {
  const next = !t.is_done;
  t.is_done = next; // optimistic
  try {
    await api.put(`/my-tasks/${t.id}`, { is_done: next });
  } catch (e) {
    t.is_done = !next; // rollback
    showError('Не удалось обновить');
  }
}

async function removeTask(t) {
  if (!confirm(`Удалить «${t.title}»?`)) return;
  try {
    await api.delete(`/my-tasks/${t.id}`);
    tasks.value = tasks.value.filter(x => x.id !== t.id);
  } catch (e) {
    showError('Не удалось удалить');
  }
}

onMounted(load);
</script>

<style scoped>
.create-form {
  background: rgba(var(--v-theme-surface-variant), 0.4);
}
.task-row.done { opacity: 0.65; }
.task-row.overdue :deep(.v-list-item-subtitle) { color: rgb(var(--v-theme-error)) !important; }
.task-row :deep(.v-list-item__prepend) { padding-inline-end: 8px !important; }
</style>
