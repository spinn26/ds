<template>
  <v-btn variant="tonal" size="small" prepend-icon="mdi-microsoft-excel" :loading="exporting" @click="doExport">
    Экспорт Excel
  </v-btn>
</template>

<script setup>
import { ref } from 'vue';
import api from '../api';

const props = defineProps({
  type: { type: String, required: true },
  params: { type: Object, default: () => ({}) },
});

const exporting = ref(false);

async function doExport() {
  exporting.value = true;
  try {
    const queryString = new URLSearchParams(props.params).toString();
    const url = `/admin/export/${props.type}${queryString ? '?' + queryString : ''}`;
    const response = await api.get(url, { responseType: 'blob' });

    const blob = new Blob([response.data], {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `ds_${props.type}_${new Date().toISOString().slice(0, 10)}.xlsx`;
    link.click();
    URL.revokeObjectURL(link.href);
  } catch {}
  exporting.value = false;
}
</script>
