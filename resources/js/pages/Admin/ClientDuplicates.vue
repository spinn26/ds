<template>
  <div>
    <PageHeader title="Дубли клиентов" icon="mdi-account-multiple-remove">
      <template #actions>
        <v-btn-toggle v-model="groupBy" mandatory density="compact" variant="outlined"
          @update:model-value="loadData">
          <v-btn value="name" size="small">По ФИО</v-btn>
          <v-btn value="email" size="small">По почте</v-btn>
          <v-btn value="phone" size="small">По телефону</v-btn>
        </v-btn-toggle>
        <v-btn variant="text" size="small" prepend-icon="mdi-refresh" :loading="loading"
          @click="loadData">Обновить</v-btn>
      </template>
    </PageHeader>

    <v-alert type="info" variant="tonal" density="compact" class="mb-3">
      Выберите карточку, которую оставляем — остальные будут объединены в неё: контракты,
      встречи, показатели и все прочие связи переедут, а лишние карточки закроются.
      Однофамильцев отметьте «Не дубли», чтобы они больше не появлялись в списке.
    </v-alert>

    <v-card v-if="loading" class="pa-6 text-center">
      <v-progress-circular indeterminate color="primary" />
    </v-card>

    <EmptyState v-else-if="!groups.length"
      icon="mdi-check-circle-outline" title="Дублей не найдено"
      text="По выбранному признаку совпадений нет." />

    <div v-else class="d-flex flex-column ga-3">
      <v-card v-for="g in groups" :key="g.key" :class="['dup-card', 'conf-' + g.confidence]">
        <v-card-title class="d-flex align-center flex-wrap ga-2 py-2">
          <span class="text-subtitle-1 font-weight-bold">{{ g.name }}</span>
          <v-chip size="x-small" :color="confColor(g.confidence)" variant="tonal">
            {{ confLabel(g.confidence) }}
          </v-chip>
          <v-chip v-if="g.sharedPerson" size="x-small" variant="tonal">общая person</v-chip>
          <v-chip v-if="g.sharedContact" size="x-small" variant="tonal">контакты совпадают</v-chip>
          <v-spacer />
          <span class="text-caption text-medium-emphasis">
            карточек: {{ g.clients.length }} · контрактов: {{ totalContracts(g) }}
          </span>
        </v-card-title>

        <v-table density="compact">
          <thead>
            <tr>
              <th style="width:110px">Оставить</th>
              <th>Карточка</th>
              <th class="text-end" style="width:110px">Контрактов</th>
              <th style="width:90px">person</th>
              <th>Партнёр</th>
              <th>Почта</th>
              <th>Телефон</th>
              <th style="width:110px">Создана</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in g.clients" :key="c.id" :class="{ 'keep-row': keepId(g) === c.id }">
              <td>
                <v-radio-group :model-value="keepId(g)" hide-details density="compact"
                  @update:model-value="v => keep[g.key] = v">
                  <v-radio :value="c.id" density="compact" />
                </v-radio-group>
              </td>
              <td>
                <a class="text-primary" :href="`/manage/clients?id=${c.id}`" target="_blank">#{{ c.id }}</a>
                <v-chip v-if="c.self" size="x-small" variant="tonal" color="success" class="ml-2">на себя</v-chip>
              </td>
              <td class="text-end tnum">{{ c.contracts }}</td>
              <td class="tnum text-medium-emphasis">{{ c.person ?? '—' }}</td>
              <td>{{ c.consultantName || '—' }}</td>
              <td class="text-truncate" style="max-width:200px">{{ c.email || '—' }}</td>
              <td class="tnum">{{ c.phone || '—' }}</td>
              <td class="text-caption text-medium-emphasis">{{ fmtDate(c.createdAt) }}</td>
            </tr>
          </tbody>
        </v-table>

        <v-card-actions class="px-4 pb-3">
          <v-btn size="small" variant="text" prepend-icon="mdi-account-off-outline"
            :loading="busy === g.key + ':ignore'" @click="markNotDuplicate(g)">
            Не дубли
          </v-btn>
          <v-spacer />
          <v-btn v-if="canFull('clients')" color="primary" size="small" prepend-icon="mdi-merge"
            :loading="busy === g.key + ':merge'" @click="doMerge(g)">
            Объединить в #{{ keepId(g) }}
          </v-btn>
          <span v-else class="text-caption text-medium-emphasis">Объединение требует полных прав на клиентов</span>
        </v-card-actions>
      </v-card>
    </div>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="5000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import { useConfirm } from '../../composables/useConfirm';
