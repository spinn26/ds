<template>
  <div
    class="node"
    :draggable="true"
    @dragstart.stop="onDragStart"
    @dragover.prevent.stop="onDragOver"
    @dragleave.stop="dropPos = null"
    @drop.prevent.stop="onDrop"
  >
    <div
      class="node-row"
      :class="{
        selected: isSelected,
        'drop-into': dropPos === 'into',
        'drop-before': dropPos === 'before',
        'drop-after': dropPos === 'after',
      }"
      :style="{ paddingLeft: (level - 1) * 14 + 'px' }"
      @click="$emit('select', node)"
    >
      <v-icon
        v-if="hasChildren"
        size="14"
        class="twirl"
        :class="{ open: expanded }"
        @click.stop="expanded = !expanded"
      >
        mdi-chevron-right
      </v-icon>
      <v-icon v-else size="6" class="bullet">mdi-circle-small</v-icon>

      <v-icon
        size="16"
        :color="iconColor"
        class="type-icon"
      >
        {{ iconName }}
      </v-icon>

      <span class="title-text" :title="node.title">{{ node.title }}</span>

      <v-menu location="bottom end" @click.stop>
        <template #activator="{ props: a }">
          <v-btn
            v-bind="a"
            icon="mdi-dots-vertical" size="x-small" variant="text" density="compact"
            class="actions-btn"
            @click.stop
          />
        </template>
        <v-list density="compact">
          <!-- Раздел (1 уровень) → можно создать курс внутри -->
          <v-list-item v-if="canAddChild"
            prepend-icon="mdi-book-plus-outline"
            @click="$emit('add-child', node)">
            <v-list-item-title>+ Курс</v-list-item-title>
          </v-list-item>
          <!-- Курс (2 уровень) → можно создать блок -->
          <v-list-item v-if="canAddLesson"
            prepend-icon="mdi-text-box-plus-outline"
            @click="$emit('add-lesson', node)">
            <v-list-item-title>+ Блок</v-list-item-title>
          </v-list-item>
          <v-divider v-if="canAddChild || canAddLesson" />
          <v-list-item prepend-icon="mdi-arrow-up" @click="$emit('move-up', node)">
            <v-list-item-title>Вверх</v-list-item-title>
          </v-list-item>
          <v-list-item prepend-icon="mdi-arrow-down" @click="$emit('move-down', node)">
            <v-list-item-title>Вниз</v-list-item-title>
          </v-list-item>
          <v-divider />
          <v-list-item prepend-icon="mdi-delete-outline"
            class="text-error" @click="$emit('delete', node)">
            <v-list-item-title>Удалить</v-list-item-title>
          </v-list-item>
        </v-list>
      </v-menu>
    </div>

    <div v-if="hasChildren && expanded">
      <ConstructorTreeNode
        v-for="child in node.children"
        :key="child.id"
        :node="child"
        :selected-id="selectedId"
        :level="level + 1"
        :max-depth="maxDepth"
        @select="(n) => $emit('select', n)"
        @add-child="(n) => $emit('add-child', n)"
        @add-lesson="(n) => $emit('add-lesson', n)"
        @move-up="(n) => $emit('move-up', n)"
        @move-down="(n) => $emit('move-down', n)"
        @delete="(n) => $emit('delete', n)"
        @drop-node="(payload) => $emit('drop-node', payload)"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
  node: { type: Object, required: true },
  selectedId: { type: [Number, String], default: null },
  level: { type: Number, default: 1 },
  maxDepth: { type: Number, default: 2 },   // 2 = Раздел(1) → Курс(2). Блоки = lessons, не узлы дерева
});
const emit = defineEmits([
  'select', 'add-child', 'add-lesson', 'delete', 'move-up', 'move-down',
  'drop-node',
]);

const expanded = ref(props.level <= 2);
const hasChildren = computed(() => (props.node.children?.length || 0) > 0);
const isSelected = computed(() => Number(props.selectedId) === props.node.id);

// Тип узла по уровню: 1 = Раздел (контейнер), 2 = Курс (с блоками).
// Раздел — оранжевая папка, курс — синяя книга.
const isSection = computed(() => props.level === 1 || props.node.isContainer);
const iconName = computed(() => isSection.value ? 'mdi-folder-outline' : 'mdi-book-open-variant');
const iconColor = computed(() => isSection.value ? 'amber-darken-2' : 'primary');

// Что разрешено добавлять в это меню:
// — На разделе можно создать только курс (+ Курс).
// — На курсе — только блок (+ Блок). Подкурсы не разрешены (3 уровня).
const canAddChild = computed(() => isSection.value && props.level < props.maxDepth);
const canAddLesson = computed(() => !isSection.value);

// Drag-and-drop позиция: куда «упадёт» при drop —
//   'before' / 'after' (стать соседом, sibling) или 'into' (стать
//   потомком). Определяется по Y-курсора относительно строки:
//   верхняя треть → before, нижняя → after, середина → into.
const dropPos = ref(null);

function onDragStart(e) {
  e.dataTransfer.effectAllowed = 'move';
  e.dataTransfer.setData('text/plain', String(props.node.id));
}

function onDragOver(e) {
  const draggedId = Number(e.dataTransfer.types.includes('text/plain')
    ? e.dataTransfer.getData('text/plain') : 0);
  if (draggedId === props.node.id) { dropPos.value = null; return; }

  const rect = e.currentTarget.getBoundingClientRect();
  const y = e.clientY - rect.top;
  const h = rect.height;
  if (y < h * 0.3) dropPos.value = 'before';
  else if (y > h * 0.7) dropPos.value = 'after';
  else dropPos.value = 'into';
  e.dataTransfer.dropEffect = 'move';
}

function onDrop(e) {
  const draggedId = Number(e.dataTransfer.getData('text/plain'));
  const pos = dropPos.value;
  dropPos.value = null;
  if (!draggedId || draggedId === props.node.id || !pos) return;
  // Лимит вложенности (3 уровня = Раздел → Курс → Блок). Дроп «into»
  // создаёт ребёнка → запрещаем если target уже на максимальной глубине.
  if (pos === 'into' && props.level >= props.maxDepth) {
    return;
  }
  emit('drop-node', {
    draggedId, targetId: props.node.id, position: pos,
  });
}
</script>

<style scoped>
.node-row {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 6px 6px 4px;
  border-radius: 6px;
  font-size: 13px;
  cursor: pointer;
  user-select: none;
}
.node-row:hover { background: rgba(var(--v-theme-on-surface), 0.05); }
.node-row.selected {
  background: rgba(46, 125, 50, 0.12);
  color: rgb(var(--v-theme-primary));
  font-weight: 600;
}

/* Drag-and-drop visuals */
.node-row.drop-into {
  background: rgba(46, 125, 50, 0.2);
  outline: 2px dashed rgb(var(--v-theme-primary));
  outline-offset: -2px;
}
.node-row.drop-before {
  box-shadow: inset 0 3px 0 rgb(var(--v-theme-primary));
}
.node-row.drop-after {
  box-shadow: inset 0 -3px 0 rgb(var(--v-theme-primary));
}

.twirl { transition: transform 0.15s ease; }
.twirl.open { transform: rotate(90deg); }
.bullet { opacity: 0.35; }
.type-icon { flex-shrink: 0; }
.title-text {
  flex: 1; min-width: 0;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.actions-btn { opacity: 0.5; }
.node-row:hover .actions-btn { opacity: 1; }
</style>
