<template>
  <v-dialog :model-value="modelValue" max-width="920" scrollable @update:model-value="$emit('update:modelValue', $event)">
    <v-card v-if="task" :loading="loading">
      <v-card-title class="d-flex align-center ga-2 py-3">
        <v-chip size="x-small" :color="statusColor(form.status)" variant="flat" label>{{ statusLabel(form.status) }}</v-chip>
        <span class="text-medium-emphasis text-caption">#{{ task.id }}</span>
        <v-spacer />
        <v-btn :icon="task.is_favorite ? 'mdi-star' : 'mdi-star-outline'" size="small" variant="text"
          :color="task.is_favorite ? 'amber' : undefined" title="В избранное" @click="toggleFavorite" />
        <v-btn icon="mdi-delete" size="small" variant="text" color="error" @click="remove" />
        <v-btn icon="mdi-close" size="small" variant="text" @click="close" />
      </v-card-title>
      <v-divider />

      <v-card-text class="pa-0">
        <v-row no-gutters>
          <!-- Левая колонка: поля -->
          <v-col cols="12" md="7" class="pa-4 task-fields">
            <v-text-field v-model="form.title" variant="plain" density="comfortable" hide-details
              class="task-title-input" placeholder="Название задачи" @blur="autosave('title')" />

            <v-textarea v-model="form.description" label="Описание" variant="outlined" density="comfortable"
              rows="4" auto-grow hide-details class="mt-2" @blur="autosave('description')" />

            <div class="d-flex flex-column ga-3 mt-4">
              <div class="d-flex align-center">
                <span class="task-field-label">Исполнитель</span>
                <div class="flex-grow-1">
                  <UserPicker v-model="form.assignee_id" :preload="assigneePreload" label="" placeholder="Назначить"
                    @update:model-value="autosave('assignee_id')" />
                </div>
                <v-btn icon="mdi-account-arrow-right" size="small" variant="text" title="Делегировать"
                  class="ml-1" @click="openDelegate" />
              </div>
              <div class="d-flex align-center">
                <span class="task-field-label">Соисполнители</span>
                <div class="flex-grow-1">
                  <UserPicker v-model="form.accomplice_ids" :preload="accomplicePreload" multiple label="" placeholder="Добавить"
                    @update:model-value="autosave('accomplice_ids')" />
                </div>
              </div>
              <div class="d-flex align-center">
                <span class="task-field-label">Наблюдатели</span>
                <div class="flex-grow-1">
                  <UserPicker v-model="form.watcher_ids" :preload="watcherPreload" multiple label="" placeholder="Добавить"
                    @update:model-value="autosave('watcher_ids')" />
                </div>
              </div>
              <div class="d-flex align-center">
                <span class="task-field-label">Крайний срок</span>
                <v-text-field v-model="form.deadline" type="datetime-local" density="compact" variant="outlined"
                  hide-details class="flex-grow-1" @blur="autosave('deadline')" />
                <v-btn icon="mdi-calendar-clock" size="small" variant="text" title="Планирование сроков"
                  class="ml-1" @click="openPlanning" />
              </div>
              <div class="d-flex align-center">
                <span class="task-field-label">Приоритет</span>
                <v-select v-model="form.priority" :items="priorityItems" density="compact" variant="outlined"
                  hide-details class="flex-grow-1" @update:model-value="autosave('priority')" />
              </div>
              <div class="d-flex align-center">
                <span class="task-field-label">Статус</span>
                <v-select v-model="form.status" :items="statusItems" density="compact" variant="outlined"
                  hide-details class="flex-grow-1" @update:model-value="autosave('status')" />
              </div>
              <div class="d-flex align-center">
                <span class="task-field-label">Теги</span>
                <div class="flex-grow-1">
                  <v-combobox v-model="form.tags" multiple chips closable-chips density="compact" variant="outlined"
                    hide-details label="" placeholder="Добавить тег" @update:model-value="autosave('tags')" />
                </div>
              </div>
              <div class="d-flex align-center">
                <span class="task-field-label">Постановщик</span>
                <span class="text-body-2">{{ task.creator?.name || '—' }}</span>
              </div>
              <div class="d-flex align-center">
                <span class="task-field-label">Учёт времени</span>
                <span class="text-body-2 font-weight-medium task-timer-val" :class="{ 'text-primary': task.timer_running }">
                  {{ displayTime }}
                </span>
                <v-btn :icon="task.timer_running ? 'mdi-pause' : 'mdi-play'" size="small"
                  :color="task.timer_running ? 'warning' : 'success'" variant="tonal" class="ml-2"
                  :loading="timerBusy" :title="task.timer_running ? 'Остановить' : 'Запустить'" @click="toggleTimer" />
              </div>
            </div>

            <!-- Требуется результат -->
            <div class="mt-4">
              <v-switch v-model="form.requires_result" color="primary" density="compact" hide-details
                label="Требуется результат" @update:model-value="autosave('requires_result')" />
              <v-textarea v-if="form.requires_result" v-model="form.result" label="Результат выполнения"
                variant="outlined" density="comfortable" rows="3" auto-grow hide-details class="mt-2"
                placeholder="Опишите итог работы по задаче" @blur="autosave('result')" />
            </div>

            <!-- Подзадачи -->
            <div class="mt-5">
              <div class="d-flex align-center mb-1">
                <span class="text-subtitle-2">Подзадачи</span>
                <span v-if="task.subtasks?.length" class="text-caption text-medium-emphasis ml-2">
                  {{ doneSubtasks }} / {{ task.subtasks.length }}
                </span>
              </div>
              <v-progress-linear v-if="task.subtasks?.length" :model-value="subtaskProgress" color="success"
                height="4" rounded class="mb-2" />
              <div v-for="st in task.subtasks" :key="st.id" class="d-flex align-center subtask-row">
                <v-checkbox-btn :model-value="st.status === 'done'" density="compact" color="success"
                  @update:model-value="toggleSubtask(st)" />
                <span class="text-body-2 flex-grow-1" :class="{ 'subtask-done': st.status === 'done' }">{{ st.title }}</span>
              </div>
              <div class="d-flex ga-2 mt-1">
                <v-text-field v-model="newSubtask" placeholder="Новая подзадача…" density="compact" variant="outlined"
                  hide-details @keyup.enter="addSubtask" />
                <v-btn icon="mdi-plus" variant="tonal" size="small" :disabled="!newSubtask.trim()" @click="addSubtask" />
              </div>
            </div>

            <!-- Файлы -->
            <div class="mt-5">
              <div class="d-flex align-center mb-1">
                <span class="text-subtitle-2">Файлы</span>
                <span v-if="task.attachments?.length" class="text-caption text-medium-emphasis ml-2">{{ task.attachments.length }}</span>
                <v-spacer />
                <v-btn size="x-small" variant="tonal" prepend-icon="mdi-paperclip" :loading="uploading" @click="triggerUpload">Прикрепить</v-btn>
                <input ref="fileInput" type="file" class="d-none" @change="onFileSelected" />
              </div>
              <div v-for="a in task.attachments" :key="a.id" class="d-flex align-center ga-2 att-row">
                <v-icon size="18" class="text-medium-emphasis">{{ fileIcon(a.mime) }}</v-icon>
                <button type="button" class="att-name text-truncate flex-grow-1 text-left" @click="downloadAtt(a)">{{ a.name }}</button>
                <span class="text-caption text-medium-emphasis">{{ fmtSize(a.size) }}</span>
                <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="removeAtt(a)" />
              </div>
              <div v-if="!task.attachments?.length" class="text-caption text-medium-emphasis">Файлов нет</div>
            </div>

            <!-- Связанные задачи -->
            <div class="mt-5">
              <div class="d-flex align-center mb-1">
                <span class="text-subtitle-2">Связанные задачи</span>
                <span v-if="task.related?.length" class="text-caption text-medium-emphasis ml-2">{{ task.related.length }}</span>
              </div>
              <div v-for="r in task.related" :key="r.link_id" class="d-flex align-center ga-2 att-row">
                <v-icon size="16" :color="statusColor(r.status)">mdi-link-variant</v-icon>
                <button type="button" class="att-name text-truncate flex-grow-1 text-left" @click="$emit('open-task', r.id)">{{ r.title }}</button>
                <v-btn icon="mdi-close" size="x-small" variant="text" @click="unlink(r)" />
              </div>
              <v-autocomplete v-model="linkPick" :items="linkOptions" :loading="linkLoading" item-title="title" item-value="id"
                placeholder="Найти и связать задачу" density="compact" variant="outlined" hide-details no-filter
                class="mt-1" @update:search="searchLinks" @update:model-value="addLink" />
            </div>

            <!-- Кнопки-фичи задачи (как в Bitrix) -->
            <div class="d-flex flex-wrap ga-2 mt-5 task-features">
              <v-btn v-for="f in features" :key="f.key" :prepend-icon="f.icon" size="small"
                :variant="f.active ? 'tonal' : 'outlined'" :color="f.active ? 'primary' : undefined"
                :disabled="f.soon" @click="f.action && f.action()">
                {{ f.label }}<span v-if="f.soon" class="text-caption ml-1">· скоро</span>
              </v-btn>
            </div>
          </v-col>

          <!-- Правая колонка: чат-лента -->
          <v-col cols="12" md="5" class="task-comments-col d-flex flex-column">
            <div class="task-chat-head d-flex align-center ga-2 px-4 py-3">
              <v-icon size="18" color="primary">mdi-forum-outline</v-icon>
              <span class="text-subtitle-2">Обсуждение</span>
              <span v-if="task.comments?.length" class="text-caption text-medium-emphasis">{{ task.comments.length }}</span>
            </div>
            <div class="task-comments px-4">
              <div v-for="c in task.comments" :key="c.id" class="task-bubble">
                <v-avatar size="28" color="primary" class="task-bubble__avatar"><span class="text-caption">{{ initials(c.author?.name) }}</span></v-avatar>
                <div class="task-bubble__body">
                  <div class="d-flex align-center ga-2">
                    <span class="text-body-2 font-weight-medium">{{ c.author?.name }}</span>
                    <span class="text-caption text-medium-emphasis">{{ fmt(c.created_at) }}</span>
                  </div>
                  <div class="text-body-2 task-comment-body">{{ c.body }}</div>
                </div>
              </div>
              <div v-if="!task.comments?.length" class="text-center text-medium-emphasis text-caption py-6">
                <v-icon size="28" class="d-block mx-auto mb-1 text-disabled">mdi-message-outline</v-icon>
                Пока нет сообщений
              </div>
            </div>
            <div class="task-chat-input d-flex ga-2 pa-3">
              <v-text-field v-model="newComment" placeholder="Написать сообщение…" density="compact"
                variant="solo-filled" flat hide-details rounded @keyup.enter="postComment" />
              <v-btn icon="mdi-send" color="primary" :disabled="!newComment.trim()" :loading="posting" @click="postComment" />
            </div>
          </v-col>
        </v-row>
      </v-card-text>
    </v-card>

    <!-- Делегирование -->
    <v-dialog v-model="delegateDialog" max-width="420">
      <v-card>
        <v-card-title>Делегировать задачу</v-card-title>
        <v-card-text>
          <p class="text-body-2 text-medium-emphasis mb-2">Прежний исполнитель останется наблюдателем.</p>
          <UserPicker v-model="delegateTo" label="Новый исполнитель" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="delegateDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="delegating" :disabled="!delegateTo" @click="doDelegate">Делегировать</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Диалог планирования сроков -->
    <v-dialog v-model="planningDialog" max-width="460">
      <v-card>
        <v-card-title>Планирование сроков</v-card-title>
        <v-card-text>
          <p class="text-body-2 text-medium-emphasis mb-3">
            Запланируйте даты начала и завершения задачи — это поможет расставить приоритеты.
          </p>
          <v-text-field v-model="planning.start" type="datetime-local" label="Начать" density="comfortable" @update:model-value="recalcDuration" />
          <v-text-field v-model="planning.end" type="datetime-local" label="Завершить" density="comfortable" class="mt-2" @update:model-value="recalcDuration" />
          <div class="d-flex align-center ga-2 mt-1">
            <span class="text-body-2 text-medium-emphasis">Длительность:</span>
            <span class="text-body-2 font-weight-medium">{{ durationLabel }}</span>
          </div>
          <v-switch v-model="planning.skipWeekends" label="Пропускать выходные дни" color="primary" density="compact" hide-details class="mt-2" @update:model-value="recalcDuration" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="planningDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="savingPlanning" @click="savePlanning">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-dialog>
