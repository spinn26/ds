<template>
  <div>
    <div class="text-subtitle-2 font-weight-bold text-uppercase letter-spacing-1 text-medium-emphasis mb-3">
      Урок
    </div>
    <v-text-field
      v-model="local.title"
      label="Название урока *"
      variant="outlined" density="comfortable"
    />
    <v-textarea
      v-model="local.content"
      label="Краткое описание (необязательно)"
      variant="outlined" density="comfortable"
      rows="2" auto-grow
    />

    <!-- Drip-feed + стоп-урок + домашка (миграция 2026_05_25_000020) -->
    <v-expansion-panels variant="accordion" class="mt-2">
      <v-expansion-panel>
        <v-expansion-panel-title>
          <v-icon size="18" class="me-2">mdi-calendar-clock</v-icon>
          Расписание и условия открытия
        </v-expansion-panel-title>
        <v-expansion-panel-text>
          <v-row dense>
            <v-col cols="12" sm="6">
              <v-text-field
                v-model.number="local.drip_delay_hours"
                label="Открыть через N часов от старта курса"
                type="number" min="0"
                hint="Пустое = открыт сразу. 24 = откроется через сутки"
                persistent-hint
                variant="outlined" density="comfortable"
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field
                v-model="local.drip_open_at"
                label="Или конкретная дата открытия"
                type="datetime-local"
                hint="Имеет приоритет над «через N часов»"
                persistent-hint
                variant="outlined" density="comfortable"
              />
            </v-col>
          </v-row>
          <v-switch
            v-model="local.is_stop_lesson"
            label="Стоп-урок (пока не пройден — следующие уроки закрыты)"
            color="primary" hide-details density="compact"
            class="mt-2"
          />
          <v-switch
            v-model="local.requires_homework"
            label="Урок требует домашнее задание (проверяет куратор)"
            color="primary" hide-details density="compact"
            class="mt-1"
          />
          <v-textarea
            v-if="local.requires_homework"
            v-model="local.homework_instructions"
            label="Инструкция к домашке"
            variant="outlined" density="comfortable"
            rows="3" auto-grow
            class="mt-3"
          />
        </v-expansion-panel-text>
      </v-expansion-panel>
    </v-expansion-panels>

    <!-- Блоки конструктора -->
    <div class="text-subtitle-1 font-weight-bold mt-4 mb-2">
      Содержимое урока ({{ local.body.length }} блоков)
    </div>

    <div v-if="!local.body.length" class="empty-blocks">
      Урок пустой. Добавьте блоки кнопками ниже.
    </div>

    <div
      v-for="(block, idx) in local.body"
      :key="idx"
      class="block-card"
      :class="{
        'drag-source': dragIdx === idx,
        'drop-before': hoverIdx === idx && hoverPos === 'before',
        'drop-after':  hoverIdx === idx && hoverPos === 'after',
      }"
      :draggable="true"
      @dragstart="onDragStart(idx, $event)"
      @dragend="onDragEnd"
      @dragover.prevent="onDragOver(idx, $event)"
      @dragleave="onDragLeave(idx)"
      @drop.prevent="onDrop(idx)"
    >
      <div class="block-header">
        <v-icon size="14" class="drag-handle me-1" title="Перетащите для смены порядка">
          mdi-drag
        </v-icon>
        <v-icon size="18" :color="blockMeta(block.type).color" class="me-2">
          {{ blockMeta(block.type).icon }}
        </v-icon>
        <span class="block-type-label">{{ blockMeta(block.type).label }}</span>
        <v-spacer />
        <v-btn icon="mdi-arrow-up" size="x-small" variant="text"
          :disabled="idx === 0" @click="move(idx, -1)" />
        <v-btn icon="mdi-arrow-down" size="x-small" variant="text"
          :disabled="idx === local.body.length - 1" @click="move(idx, 1)" />
        <v-btn icon="mdi-delete-outline" size="x-small" variant="text" color="error"
          @click="remove(idx)" />
      </div>

      <!-- Текст -->
      <v-textarea
        v-if="block.type === 'text'"
        v-model="block.value"
        label="Текст блока"
        variant="outlined" density="comfortable"
        rows="3" auto-grow hide-details
      />

      <!-- Видео -->
      <template v-else-if="block.type === 'video'">
        <v-text-field
          v-model="block.label"
          label="Заголовок видео (опционально)"
          variant="outlined" density="comfortable" hide-details
          class="mb-2"
        />
        <v-text-field
          v-model="block.value"
          label="URL видео (Rutube / YouTube / Vimeo)"
          placeholder="https://rutube.ru/video/..."
          variant="outlined" density="comfortable" hide-details
        />
      </template>

      <!-- Аудио / файл / ссылка / картинка -->
      <template v-else-if="['audio', 'file', 'link', 'image'].includes(block.type)">
        <v-text-field
          v-model="block.label"
          :label="block.type === 'link' ? 'Текст кнопки' : 'Подпись (опционально)'"
          variant="outlined" density="comfortable" hide-details
          class="mb-2"
        />
        <v-text-field
          v-model="block.value"
          :label="urlLabelFor(block.type)"
          variant="outlined" density="comfortable" hide-details
        />
      </template>

      <!-- Внутренняя ссылка -->
      <template v-else-if="block.type === 'inner_link'">
        <v-text-field
          v-model="block.label"
          label="Текст ссылки"
          variant="outlined" density="comfortable" hide-details
          class="mb-2"
        />
        <v-text-field
          v-model="block.value"
          label="ID урока или /education/courses/X/lessons/Y"
          variant="outlined" density="comfortable" hide-details
        />
      </template>
    </div>

    <!-- Кнопки добавления -->
    <div class="add-bar mt-3">
      <span class="text-caption text-medium-emphasis me-2">+ блок:</span>
      <v-btn
        v-for="t in blockTypes" :key="t.type"
        size="small" variant="tonal"
        :prepend-icon="t.icon"
        @click="addBlock(t.type)"
      >
        {{ t.label }}
      </v-btn>
    </div>

    <!-- Save / Delete -->
    <div class="d-flex ga-2 mt-6">
      <v-btn color="primary" :loading="saving" @click="$emit('save', local)">
        Сохранить урок
      </v-btn>
      <v-btn variant="text" @click="$emit('cancel')">К курсу</v-btn>
      <v-spacer />
      <v-btn color="error" variant="text" prepend-icon="mdi-delete"
        @click="$emit('delete')">
        Удалить урок
      </v-btn>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
  lesson: { type: Object, required: true },
  courseId: { type: [Number, String], default: null },
  saving: { type: Boolean, default: false },
});
defineEmits(['save', 'cancel', 'delete']);

