<template>
  <div>
    <PageHeader title="Конструктор меню" icon="mdi-menu">
      <template #actions>
        <v-btn color="primary" size="small" prepend-icon="mdi-plus" @click="openCreate">Добавить пункт</v-btn>
      </template>
    </PageHeader>

    <v-alert type="info" variant="tonal" density="comfortable" class="mb-3">
      <b>Админка и Сотрудники:</b> пункты дополняют стандартное меню. <b>Кабинет партнёра:</b>
      меню полностью управляется отсюда — порядок, группы, скрытие (переключатель «Активен»).
      Пункт с указанной группой попадает в одноимённую категорию, без группы — отдельный пункт.
      Можно вести внутренние маршруты (<code>/admin/...</code>, <code>/clients</code>) и внешние ссылки.
    </v-alert>

    <v-tabs v-model="area" density="compact" class="mb-3">
      <v-tab value="admin">Админка</v-tab>
      <v-tab value="staff">Сотрудники</v-tab>
      <v-tab value="partner">Кабинет партнёра</v-tab>
    </v-tabs>

    <v-card>
      <v-data-table :items="filtered" :headers="headers" density="comfortable" hover :loading="loading"
        items-per-page="50">
        <template #item.title="{ item }">
          <v-icon size="18" class="mr-1">{{ item.icon || 'mdi-circle-small' }}</v-icon>{{ item.title }}
        </template>
        <template #item.group_title="{ value }">
          <span v-if="value">{{ value }}</span>
          <span v-else class="text-medium-emphasis text-caption">отдельный пункт</span>
        </template>
        <template #item.to="{ item }">
          <span class="text-caption">{{ item.to || '—' }}</span>
          <v-icon v-if="item.external" size="14" class="ml-1" title="Внешняя ссылка">mdi-open-in-new</v-icon>
        </template>
        <template #item.roles="{ value }">
          <span v-if="value && value.length" class="text-caption">{{ value.join(', ') }}</span>
          <span v-else class="text-medium-emphasis text-caption">все</span>
        </template>
        <template #item.active="{ value }">
          <v-icon :color="value ? 'success' : 'grey'" size="18">{{ value ? 'mdi-check-circle' : 'mdi-circle-outline' }}</v-icon>
        </template>
        <template #item.actions="{ item }">
          <v-btn icon="mdi-arrow-up" size="x-small" variant="text" @click="move(item, -1)" />
          <v-btn icon="mdi-arrow-down" size="x-small" variant="text" @click="move(item, 1)" />
          <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openEdit(item)" />
          <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="remove(item)" />
        </template>
        <template #no-data><EmptyState message="Кастомных пунктов нет" /></template>
      </v-data-table>
    </v-card>

    <v-dialog v-model="dialog" max-width="600" persistent>
      <v-card>
        <v-card-title>{{ form.id ? 'Редактировать' : 'Добавить' }} пункт</v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col cols="12" sm="6"><v-text-field v-model="form.title" label="Название *" density="compact" :error-messages="errs.title" /></v-col>
            <v-col cols="12" sm="6">
              <v-combobox v-model="form.icon" :items="iconOptions" label="Иконка" density="compact"
                clearable placeholder="mdi-star">
                <template #prepend-inner>
                  <v-icon size="18">{{ form.icon || 'mdi-circle-small' }}</v-icon>
                </template>
                <template #item="{ props: itemProps, item }">
                  <v-list-item v-bind="itemProps" :prepend-icon="item.raw" :title="item.raw" />
                </template>
              </v-combobox>
            </v-col>
            <v-col cols="12"><v-text-field v-model="form.to" label="Ссылка / маршрут" density="compact" :error-messages="errs.to" placeholder="/admin/... или https://..." /></v-col>
            <v-col cols="12" sm="6">
              <v-combobox v-model="form.group_title" :items="groupSuggestions" label="Группа (категория)"
                density="compact" clearable placeholder="оставить пустым = отдельный пункт" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-combobox v-model="form.roles" :items="roleSuggestions" label="Роли (пусто = все)"
                multiple chips closable-chips density="compact" />
            </v-col>
            <v-col cols="6"><v-switch v-model="form.external" label="Внешняя ссылка" color="primary" density="compact" hide-details /></v-col>
            <v-col cols="6"><v-switch v-model="form.active" label="Активен" color="success" density="compact" hide-details /></v-col>
          </v-row>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="dialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" @click="save">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="3000">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import api from '../../api';
import { PageHeader, EmptyState } from '../../components';

const headers = [
  { title: 'Название', key: 'title' },
  { title: 'Группа', key: 'group_title' },
  { title: 'Ссылка', key: 'to' },
  { title: 'Роли', key: 'roles', sortable: false },
  { title: 'Активен', key: 'active', width: 90 },
  { title: '', key: 'actions', sortable: false, width: 180, align: 'end' },
];

// Подсказки групп — совпадают с реальными категориями меню каждой области
// (admin → AdminLayout, staff/partner → MainLayout). Можно ввести и свою:
// несуществующая группа будет создана в конце меню.
const GROUPS_BY_AREA = {
  admin: [
    'Рабочий стол', 'Пользователи и клиенты', 'Контент и продукты', 'Финансы и контроль',
    'Операции', 'Маркетинг и уведомления', 'Справочники', 'Настройки',
  ],
  staff: [
    'Инструменты', 'Компания', 'Данные', 'Финансы', 'Выплаты',
    'Обучение', 'Прочее', 'Помощь', 'Аналитика',
  ],
  partner: ['Обзор', 'Работа', 'Развитие', 'Связь'],
};
// Роль партнёра в WebUser.role — `consultant` (не «partner»).
const roleSuggestions = ['admin', 'calculations', 'head', 'support', 'education', 'backoffice', 'finance', 'corrections', 'invest', 'consultant'];

