<template>
  <!-- DS spec ds-missing-partner.jsx::PartnerErrorPages — 403:
       96px error-container круг + status-code chip (403) сверху-справа,
       headline-m, body-l muted, primary-кнопка на главную. -->
  <div class="err-page">
    <v-card class="err-card pa-9 text-center">
      <div class="err-icon-wrap">
        <div class="err-icon err-icon--error">
          <v-icon size="46" color="error">mdi-cancel</v-icon>
        </div>
        <div class="err-code err-code--error">403</div>
      </div>
      <h2 class="ds-headline-m mt-4">Доступ запрещён</h2>
      <div class="ds-body-l ds-muted mt-2 err-text">
        У вашей роли нет прав на этот раздел. Если считаете это ошибкой — обратитесь к администратору.
      </div>
      <v-btn to="/" color="primary" variant="flat" size="large" class="mt-5" prepend-icon="mdi-home">
        На главную
      </v-btn>
    </v-card>
  </div>
</template>

<style scoped>
.err-page {
  display: grid;
  place-items: center;
  min-height: 70vh;
  padding: 28px;
}
.err-card {
  max-width: 480px;
  width: 100%;
  border-radius: var(--ds-radius-lg, 12px) !important;
}
.err-icon-wrap {
  position: relative;
  display: inline-grid;
  place-items: center;
}
.err-icon {
  width: 96px;
  height: 96px;
  border-radius: 50%;
  display: grid;
  place-items: center;
}
.err-icon--error {
  background: var(--ds-error-container, rgba(var(--v-theme-error), 0.15));
}
.err-code {
  position: absolute;
  top: -10px;
  right: -22px;
  padding: 2px 10px;
  border-radius: 10px;
  font-family: var(--ds-font-mono);
  font-size: 11px;
  font-weight: 700;
  color: #fff;
  font-variant-numeric: tabular-nums;
}
.err-code--error {
  background: rgb(var(--v-theme-error));
}
.err-text {
  max-width: 360px;
  margin-left: auto;
  margin-right: auto;
}
</style>
