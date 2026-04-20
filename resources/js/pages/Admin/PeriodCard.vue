<template>
  <div>
    <PageHeader :title="`Период ${periodLabel}`" icon="mdi-calendar-range">
      <template #actions>
        <v-btn variant="text" prepend-icon="mdi-arrow-left" @click="$router.push('/manage/workspace')">К рабочему столу</v-btn>
        <v-btn variant="text" prepend-icon="mdi-refresh" :loading="loading" @click="reload">Обновить</v-btn>
      </template>
    </PageHeader>

    <!-- Status banner -->
    <v-alert
      :type="closure?.isFrozen ? 'warning' : 'info'"
      variant="tonal"
      class="mb-3"
    >
      <div class="d-flex align-center flex-wrap ga-2">
        <span class="font-weight-medium">
          Статус периода:
          <template v-if="closure?.isFrozen">закрыт · заморожен</template>
          <template v-else>открыт</template>
        </span>
        <span v-if="closure?.closedAt" class="text-caption text-medium-emphasis">
          Закрыт {{ fmtDate(closure.closedAt) }}{{ closure.note ? ` · ${closure.note}` : '' }}
        </span>
        <v-spacer />
        <v-btn v-if="!closure?.isFrozen" size="small" color="warning" variant="tonal"
          prepend-icon="mdi-lock" @click="openCloseDialog">Закрыть период</v-btn>
        <v-btn v-else size="small" color="info" variant="tonal"
          prepend-icon="mdi-lock-open" @click="reopenPeriod">Переоткрыть</v-btn>
      </div>
    </v-alert>

    <!-- 3 sections -->
    <v-row dense>
      <!-- Penalties -->
      <v-col cols="12" lg="6">
        <v-card class="mb-3">
          <v-card-title class="d-flex align-center pa-3">
            <v-icon class="me-2" color="error">mdi-alert-decagram</v-icon>
            Штрафы (§5): отрыв + ОП
            <v-spacer />
            <v-btn size="small" variant="tonal" color="info" prepend-icon="mdi-eye"
              :loading="loadingPenalties" @click="loadPenalties">Preview</v-btn>
            <v-btn size="small" color="error" variant="flat" prepend-icon="mdi-check"
              :disabled="!penalties || closure?.isFrozen" :loading="applyingPenalties"
              @click="applyPenalties" class="ms-2">Применить</v-btn>
          </v-card-title>
          <v-divider />
          <v-card-text class="pa-3">
            <div v-if="!penalties" class="text-medium-emphasis">
              Нажмите «Preview», чтобы увидеть изменения до записи.
            </div>
            <v-row v-else dense>
              <v-col cols="6" sm="3">
                <div class="text-caption text-medium-emphasis">Партнёров</div>
                <div class="text-h6">{{ penalties.processed ?? 0 }}</div>
              </v-col>
              <v-col cols="6" sm="3">
                <div class="text-caption text-medium-emphasis">Затронуто комиссий</div>
                <div class="text-h6">{{ penalties.affected ?? 0 }}</div>
              </v-col>
              <v-col cols="6" sm="3">
                <div class="text-caption text-medium-emphasis">Отрыв ×0.5</div>
                <div class="text-h6">{{ penalties.detachmentAffected ?? 0 }}</div>
              </v-col>
              <v-col cols="6" sm="3">
                <div class="text-caption text-medium-emphasis">ОП ×0.8</div>
                <div class="text-h6">{{ penalties.opAffected ?? 0 }}</div>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- Pool -->
      <v-col cols="12" lg="6">
        <v-card class="mb-3">
          <v-card-title class="d-flex align-center pa-3">
            <v-icon class="me-2" color="primary">mdi-cash-multiple</v-icon>
            Пул (§6)
            <v-spacer />
            <v-btn size="small" variant="tonal" color="info" prepend-icon="mdi-eye"
              :loading="loadingPool" @click="loadPool">Preview</v-btn>
            <v-btn size="small" color="primary" variant="flat" prepend-icon="mdi-check"
              :disabled="!pool || closure?.isFrozen" :loading="applyingPool"
              @click="applyPool" class="ms-2">Применить</v-btn>
          </v-card-title>
          <v-divider />
          <v-card-text class="pa-3">
            <div v-if="!pool" class="text-medium-emphasis">
              Нажмите «Preview», чтобы рассчитать пул.
            </div>
            <template v-else>
              <v-row dense>
                <v-col cols="6" sm="3">
                  <div class="text-caption text-medium-emphasis">Выручка ДС</div>
                  <div class="text-h6"><MoneyCell :value="pool.revenue" currency="₽" /></div>
                </v-col>
                <v-col cols="6" sm="3">
                  <div class="text-caption text-medium-emphasis">Фонд/уровень</div>
                  <div class="text-h6"><MoneyCell :value="pool.fund" currency="₽" /></div>
                </v-col>
                <v-col cols="6" sm="3">
                  <div class="text-caption text-medium-emphasis">Выплачено</div>
                  <div class="text-h6"><MoneyCell :value="pool.totalPaid" currency="₽" /></div>
                </v-col>
                <v-col cols="6" sm="3">
                  <div class="text-caption text-medium-emphasis">Форфейтнуто</div>
                  <div class="text-h6"><MoneyCell :value="pool.totalForfeited" currency="₽" /></div>
                </v-col>
              </v-row>
              <v-divider class="my-2" />
              <v-data-table
                :items="pool.participants"
                :headers="poolHeaders"
                density="compact"
                no-data-text="Нет участников"
                :items-per-page="10"
              >
                <template #item.participates="{ item }">
                  <v-checkbox-btn
                    :model-value="item.participates"
                    :disabled="closure?.isFrozen"
                    @update:model-value="v => toggleParticipant(item, v)"
                  />
                </template>
                <template #item.levelName="{ item }">
                  <v-chip size="x-small" color="primary">{{ item.level }} · {{ item.levelName }}</v-chip>
                </template>
                <template #item.payoutRub="{ value }">
                  <MoneyCell :value="value" currency="₽" />
                </template>
              </v-data-table>
            </template>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- Close dialog -->
    <DialogShell
      v-model="closeDialog"
      :title="`Закрыть период ${periodLabel}?`"
      :max-width="500"
      confirm-text="Закрыть"
      confirm-color="warning"
      :loading="closing"
      @confirm="closePeriod"
    >
      <p class="mb-2">
        После закрытия месяца правка комиссий, транзакций и пула в этом
        месяце будет заблокирована. Это можно откатить через «Переоткрыть».
      </p>
      <v-textarea v-model="closeNote" label="Комментарий (опционально)"
        variant="outlined" density="compact" rows="2" />
    </DialogShell>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import api from '../../api';
