<template>
  <div>
    <PageHeader title="Клиенты" icon="mdi-account-group" :count="total" />

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="search" placeholder="Поиск по ФИО, телефону, email..."
          rounded prepend-inner-icon="mdi-magnify" clearable hide-details style="max-width:300px" @update:model-value="debouncedLoad" />
        <v-chip v-if="search" size="small" color="info" variant="tonal" class="ml-1">1 фильтр</v-chip>
        <v-btn v-if="search" size="small" variant="text" color="secondary"
          prepend-icon="mdi-filter-remove" @click="search = ''; loadData()">Сбросить</v-btn>
      </div>
    </v-card>

    <v-data-table-server :items="items" :items-length="total" :loading="loading"
      :headers="headers" :items-per-page="25" @update:options="onOptions">
      <template #item.isPartner="{ value }">
        <v-icon v-if="value" color="success" size="small">mdi-check-circle</v-icon>
      </template>
      <template #item.products="{ value }">
        <v-chip v-for="p in (value || [])" :key="p" size="x-small" class="mr-1" color="primary" variant="outlined">{{ p }}</v-chip>
      </template>
      <template #item.chat="{ item }">
        <StartChatButton :partner-id="item.consultantId || item.consultant" :partner-name="item.consultantName"
          context-type="Клиент" :context-id="item.id" :context-label="item.personName || '#' + item.id" />
      </template>
      <template #item.birthDate="{ value }">
        {{ fmtDate(value) }}
      </template>
      <template #no-data><EmptyState /></template>
    </v-data-table-server>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import StartChatButton from '../../components/StartChatButton.vue';
import { fmtDate } from '../../composables/useDesign';

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const page = ref(1);
const perPage = ref(25);

const headers = [
  { title: 'ФИО', key: 'personName' },
  { title: 'Email', key: 'email' },
  { title: 'Телефон', key: 'phone' },
  { title: 'Дата рождения', key: 'birthDate', width: 130 },
  { title: 'Город', key: 'city' },
  { title: 'Работаем с', key: 'workSince', width: 130 },
  { title: 'Контракты', key: 'contractCount', width: 110, align: 'end' },
  { title: 'Партнёр?', key: 'isPartner', width: 90, sortable: false },
  { title: 'Консультант', key: 'consultantName' },
  { title: 'Комментарий', key: 'comment' },
  { title: 'Продукты', key: 'products', sortable: false },
  { title: '', key: 'chat', sortable: false, width: 50 },
];

const { debounced: debouncedLoad } = useDebounce(loadData, 400);

function onOptions(opts) {
  page.value = opts.page;
  if (opts.itemsPerPage) perPage.value = opts.itemsPerPage;
  loadData();
}

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value };
    if (search.value) params.search = search.value;
    const { data } = await api.get('/admin/clients', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

onMounted(loadData);
</script>
