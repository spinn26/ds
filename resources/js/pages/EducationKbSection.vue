<template>
  <div class="kb">
    <nav class="crumbs" aria-label="Хлебные крошки">
      <router-link to="/education">Обучение</router-link>
      <ChevronRight :size="14" :stroke-width="1.8" aria-hidden="true" />
      <router-link to="/education/kb">База знаний</router-link>
      <template v-for="(b, i) in breadcrumbs" :key="b.id">
        <ChevronRight :size="14" :stroke-width="1.8" aria-hidden="true" />
        <router-link v-if="i < breadcrumbs.length - 1" :to="`/education/kb/sections/${b.id}`">
          {{ b.title }}
        </router-link>
        <span v-else aria-current="page">{{ b.title }}</span>
      </template>
    </nav>

    <div v-if="loading" class="grid" aria-hidden="true">
      <div v-for="i in 4" :key="i" class="skeleton"></div>
    </div>

    <template v-else>
      <header class="kb-head">
        <span class="kb-head-ic">
          <component :is="icon" :size="24" :stroke-width="1.8" aria-hidden="true" />
        </span>
        <div class="kb-head-b">
          <h1>{{ section?.title || 'Раздел базы знаний' }}</h1>
          <p v-if="section?.description">{{ section.description }}</p>
          <p class="kb-head-m">{{ meta }}</p>
        </div>
      </header>

      <div v-if="!subsections.length && !articles.length" class="state">
        <span class="state-ic"><FolderOpen :size="20" :stroke-width="1.8" /></span>
        В этом разделе пока нет материалов — скоро здесь появятся регламенты,
        инструкции и записи
      </div>

      <section v-if="subsections.length" class="block">
        <h2 class="block-h">
          Подразделы
          <span>{{ subsections.length }}</span>
        </h2>
        <div class="grid">
          <KbSectionCard
            v-for="(s, i) in subsections"
            :key="s.id"
            :section="s"
            :tint="i"
          />
        </div>
      </section>

      <section v-if="articles.length" class="block">
        <h2 class="block-h">
          Материалы
          <span>{{ articles.length }}</span>
        </h2>
        <div class="rows">
          <router-link
            v-for="a in articles"
            :key="a.id"
            class="row"
            :to="`/education/kb/articles/${a.id}`"
          >
            <span class="row-ic"><FileText :size="18" :stroke-width="1.8" aria-hidden="true" /></span>
            <span class="row-b">
              <span class="row-t">{{ a.title }}</span>
              <span v-if="a.description" class="row-d">{{ a.description }}</span>
              <span v-if="a.tags?.length" class="row-tags">
                <span v-for="t in a.tags" :key="t" class="tag">#{{ t }}</span>
              </span>
            </span>
            <ChevronRight class="row-go" :size="18" :stroke-width="1.8" aria-hidden="true" />
          </router-link>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ChevronRight, FileText, FolderOpen } from 'lucide-vue-next';
import api from '../api';
import KbSectionCard from '../components/education/KbSectionCard.vue';
import { kbIcon, countLabel } from '../utils/kb';

const route = useRoute();
const loading = ref(true);
const section = ref(null);
const subsections = ref([]);
const articles = ref([]);
const breadcrumbs = ref([]);

const icon = computed(() => kbIcon(section.value?.icon));

