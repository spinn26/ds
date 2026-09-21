<template>
  <section class="feed-card">
    <header class="feed-head">
      <h2 class="feed-h">
        <CalendarDays :size="18" :stroke-width="1.8" />
        Новости и объявления
      </h2>

      <div class="feed-tabs" role="tablist">
        <button v-for="t in tabs" :key="t.key" type="button" role="tab"
          :aria-selected="t.key === active" :class="{ active: t.key === active }"
          @click="active = t.key">
          {{ t.label }}
          <span v-if="t.count">{{ t.count }}</span>
        </button>
      </div>

      <router-link class="feed-archive" to="/news">
        Архив
        <ChevronRight :size="16" :stroke-width="1.8" />
      </router-link>
    </header>

    <div v-if="!visible.length" class="feed-empty">
      <span class="feed-empty-ic"><Inbox :size="20" :stroke-width="1.8" /></span>
      В этой категории пока нет новостей
    </div>

    <template v-else>
      <!-- Главная новость: обложка + текст рядом. -->
      <router-link class="feat" :to="`/news/${visible[0].id}`">
        <span class="feat-cover">
          <NewsCover :kind="visible[0].kind" :url="visible[0].coverUrl"
            :eyebrow="visible[0].coverEyebrow" :numeral="visible[0].coverNumeral"
            :caption="visible[0].coverCaption" :alt="visible[0].title" />
        </span>
        <span class="feat-body">
          <NewsMeta :item="visible[0]" />
          <h3>{{ visible[0].title }}</h3>
          <p>{{ visible[0].excerpt }}</p>
          <span class="feat-go">
            Читать<template v-if="visible[0].readingMinutes"> · {{ visible[0].readingMinutes }} мин</template>
            <ArrowRight :size="16" :stroke-width="1.8" />
          </span>
        </span>
      </router-link>

      <!-- Остальные — строками с миниатюрой. -->
      <div v-if="visible.length > 1" class="rows">
        <router-link v-for="n in visible.slice(1)" :key="n.id" class="row" :to="`/news/${n.id}`">
          <span class="row-cover">
            <NewsCover :kind="n.kind" :url="n.coverUrl" :alt="n.title" />
          </span>
          <span class="row-body">
            <NewsMeta :item="n" />
            <h4>{{ n.title }}</h4>
          </span>
        </router-link>
      </div>
    </template>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue';
import { CalendarDays, ChevronRight, ArrowRight, Inbox } from 'lucide-vue-next';
import NewsCover from './NewsCover.vue';
import NewsMeta from './NewsMeta.vue';

/**
 * Лента новостей кабинета (per design/components/NewsCard.md): вкладки,
 * главная новость крупно, остальные строками. Вся карточка — одна ссылка на
 * страницу новости: полный текст и расчёты живут там, а не в виджете.
 */
const props = defineProps({
  items: { type: Array, default: () => [] },
});

const active = ref('all');

const visible = computed(() => (active.value === 'all'
  ? props.items
  : props.items.filter((n) => n.kind === active.value)));

const tabs = computed(() => [
  // Счётчик на «Все» — это непрочитанные, а не общее число: общее партнёр
  // и так видит списком.
  { key: 'all', label: 'Все', count: props.items.filter((n) => n.isNew).length },
  { key: 'promo', label: 'Промо', count: 0 },
  { key: 'update', label: 'Обновления', count: 0 },
]);
</script>

<style scoped>
.feed-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-card);
  padding: var(--space-5);
}

.feed-head {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  flex-wrap: wrap;
  margin-bottom: var(--space-5);
}
.feed-h {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
  flex: 1 1 auto;
  font: 600 17px/24px var(--font-sans);
  color: var(--ink);
}
.feed-h svg { color: var(--brand); }

.feed-tabs {
  display: flex;
  gap: 2px;
  padding: 3px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
}
.feed-tabs button {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border: 0;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--ink-muted);
  font: 500 13px/18px var(--font-sans);
  cursor: pointer;
}
.feed-tabs button.active { background: var(--surface); color: var(--ink); }
.feed-tabs button span {
  padding: 0 6px;
  border-radius: var(--radius-pill);
  background: var(--brand-soft);
  color: var(--brand);
  font: 600 11px/16px var(--font-sans);
}
.feed-tabs button:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }

.feed-archive {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  font: 500 13px/18px var(--font-sans);
  color: var(--brand);
  text-decoration: none;
}

.feat {
  display: grid;
  grid-template-columns: 376px minmax(0, 1fr);
  gap: var(--space-5);
  text-decoration: none;
  color: inherit;
  border-radius: var(--radius-md);
}
.feat:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
.feat-cover {
  display: block;
  aspect-ratio: 16 / 10;
  border-radius: var(--radius-lg);
  overflow: hidden;
}
.feat-body { display: flex; flex-direction: column; gap: var(--space-2); justify-content: center; }
.feat-body h3 {
  margin: 0;
  font: 700 21px/28px var(--font-sans);
  letter-spacing: -0.01em;
  color: var(--ink);
}
.feat-body p {
  margin: 0;
  font: 400 14px/22px var(--font-sans);
  color: var(--ink-muted);
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.feat-go {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: var(--space-2);
  font: 500 13px/18px var(--font-sans);
  color: var(--brand);
}
.feat:hover .feat-go { text-decoration: underline; }

.rows { margin-top: var(--space-5); border-top: 1px solid var(--border); }
.row {
  display: grid;
  grid-template-columns: 96px minmax(0, 1fr);
  gap: var(--space-4);
  padding: var(--space-4) 0;
  text-decoration: none;
  color: inherit;
}
.row + .row { border-top: 1px solid var(--border); }
.row:hover h4 { color: var(--brand); }
.row:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
.row-cover {
  display: block;
  aspect-ratio: 16 / 10;
  border-radius: var(--radius-md);
  overflow: hidden;
}
.row-body { display: flex; flex-direction: column; gap: 4px; justify-content: center; }
.row-body h4 {
  margin: 0;
  font: 600 15px/22px var(--font-sans);
  color: var(--ink);
}

.feed-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-8);
  font: 400 14px/22px var(--font-sans);
  color: var(--ink-muted);
}
.feed-empty-ic {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
}

@media (max-width: 1180px) {
  .feat { grid-template-columns: 300px minmax(0, 1fr); }
}
@media (max-width: 860px) {
  .feat { grid-template-columns: minmax(0, 1fr); }
  .feat-body h3 { font-size: 18px; line-height: 24px; }
  .feed-head { row-gap: var(--space-2); }
}
</style>
