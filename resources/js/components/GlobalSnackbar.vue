<template>
  <v-snackbar v-model="snackbar" :color="snackbarColor" :timeout="snackbarTimeout"
    location="top right" multi-line>
    {{ snackbarText }}
    <template #actions>
      <v-btn v-if="snackbarAction"
        variant="text" size="small"
        :to="typeof snackbarAction.to === 'string' || typeof snackbarAction.to === 'object' ? snackbarAction.to : undefined"
        @click="onActionClick">
        {{ snackbarAction.label }}
      </v-btn>
      <v-btn icon="mdi-close" size="small" variant="text" @click="snackbar = false" />
    </template>
  </v-snackbar>
</template>

<script setup>
import { useSnackbar } from '../composables/useSnackbar';

const { snackbar, snackbarText, snackbarColor, snackbarTimeout, snackbarAction } = useSnackbar();

function onActionClick() {
  // router-link уже сработает по :to; здесь — только закрытие, чтобы
  // snackbar не висел поверх перехода.
  snackbar.value = false;
}
</script>