</template>

<script setup>
import { ref, reactive, watch, computed, onUnmounted } from 'vue';
import api from '../api';
import { useAuthStore } from '../stores/auth';
import UserPicker from './UserPicker.vue';

const auth = useAuthStore();

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  taskId: { type: Number, default: null },
});
const emit = defineEmits(['update:modelValue', 'updated', 'deleted', 'open-task']);

const task = ref(null);
const loading = ref(false);
const posting = ref(false);
const newComment = ref('');
const newSubtask = ref('');

const doneSubtasks = computed(() => (task.value?.subtasks || []).filter((s) => s.status === 'done').length);
const subtaskProgress = computed(() => {
  const total = task.value?.subtasks?.length || 0;
  return total ? Math.round((doneSubtasks.value / total) * 100) : 0;
});
const form = reactive({ title: '', description: '', assignee_id: null, watcher_ids: [], accomplice_ids: [], tags: [], deadline: '', priority: 'normal', status: 'pending', requires_result: false, result: '' });

const priorityItems = [
  { title: 'Низкий', value: 'low' },
  { title: 'Обычный', value: 'normal' },
  { title: 'Высокий', value: 'high' },
];
const STATUS = {
  pending: { label: 'Ждёт', color: 'grey' },
  in_progress: { label: 'В работе', color: 'info' },
  done: { label: 'Готово', color: 'success' },
  deferred: { label: 'Отложена', color: 'warning' },
  rejected: { label: 'Отклонена', color: 'error' },
};
const statusItems = Object.entries(STATUS).map(([value, v]) => ({ title: v.label, value }));
function statusLabel(s) { return STATUS[s]?.label || s; }
function statusColor(s) { return STATUS[s]?.color || 'grey'; }

