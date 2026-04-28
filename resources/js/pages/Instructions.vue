<template>
  <div>
    <PageHeader title="Инструкции" icon="mdi-book-open-variant" />

    <v-card class="mb-3 pa-3">
      <v-text-field v-model="search" placeholder="Поиск по тексту инструкций..."
        density="compact" variant="outlined" hide-details rounded clearable
        prepend-inner-icon="mdi-magnify"
        @update:model-value="debouncedLoad" />
    </v-card>

    <v-row>
      <!-- Категории / каталог -->
      <v-col cols="12" md="4">
        <v-card>
          <v-list density="compact">
            <v-list-subheader>Категории</v-list-subheader>
            <v-list-item v-for="(items, cat) in categories" :key="cat"
              :title="cat" :subtitle="`${items.length} статей`"
              :active="activeCategory === cat"
              @click="activeCategory = cat" />
            <v-list-item v-if="!Object.keys(categories).length"
              title="Ничего не найдено" subtitle="Попробуйте другой поиск" />
          </v-list>
        </v-card>
      </v-col>

      <v-col cols="12" md="8">
        <v-card v-if="activeCategory && categories[activeCategory]" class="pa-3">
          <div class="text-h6 mb-3">{{ activeCategory }}</div>
          <v-list density="compact">
            <v-list-item v-for="ins in categories[activeCategory]" :key="ins.id"
              :title="ins.title"
              prepend-icon="mdi-file-document-outline"
              @click="openInstruction(ins)">
              <template #append v-if="ins.video_url">
                <v-icon color="info" size="18" title="Есть видео">mdi-play-circle-outline</v-icon>
              </template>
            </v-list-item>
          </v-list>
        </v-card>
        <v-card v-else class="pa-4 text-center text-medium-emphasis">
          Выберите категорию слева
        </v-card>
      </v-col>
    </v-row>

    <!-- Drawer статьи -->
    <v-navigation-drawer v-model="readerOpen" location="right" temporary width="800">
      <v-card v-if="selectedInstruction" flat>
        <v-card-title class="d-flex align-center">
          {{ selectedInstruction.title }}
          <v-spacer />
          <v-btn icon="mdi-close" size="small" variant="text" @click="readerOpen = false" />
        </v-card-title>
        <v-card-text>
          <!-- TOC -->
          <v-card v-if="toc.length" variant="tonal" color="info" class="mb-3 pa-3">
            <div class="text-subtitle-2 mb-2">План подразделов</div>
            <ul class="ps-4">
              <li v-for="t in toc" :key="t.anchor" :style="{ marginLeft: ((t.level - 2) * 16) + 'px' }">
                <a :href="'#' + t.anchor" class="text-primary text-decoration-none"
                  @click.prevent="scrollToAnchor(t.anchor)">{{ t.title }}</a>
              </li>
            </ul>
          </v-card>

          <!-- Видео -->
          <div v-if="selectedInstruction.video_url" class="mb-4 video-wrapper">
            <iframe :src="embedUrl(selectedInstruction.video_url)" allowfullscreen frameborder="0" />
          </div>

          <!-- Контент markdown → html -->
          <div class="instruction-body" v-html="renderedHtml" />
        </v-card-text>

        <v-btn icon="mdi-arrow-up" size="large" color="primary" class="back-to-top" @click="scrollToTop">
          <v-icon>mdi-arrow-up</v-icon>
        </v-btn>
      </v-card>
    </v-navigation-drawer>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api';
import { useDebounce } from '../composables/useDebounce';
import PageHeader from '../components/PageHeader.vue';

const search = ref('');
const categories = ref({});
const activeCategory = ref(null);
const readerOpen = ref(false);
const selectedInstruction = ref(null);
const toc = ref([]);

const { debounced: debouncedLoad } = useDebounce(loadList, 400);

async function loadList() {
  try {
    const params = {};
    if (search.value) params.search = search.value;
    const { data } = await api.get('/instructions', { params });
    categories.value = data.categories || {};
    if (!activeCategory.value || !categories.value[activeCategory.value]) {
      activeCategory.value = Object.keys(categories.value)[0] || null;
    }
  } catch {}
}

async function openInstruction(item) {
  try {
    const { data } = await api.get('/instructions/' + item.slug);
    selectedInstruction.value = data.instruction;
    toc.value = data.toc || [];
    readerOpen.value = true;
  } catch {}
}

// Простой markdown → html (без внешней либы): заголовки, списки, abzац, code.
const renderedHtml = computed(() => {
  if (!selectedInstruction.value?.body_md) return '<p class="text-medium-emphasis">Текст ещё не заполнен.</p>';
  return mdToHtml(selectedInstruction.value.body_md);
});

function mdToHtml(md) {
  const lines = md.split(/\r?\n/);
  let html = '';
  let inList = false;
  for (const line of lines) {
    const h2 = line.match(/^##\s+(.+?)\s*$/);
    const h3 = line.match(/^###\s+(.+?)\s*$/);
    const li = line.match(/^[-*]\s+(.+)$/);
    if (h2) {
      if (inList) { html += '</ul>'; inList = false; }
      html += `<h2 id="${slugify(h2[1])}">${escape(h2[1])}</h2>`;
    } else if (h3) {
      if (inList) { html += '</ul>'; inList = false; }
      html += `<h3 id="${slugify(h3[1])}">${escape(h3[1])}</h3>`;
    } else if (li) {
      if (!inList) { html += '<ul>'; inList = true; }
      html += `<li>${escape(li[1])}</li>`;
    } else if (line.trim() === '') {
      if (inList) { html += '</ul>'; inList = false; }
    } else {
      if (inList) { html += '</ul>'; inList = false; }
      html += `<p>${escape(line)}</p>`;
    }
  }
  if (inList) html += '</ul>';
  return html;
}

function escape(s) {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function slugify(s) {
  return s.toLowerCase().replace(/[^a-zа-яё0-9]+/gi, '-').replace(/^-|-$/g, '');
}
function scrollToAnchor(a) {
  const el = document.getElementById(a);
  if (el) el.scrollIntoView({ behavior: 'smooth' });
}
function scrollToTop() { window.scrollTo({ top: 0, behavior: 'smooth' }); }

function embedUrl(url) {
  if (!url) return '';
  // YouTube
  let m = url.match(/youtube\.com\/watch\?v=([\w-]+)/) || url.match(/youtu\.be\/([\w-]+)/);
  if (m) return `https://www.youtube.com/embed/${m[1]}`;
  m = url.match(/vimeo\.com\/(\d+)/);
  if (m) return `https://player.vimeo.com/video/${m[1]}`;
  return url;
}

onMounted(loadList);
</script>

<style scoped>
.video-wrapper {
  position: relative;
  padding-bottom: 56.25%;
  height: 0;
}
.video-wrapper iframe {
  position: absolute;
  top: 0; left: 0;
  width: 100%; height: 100%;
}
.back-to-top {
  position: fixed;
  bottom: 16px; right: 16px;
}
.instruction-body :deep(h2) { margin-top: 24px; margin-bottom: 12px; }
.instruction-body :deep(h3) { margin-top: 18px; margin-bottom: 8px; }
.instruction-body :deep(p) { margin-bottom: 8px; }
.instruction-body :deep(ul) { margin-bottom: 12px; padding-left: 24px; }
</style>
