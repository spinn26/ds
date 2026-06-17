<template>
  <div>
    <PageHeader title="Задачи" icon="mdi-checkbox-marked-outline">
      <template #actions>
        <div class="d-flex align-center ga-2">
          <v-btn variant="text" size="small" prepend-icon="mdi-content-copy" @click="templatesDialog = true">Шаблоны</v-btn>
          <v-btn v-if="isAdmin" variant="text" size="small" prepend-icon="mdi-shield-key-outline" @click="openPerms">Права</v-btn>
          <v-btn color="primary" size="small" prepend-icon="mdi-plus" @click="openCreate(null)">Создать задачу</v-btn>
        </div>
      </template>
    </PageHeader>

    <!-- Виды — современный сегмент-таб -->
    <div class="tasks-views mb-3">
      <button v-for="v in viewTabs" :key="v.key" type="button" class="tasks-view" :class="{ 'tasks-view--active': view === v.key }"
        @click="view = v.key">
        <v-icon size="17">{{ v.icon }}</v-icon>
        <span>{{ v.label }}</span>
        <span v-if="v.count" class="tasks-view__count">{{ visibleTasks.length }}</span>
      </button>
    </div>

    <!-- Панель фильтров -->
    <div class="d-flex align-center flex-wrap ga-2 mb-3">
      <v-chip-group v-model="scope" mandatory selected-class="text-primary" @update:model-value="reload">
        <v-chip value="assigned" size="small" filter>Назначены мне</v-chip>
        <v-chip value="created" size="small" filter>Я поставил</v-chip>
        <v-chip value="accomplice" size="small" filter>Соисполнитель</v-chip>
        <v-chip value="watching" size="small" filter>Наблюдаю</v-chip>
        <v-chip value="favorites" size="small" filter prepend-icon="mdi-star">Избранное</v-chip>
        <v-chip value="all" size="small" filter>Все мои</v-chip>
      </v-chip-group>
      <v-spacer />
      <v-select v-model="preset" :items="presetItems" placeholder="Фильтр" density="compact" variant="outlined"
        hide-details clearable prepend-inner-icon="mdi-filter-variant" style="max-width: 200px" />
      <v-select v-if="availableTags.length" v-model="selectedTags" :items="availableTags" placeholder="Теги"
        density="compact" variant="outlined" hide-details multiple chips closable-chips clearable
        prepend-inner-icon="mdi-tag-outline" style="max-width: 240px" />
      <v-text-field v-model="search" placeholder="Поиск" density="compact" variant="outlined" hide-details
        clearable prepend-inner-icon="mdi-magnify" style="max-width: 240px" @update:model-value="debouncedReload" />
    </div>

    <!-- КАНБАН (кастомные колонки-стадии) -->
    <div v-if="view === 'board'" class="kanban" :class="{ 'kanban--loading': loading }">
      <div v-for="(stage, idx) in stages" :key="stage.id" class="kanban-col" @dragover.prevent @drop="onDrop(stage)">
        <div class="kanban-col__head" :style="{ borderTopColor: stage.color }">
          <span class="kanban-col__dot" :style="{ background: stage.color }" />
          <span class="kanban-col__title">{{ stage.name }}</span>
          <span class="kanban-col__count">{{ stageTasks(stage, idx).length }}</span>
          <v-menu>
            <template #activator="{ props }">
              <v-btn v-bind="props" icon="mdi-dots-horizontal" size="x-small" variant="text" />
            </template>
            <v-list density="compact">
              <v-list-item title="Настроить" prepend-icon="mdi-cog" @click="editStage(stage)" />
              <v-list-item title="Удалить" prepend-icon="mdi-delete" :disabled="stages.length <= 1" @click="deleteStage(stage)" />
            </v-list>
          </v-menu>
        </div>
        <div class="kanban-col__body">
          <v-card v-for="t in stageTasks(stage, idx)" :key="t.id" class="kanban-card" :class="`kanban-card--${t.priority}`"
            draggable="true" @dragstart="onDragStart(t)" @click="openTask(t.id)">
            <div v-if="t.priority !== 'normal'" class="kanban-card__prio" :style="{ background: prioColor(t.priority) }" />
            <div class="d-flex align-start ga-1">
              <span class="kanban-card__title flex-grow-1">{{ t.title }}</span>
              <v-icon v-if="t.is_favorite" size="14" color="amber">mdi-star</v-icon>
            </div>
            <div v-if="t.tags && t.tags.length" class="d-flex flex-wrap ga-1 mt-1">
              <span v-for="tag in t.tags" :key="tag" class="kanban-tag">{{ tag }}</span>
            </div>
            <div class="d-flex align-center justify-space-between mt-2 kanban-card__meta">
              <span v-if="t.deadline" class="kanban-pill" :class="overdue(t) ? 'kanban-pill--overdue' : ''">
                <v-icon size="12">mdi-clock-outline</v-icon>{{ fmtDate(t.deadline) }}
              </span>
              <span v-else />
              <div class="d-flex align-center ga-2">
                <span v-if="t.comments_count" class="d-inline-flex align-center text-medium-emphasis">
                  <v-icon size="14">mdi-comment-outline</v-icon>
                  <span class="text-caption ml-1">{{ t.comments_count }}</span>
                </span>
                <div class="d-flex align-center kanban-avatars">
                  <v-avatar v-for="w in (t.watchers || []).slice(0, 2)" :key="'w' + w.id" size="22"
                    color="surface-variant" :title="'Наблюдатель: ' + w.name" class="kanban-avatar">
                    <span class="text-caption">{{ initials(w.name) }}</span>
                  </v-avatar>
                  <v-avatar v-if="t.assignee" size="24" color="primary" :title="'Исполнитель: ' + t.assignee.name" class="kanban-avatar kanban-avatar--lead">
                    <span class="text-caption">{{ initials(t.assignee.name) }}</span>
                  </v-avatar>
                </div>
              </div>
            </div>
          </v-card>
        </div>
        <button type="button" class="kanban-add" @click="openCreate(stage.id)">
          <v-icon size="16">mdi-plus</v-icon>Задача
        </button>
      </div>
      <!-- Добавить колонку -->
      <div class="kanban-col kanban-col--add">
        <button type="button" class="kanban-add-col" @click="openStageCreate">
          <v-icon size="18">mdi-plus</v-icon>Колонка
        </button>
      </div>
    </div>

    <!-- СПИСОК -->
    <v-card v-else-if="view === 'list'">
      <v-data-table :items="visibleTasks" :headers="headers" :loading="loading" density="comfortable" hover
        @click:row="(e, { item }) => openTask(item.id)">
        <template #item.title="{ item }">
          <span>{{ item.title }}</span>
          <v-icon v-if="overdue(item)" size="14" color="error" class="ml-1" title="Просрочена">mdi-alert-circle</v-icon>
        </template>
        <template #item.status="{ value }"><v-chip size="x-small" :color="statusColor(value)" variant="tonal">{{ statusLabel(value) }}</v-chip></template>
        <template #item.assignee="{ item }">{{ item.assignee?.name || '—' }}</template>
        <template #item.deadline="{ value }">{{ value ? fmtDate(value) : '—' }}</template>
        <template #no-data><EmptyState message="Задач нет" /></template>
      </v-data-table>
    </v-card>

    <!-- КАЛЕНДАРЬ -->
    <template v-else-if="view === 'calendar'">
      <div class="d-flex align-center mb-3 ga-2">
        <v-btn icon="mdi-chevron-left" size="small" variant="text" @click="shiftMonth(-1)" />
        <span class="text-subtitle-1 font-weight-medium cal-month">{{ monthLabel }}</span>
        <v-btn icon="mdi-chevron-right" size="small" variant="text" @click="shiftMonth(1)" />
        <v-btn size="small" variant="tonal" @click="goToday">Сегодня</v-btn>
        <v-spacer />
        <span class="text-caption text-medium-emphasis">Задачи показаны на дату дедлайна</span>
      </div>
      <v-card class="pa-2">
        <div class="cal-grid cal-head">
          <div v-for="d in weekDays" :key="d" class="cal-weekday">{{ d }}</div>
        </div>
        <div class="cal-grid">
          <div v-for="cell in calendarCells" :key="cell.key" class="cal-cell"
            :class="{ 'cal-cell--out': !cell.inMonth, 'cal-cell--today': cell.isToday }">
            <div class="cal-date">{{ cell.day }}</div>
            <div v-for="t in cell.tasks" :key="t.id" class="cal-task"
              :style="{ borderLeftColor: statusHex(t.status) }" :title="t.title" @click="openTask(t.id)">
              {{ t.title }}
            </div>
          </div>
        </div>
      </v-card>
    </template>

    <!-- ГАНТ -->
    <v-card v-else-if="view === 'gantt'" class="pa-0 gantt-card">
      <div v-if="ganttRows.length" class="gantt">
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

    <!-- МОЙ ПЛАН — личная группировка по статусу -->
    <div v-else>
      <div v-for="g in planGroups" :key="g.key" class="mb-4">
        <div class="d-flex align-center ga-2 mb-2">
          <span class="plan-dot" :style="{ background: g.color }" />
          <span class="text-subtitle-2">{{ g.label }}</span>
          <span class="kanban-col__count">{{ g.tasks.length }}</span>
        </div>
        <v-card v-if="g.tasks.length">
          <v-list density="comfortable">
            <v-list-item v-for="t in g.tasks" :key="t.id" @click="openTask(t.id)">
              <template #prepend>
                <v-icon v-if="t.priority === 'high'" color="error" size="18" class="mr-1">mdi-flag</v-icon>
              </template>
              <v-list-item-title>{{ t.title }}</v-list-item-title>
              <template #append>
                <span v-if="t.deadline" class="text-caption mr-3" :class="overdue(t) ? 'text-error' : 'text-medium-emphasis'">{{ fmtDate(t.deadline) }}</span>
                <v-avatar v-if="t.assignee" size="24" color="primary" :title="t.assignee.name"><span class="text-caption">{{ initials(t.assignee.name) }}</span></v-avatar>
              </template>
            </v-list-item>
          </v-list>
        </v-card>
        <div v-else class="text-caption text-medium-emphasis ml-4">Нет задач</div>
      </div>
    </div>

    <!-- Создание задачи -->
    <v-dialog v-model="createDialog" max-width="540">
      <v-card>
        <v-card-title>Новая задача</v-card-title>
        <v-card-text>
          <v-text-field v-model="newTask.title" label="Название *" density="comfortable" autofocus @keyup.enter="createTask" />
          <UserPicker v-model="newTask.assignee_id" label="Исполнитель" class="mt-2" />
          <UserPicker v-model="newTask.watcher_ids" multiple label="Наблюдатели" class="mt-2" />
          <div class="d-flex ga-2 mt-2">
            <v-select v-model="newTask.priority" :items="priorityItems" label="Приоритет" density="comfortable" class="flex-grow-1" />
            <v-text-field v-model="newTask.deadline" type="datetime-local" label="Крайний срок" density="comfortable" class="flex-grow-1" />
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="createDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="creating" :disabled="!newTask.title.trim()" @click="createTask">Создать</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Диалог колонки -->
    <v-dialog v-model="stageDialog" max-width="440">
      <v-card>
        <v-card-title>{{ editingStage ? 'Колонка' : 'Новая колонка' }}</v-card-title>
        <v-card-text>
          <v-text-field v-model="stageForm.name" label="Название" density="comfortable" autofocus />
          <div class="mt-2 mb-1 text-caption text-medium-emphasis">Цвет</div>
          <div class="d-flex flex-wrap ga-2">
            <button v-for="c in palette" :key="c" type="button" class="color-swatch" :style="{ background: c }"
              :class="{ 'color-swatch--active': stageForm.color === c }" @click="stageForm.color = c" />
          </div>
          <v-switch v-if="editingStage" v-model="stageForm.is_done" label="Колонка «Готово» (помечает задачи выполненными)"
            color="success" density="compact" hide-details class="mt-2" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="stageDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="savingStage" @click="saveStage">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <TaskDetailDrawer v-model="detailOpen" :task-id="activeTaskId" @updated="onTaskUpdated" @deleted="reload"
      @open-task="(id) => { activeTaskId = id; }" />

    <TaskTemplatesDialog v-model="templatesDialog" @task-created="(id) => { reload(); openTask(id); }" />

    <!-- Права доступа к задачам (admin) -->
    <v-dialog v-model="permsDialog" max-width="720">
      <v-card>
        <v-card-title class="d-flex align-center">
          <v-icon class="mr-2" color="primary">mdi-shield-key-outline</v-icon>Права доступа к задачам
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" size="small" @click="permsDialog = false" />
        </v-card-title>
        <v-card-text>
          <p class="text-body-2 text-medium-emphasis mb-3">
            Кто и что может делать с задачей в зависимости от роли (определяется отношением
            к задаче). Администраторы имеют полный доступ всегда.
          </p>
          <v-table density="comfortable" class="perms-table">
            <thead>
              <tr>
                <th>Роль</th>
                <th v-for="(label, act) in permActions" :key="act" class="text-center">{{ label }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(rLabel, role) in permRoles" :key="role">
                <td class="font-weight-medium">{{ rLabel }}</td>
                <td v-for="(label, act) in permActions" :key="act" class="text-center">
                  <v-checkbox-btn v-model="permMatrix[role][act]" density="compact" color="primary" class="d-inline-flex" />
                </td>
              </tr>
            </tbody>
          </v-table>
          <v-divider class="my-3" />
          <v-switch v-model="permColumnsAdminOnly" color="primary" density="compact" hide-details
            label="Колонками канбана управляют только администраторы" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="permsDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="savingPerms" @click="savePerms">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="2500">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, EmptyState } from '../../components';
import UserPicker from '../../components/UserPicker.vue';
import TaskDetailDrawer from '../../components/TaskDetailDrawer.vue';
import TaskTemplatesDialog from '../../components/TaskTemplatesDialog.vue';
import { useDebounce } from '../../composables/useDebounce';
import { useAuthStore } from '../../stores/auth';

const auth = useAuthStore();
const isAdmin = computed(() => auth.isAdmin);
const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

const view = ref('board');
const templatesDialog = ref(false);
const scope = ref('all');
const viewTabs = [
  { key: 'board', label: 'Канбан', icon: 'mdi-view-column', count: true },
  { key: 'list', label: 'Список', icon: 'mdi-format-list-bulleted', count: true },
  { key: 'calendar', label: 'Календарь', icon: 'mdi-calendar', count: false },
  { key: 'gantt', label: 'Гант', icon: 'mdi-chart-timeline', count: false },
  { key: 'plan', label: 'Мой план', icon: 'mdi-clipboard-text-clock-outline', count: true },
];
const search = ref('');
const tasks = ref([]);
const stages = ref([]);
const loading = ref(false);

const palette = ['#90A4AE', '#42A5F5', '#26A69A', '#66BB6A', '#FFA726', '#EF5350', '#AB47BC', '#5C6BC0', '#EC407A', '#8D6E63'];
const STATUS = { pending: ['Бэклог', 'grey'], in_progress: ['В работе', 'info'], done: ['Выполнена', 'success'], deferred: ['На стопе', 'warning'], rejected: ['Отклонена', 'error'] };
const STATUS_HEX = { pending: '#90A4AE', in_progress: '#42A5F5', done: '#66BB6A', deferred: '#FFA726', rejected: '#EF5350' };
const priorityItems = [{ title: 'Низкий', value: 'low' }, { title: 'Обычный', value: 'normal' }, { title: 'Высокий', value: 'high' }];
const headers = [
  { title: 'Задача', key: 'title' },
  { title: 'Статус', key: 'status', width: 130 },
  { title: 'Исполнитель', key: 'assignee', width: 180 },
  { title: 'Срок', key: 'deadline', width: 130 },
];
function statusLabel(s) { return STATUS[s]?.[0] || s; }
function statusColor(s) { return STATUS[s]?.[1] || 'grey'; }
function statusHex(s) { return STATUS_HEX[s] || '#90A4AE'; }
function prioColor(p) { return p === 'high' ? '#EF5350' : (p === 'low' ? '#90A4AE' : 'transparent'); }
function initials(name) { return (name || '?').split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase(); }
function fmtDate(s) { const d = new Date(s); return isNaN(d) ? '' : d.toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' }); }
function overdue(t) { return t.deadline && new Date(t.deadline) < new Date() && t.status !== 'done'; }

// Готовые фильтры (как в Bitrix).
const preset = ref(null);
const presetItems = [
  { title: 'В работе', value: 'in_progress' },
  { title: 'Завершённые', value: 'done' },
  { title: 'Отложенные', value: 'deferred' },
  { title: 'Просроченные', value: 'overdue' },
  { title: 'Почти просрочены', value: 'almost' },
];
function matchPreset(t) {
  if (!preset.value) return true;
  if (preset.value === 'overdue') return t.deadline && new Date(t.deadline) < new Date() && t.status !== 'done';
  if (preset.value === 'almost') {
    if (!t.deadline || t.status === 'done') return false;
    const d = new Date(t.deadline).getTime() - Date.now();
    return d > 0 && d <= 24 * 3600 * 1000;
  }
  return t.status === preset.value;
}

// Теги: доступные (из загруженных задач) + клиентский фильтр.
const selectedTags = ref([]);
const availableTags = computed(() => [...new Set(tasks.value.flatMap((t) => t.tags || []))].sort());
const visibleTasks = computed(() => tasks.value.filter((t) =>
  matchPreset(t)
  && (!selectedTags.value.length || (t.tags || []).some((tg) => selectedTags.value.includes(tg)))));

// «Мой план» — группировка видимых задач по статусу.
const planGroups = computed(() => {
  const order = [
    { key: 'in_progress', label: 'В работе', color: '#42A5F5' },
    { key: 'pending', label: 'Бэклог', color: '#90A4AE' },
    { key: 'deferred', label: 'На стопе', color: '#FFA726' },
    { key: 'done', label: 'Выполнены', color: '#66BB6A' },
    { key: 'rejected', label: 'Отклонены', color: '#EF5350' },
  ];
  return order.map((g) => ({ ...g, tasks: visibleTasks.value.filter((t) => t.status === g.key) }))
    .filter((g) => g.tasks.length);
});

// Задачи колонки. В первую колонку (idx 0) сваливаем карточки без стадии
// и «осиротевшие» (стадия не из текущего набора — напр. от удалённого проекта).
const stageIdSet = computed(() => new Set(stages.value.map((s) => s.id)));
function stageTasks(stage, idx) {
  return visibleTasks.value.filter((t) =>
    t.stage_id === stage.id || (idx === 0 && (!t.stage_id || !stageIdSet.value.has(t.stage_id))));
}

async function reload() {
  loading.value = true;
  try {
    if (view.value === 'board') {
      const { data } = await api.get('/tasks/board', { params: { scope: scope.value } });
      stages.value = data.stages || [];
      tasks.value = data.tasks || [];
    } else {
      const { data } = await api.get('/tasks', { params: { scope: scope.value, search: search.value || undefined } });
      tasks.value = data.tasks || [];
    }
  } catch { /* ignore */ }
  loading.value = false;
}
const { debounced: debouncedReload } = useDebounce(reload, 350);

// view switch перезагружает данные.
watch(view, reload);

// ─── drag-n-drop: смена стадии ───
const dragged = ref(null);
function onDragStart(t) { dragged.value = t; }
async function onDrop(stage) {
  if (!dragged.value || dragged.value.stage_id === stage.id) { dragged.value = null; return; }
  const t = dragged.value; dragged.value = null;
  const prev = t.stage_id;
  t.stage_id = stage.id; // оптимистично
  try {
    const maxOrder = Math.max(0, ...tasks.value.filter((x) => x.stage_id === stage.id).map((x) => x.sort_order || 0));
    const { data } = await api.post(`/tasks/${t.id}/move`, { stage_id: stage.id, sort_order: maxOrder + 1 });
    Object.assign(t, data.task);
  } catch { t.stage_id = prev; }
}

// ─── создание ───
const createDialog = ref(false);
const creating = ref(false);
const newTask = reactive({ title: '', assignee_id: null, watcher_ids: [], priority: 'normal', deadline: '', stage_id: null });
function openCreate(stageId) {
  Object.assign(newTask, { title: '', assignee_id: null, watcher_ids: [], priority: 'normal', deadline: '', stage_id: stageId ?? stages.value[0]?.id ?? null });
  createDialog.value = true;
}
async function createTask() {
  if (!newTask.title.trim()) return;
  creating.value = true;
  try {
    const { data } = await api.post('/tasks', {
      title: newTask.title.trim(), assignee_id: newTask.assignee_id, watcher_ids: newTask.watcher_ids,
      priority: newTask.priority, deadline: newTask.deadline || null, stage_id: newTask.stage_id,
    });
    tasks.value.unshift(data.task);
    createDialog.value = false;
  } catch { /* ignore */ }
  creating.value = false;
}

// ─── колонки ───
const stageDialog = ref(false);
const savingStage = ref(false);
const editingStage = ref(null);
const stageForm = reactive({ name: '', color: '#90A4AE', is_done: false });
function openStageCreate() { editingStage.value = null; Object.assign(stageForm, { name: '', color: '#90A4AE', is_done: false }); stageDialog.value = true; }
function editStage(s) { editingStage.value = s; Object.assign(stageForm, { name: s.name, color: s.color, is_done: s.is_done }); stageDialog.value = true; }
async function saveStage() {
  savingStage.value = true;
  try {
    if (editingStage.value) await api.put(`/tasks/stages/${editingStage.value.id}`, stageForm);
    else await api.post('/tasks/stages', { name: stageForm.name || 'Новая колонка', color: stageForm.color });
    stageDialog.value = false;
    await reload();
  } catch { /* ignore */ }
  savingStage.value = false;
}
async function deleteStage(s) {
  if (!confirm(`Удалить колонку «${s.name}»? Задачи останутся без стадии.`)) return;
  try { await api.delete(`/tasks/stages/${s.id}`); await reload(); } catch { /* ignore */ }
}

// ─── детальная ───
const detailOpen = ref(false);
const activeTaskId = ref(null);
function openTask(id) { activeTaskId.value = id; detailOpen.value = true; }
function onTaskUpdated(updated) { const i = tasks.value.findIndex((t) => t.id === updated.id); if (i >= 0) Object.assign(tasks.value[i], updated); }

// ─── Права доступа (admin) ───
const permsDialog = ref(false);
const savingPerms = ref(false);
const permActions = ref({});
const permRoles = ref({});
const permMatrix = reactive({});
const permColumnsAdminOnly = ref(false);
async function openPerms() {
  try {
    const { data } = await api.get('/admin/task-permissions');
    permActions.value = data.actions || {};
    permRoles.value = data.roles || {};
    Object.keys(permMatrix).forEach((k) => delete permMatrix[k]);
    for (const role of Object.keys(data.roles || {})) permMatrix[role] = { ...(data.matrix?.[role] || {}) };
    permColumnsAdminOnly.value = !!data.columns_admin_only;
    permsDialog.value = true;
  } catch (e) { notify(e.response?.data?.message || 'Ошибка загрузки прав', 'error'); }
}
async function savePerms() {
  savingPerms.value = true;
  try {
    await api.put('/admin/task-permissions', { matrix: permMatrix, columns_admin_only: permColumnsAdminOnly.value });
    permsDialog.value = false;
    notify('Права сохранены');
  } catch (e) { notify(e.response?.data?.message || 'Ошибка сохранения', 'error'); }
  savingPerms.value = false;
}

// ─── Календарь ───
const calCursor = ref(new Date());
const weekDays = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
const monthLabel = computed(() => calCursor.value.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' }));
function shiftMonth(d) { const c = new Date(calCursor.value); c.setMonth(c.getMonth() + d); calCursor.value = c; }
function goToday() { calCursor.value = new Date(); }
function ymd(d) { const p = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`; }
const calendarCells = computed(() => {
  const y = calCursor.value.getFullYear(), m = calCursor.value.getMonth();
  const first = new Date(y, m, 1);
  const startDow = (first.getDay() + 6) % 7;
  const start = new Date(y, m, 1 - startDow);
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const byDay = {};
  for (const t of visibleTasks.value) { if (!t.deadline) continue; (byDay[ymd(new Date(t.deadline))] ||= []).push(t); }
  const cells = [];
  for (let i = 0; i < 42; i++) {
    const d = new Date(start); d.setDate(start.getDate() + i);
    const key = ymd(d);
    cells.push({ key, day: d.getDate(), inMonth: d.getMonth() === m, isToday: d.getTime() === today.getTime(), tasks: byDay[key] || [] });
  }
  return cells;
});

// ─── Гант ───
const dayW = 28;
function startOfDay(s) { if (!s) return null; const d = new Date(s); if (isNaN(d)) return null; d.setHours(0, 0, 0, 0); return d; }
function diffDays(a, b) { return Math.round((b - a) / 86400000); }
const ganttModel = computed(() => {
  const items = visibleTasks.value.map((t) => {
    const start = startOfDay(t.created_at);
    const end = startOfDay(t.deadline) || start;
    return start ? { ...t, _start: start, _end: end < start ? start : end } : null;
  }).filter(Boolean);
  if (!items.length) return { days: [], months: [], rows: [] };
  let min = items[0]._start, max = items[0]._end;
  for (const it of items) { if (it._start < min) min = it._start; if (it._end > max) max = it._end; }
  min = new Date(min); min.setDate(min.getDate() - 1);
  max = new Date(max); max.setDate(max.getDate() + 2);
  const today = startOfDay(new Date());
  const totalDays = diffDays(min, max);
  const days = [], months = [];
  for (let i = 0; i <= totalDays; i++) {
    const d = new Date(min); d.setDate(min.getDate() + i);
    const dow = d.getDay();
    days.push({ key: i, day: d.getDate(), weekend: dow === 0 || dow === 6, today: today && d.getTime() === today.getTime() });
    const mk = `${d.getFullYear()}-${d.getMonth()}`;
    const last = months[months.length - 1];
    if (last && last.key === mk) last.days++;
    else months.push({ key: mk, label: d.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' }), days: 1 });
  }
  const rows = items.map((it) => ({ ...it, offset: diffDays(min, it._start), span: Math.max(1, diffDays(it._start, it._end) + 1) }));
  return { days, months, rows };
});
const ganttDays = computed(() => ganttModel.value.days);
const ganttMonths = computed(() => ganttModel.value.months);
const ganttRows = computed(() => ganttModel.value.rows);

onMounted(reload);
</script>

<style scoped>
/* Канбан — аккуратный, спокойный */
.kanban { display: flex; gap: 14px; overflow-x: auto; padding-bottom: 12px; align-items: flex-start; }
.kanban-col { flex: 0 0 300px; max-height: calc(100vh - 250px); display: flex; flex-direction: column;
  background: rgba(var(--v-theme-on-surface), 0.022); border: 1px solid rgba(var(--v-border-color), 0.06);
  border-radius: 14px; }
.kanban-col--add { background: transparent; border: 0; }
.kanban-col__head { display: flex; align-items: center; gap: 7px; padding: 12px 14px 10px; }
.kanban-col__dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.kanban-col__title { font-weight: 600; font-size: 0.76rem; text-transform: uppercase; letter-spacing: 0.6px;
  color: rgba(var(--v-theme-on-surface), 0.66); white-space: nowrap; }
.kanban-col__count { font-size: 0.72rem; font-weight: 600; color: rgba(var(--v-theme-on-surface), 0.4);
  margin-left: 1px; font-variant-numeric: tabular-nums; }
.kanban-col__head .v-btn { margin-left: auto; opacity: 0; transition: opacity .15s ease; }
.kanban-col:hover .kanban-col__head .v-btn { opacity: 0.7; }
.kanban-col__body { padding: 0 8px; overflow-y: auto; flex: 1; min-height: 24px; }
.kanban-add { width: 100%; justify-content: flex-start; margin: 4px 0 10px; padding: 6px 10px; border: 0; background: transparent;
  cursor: pointer; border-radius: 8px; font-size: 0.8rem; color: rgba(var(--v-theme-on-surface), 0.5);
  display: flex; align-items: center; gap: 6px; transition: background .15s ease, color .15s ease; }
.kanban-add:hover { background: rgba(var(--v-theme-on-surface), 0.05); color: rgb(var(--v-theme-primary)); }
.kanban-add-col { width: 300px; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 12px;
  border: 1px dashed rgba(var(--v-border-color), 0.25); border-radius: 14px; background: transparent; cursor: pointer;
  font-size: 0.82rem; font-weight: 500; color: rgba(var(--v-theme-on-surface), 0.55); transition: all .15s ease; }
.kanban-add-col:hover { border-color: rgba(var(--v-theme-primary), 0.4); color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.04); }
.kanban-card { padding: 12px 14px; margin-bottom: 8px; cursor: pointer; position: relative; border-radius: 11px !important;
  border: 1px solid rgba(var(--v-border-color), 0.07) !important; box-shadow: 0 1px 1px rgba(15,30,15,0.03) !important;
  background: rgb(var(--v-theme-surface)) !important;
  transition: border-color .14s ease, box-shadow .14s ease, transform .14s ease; overflow: hidden; }
.kanban-card:hover { border-color: rgba(var(--v-theme-primary), 0.3) !important;
  box-shadow: 0 6px 18px rgba(15,30,15,0.10) !important; transform: translateY(-1px); }
.kanban-card__title { font-size: 0.875rem; line-height: 1.45; font-weight: 500; word-break: break-word; letter-spacing: -0.01em; }
.kanban-card__prio { position: absolute; top: 0; left: 0; width: 3px; height: 100%; border-radius: 11px 0 0 11px; }
.kanban-card__meta { min-height: 22px; }
.kanban-tag { font-size: 0.68rem; padding: 2px 8px; border-radius: 7px; background: rgba(var(--v-theme-primary), 0.09);
  color: rgb(var(--v-theme-primary)); font-weight: 500; }
.plan-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }

/* Сегмент-таб видов (современный, как в Linear/Height) */
.tasks-views { display: inline-flex; gap: 2px; padding: 3px; border-radius: 12px;
  background: rgba(var(--v-theme-on-surface), 0.05); flex-wrap: wrap; }
.tasks-view { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border: 0; cursor: pointer;
  border-radius: 9px; font-size: 0.82rem; font-weight: 500; color: rgba(var(--v-theme-on-surface), 0.66);
  background: transparent; transition: background .15s ease, color .15s ease, box-shadow .15s ease; }
.tasks-view:hover { color: rgb(var(--v-theme-on-surface)); }
.tasks-view--active { background: rgb(var(--v-theme-surface)); color: rgb(var(--v-theme-primary));
  box-shadow: 0 1px 3px rgba(15,30,15,0.12); font-weight: 600; }
.tasks-view__count { font-size: 0.7rem; font-weight: 600; padding: 0 6px; border-radius: 8px; min-width: 18px; text-align: center;
  background: rgba(var(--v-theme-on-surface), 0.08); font-variant-numeric: tabular-nums; }
.tasks-view--active .tasks-view__count { background: rgba(var(--v-theme-primary), 0.14); color: rgb(var(--v-theme-primary)); }
.kanban-pill { display: inline-flex; align-items: center; gap: 4px; font-size: 0.72rem; font-weight: 500; padding: 2px 8px; border-radius: 8px;
  background: rgba(var(--v-theme-on-surface), 0.06); color: rgba(var(--v-theme-on-surface), 0.7); font-variant-numeric: tabular-nums; }
.kanban-pill--overdue { background: rgba(var(--v-theme-error), 0.14); color: rgb(var(--v-theme-error)); }
.kanban-avatars { padding-left: 6px; }
.kanban-avatar { border: 2px solid rgb(var(--v-theme-surface)); margin-left: -6px; }
.kanban-avatar--lead { margin-left: -4px; }
.kanban--loading { opacity: 0.6; pointer-events: none; }
.color-swatch { width: 30px; height: 30px; border-radius: 8px; border: 2px solid transparent; cursor: pointer; }
.color-swatch--active { border-color: rgb(var(--v-theme-on-surface)); outline: 2px solid rgba(var(--v-theme-on-surface), 0.2); }

/* Календарь */
.cal-month { min-width: 170px; text-align: center; text-transform: capitalize; }
.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
.cal-head { margin-bottom: 4px; }
.cal-weekday { text-align: center; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; color: rgba(var(--v-theme-on-surface), 0.5); padding: 4px 0; }
.cal-cell { min-height: 96px; border: 1px solid rgba(var(--v-border-color), 0.08); border-radius: 8px; padding: 4px; display: flex; flex-direction: column; gap: 2px; background: rgb(var(--v-theme-surface)); }
.cal-cell--out { opacity: 0.45; }
.cal-cell--today { border-color: rgb(var(--v-theme-primary)); box-shadow: inset 0 0 0 1px rgb(var(--v-theme-primary)); }
.cal-date { font-size: 0.75rem; font-weight: 600; color: rgba(var(--v-theme-on-surface), 0.6); padding: 0 2px; }
.cal-task { font-size: 0.72rem; line-height: 1.2; padding: 2px 6px; border-left: 3px solid; border-radius: 4px; background: rgba(var(--v-theme-on-surface), 0.05); cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cal-task:hover { background: rgba(var(--v-theme-primary), 0.12); }

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
