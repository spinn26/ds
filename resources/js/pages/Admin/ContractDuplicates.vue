<template>
  <div class="pa-4">
    <PageHeader title="Дубли контрактов"
      subtitle="Поиск контрактов с одинаковым номером и инструменты — объединить или удалить" />

    <v-card class="mb-4 pa-3" variant="tonal">
      <div class="d-flex align-center flex-wrap ga-3">
        <v-btn-toggle v-model="mode" mandatory density="comfortable" color="primary" @update:model-value="load">
          <v-btn value="number" size="small">По номеру</v-btn>
          <v-btn value="number_client" size="small">Номер + клиент</v-btn>
        </v-btn-toggle>
        <span class="text-caption text-medium-emphasis" style="max-width:520px">
          «По номеру» ловит и разных клиентов под одним номером (напр. Inssmart-хэши) —
          такие группы помечены и объединять/удалять их нужно осторожно.
          «Номер + клиент» — строгие дубли одной сделки.
        </span>
        <v-spacer />
        <v-btn size="small" variant="text" prepend-icon="mdi-refresh" :loading="loading" @click="load">Обновить</v-btn>
      </div>
    </v-card>

    <div v-if="loading" class="py-8 text-center">
      <v-progress-circular indeterminate color="primary" />
    </div>

    <EmptyState v-else-if="!groups.length"
      icon="mdi-check-decagram" title="Дублей не найдено"
      text="Контрактов с повторяющимся номером нет." />

    <div v-else>
      <div class="text-body-2 text-medium-emphasis mb-3">
        Найдено групп: <strong>{{ groups.length }}</strong>,
        контрактов в дублях: <strong>{{ totalContracts }}</strong>,
        полных совпадений: <strong>{{ fullMatchCount }}</strong>,
        разные данные: <strong>{{ groups.length - fullMatchCount }}</strong>
      </div>

      <v-card v-for="g in groups" :key="g.number + '_' + g.contracts[0].id" class="mb-4" variant="outlined">
        <v-card-title class="d-flex align-center ga-2 py-2">
          <v-icon size="18">mdi-content-duplicate</v-icon>
          <span class="text-body-1 font-weight-medium">№ {{ g.number }}</span>
          <v-chip size="x-small" variant="tonal">{{ g.count }} шт.</v-chip>
          <v-chip v-if="g.totalTx" size="x-small" color="info" variant="tonal">транзакций: {{ g.totalTx }}</v-chip>
          <v-chip v-if="g.fullMatch" size="x-small" color="success" variant="tonal">
            <v-icon start size="14">mdi-check-all</v-icon>полное совпадение — оставить с транзакциями
          </v-chip>
          <v-chip v-else size="x-small" color="warning" variant="tonal">
            <v-icon start size="14">mdi-alert</v-icon>разные данные — оставить как есть
          </v-chip>
          <v-chip v-if="!g.sameClient" size="x-small" color="warning" variant="tonal">
            <v-icon start size="14">mdi-account-alert</v-icon>разные клиенты
          </v-chip>
        </v-card-title>
        <v-divider />
        <v-table density="comfortable">
          <thead>
            <tr>
              <th style="width:44px"></th>
              <th>ID</th>
              <th>Клиент</th>
              <th>Партнёр</th>
              <th>Продукт / программа</th>
              <th>Статус</th>
              <th class="text-end">Сумма</th>
              <th class="text-center">Транз.</th>
              <th>Создан</th>
              <th style="width:120px" class="text-center">Канонический</th>
              <th style="width:44px" class="text-center"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in g.contracts" :key="c.id"
              :class="{ 'tx-canon-row': canonical[groupKey(g)] === c.id }">
              <td>
                <v-checkbox-btn :model-value="isSelected(g, c.id)"
                  :disabled="canonical[groupKey(g)] === c.id"
                  @update:model-value="v => toggleSelect(g, c.id, v)" />
              </td>
              <td class="text-medium-emphasis">{{ c.id }}</td>
              <td>{{ c.clientName || '—' }}</td>
              <td>{{ c.consultantName || '—' }}</td>
              <td>
                <div>{{ c.productName || '—' }}</div>
                <div class="text-caption text-medium-emphasis">{{ c.programName || '' }}</div>
              </td>
              <td>
                <v-chip v-if="c.statusName" size="x-small" variant="tonal">{{ c.statusName }}</v-chip>
                <span v-else class="text-medium-emphasis">—</span>
              </td>
              <td class="text-end" style="font-variant-numeric:tabular-nums">{{ fmtAmt(c.ammount) }}</td>
              <td class="text-center">
                <v-chip v-if="c.txCount" size="x-small" color="info" variant="tonal">{{ c.txCount }}</v-chip>
                <span v-else class="text-medium-emphasis">—</span>
              </td>
              <td class="text-caption">{{ fmtDate(c.createDate) }}</td>
              <td class="text-center">
                <v-radio :model-value="canonical[groupKey(g)]" :value="c.id"
                  @update:model-value="v => setCanonical(g, c.id)" density="compact" hide-details />
              </td>
              <td class="text-center">
                <v-btn :href="managerBase + '?id=' + c.id" target="_blank" icon="mdi-open-in-new"
                  size="x-small" variant="text" title="Открыть контракт" />
              </td>
            </tr>
          </tbody>
        </v-table>
        <v-divider />
        <v-card-actions class="px-4 py-2 ga-2">
          <v-btn size="small" :color="g.fullMatch ? 'primary' : 'default'"
            :variant="g.fullMatch ? 'flat' : 'outlined'" prepend-icon="mdi-merge"
            :disabled="!canMerge(g)" @click="doMerge(g)">
            {{ g.fullMatch ? 'Оставить одну (с транзакциями)' : 'Объединить в канонический' }}
          </v-btn>
          <v-btn size="small" color="error" variant="tonal" prepend-icon="mdi-delete"
            :disabled="!selectedCount(g)" @click="doDelete(g)">
            Удалить выбранные ({{ selectedCount(g) }})
          </v-btn>
          <v-spacer />
          <span v-if="g.fullMatch" class="text-caption text-medium-emphasis">
            Полное совпадение: транзакции переносятся на канонический, дубли удаляются (обратимо).
          </span>
          <span v-else class="text-caption text-warning">
            <v-icon size="14">mdi-information</v-icon>
            Данные контрактов различаются — вероятно, это разные сделки. Рекомендуется оставить как есть.
          </span>
        </v-card-actions>
      </v-card>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useRoute } from 'vue-router';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import { useSnackbar } from '../../composables/useSnackbar';
