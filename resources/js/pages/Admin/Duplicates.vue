<template>
  <div>
    <PageHeader title="Дубли и связки" icon="mdi-account-multiple-remove" />

    <v-tabs v-model="tab" class="mb-3">
      <v-tab value="partners">Партнёры<v-chip v-if="partnerGroups.length" size="x-small" class="ms-2" variant="tonal">{{ partnerGroups.length }}</v-chip></v-tab>
      <v-tab value="clients">Клиенты<v-chip v-if="visibleClientGroups.length" size="x-small" class="ms-2" variant="tonal">{{ visibleClientGroups.length }}</v-chip></v-tab>
    </v-tabs>

    <!-- ПАРТНЁРЫ -->
    <div v-if="tab === 'partners'">
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
        <v-table density="compact" class="dup-table">
          <thead>
            <tr>
              <th>Запись</th>
              <th>Статус</th>
              <th>Контакты</th>
              <th>Наставник / код</th>
              <th class="text-end">Объёмы</th>
              <th class="text-end">Портфель</th>
              <th class="text-end">Деньги</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in g.records" :key="r.id">
              <!-- Кто это: id, вход в кабинет, даты жизни записи -->
              <td>
                <div class="d-flex align-center ga-1">
                  <span class="font-weight-bold">{{ r.id }}</span>
                  <v-chip v-if="r.hasLogin" size="x-small" color="success" variant="tonal">вход есть</v-chip>
                  <v-chip v-else size="x-small" color="warning" variant="tonal">без входа</v-chip>
                  <v-chip v-if="r.isClient" size="x-small" variant="tonal">ещё и клиент</v-chip>
                </div>
                <div class="text-caption text-medium-emphasis mt-1">
                  заведён {{ d(r.dateCreated) }}
                  <template v-if="r.lastSeenAt"> · заходил {{ d(r.lastSeenAt) }}</template>
                  <template v-else> · не заходил</template>
                </div>
              </td>

              <!-- Статус, квалификация, акцепт, терминации -->
              <td>
                <StatusChip size="x-small" :text="activityName(r.activityId)" :color="activityColor(r.activityId)" />
                <div class="text-caption text-medium-emphasis mt-1">
                  {{ r.statusName || 'без квалификации' }}
                </div>
                <div class="text-caption">
                  <span :class="r.acceptance ? 'text-success' : 'text-medium-emphasis'">
                    {{ r.acceptance ? 'акцепт есть' : 'без акцепта' }}
                  </span>
                  <template v-if="r.terminationCount"> · терминаций {{ r.terminationCount }}</template>
                </div>
              </td>

              <td class="text-caption">
                {{ r.email || '—' }}<br>{{ r.phone || '—' }}
              </td>

              <td class="text-caption">
                {{ r.inviterName || '—' }}
                <div class="text-medium-emphasis">код {{ r.participantCode || '—' }}</div>
                <div v-if="r.dateActivity" class="text-medium-emphasis">активен с {{ d(r.dateActivity) }}</div>
              </td>

              <!-- ЛП/ГП и глубина истории расчётов -->
              <td class="text-end text-caption">
                ЛП {{ fmt(r.personalVolume) }}<br>
                ГП {{ fmt(r.groupVolume) }}
                <div class="text-medium-emphasis">периодов {{ r.qualLogs }}</div>
              </td>

              <!-- Что на записи висит -->
              <td class="text-end text-caption">
                контрактов {{ r.contracts }}<br>
                клиентов {{ r.clients }}
                <div :class="r.downline ? 'text-warning font-weight-bold' : 'text-medium-emphasis'">
                  ниже {{ r.downline }}
                </div>
              </td>

              <!-- Деньги: начислено / выплачено / остаток -->
              <td class="text-end text-caption">
                начислено {{ fmt(r.accrued) }} ₽<br>
                выплачено {{ fmt(r.payed) }} ₽
                <div :class="r.remaining ? 'font-weight-bold text-warning' : 'text-medium-emphasis'">
                  остаток {{ fmt(r.remaining) }} ₽
                </div>
                <div class="text-medium-emphasis">комиссий {{ r.commissions }} на {{ fmt(r.commissionsSum) }} ₽</div>
              </td>

              <td class="text-end">
                <!-- Блокирует нижестоящий у ВТОРОЙ записи: сервер отказывает,
                     когда нижестоящие есть у УДАЛЯЕМОЙ. Прежний гард смотрел на
                     оставляемую и запрещал ровно ту пару, которая сливается. -->
                <v-btn size="x-small" variant="tonal" color="primary"
                  :disabled="g.records.length !== 2 || otherHasDownline(g, r)"
                  @click="openMerge(g, r)">Оставить эту</v-btn>
                <div v-if="otherHasDownline(g, r)" class="text-caption text-warning mt-1" style="max-width:150px">
                  у второй записи есть нижестоящие — сначала «Перестановки»
                </div>
              </td>
            </tr>
          </tbody>
        </v-table>
        <div v-if="g.records.length !== 2" class="pa-2 text-caption text-medium-emphasis">
          В группе больше двух записей — сливайте по одной паре через поддержку.
        </div>
      </v-card>
    </div>


    <!-- КЛИЕНТЫ -->
    <div v-else>
      <v-card class="ds-card mb-3 pa-3" elevation="0">
        <div class="d-flex ga-3 align-center flex-wrap">
          <v-btn-toggle v-model="clientBy" density="compact" color="primary" mandatory
            @update:model-value="loadClients">
            <v-btn value="fio" size="small">По ФИО</v-btn>
            <v-btn value="phone" size="small">По телефону</v-btn>
            <v-btn value="email" size="small">По почте</v-btn>
          </v-btn-toggle>
          <v-checkbox-btn v-model="onlySameName" label="только с одинаковым ФИО"
            density="compact" hide-details class="flex-grow-0" />
          <div class="text-caption text-medium-emphasis" style="max-width:520px">
            Один телефон и одна почта сплошь общие у семьи — родитель оформляет полисы
            на детей. Группа по контакту это сигнал посмотреть, а не команда сливать.
          </div>
        </div>
      </v-card>

      <v-alert v-if="!loading && !visibleClientGroups.length" type="success" variant="tonal" density="compact">
        Дублей не найдено.
      </v-alert>

      <v-card v-for="g in visibleClientGroups" :key="g.key" class="ds-card mb-3" elevation="0">
        <div class="ds-card__head d-flex align-center ga-2">
          <v-icon size="18" :color="g.sameName ? 'warning' : 'error'">mdi-account-multiple</v-icon>
          <span class="ds-title-l">{{ g.key }}</span>
          <v-chip v-if="!g.sameName" size="x-small" color="error" variant="tonal">ФИО разные — вероятно, семья</v-chip>
          <v-spacer />
          <v-btn size="x-small" variant="text" color="warning" prepend-icon="mdi-account-off-outline"
            :loading="ignoring === g.key" @click="markNotDuplicate(g)">Не дубли</v-btn>
        </div>
        <v-table density="compact">
          <thead>
            <tr>
              <th>Карточка</th><th>Партнёр</th><th>Контакты</th>
              <th class="text-end">Контракты</th><th class="text-end">Деньги</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in g.records" :key="r.id">
              <td>
                <div class="d-flex align-center ga-1">
                  <span class="font-weight-bold">{{ r.id }}</span>
                  <v-chip v-if="r.isPartner" size="x-small" variant="tonal">ещё и партнёр</v-chip>
                </div>
                <div>{{ r.personName || '—' }}</div>
                <div class="text-caption text-medium-emphasis">
                  заведена {{ d(r.dateCreated) }}
                  <template v-if="r.birthDate"> · ДР {{ d(r.birthDate) }}</template>
                </div>
              </td>
              <td class="text-caption">
                {{ r.consultantName || '— без наставника' }}
                <div v-if="r.city" class="text-medium-emphasis">{{ r.city }}</div>
              </td>
              <td class="text-caption">
                {{ r.email || '—' }}<br>{{ r.phone || '—' }}
              </td>
              <td class="text-end text-caption">
                {{ r.contracts }}
                <div class="text-medium-emphasis">
                  <template v-if="r.lastContractAt">последний {{ d(r.lastContractAt) }}</template>
                  <template v-else>нет</template>
                </div>
              </td>
              <td class="text-end text-caption">
                сумма {{ fmt(r.contractsSum) }} ₽
                <div :class="r.dsIncome ? 'font-weight-bold' : 'text-medium-emphasis'">
                  доход ДС {{ fmt(r.dsIncome) }} ₽
                </div>
                <div class="text-medium-emphasis">транзакций {{ r.transactions }}</div>
              </td>
              <td class="text-end">
                <v-btn size="x-small" variant="tonal" color="primary"
                  :disabled="g.records.length !== 2"
                  @click="openClientMerge(g, r)">Оставить эту</v-btn>
              </td>
            </tr>
          </tbody>
        </v-table>
        <div v-if="g.records.length !== 2" class="pa-2 text-caption text-medium-emphasis">
          В группе больше двух карточек — сливайте по одной паре.
        </div>
      </v-card>
    </div>

    <!-- Подтверждение слияния клиентов -->
    <v-dialog v-model="clientMergeOpen" max-width="560" persistent>
      <v-card v-if="clientPlan">
        <v-card-title>Слить карточки клиента?</v-card-title>
        <v-card-text>
          <div class="text-body-2 mb-2">
            Остаётся <strong>id {{ clientPlan.to }}</strong>, удаляется
            <strong>id {{ clientPlan.from }}</strong>. Контракты, сделки и связи
            удаляемой карточки перейдут на остающуюся, пустые контакты дозаполнятся.
          </div>
          <v-alert v-if="clientPlan.differentNames" type="warning" variant="tonal" density="compact" class="mb-2">
            У карточек РАЗНОЕ ФИО. Убедитесь, что это один человек, а не родственники
            с общим телефоном или почтой.
          </v-alert>
          <v-alert type="info" variant="tonal" density="compact" class="mb-2">
            {{ clientPreview?.message }}
          </v-alert>
          <div v-if="clientPreview?.moved" class="text-caption">
            Переносим: {{ describe(clientPreview.moved) }}
          </div>
          <v-alert v-if="clientMergeError" type="error" density="compact" class="mt-2">{{ clientMergeError }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="clientMergeOpen = false">Отмена</v-btn>
          <v-btn color="primary" :loading="clientMerging" :disabled="!clientPreview?.ok"
            @click="doClientMerge">Слить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

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
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import StatusChip from '../../components/StatusChip.vue';

const loading = ref(false);
const tab = ref('partners');
const partnerBy = ref('fio');
const partnerGroups = ref([]);
const clientBy = ref('fio');
const clientGroups = ref([]);
// По умолчанию прячем группы с разным ФИО: по телефону и почте это семьи,
// и без фильтра список тонет в них.
const onlySameName = ref(true);
const visibleClientGroups = computed(() => (onlySameName.value
  ? clientGroups.value.filter(g => g.sameName)
  : clientGroups.value));

const snack = ref({ open: false, color: 'success', text: '' });
const notify = (text, color = 'success') => { snack.value = { open: true, color, text }; };

const fmt = (n) => Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2 });
// Дата коротко: в таблице важен год, а не часы.
const d = (v) => (v ? new Date(v).toLocaleDateString('ru-RU') : '—');
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

