<template>
  <section class="hero" :class="{ 'hero--solo': !promo?.active }">
    <!-- Сетка в углу — та же графика, что на обложках промо: связывает hero
         с лентой новостей. Чисто декоративная, поэтому aria-hidden. -->
    <span class="hero-mesh" aria-hidden="true"></span>

    <div class="hero-main">
      <div class="hero-eyebrow">{{ eyebrow }}</div>
      <h1 class="hero-title">{{ greeting }}, {{ name }}!</h1>
      <div class="hero-date">{{ dateLabel }}</div>
      <p v-if="focusLine" class="hero-focus">{{ focusLine }}</p>
    </div>

    <PromoPanel v-if="promo?.active" :promo="promo" class="hero-promo" />
  </section>
</template>

<script setup>
import { computed } from 'vue';
import PromoPanel from './PromoPanel.vue';

/**
 * «Фокус дня» — первый экран кабинета (per design/components/Hero.md).
 * Отвечает на вопрос «что мне сделать сегодня»: приветствие по времени,
 * дата и одна фраза по делу; справа — личный прогресс по акции.
 */
const props = defineProps({
  name: { type: String, default: '' },
  promo: { type: Object, default: null },
  now: { type: Date, default: () => new Date() },
});

const MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля',
  'августа', 'сентября', 'октября', 'ноября', 'декабря'];
const DAYS = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];

const parts = computed(() => {
  const h = props.now.getHours();
  if (h >= 5 && h < 12) return ['Доброе утро', 'Утро'];
  if (h >= 12 && h < 17) return ['Добрый день', 'День'];
  if (h >= 17 && h < 23) return ['Добрый вечер', 'Вечер'];
  return ['Доброй ночи', 'Ночь'];
});

const greeting = computed(() => parts.value[0]);
const isWeekend = computed(() => [0, 6].includes(props.now.getDay()));
const eyebrow = computed(() => `${parts.value[1]} · ${isWeekend.value ? 'выходной' : 'рабочий день'}`);
const dateLabel = computed(() => {
  const d = props.now;
  const day = DAYS[d.getDay()];
  return `${day.charAt(0).toUpperCase()}${day.slice(1)}, ${d.getDate()} ${MONTHS[d.getMonth()]}`;
});

// Фраза под датой — одна и по делу. Пока акция идёт, она про акцию: это
// единственное, что партнёр может сделать прямо сегодня и увидеть в деньгах.
const focusLine = computed(() => {
  const p = props.promo;
  if (!p?.active) return '';
  const left = Number(p.daysLeft) || 0;
  if (Number(p.current) >= Number(p.target)) {
    return 'Условие месяца выполнено — бонус уже ваш.';
  }
  if (left <= 3) return `До конца месяца ${left === 0 ? 'меньше дня' : left + ' дн.'} — успевайте закрыть сделки.`;
  return `До конца месяца ${left} дней — хорошее время вернуться к незакрытым сделкам.`;
});
</script>

<style scoped>
.hero {
  position: relative;
  overflow: hidden;
  display: grid;
  grid-template-columns: minmax(0, 1fr) 340px;
  gap: var(--space-6);
  align-items: center;
  padding: var(--space-8);
  border-radius: var(--radius-xl);
  background: var(--brand-deep);
  color: var(--on-brand-deep);
}
/* В тёмной теме brand-deep близок к фону — отделяем карточку кантом. */
:global([data-theme="dark"]) .hero { box-shadow: inset 0 0 0 1px var(--brand-deep-2); }
.hero--solo { grid-template-columns: minmax(0, 1fr); }

/* Сетка клетками по 40px: рисуем градиентом, а не svg — тянущийся viewBox
   давал крупные прямоугольники вместо ровной клетки. */
.hero-mesh {
  position: absolute;
  left: 0;
  bottom: 0;
  width: 70%;
  height: 70%;
  pointer-events: none;
  background-image:
    repeating-linear-gradient(to right, var(--brand-glow) 0 1px, transparent 1px 40px),
    repeating-linear-gradient(to bottom, var(--brand-glow) 0 1px, transparent 1px 40px);
  opacity: 0.1;
  mask-image: linear-gradient(to top right, #000, transparent 70%);
}

.hero-main { position: relative; display: flex; flex-direction: column; justify-content: center; }

.hero-eyebrow {
  font: 600 11px/14px var(--font-sans);
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--brand-glow);
}
.hero-title {
  margin: 10px 0 6px;
  font: 700 34px/40px var(--font-sans);
  letter-spacing: -0.025em;
  color: var(--on-brand-deep);
}
.hero-date {
  font: 400 14px/22px var(--font-sans);
  color: var(--on-brand-deep-muted);
}
.hero-focus {
  margin: var(--space-5) 0 0;
  max-width: 420px;
  font: 400 15px/23px var(--font-sans);
  color: var(--on-brand-deep);
}

/* < 1280px панель акции уходит под приветствие (per BRAND.md). */
@media (max-width: 1280px) {
  .hero { grid-template-columns: minmax(0, 1fr); gap: var(--space-6); }
}
@media (max-width: 860px) {
  .hero { padding: var(--space-5); }
  .hero-title { font-size: 24px; line-height: 30px; }
  .hero-focus { font-size: 15px; line-height: 24px; margin-top: var(--space-4); }
}
</style>
