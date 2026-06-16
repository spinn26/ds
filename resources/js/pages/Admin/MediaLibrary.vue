<template>
  <div>
    <PageHeader title="Медиа-библиотека" icon="mdi-folder-multiple-image">
      <template #actions>
        <v-btn color="primary" size="small" prepend-icon="mdi-upload" :loading="uploading" @click="$refs.fileInput.click()">
          Загрузить
        </v-btn>
        <input ref="fileInput" type="file" class="d-none" @change="onUpload" />
      </template>
    </PageHeader>

    <v-alert type="info" variant="tonal" density="comfortable" class="mb-3">
      Файлы для использования в дизайне, контенте, письмах. Скопируйте URL и
      вставьте, где нужно (лого, баннеры, картинки страниц).
    </v-alert>

    <v-card class="pa-3">
      <div v-if="loading" class="d-flex justify-center pa-6"><v-progress-circular indeterminate color="primary" /></div>
      <EmptyState v-else-if="!files.length" icon="mdi-image-off-outline" message="Файлов нет — загрузите первый" />
      <div v-else class="media-grid">
        <div v-for="f in files" :key="f.path" class="media-cell">
          <div class="media-thumb">
            <v-img v-if="isImage(f.name)" :src="f.url" cover height="120" />
            <div v-else class="media-file"><v-icon size="40">{{ fileIcon(f.name) }}</v-icon></div>
          </div>
          <div class="media-name" :title="f.name">{{ f.name }}</div>
          <div class="text-caption text-medium-emphasis">{{ fmtSize(f.size) }}</div>
          <div class="d-flex ga-1 mt-1">
            <v-btn size="x-small" variant="text" prepend-icon="mdi-content-copy" @click="copyUrl(f.url)">URL</v-btn>
            <v-spacer />
            <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="remove(f)" />
          </div>
        </div>
      </div>
    </v-card>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="3000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, EmptyState } from '../../components';

const files = ref([]);
const loading = ref(false);
const uploading = ref(false);
const fileInput = ref(null);
const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

function isImage(n) { return /\.(png|jpe?g|webp|gif|ico)$/i.test(n); }
function fileIcon(n) {
  if (/\.pdf$/i.test(n)) return 'mdi-file-pdf-box';
  if (/\.(docx?|rtf)$/i.test(n)) return 'mdi-file-word-box';
  if (/\.(xlsx?|csv)$/i.test(n)) return 'mdi-file-excel-box';
  if (/\.(mp4|webm|mov)$/i.test(n)) return 'mdi-file-video';
  return 'mdi-file';
}
function fmtSize(b) {
  if (b < 1024) return b + ' Б';
  if (b < 1048576) return (b / 1024).toFixed(0) + ' КБ';
  return (b / 1048576).toFixed(1) + ' МБ';
}

async function load() {
  loading.value = true;
  try { const { data } = await api.get('/admin/media'); files.value = data.files || []; }
  catch (e) { notify(e.response?.data?.message || 'Ошибка загрузки', 'error'); }
  loading.value = false;
}

async function onUpload(e) {
  const file = e.target.files?.[0];
  if (!file) return;
  uploading.value = true;
  try {
    const fd = new FormData();
    fd.append('file', file);
    await api.post('/admin/media', fd, { headers: { 'Content-Type': 'multipart/form-data' } });
    await load();
    notify('Загружено');
  } catch (err) { notify(err.response?.data?.message || 'Ошибка загрузки', 'error'); }
  uploading.value = false;
  e.target.value = '';
}

async function copyUrl(url) {
  const full = url.startsWith('http') ? url : (window.location.origin + url);
  try { await navigator.clipboard.writeText(full); notify('URL скопирован'); }
  catch { notify('Не удалось скопировать', 'error'); }
}

async function remove(f) {
  if (!confirm(`Удалить «${f.name}»?`)) return;
  try { await api.delete('/admin/media', { params: { path: f.path } }); await load(); notify('Удалено'); }
  catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
}

onMounted(load);
</script>

<style scoped>
.media-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; }
.media-cell {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  border-radius: 10px; padding: 8px; background: rgb(var(--v-theme-surface));
}
.media-thumb { border-radius: 8px; overflow: hidden; background: rgba(var(--v-theme-on-surface), 0.04); }
.media-file { height: 120px; display: flex; align-items: center; justify-content: center; color: rgba(var(--v-theme-on-surface), 0.5); }
.media-name { font-size: 12px; margin-top: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
</style>
