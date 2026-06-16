<template>
  <div>
    <PageHeader title="Матрица квалификаций" icon="mdi-stairs">
      <template #actions>
        <v-btn color="primary" size="small" prepend-icon="mdi-content-save" :loading="saving"
          :disabled="!dirty" @click="save">Сохранить</v-btn>
      </template>
    </PageHeader>

    <v-alert type="warning" variant="tonal" density="comfortable" class="mb-3">
      Изменения влияют на расчёты (% группового бонуса, пороги НГП, ОП, отрыв).
      Перед правкой на проде — проверьте пересчёт периода на копии. Исторические
      данные (до cutoff) не пересчитываются.
    </v-alert>

    <v-card>
      <v-table density="comfortable">
        <thead>
          <tr>
            <th>Ур.</th>
            <th>Название</th>
            <th class="text-right">% бонуса</th>
            <th class="text-right">НГП (накопл.)</th>
            <th class="text-right">ОП (mandatoryGP)</th>
            <th class="text-right">Отрыв %</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="l in levels" :key="l.id">
            <td>{{ l.level }}</td>
            <td style="min-width:160px">
              <v-text-field v-model="l.title" density="compact" variant="outlined" hide-details @update:model-value="dirty = true" />
            </td>
            <td style="width:120px">
              <v-text-field v-model.number="l.percent" type="number" density="compact" variant="outlined" hide-details suffix="%" @update:model-value="dirty = true" />
            </td>
            <td style="width:150px">
              <v-text-field v-model.number="l.groupVolumeCumulative" type="number" density="compact" variant="outlined" hide-details @update:model-value="dirty = true" />
            </td>
            <td style="width:150px">
              <v-text-field v-model.number="l.mandatoryGP" type="number" density="compact" variant="outlined" hide-details @update:model-value="dirty = true" />
            </td>
            <td style="width:120px">
              <v-text-field v-model.number="l.otrif" type="number" density="compact" variant="outlined" hide-details suffix="%" @update:model-value="dirty = true" />
            </td>
          </tr>
        </tbody>
      </v-table>
      <div v-if="loading" class="pa-6 d-flex justify-center"><v-progress-circular indeterminate color="primary" /></div>
    </v-card>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="3500">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';
import { PageHeader } from '../../components';

const levels = ref([]);
const loading = ref(false);
const saving = ref(false);
const dirty = ref(false);
const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

async function load() {
  loading.value = true;
  try { const { data } = await api.get('/admin/qualification-matrix'); levels.value = (data.levels || []).map(l => ({ ...l })); }
  catch (e) { notify(e.response?.data?.message || 'Ошибка загрузки', 'error'); }
  loading.value = false;
}

async function save() {
  saving.value = true;
  try {
    await api.put('/admin/qualification-matrix', {
      levels: levels.value.map(l => ({
        id: l.id, title: l.title, percent: l.percent,
        groupVolumeCumulative: l.groupVolumeCumulative, mandatoryGP: l.mandatoryGP, otrif: l.otrif,
      })),
    });
    dirty.value = false;
    notify('Сохранено');
  } catch (e) { notify(e.response?.data?.message || 'Ошибка сохранения', 'error'); }
  saving.value = false;
}

onMounted(load);
</script>
