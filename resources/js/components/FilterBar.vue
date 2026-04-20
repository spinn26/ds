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

        <v-col v-if="showReset" cols="auto" class="ms-auto">
          <v-btn
            variant="text"
            size="small"
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
defineProps({
  search: { type: String, default: undefined },
  searchPlaceholder: { type: String, default: 'Поиск…' },
  searchCols: { type: [String, Number], default: 4 },

  showReset: { type: Boolean, default: false },
  resetText: { type: String, default: 'Сбросить' },

  elevation: { type: [String, Number], default: 1 },
});

defineEmits(['update:search', 'reset']);
</script>
