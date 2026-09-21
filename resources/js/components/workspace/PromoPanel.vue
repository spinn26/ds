<template>
  <div class="promo" :class="{ 'promo--flat': flat }">
    <div class="promo-head">
      <span class="promo-tag">{{ promo.title }}</span>
      <span class="promo-month">{{ promo.monthLabel }}</span>
    </div>

    <div class="promo-value">
      <b>{{ fmt(promo.current) }}</b>
      <span>/ {{ fmt(promo.target) }} {{ promo.unit }}</span>
    </div>

    <div class="promo-bar" role="progressbar" :aria-valuenow="pct" aria-valuemin="0" aria-valuemax="100">
      <span :style="{ width: pct + '%' }"></span>
    </div>

    <p class="promo-note">
      <template v-if="done">
        Условие выполнено — {{ promo.bonusLabel }} за {{ promo.monthLabel.toLowerCase() }}.
      </template>
      <template v-else>
        Ещё <b>{{ fmt(rest) }}</b> {{ promo.unit }} до {{ promo.bonusLabel }}
        <template v-if="promo.daysLeft != null"> · {{ promo.daysLeft }} {{ daysWord }} до конца месяца</template>
      </template>
    </p>

    <div v-if="promo.months?.length" class="promo-months">
      <span v-for="m in promo.months" :key="m.label" :class="['promo-month-chip', m.state]">
        {{ m.short }}
      </span>
    </div>

    <router-link v-if="promo.newsId && withLink" class="promo-cta" :to="`/news/${promo.newsId}`">
      Условия акции
      <ArrowRight :size="16" :stroke-width="1.8" />
    </router-link>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { ArrowRight } from 'lucide-vue-next';

/**
 * Личный прогресс по действующей акции (per design/components/Hero.md).
 * Панель одна и та же в hero и в боковой колонке страницы новости, поэтому
 * все параметры — цель, бонус, месяцы — приходят из данных акции, а не
 * зашиты здесь: у следующей акции они будут другими.
 */
const props = defineProps({
  promo: { type: Object, required: true },
  withLink: { type: Boolean, default: true },
  flat: { type: Boolean, default: false },
});

const pct = computed(() => {
  const target = Number(props.promo.target) || 0;
  if (!target) return 0;
  return Math.max(0, Math.min(100, (Number(props.promo.current) || 0) / target * 100));
});
const rest = computed(() => Math.max(0, (Number(props.promo.target) || 0) - (Number(props.promo.current) || 0)));
const done = computed(() => rest.value === 0);

// «9 дней», «2 дня», «1 день» — иначе в hero каждый день виден корявый текст.
const daysWord = computed(() => {
  const n = Number(props.promo.daysLeft) || 0;
  const m = n % 10;
  const h = n % 100;
  if (m === 1 && h !== 11) return 'день';
  if (m >= 2 && m <= 4 && (h < 10 || h >= 20)) return 'дня';
  return 'дней';
});

function fmt(v) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(Number(v) || 0);
}
</script>

<style scoped>
.promo {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  padding: var(--space-5);
  border-radius: var(--radius-lg);
  background: var(--brand-deep-2);
  color: var(--on-brand-deep);
}
/* На странице новости панель лежит на обычной карточке. */
.promo--flat { background: var(--brand-deep); }

.promo-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
}
.promo-tag {
  padding: 4px 10px;
  border-radius: var(--radius-pill);
  background: var(--accent-glow);
  color: var(--on-brand-glow);
  font: 600 12px/16px var(--font-sans);
}
.promo-month {
  font: 400 12px/16px var(--font-sans);
  color: var(--on-brand-deep-muted);
}

.promo-value {
  display: flex;
  align-items: baseline;
  gap: var(--space-2);
}
.promo-value b {
  font: 800 36px/40px var(--font-sans);
  letter-spacing: -0.03em;
  font-variant-numeric: tabular-nums;
}
.promo-value span {
  font: 400 14px/22px var(--font-sans);
  color: var(--on-brand-deep-muted);
}

.promo-bar {
  height: 8px;
  border-radius: var(--radius-pill);
  background: var(--brand-deep);
  overflow: hidden;
}
.promo-bar span {
  display: block;
  height: 100%;
  min-width: 6px;
  border-radius: var(--radius-pill);
  background: var(--brand-glow);
}

.promo-note {
  margin: 0;
  font: 400 13px/20px var(--font-sans);
  color: var(--on-brand-deep-muted);
}
.promo-note b { color: var(--on-brand-deep); font-weight: 600; }

.promo-months {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 6px;
}
.promo-month-chip {
  height: 28px;
  display: grid;
  place-items: center;
  border-radius: var(--radius-sm);
  color: var(--on-brand-deep-muted);
  font: 600 12px/1 var(--font-sans);
  /* Тонкая рамка вместо заливки: залитые плитки спорят с текущим месяцем. */
  box-shadow: inset 0 0 0 1px rgba(234, 251, 239, 0.18);
}
.promo-month-chip.now {
  background: var(--brand-glow);
  color: var(--on-brand-glow);
  box-shadow: none;
}
.promo-month-chip.past { opacity: 0.5; }

/* Кнопка светлая на тёмном: ярко-зелёная заливка рядом с зелёным прогрессом
   спорит с ним за внимание (в прототипе — on-brand-deep). */
.promo-cta {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  height: 40px;
  border-radius: var(--radius-md);
  background: var(--on-brand-deep);
  color: var(--brand-deep);
  font: 600 13px/18px var(--font-sans);
  text-decoration: none;
}
.promo-cta:hover { background: #fff; }
.promo-cta:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
</style>
