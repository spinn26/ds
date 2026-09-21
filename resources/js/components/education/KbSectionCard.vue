<template>
  <router-link
    class="card"
    :class="[`card--${tintClass}`, { 'card--empty': isEmpty }]"
    :to="`/education/kb/sections/${section.id}`"
  >
    <span class="card-ic">
      <component :is="icon" :size="22" :stroke-width="1.8" aria-hidden="true" />
    </span>
    <span class="card-b">
      <span v-if="path" class="card-path">{{ path }}</span>
      <span class="card-t">{{ section.title }}</span>
      <span v-if="section.description" class="card-d">{{ section.description }}</span>
      <span class="card-m">{{ meta }}</span>
    </span>
    <ChevronRight class="card-go" :size="18" :stroke-width="1.8" aria-hidden="true" />
  </router-link>
</template>

<script setup>
import { computed } from 'vue';
import { ChevronRight } from 'lucide-vue-next';
import { kbIcon, countLabel } from '../../utils/kb';

/**
 * Карточка раздела базы знаний. Одна на страницу «База знаний» и на
 * страницу раздела: подразделы выглядят так же, как разделы верхнего
 * уровня, чтобы вложенность не меняла правила чтения.
 */
const props = defineProps({
  /** Раздел из /education/kb или /education/kb/sections/{id}. */
  section: { type: Object, required: true },
  /** Порядковый номер в сетке — от него только оттенок плитки иконки. */
  tint: { type: Number, default: 0 },
  /** Путь до родителя (показываем в результатах поиска). */
  path: { type: String, default: '' },
});

const TINTS = ['brand', 'info', 'accent', 'plain'];

const icon = computed(() => kbIcon(props.section.icon));
const tintClass = computed(() => TINTS[props.tint % TINTS.length]);

/** Дерево даёт children, страница раздела — childCount: считаем оба. */
const childCount = computed(() => (Array.isArray(props.section.children)
  ? props.section.children.length
  : Number(props.section.childCount) || 0));

const articleCount = computed(() => Number(props.section.articleCount) || 0);
const isEmpty = computed(() => !articleCount.value && !childCount.value);

const meta = computed(() => {
  const parts = [];
  if (articleCount.value) {
    parts.push(countLabel(articleCount.value, 'материал', 'материала', 'материалов'));
  }
  if (childCount.value) {
    parts.push(countLabel(childCount.value, 'подраздел', 'подраздела', 'подразделов'));
  }
  return parts.join(' · ') || 'Пока пусто';
});
</script>

<style scoped>
.card {
  display: grid;
  grid-template-columns: 48px minmax(0, 1fr) 18px;
  gap: var(--space-4);
  align-items: start;
  padding: var(--space-5);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
  box-shadow: var(--shadow-card);
  color: var(--ink);
  text-decoration: none;
  transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
}
.card:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow-hover);
  border-color: var(--border-strong);
}
.card:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }

.card-ic {
  display: grid;
  place-items: center;
  width: 48px;
  height: 48px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
  color: var(--ink-muted);
}
.card--brand .card-ic { background: var(--brand-soft); color: var(--brand); }
.card--info .card-ic { background: var(--info-soft); color: var(--info); }
.card--accent .card-ic { background: var(--accent-soft); color: var(--accent); }

.card-b { display: flex; flex-direction: column; min-width: 0; }
.card-path {
  margin-bottom: 2px;
  font: 400 12px/16px var(--font-sans);
  color: var(--ink-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.card-t {
  margin-top: 2px;
  font: 600 16px/22px var(--font-sans);
}
.card-d {
  margin-top: 6px;
  font: 400 13px/19px var(--font-sans);
  color: var(--ink-muted);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.card-m {
  margin-top: 10px;
  font: 500 12px/16px var(--font-sans);
  color: var(--ink-muted);
}

.card-go {
  align-self: center;
  color: var(--ink-muted);
  transition: transform 0.18s ease, color 0.18s ease;
}
.card:hover .card-go { transform: translateX(3px); color: var(--brand); }

/* Пустой раздел: пунктир вместо карточки — видно, что заходить пока некуда. */
.card--empty {
  background: transparent;
  border-style: dashed;
  box-shadow: none;
}
.card--empty .card-ic { background: var(--surface-2); color: var(--ink-muted); }
.card--empty .card-t { color: var(--ink-muted); }
.card--empty:hover { box-shadow: none; }

@media (max-width: 860px) {
  .card { padding: var(--space-4); gap: var(--space-3); grid-template-columns: 40px minmax(0, 1fr) 18px; }
  .card-ic { width: 40px; height: 40px; }
}
</style>