const assigneePreload = computed(() => (task.value?.assignee ? [task.value.assignee] : []));
const watcherPreload = computed(() => task.value?.watchers || []);
const accomplicePreload = computed(() => task.value?.accomplices || []);

// Кнопки-фичи задачи (Bitrix-стиль). Реализованные — активны, остальные «скоро».
const features = computed(() => [
  { key: 'subtasks', label: 'Чек-лист', icon: 'mdi-format-list-checks', active: !!task.value?.subtasks?.length },
  { key: 'watchers', label: 'Наблюдатели', icon: 'mdi-eye-outline', active: !!task.value?.watchers?.length },
  { key: 'accomplices', label: 'Соисполнители', icon: 'mdi-account-multiple-outline', active: !!task.value?.accomplices?.length },
  { key: 'files', label: 'Файлы', icon: 'mdi-paperclip', active: !!task.value?.attachments?.length, action: triggerUpload },
  { key: 'planning', label: 'Планирование', icon: 'mdi-calendar-clock', action: openPlanning },
  { key: 'tags', label: 'Теги', icon: 'mdi-tag-outline', active: !!task.value?.tags?.length },
  { key: 'related', label: 'Связанные', icon: 'mdi-link-variant', active: !!task.value?.related?.length },
  { key: 'delegate', label: 'Делегировать', icon: 'mdi-account-arrow-right', action: openDelegate },
  { key: 'reminders', label: 'Напоминания', icon: 'mdi-bell-outline', soon: true },
]);

