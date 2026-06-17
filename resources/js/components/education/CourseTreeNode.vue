<template>
  <div class="tree-node">
    <div
      class="tree-row"
      :class="{ 'is-current': isCurrent, 'is-locked': isLocked }"
      :style="{ paddingLeft: (level - 1) * 12 + 'px' }"
      @click="onClick"
    >
      <v-icon
        v-if="hasChildren"
        size="14"
        class="tree-twirl"
        :class="{ open: expanded }"
        @click.stop="expanded = !expanded"
      >
        mdi-chevron-right
      </v-icon>
      <v-icon v-else size="6" class="tree-bullet">mdi-circle-small</v-icon>

      <v-icon size="14" :color="statusColor" class="tree-status">
        {{ statusIcon }}
      </v-icon>

      <span class="tree-label">{{ node.title }}</span>

      <span
        v-if="node.lessonCount && progress < 100 && !passed"
        class="tree-mini tabular-nums"
      >
        {{ progress }}%
      </span>
    </div>

    <div v-if="hasChildren && expanded" class="tree-children">
      <CourseTreeNode
        v-for="child in node.children"
        :key="child.id"
        :node="child"
        :current-id="currentId"
        :level="level + 1"
        @navigate="(id) => $emit('navigate', id)"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useEducationStore } from '../../stores/education';

const props = defineProps({
  node: { type: Object, required: true },
  currentId: { type: [String, Number], default: null },
  level: { type: Number, default: 1 },
});
const emit = defineEmits(['navigate']);

const edu = useEducationStore();

const expanded = ref(props.level <= 2); // первые 2 уровня раскрыты по умолчанию
const hasChildren = computed(() => (props.node.children?.length || 0) > 0);
const isCurrent = computed(() => Number(props.currentId) === props.node.id);
const isLocked = computed(() => false); // drip pending — пока всегда открыто

function aggregate(n) {
  let total = n.lessonCount || 0;
  let viewed = n.lessonViewed || 0;
  for (const c of n.children || []) {
    const s = aggregate(c);
    total += s.total;
    viewed += s.viewed;
  }
  return { total, viewed };
}

const stats = computed(() => aggregate(props.node));
const progress = computed(() =>
  stats.value.total ? Math.round((stats.value.viewed / stats.value.total) * 100) : 0
);

// «Пройден» = тест сдан (единый критерий со всей платформой). Раньше статус
// в дереве считался ТОЛЬКО по просмотру уроков → курс со сданным тестом, но
// не все уроки открыты, показывался «не пройден», хотя в самом курсе —
// «Тест сдан». Учитываем серверный testPassed + оптимистичный стор.
const passed = computed(() => !!props.node.testPassed || edu.isPassed(props.node.id));
const done = computed(() => passed.value || (progress.value === 100 && stats.value.total > 0));

const statusIcon = computed(() => {
  if (done.value) return 'mdi-check-circle';
  if (progress.value > 0) return 'mdi-circle-slice-4';
  return 'mdi-circle-outline';
});
const statusColor = computed(() => {
  if (done.value) return 'success';
  if (progress.value > 0) return 'warning';
  return 'grey-lighten-1';
});

function onClick() {
  if (hasChildren.value) expanded.value = !expanded.value;
  emit('navigate', props.node.id);
}
</script>

<style scoped>
.tree-row {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 8px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 13px;
  line-height: 1.3;
  user-select: none;
  transition: background 0.1s ease;
}
.tree-row:hover { background: rgba(var(--v-theme-on-surface), 0.04); }
.tree-row.is-current {
  background: rgba(46, 125, 50, 0.1);
  color: rgb(var(--v-theme-primary));
  font-weight: 600;
}
.tree-row.is-locked { opacity: 0.5; cursor: not-allowed; }

.tree-twirl {
  transition: transform 0.15s ease;
}
.tree-twirl.open { transform: rotate(90deg); }
.tree-bullet { opacity: 0.4; }

.tree-label {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.tree-mini {
  font-size: 10.5px;
  color: rgba(var(--v-theme-on-surface), 0.55);
  font-variant-numeric: tabular-nums;
}

.tabular-nums { font-variant-numeric: tabular-nums; }
</style>
