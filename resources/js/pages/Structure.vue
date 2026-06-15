<template>
  <div>
    <PageHeader title="Структура моей команды" icon="mdi-sitemap" />

    <v-card class="ds-card mb-3 pa-3" elevation="0">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-text-field v-model="filters.search" placeholder="ФИО партнёра..."
          rounded prepend-inner-icon="mdi-magnify" clearable hide-details style="max-width:240px" @update:model-value="debouncedLoad" />
        <v-select v-model="filters.qualification" :items="qualificationOptions" label="Квалификация"
          multiple clearable hide-details style="max-width:240px" @update:model-value="loadData" />
        <v-select v-model="filters.status" :items="statusOptions" label="Статус"
          multiple clearable hide-details style="max-width:240px" @update:model-value="loadData" />
        <v-btn variant="text" size="small" :prepend-icon="showAdvanced ? 'mdi-chevron-up' : 'mdi-chevron-down'"
          @click="showAdvanced = !showAdvanced">Доп. фильтры</v-btn>
        <v-chip v-if="activeFilterCount > 0" size="small" color="info" variant="tonal" class="ml-1">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : activeFilterCount < 5 ? 'фильтра' : 'фильтров' }}
        </v-chip>
        <v-btn v-if="activeFilterCount > 0" size="small" variant="text" color="secondary"
          prepend-icon="mdi-filter-remove" @click="resetFilters">Сбросить</v-btn>
        <v-spacer />
        <v-btn size="small" variant="tonal" prepend-icon="mdi-download"
          :loading="exportingAll" @click="exportAll">
          Экспорт структуры
        </v-btn>
      </div>
      <v-expand-transition>
        <div v-if="showAdvanced" class="d-flex ga-3 flex-wrap align-start mt-3">
          <SmartRangeFilter label="Дата рождения" kind="date"
            v-model:from="filters.birth_date_from"
            v-model:to="filters.birth_date_to"
            @update:from="debouncedLoad" @update:to="debouncedLoad" />
          <SmartRangeFilter label="Дата смены статуса" kind="date"
            v-model:from="filters.termination_from"
            v-model:to="filters.termination_to"
            @update:from="debouncedLoad" @update:to="debouncedLoad" />
          <SmartRangeFilter label="ЛП" kind="number"
            v-model:from="filters.lp_min" v-model:to="filters.lp_max"
            @update:from="debouncedLoad" @update:to="debouncedLoad" />
          <SmartRangeFilter label="ГП" kind="number"
            v-model:from="filters.gp_min" v-model:to="filters.gp_max"
            @update:from="debouncedLoad" @update:to="debouncedLoad" />
          <SmartRangeFilter label="НГП" kind="number"
            v-model:from="filters.ngp_min" v-model:to="filters.ngp_max"
            @update:from="debouncedLoad" @update:to="debouncedLoad" />
          <div class="d-flex flex-column">
            <div class="text-caption text-medium-emphasis mb-1">Город</div>
            <v-autocomplete v-model="filters.city" :items="cityOptions"
              :loading="citySearchLoading" placeholder="Город"
              item-title="name" item-value="name"
              density="compact" variant="outlined"
              hide-details hide-no-data clearable
              @update:search="onCitySearch"
              style="max-width:220px; min-width:180px" @update:model-value="loadData" />
          </div>
        </div>
      </v-expand-transition>
    </v-card>

    <v-card :loading="loading" class="ds-card" elevation="0">
      <div style="overflow-x: auto">
      <v-table density="compact" hover>
        <thead>
          <tr>
            <th>Партнёр</th>
            <th class="text-center" style="width:70px">Уровень</th>
            <th>Квалификация</th>
            <th>Статус</th>
            <th style="white-space:nowrap">Дата смены статуса</th>
            <th class="text-right sort-th" style="white-space:nowrap" @click="sortToggle('lp')">
              ЛП <v-icon size="13" :class="sortBy === 'lp' ? 'text-primary' : 'text-disabled'">
                {{ sortBy === 'lp' ? (sortDir === 'desc' ? 'mdi-sort-descending' : 'mdi-sort-ascending') : 'mdi-sort' }}
              </v-icon>
            </th>
            <th class="text-right sort-th" style="white-space:nowrap" @click="sortToggle('gp')">
              ГП <v-icon size="13" :class="sortBy === 'gp' ? 'text-primary' : 'text-disabled'">
                {{ sortBy === 'gp' ? (sortDir === 'desc' ? 'mdi-sort-descending' : 'mdi-sort-ascending') : 'mdi-sort' }}
              </v-icon>
            </th>
            <th class="text-right sort-th" style="white-space:nowrap" @click="sortToggle('ngp')">
              НГП <v-icon size="13" :class="sortBy === 'ngp' ? 'text-primary' : 'text-disabled'">
                {{ sortBy === 'ngp' ? (sortDir === 'desc' ? 'mdi-sort-descending' : 'mdi-sort-ascending') : 'mdi-sort' }}
              </v-icon>
            </th>
            <th class="text-right sort-th" style="width:90px" @click="sortToggle('clients')">
              Клиенты <v-icon size="13" :class="sortBy === 'clients' ? 'text-primary' : 'text-disabled'">
                {{ sortBy === 'clients' ? (sortDir === 'desc' ? 'mdi-sort-descending' : 'mdi-sort-ascending') : 'mdi-sort' }}
              </v-icon>
            </th>
            <th class="text-right sort-th" style="width:100px" @click="sortToggle('contracts')">
              Контракты <v-icon size="13" :class="sortBy === 'contracts' ? 'text-primary' : 'text-disabled'">
                {{ sortBy === 'contracts' ? (sortDir === 'desc' ? 'mdi-sort-descending' : 'mdi-sort-ascending') : 'mdi-sort' }}
              </v-icon>
            </th>
            <th class="text-right sort-th" style="width:100px" @click="sortToggle('partners')">
              Партнёры <v-icon size="13" :class="sortBy === 'partners' ? 'text-primary' : 'text-disabled'">
                {{ sortBy === 'partners' ? (sortDir === 'desc' ? 'mdi-sort-descending' : 'mdi-sort-ascending') : 'mdi-sort' }}
              </v-icon>
            </th>
            <th style="width:50px"></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="row in flatRows" :key="row._uid">
            <tr :class="{ 'tree-root': row._depth === 0 }">
              <td class="tree-cell">
                <span class="tree-row-inner">
                  <span v-for="(anc, i) in row._guides" :key="i" class="tree-rail" :class="{ 'tree-rail--line': !anc }"></span>
                  <span v-if="row._depth > 0" class="tree-elbow" :class="row._isLast ? 'is-last' : 'is-mid'"></span>
                  <v-btn v-if="row.hasChildren !== false" icon size="x-small" variant="text" class="tree-toggle"
                    :loading="row._loadingChildren" @click="toggleExpand(row)">
                    <v-icon size="18">{{ row._expanded ? 'mdi-chevron-down' : 'mdi-chevron-right' }}</v-icon>
                  </v-btn>
                  <span v-else class="tree-toggle"></span>
                  <span class="tree-label partner-link" @click.stop="openCard(row)">{{ row.personName }}</span>
                </span>
              </td>
              <td class="text-center">{{ row.level ?? '—' }}</td>
              <td style="white-space:nowrap">
                <v-chip v-if="row.qualification" size="x-small" color="secondary">{{ row.qualification.level }} [{{ row.qualification.title }}]</v-chip>
                <span v-else>—</span>
              </td>
              <td style="white-space:nowrap">
                <StatusChip v-if="row.activityName" :value="row.activityName" kind="activityName" size="x-small" :text="row.activityName" />
                <span v-else>—</span>
              </td>
              <td style="white-space:nowrap">
                <div>{{ statusChangeDate(row) || '—' }}</div>
                <div v-if="isActive(row) && row.personalVolumeSinceActivation != null"
                  class="text-caption" :class="row.personalVolumeSinceActivation < 500 ? 'text-warning' : 'text-success'">
                  ЛП с активации: {{ fmt(row.personalVolumeSinceActivation) }} / 500
                </div>
              </td>
              <td class="text-right" style="white-space:nowrap">{{ fmt(row.personalVolume) }}</td>
              <td class="text-right" style="white-space:nowrap">{{ fmt(row.groupVolume) }}</td>
              <td class="text-right" style="white-space:nowrap">{{ fmt(row.groupVolumeCumulative) }}</td>
              <td class="text-right">{{ row.clientCount ?? 0 }}</td>
              <td class="text-right">{{ row.contractCount ?? 0 }}</td>
              <td class="text-right">{{ row.partnersCount ?? 0 }}</td>
              <td>
                <v-tooltip text="Экспорт ветки в XLSX" location="top">
                  <template #activator="{ props: tip }">
                    <v-btn v-bind="tip" icon size="x-small" variant="text"
                      :loading="exportingId === row.id"
                      @click="exportSubtree(row)">
                      <v-icon size="18">mdi-download</v-icon>
                    </v-btn>
                  </template>
                </v-tooltip>
              </td>
            </tr>
          </template>
          <tr v-if="!flatRows.length && !loading">
            <td colspan="12"><EmptyState /></td>
          </tr>
        </tbody>
      </v-table>
      </div>
      <div v-if="total > 25" class="d-flex justify-center pa-3">
        <v-pagination v-model="page" :length="Math.ceil(total / 25)" density="compact" @update:model-value="loadData" />
      </div>
    </v-card>
  </div>

  <!-- Карточка партнёра -->
  <v-dialog v-model="cardOpen" max-width="480" scrollable>
    <v-card v-if="selectedPartner" rounded="lg">
      <v-card-title class="d-flex align-center ga-2 pa-4 pb-2">
        <v-avatar color="primary" size="40" class="flex-shrink-0">
          <span class="text-body-2 font-weight-bold text-white">
            {{ avatarInitials(selectedPartner) }}
          </span>
        </v-avatar>
        <div class="flex-grow-1 min-width-0">
          <div class="text-subtitle-1 font-weight-bold text-truncate">{{ selectedPartner.personName }}</div>
          <StatusChip v-if="selectedPartner.activityName"
            :value="selectedPartner.activityName" kind="activityName"
            size="x-small" :text="selectedPartner.activityName" class="mt-1" />
        </div>
        <v-btn icon size="small" variant="text" @click="cardOpen = false">
          <v-icon>mdi-close</v-icon>
        </v-btn>
      </v-card-title>

      <v-divider />

      <v-card-text class="pa-4">
        <!-- Квалификация -->
        <div class="d-flex ga-3 mb-3 flex-wrap">
          <div class="info-block">
            <div class="text-caption text-medium-emphasis">Уровень</div>
            <div class="text-body-2 font-weight-medium">{{ selectedPartner.level ?? '—' }}</div>
          </div>
          <div class="info-block flex-grow-1">
            <div class="text-caption text-medium-emphasis">Квалификация</div>
            <div class="text-body-2 font-weight-medium">
              <span v-if="selectedPartner.qualification">
                {{ selectedPartner.qualification.level }} [{{ selectedPartner.qualification.title }}]
              </span>
              <span v-else>—</span>
            </div>
          </div>
        </div>

        <!-- Объёмы -->
        <v-row dense class="mb-3">
          <v-col cols="4">
            <div class="volume-block">
              <div class="text-caption text-medium-emphasis">ЛП</div>
              <div class="text-body-2 font-weight-bold" style="font-variant-numeric:tabular-nums">
                {{ fmt(selectedPartner.personalVolume) }}
              </div>
            </div>
          </v-col>
          <v-col cols="4">
            <div class="volume-block">
              <div class="text-caption text-medium-emphasis">ГП</div>
              <div class="text-body-2 font-weight-bold" style="font-variant-numeric:tabular-nums">
                {{ fmt(selectedPartner.groupVolume) }}
              </div>
            </div>
          </v-col>
          <v-col cols="4">
            <div class="volume-block">
              <div class="text-caption text-medium-emphasis">НГП</div>
              <div class="text-body-2 font-weight-bold" style="font-variant-numeric:tabular-nums">
                {{ fmt(selectedPartner.groupVolumeCumulative) }}
              </div>
            </div>
          </v-col>
        </v-row>

        <!-- Счётчики -->
        <v-row dense class="mb-3">
          <v-col cols="4">
            <div class="volume-block">
              <div class="text-caption text-medium-emphasis">Клиенты</div>
              <div class="text-body-2 font-weight-medium">{{ selectedPartner.clientCount ?? 0 }}</div>
            </div>
          </v-col>
          <v-col cols="4">
            <div class="volume-block">
              <div class="text-caption text-medium-emphasis">Контракты</div>
              <div class="text-body-2 font-weight-medium">{{ selectedPartner.contractCount ?? 0 }}</div>
            </div>
          </v-col>
          <v-col cols="4">
            <div class="volume-block">
              <div class="text-caption text-medium-emphasis">Партнёры</div>
              <div class="text-body-2 font-weight-medium">{{ selectedPartner.partnersCount ?? 0 }}</div>
            </div>
          </v-col>
        </v-row>

        <v-divider class="mb-3" />

        <!-- Контактные данные -->
        <div v-if="selectedPartner.email || selectedPartner.phone || selectedPartner.nicTG"
          class="d-flex flex-column ga-2 mb-3">
          <div v-if="selectedPartner.phone" class="d-flex align-center ga-2">
            <v-icon size="18" color="medium-emphasis">mdi-phone-outline</v-icon>
            <a :href="'tel:' + selectedPartner.phone" class="text-body-2 contact-link">
              {{ selectedPartner.phone }}
            </a>
          </div>
          <div v-if="selectedPartner.email" class="d-flex align-center ga-2">
            <v-icon size="18" color="medium-emphasis">mdi-email-outline</v-icon>
            <a :href="'mailto:' + selectedPartner.email" class="text-body-2 contact-link">
              {{ selectedPartner.email }}
            </a>
          </div>
          <div v-if="selectedPartner.nicTG" class="d-flex align-center ga-2">
            <v-icon size="18" color="medium-emphasis">mdi-send-outline</v-icon>
            <a :href="'https://t.me/' + selectedPartner.nicTG.replace('@', '')"
              target="_blank" rel="noopener" class="text-body-2 contact-link">
              {{ selectedPartner.nicTG.startsWith('@') ? selectedPartner.nicTG : '@' + selectedPartner.nicTG }}
            </a>
          </div>
        </div>
        <v-divider v-if="selectedPartner.email || selectedPartner.phone || selectedPartner.nicTG" class="mb-3" />

        <!-- Персональные данные -->
        <div class="d-flex flex-column ga-2">
          <div v-if="selectedPartner.city" class="d-flex align-center ga-2">
            <v-icon size="18" color="medium-emphasis">mdi-map-marker-outline</v-icon>
            <span class="text-body-2">{{ selectedPartner.city }}</span>
          </div>
          <div v-if="selectedPartner.birthDate" class="d-flex align-center ga-2">
            <v-icon size="18" color="medium-emphasis">mdi-cake-variant-outline</v-icon>
            <span class="text-body-2">{{ selectedPartner.birthDate }}</span>
          </div>
          <div v-if="selectedPartner.dateActivity" class="d-flex align-center ga-2">
            <v-icon size="18" color="medium-emphasis">mdi-calendar-check-outline</v-icon>
            <span class="text-body-2">Активирован: {{ selectedPartner.dateActivity }}</span>
          </div>
          <div v-if="statusChangeDate(selectedPartner)" class="d-flex align-center ga-2">
            <v-icon size="18" color="medium-emphasis">mdi-calendar-clock-outline</v-icon>
            <span class="text-body-2">
              {{ isActive(selectedPartner) ? 'Конец цикла:' : 'Срок активации:' }}
              {{ statusChangeDate(selectedPartner) }}
            </span>
          </div>
          <div v-if="selectedPartner.inviterName" class="d-flex align-center ga-2">
            <v-icon size="18" color="medium-emphasis">mdi-account-arrow-up-outline</v-icon>
            <span class="text-body-2">Пригласитель: {{ selectedPartner.inviterName }}</span>
          </div>
          <div v-if="selectedPartner.personalVolumeSinceActivation != null && isActive(selectedPartner)"
            class="d-flex align-center ga-2">
            <v-icon size="18" :color="selectedPartner.personalVolumeSinceActivation < 500 ? 'warning' : 'success'">
              mdi-trending-up
            </v-icon>
            <span class="text-body-2"
              :class="selectedPartner.personalVolumeSinceActivation < 500 ? 'text-warning' : 'text-success'">
              ЛП с активации: {{ fmt(selectedPartner.personalVolumeSinceActivation) }} / 500
            </span>
          </div>
        </div>

        <v-divider class="my-3" />

        <!-- Комментарии -->
        <div class="text-caption text-medium-emphasis mb-2 d-flex align-center ga-1">
          <v-icon size="14">mdi-comment-text-outline</v-icon>
          Комментарии
        </div>

        <div v-if="commentsLoading" class="d-flex justify-center py-2">
          <v-progress-circular indeterminate size="20" width="2" />
        </div>
        <div v-else>
          <div v-if="comments.length === 0" class="text-caption text-disabled mb-2">Нет комментариев</div>
          <div v-for="c in comments" :key="c.id" class="comment-item mb-2">
            <div class="d-flex align-center justify-space-between">
              <span class="text-caption font-weight-medium">{{ c.author_name || 'Пользователь' }}</span>
              <div class="d-flex align-center ga-1">
                <span class="text-caption text-disabled">{{ fmtDate(c.created_at) }}</span>
                <v-btn v-if="c.author_id === currentUserId" icon size="x-small" variant="text"
                  color="error" @click="deleteComment(c.id)" title="Удалить">
                  <v-icon size="12">mdi-close</v-icon>
                </v-btn>
              </div>
            </div>
            <div class="text-body-2 mt-1">{{ c.body }}</div>
          </div>
        </div>

        <div class="d-flex ga-2 mt-2">
          <v-textarea v-model="newComment" placeholder="Написать комментарий..."
            variant="outlined" density="compact" rows="2" hide-details
            class="grow" style="font-size:13px" />
          <v-btn :loading="commentSaving" :disabled="!newComment.trim()"
            color="primary" size="small" icon @click="submitComment">
            <v-icon>mdi-send</v-icon>
          </v-btn>
        </div>
      </v-card-text>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import api from '../api';