// ─── Вложения ───
const fileInput = ref(null);
const uploading = ref(false);
function triggerUpload() { fileInput.value?.click(); }
async function onFileSelected(e) {
  const file = e.target.files?.[0];
  if (!file || !task.value) return;
  uploading.value = true;
  try {
    const fd = new FormData();
    fd.append('file', file);
    const { data } = await api.post(`/tasks/${task.value.id}/attachments`, fd, { headers: { 'Content-Type': 'multipart/form-data' } });
    task.value.attachments = [data.attachment, ...(task.value.attachments || [])];
  } catch (err) { alert(err.response?.data?.message || 'Не удалось загрузить файл'); }
  uploading.value = false;
  if (fileInput.value) fileInput.value.value = '';
}
async function downloadAtt(a) {
  try {
    const res = await api.get(`/tasks/${task.value.id}/attachments/${a.id}`, { responseType: 'blob' });
    const url = URL.createObjectURL(res.data);
    const link = document.createElement('a');
    link.href = url; link.download = a.name; link.click();
    URL.revokeObjectURL(url);
  } catch { /* ignore */ }
}
async function removeAtt(a) {
  if (!confirm(`Удалить файл «${a.name}»?`)) return;
  try {
    await api.delete(`/tasks/${task.value.id}/attachments/${a.id}`);
    task.value.attachments = (task.value.attachments || []).filter((x) => x.id !== a.id);
  } catch (e) { alert(e.response?.data?.message || 'Ошибка'); }
}
function fmtSize(b) { if (!b) return ''; const u = ['Б', 'КБ', 'МБ', 'ГБ']; let i = 0; let n = b; while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; } return `${n.toFixed(i ? 1 : 0)} ${u[i]}`; }
function fileIcon(mime) {
  const m = mime || '';
  if (m.startsWith('image/')) return 'mdi-file-image-outline';
  if (m.includes('pdf')) return 'mdi-file-pdf-box';
  if (m.includes('word') || m.includes('document')) return 'mdi-file-word-outline';
  if (m.includes('sheet') || m.includes('excel') || m.includes('csv')) return 'mdi-file-excel-outline';
  if (m.includes('zip') || m.includes('rar') || m.includes('compressed')) return 'mdi-folder-zip-outline';
  if (m.startsWith('video/')) return 'mdi-file-video-outline';
  if (m.startsWith('audio/')) return 'mdi-file-music-outline';
  return 'mdi-file-outline';
}

