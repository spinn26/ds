<template>
  <v-card :elevation="elevation" class="mb-4">
    <v-card-text class="pa-3">
      <v-row dense align="center">
        <!-- Search slot: always first when provided -->
        <v-col v-if="$slots.search || search !== undefined" cols="12" :md="searchCols">
          <slot name="search">
            <v-text-field
              :model-value="search"
              :placeholder="searchPlaceholder"
              prepend-inner-icon="mdi-magnify"
              variant="outlined"
              density="comfortable"
              hide-details
              clearable
              @update:model-value="$emit('update:search', $event)"
            />
          </slot>
        </v-col>

        <slot />

        <!-- Чип-сводка по активным фильтрам — единый стиль на всех страницах. -->
        <v-col v-if="activeCount > 0" cols="auto" class="d-flex align-center">
          <v-chip size="small" color="info" variant="tonal">
            <v-icon size="14" start>mdi-filter</v-icon>
            {{ activeCount }} {{ countLabel }}
          </v-chip>
        </v-col>

        <v-col v-if="showReset || activeCount > 0" cols="auto" class="ms-auto">
          <v-btn
            v-if="activeCount > 0 || showReset"
            variant="text"
            size="small"
            color="secondary"
            prepend-icon="mdi-filter-off-outline"
            @click="$emit('reset')"
          >
            {{ resetText }}
          </v-btn>
        </v-col>

        <v-col v-if="$slots.actions" cols="auto">
          <slot name="actions" />
        </v-col>
      </v-row>
    </v-card-text>
  </v-card>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  search: { type: String, default: undefined },
  searchPlaceholder: { type: String, default: 'Поиск…' },
  searchCols: { type: [String, Number], default: 4 },

  /**
   * Количество активных фильтров — рендерит чип «N фильтров»
   * с правильным числом (1 фильтр / 2 фильтра / 5 фильтров).
   * Чтобы каждая страница не дублировала эту логику.
   */
  activeCount: { type: Number, default: 0 },

  showReset: { type: Boolean, default: false },
  resetText: { type: String, default: 'Сбросить' },

  elevation: { type: [String, Number], default: 1 },
});

defineEmits(['update:search', 'reset']);

const countLabel = computed(() => {
  const n = props.activeCount;
  if (n === 1) return 'фильтр';
  if (n >= 2 && n <= 4) return 'фильтра';
  return 'фильтров';
});
</script>
