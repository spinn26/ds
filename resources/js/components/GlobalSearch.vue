<template>
  <v-dialog v-model="open" max-width="580" scrollable transition="dialog-top-transition"
    content-class="palette-dialog">
    <div class="palette">
      <div class="palette-field">
        <v-icon size="20">mdi-magnify</v-icon>
        <input ref="inputRef" v-model="query" autofocus class="palette-input"
          :placeholder="placeholder"
          @keydown.enter="goActive"
          @keydown.down.prevent="move(1)"
          @keydown.up.prevent="move(-1)"
          @keydown.esc="open = false" />
        <span class="palette-kbd">esc</span>
      </div>

      <div v-if="loading && !rows.length" class="palette-empty">
        <v-progress-circular indeterminate size="22" />
      </div>
      <div v-else-if="!rows.length" class="palette-empty">
        <span>{{ query ? 'Ничего не найдено' : 'Разделы не найдены' }}</span>
      </div>
      <div v-else ref="listRef" class="palette-list" role="listbox">
        <button v-for="(r, idx) in rows" :key="`${r.type}-${r.url}-${idx}`" type="button"
          role="option" :aria-selected="idx === activeIdx"
          :class="['palette-row', { active: idx === activeIdx }]"
          @click="go(r)" @mouseenter="activeIdx = idx">
          <v-icon size="18" class="palette-row-ic">{{ r.icon || 'mdi-chevron-right' }}</v-icon>
          <span class="palette-row-title">{{ r.title }}</span>
          <span class="palette-row-group">{{ r.subtitle || typeLabel(r.type) }}</span>
        </button>
      </div>

      <div class="palette-foot">
        <span><span class="palette-kbd">↑↓</span> выбрать</span>
        <span><span class="palette-kbd">Enter</span> открыть</span>
        <span><span class="palette-kbd">Ctrl K</span> вызвать</span>
      </div>
    </div>
  </v-dialog>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api';

// Палитра перехода по разделам (per ds-redesign/design/components/CommandPalette.md).
// Разделы приходят из того же меню, что рисует сайдбар, — поэтому поиск по
// меню переехал сюда из сайдбара и работает у всех, а не только у сотрудников.
// Поиск по ДАННЫМ (партнёры, клиенты, договоры) остаётся привилегией staff:
// эндпоинт /search отдаёт партнёру только его договоры и продукты.
const props = defineProps({
  sections: { type: Array, default: () => [] },
  dataSearch: { type: Boolean, default: false },
});

const router = useRouter();
const open = ref(false);
const query = ref('');
const apiResults = ref([]);
const loading = ref(false);
const activeIdx = ref(0);
const inputRef = ref(null);
const listRef = ref(null);
let debounceTimer;

const placeholder = computed(() => props.dataSearch
  ? 'Раздел, партнёр, клиент, договор…'
  : 'Поиск по разделам');

// Разделы фильтруем на месте: они уже в памяти, ходить на сервер незачем.
const sectionRows = computed(() => {
  const q = query.value.trim().toLowerCase();
  const list = props.sections.filter((s) => !q || s.title.toLowerCase().includes(q));
  return q ? list.slice(0, 12) : list;
});

const rows = computed(() => [...sectionRows.value, ...apiResults.value]);

watch(query, () => {
  activeIdx.value = 0;
  clearTimeout(debounceTimer);
  if (!props.dataSearch || query.value.trim().length < 2) {
    apiResults.value = [];
    return;
  }
  debounceTimer = setTimeout(search, 250);
});

watch(open, (v) => {
  if (v) {
    query.value = '';
    apiResults.value = [];
    activeIdx.value = 0;
    nextTick(() => inputRef.value?.focus());
  }
});

// Порядковый номер запроса: ответы приходят не в том порядке, в каком ушли,
// и старый затирал бы свежий.
let seq = 0;

