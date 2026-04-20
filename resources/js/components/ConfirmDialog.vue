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
    @confirm="handleConfirm"
    @close="handleCancel"
  >
    <div v-if="html" v-html="message" />
    <div v-else>{{ message }}</div>
  </DialogShell>
</template>

<script setup>
import { ref } from 'vue';
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

let resolver = null;

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
  loading.value = false;
  open.value = true;
  return new Promise((resolve) => { resolver = resolve; });
}

function handleConfirm() {
  if (resolver) { resolver(true); resolver = null; }
  open.value = false;
}
function handleCancel() {
  if (resolver) { resolver(false); resolver = null; }
}

defineExpose({ ask });
</script>