// ─── Планирование сроков ───
const planningDialog = ref(false);
const savingPlanning = ref(false);
const planning = reactive({ start: '', end: '', skipWeekends: false });
const durationDays = ref(0);
const durationLabel = computed(() => {
  const d = durationDays.value;
  if (!d) return '—';
  const word = d % 10 === 1 && d % 100 !== 11 ? 'день' : (d % 10 >= 2 && d % 10 <= 4 && (d % 100 < 10 || d % 100 >= 20) ? 'дня' : 'дней');
  return `${d} ${word}`;
});
function openPlanning() {
  planning.start = toLocalInput(task.value?.started_at);
  planning.end = form.deadline;
  planning.skipWeekends = false;
  recalcDuration();
  planningDialog.value = true;
}
function recalcDuration() {
  const a = planning.start ? new Date(planning.start) : null;
  const b = planning.end ? new Date(planning.end) : null;
  if (!a || !b || isNaN(a) || isNaN(b) || b < a) { durationDays.value = 0; return; }
  let days = 0;
  const cur = new Date(a); cur.setHours(0, 0, 0, 0);
  const end = new Date(b); end.setHours(0, 0, 0, 0);
  while (cur <= end) {
    const dow = cur.getDay();
    if (!planning.skipWeekends || (dow !== 0 && dow !== 6)) days++;
    cur.setDate(cur.getDate() + 1);
  }
  durationDays.value = days;
}
async function savePlanning() {
  savingPlanning.value = true;
  try {
    const { data } = await api.put(`/tasks/${task.value.id}`, {
      started_at: planning.start || null,
      deadline: planning.end || null,
    });
    task.value = { ...task.value, ...data.task };
    form.deadline = toLocalInput(data.task.deadline);
    emit('updated', data.task);
    planningDialog.value = false;
  } catch { /* ignore */ }
  savingPlanning.value = false;
}

