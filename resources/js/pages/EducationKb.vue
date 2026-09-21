<template>
  <div class="kb">
    <header class="kb-head">
      <nav class="crumbs" aria-label="Хлебные крошки">
        <router-link to="/education">Обучение</router-link>
        <ChevronRight :size="14" :stroke-width="1.8" aria-hidden="true" />
        <span aria-current="page">База знаний</span>
      </nav>
      <h1>База знаний</h1>
      <p v-if="!loading">
        {{ countLabel(totalArticles, 'материал', 'материала', 'материалов') }}
        в {{ countLabel(sections.length, 'разделе', 'разделах', 'разделах') }}
      </p>
    </header>

    <!-- Поиск: разделы фильтруются на месте, материалы и курсы приходят
         с /education/search (тот же эндпоинт, что был раньше). -->
    <label class="search" :class="{ 'search--busy': searching }">
      <Search :size="18" :stroke-width="1.8" aria-hidden="true" />
      <input
        v-model="search"
        type="search"
        enterkeyhint="search"
        placeholder="Поиск по разделам и материалам"
        aria-label="Поиск по разделам и материалам"
      >
      <button
        v-if="query"
        type="button"
        class="search-clear"
        aria-label="Очистить поиск"
        @click="clearSearch"
      >
        <X :size="16" :stroke-width="1.8" aria-hidden="true" />
      </button>
    </label>

    <!-- Загрузка дерева разделов -->
    <div v-if="loading" class="grid" aria-hidden="true">
      <div v-for="i in 6" :key="i" class="skeleton"></div>
    </div>

    <!-- Режим поиска -->
    <template v-else-if="query">
      <section v-if="foundSections.length" class="block">
        <h2 class="block-h">
          Разделы
          <span>{{ foundSections.length }}</span>
        </h2>
        <div class="grid">
          <SectionCard
            v-for="(s, i) in foundSections"
            :key="s.id"
            :section="s"
            :tint="i"
            :path="s.path"
          />
        </div>
      </section>

      <section v-if="foundItems.length" class="block">
        <h2 class="block-h">
          Материалы и курсы
          <span>{{ foundItems.length }}</span>
        </h2>
        <div class="rows">
          <router-link v-for="r in foundItems" :key="`${r.type}-${r.id}`" class="row" :to="r.to">
            <span class="row-ic"><component :is="r.icon" :size="18" :stroke-width="1.8" /></span>
            <span class="row-t">{{ r.title }}</span>
            <span class="row-type">{{ r.label }}</span>
          </router-link>
        </div>
      </section>

      <div v-if="!foundSections.length && !foundItems.length" class="state">
        <span class="state-ic"><SearchX :size="20" :stroke-width="1.8" /></span>
        <template v-if="searching">Ищем…</template>
        <template v-else-if="query.length < MIN_CHARS">
          Введите хотя бы {{ countLabel(MIN_CHARS, 'символ', 'символа', 'символов') }}
        </template>
        <template v-else>По запросу «{{ query }}» ничего не нашлось</template>
      </div>
    </template>

    <!-- Обычный режим: разделы верхнего уровня -->
    <div v-else-if="sections.length" class="grid">
      <SectionCard
        v-for="(s, i) in sections"
        :key="s.id"
        :section="s"
        :tint="i"
      />
    </div>

    <div v-else class="state">
      <span class="state-ic"><Library :size="20" :stroke-width="1.8" /></span>
      База знаний пока пуста — сотрудник отдела обучения добавит разделы и материалы
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount, onMounted } from 'vue';
import {
  ChevronRight, FileText, GraduationCap, Library, Search, SearchX, Video, X,
} from 'lucide-vue-next';
import api from '../api';
import SectionCard from '../components/education/KbSectionCard.vue';
import { countLabel } from '../utils/kb';

/** Минимум символов для серверного поиска — совпадает с education.search_min_chars. */
const MIN_CHARS = 2;

/** Как показать результат /education/search: подпись, иконка и куда вести. */
const RESULT_TYPES = {
  kb_article: { label: 'Материал', icon: FileText, to: (i) => `/education/kb/articles/${i.id}` },
  course: { label: 'Курс', icon: GraduationCap, to: (i) => `/education/courses/${i.id}` },
  lesson: {
    label: 'Урок',
    icon: Video,
    // Урок открывается только внутри курса, без courseId ссылку не собрать.
    to: (i) => (i.courseId ? `/education/courses/${i.courseId}/lessons/${i.id}` : null),
  },
};

const sections = ref([]);
const loading = ref(true);
const search = ref('');
const searching = ref(false);
const results = ref([]);

const query = computed(() => search.value.trim());

const totalArticles = computed(() => {
  let n = 0;
  const walk = (nodes) => {
    for (const x of nodes || []) {
      n += x.articleCount || 0;
      walk(x.children);
    }
  };
  walk(sections.value);
  return n;
});