async function loadClients() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/duplicates/clients', { params: { by: clientBy.value } });
    clientGroups.value = data.data || [];
  } catch (e) {
    notify(e.response?.data?.message || 'Не удалось загрузить дубли клиентов', 'error');
  }
  loading.value = false;
}

// Отметка «не дубли»: однофамильцы и родня с общим контактом. Ключ группы —
// id карточек по возрастанию, тот же формат, что копила прежняя страница.
const ignoring = ref('');

async function markNotDuplicate(group) {
  ignoring.value = group.key;
  try {
    const { data } = await api.post('/admin/duplicates/clients/ignore',
      { ids: group.records.map(r => r.id) });
    notify(data.message);
    clientGroups.value = clientGroups.value.filter(g => g.key !== group.key);
  } catch (e) {
    notify(e.response?.data?.message || 'Не удалось отметить группу', 'error');
  }
  ignoring.value = '';
}

// --- слияние клиентов ---
const clientMergeOpen = ref(false);
const clientMerging = ref(false);
const clientPlan = ref(null);
const clientPreview = ref(null);
const clientMergeError = ref('');

async function openClientMerge(group, keep) {
  const other = group.records.find(r => r.id !== keep.id);
  clientPlan.value = { from: other.id, to: keep.id, differentNames: !group.sameName };
  clientPreview.value = null;
  clientMergeError.value = '';
  clientMergeOpen.value = true;
  try {
    const { data } = await api.post('/admin/duplicates/clients/merge',
      { from: other.id, to: keep.id, apply: false });
    clientPreview.value = data;
  } catch (e) {
    clientPreview.value = { ok: false };
    clientMergeError.value = e.response?.data?.message || 'Не удалось построить план слияния';
  }
}

async function doClientMerge() {
  clientMerging.value = true;
  clientMergeError.value = '';
  try {
    const { data } = await api.post('/admin/duplicates/clients/merge',
      { from: clientPlan.value.from, to: clientPlan.value.to, apply: true });
    clientMergeOpen.value = false;
    notify(data.message);
    await loadClients();
  } catch (e) {
    clientMergeError.value = e.response?.data?.message || 'Слияние не удалось';
  }
  clientMerging.value = false;
}

// --- слияние партнёров ---
const mergeOpen = ref(false);
const merging = ref(false);
const mergePlan = ref(null);
const mergePreview = ref(null);
const mergeError = ref('');

// У записи, которая уйдёт в удаление, есть нижестоящие? Сервер такую пару
// отклоняет: перевешивание структуры делается через «Перестановки».
function otherHasDownline(group, keep) {
  const other = group.records.find(r => r.id !== keep.id);
  return !!other && other.downline > 0;
}

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

onMounted(() => { loadPartners(); loadClients(); });
</script>
