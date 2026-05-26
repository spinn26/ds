<template>
  <!-- DS spec ds-missing-partner.jsx::PartnerErrorPages — 404:
       96px warning-container круг + 404 chip, компас-иконка. -->
  <div class="err-page">
    <v-card class="err-card pa-9 text-center">
      <div class="err-icon-wrap">
        <div class="err-icon err-icon--warn">
          <v-icon size="46" color="warning">mdi-compass-off</v-icon>
        </div>
        <div class="err-code err-code--warn">404</div>
      </div>
      <h2 class="ds-headline-m mt-4">Страница не найдена</h2>
      <div class="ds-body-l ds-muted mt-2 err-text">
        Адрес <code>{{ $route.fullPath }}</code> не существует или был удалён. Возможно, ссылка устарела.
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
.err-icon--warn {
  background: var(--ds-warning-container, rgba(var(--v-theme-warning), 0.15));
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
.err-code--warn {
  background: rgb(var(--v-theme-warning));
}
.err-text {
  max-width: 380px;
  margin-left: auto;
  margin-right: auto;
}
.err-text code {
  font-family: var(--ds-font-mono);
  font-size: 12px;
  padding: 2px 4px;
  background: var(--ds-overlay, rgba(0,0,0,0.04));
  border-radius: 4px;
}
</style>
