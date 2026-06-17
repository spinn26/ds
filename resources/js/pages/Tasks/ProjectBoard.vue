<template>
  <div>
    <div class="d-flex align-center flex-wrap ga-2 mb-4">
      <v-btn icon="mdi-arrow-left" size="small" variant="text" to="/tasks" />
      <div class="page-board-dot" :style="{ background: project.color }" />
      <h5 class="text-h6 mb-0">{{ project.name || 'Проект' }}</h5>
      <v-spacer />
      <v-btn-toggle v-model="view" density="compact" variant="outlined" mandatory>
        <v-btn value="board" icon="mdi-view-column" size="small" />
        <v-btn value="list" icon="mdi-format-list-bulleted" size="small" />
        <v-btn value="gantt" icon="mdi-chart-timeline" size="small" />
      </v-btn-toggle>
      <v-btn color="primary" size="small" prepend-icon="mdi-plus" @click="openCreate(null)">Задача</v-btn>
    </div>

    <!-- Канбан -->
    <div v-if="view === 'board'" class="kanban" :class="{ 'kanban--loading': loading }">
      <div v-for="stage in stages" :key="stage.id" class="kanban-col"
        @dragover.prevent @drop="onDrop(stage)">
        <div class="kanban-col__head" :style="{ borderTopColor: stage.color }">
          <div class="d-flex align-center ga-2">
            <span class="kanban-col__dot" :style="{ background: stage.color }" />
            <span class="kanban-col__title">{{ stage.name }}</span>
            <span class="kanban-col__count">{{ tasksByStage(stage.id).length }}</span>
          </div>
          <v-menu>
            <template #activator="{ props }">
              <v-btn v-bind="props" icon="mdi-dots-horizontal" size="x-small" variant="text" />
            </template>
            <v-list density="compact">
              <v-list-item title="Переименовать" prepend-icon="mdi-pencil" @click="editStage(stage)" />
              <v-list-item title="Цвет" prepend-icon="mdi-palette" @click="editStage(stage)" />
              <v-list-item title="Удалить" prepend-icon="mdi-delete" @click="deleteStage(stage)" />
            </v-list>
          </v-menu>
        </div>

        <div class="kanban-col__body">
          <v-card v-for="t in tasksByStage(stage.id)" :key="t.id" class="kanban-card" draggable="true"
            @dragstart="onDragStart(t)" @click="openTask(t.id)">
            <div v-if="t.priority === 'high'" class="kanban-card__prio" />
            <div class="kanban-card__title">{{ t.title }}</div>
            <div class="d-flex align-center justify-space-between mt-2">
              <span v-if="t.deadline" class="text-caption" :class="overdue(t) ? 'text-error' : 'text-medium-emphasis'">
                <v-icon size="13">mdi-clock-outline</v-icon> {{ fmtDate(t.deadline) }}
              </span>
              <span v-else />
              <div class="d-flex align-center ga-1">
                <v-icon v-if="t.comments_count" size="13" class="text-medium-emphasis">mdi-comment-outline</v-icon>
                <span v-if="t.comments_count" class="text-caption text-medium-emphasis">{{ t.comments_count }}</span>
                <v-avatar v-if="t.assignee" size="22" color="primary" :title="t.assignee.name">
                  <span class="text-caption">{{ initials(t.assignee.name) }}</span>
                </v-avatar>
              </div>
            </div>
          </v-card>

          <v-btn variant="text" size="small" block class="mt-1 text-medium-emphasis" prepend-icon="mdi-plus"
            @click="openCreate(stage.id)">Задача</v-btn>
        </div>
      </div>

      <!-- Добавить колонку -->
      <div class="kanban-col kanban-col--add">
        <v-btn variant="tonal" block prepend-icon="mdi-plus" @click="addStageDialog = true">Колонка</v-btn>
      </div>
    </div>

    <!-- Список -->
    <v-card v-else-if="view === 'list'">
      <v-data-table :items="tasks" :headers="listHeaders" density="comfortable" hover @click:row="(e, { item }) => openTask(item.id)">
        <template #item.status="{ value }"><v-chip size="x-small" :color="statusColor(value)" variant="tonal">{{ statusLabel(value) }}</v-chip></template>
        <template #item.assignee="{ item }">{{ item.assignee?.name || '—' }}</template>
        <template #item.deadline="{ value }">{{ value ? fmtDate(value) : '—' }}</template>
        <template #no-data><EmptyState message="Задач нет" /></template>
      </v-data-table>
    </v-card>

    <!-- Гант -->
    <v-card v-else class="pa-0 gantt-card">
      <div v-if="ganttRows.length" class="gantt">
        <!-- Шапка: месяцы + дни -->
        <div class="gantt-head">
          <div class="gantt-labels-col gantt-head__corner">Задача</div>
          <div class="gantt-timeline">
            <div class="gantt-months">
              <div v-for="mo in ganttMonths" :key="mo.key" class="gantt-month" :style="{ width: mo.days * dayW + 'px' }">{{ mo.label }}</div>
            </div>
            <div class="gantt-days">
              <div v-for="d in ganttDays" :key="d.key" class="gantt-day" :class="{ 'gantt-day--we': d.weekend, 'gantt-day--today': d.today }"
                :style="{ width: dayW + 'px' }">{{ d.day }}</div>
            </div>
          </div>
        </div>
        <!-- Строки -->
        <div class="gantt-body">
          <div v-for="r in ganttRows" :key="r.id" class="gantt-row" @click="openTask(r.id)">
            <div class="gantt-labels-col gantt-row__label" :title="r.title">{{ r.title }}</div>
            <div class="gantt-timeline gantt-row__track" :style="{ width: ganttDays.length * dayW + 'px' }">
              <div class="gantt-bar" :class="{ 'gantt-bar--done': r.status === 'done' }"
                :style="{ left: r.offset * dayW + 'px', width: r.span * dayW + 'px', background: statusHex(r.status) }">
                <span class="gantt-bar__label">{{ r.assignee?.name || '' }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
      <EmptyState v-else message="Нет задач со сроками для диаграммы" />
    </v-card>

    <!-- Диалог создания задачи -->
    <v-dialog v-model="createDialog" max-width="540">
      <v-card>
        <v-card-title>Новая задача</v-card-title>
        <v-card-text>
          <v-text-field v-model="newTask.title" label="Название *" density="comfortable" autofocus
            @keyup.enter="createTask" />
          <UserPicker v-model="newTask.assignee_id" label="Исполнитель" class="mt-2" />
          <v-text-field v-model="newTask.deadline" type="datetime-local" label="Крайний срок" density="comfortable" class="mt-2" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="createDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="creating" :disabled="!newTask.title.trim()" @click="createTask">Создать</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Диалог колонки -->
    <v-dialog v-model="addStageDialog" max-width="440">
      <v-card>
        <v-card-title>{{ editingStage ? 'Колонка' : 'Новая колонка' }}</v-card-title>
        <v-card-text>
          <v-text-field v-model="stageForm.name" label="Название" density="comfortable" autofocus />
          <div class="mt-2 mb-1 text-caption text-medium-emphasis">Цвет</div>
          <div class="d-flex flex-wrap ga-2">
            <button v-for="c in palette" :key="c" class="color-swatch" :style="{ background: c }"
              :class="{ 'color-swatch--active': stageForm.color === c }" @click="stageForm.color = c" />
          </div>
          <v-switch v-if="editingStage" v-model="stageForm.is_done" label="Колонка «Готово» (помечает задачи выполненными)"
            color="success" density="compact" hide-details class="mt-2" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="closeStageDialog">Отмена</v-btn>
          <v-btn color="primary" :loading="savingStage" @click="saveStage">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <TaskDetailDrawer v-model="detailOpen" :task-id="activeTaskId" @updated="onTaskUpdated" @deleted="onTaskDeleted" />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '../../api';
import { EmptyState } from '../../components';
import UserPicker from '../../components/UserPicker.vue';
import TaskDetailDrawer from '../../components/TaskDetailDrawer.vue';

const route = useRoute();
const projectId = computed(() => Number(route.params.id));

const project = ref({});
const stages = ref([]);
const tasks = ref([]);
const loading = ref(false);
const view = ref('board');

const palette = ['#90A4AE', '#42A5F5', '#26A69A', '#66BB6A', '#FFA726', '#EF5350', '#AB47BC', '#5C6BC0', '#EC407A', '#8D6E63'];
const listHeaders = [
  { title: 'Задача', key: 'title' },
  { title: 'Статус', key: 'status', width: 130 },
  { title: 'Исполнитель', key: 'assignee', width: 180 },
  { title: 'Срок', key: 'deadline', width: 140 },
];
const STATUS = { pending: ['Ждёт', 'grey'], in_progress: ['В работе', 'info'], done: ['Готово', 'success'], deferred: ['Отложена', 'warning'], rejected: ['Отклонена', 'error'] };
function statusLabel(s) { return STATUS[s]?.[0] || s; }
function statusColor(s) { return STATUS[s]?.[1] || 'grey'; }
function initials(name) { return (name || '?').split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase(); }
function fmtDate(s) { const d = new Date(s); return isNaN(d) ? '' : d.toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' }); }
function overdue(t) { return t.deadline && new Date(t.deadline) < new Date() && t.status !== 'done'; }

function tasksByStage(stageId) { return tasks.value.filter((t) => t.stage_id === stageId); }

// ─── Гант ───
const dayW = 28;
const STATUS_HEX = { pending: '#90A4AE', in_progress: '#42A5F5', done: '#66BB6A', deferred: '#FFA726', rejected: '#EF5350' };
function statusHex(s) { return STATUS_HEX[s] || '#90A4AE'; }
function startOfDay(s) { if (!s) return null; const d = new Date(s); if (isNaN(d)) return null; d.setHours(0, 0, 0, 0); return d; }
function diffDays(a, b) { return Math.round((b - a) / 86400000); }

const ganttModel = computed(() => {
  // Старт задачи — created_at, конец — deadline (или старт, если срока нет).
  const items = tasks.value.map((t) => {
    const start = startOfDay(t.created_at);
    const end = startOfDay(t.deadline) || start;
    return start ? { ...t, _start: start, _end: end < start ? start : end } : null;
  }).filter(Boolean);
  if (!items.length) return { days: [], months: [], rows: [] };

  let min = items[0]._start, max = items[0]._end;
  for (const it of items) { if (it._start < min) min = it._start; if (it._end > max) max = it._end; }
  // Поля по краям для читаемости.
  min = new Date(min); min.setDate(min.getDate() - 1);
  max = new Date(max); max.setDate(max.getDate() + 2);

  const today = startOfDay(new Date());
  const days = [];
  const totalDays = diffDays(min, max);
  for (let i = 0; i <= totalDays; i++) {
    const d = new Date(min); d.setDate(min.getDate() + i);
    const dow = d.getDay();
    days.push({ key: i, day: d.getDate(), weekend: dow === 0 || dow === 6, today: today && d.getTime() === today.getTime() });
  }
  // Месяцы (группировка дней).
  const months = [];
  for (let i = 0; i <= totalDays; i++) {
    const d = new Date(min); d.setDate(min.getDate() + i);
    const key = `${d.getFullYear()}-${d.getMonth()}`;
    const last = months[months.length - 1];
    if (last && last.key === key) last.days++;
    else months.push({ key, label: d.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' }), days: 1 });
  }
  const rows = items.map((it) => ({
    ...it,
    offset: diffDays(min, it._start),
    span: Math.max(1, diffDays(it._start, it._end) + 1),
  }));
  return { days, months, rows };
});
const ganttDays = computed(() => ganttModel.value.days);
const ganttMonths = computed(() => ganttModel.value.months);
const ganttRows = computed(() => ganttModel.value.rows);

async function loadBoard() {
  loading.value = true;
  try {
    const { data } = await api.get(`/projects/${projectId.value}/board`);
    project.value = data.project;
    stages.value = data.stages;
    tasks.value = data.tasks;
  } catch { /* ignore */ }
  loading.value = false;
}

// ─── drag-n-drop ───
const dragged = ref(null);
function onDragStart(t) { dragged.value = t; }
async function onDrop(stage) {
  if (!dragged.value || dragged.value.stage_id === stage.id) { dragged.value = null; return; }
  const t = dragged.value;
  dragged.value = null;
  const prevStage = t.stage_id;
  t.stage_id = stage.id; // оптимистично
  try {
    const maxOrder = Math.max(0, ...tasksByStage(stage.id).map((x) => x.sort_order || 0));
    const { data } = await api.post(`/tasks/${t.id}/move`, { stage_id: stage.id, sort_order: maxOrder + 1 });
    Object.assign(t, data.task);
  } catch { t.stage_id = prevStage; }
}

// ─── создание задачи ───
const createDialog = ref(false);
const creating = ref(false);
const newTask = reactive({ title: '', assignee_id: null, deadline: '', stage_id: null });
function openCreate(stageId) {
  Object.assign(newTask, { title: '', assignee_id: null, deadline: '', stage_id: stageId ?? stages.value[0]?.id ?? null });
  createDialog.value = true;
}
async function createTask() {
  if (!newTask.title.trim()) return;
  creating.value = true;
  try {
    const { data } = await api.post('/tasks', {
      project_id: projectId.value, stage_id: newTask.stage_id,
      title: newTask.title.trim(), assignee_id: newTask.assignee_id, deadline: newTask.deadline || null,
    });
    tasks.value.push(data.task);
    createDialog.value = false;
  } catch { /* ignore */ }
  creating.value = false;
}

// ─── стадии ───
const addStageDialog = ref(false);
const savingStage = ref(false);
const editingStage = ref(null);
const stageForm = reactive({ name: '', color: '#90A4AE', is_done: false });
function editStage(s) { editingStage.value = s; Object.assign(stageForm, { name: s.name, color: s.color, is_done: s.is_done }); addStageDialog.value = true; }
function closeStageDialog() { addStageDialog.value = false; editingStage.value = null; Object.assign(stageForm, { name: '', color: '#90A4AE', is_done: false }); }
async function saveStage() {
  savingStage.value = true;
  try {
    if (editingStage.value) await api.put(`/projects/${projectId.value}/stages/${editingStage.value.id}`, stageForm);
    else await api.post(`/projects/${projectId.value}/stages`, { name: stageForm.name, color: stageForm.color });
    await loadBoard();
    closeStageDialog();
  } catch { /* ignore */ }
  savingStage.value = false;
}
async function deleteStage(s) {
  if (!confirm(`Удалить колонку «${s.name}»? Задачи останутся без стадии.`)) return;
  try { await api.delete(`/projects/${projectId.value}/stages/${s.id}`); await loadBoard(); } catch { /* ignore */ }
}

// ─── детальная карточка ───
const detailOpen = ref(false);
const activeTaskId = ref(null);
function openTask(id) { activeTaskId.value = id; detailOpen.value = true; }
function onTaskUpdated(updated) { const i = tasks.value.findIndex((t) => t.id === updated.id); if (i >= 0) Object.assign(tasks.value[i], updated); }
function onTaskDeleted(id) { tasks.value = tasks.value.filter((t) => t.id !== id); }

watch(projectId, loadBoard);
onMounted(loadBoard);
</script>

<style scoped>
.page-board-dot { width: 14px; height: 14px; border-radius: 4px; }
.kanban { display: flex; gap: 12px; overflow-x: auto; padding-bottom: 8px; align-items: flex-start; }
.kanban-col { flex: 0 0 280px; background: rgba(var(--v-theme-on-surface), 0.03); border-radius: 12px; max-height: calc(100vh - 200px); display: flex; flex-direction: column; }
.kanban-col--add { background: transparent; padding-top: 4px; }
.kanban-col__head { display: flex; align-items: center; justify-content: space-between; padding: 8px 10px 8px 12px; border-top: 3px solid transparent; border-radius: 12px 12px 0 0; }
.kanban-col__dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.kanban-col__title { font-weight: 600; font-size: 0.85rem; }
.kanban-col__count { font-size: 0.72rem; background: rgba(var(--v-theme-on-surface), 0.1); border-radius: 10px; padding: 1px 7px; }
.kanban-col__body { padding: 4px 8px 8px; overflow-y: auto; flex: 1; }
.kanban-card { padding: 10px 12px; margin-bottom: 8px; cursor: pointer; position: relative; border-radius: 10px !important; }
.kanban-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,0.1) !important; }
.kanban-card__title { font-size: 0.85rem; line-height: 1.35; }
.kanban-card__prio { position: absolute; top: 0; left: 0; width: 3px; height: 100%; background: rgb(var(--v-theme-error)); border-radius: 10px 0 0 10px; }
.kanban--loading { opacity: 0.6; pointer-events: none; }
.color-swatch { width: 30px; height: 30px; border-radius: 8px; border: 2px solid transparent; cursor: pointer; }
.color-swatch--active { border-color: rgb(var(--v-theme-on-surface)); outline: 2px solid rgba(var(--v-theme-on-surface), 0.2); }

/* Гант */
.gantt-card { overflow: auto; }
.gantt { display: inline-block; min-width: 100%; }
.gantt-labels-col { width: 220px; flex-shrink: 0; padding: 0 12px; box-sizing: border-box; }
.gantt-head { display: flex; position: sticky; top: 0; z-index: 2; background: rgb(var(--v-theme-surface)); border-bottom: 1px solid rgba(var(--v-border-color), 0.1); }
.gantt-head__corner { display: flex; align-items: flex-end; padding-bottom: 6px; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; color: rgba(var(--v-theme-on-surface), 0.5); }
.gantt-timeline { display: flex; flex-direction: column; }
.gantt-months { display: flex; }
.gantt-month { font-size: 0.75rem; font-weight: 600; text-transform: capitalize; padding: 6px 8px 2px; border-left: 1px solid rgba(var(--v-border-color), 0.12); white-space: nowrap; box-sizing: border-box; }
.gantt-days { display: flex; }
.gantt-day { font-size: 0.65rem; text-align: center; color: rgba(var(--v-theme-on-surface), 0.55); padding: 2px 0; box-sizing: border-box; border-left: 1px solid rgba(var(--v-border-color), 0.06); }
.gantt-day--we { background: rgba(var(--v-theme-on-surface), 0.04); }
.gantt-day--today { background: rgba(var(--v-theme-primary), 0.14); font-weight: 700; color: rgb(var(--v-theme-primary)); }
.gantt-row { display: flex; align-items: center; min-height: 38px; cursor: pointer; border-bottom: 1px solid rgba(var(--v-border-color), 0.06); }
.gantt-row:hover { background: rgba(var(--v-theme-primary), 0.04); }
.gantt-row__label { font-size: 0.82rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.gantt-row__track { position: relative; height: 38px; }
.gantt-bar { position: absolute; top: 8px; height: 22px; border-radius: 6px; display: flex; align-items: center; padding: 0 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.18); min-width: 8px; }
.gantt-bar--done { opacity: 0.6; }
.gantt-bar__label { font-size: 0.7rem; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-shadow: 0 1px 1px rgba(0,0,0,0.25); }
</style>
