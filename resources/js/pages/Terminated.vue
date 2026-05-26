<template>
  <!-- DS spec ds-missing-partner.jsx::PartnerTerminated:
       центрированная карточка max-width 560, 88px lock-кружок в
       error-container фоне, два списка "Что это значит" / "Для
       восстановления", две кнопки внизу (filled + outlined). -->
  <div class="terminated-page">
    <v-card class="terminated-card pa-9 text-center">
      <div class="t-icon">
        <v-icon size="42" color="error">mdi-lock</v-icon>
      </div>

      <h2 class="ds-headline-m text-error mt-4">Доступ ограничен</h2>
      <div class="ds-body-l ds-muted mt-2">
        Ваш аккаунт находится в статусе «Терминирован». Доступ к разделам платформы временно закрыт.
      </div>

      <v-divider class="my-6" />

      <div class="t-lists d-flex flex-column ga-5 text-left">
        <div>
          <div class="ds-title-s mb-2">Что это значит:</div>
          <ul class="t-bullets">
            <li>Условия активационного периода не выполнены</li>
            <li>Накопленные баллы обнулены, клиенты и контракты переданы вышестоящему партнёру</li>
            <li>Повторная регистрация возможна не более 3 раз</li>
          </ul>
        </div>
        <div>
          <div class="ds-title-s mb-2">Для восстановления доступа:</div>
          <ul class="t-bullets">
            <li>Свяжитесь с техподдержкой через раздел «Обратная связь»</li>
            <li>Обсудите план активации с наставником</li>
          </ul>
        </div>
      </div>

      <div class="d-flex justify-center ga-2 mt-6">
        <v-btn to="/communication" color="primary" variant="flat" size="large" prepend-icon="mdi-chat">
          Обратная связь
        </v-btn>
        <v-btn to="/profile" variant="outlined" size="large" prepend-icon="mdi-account">
          Профиль
        </v-btn>
      </div>
    </v-card>
  </div>
</template>

<style scoped>
.terminated-page {
  display: grid;
  place-items: center;
  min-height: 70vh;
  padding: 28px;
}
.terminated-card {
  max-width: 560px;
  width: 100%;
  border-radius: var(--ds-radius-lg, 12px) !important;
}
.t-icon {
  width: 88px;
  height: 88px;
  border-radius: 50%;
  background: var(--ds-error-container, rgb(var(--v-theme-error-container), 0.15));
  display: grid;
  place-items: center;
  margin: 0 auto;
}
.t-bullets {
  margin: 0;
  padding-left: 20px;
  color: var(--ds-on-surface-variant, rgba(var(--v-theme-on-surface), 0.65));
  font-size: 14px;
  line-height: 1.65;
}
.t-bullets li {
  margin-bottom: 2px;
}
</style>
