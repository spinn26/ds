<template>
  <v-snackbar v-model="snackbar" :color="snackbarColor" :timeout="snackbarTimeout"
    location="top right">
    {{ snackbarText }}
    <template #actions>
      <v-btn icon="mdi-close" size="small" variant="text" @click="snackbar = false" />
    </template>
  </v-snackbar>
</template>

<script setup>
import { useSnackbar } from '../composables/useSnackbar';

const { snackbar, snackbarText, snackbarColor, snackbarTimeout } = useSnackbar();
</script>
