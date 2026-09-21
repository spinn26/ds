<template>
  <section class="card">
    <h2 class="card-h">
      <Zap :size="18" :stroke-width="1.8" />
      Быстрые действия
    </h2>

    <div class="grid">
      <router-link v-for="a in actions" :key="a.to" class="tile" :to="a.to">
        <span class="ic"><component :is="a.icon" :size="18" :stroke-width="1.8" /></span>
        <span class="label">{{ a.label }}</span>
      </router-link>
    </div>
  </section>
</template>

<script setup>
import { Zap, Landmark, Calculator, Users, User } from 'lucide-vue-next';

/**
 * Сетка 2×2 на частые разделы (per design/components/QuickActions.md).
 * Порядок — по частоте обращения партнёра; больше четырёх плиток не кладём.
 */
const actions = [
  { to: '/finance/report', label: 'Отчёт начислений', icon: Landmark },
  { to: '/finance/calculator', label: 'Калькулятор', icon: Calculator },
  { to: '/clients', label: 'Мои клиенты', icon: Users },
  { to: '/profile', label: 'Профиль', icon: User },
];
</script>

<style scoped>
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-card);
  padding: var(--space-5);
}
.card-h {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0 0 var(--space-4);
  font: 600 17px/24px var(--font-sans);
  color: var(--ink);
}
.card-h svg { color: var(--brand); }

.grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--space-3);
}

.tile {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-3);
  border-radius: var(--radius-md);
  background: var(--surface-2);
  color: var(--ink);
  text-decoration: none;
}
.tile:hover { background: var(--brand-soft); }
.tile:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }

.ic {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  flex: 0 0 auto;
  border-radius: var(--radius-sm);
  background: var(--surface);
  color: var(--brand);
}
.label { font: 500 13px/18px var(--font-sans); }
</style>
