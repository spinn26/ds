<template>
  <DialogShell
    v-model="open"
    :title="title"
    :max-width="maxWidth"
    :loading="loading"
    :confirm-text="confirmText"
    :confirm-color="confirmColor"
    :cancel-text="cancelText"
    :icon="icon"
    :icon-color="iconColor"
    :confirm-disabled="inputInvalid"
    @confirm="handleConfirm"
    @close="handleCancel"
  >
    <div v-if="html" v-html="message" />
    <div v-else>{{ message }}</div>

    <!-- Режим «подтверждение с причиной»: опция input в ask(). Нужен там, где
         действие обязано быть объяснено в аудит-логе (терминация партнёра,
         отмена терминации) — window.prompt такое не гарантирует. -->
    <v-textarea
      v-if="input"
      v-model="inputValue"
      class="mt-4"
      :label="input.label || 'Причина'"
      :placeholder="input.placeholder || ''"
      :rows="input.rows || 3"
      :error-messages="inputTouched && inputInvalid ? ['Укажите причину'] : []"
      variant="outlined" density="compact" auto-grow autofocus
      @blur="inputTouched = true"
    />
  </DialogShell>
</template>

<script setup>
import { computed, ref } from 'vue';
import DialogShell from './DialogShell.vue';

// A plain-shape confirm dialog. Works either:
// 1) Standalone with v-model + @confirm — like a normal dialog.
// 2) Imperatively through useConfirm() — call .ask(...) and await the promise.

const open = ref(false);
const title = ref('Подтвердите действие');
const message = ref('');
const confirmText = ref('Ок');
const cancelText = ref('Отмена');
const confirmColor = ref('primary');
const icon = ref(null);
const iconColor = ref('primary');
const maxWidth = ref(420);
const loading = ref(false);
const html = ref(false);

// Режим ввода: opts.input = { label, placeholder, rows, required }.
const input = ref(null);
const inputValue = ref('');
const inputTouched = ref(false);
const inputInvalid = computed(() =>
  !!input.value?.required && !String(inputValue.value || '').trim());

let resolver = null;

/**
 * ask(opts) → Promise<boolean>
 * ask({ ...opts, input: {...} }) → Promise<{ confirmed: boolean, value: string }>
 */
function ask(opts = {}) {
  title.value = opts.title ?? 'Подтвердите действие';
  message.value = opts.message ?? '';
  confirmText.value = opts.confirmText ?? 'Ок';
  cancelText.value = opts.cancelText ?? 'Отмена';
  confirmColor.value = opts.confirmColor ?? 'primary';
  icon.value = opts.icon ?? null;
  iconColor.value = opts.iconColor ?? (opts.confirmColor === 'error' ? 'error' : 'primary');
  maxWidth.value = opts.maxWidth ?? 420;
  html.value = !!opts.html;
  input.value = opts.input ?? null;
  inputValue.value = opts.input?.value ?? '';
  inputTouched.value = false;
  loading.value = false;
  open.value = true;
  return new Promise((resolve) => { resolver = resolve; });
}

function handleConfirm() {
  // Обязательное поле пустое — не закрываем диалог, подсвечиваем ошибку.
  if (inputInvalid.value) { inputTouched.value = true; return; }
  if (resolver) {
    resolver(input.value ? { confirmed: true, value: String(inputValue.value || '').trim() } : true);
    resolver = null;
  }
  open.value = false;
}
function handleCancel() {
  if (resolver) {
    resolver(input.value ? { confirmed: false, value: '' } : false);
    resolver = null;
  }
}

defineExpose({ ask });
</script>