/** Сколько в разделе своих материалов и подразделов. */
const meta = computed(() => {
  const parts = [];
  if (articles.value.length) {
    parts.push(countLabel(articles.value.length, 'материал', 'материала', 'материалов'));
  }
  if (subsections.value.length) {
    parts.push(countLabel(subsections.value.length, 'подраздел', 'подраздела', 'подразделов'));
  }
  return parts.join(' · ') || 'Пока пусто';
});

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/education/kb/sections/${route.params.id}`);
    section.value = data.section || null;
    subsections.value = data.subsections || [];
    articles.value = data.articles || [];
    breadcrumbs.value = data.breadcrumbs || [];
  } catch {
    section.value = null;
    subsections.value = [];
    articles.value = [];
    breadcrumbs.value = [];
  }
  loading.value = false;
}
onMounted(load);
watch(() => route.params.id, (id) => { if (id) load(); });
</script>

<style scoped>
.kb {
  font-family: var(--font-sans);
  color: var(--ink);
}

/* ---------- крошки и шапка ---------- */
.crumbs {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px;
  margin-bottom: var(--space-4);
  font: 400 13px/18px var(--font-sans);
  color: var(--ink-muted);
}
.crumbs a { color: var(--ink-muted); text-decoration: none; }
.crumbs a:hover { color: var(--brand); }
.crumbs a:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; border-radius: var(--radius-sm); }

.kb-head {
  display: flex;
  align-items: flex-start;
  gap: var(--space-4);
  margin-bottom: var(--space-6);
}
.kb-head-ic {
  display: grid;
  place-items: center;
  flex: 0 0 auto;
  width: 52px;
  height: 52px;
  border-radius: var(--radius-md);
  background: var(--brand-soft);
  color: var(--brand);
}
.kb-head-b { min-width: 0; }
.kb-head h1 {
  margin: 0;
  font: 700 28px/34px var(--font-sans);
  letter-spacing: -0.015em;
}
.kb-head p {
  margin: 6px 0 0;
  font: 400 14px/22px var(--font-sans);
  color: var(--ink-muted);
}
.kb-head-m { font-weight: 500; font-size: 13px !important; }

/* ---------- блоки ---------- */
.block + .block { margin-top: var(--space-6); }
.block-h {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0 0 var(--space-3);
  font: 600 17px/24px var(--font-sans);
  color: var(--ink);
}
.block-h span {
  padding: 0 8px;
  border-radius: var(--radius-pill);
  background: var(--brand-soft);
  color: var(--brand);
  font: 600 12px/20px var(--font-sans);
  font-variant-numeric: tabular-nums;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: var(--space-4);
}
.skeleton {
  height: 124px;
  border-radius: var(--radius-lg);
  background: var(--surface-2);
}

/* ---------- список материалов ---------- */
.rows {
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
  box-shadow: var(--shadow-card);
  overflow: hidden;
}
.row {
  display: grid;
  grid-template-columns: 32px minmax(0, 1fr) 18px;
  gap: var(--space-3);
  align-items: start;
  padding: var(--space-4);
  color: inherit;
  text-decoration: none;
}
.row + .row { border-top: 1px solid var(--border); }
.row:hover { background: var(--surface-2); }
.row:hover .row-t { color: var(--brand); }
.row:focus-visible { outline: 2px solid var(--focus); outline-offset: -2px; }
.row-ic {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: var(--radius-sm);
  background: var(--surface-2);
  color: var(--ink-muted);
}
.row-b { display: flex; flex-direction: column; min-width: 0; }
.row-t { font: 500 14px/20px var(--font-sans); }
.row-d {
  margin-top: 2px;
  font: 400 13px/19px var(--font-sans);
  color: var(--ink-muted);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.row-tags { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px; }
.tag {
  padding: 1px 8px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  color: var(--ink-muted);
  font: 500 11px/18px var(--font-sans);
}
.row-go { align-self: center; color: var(--ink-muted); }
.row:hover .row-go { color: var(--brand); }

/* ---------- пустое состояние ---------- */
.state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-8);
  text-align: center;
  font: 400 14px/22px var(--font-sans);
  color: var(--ink-muted);
}
.state-ic {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
}

@media (max-width: 1320px) {
  .grid { grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }
}
@media (max-width: 1180px) {
  .grid { grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }
}
@media (max-width: 860px) {
  .grid { grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }
  .kb-head { gap: var(--space-3); }
  .kb-head-ic { width: 44px; height: 44px; }
  .kb-head h1 { font: 700 22px/28px var(--font-sans); }
  .row { padding: var(--space-3); }
}
/* Узкий экран: карточка в одну колонку, иначе заголовки рвутся. */
@media (max-width: 640px) {
  .grid { grid-template-columns: minmax(0, 1fr); }
}
</style>
