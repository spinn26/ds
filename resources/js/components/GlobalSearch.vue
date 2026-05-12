<template>
  <v-dialog v-model="open" max-width="640" scrollable transition="dialog-top-transition">
    <v-card class="global-search">
      <v-card-text class="pa-0">
        <div class="d-flex align-center pa-3">
          <v-icon size="22" color="primary">mdi-magnify</v-icon>
          <input ref="inputRef" v-model="query" autofocus class="search-input ml-2 flex-grow-1"
            placeholder="Найти партнёра, клиента, контракт, тикет, продукт..."
            @keydown.enter="goActive"
            @keydown.down.prevent="move(1)"
            @keydown.up.prevent="move(-1)"
            @keydown.esc="open = false" />
          <v-chip size="x-small" variant="outlined" color="grey">esc</v-chip>
        </div>
        <v-divider />
        <div v-if="loading" class="d-flex justify-center pa-6">
          <v-progress-circular indeterminate size="24" />
        </div>
        <div v-else-if="!query || query.length < 2" class="text-center text-medium-emphasis pa-6">
          <v-icon size="40" color="grey-lighten-1">mdi-keyboard</v-icon>
          <div class="mt-2 text-body-2">Введите минимум 2 символа</div>
          <div class="text-caption mt-1">Партнёры · Клиенты · Контракты · Тикеты · Продукты</div>
        </div>
        <div v-else-if="!results.length" class="text-center text-medium-emphasis pa-6">
          <v-icon size="40" color="grey-lighten-1">mdi-magnify-close</v-icon>
          <div class="mt-2 text-body-2">Ничего не найдено</div>
        </div>
        <v-list v-else density="comfortable" class="search-results">
          <v-list-item v-for="(r, idx) in results" :key="`${r.type}-${idx}`"
            :class="['search-row', { active: idx === activeIdx }]"
            @click="go(r)"
            @mouseenter="activeIdx = idx">
            <template #prepend><v-icon :color="typeColor(r.type)" size="20">{{ r.icon }}</v-icon></template>
            <div>
              <div class="font-weight-medium">{{ r.title }}</div>
              <div v-if="r.subtitle" class="text-caption text-medium-emphasis">{{ r.subtitle }}</div>
            </div>
            <template #append>
              <v-chip size="x-small" variant="tonal" :color="typeColor(r.type)">{{ typeLabel(r.type) }}</v-chip>
            </template>
          </v-list-item>
        </v-list>
        <v-divider />
        <div class="d-flex justify-space-between align-center pa-2 text-caption text-medium-emphasis">
          <div>
            <v-chip size="x-small" variant="outlined">↑↓</v-chip>
            <v-chip size="x-small" variant="outlined" class="ms-1">Enter</v-chip>
            <span class="ms-2">— навигация</span>
          </div>
          <div>
            <v-chip size="x-small" variant="outlined">Ctrl</v-chip>
            <v-chip size="x-small" variant="outlined" class="ms-1">K</v-chip>
            <span class="ms-2">— открыть</span>
          </div>
        </div>
      </v-card-text>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted, nextTick } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api';

const router = useRouter();
const open = ref(false);
const query = ref('');
const results = ref([]);
const loading = ref(false);
const activeIdx = ref(0);
const inputRef = ref(null);
let debounceTimer;

watch(query, () => {
  activeIdx.value = 0;
  clearTimeout(debounceTimer);
  if (query.value.length < 2) {
    results.value = [];
    return;
  }
  debounceTimer = setTimeout(search, 250);
});

async function search() {
  loading.value = true;
  try {
    const { data } = await api.get('/search', { params: { q: query.value } });
    results.value = data.results || [];
  } catch {
    results.value = [];
  }
  loading.value = false;
}

function move(delta) {
  if (!results.value.length) return;
  activeIdx.value = (activeIdx.value + delta + results.value.length) % results.value.length;
}
function goActive() {
  if (results.value[activeIdx.value]) go(results.value[activeIdx.value]);
}
function go(r) {
  open.value = false;
  router.push(r.url);
}
function typeLabel(t) {
  return { partner: 'Партнёр', client: 'Клиент', contract: 'Контракт',
    ticket: 'Тикет', product: 'Продукт' }[t] || t;
}
function typeColor(t) {
  return { partner: 'primary', client: 'info', contract: 'success',
    ticket: 'warning', product: 'secondary' }[t] || 'grey';
}

function handleKeyDown(e) {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault();
    open.value = true;
    nextTick(() => inputRef.value?.focus());
  }
}
onMounted(() => window.addEventListener('keydown', handleKeyDown));
onUnmounted(() => window.removeEventListener('keydown', handleKeyDown));

defineExpose({ open: () => { open.value = true; } });
</script>

<style scoped>
.global-search {
  border-radius: 12px;
}
.search-input {
  background: transparent;
  border: none;
  outline: none;
  color: inherit;
  font-size: 16px;
  width: 100%;
}
.search-row {
  cursor: pointer;
  transition: background 0.1s;
}
.search-row.active {
  background: rgba(var(--v-theme-primary), 0.12);
}
.search-results {
  max-height: 50vh;
  overflow-y: auto;
}
</style>