// Курируемый набор MDI-иконок для выбора (можно ввести и свою mdi-...).
const iconOptions = [
  'mdi-star', 'mdi-star-outline', 'mdi-home', 'mdi-view-dashboard', 'mdi-chart-bar',
  'mdi-chart-line', 'mdi-chart-pie', 'mdi-finance', 'mdi-cash', 'mdi-cash-multiple',
  'mdi-currency-usd', 'mdi-bank', 'mdi-credit-card', 'mdi-receipt', 'mdi-calculator',
  'mdi-account', 'mdi-account-group', 'mdi-account-multiple', 'mdi-account-search',
  'mdi-account-tie', 'mdi-sitemap', 'mdi-file-document', 'mdi-file-chart',
  'mdi-folder', 'mdi-folder-open', 'mdi-clipboard-text', 'mdi-clipboard-check',
  'mdi-book-open-variant', 'mdi-school', 'mdi-lightbulb', 'mdi-rocket-launch',
  'mdi-trophy', 'mdi-medal', 'mdi-gift', 'mdi-calendar', 'mdi-calendar-check',
  'mdi-clock-outline', 'mdi-bell', 'mdi-email', 'mdi-chat', 'mdi-phone',
  'mdi-help-circle', 'mdi-information', 'mdi-alert', 'mdi-shield-check',
  'mdi-cog', 'mdi-tools', 'mdi-wrench', 'mdi-link-variant', 'mdi-open-in-new',
  'mdi-web', 'mdi-cloud', 'mdi-download', 'mdi-upload', 'mdi-magnify',
  'mdi-heart', 'mdi-fire', 'mdi-flash', 'mdi-target', 'mdi-map-marker',
  'mdi-package-variant-closed', 'mdi-tag', 'mdi-percent', 'mdi-handshake',
];

const items = ref([]);
const area = ref('admin');
const loading = ref(false);
const dialog = ref(false);
const saving = ref(false);
const errs = reactive({});
const snack = ref({ open: false, color: 'success', text: '' });
const form = reactive(emptyForm());

function emptyForm() { return { id: null, area: 'admin', group_title: null, title: '', icon: '', to: '', external: false, roles: [], active: true }; }
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

const filtered = computed(() => items.value.filter((i) => i.area === area.value));
// Группы предлагаем по области ФОРМЫ (form.area), а не активной вкладки —
// диалог редактирования может быть открыт для пункта другой области.
// Сначала — реальные группы из существующих пунктов области (для партнёра
// это ровно категории меню ФК: партнёрское меню полностью в БД), затем
// дефолты статической навигации, которых ещё нет в БД.
const groupSuggestions = computed(() => {
  const fromItems = [...new Set(
    items.value
      .filter((i) => i.area === form.area && i.group_title)
      .map((i) => i.group_title),
  )];
  const defaults = (GROUPS_BY_AREA[form.area] || []).filter((g) => !fromItems.includes(g));
  return [...fromItems, ...defaults];
});

async function load() {
  loading.value = true;
  try { const { data } = await api.get('/admin/menu-items'); items.value = data.items || []; }
  catch (e) { notify(e.response?.data?.message || 'Ошибка загрузки', 'error'); }
  loading.value = false;
}
function openCreate() { Object.assign(form, emptyForm(), { area: area.value }); Object.keys(errs).forEach((k) => delete errs[k]); dialog.value = true; }
function openEdit(item) { Object.assign(form, { ...emptyForm(), ...item, roles: item.roles || [] }); Object.keys(errs).forEach((k) => delete errs[k]); dialog.value = true; }

async function save() {
  saving.value = true; Object.keys(errs).forEach((k) => delete errs[k]);
  try {
    if (form.id) await api.put(`/admin/menu-items/${form.id}`, form);
    else await api.post('/admin/menu-items', form);
    dialog.value = false; await load(); notify('Сохранено');
  } catch (e) {
    if (e.response?.status === 422) { const ve = e.response.data.errors || {}; for (const [k, v] of Object.entries(ve)) errs[k] = v[0]; }
    else notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  saving.value = false;
}
async function remove(item) {
  if (!confirm(`Удалить пункт «${item.title}»?`)) return;
  try { await api.delete(`/admin/menu-items/${item.id}`); await load(); notify('Удалено'); }
  catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
}
// Перестановка внутри текущей области (меняем sort_order соседей).
async function move(item, dir) {
  const list = filtered.value;
  const idx = list.findIndex((x) => x.id === item.id);
  const swap = list[idx + dir];
  if (!swap) return;
  const order = [
    { id: item.id, sort_order: swap.sort_order },
    { id: swap.id, sort_order: item.sort_order },
  ];
  try { await api.post('/admin/menu-items/reorder', { order }); await load(); }
  catch (e) { notify(e.response?.data?.message || 'Ошибка', 'error'); }
}

watch(area, () => { /* фильтрация реактивна */ });
onMounted(load);
</script>
