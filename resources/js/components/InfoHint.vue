<template>
  <!-- Маленькая иконка-подсказка с тултипом для пояснения терминов/показателей.
       Использование: <InfoHint :text="glossary.lp" /> или <InfoHint text="…" />. -->
  <v-tooltip :text="text" location="top" max-width="320" open-on-click>
    <template #activator="{ props }">
      <v-icon v-bind="props" :size="size" class="info-hint" tabindex="0" role="button"
        aria-label="Подсказка">mdi-information-outline</v-icon>
    </template>
  </v-tooltip>
</template>

<script setup>
defineProps({
  text: { type: String, required: true },
  size: { type: [Number, String], default: 15 },
});
</script>

<style scoped>
.info-hint {
  cursor: help;
  opacity: 0.55;
  vertical-align: middle;
  transition: opacity 120ms ease;
}
.info-hint:hover,
.info-hint:focus-visible {
  opacity: 1;
  color: rgb(var(--v-theme-primary));
}
</style>
