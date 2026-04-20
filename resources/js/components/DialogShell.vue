<template>
  <v-dialog
    :model-value="modelValue"
    :max-width="maxWidth"
    :persistent="persistent"
    :scrollable="scrollable"
    :fullscreen="fullscreen"
    @update:model-value="$emit('update:modelValue', $event)"
  >
    <v-card :loading="loading">
      <v-card-title
        v-if="title || $slots.title"
        class="d-flex align-center"
        :class="titleClass"
      >
        <v-icon v-if="icon" :color="iconColor" class="me-2">{{ icon }}</v-icon>
        <span class="text-h6">
          <slot name="title">{{ title }}</slot>
        </span>
        <span v-if="subtitle || $slots.subtitle" class="text-body-2 text-medium-emphasis ms-3">
          <slot name="subtitle">{{ subtitle }}</slot>
        </span>
        <v-spacer />
        <v-btn
          v-if="closable"
          icon="mdi-close"
          variant="text"
          size="small"
          @click="close"
        />
      </v-card-title>

      <v-divider v-if="divided && (title || $slots.title)" />

      <v-card-text :class="bodyClass">
        <slot />
      </v-card-text>

      <template v-if="$slots.actions || showDefaultActions">
        <v-divider v-if="divided" />
        <v-card-actions :class="actionsClass">
          <slot name="actions">
            <v-spacer />
            <v-btn variant="text" @click="close">{{ cancelText }}</v-btn>
            <v-btn
              v-if="showConfirm"
              :color="confirmColor"
              :loading="loading"
              :disabled="confirmDisabled"
              variant="flat"
              @click="$emit('confirm')"
            >
              {{ confirmText }}
            </v-btn>
          </slot>
        </v-card-actions>
      </template>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, default: null },
  subtitle: { type: String, default: null },
  icon: { type: String, default: null },
  iconColor: { type: String, default: 'primary' },

  maxWidth: { type: [String, Number], default: 600 },
  persistent: { type: Boolean, default: false },
  scrollable: { type: Boolean, default: true },
  fullscreen: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  closable: { type: Boolean, default: true },
  divided: { type: Boolean, default: true },

  titleClass: { type: String, default: 'pa-4' },
  bodyClass: { type: String, default: 'pa-4' },
  actionsClass: { type: String, default: 'pa-3' },

  // Default actions (Cancel + optional Confirm). If $slots.actions is
  // provided, the default is replaced entirely.
  showDefaultActions: { type: Boolean, default: true },
  showConfirm: { type: Boolean, default: true },
  confirmText: { type: String, default: 'Сохранить' },
  cancelText: { type: String, default: 'Отмена' },
  confirmColor: { type: String, default: 'primary' },
  confirmDisabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'confirm', 'close']);

const close = () => {
  emit('update:modelValue', false);
  emit('close');
};
</script>