function initials(name) { return (name || '?').split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase(); }
function fmt(s) { if (!s) return ''; const d = new Date(s); return isNaN(d) ? s : d.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' }); }
function toLocalInput(s) { if (!s) return ''; const d = new Date(s); if (isNaN(d)) return ''; const p = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`; }

// ─── Realtime: чат задачи через Socket.IO ───
let socket = null;
let joinedTaskId = null;
async function ensureSocket() {
  if (socket || !auth.token) return;
  try {
    const { io } = await import('socket.io-client');
    const isLocal = ['localhost', '127.0.0.1'].includes(location.hostname);
    const defaultHost = isLocal
      ? `ws://${location.hostname}:3001`
      : `${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}`;
    const host = window.__SOCKET_URL__ || defaultHost;
    socket = io(host, { auth: { token: auth.token }, transports: ['websocket', 'polling'], reconnection: true });
    socket.on('task:comment', (p) => {
      if (!task.value || Number(p.task_id) !== Number(task.value.id)) return;
      const list = task.value.comments || [];
      if (list.some((c) => c.id === p.comment.id)) return; // дедуп (свой коммент уже добавлен)
      task.value.comments = [...list, p.comment];
    });
  } catch { /* socket-server offline — комментарии остаются по REST */ }
}
async function joinTaskRoom(id) {
  await ensureSocket();
  if (!socket) return;
  if (joinedTaskId && joinedTaskId !== id) socket.emit('task:leave', joinedTaskId);
  socket.emit('task:join', id);
  joinedTaskId = id;
}
function leaveTaskRoom() {
  if (socket && joinedTaskId) { socket.emit('task:leave', joinedTaskId); joinedTaskId = null; }
}

async function fetchTask() {
  if (!props.taskId) return;
  loading.value = true;
  try {
    const { data } = await api.get(`/tasks/${props.taskId}`);
    task.value = data.task;
    Object.assign(form, {
      title: data.task.title,
      description: data.task.description || '',
      assignee_id: data.task.assignee?.id ?? null,
      watcher_ids: (data.task.watchers || []).map((w) => w.id),
      accomplice_ids: (data.task.accomplices || []).map((a) => a.id),
      tags: data.task.tags || [],
      deadline: toLocalInput(data.task.deadline),
      priority: data.task.priority,
      status: data.task.status,
      requires_result: !!data.task.requires_result,
      result: data.task.result || '',
    });
    joinTaskRoom(task.value.id);
  } catch { /* ignore */ }
  loading.value = false;
}

async function autosave(field) {
  if (!task.value) return;
  const payload = {};
  if (field === 'title') payload.title = form.title;
  else if (field === 'description') payload.description = form.description;
  else if (field === 'assignee_id') payload.assignee_id = form.assignee_id;
  else if (field === 'watcher_ids') payload.watcher_ids = form.watcher_ids;
  else if (field === 'accomplice_ids') payload.accomplice_ids = form.accomplice_ids;
  else if (field === 'tags') payload.tags = form.tags;
  else if (field === 'deadline') payload.deadline = form.deadline || null;
  else if (field === 'priority') payload.priority = form.priority;
  else if (field === 'status') payload.status = form.status;
  else if (field === 'requires_result') payload.requires_result = form.requires_result;
  else if (field === 'result') payload.result = form.result;
  try {
    const { data } = await api.put(`/tasks/${task.value.id}`, payload);
    task.value = { ...task.value, ...data.task };
    emit('updated', data.task);
  } catch { /* ignore */ }
}