import { useConfirm } from '../../composables/useConfirm';

const { showSuccess, showError, showInfo } = useSnackbar();
const confirmDialog = useConfirm();
const route = useRoute();

// Базовый путь менеджера контрактов — тот же layout, что и эта страница
// (/manage/contracts/duplicates → /manage/contracts, /admin/... → /admin/...).
const managerBase = computed(() => route.path.replace(/\/duplicates\/?$/, ''));

const mode = ref('number');
const loading = ref(false);
const groups = ref([]);
// Выбор канонического и отмеченных к удалению — по ключу группы.
const canonical = ref({});
const selected = ref({});

const totalContracts = computed(() => groups.value.reduce((s, g) => s + g.count, 0));
const fullMatchCount = computed(() => groups.value.filter(g => g.fullMatch).length);

function groupKey(g) {
  return g.number + '_' + g.contracts[0].id;
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/contracts/duplicates', { params: { mode: mode.value } });
    groups.value = data.groups || [];
    // Дефолт: канонический = контракт с наибольшим числом транзакций, иначе младший id.
    const canon = {}; const sel = {};
    for (const g of groups.value) {
      // Правило: канонический = контракт с транзакциями (рекомендация сервера),
      // иначе младший id. Для «полного совпадения» это и есть «оставить ту,
      // где есть транзакции»; «разные данные» не трогаем (merge не по умолчанию).
      const best = [...g.contracts].sort((a, b) => (b.txCount - a.txCount) || (a.id - b.id))[0];
      canon[groupKey(g)] = g.recommendedCanonical || best.id;
      sel[groupKey(g)] = [];
    }
    canonical.value = canon;
    selected.value = sel;
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось загрузить дубли');
  }
  loading.value = false;
}