import { useDebounce } from '../composables/useDebounce';
import PageHeader from '../components/PageHeader.vue';
import EmptyState from '../components/EmptyState.vue';
import StatusChip from '../components/StatusChip.vue';
import SmartRangeFilter from '../components/SmartRangeFilter.vue';
import { fmt, getActivityColorByName } from '../composables/useDesign';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const currentUserId = computed(() => auth.user?.id ?? null);

const loading = ref(false);
const cardOpen = ref(false);
const selectedPartner = ref(null);

// Комментарии
const comments      = ref([]);
const commentsLoading = ref(false);
const newComment    = ref('');
const commentSaving = ref(false);

function fmtDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' });
}

async function loadComments(consultantId) {
  commentsLoading.value = true;
  comments.value = [];
  try {
    const { data } = await api.get(`/partner-comments/${consultantId}`);
    comments.value = data.data || [];
  } finally {
    commentsLoading.value = false;
  }
}

async function submitComment() {
  if (!newComment.value.trim() || !selectedPartner.value) return;
  commentSaving.value = true;
  try {
    const { data } = await api.post('/partner-comments', {
      consultant_id: selectedPartner.value.id,
      body: newComment.value.trim(),
    });
    comments.value.unshift(data.comment);
    newComment.value = '';
  } finally {
    commentSaving.value = false;
  }
}