import { usePermissions } from '../../composables/usePermissions';

const confirm = useConfirm();
const { canFull } = usePermissions();

const groups = ref([]);
const loading = ref(false);
const busy = ref(null);
const groupBy = ref('name');
// Выбор «кого оставляем» по группам; по умолчанию — предложение бэкенда.
const keep = reactive({});

const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

function keepId(g) { return keep[g.key] ?? g.suggestedKeep; }
function totalContracts(g) { return g.clients.reduce((s, c) => s + c.contracts, 0); }
function fmtDate(v) { return v ? String(v).slice(0, 10) : '—'; }

function confLabel(c) {
  return { merge: 'Скорее дубли', self: 'Партнёр на себя', check: 'Проверить' }[c] || c;
}
function confColor(c) {
  return { merge: 'error', self: 'success', check: 'warning' }[c] || 'default';
}

async function loadData() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/clients/duplicates', { params: { by: groupBy.value } });
    groups.value = data.groups || [];
  } catch (e) {
    notify(e.response?.data?.message || 'Не удалось загрузить дубли', 'error');
  }
  loading.value = false;
}

async function doMerge(g) {
  const canonical = keepId(g);
  const mergeIds = g.clients.filter(c => c.id !== canonical).map(c => c.id);
  const moving = g.clients.filter(c => c.id !== canonical).reduce((s, c) => s + c.contracts, 0);

  if (!await confirm.ask({
    title: 'Объединить карточки?',
    message: `Останется карточка #${canonical}. Будет закрыто карточек: ${mergeIds.length}`
      + (moving ? `, переедет контрактов: ${moving}` : '')
      + '. Действие необратимо.',
    confirmText: 'Объединить', confirmColor: 'primary', icon: 'mdi-merge',
  })) return;

  busy.value = g.key + ':merge';
  try {
    const { data } = await api.post('/admin/clients/duplicates/merge', { canonical, mergeIds });
    notify(data.message || 'Объединено');
    await loadData();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка объединения', 'error');
  }
  busy.value = null;
}

async function markNotDuplicate(g) {
  if (!await confirm.ask({
    title: 'Это разные люди?',
    message: `Группа «${g.name}» больше не будет показываться в списке дублей.`,
    confirmText: 'Не дубли', confirmColor: 'warning', icon: 'mdi-account-off-outline',
  })) return;

  busy.value = g.key + ':ignore';
  try {
    await api.post('/admin/clients/duplicates/ignore', { ids: g.clients.map(c => c.id) });
    notify('Отмечено: не дубли');
    await loadData();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  busy.value = null;
}

onMounted(loadData);
</script>

<style scoped>
.tnum { font-variant-numeric: tabular-nums; }
/* Полоса слева кодирует уверенность — видно при беглом просмотре списка. */
.dup-card { border-left: 3px solid rgba(var(--v-theme-on-surface), 0.2); }
.dup-card.conf-merge { border-left-color: rgb(var(--v-theme-error)); }
.dup-card.conf-self { border-left-color: rgb(var(--v-theme-success)); }
.dup-card.conf-check { border-left-color: rgb(var(--v-theme-warning)); }
.keep-row { background: rgba(var(--v-theme-primary), 0.06); }
/* Радиокнопка в ячейке не должна тянуть строку по высоте. */
.dup-card :deep(.v-selection-control) { min-height: 24px; }
</style>
