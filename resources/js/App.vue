<template>
  <v-app>
    <router-view />
    <!-- Global snackbar for errors/success notifications -->
    <v-snackbar v-model="snackbar" :color="snackbarColor" :timeout="snackbarTimeout"
      location="top right" rounded="lg" elevation="8">
      <div class="d-flex align-center ga-2">
        <v-icon>{{ snackbarColor === 'error' ? 'mdi-alert-circle' : snackbarColor === 'success' ? 'mdi-check-circle' : 'mdi-information' }}</v-icon>
        {{ snackbarText }}
      </div>
      <template #actions>
        <v-btn variant="text" size="small" @click="snackbar = false">OK</v-btn>
      </template>
    </v-snackbar>
  </v-app>
</template>

<script setup>
import { useSnackbar } from './composables/useSnackbar';
const { snackbar, snackbarText, snackbarColor, snackbarTimeout } = useSnackbar();
</script>
