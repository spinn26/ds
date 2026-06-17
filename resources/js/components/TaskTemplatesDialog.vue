<template>
  <v-dialog :model-value="modelValue" max-width="640" @update:model-value="$emit('update:modelValue', $event)">
    <v-card>
      <v-card-title class="d-flex align-center">
        <v-icon class="mr-2" color="primary">mdi-content-copy</v-icon>
        {{ editing ? (form.id ? 'Шаблон' : 'Новый шаблон') : 'Шаблоны задач' }}
        <v-spacer />
        <v-btn v-if="!editing" size="small" color="primary" variant="tonal" prepend-icon="mdi-plus" @click="openCreate">Шаблон</v-btn>
        <v-btn icon="mdi-close" variant="text" size="small" @click="$emit('update:modelValue', false)" />
      </v-card-title>
      <v-divider />

      <!-- Список -->
      <v-card-text v-if="!editing">
        <div v-for="t in templates" :key="t.id" class="d-flex align-center ga-2 tpl-row">
          <div class="flex-grow-1 min-w-0">
            <div class="text-body-2 font-weight-medium text-truncate">{{ t.name }}</div>
            <div class="text-caption text-medium-emphasis text-truncate">
              {{ t.title }}
              <span v-if="t.recurrence_freq !== 'none'"> · <v-icon size="12">mdi-repeat</v-icon> {{ freqLabel(t) }}</span>
            </div>
          </div>
          <v-btn size="small" variant="tonal" color="primary" @click="run(t)">Создать</v-btn>
          <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openEdit(t)" />
          <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="remove(t)" />
        </div>
        <EmptyState v-if="!templates.length" message="Шаблонов нет" />
      </v-card-text>

      <!-- Редактор -->
      <v-card-text v-else>
        <v-text-field v-model="form.name" label="Название шаблона *" density="comfortable" />
        <v-text-field v-model="form.title" label="Заголовок задачи *" density="comfortable" class="mt-1" />
        <v-textarea v-model="form.description" label="Описание" density="comfortable" rows="2" auto-grow class="mt-1" />
        <div class="d-flex ga-2 mt-1">
          <v-select v-model="form.priority" :items="priorityItems" label="Приоритет" density="comfortable" class="flex-grow-1" />
          <UserPicker v-model="form.assignee_id" label="Исполнитель" class="flex-grow-1" />
        </div>
        <v-combobox v-model="form.tags" multiple chips closable-chips label="Теги" density="comfortable" class="mt-1" />
        <v-combobox v-model="form.checklist" multiple chips closable-chips label="Чек-лист (пункты)" density="comfortable" class="mt-1" />
        <v-switch v-model="form.requires_result" label="Требуется результат" color="primary" density="compact" hide-details class="mt-1" />

        <v-divider class="my-3" />
        <div class="text-subtitle-2 mb-2">Повтор</div>
        <v-select v-model="form.recurrence_freq" :items="freqItems" label="Периодичность" density="comfortable" />
        <div v-if="form.recurrence_freq !== 'none'" class="d-flex flex-wrap ga-2 mt-1">
          <v-text-field v-if="['daily','monthly'].includes(form.recurrence_freq)" v-model.number="form.recurrence_interval"
            type="number" min="1" label="Интервал" density="comfortable" style="max-width: 120px" />
          <v-select v-if="form.recurrence_freq === 'weekly'" v-model="form.recurrence_weekday" :items="weekdayItems"
            label="День недели" density="comfortable" style="max-width: 160px" />
          <v-text-field v-if="form.recurrence_freq === 'monthly'" v-model.number="form.recurrence_monthday"
            type="number" min="1" max="28" label="День месяца" density="comfortable" style="max-width: 140px" />
          <v-text-field v-model="form.recurrence_time" type="time" label="Время" density="comfortable" style="max-width: 130px" />
        </div>
      </v-card-text>

      <v-card-actions>
        <v-btn v-if="editing" variant="text" @click="editing = false">Назад</v-btn>
        <v-spacer />
        <v-btn v-if="editing" color="primary" :loading="saving" :disabled="!form.name?.trim() || !form.title?.trim()" @click="save">Сохранить</v-btn>
      </v-card-actions>
      <v-snackbar v-model="snack.open" :color="snack.color" timeout="2500">{{ snack.text }}</v-snackbar>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { ref, reactive, watch } from 'vue';
import api from '../api';
import { EmptyState } from '../components';
import UserPicker from './UserPicker.vue';

const props = defineProps({ modelValue: { type: Boolean, default: false } });
const emit = defineEmits(['update:modelValue', 'task-created']);

const templates = ref([]);
const editing = ref(false);
const saving = ref(false);
const snack = ref({ open: false, color: 'success', text: '' });
const form = reactive(empty());

const priorityItems = [{ title: 'Низкий', value: 'low' }, { title: 'Обычный', value: 'normal' }, { title: 'Высокий', value: 'high' }];
const freqItems = [
  { title: 'Без повтора', value: 'none' }, { title: 'Ежедневно', value: 'daily' },
  { title: 'Еженедельно', value: 'weekly' }, { title: 'Ежемесячно', value: 'monthly' },
];
const weekdayItems = [
  { title: 'Понедельник', value: 1 }, { title: 'Вторник', value: 2 }, { title: 'Среда', value: 3 },
  { title: 'Четверг', value: 4 }, { title: 'Пятница', value: 5 }, { title: 'Суббота', value: 6 }, { title: 'Воскресенье', value: 7 },
];

function empty() {
  return { id: null, name: '', title: '', description: '', priority: 'normal', tags: [], checklist: [],
    requires_result: false, assignee_id: null, recurrence_freq: 'none', recurrence_interval: 1,
    recurrence_weekday: 1, recurrence_monthday: 1, recurrence_time: '09:00' };
}
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }
function freqLabel(t) {
  if (t.recurrence_freq === 'daily') return `каждые ${t.recurrence_interval} дн.`;
  if (t.recurrence_freq === 'weekly') return weekdayItems.find((w) => w.value === t.recurrence_weekday)?.title || 'еженедельно';
  if (t.recurrence_freq === 'monthly') return `${t.recurrence_monthday} числа`;
  return '';
}

async function load() {
  try { const { data } = await api.get('/task-templates'); templates.value = data.templates || []; } catch { /* ignore */ }
}
function openCreate() { Object.assign(form, empty()); editing.value = true; }
function openEdit(t) { Object.assign(form, empty(), { ...t, tags: t.tags || [], checklist: t.checklist || [], recurrence_time: t.recurrence_time || '09:00' }); editing.value = true; }
async function save() {
  saving.value = true;
  try {
    if (form.id) await api.put(`/task-templates/${form.id}`, form);
    else await api.post('/task-templates', form);
    editing.value = false; await load(); notify('Сохранено');
  } catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
  saving.value = false;
}
async function remove(t) {
  if (!confirm(`Удалить шаблон «${t.name}»?`)) return;
  try { await api.delete(`/task-templates/${t.id}`); await load(); } catch { /* ignore */ }
}
async function run(t) {
  try {
    const { data } = await api.post(`/task-templates/${t.id}/instantiate`);
    emit('update:modelValue', false);
    emit('task-created', data.task_id);
  } catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
}

watch(() => props.modelValue, (v) => { if (v) { editing.value = false; load(); } });
</script>

<style scoped>
.tpl-row { padding: 8px 0; border-bottom: 1px solid rgba(var(--v-border-color), 0.08); }
.min-w-0 { min-width: 0; }
</style>
