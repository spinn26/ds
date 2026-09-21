<template>
  <div class="list">
    <header class="list-head">
      <h1>Новости и объявления</h1>
      <div class="tabs" role="tablist">
        <button v-for="t in TABS" :key="t.key" type="button" role="tab"
          :aria-selected="t.key === kind" :class="{ active: t.key === kind }"
          @click="select(t.key)">
          {{ t.label }}
        </button>
      </div>
    </header>

    <div v-if="loading" class="skeleton"></div>

    <div v-else-if="!items.length" class="empty">
      <span class="empty-ic"><Inbox :size="20" :stroke-width="1.8" /></span>
      В этой категории пока нет новостей
    </div>

    <div v-else class="grid">
      <router-link v-for="n in items" :key="n.id" class="item" :to="`/news/${n.id}`">
        <span class="item-cover">
          <NewsCover :kind="n.kind" :url="n.coverUrl" :eyebrow="n.coverEyebrow"
            :numeral="n.coverNumeral" :caption="n.coverCaption" :alt="n.title" />
        </span>
        <span class="item-body">
          <NewsMeta :item="n" />
          <span class="item-title">{{ n.title }}</span>
          <span class="item-excerpt">{{ n.excerpt }}</span>
        </span>
      </router-link>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Inbox } from 'lucide-vue-next';
import api from '../../api';
import NewsCover from '../../components/workspace/NewsCover.vue';
import NewsMeta from '../../components/workspace/NewsMeta.vue';

/** Архив новостей — та же лента, что на главной, но целиком и с фильтром. */
const TABS = [
  { key: '', label: 'Все' },
  { key: 'promo', label: 'Промо' },
  { key: 'update', label: 'Обновления' },
];

const items = ref([]);
const loading = ref(true);
const kind = ref('');

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/news', { params: { kind: kind.value || undefined, limit: 50 } });
    items.value = data.data || [];
  } catch {
    items.value = [];
  }
  loading.value = false;
}

function select(key) {
  kind.value = key;
  load();
}

onMounted(load);
</script>

<style scoped>
.list { font-family: var(--font-sans); color: var(--ink); max-width: 1100px; }

.list-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
  margin-bottom: var(--space-6);
}
.list-head h1 { margin: 0; font: 700 28px/34px var(--font-sans); letter-spacing: -0.015em; }

.tabs { display: flex; gap: 2px; padding: 3px; border-radius: var(--radius-md); background: var(--surface-2); }
.tabs button {
  padding: 6px 12px;
  border: 0;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--ink-muted);
  font: 500 13px/18px var(--font-sans);
  cursor: pointer;
}
.tabs button.active { background: var(--surface); color: var(--ink); }
.tabs button:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }

.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: var(--space-5); }

.item {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  padding: var(--space-4);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
  box-shadow: var(--shadow-card);
  text-decoration: none;
  color: inherit;
}
.item:hover { box-shadow: var(--shadow-hover); }
.item:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
.item-cover { display: block; aspect-ratio: 16 / 10; border-radius: var(--radius-md); overflow: hidden; }
.item-body { display: flex; flex-direction: column; gap: var(--space-2); }
.item-title { font: 600 15px/22px var(--font-sans); }
.item-excerpt {
  font: 400 13px/20px var(--font-sans);
  color: var(--ink-muted);
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-8);
  color: var(--ink-muted);
  font: 400 14px/22px var(--font-sans);
}
.empty-ic {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
}
.skeleton { height: 240px; border-radius: var(--radius-lg); background: var(--surface-2); }
</style>
