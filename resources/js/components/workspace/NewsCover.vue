<template>
  <img v-if="url" :src="url" :alt="alt || ''" class="cover-img" />
  <svg v-else class="cover-art" viewBox="0 0 400 250" preserveAspectRatio="xMidYMid slice" role="img">
    <title>{{ alt || title }}</title>
    <rect class="art-bg" width="400" height="250" />
    <g class="art-grid">
      <path v-for="x in verticals" :key="`v${x}`" :d="`M${x} 0V250`" />
      <path v-for="y in horizontals" :key="`h${y}`" :d="`M0 ${y}H400`" />
    </g>

    <!-- Промо: растущие столбцы и крупная цифра. -->
    <template v-if="kind === 'promo'">
      <rect v-for="(b, i) in bars" :key="`b${i}`"
        :class="i === bars.length - 1 ? 'art-accent' : 'art-brand'"
        :x="238 + i * 30" :y="192 - b" width="20" :height="b" rx="6" />
      <text class="art-sub" x="28" y="92" font-size="12">{{ eyebrow }}</text>
      <text class="art-num" x="24" y="152" font-size="66">{{ numeral }}</text>
      <text class="art-sub" x="28" y="182" font-size="13">{{ caption }}</text>
    </template>

    <!-- Обновление: лист документа с отметкой. -->
    <template v-else>
      <rect class="art-sheet" x="118" y="58" width="130" height="150" rx="14" />
      <rect class="art-line-1" x="140" y="86" width="72" height="8" rx="4" />
      <rect class="art-line-2" x="140" y="108" width="86" height="6" rx="3" />
      <rect class="art-line-2" x="140" y="124" width="64" height="6" rx="3" />
      <circle class="art-brand" cx="232" cy="186" r="22" />
      <path class="art-check" d="M222 186l7 7 13-14" />
    </template>
  </svg>
</template>

<script setup>
import { computed } from 'vue';

/**
 * Обложка новости. Загруженная картинка главнее; если её нет — рисуем
 * обложку из токенов (per design/BRAND.md: «сгенерированная DS.art(kind),
 * перекрашивается с темой»). Стоковых фото людей в системе нет.
 */
const props = defineProps({
  kind: { type: String, default: 'update' },   // promo | update
  url: { type: String, default: null },
  alt: { type: String, default: '' },
  // Надписи промо-обложки приходят из новости, а не зашиты в код.
  eyebrow: { type: String, default: '' },
  numeral: { type: String, default: '' },
  caption: { type: String, default: '' },
});

const title = computed(() => (props.kind === 'promo' ? 'Промо' : 'Обновление'));
const bars = [40, 58, 78, 100, 126];
const verticals = Array.from({ length: 11 }, (_, i) => i * 40);
const horizontals = Array.from({ length: 7 }, (_, i) => 5 + i * 40);
</script>

<style scoped>
.cover-img,
.cover-art {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.art-bg { fill: var(--brand-deep); }
.art-grid path { stroke: var(--brand-glow); stroke-opacity: 0.12; stroke-width: 1; }
.art-brand { fill: var(--brand-glow); }
.art-accent { fill: var(--accent-glow); }
.art-sub {
  fill: var(--on-brand-deep-muted);
  font-family: var(--font-sans);
  font-weight: 600;
  letter-spacing: 0.08em;
}
.art-num {
  fill: var(--on-brand-deep);
  font-family: var(--font-sans);
  font-weight: 800;
  letter-spacing: -0.04em;
}
.art-sheet { fill: var(--on-brand-deep); fill-opacity: 0.1; }
.art-line-1 { fill: var(--on-brand-deep); fill-opacity: 0.55; }
.art-line-2 { fill: var(--on-brand-deep); fill-opacity: 0.3; }
.art-check {
  fill: none;
  stroke: var(--on-brand-glow);
  stroke-width: 3;
  stroke-linecap: round;
  stroke-linejoin: round;
}
</style>
