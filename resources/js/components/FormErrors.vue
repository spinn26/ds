<template>
  <v-alert
    v-if="hasErrors"
    :type="type"
    density="compact"
    variant="tonal"
    closable
    class="mb-2"
    @click:close="$emit('dismiss')"
  >
    <div v-if="message" class="font-weight-medium mb-1">{{ message }}</div>
    <ul v-if="flatErrors.length" class="mb-0 ps-4">
      <li v-for="(err, i) in flatErrors" :key="i" class="text-body-2">{{ err }}</li>
    </ul>
  </v-alert>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  // Laravel's default 422 shape: { message, errors: { field: [msg,msg] } }
  // Or: string | string[] | object {field:[msgs]}.
  errors: { type: [Object, Array, String, null], default: null },
  // Top-level message shown before the list.
  message: { type: String, default: '' },
  type: { type: String, default: 'error' },
});

defineEmits(['dismiss']);

const flatErrors = computed(() => {
  const e = props.errors;
  if (!e) return [];
  if (typeof e === 'string') return [e];
  if (Array.isArray(e)) return e;
  if (typeof e === 'object') {
    const out = [];
    for (const k of Object.keys(e)) {
      const v = e[k];
      if (Array.isArray(v)) out.push(...v);
      else if (typeof v === 'string') out.push(v);
    }
    return out;
  }
  return [];
});

const hasErrors = computed(() => flatErrors.value.length > 0 || props.message);
</script>
