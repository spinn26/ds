<template>
  <div>
    <PageHeader title="Новости и объявления" icon="mdi-newspaper">
      <template #actions>
        <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreate">Добавить</v-btn>
      </template>
    </PageHeader>

    <v-card :loading="loading">
      <div class="d-flex justify-end px-3 pt-2">
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="news-cols" />
      </div>
      <v-data-table :items="items" :headers="visibleHeaders" density="compact" hover>
        <template #item.type="{ value }">
          <StatusChip
            :color="value === 'warning' ? 'warning' : value === 'success' ? 'success' : 'primary'"
            :text="{ info: 'Инфо', warning: 'Важно', success: 'Успех' }[value] || value"
            size="x-small"
          />
        </template>
        <template #item.active="{ value }">
          <BooleanCell :value="!!value" :tooltip="{ on: 'Активна', off: 'Скрыта' }" />
        </template>
        <template #item.created_at="{ value }">{{ fmtDate(value) }}</template>
        <template #item.content="{ value }">
          <span class="text-body-2">{{ value?.length > 80 ? value.slice(0, 80) + '...' : value }}</span>
        </template>
        <template #item.actions="{ item }">
          <ActionsCell @edit="openEdit(item)" @delete="confirmDelete(item)" />
        </template>
        <template #no-data>
          <EmptyState message="Нет новостей" icon="mdi-newspaper-variant-outline" />
        </template>
      </v-data-table>
    </v-card>

    <DialogShell
      v-model="editDialog"
      :title="editForm.id ? `Редактировать новость${editForm.title ? ` «${editForm.title}»` : ''}` : 'Новая новость'"
      :max-width="720"
      persistent
      :loading="saving"
      :confirm-disabled="!editForm.title || !editForm.content"
      :confirm-text="editForm.id ? 'Сохранить' : 'Создать'"
      @confirm="save"
    >
      <FormErrors :errors="editErrors" :message="editMessage" />
      <v-text-field v-model="editForm.title" label="Заголовок *" variant="outlined" density="compact" class="mb-3" />
      <div class="text-caption text-medium-emphasis mb-1">Содержание *</div>
      <RichTextEditor v-model="editForm.content" min-height="260px" />
      <v-select v-model="editForm.type" :items="typeOptions" label="Тип" variant="outlined" density="compact" class="mt-3 mb-3" />
      <v-checkbox v-model="editForm.active" label="Активна (видна всем)" density="compact" />
    </DialogShell>

    <DialogShell
      v-model="deleteDialog"
      title="Удалить новость?"
      :max-width="400"
      :loading="saving"
      confirm-text="Удалить"
      confirm-color="error"
      @confirm="remove"
    >
      {{ deleteTarget?.title }}
    </DialogShell>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import {
  PageHeader, DialogShell, StatusChip, BooleanCell, ActionsCell, FormErrors, RichTextEditor, ColumnVisibilityMenu, EmptyState,
} from '../../components';
import { useCrud } from '../../composables/useCrud';
import { fmtDate } from '../../composables/useDesign';

const {
  items, loading,
  editDialog, editForm, editErrors, editMessage, saving,
  deleteDialog, deleteTarget,
  load, openCreate, openEdit, save, confirmDelete, remove,
} = useCrud('admin/news', {
  defaults: { title: '', content: '', type: 'info', active: true },
  normalise: (d) => ({
    items: Array.isArray(d) ? d : (d.items ?? d.data ?? []),
    total: Array.isArray(d) ? d.length : (d.total ?? 0),
  }),
  labels: {
    created: 'Новость создана',
    updated: 'Новость обновлена',
    deleted: 'Новость удалена',
    error: 'Ошибка',
  },
});

const typeOptions = [
  { title: 'Информация', value: 'info' },
  { title: 'Важное', value: 'warning' },
  { title: 'Успех', value: 'success' },
];

const headers = [
  { title: 'Заголовок', key: 'title' },
  { title: 'Содержание', key: 'content' },
  { title: 'Тип', key: 'type', width: 100 },
  { title: 'Активна', key: 'active', width: 90 },
  { title: 'Дата', key: 'created_at', width: 120 },
  { title: '', key: 'actions', sortable: false, width: 80 },
];

const columnVisible = ref({});
const visibleHeaders = computed(() => headers.filter(h => columnVisible.value[h.key] !== false));

onMounted(load);
</script>
