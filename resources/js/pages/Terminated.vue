<template>
  <v-container class="fill-height" fluid>
    <v-row justify="center" align="center">
      <v-col cols="12" sm="8" md="6">
        <v-card class="pa-8 text-center" elevation="8" rounded="xl">
          <v-icon size="80" color="error" class="mb-4">mdi-account-lock</v-icon>

          <h4 class="text-h4 font-weight-bold text-error mb-2">Доступ ограничен</h4>

          <p class="text-body-1 text-medium-emphasis mb-6">
            Ваш аккаунт находится в статусе <strong>«Терминирован»</strong>.<br>
            Доступ к разделам платформы временно закрыт.
          </p>

          <v-divider class="mb-4" />

          <div class="text-left mb-4">
            <div class="text-subtitle-2 font-weight-bold mb-2">Что это значит:</div>
            <ul class="text-body-2 text-medium-emphasis">
              <li>Условия активации или годового периода не были выполнены</li>
              <li>Баллы обнулены, клиенты и контракты переданы вышестоящему партнёру</li>
              <li>Вы можете пройти повторную регистрацию (не более 3 раз)</li>
            </ul>
          </div>

          <div class="text-left mb-6">
            <div class="text-subtitle-2 font-weight-bold mb-2">Для восстановления доступа:</div>
            <ul class="text-body-2 text-medium-emphasis">
              <li>Свяжитесь с техподдержкой через раздел Коммуникация</li>
              <li>Или обратитесь к вашему наставнику</li>
            </ul>
          </div>

          <v-btn to="/communication" color="primary" size="large" prepend-icon="mdi-chat" class="mr-2">
            Обратная связь
          </v-btn>
          <v-btn to="/profile" variant="outlined" size="large" prepend-icon="mdi-account">
            Профиль
          </v-btn>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>