async function deleteComment(id) {
  try {
    await api.delete(`/partner-comments/${id}`);
    comments.value = comments.value.filter(c => c.id !== id);
  } catch {}
}

function openCard(row) {
  selectedPartner.value = row;
  newComment.value = '';
  cardOpen.value = true;
  loadComments(row.id);
}

function avatarInitials(row) {
  const parts = (row.personName || '').trim().split(/\s+/);
  if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
  return (parts[0]?.[0] ?? '?').toUpperCase();
}
const showAdvanced = ref(false);
const exportingId = ref(null);
const exportingAll = ref(false);
const sortBy = ref('');
const sortDir = ref('desc');

function sortToggle(field) {
  if (sortBy.value === field) {
    sortDir.value = sortDir.value === 'desc' ? 'asc' : 'desc';
  } else {
    sortBy.value = field;
    sortDir.value = 'desc';
  }
  page.value = 1;
  loadData();
}

async function exportAll() {
  exportingAll.value = true;
  try {
    const resp = await api.get('/structure/export', { params: filterParams(), responseType: 'blob' });
    const url = URL.createObjectURL(resp.data);
    const a = document.createElement('a');
    a.href = url;
    const cd = resp.headers?.['content-disposition'] || '';
    const m = /filename\*?=(?:UTF-8'')?\"?([^\";]+)/i.exec(cd);
    a.download = m ? decodeURIComponent(m[1]) : 'structure.xlsx';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  } catch (e) {
    console.error('structure export failed', e);
  }
  exportingAll.value = false;
}

