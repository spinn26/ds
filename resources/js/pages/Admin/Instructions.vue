<template>
  <div>
    <PageHeader title="Управление инструкциями" icon="mdi-book-edit-outline">
      <template #actions>
        <ColumnVisibilityMenu
          :headers="headers"
          v-model:visible="columnVisible"
          storage-key="instructions-cols" />
        <v-btn v-if="canEdit('instructions')" color="primary" prepend-icon="mdi-plus" @click="openCreate">Новая инструкция</v-btn>
      </template>
    </PageHeader>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по заголовку"
          density="compact" variant="outlined" hide-details rounded clearable
          prepend-inner-icon="mdi-magnify" style="max-width:280px"
          @update:model-value="loadData" />
        <v-select v-model="audienceFilter" :items="audienceOptions" placeholder="Аудитория"
          density="compact" variant="outlined" hide-details clearable
          style="max-width:180px" @update:model-value="loadData" />
        <v-select v-model="statusFilter" :items="statusOptions" placeholder="Статус"
          density="compact" variant="outlined" hide-details clearable
          style="max-width:180px" @update:model-value="loadData" />
      </div>
    </v-card>

    <v-data-table :items="items" :headers="visibleHeaders" density="compact" hover :items-per-page="50">
      <template #item.audience="{ value }">
        <v-chip size="x-small" variant="tonal">{{ audienceLabel(value) }}</v-chip>
      </template>
      <template #item.roles="{ value }">
        <span v-if="!value || !value.length" class="text-medium-emphasis text-caption">Всем</span>
        <template v-else>
          <v-chip v-for="r in value" :key="r" size="x-small" variant="tonal" color="primary" class="mr-1 mb-1">
            {{ roleTitle(r) }}
          </v-chip>
        </template>
      </template>
      <template #item.publish_status="{ value }">
        <v-chip size="x-small" :color="value === 'published' ? 'success' : 'grey'" variant="tonal">
          {{ value === 'published' ? 'Опубликовано' : 'Черновик' }}
        </v-chip>
      </template>
      <template #item.actions="{ item }">
        <v-btn icon="mdi-pencil" size="x-small" variant="text" color="success" @click="openEdit(item)" />
        <v-btn v-if="canFull('instructions')" icon="mdi-delete" size="x-small" variant="text" color="error" @click="confirmDelete(item)" />
      </template>
    </v-data-table>

    <v-dialog v-model="editOpen" max-width="900" scrollable>
      <v-card>
        <v-card-title>{{ editingId ? 'Редактирование' : 'Новая инструкция' }}</v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col cols="12" sm="8">
              <v-text-field v-model="form.title" label="Заголовок *"
                variant="outlined" density="comfortable" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.category" label="Категория *"
                variant="outlined" density="comfortable"
                hint="Например: Менеджер контрактов" persistent-hint />
            </v-col>
            <v-col cols="12" sm="4">
              <v-select v-model="form.audience" :items="audienceOptions" label="Аудитория *"
                variant="outlined" density="comfortable" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-select v-model="form.publish_status" :items="statusOptions" label="Статус *"
                variant="outlined" density="comfortable" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model.number="form.order_index" type="number"
                label="Порядок" variant="outlined" density="comfortable" />
            </v-col>
            <v-col cols="12">
              <v-select v-model="form.roles" :items="roleOptions" label="Роли"
                multiple chips closable-chips variant="outlined" density="comfortable"
                prepend-inner-icon="mdi-account-key"
                hint="Пусто — видно всем в выбранной аудитории. Иначе только указанным ролям."
                persistent-hint />
            </v-col>
            <v-col cols="12">
              <div class="d-flex ga-2 align-start">
                <v-text-field v-model="form.video_url"
                  label="Видео: ссылка YouTube/Vimeo или загруженный файл"
                  variant="outlined" density="comfortable"
                  prepend-inner-icon="mdi-play-circle" class="flex-grow-1" clearable />
                <v-btn :loading="uploadingVideo" prepend-icon="mdi-upload" variant="tonal"
                  class="mt-1" @click="pickVideo">Загрузить видео</v-btn>
                <input ref="videoInput" type="file" hidden
                  accept="video/mp4,video/webm,video/quicktime" @change="onVideoPicked">
              </div>
            </v-col>
            <v-col cols="12">
              <div class="text-caption text-medium-emphasis mb-1">
                Текст (markdown). Заголовки ## и ### попадают в оглавление.
                Картинки и фото можно перетащить прямо в редактор или вставить из буфера —
                они загрузятся на сервер автоматически.
              </div>
              <MdEditor v-model="form.body_md" :theme="editorTheme"
                language="en-US" :toolbars-exclude="['github', 'save']"
                :on-upload-img="onUploadImg" style="height: 440px" />
            </v-col>
          </v-row>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="editOpen = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" :disabled="!canSave" @click="save">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="4000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useTheme } from 'vuetify';
