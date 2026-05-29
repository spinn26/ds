<template>
  <div class="auth-page" :class="{ 'auth-page--mobile': mobile }">
    <!-- Hero (left half desktop, hidden on mobile) -->
    <aside class="auth-hero">
      <div class="hero-waves">
        <BrandWaves :width="900" :height="900"
          bg-color="transparent" stroke-color="#ffffff"
          :rows="18" :columns="22" :amplitude="6" :frequency="1.2"
          :stroke-width="0.8" :stroke-opacity="0.35" />
      </div>

      <header class="hero-brand">
        <div class="hero-mark">DS</div>
        <div>
          <div class="hero-brand-title">DS Consulting</div>
          <div class="hero-brand-sub">Партнёрская платформа</div>
        </div>
      </header>

      <div class="hero-pitch">
        <h1 class="hero-headline">{{ heroTitle }}</h1>
        <p class="hero-lead">{{ heroLead }}</p>
      </div>

      <footer class="hero-footer">© DS Consulting · 2026 · 152-ФЗ</footer>
    </aside>

    <!-- Form area (right desktop / full mobile) -->
    <section class="auth-form-wrap">
      <div class="auth-form">
        <div v-if="mobile" class="form-mobile-brand">
          <div class="hero-mark hero-mark--inverse">DS</div>
          <div class="hero-brand-title text-on-surface">DS Consulting</div>
        </div>
        <slot />
      </div>
    </section>
  </div>
</template>

<script setup>
import { useDisplay } from 'vuetify';
import BrandWaves from './BrandWaves.vue';

defineProps({
  heroTitle: {
    type: String,
    default: 'Партнёрский кабинет для финансовых консультантов',
  },
  heroLead: {
    type: String,
    default: 'Клиенты, контракты, комиссии и обучение — в одном месте. Real-time чат с поддержкой и кураторами.',
  },
});

const { mobile } = useDisplay();
</script>

<style scoped>
.auth-page {
  min-height: 100vh;
  display: grid;
  grid-template-columns: 1fr 1fr;
  background: rgb(var(--v-theme-surface));
}
.auth-page--mobile { grid-template-columns: 1fr; }

.auth-hero {
  position: relative;
  overflow: hidden;
  padding: 48px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  color: #fff;
  background: linear-gradient(135deg, #1B5E20 0%, #2E7D32 100%);
}
.auth-page--mobile .auth-hero { display: none; }

.hero-waves { position: absolute; inset: 0; opacity: 0.45; pointer-events: none; }

.hero-brand { position: relative; display: flex; align-items: center; gap: 14px; }
.hero-mark {
  width: 44px; height: 44px;
  display: grid; place-items: center;
  background: rgba(255, 255, 255, 0.16);
  border: 1.5px solid rgba(255, 255, 255, 0.4);
  border-radius: 10px;
  font-weight: 800; font-size: 18px; letter-spacing: 0.4px;
  color: #fff; backdrop-filter: blur(4px);
}
.hero-mark--inverse {
  background: rgb(var(--v-theme-primary));
  color: #fff; border-color: transparent;
}
.hero-brand-title { font-size: 18px; font-weight: 600; line-height: 1.2; }
.hero-brand-sub { font-size: 13px; opacity: 0.82; margin-top: 2px; }

.hero-pitch { position: relative; max-width: 540px; }
.hero-headline {
  font-size: 38px; line-height: 1.12; font-weight: 700;
  letter-spacing: -0.5px; margin: 0 0 18px;
}
.hero-lead { font-size: 16px; line-height: 1.5; opacity: 0.95; margin: 0; max-width: 460px; }

.hero-footer { position: relative; font-size: 13px; opacity: 0.78; }

.auth-form-wrap {
  display: flex; align-items: center; justify-content: center;
  padding: 48px 56px;
  background: rgb(var(--v-theme-surface));
}
.auth-page--mobile .auth-form-wrap { padding: 32px 20px; }

.auth-form { width: 100%; max-width: 380px; }

.form-mobile-brand {
  display: flex; align-items: center; gap: 12px; margin-bottom: 24px;
}
</style>