function setCanonical(g, id) {
  const k = groupKey(g);
  canonical.value[k] = id;
  // Канонический не может быть одновременно отмечен к удалению.
  selected.value[k] = (selected.value[k] || []).filter(x => x !== id);
}
function isSelected(g, id) {
  return (selected.value[groupKey(g)] || []).includes(id);
}
function toggleSelect(g, id, v) {
  const k = groupKey(g);
  const set = new Set(selected.value[k] || []);
  v ? set.add(id) : set.delete(id);
  selected.value[k] = [...set];
}
function selectedCount(g) {
  return (selected.value[groupKey(g)] || []).length;
}
// Объединяем всё, кроме канонического (независимо от чекбоксов — «схлопнуть группу»).
function mergeTargets(g) {
  const canon = canonical.value[groupKey(g)];
  return g.contracts.map(c => c.id).filter(id => id !== canon);
}
function canMerge(g) {
  return !!canonical.value[groupKey(g)] && mergeTargets(g).length > 0;
}

function fmtAmt(v) {
  if (v == null) return '—';
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(v);
}
function fmtDate(v) {
  if (!v) return '—';
  return String(v).slice(0, 10).split('-').reverse().join('.');
}

async function doMerge(g) {
  const canonId = canonical.value[groupKey(g)];
  const targets = mergeTargets(g);
  const canonC = g.contracts.find(c => c.id === canonId);
  if (!await confirmDialog.ask({
    title: 'Объединить дубли?',
    message: `Транзакции контрактов ${targets.join(', ')} будут перенесены на канонический #${canonId} (${canonC?.clientName || ''}), а сами они помечены удалёнными. Комиссии за открытые периоды пересчитаются. Продолжить?`,
    confirmText: 'Объединить', confirmColor: 'primary', icon: 'mdi-merge',
  })) return;
  try {
    const { data } = await api.post('/admin/contracts/duplicates/merge', {
      canonical: canonId, mergeIds: targets,
    });
    showSuccess(data.message || 'Объединено');
    await load();
  } catch (e) {
    showError(e.response?.data?.message || 'Ошибка объединения');
  }
}

async function doDelete(g) {
  const ids = selected.value[groupKey(g)] || [];
  if (!ids.length) return;
  if (!await confirmDialog.ask({
    title: 'Удалить выбранные контракты?',
    message: `Будет помечено удалёнными: ${ids.join(', ')}. Контракты с транзакциями пропускаются (используйте «Объединить»). Действие обратимо.`,
    confirmText: 'Удалить', confirmColor: 'error', icon: 'mdi-delete',
  })) return;
  try {
    const { data } = await api.post('/admin/contracts/duplicates/delete', { ids });
    if (data.blocked?.length) {
      // Часть контрактов имеет транзакции — по умолчанию их не удаляем, чтобы
      // не оторвать деньги. Предлагаем осознанное force-удаление (обратимо).
      const blockedIds = data.blocked.map(b => b.id);
      showInfo(data.message);
      if (await confirmDialog.ask({
        title: 'Удалить контракты с транзакциями?',
        message: `Контракты ${blockedIds.join(', ')} имеют транзакции и обычно объединяются, а не удаляются. Принудительно пометить их удалёнными? Транзакции останутся привязаны к удалённым контрактам. Действие обратимо.`,
        confirmText: 'Удалить принудительно', confirmColor: 'error', icon: 'mdi-delete-alert',
      })) {
        const { data: forced } = await api.post('/admin/contracts/duplicates/delete', { ids: blockedIds, force: true });
        showSuccess(forced.message || 'Удалено');
      }
    } else {
      showSuccess(data.message || 'Удалено');
    }
    await load();
  } catch (e) {
    showError(e.response?.data?.message || 'Ошибка удаления');
  }
}

load();
</script>

<style scoped>
.tx-canon-row {
  background: rgba(var(--v-theme-primary), 0.10);
}
</style>
