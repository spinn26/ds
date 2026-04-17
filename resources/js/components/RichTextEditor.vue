<template>
  <div class="rich-editor" :class="{ 'is-focused': focused }">
    <div class="rich-toolbar">
      <button type="button" class="tb-btn" title="Жирный"
        :class="{ active: state.bold }" @mousedown.prevent="exec('bold')">
        <v-icon size="16">mdi-format-bold</v-icon>
      </button>
      <button type="button" class="tb-btn" title="Курсив"
        :class="{ active: state.italic }" @mousedown.prevent="exec('italic')">
        <v-icon size="16">mdi-format-italic</v-icon>
      </button>
      <button type="button" class="tb-btn" title="Подчёркнутый"
        :class="{ active: state.underline }" @mousedown.prevent="exec('underline')">
        <v-icon size="16">mdi-format-underline</v-icon>
      </button>
      <span class="tb-sep" />

      <select class="tb-select" @mousedown.prevent @change="applyBlock($event)">
        <option value="p">Текст</option>
        <option value="h1">Заголовок 1</option>
        <option value="h2">Заголовок 2</option>
        <option value="h3">Заголовок 3</option>
      </select>
      <span class="tb-sep" />

      <button type="button" class="tb-btn" title="Маркированный список"
        @mousedown.prevent="exec('insertUnorderedList')">
        <v-icon size="16">mdi-format-list-bulleted</v-icon>
      </button>
      <button type="button" class="tb-btn" title="Нумерованный список"
        @mousedown.prevent="exec('insertOrderedList')">
        <v-icon size="16">mdi-format-list-numbered</v-icon>
      </button>
      <span class="tb-sep" />

      <button type="button" class="tb-btn" title="По левому краю"
        @mousedown.prevent="exec('justifyLeft')">
        <v-icon size="16">mdi-format-align-left</v-icon>
      </button>
      <button type="button" class="tb-btn" title="По центру"
        @mousedown.prevent="exec('justifyCenter')">
        <v-icon size="16">mdi-format-align-center</v-icon>
      </button>
      <button type="button" class="tb-btn" title="По правому краю"
        @mousedown.prevent="exec('justifyRight')">
        <v-icon size="16">mdi-format-align-right</v-icon>
      </button>
      <span class="tb-sep" />

      <button type="button" class="tb-btn" title="Ссылка"
        @mousedown.prevent="insertLink">
        <v-icon size="16">mdi-link-variant</v-icon>
      </button>
      <button type="button" class="tb-btn" title="Убрать ссылку"
        @mousedown.prevent="exec('unlink')">
        <v-icon size="16">mdi-link-variant-off</v-icon>
      </button>
      <span class="tb-sep" />

      <button type="button" class="tb-btn" title="Цитата"
        @mousedown.prevent="applyBlockName('blockquote')">
        <v-icon size="16">mdi-format-quote-close</v-icon>
      </button>
      <button type="button" class="tb-btn" title="Горизонтальная линия"
        @mousedown.prevent="exec('insertHorizontalRule')">
        <v-icon size="16">mdi-minus</v-icon>
      </button>
      <span class="tb-sep" />

      <button type="button" class="tb-btn" title="Очистить форматирование"
        @mousedown.prevent="exec('removeFormat')">
        <v-icon size="16">mdi-format-clear</v-icon>
      </button>

      <span style="flex:1" />

      <slot name="toolbar-extra" />
    </div>

    <div ref="editor"
      class="rich-content"
      contenteditable="true"
      :style="{ minHeight: minHeight }"
      @input="onInput"
      @focus="focused = true"
      @blur="focused = false"
      @keyup="updateState"
      @mouseup="updateState"
      @paste="onPaste" />
  </div>
</template>

<script setup>
import { ref, watch, onMounted, reactive } from 'vue';

const props = defineProps({
  modelValue: { type: String, default: '' },
  minHeight: { type: String, default: '220px' },
});
const emit = defineEmits(['update:modelValue']);

const editor = ref(null);
const focused = ref(false);
const state = reactive({ bold: false, italic: false, underline: false });

function setInitial() {
  if (editor.value && editor.value.innerHTML !== (props.modelValue || '')) {
    editor.value.innerHTML = props.modelValue || '';
  }
}

