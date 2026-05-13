<template>
  <v-card class="pa-4">
    <div class="d-flex align-center justify-space-between mb-2">
      <div class="text-subtitle-1 font-weight-bold">
        <v-icon class="mr-1" size="20" color="warning">mdi-note-text-outline</v-icon>
        Заметка
      </div>
      <span class="text-caption text-medium-emphasis" v-if="lastSaved">
        Сохранено · {{ lastSavedAgo }}
      </span>
      <span v-else-if="dirty" class="text-caption text-warning">
        <v-icon size="14">mdi-pencil-outline</v-icon> Изменено
      </span>
    </div>
    <v-textarea v-model="content" placeholder="Запишите что-нибудь — сохранится автоматически"
      variant="outlined" density="compact" rows="6" auto-grow hide-details
      @input="onInput" @blur="flushSave" class="note-area" />
  </v-card>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import api from '../api';

const content = ref('');
const dirty = ref(false);
const lastSaved = ref(null);
let saveTimer = null;

const lastSavedAgo = computed(() => {
  if (!lastSaved.value) return '';
  const sec = Math.floor((Date.now() - new Date(lastSaved.value).getTime()) / 1000);
  if (sec < 5) return 'только что';
  if (sec < 60) return `${sec} сек назад`;
  if (sec < 3600) return `${Math.floor(sec / 60)} мин назад`;
  return new Date(lastSaved.value).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
});

async function load() {
  try {
    const { data } = await api.get('/my-note');
    content.value = data.content || '';
    lastSaved.value = data.updated_at || null;
  } catch {}
}

function onInput() {
  dirty.value = true;
  // Debounced auto-save: 1.2s после остановки печати.
  if (saveTimer) clearTimeout(saveTimer);
  saveTimer = setTimeout(flushSave, 1200);
}

async function flushSave() {
  if (!dirty.value) return;
  if (saveTimer) { clearTimeout(saveTimer); saveTimer = null; }
  try {
    const { data } = await api.put('/my-note', { content: content.value });
    lastSaved.value = data.updated_at || new Date().toISOString();
    dirty.value = false;
  } catch {}
}

onMounted(load);
onUnmounted(() => {
  if (saveTimer) clearTimeout(saveTimer);
  // Финальный save при размонтировании, если есть несохранённые изменения.
  if (dirty.value) flushSave();
});
</script>

<style scoped>
.note-area :deep(textarea) {
  font-family: 'Caveat', 'Segoe UI', sans-serif;
  font-size: 15px;
  line-height: 1.5;
}
</style>