const local = ref({
  title: '',
  content: '',
  body: [],
  sort_order: 0,
  drip_delay_hours: null,
  drip_open_at: '',
  is_stop_lesson: false,
  requires_homework: false,
  homework_instructions: '',
});

watch(() => props.lesson, (l) => {
  if (!l) return;
  local.value = {
    title: l.title || '',
    content: l.content || '',
    body: Array.isArray(l.body) ? JSON.parse(JSON.stringify(l.body)) : [],
    sort_order: l.sort_order || 0,
    drip_delay_hours: l.drip_delay_hours ?? null,
    drip_open_at: l.drip_open_at
      ? String(l.drip_open_at).slice(0, 16)   // ISO → datetime-local
      : '',
    is_stop_lesson: !!l.is_stop_lesson,
    requires_homework: !!l.requires_homework,
    homework_instructions: l.homework_instructions || '',
  };
}, { immediate: true });

const blockTypes = [
  { type: 'text', label: 'Текст', icon: 'mdi-text' },
  { type: 'video', label: 'Видео', icon: 'mdi-video' },
  { type: 'audio', label: 'Аудио', icon: 'mdi-music-note' },
  { type: 'image', label: 'Картинка', icon: 'mdi-image' },
  { type: 'file', label: 'Файл', icon: 'mdi-file-document' },
  { type: 'link', label: 'Кнопка-ссылка', icon: 'mdi-link' },
  { type: 'inner_link', label: 'На другой урок', icon: 'mdi-link-variant' },
];