/** Плоский список всех разделов дерева с путём до родителя — для поиска. */
const flatSections = computed(() => {
  const out = [];
  const walk = (nodes, trail) => {
    for (const s of nodes || []) {
      out.push({ ...s, path: trail.join(' / ') });
      walk(s.children, [...trail, s.title]);
    }
  };
  walk(sections.value, []);
  return out;
});

const foundSections = computed(() => {
  const q = query.value.toLowerCase();
  if (!q) return [];
  return flatSections.value.filter((s) => `${s.title || ''} ${s.description || ''}`
    .toLowerCase()
    .includes(q));
});

const foundItems = computed(() => results.value
  .map((i) => {
    const t = RESULT_TYPES[i.type];
    if (!t) return null;
    const to = t.to(i);
    return to ? { ...i, to, label: t.label, icon: t.icon } : null;
  })
  .filter(Boolean));

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/education/kb');
    sections.value = data.sections || [];
  } catch {
    sections.value = [];
  }
  loading.value = false;
}

let searchTimer;
async function runSearch(q) {
  try {
    const { data } = await api.get('/education/search', { params: { q } });
    // Ответ мог прийти на уже устаревший запрос — отбрасываем.
    if (q !== query.value) return;
    results.value = data.items || [];
  } catch {
    results.value = [];
  }
  searching.value = false;
}

watch(query, (q) => {
  clearTimeout(searchTimer);
  if (q.length < MIN_CHARS) {
    results.value = [];
    searching.value = false;
    return;
  }
  searching.value = true;
  searchTimer = setTimeout(() => runSearch(q), 400);
});

function clearSearch() {
  search.value = '';
}

onBeforeUnmount(() => clearTimeout(searchTimer));
onMounted(load);
</script>

<style scoped>
.kb {
  font-family: var(--font-sans);
  color: var(--ink);
}

/* ---------- шапка страницы ---------- */
.kb-head { margin-bottom: var(--space-5); }
.crumbs {
  display: flex;
  align-items: center;
  gap: 4px;
  margin-bottom: 6px;
  font: 400 13px/18px var(--font-sans);
  color: var(--ink-muted);
}
.crumbs a { color: var(--ink-muted); text-decoration: none; }
.crumbs a:hover { color: var(--brand); }
.crumbs a:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; border-radius: var(--radius-sm); }
.kb-head h1 {
  margin: 0;
  font: 700 28px/34px var(--font-sans);
  letter-spacing: -0.015em;
}
.kb-head p {
  margin: 4px 0 0;
  font: 400 14px/22px var(--font-sans);
  color: var(--ink-muted);
}

/* ---------- поиск ---------- */
.search {
  display: flex;
  align-items: center;
  gap: 10px;
  height: 52px;
  margin-bottom: var(--space-5);
  padding: 0 var(--space-4);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
  box-shadow: var(--shadow-card);
  color: var(--ink-muted);
}
.search:focus-within { outline: 2px solid var(--focus); outline-offset: 2px; }
.search--busy { border-color: var(--border-strong); }
.search input {
  flex: 1;
  min-width: 0;
  height: 100%;
  border: 0;
  outline: 0;
  background: none;
  color: var(--ink);
  font: 400 16px/1 var(--font-sans);
}
.search input::placeholder { color: var(--ink-muted); }
/* Родной крестик у type="search" — свой, с aria-label. */
.search input::-webkit-search-cancel-button { display: none; }
.search-clear {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  padding: 0;
  border: 0;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  color: var(--ink-muted);
  cursor: pointer;
}
.search-clear:hover { color: var(--ink); }
.search-clear:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }

/* ---------- сетка разделов ---------- */
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

/* ---------- блоки результатов поиска ---------- */
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

.rows {
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
  box-shadow: var(--shadow-card);
  overflow: hidden;
}
.row {
  display: grid;
  grid-template-columns: 32px minmax(0, 1fr) auto;
  gap: var(--space-3);
  align-items: center;
  padding: var(--space-3) var(--space-4);
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
.row-t {
  min-width: 0;
  font: 500 14px/20px var(--font-sans);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.row-type {
  font: 400 12px/16px var(--font-sans);
  color: var(--ink-muted);
  white-space: nowrap;
}

/* ---------- пустые состояния ---------- */
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
  .kb-head h1 { font: 700 24px/30px var(--font-sans); }
  .search { height: 48px; padding: 0 var(--space-3); }
  .search input { font-size: 15px; }
  .row { grid-template-columns: 32px minmax(0, 1fr); }
  .row-type { grid-column: 2; margin-top: -2px; }
}
/* Узкий экран: карточка в одну колонку, иначе заголовки рвутся. */
@media (max-width: 640px) {
  .grid { grid-template-columns: minmax(0, 1fr); }
}
</style>
