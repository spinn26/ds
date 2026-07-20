<template>
  <div class="pa-4">
    <PageHeader title="Инструкция партнёра" subtitle="Документ доступен только администраторам. Виден здесь; при необходимости — редактируется.">
      <template #actions>
        <template v-if="!editing">
          <v-btn color="primary" prepend-icon="mdi-pencil" :loading="loading" @click="startEdit">
            Редактировать
          </v-btn>
        </template>
        <template v-else>
          <v-btn variant="text" class="mr-2" :disabled="saving" @click="cancelEdit">Отмена</v-btn>
          <v-btn color="primary" prepend-icon="mdi-content-save" :loading="saving" @click="save">
            Сохранить
          </v-btn>
        </template>
      </template>
    </PageHeader>

    <v-alert v-if="editing" type="info" variant="tonal" density="compact" class="mb-3">
      Формат — Markdown. Панель сверху вставляет заголовки, списки, таблицы, ссылки и картинки.
      Справа — живое превью того, как увидит документ читатель.
    </v-alert>

    <div v-if="loading" class="d-flex justify-center py-12">
      <v-progress-circular indeterminate color="primary" />
    </div>

    <template v-else>
      <!-- Просмотр -->
      <div v-show="!editing" class="doc-surface">
        <MdPreview :id="previewId" :model-value="content" theme="dark" preview-theme="github" />
      </div>

      <!-- Редактирование -->
      <MdEditor
        v-if="editing"
        v-model="draft"
        theme="dark"
        preview-theme="github"
        language="en-US"
        :toolbars-exclude="['github', 'save']"
        style="height: calc(100vh - 240px)"
      />
    </template>

    <div v-if="updatedAt && !loading" class="text-caption text-medium-emphasis mt-2">
      Последнее изменение: {{ formatDate(updatedAt) }}
    </div>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="3000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { MdEditor, MdPreview } from 'md-editor-v3';
import 'md-editor-v3/lib/style.css';
import api from '../../api';
import { PageHeader } from '../../components';

const SLUG = 'partner-cabinet';
const previewId = 'partner-guide-preview';

const loading = ref(true);
const saving = ref(false);
const editing = ref(false);
const content = ref('');   // сохранённая версия (для просмотра)
const draft = ref('');     // черновик в редакторе
const updatedAt = ref(null);
const snack = ref({ open: false, color: 'success', text: '' });

function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

function formatDate(iso) {
  if (!iso) return '';
  try { return new Date(iso).toLocaleString('ru-RU'); } catch { return iso; }
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/admin/doc-pages/${SLUG}`);
    content.value = data.content || '';
    updatedAt.value = data.updatedAt || null;
  } catch (e) {
    notify(e.response?.data?.message || 'Не удалось загрузить документ', 'error');
  } finally {
    loading.value = false;
  }
}

function startEdit() {
  draft.value = content.value;
  editing.value = true;
}

function cancelEdit() {
  editing.value = false;
  draft.value = '';
}

async function save() {
  saving.value = true;
  try {
    const { data } = await api.put(`/admin/doc-pages/${SLUG}`, { content: draft.value });
    content.value = draft.value;
    updatedAt.value = data.updatedAt || updatedAt.value;
    editing.value = false;
    notify('Инструкция сохранена');
  } catch (e) {
    notify(e.response?.data?.message || 'Не удалось сохранить', 'error');
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.doc-surface {
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 12px;
  padding: 8px 20px;
}
/* md-editor-v3 сам управляет своими цветами в теме dark */
.doc-surface :deep(.md-editor-preview-wrapper) {
  padding: 12px 0;
}
</style>
