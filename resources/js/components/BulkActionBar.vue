<template>
  <v-slide-y-transition>
    <v-card
      v-if="count > 0"
      class="mb-3 pa-3"
      :color="color"
      variant="tonal"
    >
      <div class="d-flex align-center flex-wrap ga-2">
        <v-chip :color="color" variant="flat">
          <v-icon start size="16">mdi-checkbox-multiple-marked</v-icon>
          {{ label }}: {{ count }}
        </v-chip>

        <slot />

        <v-spacer />

        <v-btn
          size="small"
          variant="text"
          prepend-icon="mdi-close"
          @click="$emit('clear')"
        >
          {{ clearText }}
        </v-btn>
      </div>

      <v-alert
        v-if="message"
        :type="messageType"
        density="compact"
        class="mt-2"
        closable
        @click:close="$emit('dismiss-message')"
      >
        {{ message }}
      </v-alert>
    </v-card>
  </v-slide-y-transition>
</template>

<script setup>
defineProps({
  count: { type: Number, default: 0 },
  label: { type: String, default: 'Выбрано' },
  color: { type: String, default: 'primary' },
  clearText: { type: String, default: 'Снять выбор' },
  message: { type: String, default: '' },
  messageType: { type: String, default: 'info' },
});

defineEmits(['clear', 'dismiss-message']);
</script>
