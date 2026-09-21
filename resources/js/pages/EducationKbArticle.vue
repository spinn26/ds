<template>
  <div class="kb">
    <nav class="crumbs" aria-label="Хлебные крошки">
      <router-link to="/education">Обучение</router-link>
      <ChevronRight :size="14" :stroke-width="1.8" aria-hidden="true" />
      <router-link to="/education/kb">База знаний</router-link>
      <template v-if="article">
        <template v-if="sectionTitle">
          <ChevronRight :size="14" :stroke-width="1.8" aria-hidden="true" />
          <router-link :to="`/education/kb/sections/${article.sectionId}`">{{ sectionTitle }}</router-link>
        </template>
        <ChevronRight :size="14" :stroke-width="1.8" aria-hidden="true" />
        <span aria-current="page">{{ article.title }}</span>
      </template>
    </nav>

    <div v-if="loading" class="skeleton" aria-hidden="true"></div>

    <article v-else-if="article" class="art">
      <header class="art-head">
        <h1>{{ article.title }}</h1>
        <p v-if="article.description" class="lead">{{ article.description }}</p>
        <div v-if="article.tags?.length" class="tags">
          <span v-for="t in article.tags" :key="t" class="tag">#{{ t }}</span>
        </div>
      </header>

      <LessonBlockRenderer v-if="hasBlocks" :blocks="article.body" class="art-body" />

      <div v-else class="note">
        <Info :size="18" :stroke-width="1.8" aria-hidden="true" />
        <span>Содержимое материала пока пустое — отдел обучения добавит блоки позже.</span>
      </div>
    </article>

    <div v-else class="state">
      <span class="state-ic"><FileText :size="20" :stroke-width="1.8" /></span>
      Материал не найден или снят с публикации
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ChevronRight, FileText, Info } from 'lucide-vue-next';
import api from '../api';
import LessonBlockRenderer from '../components/education/LessonBlockRenderer.vue';

const route = useRoute();
const loading = ref(true);
const article = ref(null);
/** Название раздела для крошек: в ответе материала есть только sectionId. */
const sectionTitle = ref('');

const hasBlocks = computed(() => {
  const b = article.value?.body;
  if (!b) return false;
  if (Array.isArray(b)) return b.length > 0;
  try { const p = JSON.parse(b); return Array.isArray(p) && p.length > 0; }
  catch { return false; }
});

async function load() {
  loading.value = true;
  sectionTitle.value = '';
  try {
    const { data } = await api.get(`/education/kb/articles/${route.params.id}`);
    article.value = data;
  } catch {
    article.value = null;
  }
  loading.value = false;
  loadSectionTitle();
}

/** Подтягиваем раздел отдельно — крошка без названия бесполезна. */
async function loadSectionTitle() {
  const id = article.value?.sectionId;
  if (!id) return;
  try {
    const { data } = await api.get(`/education/kb/sections/${id}`);
    if (article.value?.sectionId === id) sectionTitle.value = data.section?.title || '';
  } catch { /* крошка просто не появится */ }
}
onMounted(load);
watch(() => route.params.id, (id) => { if (id) load(); });
</script>

<style scoped>
.kb {
  font-family: var(--font-sans);
  color: var(--ink);
}

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

/* Колонка чтения: узкая, чтобы строка не расползалась по широкому экрану. */
.art { max-width: 820px; }

.art-head { margin-bottom: var(--space-6); }
.art-head h1 {
  margin: 0;
  font: 700 28px/34px var(--font-sans);
  letter-spacing: -0.015em;
}
.lead {
  margin: var(--space-3) 0 0;
  font: 400 16px/26px var(--font-sans);
  color: var(--ink-muted);
}
.tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: var(--space-4); }
.tag {
  padding: 2px 10px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  color: var(--ink-muted);
  font: 500 12px/20px var(--font-sans);
}

.art-body {
  padding: var(--space-5);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
  box-shadow: var(--shadow-card);
}

.note {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-4) var(--space-5);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--info-soft);
  color: var(--ink);
  font: 400 14px/22px var(--font-sans);
}
.note svg { flex: 0 0 auto; color: var(--info); }

.skeleton {
  max-width: 820px;
  height: 320px;
  border-radius: var(--radius-lg);
  background: var(--surface-2);
}

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

@media (max-width: 860px) {
  .art-head h1 { font: 700 22px/28px var(--font-sans); }
  .lead { font: 400 15px/24px var(--font-sans); }
  .art-body { padding: var(--space-4); }
}
</style>