// ─── Связанные задачи ───
const linkPick = ref(null);
const linkOptions = ref([]);
const linkLoading = ref(false);
let linkTimer = null;
function searchLinks(q) {
  clearTimeout(linkTimer);
  linkTimer = setTimeout(async () => {
    linkLoading.value = true;
    try {
      const { data } = await api.get('/tasks/search', { params: { q: q || undefined } });
      linkOptions.value = (data.tasks || []).filter((t) => t.id !== task.value?.id);
    } catch { /* ignore */ }
    linkLoading.value = false;
  }, 300);
}
async function addLink(rid) {
  if (!rid || !task.value) return;
  try {
    const { data } = await api.post(`/tasks/${task.value.id}/links`, { related_task_id: rid });
    task.value.related = data.related;
  } catch (e) { alert(e.response?.data?.message || 'Ошибка'); }
  linkPick.value = null;
}
async function unlink(r) {
  try {
    const { data } = await api.delete(`/tasks/${task.value.id}/links/${r.link_id}`);
    task.value.related = data.related;
  } catch { /* ignore */ }
}

// ─── Делегирование ───
const delegateDialog = ref(false);
const delegateTo = ref(null);
const delegating = ref(false);
function openDelegate() { delegateTo.value = null; delegateDialog.value = true; }
async function doDelegate() {
  if (!delegateTo.value) return;
  delegating.value = true;
  try {
    const { data } = await api.post(`/tasks/${task.value.id}/delegate`, { assignee_id: delegateTo.value });
    task.value = { ...task.value, ...data.task };
    form.assignee_id = data.task.assignee?.id ?? null;
    form.watcher_ids = (data.task.watchers || []).map((w) => w.id);
    emit('updated', data.task);
    delegateDialog.value = false;
  } catch (e) { alert(e.response?.data?.message || 'Ошибка'); }
  delegating.value = false;
}

// ─── Учёт времени ───
const timerBusy = ref(false);
const tick = ref(0);
let tickInterval = null;
function ensureTicking() {
  if (task.value?.timer_running && !tickInterval) {
    tickInterval = setInterval(() => { tick.value++; }, 1000);
  } else if (!task.value?.timer_running && tickInterval) {
    clearInterval(tickInterval); tickInterval = null;
  }
}
function fmtDuration(sec) {
  sec = Math.max(0, Math.floor(sec));
  const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
  const p = (n) => String(n).padStart(2, '0');
  return `${p(h)}:${p(m)}:${p(s)}`;
}
const displayTime = computed(() => {
  void tick.value; // реактивная зависимость от секундного тика
  let total = task.value?.time_spent || 0;
  if (task.value?.timer_running && task.value?.timer_started_at) {
    total += Math.max(0, Math.floor((Date.now() - new Date(task.value.timer_started_at).getTime()) / 1000));
  }
  return fmtDuration(total);
});
async function toggleTimer() {
  if (!task.value) return;
  timerBusy.value = true;
  const action = task.value.timer_running ? 'stop' : 'start';
  try {
    const { data } = await api.post(`/tasks/${task.value.id}/timer/${action}`);
    task.value = { ...task.value, ...data.task };
    ensureTicking();
    emit('updated', data.task);
  } catch { /* ignore */ }
  timerBusy.value = false;
}
onUnmounted(() => {
  if (tickInterval) clearInterval(tickInterval);
  leaveTaskRoom();
  if (socket) { socket.disconnect(); socket = null; }
});