async function search() {
  const my = ++seq;
  const term = query.value.trim();
  loading.value = true;
  try {
    // У сотрудника — тот же /admin/search, что был в прежней панели: он режет
    // разделы по правам и ищет шире (партнёры, клиенты, договоры, обращения).
    // Партнёру этот эндпоинт отдаёт пустоту, для него — кабинетный /search.
    if (props.dataSearch) {
      const { data } = await api.get('/admin/search', { params: { q: term } });
      if (my !== seq) return;
      apiResults.value = (data.groups || []).flatMap((g) => (g.items || []).map((it) => ({
        type: 'data',
        icon: g.icon,
        title: it.title,
        subtitle: g.title,
        url: it.path,
      })));
    } else {
      const { data } = await api.get('/search', { params: { q: term } });
      if (my !== seq) return;
      apiResults.value = (data.results || []).map((r) => ({ ...r, subtitle: typeLabel(r.type) }));
    }
  } catch {
    if (my === seq) apiResults.value = [];
  }
  if (my === seq) loading.value = false;
}

function move(delta) {
  if (!rows.value.length) return;
  activeIdx.value = (activeIdx.value + delta + rows.value.length) % rows.value.length;
  nextTick(() => {
    listRef.value?.querySelector('.palette-row.active')?.scrollIntoView({ block: 'nearest' });
  });
}
function goActive() {
  if (rows.value[activeIdx.value]) go(rows.value[activeIdx.value]);
}
function go(r) {
  open.value = false;
  if (!r.url) return;
  // Внешние пункты меню (ФинРывок, Telegram-поддержка) — обычные ссылки.
  if (/^https?:\/\//.test(r.url)) window.open(r.url, '_blank', 'noopener');
  else router.push(r.url);
}
function typeLabel(t) {
  return { section: 'Раздел', partner: 'Партнёр', client: 'Клиент', contract: 'Договор',
    ticket: 'Обращение', product: 'Продукт' }[t] || t;
}

function handleKeyDown(e) {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault();
    open.value = true;
  }
}
onMounted(() => window.addEventListener('keydown', handleKeyDown));
onUnmounted(() => { window.removeEventListener('keydown', handleKeyDown); clearTimeout(debounceTimer); });

defineExpose({ open: () => { open.value = true; } });
</script>

<style scoped>
.palette {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-pop);
  overflow: hidden;
  font-family: var(--font-sans);
  color: var(--ink);
}

.palette-field {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-4) var(--space-5);
  border-bottom: 1px solid var(--border);
  color: var(--ink-muted);
}

.palette-input {
  flex: 1 1 auto;
  background: transparent;
  border: 0;
  outline: none;
  font: 400 16px/24px var(--font-sans);
  color: var(--ink);
}
.palette-input::placeholder { color: var(--ink-muted); }

.palette-kbd {
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  padding: 1px 6px;
  font: 400 11px/16px var(--font-sans);
  color: var(--ink-muted);
  white-space: nowrap;
}

.palette-list {
  max-height: 52vh;
  overflow-y: auto;
  padding: var(--space-2);
}

.palette-row {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  width: 100%;
  padding: 9px var(--space-3);
  border: 0;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--ink);
  font: 500 13px/18px var(--font-sans);
  text-align: left;
  cursor: pointer;
}
.palette-row.active {
  background: var(--brand-soft);
  color: var(--brand);
}
.palette-row.active .palette-row-ic { color: var(--brand); }

.palette-row-ic { color: var(--ink-muted); flex: 0 0 auto; }
.palette-row-title { flex: 1 1 auto; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.palette-row-group {
  flex: 0 0 auto;
  font: 400 12px/16px var(--font-sans);
  color: var(--ink-muted);
}

.palette-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--space-8);
  color: var(--ink-muted);
  font: 400 14px/22px var(--font-sans);
}

.palette-foot {
  display: flex;
  gap: var(--space-4);
  padding: var(--space-3) var(--space-5);
  border-top: 1px solid var(--border);
  font: 400 12px/16px var(--font-sans);
  color: var(--ink-muted);
}
.palette-foot span { display: inline-flex; align-items: center; gap: 6px; }
</style>
