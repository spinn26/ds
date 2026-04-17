<template>
  <v-card :elevation="1" rounded="lg" class="dtw-card">
    <!-- Toolbar -->
    <div v-if="$slots.toolbar || title || searchable" class="dtw-toolbar pa-3 d-flex align-center flex-wrap ga-2">
      <div v-if="title" class="dtw-title">{{ title }}</div>
      <v-text-field
        v-if="searchable"
        v-model="searchProxy"
        :placeholder="searchPlaceholder"
        prepend-inner-icon="mdi-magnify"
        density="compact"
        hide-details
        variant="outlined"
        clearable
        class="dtw-search"
      />
      <v-spacer />
      <slot name="toolbar" />
    </div>

    <!-- Filters slot (chips row) -->
    <div v-if="$slots.filters" class="dtw-filters px-3 pb-2">
      <slot name="filters" />
    </div>

    <!-- Body: skeleton / empty / table -->
    <template v-if="loading && !items.length">
      <v-skeleton-loader
        :type="`table-thead, table-tbody@${skeletonRows}`"
        class="dtw-skeleton"
      />
    </template>

    <template v-else-if="!loading && !items.length">
      <EmptyState
        :icon="emptyIcon"
        :message="emptyMessage"
        :hint="emptyHint"
      >
        <template v-if="$slots.empty" #action>
          <slot name="empty" />
        </template>
      </EmptyState>
    </template>

    <template v-else>
      <v-data-table-server
        v-if="serverSide"
        v-model:page="pageProxy"
        v-model="selectedProxy"
        :show-select="selectable"
        :items-per-page="itemsPerPage"
        :items-length="itemsLength"
        :items="items"
        :headers="headers"
        :loading="loading"
        :density="density"
        :row-props="rowProps"
        :item-value="itemValue"
        return-object
        hover
        class="dtw-table"
        @update:options="$emit('update:options', $event)"
      >
        <template v-for="(_, name) in $slots" #[name]="slotData" :key="name">
          <slot :name="name" v-bind="slotData" />
        </template>
      </v-data-table-server>

      <v-data-table
        v-else
        v-model="selectedProxy"
        :show-select="selectable"
        :items="items"
        :headers="headers"
        :items-per-page="itemsPerPage"
        :density="density"
        :row-props="rowProps"
        :item-value="itemValue"
        return-object
        hover
        class="dtw-table"
      >
        <template v-for="(_, name) in $slots" #[name]="slotData" :key="name">
          <slot :name="name" v-bind="slotData" />
        </template>
      </v-data-table>
    </template>
  </v-card>
</template>

<script setup>
import { computed } from 'vue';
import EmptyState from './EmptyState.vue';

const props = defineProps({
  items: { type: Array, required: true },
  headers: { type: Array, required: true },
  loading: { type: Boolean, default: false },
  title: { type: String, default: null },

  // Server-side pagination
  serverSide: { type: Boolean, default: false },
  page: { type: Number, default: 1 },
  itemsPerPage: { type: Number, default: 25 },
  itemsLength: { type: Number, default: 0 },

  // Search
  searchable: { type: Boolean, default: false },
  search: { type: String, default: '' },
  searchPlaceholder: { type: String, default: 'Поиск…' },

  // Density
  density: { type: String, default: 'comfortable' },

  // Empty state
  emptyIcon: { type: String, default: 'mdi-file-search-outline' },
  emptyMessage: { type: String, default: 'Ничего не найдено' },
  emptyHint: { type: String, default: null },

  // Row class / props
  rowProps: { type: Function, default: null },

  // Skeleton rows count
  skeletonRows: { type: Number, default: 6 },

  // Selection
  selectable: { type: Boolean, default: false },
  selected: { type: Array, default: () => [] },
  itemValue: { type: String, default: 'id' },
});

const emit = defineEmits(['update:page', 'update:search', 'update:options', 'update:selected']);

const pageProxy = computed({
  get: () => props.page,
  set: (v) => emit('update:page', v),
});
const searchProxy = computed({
  get: () => props.search,
  set: (v) => emit('update:search', v ?? ''),
});
const selectedProxy = computed({
  get: () => props.selected,
  set: (v) => emit('update:selected', v),
});
</script>

<style scoped>
.dtw-card { overflow: hidden; }
.dtw-toolbar { border-bottom: 1px solid rgba(var(--v-border-color), 0.15); }
.dtw-title { font-size: 14px; font-weight: 700; }
.dtw-search { max-width: 320px; min-width: 200px; }
.dtw-filters { border-bottom: 1px solid rgba(var(--v-border-color), 0.1); }
.dtw-skeleton :deep(.v-skeleton-loader__table-thead) { padding: 12px 16px; }
.dtw-skeleton :deep(.v-skeleton-loader__table-tbody) { padding: 0 16px; }
.dtw-table :deep(.v-data-table__td) { font-variant-numeric: tabular-nums; }
.dtw-table :deep(.v-data-table__tr:hover .v-data-table__td) { background: rgba(var(--v-theme-primary), 0.03); }
</style>
