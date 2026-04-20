<template>
  <div class="d-flex align-center justify-center" style="min-height: 60vh">
    <v-card class="pa-4 text-center" variant="tonal" max-width="440">
      <v-icon size="56" color="warning" class="mb-3">mdi-compass-off</v-icon>
      <div class="text-h5 font-weight-bold mb-2">Страница не найдена</div>
      <div class="text-body-2 text-medium-emphasis mb-4">
        Адрес «{{ $route.fullPath }}» не существует или был удалён.
      </div>
      <v-btn color="primary" size="large" prepend-icon="mdi-home" to="/">На главную</v-btn>
    </v-card>
  </div>
</template>
