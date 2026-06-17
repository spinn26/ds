<template>
  <v-autocomplete
    :model-value="modelValue"
    :items="items"
    :loading="loading"
    :multiple="multiple"
    :chips="multiple"
    :closable-chips="multiple"
    item-title="name"
    item-value="id"
    :label="label"
    :placeholder="placeholder"
    density="compact"
    variant="outlined"
    hide-details
    clearable
    no-filter
    :return-object="false"
    @update:model-value="$emit('update:modelValue', $event)"
    @update:search="onSearch"
  >
    <template #item="{ props, item }">
      <v-list-item v-bind="props" :title="item.raw.name" :subtitle="item.raw.email || item.raw.role" />
    </template>
  </v-autocomplete>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api';
import { useDebounce } from '../composables/useDebounce';

const props = defineProps({
  modelValue: { type: [Number, Array, null], default: null },
  multiple: { type: Boolean, default: false },
  label: { type: String, default: 'Пользователь' },
  placeholder: { type: String, default: 'Имя или email' },
  // Предзагруженные опции (чтобы выбранные id отображались именами).
  preload: { type: Array, default: () => [] },
});
defineEmits(['update:modelValue']);

// results — текущая выдача поиска; cache — все когда-либо виденные опции
// (для отображения имён выбранных чипов, которых нет в текущей выдаче).
const results = ref([...props.preload]);
const cache = new Map(props.preload.map((u) => [u.id, u]));
const loading = ref(false);

const selectedIds = computed(() => (Array.isArray(props.modelValue)
  ? props.modelValue
  : (props.modelValue != null ? [props.modelValue] : [])));

// Список для дропдауна: результаты поиска + выбранные (из кэша), чтобы
// чипы/значение не теряли подписи. Без накопления старых результатов.
const items = computed(() => {
  const map = new Map(results.value.map((u) => [u.id, u]));
  for (const id of selectedIds.value) {
    if (!map.has(id) && cache.has(id)) map.set(id, cache.get(id));
  }
  return [...map.values()];
});

async function fetchUsers(search) {
  loading.value = true;
  try {
    const { data } = await api.get('/tasks/assignable-users', { params: { search: search || undefined } });
    results.value = data.users || [];
    results.value.forEach((u) => cache.set(u.id, u));
  } catch { /* ignore */ }
  loading.value = false;
}

const { debounced } = useDebounce((s) => fetchUsers(s), 300);
// Игнорируем пустую строку поиска, когда уже что-то выбрано (Vuetify шлёт
// '' после выбора — иначе сбрасывали бы выдачу без причины).
function onSearch(s) { debounced(s || ''); }

onMounted(() => { fetchUsers(''); });
</script>
