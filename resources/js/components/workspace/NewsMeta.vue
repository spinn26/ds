<template>
  <span class="meta">
    <span :class="['tag', item.kind === 'promo' ? 'promo' : 'update']">
      <span class="dot"></span>{{ item.tag || (item.kind === 'promo' ? 'Промо' : 'Обновление') }}
    </span>
    <span class="date">{{ dateLabel }}</span>
    <span v-if="item.isNew" class="fresh"><span class="dot"></span>Новое</span>
  </span>
</template>

<script setup>
import { computed } from 'vue';

/**
 * Строка «тег · дата · Новое» над заголовком новости (per design/components/Tag.md).
 * Точка дублирует цвет, но смысл несёт слово: цветом одним смысл не передаём.
 */
const props = defineProps({
  item: { type: Object, required: true },
});

const MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля',
  'августа', 'сентября', 'октября', 'ноября', 'декабря'];

// В карточках дата словами («16 сентября 2026») — per BRAND.md.
const dateLabel = computed(() => {
  const raw = props.item.publishedAt || props.item.createdAt;
  if (!raw) return '';
  const d = new Date(raw);
  if (Number.isNaN(d.getTime())) return '';
  return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
});
</script>

<style scoped>
.meta {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  flex-wrap: wrap;
}
.tag {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 10px;
  border-radius: var(--radius-pill);
  font: 500 12px/16px var(--font-sans);
}
.tag.promo { background: var(--accent-soft); color: var(--accent); }
.tag.update { background: var(--info-soft); color: var(--info); }
.dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
}
.date { font: 400 12px/16px var(--font-sans); color: var(--ink-muted); }
.fresh {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font: 500 12px/16px var(--font-sans);
  color: var(--brand);
}
</style>
