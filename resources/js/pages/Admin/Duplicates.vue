<template>
  <div>
    <PageHeader title="Дубли и связки" icon="mdi-account-multiple-remove" />


    <!-- ПАРТНЁРЫ -->
    <div>
      <v-card class="ds-card mb-3 pa-3" elevation="0">
        <div class="d-flex ga-3 align-center flex-wrap">
          <v-btn-toggle v-model="partnerBy" density="compact" color="primary" mandatory
            @update:model-value="loadPartners">
            <v-btn value="fio" size="small">По ФИО</v-btn>
            <v-btn value="phone" size="small">По телефону</v-btn>
          </v-btn-toggle>
          <div class="text-caption text-medium-emphasis">
            Выберите, какая запись остаётся. Вторая уйдёт в мягкое удаление, всё с неё
            перейдёт на выбранную. Суммы не меняются — это один человек.
          </div>
        </div>
      </v-card>

      <v-alert v-if="!loading && !partnerGroups.length" type="success" variant="tonal" density="compact">
        Дублей не найдено.
      </v-alert>

      <v-card v-for="g in partnerGroups" :key="g.key" class="ds-card mb-3" elevation="0">
        <div class="ds-card__head d-flex align-center ga-2">
          <v-icon size="18" color="warning">mdi-account-alert</v-icon>
          <span class="ds-title-l">{{ g.key }}</span>
        </div>
        <v-table density="compact">
          <thead>
            <tr>
              <th>ID</th><th>Статус</th><th>Логин</th><th>Контакты</th>
              <th class="text-end">Контракты</th><th class="text-end">Клиенты</th>
              <th class="text-end">Ниже</th><th class="text-end">Комиссии</th>
              <th class="text-end">Остаток</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in g.records" :key="r.id">
              <td>{{ r.id }}</td>
              <td><StatusChip size="x-small" :text="activityName(r.activityId)" :color="activityColor(r.activityId)" /></td>
              <td>
                <v-icon v-if="r.hasLogin" size="16" color="success">mdi-check</v-icon>
                <span v-else class="text-medium-emphasis">—</span>
              </td>
              <td class="text-caption">
                {{ r.email || '—' }}<br>{{ r.phone || '—' }}
              </td>
              <td class="text-end">{{ r.contracts }}</td>
              <td class="text-end">{{ r.clients }}</td>
              <td class="text-end">{{ r.downline }}</td>
              <td class="text-end">{{ r.commissions }}</td>
              <td class="text-end" :class="r.remaining ? 'font-weight-bold' : 'text-medium-emphasis'">
                {{ fmt(r.remaining) }} ₽
              </td>
              <td class="text-end">
                <v-btn size="x-small" variant="tonal" color="primary"
                  :disabled="g.records.length !== 2"
                  @click="openMerge(g, r)">Оставить эту</v-btn>
              </td>
            </tr>
          </tbody>
        </v-table>
        <div v-if="g.records.length !== 2" class="pa-2 text-caption text-medium-emphasis">
          В группе больше двух записей — сливайте по одной паре через поддержку.
        </div>
      </v-card>
    </div>

    <!-- Подтверждение слияния -->
    <v-dialog v-model="mergeOpen" max-width="560" persistent>
      <v-card v-if="mergePlan">
        <v-card-title>Слить записи партнёра?</v-card-title>
        <v-card-text>
          <div class="text-body-2 mb-2">
            Остаётся <strong>id {{ mergePlan.to }}</strong>, удаляется
            <strong>id {{ mergePlan.from }}</strong>. Всё с удаляемой записи —
            контракты, клиенты, комиссии, баланс, квалификации — перейдёт на остающуюся.
          </div>
          <v-alert type="info" variant="tonal" density="compact" class="mb-2">
            {{ mergePreview?.message }}
          </v-alert>
          <div v-if="mergePreview?.moved" class="text-caption">
            Переносим: {{ describe(mergePreview.moved) }}<br>
            Удаляем пустых строк: {{ describe(mergePreview.deleted) }}
          </div>
          <v-alert v-if="mergeError" type="error" density="compact" class="mt-2">{{ mergeError }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="mergeOpen = false">Отмена</v-btn>
          <v-btn color="primary" :loading="merging" :disabled="!mergePreview?.ok"
            @click="doMerge">Слить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="5000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import StatusChip from '../../components/StatusChip.vue';

const loading = ref(false);
const partnerBy = ref('fio');
const partnerGroups = ref([]);

const snack = ref({ open: false, color: 'success', text: '' });
const notify = (text, color = 'success') => { snack.value = { open: true, color, text }; };

const fmt = (n) => Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2 });
const describe = (obj) => Object.entries(obj || {}).map(([k, v]) => `${k}: ${v}`).join(', ') || '—';

const ACTIVITY = { 1: ['Активен', 'success'], 2: ['Активен', 'success'], 3: ['Терминирован', 'error'],
  4: ['Зарегистрирован', 'info'], 5: ['Исключён', 'error'] };
const activityName = (id) => ACTIVITY[id]?.[0] || '—';
const activityColor = (id) => ACTIVITY[id]?.[1] || 'default';

async function loadPartners() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/duplicates/partners', { params: { by: partnerBy.value } });
    partnerGroups.value = data.data || [];
  } catch (e) {
    notify(e.response?.data?.message || 'Не удалось загрузить дубли партнёров', 'error');
  }
  loading.value = false;
}

// --- слияние партнёров ---
const mergeOpen = ref(false);
const merging = ref(false);
const mergePlan = ref(null);
const mergePreview = ref(null);
const mergeError = ref('');

async function openMerge(group, keep) {
  const other = group.records.find(r => r.id !== keep.id);
  mergePlan.value = { from: other.id, to: keep.id };
  mergePreview.value = null;
  mergeError.value = '';
  mergeOpen.value = true;
  try {
    // Сначала предпросмотр: оператор видит, что именно поедет.
    const { data } = await api.post('/admin/duplicates/partners/merge',
      { ...mergePlan.value, apply: false });
    mergePreview.value = data;
  } catch (e) {
    mergePreview.value = { ok: false };
    mergeError.value = e.response?.data?.message || 'Не удалось построить план слияния';
  }
}

async function doMerge() {
  merging.value = true;
  mergeError.value = '';
  try {
    const { data } = await api.post('/admin/duplicates/partners/merge',
      { ...mergePlan.value, apply: true });
    mergeOpen.value = false;
    notify(data.message);
    await loadPartners();
  } catch (e) {
    mergeError.value = e.response?.data?.message || 'Слияние не удалось';
  }
  merging.value = false;
}

onMounted(() => { loadPartners(); });
</script>