async function exportSubtree(row) {
  if (!row?.id) return;
  exportingId.value = row.id;
  try {
    const resp = await api.get(`/structure/${row.id}/export`, { responseType: 'blob' });
    const url = URL.createObjectURL(resp.data);
    const a = document.createElement('a');
    a.href = url;
    // Имя файла берём из Content-Disposition если пришло, иначе генерим
    const cd = resp.headers?.['content-disposition'] || '';
    const m = /filename\*?=(?:UTF-8'')?\"?([^\";]+)/i.exec(cd);
    a.download = m ? decodeURIComponent(m[1]) : `structure-${row.id}.xlsx`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  } catch (e) {
    console.error('subtree export failed', e);
  }
  exportingId.value = null;
}

const activeFilterCount = computed(() => {
  const f = filters.value;
  let c = 0;
  if (f.search) c++;
  if (f.last_name) c++;
  if (f.first_name) c++;
  if (f.patronymic) c++;
  if (f.qualification?.length) c++;
  if (f.levels?.length) c++;
  if (f.status?.length) c++;
  if (f.birth_date_from) c++;
  if (f.birth_date_to) c++;
  if (f.city) c++;
  if (f.lp_min) c++;
  if (f.lp_max) c++;
  if (f.gp_min) c++;
  if (f.gp_max) c++;
  if (f.ngp_min) c++;
  if (f.ngp_max) c++;
  if (f.termination_from) c++;
  if (f.termination_to) c++;
  return c;
});

