<template>
  <div class="node">
    <div
      class="node-row"
      :class="{ selected: isSelected }"
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
        :color="node.isContainer ? 'amber' : 'primary'"
        class="type-icon"
      >
        {{ node.isContainer ? 'mdi-folder-outline' : 'mdi-book-open-variant' }}
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
          <v-list-item prepend-icon="mdi-folder-plus" @click="$emit('add-child', node)">
            <v-list-item-title>+ Подкурс / модуль</v-list-item-title>
          </v-list-item>
          <v-list-item prepend-icon="mdi-text-box-plus-outline" @click="$emit('add-lesson', node)">
            <v-list-item-title>+ Урок</v-list-item-title>
          </v-list-item>
          <v-divider />
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
        @select="(n) => $emit('select', n)"
        @add-child="(n) => $emit('add-child', n)"
        @add-lesson="(n) => $emit('add-lesson', n)"
        @move-up="(n) => $emit('move-up', n)"
        @move-down="(n) => $emit('move-down', n)"
        @delete="(n) => $emit('delete', n)"
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
});
defineEmits(['select', 'add-child', 'add-lesson', 'delete', 'move-up', 'move-down']);

const expanded = ref(props.level <= 2);
const hasChildren = computed(() => (props.node.children?.length || 0) > 0);
const isSelected = computed(() => Number(props.selectedId) === props.node.id);
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
