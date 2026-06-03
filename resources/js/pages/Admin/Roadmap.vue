<template>
  <div>
    <PageHeader title="Роадмап продукта" icon="mdi-map-marker-path">
      <template #actions>
        <v-btn
          variant="text"
          color="grey-lighten-1"
          prepend-icon="mdi-open-in-new"
          href="/roadmap"
          target="_blank"
          class="mr-2"
        >
          Публичная страница
        </v-btn>
        <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreate">Добавить</v-btn>
      </template>
    </PageHeader>

    <v-alert
      type="info"
      variant="tonal"
      density="compact"
      class="mb-3"
      icon="mdi-link-variant"
    >
      Публичная ссылка: <code>/roadmap</code> — открывается без авторизации.
      Видны только записи с галочкой «Опубликована».
    </v-alert>

    <v-card class="mb-3 pa-3">
      <div class="d-flex flex-wrap ga-2 align-center">
        <v-select
          v-model="filterCategory"
          :items="categoryOptions"
          placeholder="Тег (категория)"
          prepend-inner-icon="mdi-tag-outline"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          style="max-width: 240px; flex: 1 1 200px"
        />
        <v-select
          v-model="filterStatus"
          :items="statusFilterOptions"
          placeholder="Статус"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          style="max-width: 180px; flex: 1 1 140px"
        />
        <v-chip v-if="filterCategory || filterStatus" size="small" color="info" variant="tonal">
          {{ filteredItems.length }} из {{ items.length }}
        </v-chip>
        <v-spacer />
        <v-btn
          v-if="filterCategory || filterStatus"
          size="small"
          variant="text"
          color="secondary"
          prepend-icon="mdi-filter-remove"
          @click="filterCategory = null; filterStatus = null"
        >
          Сбросить
        </v-btn>
      </div>
    </v-card>

    <v-card :loading="loading">
      <v-data-table :items="filteredItems" :headers="headers" density="compact" hover>
        <template #item.status="{ value }">
          <v-chip :color="statusColor(value)" size="x-small" variant="tonal" label>
            {{ statusLabel(value) }}
          </v-chip>
        </template>
        <template #item.published="{ value }">
          <BooleanCell :value="!!value" :tooltip="{ on: 'Видна на /roadmap', off: 'Скрыта' }" />
        </template>
        <template #item.released_at="{ value }">
          {{ value ? fmtDate(value) : '—' }}
        </template>
        <template #item.description="{ value }">
          <span class="text-body-2 text-medium-emphasis">
            {{ (value || '').length > 80 ? (value || '').slice(0, 80) + '…' : (value || '—') }}
          </span>
        </template>
        <template #item.actions="{ item }">
          <ActionsCell @edit="openEdit(item)" @delete="confirmDelete(item)" />
        </template>
        <template #no-data>
          <EmptyState message="Записей пока нет — добавьте первую" icon="mdi-rocket-launch-outline" />
        </template>
      </v-data-table>
    </v-card>

    <DialogShell
      v-model="editDialog"
      :title="editForm.id ? 'Редактировать запись' : 'Новая запись роадмапа'"
      :max-width="640"
      persistent
      :loading="saving"
      :confirm-disabled="!editForm.title"
      :confirm-text="editForm.id ? 'Сохранить' : 'Создать'"
      @confirm="save"
    >
      <FormErrors :errors="editErrors" :message="editMessage" />

      <v-text-field
        v-model="editForm.title"
        label="Заголовок *"
        variant="outlined"
        density="compact"
        class="mb-3"
      />

      <v-textarea
        v-model="editForm.description"
        label="Описание"
        variant="outlined"
        density="compact"
        rows="4"
        auto-grow
        class="mb-3"
        hint="Можно использовать переносы строк. Поддержки markdown пока нет."
      />

      <div class="d-flex ga-3 mb-3">
        <v-select
          v-model="editForm.status"
          :items="statusOptions"
          label="Статус"
          variant="outlined"
          density="compact"
          style="flex: 1"
        />
        <v-text-field
          v-model="editForm.category"
          label="Категория"
          placeholder="Платформа, Мобильное, …"
          variant="outlined"
          density="compact"
          style="flex: 1"
        />
      </div>

      <div class="d-flex ga-3 mb-3">
        <v-text-field
          v-model="editForm.icon"
          label="MDI-иконка"
          placeholder="mdi-rocket"
          prepend-inner-icon="mdi-emoticon-outline"
          variant="outlined"
          density="compact"
          style="flex: 1"
        />
        <v-text-field
          v-model="editForm.released_at"
          label="Дата выпуска (опц.)"
          type="date"
          variant="outlined"
          density="compact"
          style="flex: 1"
        />
      </div>

      <div class="d-flex ga-3 align-center">
        <v-text-field
          v-model.number="editForm.sort_order"
          label="Порядок"
          type="number"
          variant="outlined"
          density="compact"
          style="max-width: 140px"
        />
        <v-checkbox v-model="editForm.published" label="Опубликована" density="compact" hide-details />
      </div>
    </DialogShell>

    <DialogShell
      v-model="deleteDialog"
      title="Удалить запись?"
      :max-width="400"
      :loading="saving"
      confirm-text="Удалить"
      confirm-color="error"
      @confirm="remove"
    >
      «{{ deleteTarget?.title }}» — действие нельзя отменить.
    </DialogShell>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import {
  PageHeader, DialogShell, BooleanCell, ActionsCell, FormErrors, EmptyState,
} from '../../components';
import { useCrud } from '../../composables/useCrud';
import { fmtDate } from '../../composables/useDesign';

