<template>
  <v-menu :close-on-content-click="false" location="bottom end">
    <template #activator="{ props: menuProps }">
      <v-btn v-bind="menuProps" :size="size" :variant="variant"
        prepend-icon="mdi-view-column" :color="color">
        <slot name="label">Колонки</slot>
        <v-chip v-if="hiddenCount > 0" size="x-small" class="ms-2" color="warning">
          {{ hiddenCount }}
        </v-chip>
      </v-btn>
    </template>
    <v-list density="compact" style="min-width: 260px" max-height="460" class="col-prefs-list">
      <v-list-subheader>
        Колонки
        <span v-if="reorderable" class="text-caption text-medium-emphasis ms-1">
          · перетаскивайте для порядка
        </span>
      </v-list-subheader>
      <v-list-item
        v-for="(col, idx) in orderedColumns" :key="col.key"
        :class="['col-prefs-row', { 'col-prefs-row--drag': dragKey === col.key }]"
        :draggable="reorderable"
        @dragstart="onDragStart($event, col.key)"
        @dragover.prevent="onDragOver($event, col.key)"
        @drop.prevent="onDrop(col.key)"
        @dragend="onDragEnd">
        <template #prepend>
          <v-icon v-if="reorderable" size="16" class="me-1 col-prefs-handle"
            title="Перетащить">mdi-drag-vertical</v-icon>
          <v-checkbox-btn
            :model-value="isVisible(col.key)"
            @update:model-value="v => toggle(col.key, v)"
          />
        </template>
        <v-list-item-title>{{ col.title }}</v-list-item-title>
      </v-list-item>
      <v-divider />
      <v-list-item @click="showAll" prepend-icon="mdi-eye">
        <v-list-item-title>Показать все</v-list-item-title>
      </v-list-item>
      <v-list-item v-if="reorderable" @click="resetOrder" prepend-icon="mdi-restore">
        <v-list-item-title>Сбросить порядок</v-list-item-title>
      </v-list-item>
    </v-list>
  </v-menu>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useColumnPrefsStore } from '@/stores/columnPrefs';

const prefs = useColumnPrefsStore();

const props = defineProps({
  /** Исходные headers таблицы — как в v-data-table. */
  headers: { type: Array, required: true },
  /** v-model:visible = { key: bool }. */
  visible: { type: Object, default: () => ({}) },
  /**
   * v-model:order = массив ключей в нужном порядке. Если не передан —
   * DnD-режим отключён, drag-handle не показывается, порядок не
   * сохраняется. Это сделано чтобы не ломать страницы, которые
   * подключают меню только для управления видимостью.
   */
  order: { type: Array, default: null },
  storageKey: { type: String, default: null },
  alwaysVisible: { type: Array, default: () => ['actions'] },
  size: { type: String, default: 'small' },
  variant: { type: String, default: 'text' },
  color: { type: String, default: undefined },
});

const emit = defineEmits(['update:visible', 'update:order']);

const reorderable = computed(() => Array.isArray(props.order));

const toggleableColumns = computed(() =>
  // Игнорируем колонки без `title` — это служебные (actions, chat,
  // expand-toggle и т.п.), пользователь их прятать/двигать не должен.
  props.headers.filter(h => h.key
    && h.title && String(h.title).trim() !== ''
    && !props.alwaysVisible.includes(h.key))
);

/** Колонки в порядке, который сейчас сохранён (или исходном). */
const orderedColumns = computed(() => {
  if (!reorderable.value) return toggleableColumns.value;
  const byKey = new Map(toggleableColumns.value.map(c => [c.key, c]));
  const seen = new Set();
  const out = [];
  for (const key of props.order || []) {
    if (byKey.has(key) && !seen.has(key)) {
      out.push(byKey.get(key));
      seen.add(key);
    }
  }
  // Хвост: колонки, которых не было в сохранённом порядке (новые
  // версии таблицы добавили), цепляем в конец в исходном порядке.
  for (const c of toggleableColumns.value) {
    if (!seen.has(c.key)) out.push(c);
  }
  return out;
});

const hiddenCount = computed(() =>
  toggleableColumns.value.filter(c => !isVisible(c.key)).length
);

function isVisible(key) {
  if (!(key in props.visible)) return true;
  return props.visible[key] !== false;
}

function toggle(key, v) {
  const next = { ...props.visible, [key]: !!v };
  emit('update:visible', next);
  persist(next, props.order);
}

function showAll() {
  const next = {};
  toggleableColumns.value.forEach(c => { next[c.key] = true; });
  emit('update:visible', next);
  persist(next, props.order);
}

function resetOrder() {
  if (!reorderable.value) return;
  const next = toggleableColumns.value.map(c => c.key);
  emit('update:order', next);
  persist(props.visible, next);
}

// --- DnD ---
const dragKey = ref(null);
function onDragStart(ev, key) {
  if (!reorderable.value) return;
  dragKey.value = key;
  // Без setData Firefox блокирует drop.
  try { ev.dataTransfer?.setData('text/plain', key); } catch {}
  if (ev.dataTransfer) ev.dataTransfer.effectAllowed = 'move';
}
function onDragOver(ev) {
  if (!reorderable.value || !dragKey.value) return;
  if (ev.dataTransfer) ev.dataTransfer.dropEffect = 'move';
}
function onDrop(targetKey) {
  if (!reorderable.value || !dragKey.value || dragKey.value === targetKey) return;
  const current = orderedColumns.value.map(c => c.key);
  const from = current.indexOf(dragKey.value);
  const to = current.indexOf(targetKey);
  if (from < 0 || to < 0) return;
  current.splice(to, 0, current.splice(from, 1)[0]);
  emit('update:order', current);
  persist(props.visible, current);
  dragKey.value = null;
}
function onDragEnd() { dragKey.value = null; }

function persist(visibleState, orderState) {
  if (!props.storageKey) return;
  prefs.save(props.storageKey, {
    visible: visibleState,
    order: Array.isArray(orderState) ? orderState : null,
  });
}

onMounted(() => {
  if (!props.storageKey) return;
  const loaded = prefs.load(props.storageKey);
  if (loaded) {
    if (loaded.visible) emit('update:visible', loaded.visible);
    if (loaded.order && reorderable.value) emit('update:order', loaded.order);
    return;
  }
  // Fallback: одноразовая миграция со старого ключа `cols:${storageKey}`
  // (без per-user namespace, плоский объект видимости).
  try {
    const legacy = localStorage.getItem(`cols:${props.storageKey}`);
    if (legacy) {
      const parsed = JSON.parse(legacy);
      emit('update:visible', parsed);
      prefs.save(props.storageKey, { visible: parsed, order: null });
      localStorage.removeItem(`cols:${props.storageKey}`);
    }
  } catch {}
});
</script>

<style scoped>
.col-prefs-row {
  cursor: default;
}
.col-prefs-row[draggable="true"] {
  cursor: grab;
}
.col-prefs-row[draggable="true"]:active {
  cursor: grabbing;
}
.col-prefs-row--drag {
  opacity: 0.4;
}
.col-prefs-handle {
  opacity: 0.5;
}
</style>