function resetFilters() {
  filters.value = {
    search: '', last_name: '', first_name: '', patronymic: '',
    qualification: [], levels: [], status: [],
    line: '',
    birth_date_from: '', birth_date_to: '',
    city: '',
    lp_min: '', lp_max: '', gp_min: '', gp_max: '', ngp_min: '', ngp_max: '',
    termination_from: '', termination_to: '',
  };
  loadData();
}
const items = ref([]);
const total = ref(0);
const page = ref(1);
const qualificationOptions = ref([]);
const statusOptions = [
  { title: 'Зарегистрирован-Партнёр', value: 'registered' },
  { title: 'Активен', value: 'active' },
  { title: 'Терминирован', value: 'terminated' },
  { title: 'Исключён', value: 'excluded' },
];
const filters = ref({
  search: '', last_name: '', first_name: '', patronymic: '',
  qualification: [], levels: [], status: [],
  line: '',
  birth_date_from: '', birth_date_to: '',
  city: '',
  lp_min: '', lp_max: '', gp_min: '', gp_max: '', ngp_min: '', ngp_max: '',
  termination_from: '', termination_to: '',
});

let uidCounter = 0;
function enrichRows(rows, depth = 0) {
  return (rows || []).map(r => ({
    ...r,
    _uid: ++uidCounter,
    _depth: depth,
    _expanded: false,
    _loadingChildren: false,
    _children: [],
  }));
}