const {
  items, loading,
  editDialog, editForm, editErrors, editMessage, saving,
  deleteDialog, deleteTarget,
  load, openCreate, openEdit, save, confirmDelete, remove,
} = useCrud('admin/roadmap', {
  defaults: {
    title: '', description: '', status: 'planned',
    category: '', icon: '', released_at: null,
    sort_order: 0, published: true,
  },
  normalise: (d) => ({
    items: d.items ?? d.data ?? [],
    total: d.total ?? (d.items ?? []).length,
  }),
  labels: {
    created: 'Запись добавлена',
    updated: 'Запись обновлена',
    deleted: 'Запись удалена',
    error: 'Ошибка',
  },
});

const statusOptions = [
  { title: 'В планах', value: 'planned' },
  { title: 'В работе', value: 'in_progress' },
  { title: 'Выпущено', value: 'shipped' },
];
const statusFilterOptions = statusOptions;

// Фильтры таблицы (тег + статус). Категории собираем из самих записей.
const filterCategory = ref(null);
const filterStatus = ref(null);

const categoryOptions = computed(() =>
  [...new Set(items.value.map(i => i.category).filter(Boolean))]
    .sort((a, b) => a.localeCompare(b, 'ru'))
);

const filteredItems = computed(() =>
  items.value.filter(i =>
    (!filterCategory.value || i.category === filterCategory.value)
    && (!filterStatus.value || i.status === filterStatus.value))
);

function statusLabel(s) {
  return ({ planned: 'В планах', in_progress: 'В работе', shipped: 'Выпущено' })[s] || s;
}

function statusColor(s) {
  return ({ planned: 'grey', in_progress: 'primary', shipped: 'success' })[s] || 'grey';
}

const headers = [
  { title: '#', key: 'sort_order', width: 60 },
  { title: 'Заголовок', key: 'title' },
  { title: 'Описание', key: 'description' },
  { title: 'Статус', key: 'status', width: 120 },
  { title: 'Категория', key: 'category', width: 140 },
  { title: 'Выпуск', key: 'released_at', width: 120 },
  { title: 'Видна', key: 'published', width: 80 },
  { title: '', key: 'actions', sortable: false, width: 80 },
];

onMounted(load);
</script>
