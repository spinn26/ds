<template>
  <div class="d-inline-flex ga-1">
    <v-btn
      v-if="editable"
      :icon="editIcon"
      :size="size"
      :color="editColor"
      variant="text"
      :title="editTitle"
      @click.stop="$emit('edit')"
    />
    <v-btn
      v-if="deletable"
      :icon="deleteIcon"
      :size="size"
      color="error"
      variant="text"
      :title="deleteTitle"
      @click.stop="$emit('delete')"
    />
    <slot />
  </div>
</template>

<script setup>
defineProps({
  editable: { type: Boolean, default: true },
  deletable: { type: Boolean, default: true },
  size: { type: String, default: 'x-small' },
  editIcon: { type: String, default: 'mdi-pencil' },
  deleteIcon: { type: String, default: 'mdi-delete' },
  editColor: { type: String, default: undefined },
  editTitle: { type: String, default: 'Редактировать' },
  deleteTitle: { type: String, default: 'Удалить' },
});
defineEmits(['edit', 'delete']);
</script>
