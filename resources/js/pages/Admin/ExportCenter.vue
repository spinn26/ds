<template>
  <div>
    <PageHeader title="Центр экспорта" icon="mdi-database-export" />

    <v-alert type="info" variant="tonal" density="comfortable" class="mb-3">
      Выгрузка данных в стилизованный XLSX (до 5000 строк). Можно указать поиск
      для сущностей, где он поддерживается.
    </v-alert>

    <v-row dense>
      <v-col v-for="t in types" :key="t.type" cols="12" sm="6" md="4">
        <v-card class="pa-3 h-100 d-flex flex-column">
          <div class="d-flex align-center ga-2 mb-2">
            <v-icon :icon="t.icon" color="primary" />
            <div class="text-subtitle-2 font-weight-bold">{{ t.label }}</div>
          </div>
          <v-text-field v-if="t.search" v-model="searchByType[t.type]" placeholder="Поиск (необязательно)"
            density="compact" variant="outlined" hide-details class="mb-2" />
          <v-spacer />
          <v-btn color="primary" variant="tonal" size="small" prepend-icon="mdi-microsoft-excel"
            :loading="busy === t.type" block @click="download(t)">Скачать XLSX</v-btn>
        </v-card>
      </v-col>
    </v-row>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="3500">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import api from '../../api';
import { PageHeader } from '../../components';

const types = [
  { type: 'partners', label: 'Партнёры', icon: 'mdi-account-group', search: true },
  { type: 'clients', label: 'Клиенты', icon: 'mdi-account-multiple', search: true },
  { type: 'contracts', label: 'Контракты', icon: 'mdi-file-document', search: true },
  { type: 'transactions', label: 'Транзакции', icon: 'mdi-swap-horizontal', search: false },
  { type: 'commissions', label: 'Комиссии', icon: 'mdi-receipt', search: false },
  { type: 'qualifications', label: 'Квалификации', icon: 'mdi-chart-bar', search: false },
  { type: 'payments', label: 'Выплаты', icon: 'mdi-cash', search: false },
];

const busy = ref('');
const searchByType = reactive({});
const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

async function download(t) {
  busy.value = t.type;
  try {
    const params = {};
    if (t.search && searchByType[t.type]) params.search = searchByType[t.type];
    const res = await api.get(`/admin/export/${t.type}`, { params, responseType: 'blob' });
    const url = URL.createObjectURL(new Blob([res.data], {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    }));
    const a = document.createElement('a');
    a.href = url;
    a.download = `ds_export_${t.type}_${new Date().toISOString().slice(0, 10)}.xlsx`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка экспорта', 'error');
  }
  busy.value = '';
}
</script>