// Плоский список с метаданными для дерева-коннекторов:
//  _guides   — для каждого предка: true если он был последним ребёнком
//              (вертикальную линию ниже не тянем), false — есть братья ниже.
//  _isLast   — последний ли этот узел среди своих братьев (└ vs ├).
const flatRows = computed(() => {
  const result = [];
  function walk(rows, ancestorsLast) {
    rows.forEach((r, i) => {
      const isLast = i === rows.length - 1;
      r._guides = ancestorsLast;
      r._isLast = isLast;
      result.push(r);
      if (r._expanded && r._children.length) {
        walk(r._children, [...ancestorsLast, isLast]);
      }
    });
  }
  walk(items.value, []);
  return result;
});


// "Дата смены статуса" по спеке ✅Структура §4:
//  - Активен → dateActivity + 12 месяцев (конец годового цикла, yearPeriodEnd).
//    Fallback: если yearPeriodEnd пуст (legacy-партнёры), считаем
//    dateActivity + 12 месяцев на лету.
//  - Зарегистрирован → activationDeadline (90 дней с регистрации).
//  - Терминирован/Исключён → не отображается.
function statusChangeDate(row) {
  const name = (row.activityName || '').toLowerCase();
  if (name.includes('терм') || name.includes('исключ')) return null;
  if (name.includes('актив')) {
    if (row.yearPeriodEnd) return row.yearPeriodEnd;
    if (row.dateActivity) {
      // dateActivity приходит как 'd.m.Y' — превращаем в Date и добавляем год.
      const [d, m, y] = row.dateActivity.split('.');
      if (d && m && y) {
        const dt = new Date(+y + 1, +m - 1, +d);
        return `${String(dt.getDate()).padStart(2, '0')}.${String(dt.getMonth() + 1).padStart(2, '0')}.${dt.getFullYear()}`;
      }
    }
    return null;
  }
  if (name.includes('зарег')) return row.activationDeadline;
  return row.dateActivity;
}

