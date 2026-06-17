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
import { ref, onMounted } from 'vue';
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

const items = ref([...props.preload]);
const loading = ref(false);

async function fetchUsers(search) {
  loading.value = true;
  try {
    const { data } = await api.get('/tasks/assignable-users', { params: { search: search || undefined } });
    // Сохраняем уже выбранные опции, чтобы чипы не теряли имена.
    const byId = new Map(items.value.map((u) => [u.id, u]));
    (data.users || []).forEach((u) => byId.set(u.id, u));
    items.value = [...byId.values()];
  } catch { /* ignore */ }
  loading.value = false;
}

const { debounced } = useDebounce((s) => fetchUsers(s), 300);
function onSearch(s) { debounced(s); }

onMounted(() => { if (!items.value.length) fetchUsers(''); });
</script>