import { PageHeader, DialogShell, MoneyCell } from '../../components';
import { fmtDate } from '../../composables/useDesign';
import { useSnackbar } from '../../composables/useSnackbar';

const route = useRoute();
const { showSuccess, showError } = useSnackbar();

const ym = computed(() => route.params.ym || ''); // YYYY-MM
const year = computed(() => parseInt(ym.value.slice(0, 4), 10));
const month = computed(() => parseInt(ym.value.slice(5, 7), 10));
const periodLabel = computed(() => ym.value);

const loading = ref(false);
const closure = ref(null);
const closeDialog = ref(false);
const closeNote = ref('');
const closing = ref(false);

const penalties = ref(null);
const loadingPenalties = ref(false);
const applyingPenalties = ref(false);

const pool = ref(null);
const loadingPool = ref(false);
const applyingPool = ref(false);

const poolHeaders = [
  { title: 'Участвует', key: 'participates', width: 100, sortable: false },
  { title: 'Партнёр', key: 'personName' },
  { title: 'Уровень', key: 'levelName', width: 180 },
  { title: 'Выплата', key: 'payoutRub', align: 'end', width: 140 },
];

async function loadClosure() {
  try {
    const { data } = await api.get('/admin/periods');
    const match = (data.data || []).find(c => c.year === year.value && c.month === month.value);
    closure.value = match || { isFrozen: false };
  } catch { closure.value = null; }
}

async function loadPenalties() {
  loadingPenalties.value = true;
  try {
    const { data } = await api.post('/admin/finalize/preview', { year: year.value, month: month.value });
    penalties.value = data;
  } catch (e) { showError(e.response?.data?.message || 'Не удалось рассчитать штрафы'); }
  loadingPenalties.value = false;
}

async function applyPenalties() {
  applyingPenalties.value = true;
  try {
    const { data } = await api.post('/admin/finalize/apply', { year: year.value, month: month.value });
    showSuccess(data.message || 'Штрафы применены');
    penalties.value = data.result || penalties.value;
  } catch (e) { showError(e.response?.data?.message || 'Не удалось применить штрафы'); }
  applyingPenalties.value = false;
}

async function loadPool() {
  loadingPool.value = true;
  try {
    const { data } = await api.get('/admin/pool/participants', { params: { year: year.value, month: month.value } });
    pool.value = data;
  } catch (e) { showError(e.response?.data?.message || 'Не удалось рассчитать пул'); }
  loadingPool.value = false;
}

async function applyPool() {
  applyingPool.value = true;
  try {
    const { data } = await api.post('/admin/pool/apply', { year: year.value, month: month.value });
    showSuccess(data.message || 'Пул применён');
    pool.value = data.result || pool.value;
  } catch (e) { showError(e.response?.data?.message || 'Не удалось применить пул'); }
  applyingPool.value = false;
}

async function toggleParticipant(p, v) {
  try {
    await api.put('/admin/pool/participants', {
      year: year.value, month: month.value, consultant: p.id, participates: v,
    });
    p.participates = v;
  } catch (e) { showError('Не удалось обновить участие'); }
}

function openCloseDialog() { closeNote.value = ''; closeDialog.value = true; }

async function closePeriod() {
  closing.value = true;
  try {
    await api.post('/admin/periods/close', {
      year: year.value, month: month.value, note: closeNote.value || null,
    });
    showSuccess(`Период ${periodLabel.value} закрыт`);
    closeDialog.value = false;
    await loadClosure();
  } catch (e) { showError(e.response?.data?.message || 'Не удалось закрыть период'); }
  closing.value = false;
}

async function reopenPeriod() {
  try {
    await api.post('/admin/periods/reopen', { year: year.value, month: month.value });
    showSuccess(`Период ${periodLabel.value} переоткрыт`);
    await loadClosure();
  } catch (e) { showError(e.response?.data?.message || 'Не удалось переоткрыть период'); }
}

async function reload() {
  loading.value = true;
  await Promise.all([loadClosure(), loadPenalties().catch(() => {}), loadPool().catch(() => {})]);
  loading.value = false;
}

watch(() => route.params.ym, () => reload());

onMounted(() => { if (ym.value) loadClosure(); });
</script>