function blockMeta(type) {
  return blockTypes.find(t => t.type === type)
    || { type, label: type, icon: 'mdi-help-circle', color: 'grey' };
}
function urlLabelFor(type) {
  return {
    audio: 'URL аудио',
    file: 'URL файла (PDF/DOCX/...)',
    link: 'URL для перехода',
    image: 'URL изображения',
  }[type] || 'URL';
}

function addBlock(type) {
  local.value.body.push({
    type, value: '', label: '', order: local.value.body.length,
  });
}
function move(idx, delta) {
  const newIdx = idx + delta;
  if (newIdx < 0 || newIdx >= local.value.body.length) return;
  const arr = local.value.body;
  [arr[idx], arr[newIdx]] = [arr[newIdx], arr[idx]];
}
function remove(idx) { local.value.body.splice(idx, 1); }

// === Drag-and-drop блоков (native HTML5, без vuedraggable) ===
import { ref as _r } from 'vue';
const dragIdx = _r(null);
const hoverIdx = _r(null);
const hoverPos = _r(null);   // 'before' | 'after'

function onDragStart(idx, e) {
  dragIdx.value = idx;
  e.dataTransfer.effectAllowed = 'move';
  e.dataTransfer.setData('text/plain', String(idx));
}
function onDragEnd() {
  dragIdx.value = null;
  hoverIdx.value = null;
  hoverPos.value = null;
}
function onDragOver(idx, e) {
  if (dragIdx.value == null || dragIdx.value === idx) return;
  const r = e.currentTarget.getBoundingClientRect();
  hoverIdx.value = idx;
  hoverPos.value = (e.clientY - r.top) < r.height / 2 ? 'before' : 'after';
  e.dataTransfer.dropEffect = 'move';
}
function onDragLeave(idx) {
  if (hoverIdx.value === idx) { hoverIdx.value = null; hoverPos.value = null; }
}
function onDrop(idx) {
  const from = dragIdx.value;
  const pos = hoverPos.value;
  if (from == null || from === idx || !pos) return;
  const arr = local.value.body;
  const item = arr.splice(from, 1)[0];
  let insertAt = pos === 'after' ? idx + 1 : idx;
  if (from < idx) insertAt -= 1;
  arr.splice(insertAt, 0, item);
  onDragEnd();
}
</script>

<style scoped>
.empty-blocks {
  padding: 24px;
  text-align: center;
  font-size: 13px;
  color: rgba(var(--v-theme-on-surface), 0.55);
  border: 2px dashed rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 10px;
}

.block-card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 10px;
  padding: 10px 12px 12px;
  margin-bottom: 10px;
  background: rgb(var(--v-theme-surface));
  transition: opacity 0.1s ease, box-shadow 0.15s ease;
}
.block-card.drag-source { opacity: 0.4; }
.block-card.drop-before { box-shadow: 0 -3px 0 rgb(var(--v-theme-primary)); }
.block-card.drop-after  { box-shadow: 0  3px 0 rgb(var(--v-theme-primary)); }

.drag-handle {
  cursor: grab;
  color: rgba(var(--v-theme-on-surface), 0.45);
}
.drag-handle:active { cursor: grabbing; }

.block-header {
  display: flex;
  align-items: center;
  margin-bottom: 8px;
}
.block-type-label {
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  color: rgba(var(--v-theme-on-surface), 0.65);
}

.add-bar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}

.letter-spacing-1 { letter-spacing: 1.2px; }
</style>