onMounted(setInitial);
watch(() => props.modelValue, (v) => {
  // Only update DOM when external value differs from current innerHTML
  if (editor.value && editor.value.innerHTML !== (v || '')) {
    editor.value.innerHTML = v || '';
  }
});

function onInput() {
  emit('update:modelValue', editor.value?.innerHTML || '');
  updateState();
}

function onPaste(e) {
  // Force plain-text paste — strips external formatting that usually breaks email HTML
  e.preventDefault();
  const text = (e.clipboardData || window.clipboardData).getData('text/plain');
  document.execCommand('insertText', false, text);
}

function exec(cmd, arg = null) {
  editor.value?.focus();
  document.execCommand(cmd, false, arg);
  onInput();
}

function applyBlock(ev) {
  const tag = ev.target.value;
  applyBlockName(tag);
  ev.target.value = 'p';
}

function applyBlockName(tag) {
  editor.value?.focus();
  document.execCommand('formatBlock', false, tag);
  onInput();
}

function insertLink() {
  const url = window.prompt('Введите URL:', 'https://');
  if (!url) return;
  exec('createLink', url);
}

function updateState() {
  try {
    state.bold = document.queryCommandState('bold');
    state.italic = document.queryCommandState('italic');
    state.underline = document.queryCommandState('underline');
  } catch {
    // queryCommandState is deprecated but still works in all modern browsers.
    // Silently ignore if browser blocks it.
  }
}

defineExpose({
  focus: () => editor.value?.focus(),
  insertAtCursor: (html) => {
    editor.value?.focus();
    document.execCommand('insertHTML', false, html);
    onInput();
  },
});
</script>

<style scoped>
.rich-editor {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
  overflow: hidden;
  transition: border-color 0.15s;
  background: rgb(var(--v-theme-surface));
}
.rich-editor.is-focused {
  border-color: rgb(var(--v-theme-primary));
  box-shadow: 0 0 0 1px rgb(var(--v-theme-primary));
}

.rich-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 2px;
  padding: 6px 8px;
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background: rgba(var(--v-theme-on-surface), 0.03);
}

.tb-btn {
  background: transparent;
  border: none;
  padding: 4px 6px;
  border-radius: 6px;
  cursor: pointer;
  color: rgba(var(--v-theme-on-surface), 0.7);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: background 0.12s;
}
.tb-btn:hover {
  background: rgba(var(--v-theme-primary), 0.1);
  color: rgb(var(--v-theme-primary));
}
.tb-btn.active {
  background: rgba(var(--v-theme-primary), 0.18);
  color: rgb(var(--v-theme-primary));
}

.tb-sep {
  width: 1px;
  height: 18px;
  background: rgba(var(--v-border-color), var(--v-border-opacity));
  margin: 0 4px;
}

.tb-select {
  background: transparent;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
  padding: 3px 6px;
  font-size: 12px;
  color: rgb(var(--v-theme-on-surface));
  cursor: pointer;
}

.rich-content {
  padding: 12px 14px;
  outline: none;
  font-size: 14px;
  line-height: 1.55;
  color: rgb(var(--v-theme-on-surface));
}
.rich-content :deep(h1),
.rich-content :deep(h2),
.rich-content :deep(h3) { margin: 0.6em 0 0.3em; font-weight: 700; line-height: 1.2; }
.rich-content :deep(h1) { font-size: 1.6em; }
.rich-content :deep(h2) { font-size: 1.3em; }
.rich-content :deep(h3) { font-size: 1.1em; }
.rich-content :deep(p)  { margin: 0 0 0.6em; }
.rich-content :deep(ul),
.rich-content :deep(ol) { padding-left: 1.4em; margin: 0 0 0.6em; }
.rich-content :deep(blockquote) {
  border-left: 3px solid rgba(var(--v-theme-primary), 0.6);
  margin: 0.6em 0;
  padding: 0.2em 0.9em;
  color: rgba(var(--v-theme-on-surface), 0.8);
  background: rgba(var(--v-theme-on-surface), 0.04);
  border-radius: 0 6px 6px 0;
}
.rich-content :deep(a) { color: rgb(var(--v-theme-primary)); text-decoration: underline; }
.rich-content :deep(hr) { border: none; border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); margin: 1em 0; }
</style>