async function toggleFavorite() {
  if (!task.value) return;
  try {
    const { data } = await api.post(`/tasks/${task.value.id}/favorite`);
    task.value.is_favorite = data.is_favorite;
    emit('updated', { ...task.value });
  } catch { /* ignore */ }
}

async function postComment() {
  if (!newComment.value.trim()) return;
  posting.value = true;
  try {
    const { data } = await api.post(`/tasks/${task.value.id}/comments`, { body: newComment.value.trim() });
    task.value.comments = [...(task.value.comments || []), data.comment];
    newComment.value = '';
  } catch { /* ignore */ }
  posting.value = false;
}

async function addSubtask() {
  if (!newSubtask.value.trim() || !task.value) return;
  try {
    const { data } = await api.post('/tasks', {
      parent_id: task.value.id,
      project_id: task.value.project_id,
      title: newSubtask.value.trim(),
    });
    task.value.subtasks = [...(task.value.subtasks || []), data.task];
    newSubtask.value = '';
  } catch { /* ignore */ }
}
async function toggleSubtask(st) {
  const next = st.status === 'done' ? 'pending' : 'done';
  try {
    const { data } = await api.put(`/tasks/${st.id}`, { status: next });
    const i = task.value.subtasks.findIndex((s) => s.id === st.id);
    if (i >= 0) task.value.subtasks[i] = { ...task.value.subtasks[i], ...data.task };
  } catch { /* ignore */ }
}

async function remove() {
  if (!confirm('Удалить задачу?')) return;
  try { await api.delete(`/tasks/${task.value.id}`); emit('deleted', task.value.id); close(); } catch { /* ignore */ }
}
function close() { emit('update:modelValue', false); }

watch(() => props.modelValue, (v) => {
  if (v) { fetchTask().then(ensureTicking); }
  else { leaveTaskRoom(); task.value = null; if (tickInterval) { clearInterval(tickInterval); tickInterval = null; } }
});
// Смена задачи при открытом окне (напр. переход по связанной задаче).
watch(() => props.taskId, (id) => { if (props.modelValue && id) fetchTask().then(ensureTicking); });
</script>

<style scoped>
.task-title-input :deep(input) { font-size: 1.2rem; font-weight: 600; letter-spacing: -0.01em; }
.task-field-label { width: 120px; flex-shrink: 0; font-size: 0.8rem; color: rgba(var(--v-theme-on-surface), 0.6); }

/* Правая чат-панель */
.task-comments-col { border-left: 1px solid rgba(var(--v-border-color), 0.1); background: rgba(var(--v-theme-on-surface), 0.025); min-height: 460px; }
.task-chat-head { border-bottom: 1px solid rgba(var(--v-border-color), 0.08); }
.task-comments { flex: 1; overflow-y: auto; max-height: 440px; padding-top: 12px; padding-bottom: 12px; }
.task-bubble { display: flex; gap: 8px; margin-bottom: 14px; }
.task-bubble__avatar { flex-shrink: 0; }
.task-bubble__body { background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-border-color), 0.08);
  border-radius: 4px 12px 12px 12px; padding: 7px 11px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); min-width: 0; flex: 1; }
.task-comment-body { white-space: pre-wrap; margin-top: 2px; word-break: break-word; }
.task-chat-input { border-top: 1px solid rgba(var(--v-border-color), 0.08); }

/* Подзадачи / фичи */
.subtask-row { gap: 4px; }
.subtask-done { text-decoration: line-through; color: rgba(var(--v-theme-on-surface), 0.5); }
.att-row { padding: 3px 0; }
.att-name { font-size: 0.82rem; color: rgb(var(--v-theme-primary)); cursor: pointer; background: none; border: 0; padding: 0; min-width: 0; }
.att-name:hover { text-decoration: underline; }
.task-features :deep(.v-btn) { text-transform: none; letter-spacing: 0; }
</style>
