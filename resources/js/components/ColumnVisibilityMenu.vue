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
    <v-list density="compact" style="min-width: 240px" max-height="400">
      <v-list-subheader>Видимые колонки</v-list-subheader>
      <v-list-item v-for="col in toggleableColumns" :key="col.key">
        <template #prepend>
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
    </v-list>
  </v-menu>
</template>

<script setup>
import { computed, watch, onMounted } from 'vue';
import { useColumnPrefsStore } from '@/stores/columnPrefs';

const prefs = useColumnPrefsStore();

const props = defineProps({
  /** Исходные headers таблицы — как в v-data-table. */
  headers: { type: Array, required: true },

  /**
   * v-model:visible = { key: bool } объект. Родитель применяет его к
   * computed headers, фильтруя по visible[key] !== false.
   */
  visible: { type: Object, default: () => ({}) },

  /**
   * Ключ для localStorage — если передан, состояние persistется.
   * Например: 'partners-cols', 'clients-cols'.
   */
  storageKey: { type: String, default: null },

  /**
   * Ключи колонок, которые нельзя скрыть (например, actions или
   * главный идентификатор). По умолчанию — только 'actions'.
   */
  alwaysVisible: { type: Array, default: () => ['actions'] },

  size: { type: String, default: 'small' },
  variant: { type: String, default: 'text' },
  color: { type: String, default: undefined },
});

const emit = defineEmits(['update:visible']);

const toggleableColumns = computed(() =>
  // Игнорируем колонки без `title` — это служебные (actions, chat,
  // expand-toggle и т.п.), пользователь их прятать не должен.
  // Также alwaysVisible — явный whitelist.
  props.headers.filter(h => h.key
    && h.title && String(h.title).trim() !== ''
    && !props.alwaysVisible.includes(h.key))
);

const hiddenCount = computed(() =>
  toggleableColumns.value.filter(c => !isVisible(c.key)).length
);

function isVisible(key) {
  // Если ключ не присутствует в visible — считаем что показан (default).
  if (!(key in props.visible)) return true;
  return props.visible[key] !== false;
}

function toggle(key, v) {
  const next = { ...props.visible, [key]: !!v };
  emit('update:visible', next);
  persist(next);
}

function showAll() {
  const next = {};
  toggleableColumns.value.forEach(c => { next[c.key] = true; });
  emit('update:visible', next);
  persist(next);
}

function persist(state) {
  if (!props.storageKey) return;
  prefs.save(props.storageKey, state);
}

onMounted(() => {
  if (!props.storageKey) return;
  // Сначала читаем по новой схеме (per-user). Если пусто — fallback
  // на старый ключ `cols:${storageKey}`, разово мигрируем в новый.
  const loaded = prefs.load(props.storageKey);
  if (loaded) {
    emit('update:visible', loaded);
    return;
  }
  try {
    const legacy = localStorage.getItem(`cols:${props.storageKey}`);
    if (legacy) {
      const parsed = JSON.parse(legacy);
      emit('update:visible', parsed);
      prefs.save(props.storageKey, parsed);
      localStorage.removeItem(`cols:${props.storageKey}`);
    }
  } catch {}
});
</script>