function isActive(row) {
  return (row.activityName || '').toLowerCase().includes('актив');
}

async function toggleExpand(row) {
  if (row._expanded) {
    row._expanded = false;
    return;
  }
  if (row._children.length) {
    row._expanded = true;
    return;
  }
  row._loadingChildren = true;
  try {
    // Передаём текущие фильтры в /children — раньше эндпоинт игнорил
    // фильтры, поэтому развёрнутые ветки показывали ВСЕХ потомков
    // (например, при выборе «Активен» в развёртке всё равно были
    // терминированные).
    const { data } = await api.get(`/structure/${row.id}/children`, { params: filterParams() });
    row._children = enrichRows(data.data || data, row._depth + 1);
    row._expanded = true;
  } catch {}
  row._loadingChildren = false;
}

// Сборка params — общая для loadData и toggleExpand. Извлечена в функцию,
// чтобы при правке фильтров не забывать обновлять оба места.
function filterParams() {
  const params = {};
  if (filters.value.search) params.search = filters.value.search;
  if (filters.value.last_name) params.last_name = filters.value.last_name;
  if (filters.value.first_name) params.first_name = filters.value.first_name;
  if (filters.value.patronymic) params.patronymic = filters.value.patronymic;
  if (filters.value.qualification?.length) params.qualification = filters.value.qualification.join(',');
  if (filters.value.levels?.length) params.levels = filters.value.levels.join(',');
  if (filters.value.status?.length) params.status = filters.value.status.join(',');
  if (filters.value.birth_date_from) params.birth_date_from = filters.value.birth_date_from;
  if (filters.value.birth_date_to) params.birth_date_to = filters.value.birth_date_to;
  if (filters.value.city) params.city = filters.value.city;
  if (filters.value.lp_min) params.lp_min = filters.value.lp_min;
  if (filters.value.lp_max) params.lp_max = filters.value.lp_max;
  if (filters.value.gp_min) params.gp_min = filters.value.gp_min;
  if (filters.value.gp_max) params.gp_max = filters.value.gp_max;
  if (filters.value.ngp_min) params.ngp_min = filters.value.ngp_min;
  if (filters.value.ngp_max) params.ngp_max = filters.value.ngp_max;
  if (filters.value.termination_from) params.termination_from = filters.value.termination_from;
  if (filters.value.termination_to) params.termination_to = filters.value.termination_to;
  // Опциональный фильтр «только 1 линия» — приходит из дашборда query.
  // Бэк, который его не знает, просто проигнорирует параметр.
  if (filters.value.line) params.line = filters.value.line;
  if (sortBy.value) { params.sort_by = sortBy.value; params.sort_dir = sortDir.value; }
  return params;
}

const { debounced: debouncedLoad } = useDebounce(loadData, 400);

async function loadData() {
  loading.value = true;
  try {
    const params = { page: page.value, ...filterParams() };
    const { data } = await api.get('/structure', { params });
    uidCounter = 0;
    const responseData = data.data || data;
    items.value = enrichRows(responseData);
    total.value = data.total || responseData.length;
  } catch {}
  loading.value = false;
}

async function loadFilterOptions() {
  try {
    const [act, qual] = await Promise.all([
      api.get('/structure/activity-statuses'),
      api.get('/structure/qualification-levels'),
    ]);
    // activity-statuses can override statusOptions if backend provides them
    // value = q.level (число 1..10), а не q.id — backend сравнивает с
    // m.qualification.level (см. ConsultantService::applyFilters).
    qualificationOptions.value = qual.data.map(q => ({ title: `${q.level} [${q.title}]`, value: q.level }));
  } catch {}
}