import { MdEditor } from 'md-editor-v3';
import 'md-editor-v3/lib/style.css';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import { useConfirm } from '../../composables/useConfirm';
import { usePermissions } from '../../composables/usePermissions';

const confirm = useConfirm();
const { canEdit, canFull } = usePermissions();

const items = ref([]);
const search = ref('');
const audienceFilter = ref(null);
const statusFilter = ref(null);
const editOpen = ref(false);
const editingId = ref(null);
const saving = ref(false);

const audienceOptions = [
  { title: 'Партнёр', value: 'partner' },
  { title: 'Сотрудник', value: 'staff' },
  { title: 'Все', value: 'both' },
];
const statusOptions = [
  { title: 'Опубликовано', value: 'published' },
  { title: 'Черновик', value: 'draft' },
];

const headers = [
  { title: 'Заголовок', key: 'title' },
  { title: 'Категория', key: 'category', width: 180 },
  { title: 'Аудитория', key: 'audience', width: 130 },
  { title: 'Роли', key: 'roles', width: 220, sortable: false },
  { title: 'Статус', key: 'publish_status', width: 140 },
  { title: 'Порядок', key: 'order_index', width: 90, align: 'end' },
  { title: '', key: 'actions', sortable: false, width: 90 },
];

const columnVisible = ref({});
const visibleHeaders = computed(() =>
  headers.filter(h => columnVisible.value[h.key] !== false)
);

const blank = () => ({ title: '', category: '', audience: 'both', roles: [], publish_status: 'draft', body_md: '', video_url: '', order_index: 0 });
const form = ref(blank());

// Роли для таргетинга. Единый источник — permission_groups (как при
// назначении роли пользователю), + структурные партнёрские роли.
const roleOptions = ref([]);
async function loadRoleOptions() {
  try {
    const { data } = await api.get('/admin/instructions/roles');
    roleOptions.value = data.roles || [];
  } catch { /* селект просто останется пустым — таргетинг необязателен */ }
}
function roleTitle(v) {
  return roleOptions.value.find(r => r.value === v)?.title || v;
}

const canSave = computed(() => form.value.title && form.value.category && form.value.audience && form.value.publish_status);

const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

// ─── Медиа: фото/картинки в теле + видео-файл ─────────────────
const vTheme = useTheme();
const editorTheme = computed(() => (vTheme.global.current.value.dark ? 'dark' : 'light'));
const uploadingVideo = ref(false);
const videoInput = ref(null);

async function uploadFile(file) {
  const fd = new FormData();
  fd.append('file', file);
  const { data } = await api.post('/admin/instructions/upload', fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return data;
}

// Хук md-editor-v3: грузим картинки на сервер и отдаём редактору их URL,
// он сам вставит markdown-разметку ![](url).
async function onUploadImg(files, callback) {
  try {
    const uploaded = await Promise.all(Array.from(files).map(f => uploadFile(f)));
    callback(uploaded.map(u => u.url));
  } catch (e) {
    notify(e.response?.data?.message || 'Не удалось загрузить изображение', 'error');
    callback([]);
  }
}

function pickVideo() { videoInput.value?.click(); }

async function onVideoPicked(e) {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;
  uploadingVideo.value = true;
  try {
    const r = await uploadFile(file);
    form.value.video_url = r.url;
    notify('Видео загружено');
  } catch (err) {
    notify(err.response?.data?.message || 'Не удалось загрузить видео', 'error');
  }
  uploadingVideo.value = false;
}

function audienceLabel(v) {
  return { partner: 'Партнёр', staff: 'Сотрудник', both: 'Все' }[v] || v;
}

async function loadData() {
  try {
    const params = {};
    if (search.value) params.search = search.value;
    if (audienceFilter.value) params.audience = audienceFilter.value;
    if (statusFilter.value) params.publish_status = statusFilter.value;
    const { data } = await api.get('/admin/instructions', { params });
    items.value = data.data;
  } catch {}
}

function openCreate() {
  editingId.value = null;
  form.value = blank();
  editOpen.value = true;
}

function openEdit(item) {
  editingId.value = item.id;
  form.value = { ...item, roles: Array.isArray(item.roles) ? [...item.roles] : [] };
  editOpen.value = true;
}

async function save() {
  saving.value = true;
  try {
    if (editingId.value) {
      await api.put('/admin/instructions/' + editingId.value, form.value);
      notify('Инструкция обновлена');
    } else {
      await api.post('/admin/instructions', form.value);
      notify('Инструкция создана');
    }
    editOpen.value = false;
    await loadData();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  saving.value = false;
}

async function confirmDelete(item) {
  if (!await confirm.ask({
    title: 'Удалить инструкцию?',
    message: `«${item.title}» будет удалена без возможности восстановления.`,
    confirmText: 'Удалить', confirmColor: 'error', icon: 'mdi-trash-can',
  })) return;
  try {
    await api.delete('/admin/instructions/' + item.id);
    notify('Удалено');
    await loadData();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
}

onMounted(() => { loadData(); loadRoleOptions(); });
</script>
