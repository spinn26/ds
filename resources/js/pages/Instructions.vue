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
        <v-card v-else variant="flat" class="instructions-empty">
          <EmptyState
            icon="mdi-folder-text-outline"
            message="Выберите категорию слева"
            hint="После выбора здесь появится список доступных инструкций"
          />
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

          <!-- Видео: загруженный файл или эмбед YouTube/Vimeo -->
          <div v-if="selectedInstruction.video_url" class="mb-4 video-wrapper">
            <video v-if="isFileVideo(selectedInstruction.video_url)"
              :src="selectedInstruction.video_url" controls playsinline />
            <iframe v-else :src="embedUrl(selectedInstruction.video_url)" allowfullscreen frameborder="0" />
          </div>

          <!-- Контент markdown: полноценный рендер (картинки, таблицы, ссылки, код) -->
          <MdPreview class="instruction-body" :id="previewId"
            :model-value="selectedInstruction.body_md || ''"
            :theme="previewTheme" preview-theme="github" :md-heading-id="headingId" />
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
import { useTheme } from 'vuetify';
import { MdPreview } from 'md-editor-v3';
import 'md-editor-v3/lib/style.css';
import api from '../api';
import { useDebounce } from '../composables/useDebounce';
import PageHeader from '../components/PageHeader.vue';
import EmptyState from '../components/EmptyState.vue';

const search = ref('');
const categories = ref({});
const activeCategory = ref(null);
const readerOpen = ref(false);
const selectedInstruction = ref(null);

const vTheme = useTheme();
const previewTheme = computed(() => (vTheme.global.current.value.dark ? 'dark' : 'light'));
const previewId = 'instruction-preview';

// Якорь заголовка — одна функция и для оглавления, и для рендера. Иначе TOC
// не находит элемент: на бэке Str::slug транслитерирует кириллицу, а тут нет.
function headingId(text) { return slugify(String(text)); }

// Оглавление считаем из markdown на фронте — так якоря гарантированно
// совпадают с id, которые проставит MdPreview.
const toc = computed(() => {
  const md = selectedInstruction.value?.body_md || '';
  const out = [];
  for (const line of md.split(/\r?\n/)) {
    const m = line.match(/^(#{2,3})\s+(.+?)\s*$/);
    if (m) out.push({ level: m[1].length, title: m[2], anchor: slugify(m[2]) });
  }
  return out;
});

// Загруженный видео-файл показываем плеером, ссылку YouTube/Vimeo — эмбедом.
function isFileVideo(u) { return /\.(mp4|webm|mov)(\?|$)/i.test(u || ''); }

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
    readerOpen.value = true;
  } catch {}
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
.video-wrapper iframe,
.video-wrapper video {
  position: absolute;
  top: 0; left: 0;
  width: 100%; height: 100%;
  background: #000;
}
.back-to-top {
  position: fixed;
  bottom: 16px; right: 16px;
}
.instruction-body :deep(h2) { margin-top: 24px; margin-bottom: 12px; }
.instruction-body :deep(h3) { margin-top: 18px; margin-bottom: 8px; }
.instruction-body :deep(p) { margin-bottom: 8px; }
.instruction-body :deep(ul) { margin-bottom: 12px; padding-left: 24px; }
/* MdPreview рисует свой фон/отступы — гасим, чтобы вписался в карточку. */
.instruction-body { background: transparent !important; }
.instruction-body :deep(.md-editor-preview-wrapper) { padding: 0; }
/* Картинки и таблицы не должны ломать ширину читалки. */
.instruction-body :deep(img) { max-width: 100%; height: auto; border-radius: 8px; }
.instruction-body :deep(table) { display: block; overflow-x: auto; max-width: 100%; }
.instructions-empty {
  border-radius: var(--ds-radius-xl, 16px);
  border: 1px solid var(--ds-outline-variant, rgba(0, 0, 0, 0.06));
  background: rgb(var(--v-theme-surface));
}
</style>