const cityOptions = ref([]);
const citySearchLoading = ref(false);
let citySearchTimer;
function onCitySearch(q) {
  clearTimeout(citySearchTimer);
  citySearchTimer = setTimeout(async () => {
    citySearchLoading.value = true;
    try {
      const { data } = await api.get('/structure/cities', { params: q ? { q } : {} });
      cityOptions.value = data;
    } catch {}
    citySearchLoading.value = false;
  }, 250);
}

// Применяем query из URL при заходе на страницу (deep-link с дашборда).
// /structure?status=terminated → preselect filter «Терминирован».
// /structure?line=1 → ограничение на 1 линию (UI пока не имеет тоггла,
//   но передадим параметр в API чтобы бэк фильтровал).
const route = useRoute();
function applyQueryToFilters() {
  const q = route.query;
  if (q.status) {
    const raw = String(q.status);
    const valid = statusOptions.map(o => o.value);
    const vals = raw.split(',').filter(v => valid.includes(v));
    if (vals.length) filters.value.status = vals;
  }
  if (q.line) filters.value.line = String(q.line);
}

onMounted(() => {
  applyQueryToFilters();
  loadData();
  loadFilterOptions();
  onCitySearch(''); // prefill with first 30 cities
});
</script>

<style scoped>
.comment-item {
  background: rgba(var(--v-theme-on-surface), 0.04);
  border-radius: 6px;
  padding: 6px 8px;
}

/* DS-патч на Структуру: tabular-nums на счётчиках/балансах. */
:deep(.text-h5), :deep(.text-h6), :deep(.text-subtitle-1) {
  font-variant-numeric: tabular-nums;
}

/* Сортируемые заголовки таблицы */
.sort-th {
  cursor: pointer;
  user-select: none;
  white-space: nowrap;
}
.sort-th:hover {
  color: rgb(var(--v-theme-primary));
}

/* --- Дерево структуры: коннекторы наставник → команда --- */
.tree-cell {
  padding: 0 !important;
  white-space: nowrap;
}
.tree-row-inner {
  display: flex;
  align-items: stretch;
  min-height: 36px;
  height: 100%;
}
/* Рельса предка: занимает один уровень отступа. */
.tree-rail,
.tree-elbow {
  position: relative;
  flex: 0 0 22px;
  width: 22px;
}
/* Вертикальная линия предка, у которого ниже ещё есть братья. */
.tree-rail--line::before {
  content: '';
  position: absolute;
  left: 11px;
  top: 0;
  bottom: 0;
  border-left: 1px solid rgba(var(--v-theme-on-surface), 0.22);
}
/* Уголок к самому узлу: вертикаль (сверху) + горизонталь (к имени). */
.tree-elbow::before {
  content: '';
  position: absolute;
  left: 11px;
  top: 0;
  border-left: 1px solid rgba(var(--v-theme-on-surface), 0.22);
}
.tree-elbow.is-last::before { height: 50%; }          /* последний ребёнок: линия до середины */
.tree-elbow.is-mid::before { bottom: 0; }             /* есть братья ниже: линия насквозь */
.tree-elbow::after {
  content: '';
  position: absolute;
  left: 11px;
  top: 50%;
  width: 12px;
  border-top: 1px solid rgba(var(--v-theme-on-surface), 0.22);
}
.tree-toggle {
  flex: 0 0 28px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.tree-label {
  display: inline-flex;
  align-items: center;
  padding-right: 12px;
}
/* Корневые узлы — чуть жирнее для «явности» уровней. */
.tree-root .tree-label { font-weight: 600; }

/* Кликабельное имя партнёра */
.partner-link {
  cursor: pointer;
  color: rgb(var(--v-theme-primary));
  border-radius: 4px;
  padding: 0 2px;
  transition: background 0.15s;
}
.partner-link:hover {
  background: rgba(var(--v-theme-primary), 0.08);
}

/* Блоки данных в карточке */
.info-block {
  background: rgba(var(--v-theme-on-surface), 0.04);
  border-radius: 8px;
  padding: 8px 12px;
  min-width: 80px;
}
.volume-block {
  background: rgba(var(--v-theme-primary), 0.06);
  border-radius: 8px;
  padding: 8px 10px;
  text-align: center;
}
.contact-link {
  color: rgb(var(--v-theme-primary));
  text-decoration: none;
}
.contact-link:hover { text-decoration: underline; }
</style>

