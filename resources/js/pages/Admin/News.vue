<template>
  <div>
    <PageHeader title="Новости и объявления" icon="mdi-newspaper">
      <template #actions>
        <v-btn v-if="canEdit('news')" color="primary" prepend-icon="mdi-plus" @click="openCreateNews">Добавить</v-btn>
      </template>
    </PageHeader>

    <v-card :loading="loading">
      <div class="d-flex justify-end px-3 pt-2">
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="news-cols" />
      </div>
      <v-data-table :items="items" :headers="visibleHeaders" density="compact" hover>
        <template #item.kind="{ value }">
          <StatusChip :color="value === 'promo' ? 'warning' : 'info'"
            :text="value === 'promo' ? 'Промо' : 'Обновление'" size="x-small" />
        </template>
        <template #item.type="{ value }">
          <StatusChip
            :color="value === 'warning' ? 'warning' : value === 'success' ? 'success' : 'primary'"
            :text="{ info: 'Инфо', warning: 'Важно', success: 'Успех' }[value] || value"
            size="x-small"
          />
        </template>
        <template #item.pinned="{ value }">
          <BooleanCell :value="!!value" :tooltip="{ on: 'Закреплена', off: 'Обычная' }" />
        </template>
        <template #item.active="{ value }">
          <BooleanCell :value="!!value" :tooltip="{ on: 'Активна', off: 'Скрыта' }" />
        </template>
        <template #item.published_at="{ item }">{{ fmtDate(item.published_at || item.created_at) }}</template>
        <template #item.excerpt="{ item }">
          <!-- В базе лежит HTML из редактора — в превью показываем только текст. -->
          <span class="text-body-2">{{ preview(item.excerpt || item.content) }}</span>
        </template>
        <template #item.actions="{ item }">
          <ActionsCell :editable="canEdit('news')" :deletable="canFull('news')"
                       @edit="openEditNews(item)" @delete="confirmDelete(item)" />
        </template>
        <template #no-data>
          <EmptyState message="Нет новостей" icon="mdi-newspaper-variant-outline" />
        </template>
      </v-data-table>
    </v-card>

    <DialogShell
      v-model="editDialog"
      :title="editForm.id ? `Редактировать новость${editForm.title ? ` «${editForm.title}»` : ''}` : 'Новая новость'"
      :max-width="900"
      persistent
      :loading="saving"
      :confirm-disabled="!editForm.title || !editForm.content"
      :confirm-text="editForm.id ? 'Сохранить' : 'Создать'"
      @confirm="save"
    >
      <FormErrors :errors="editErrors" :message="editMessage" />

      <v-tabs v-model="tab" density="compact" color="primary" class="mb-4">
        <v-tab value="main">Основное</v-tab>
        <v-tab value="cover">Обложка и ссылка</v-tab>
        <!-- Вкладка акции появляется только у промо: у обновления этих
             параметров нет, и пустые поля только путают редактора. -->
        <v-tab v-if="isPromo" value="promo">Акция</v-tab>
      </v-tabs>

      <v-window v-model="tab">
        <v-window-item value="main">
          <v-text-field v-model="editForm.title" label="Заголовок *" variant="outlined" density="compact" class="mb-3" />

          <div class="d-flex ga-3 flex-wrap mb-3">
            <v-select v-model="editForm.kind" :items="kindOptions" label="Тег в ленте"
              variant="outlined" density="compact" style="min-width: 200px" />
            <v-select v-model="editForm.type" :items="typeOptions" label="Цвет плашки (старый виджет)"
              variant="outlined" density="compact" style="min-width: 240px" />
            <v-text-field v-model="editForm.published_at" label="Дата публикации" type="date"
              variant="outlined" density="compact" style="min-width: 190px" />
          </div>

          <!-- Анонс отдельно от текста: он рисуется в карточке ленты и в лиде
               страницы. Пустой — соберём из первых строк текста. -->
          <v-textarea v-model="editForm.excerpt" label="Анонс (1–2 предложения)"
            variant="outlined" density="compact" rows="2" auto-grow counter="500" class="mb-3"
            hint="Показывается в ленте и лидом на странице новости. Если пусто — возьмём начало текста."
            persistent-hint />

          <div class="text-caption text-medium-emphasis mb-1">Содержание *</div>
          <RichTextEditor v-model="editForm.content" min-height="240px" />

          <div class="d-flex ga-4 flex-wrap mt-3">
            <v-checkbox v-model="editForm.active" label="Активна (видна всем)" density="compact" hide-details />
            <v-checkbox v-model="editForm.pinned" label="Закрепить в начале ленты" density="compact" hide-details />
          </div>
        </v-window-item>

        <v-window-item value="cover">
          <v-text-field v-model="editForm.cover_url" label="Ссылка на обложку"
            variant="outlined" density="compact" class="mb-1"
            hint="Пусто — нарисуем обложку из фирменных цветов, она меняется вместе с темой."
            persistent-hint />

          <div class="text-subtitle-2 mt-5 mb-2">Надписи на нарисованной обложке</div>
          <div class="d-flex ga-3 flex-wrap">
            <v-text-field v-model="metaCover.eyebrow" label="Надпись сверху" placeholder="ПРОМО · СЕН–ДЕК 2026"
              variant="outlined" density="compact" style="min-width: 260px" />
            <v-text-field v-model="metaCover.numeral" label="Крупная надпись" placeholder="3000+"
              variant="outlined" density="compact" style="min-width: 160px" />
            <v-text-field v-model="metaCover.caption" label="Надпись снизу" placeholder="ЛП → +10% К СТАВКЕ"
              variant="outlined" density="compact" style="min-width: 260px" />
          </div>

          <div class="text-subtitle-2 mt-5 mb-2">Кнопка со ссылкой внизу новости</div>
          <v-text-field v-model="metaCta.url" label="Ссылка" placeholder="https://docs.google.com/..."
            variant="outlined" density="compact" class="mb-3" />
          <div class="d-flex ga-3 flex-wrap">
            <v-text-field v-model="metaCta.label" label="Надпись на кнопке" placeholder="Открыть презентацию"
              variant="outlined" density="compact" style="min-width: 260px" />
            <!-- «Куда ведёт · где лежит»: голую ссылку в тексте не показываем. -->
            <v-text-field v-model="metaCta.note" label="Подпись над кнопкой"
              placeholder="Презентация конкурса · Google Slides"
              variant="outlined" density="compact" style="min-width: 320px" />
          </div>
        </v-window-item>

        <v-window-item value="promo">
          <div class="text-caption text-medium-emphasis mb-3">
            По этим полям кабинет строит панель «Фокус дня» на главной и блок «Ваш прогресс»
            на странице новости. Пока идут даты акции, панель видна партнёру.
          </div>

          <div class="d-flex ga-3 flex-wrap mb-3">
            <v-text-field v-model="metaPromo.title" label="Название акции" placeholder="Промо «3000+»"
              variant="outlined" density="compact" style="min-width: 220px" />
            <v-text-field v-model.number="metaPromo.target" label="Цель за месяц" type="number"
              variant="outlined" density="compact" style="min-width: 150px" />
            <v-text-field v-model="metaPromo.unit" label="Единица" placeholder="ЛП"
              variant="outlined" density="compact" style="min-width: 110px" />
            <v-text-field v-model="metaPromo.bonusLabel" label="Что даёт" placeholder="+10% к ставке"
              variant="outlined" density="compact" style="min-width: 200px" />
          </div>
          <div class="d-flex ga-3 flex-wrap mb-4">
            <v-text-field v-model="metaPromo.from" label="Акция с" type="date"
              variant="outlined" density="compact" style="min-width: 180px" />
            <v-text-field v-model="metaPromo.to" label="Акция по" type="date"
              variant="outlined" density="compact" style="min-width: 180px" />
            <v-text-field v-model="metaPromo.monthHint" label="Подпись месяца" placeholder="+10%"
              variant="outlined" density="compact" style="min-width: 160px" />
          </div>

          <v-divider class="mb-4" />
          <div class="text-subtitle-2 mb-2">Правило «было → стало»</div>
          <div class="d-flex ga-3 flex-wrap mb-4">
            <v-text-field v-model="metaPromo.rule.left.value" label="Слева" placeholder="3 000"
              variant="outlined" density="compact" style="min-width: 140px" />
            <v-text-field v-model="metaPromo.rule.left.caption" label="Подпись слева" placeholder="баллов ЛП за месяц"
              variant="outlined" density="compact" style="min-width: 260px" />
            <v-text-field v-model="metaPromo.rule.right.value" label="Справа" placeholder="+10%"
              variant="outlined" density="compact" style="min-width: 140px" />
            <v-text-field v-model="metaPromo.rule.right.caption" label="Подпись справа"
              placeholder="от комиссии DS по вашему ЛП"
              variant="outlined" density="compact" style="min-width: 260px" />
          </div>

          <div class="text-subtitle-2 mb-2">Пример на цифрах</div>
          <div class="d-flex ga-3 flex-wrap mb-4">
            <v-text-field v-model="metaPromo.example.left.value" label="Было" placeholder="25%"
              variant="outlined" density="compact" style="min-width: 140px" />
            <v-text-field v-model="metaPromo.example.left.caption" label="Подпись"
              placeholder="стандартная ставка, квалификация «Эксперт»"
              variant="outlined" density="compact" style="min-width: 300px" />
            <v-text-field v-model="metaPromo.example.right.value" label="Стало" placeholder="35%"
              variant="outlined" density="compact" style="min-width: 140px" />
            <v-text-field v-model="metaPromo.example.right.caption" label="Подпись"
              placeholder="ставка за месяц с 3 000 ЛП"
              variant="outlined" density="compact" style="min-width: 300px" />
          </div>

          <v-textarea v-model="metaPromo.monthsNote" label="Пояснение к месяцам"
            placeholder="Условие можно выполнить в каждом из четырёх месяцев — каждый считается отдельно."
            variant="outlined" density="compact" rows="2" auto-grow class="mb-3" />

          <v-textarea v-model="metaPromo.note" label="Важное примечание (оранжевая плашка)"
            placeholder="<b>Важно:</b> дополнительные 10% не идут в НГП."
            variant="outlined" density="compact" rows="2" auto-grow class="mb-4"
            hint="Можно выделить жирным через <b>…</b>." persistent-hint />

          <v-divider class="mb-4" />
          <div class="d-flex align-center justify-space-between mb-2">
            <div class="text-subtitle-2">Советы, как выполнить условие</div>
            <v-btn size="small" variant="tonal" prepend-icon="mdi-plus" @click="addStep">Добавить</v-btn>
          </div>
          <v-text-field v-model="metaPromo.stepsTitle" label="Заголовок блока советов"
            placeholder="Как добрать баллы" variant="outlined" density="compact" class="mb-3" />
          <div v-for="(step, i) in metaPromo.steps" :key="i" class="d-flex ga-2 align-start mb-2">
            <v-select v-model="step.icon" :items="stepIconOptions" label="Значок"
              variant="outlined" density="compact" style="max-width: 190px" hide-details />
            <v-text-field v-model="step.text" label="Текст совета"
              variant="outlined" density="compact" hide-details />
            <v-btn icon="mdi-close" size="small" variant="text" aria-label="Убрать совет"
              @click="metaPromo.steps.splice(i, 1)" />
          </div>

          <v-textarea v-model="metaPromo.outro" label="Фраза в конце"
            placeholder="Никаких рейтингов и ожидания до конца года — результат зависит только от вас."
            variant="outlined" density="compact" rows="2" auto-grow class="mt-3" />
        </v-window-item>
      </v-window>
    </DialogShell>

    <DialogShell
      v-model="deleteDialog"
      title="Удалить новость?"
      :max-width="400"
      :loading="saving"
      confirm-text="Удалить"
      confirm-color="error"
      @confirm="remove"
    >
      {{ deleteTarget?.title }}
    </DialogShell>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import {
  PageHeader, DialogShell, StatusChip, BooleanCell, ActionsCell, FormErrors, RichTextEditor, ColumnVisibilityMenu, EmptyState,
} from '../../components';
import { useCrud } from '../../composables/useCrud';
import { usePermissions } from '../../composables/usePermissions';
import { fmtDate } from '../../composables/useDesign';
import { htmlToText } from '../../composables/useSafeHtml';

/**
 * Редактор новостей кабинета.
 *
 * Кроме заголовка и текста здесь заводятся поля, по которым кабинет рисует
 * ленту и страницу новости: тег, анонс, обложка, закрепление и — у промо —
 * параметры акции. Последние лежат в news.meta, чтобы следующую акцию
 * заводил редактор, а не разработчик (per ds-redesign/CLAUDE_TASK.md).
 */

// Пустая заготовка meta: форма всегда работает с полной структурой, а лишнее
// вычищается перед отправкой. Иначе v-model спотыкается об undefined.
function emptyMeta() {
  return {
    cover: { eyebrow: '', numeral: '', caption: '' },
    cta: { url: '', label: '', note: '' },
    promo: {
      title: '', target: null, unit: 'ЛП', bonusLabel: '', from: '', to: '',
      rule: { left: { value: '', caption: '' }, right: { value: '', caption: '' } },
      example: { left: { value: '', caption: '' }, right: { value: '', caption: '' } },
      monthsNote: '', monthHint: '', note: '', stepsTitle: '', steps: [], outro: '',
    },
  };
}

// Слияние с заготовкой по уровням: у старых новостей meta пустая или неполная.
function mergeMeta(raw) {
  const base = emptyMeta();
  let parsed = raw;
  // Из /admin/news jsonb приходит строкой — разбираем.
  if (typeof parsed === 'string') {
    try { parsed = JSON.parse(parsed); } catch { parsed = null; }
  }
  if (! parsed || typeof parsed !== 'object') return base;

  return {
    cover: { ...base.cover, ...(parsed.cover || {}) },
    cta: { ...base.cta, ...(parsed.cta || {}) },
    promo: {
      ...base.promo,
      ...(parsed.promo || {}),
      rule: {
        left: { ...base.promo.rule.left, ...(parsed.promo?.rule?.left || {}) },
        right: { ...base.promo.rule.right, ...(parsed.promo?.rule?.right || {}) },
      },
      example: {
        left: { ...base.promo.example.left, ...(parsed.promo?.example?.left || {}) },
        right: { ...base.promo.example.right, ...(parsed.promo?.example?.right || {}) },
      },
      steps: Array.isArray(parsed.promo?.steps) ? parsed.promo.steps.map((s) => ({ ...s })) : [],
    },
  };
}

const today = () => new Date().toISOString().slice(0, 10);

const {
  items, loading,
  editDialog, editForm, editErrors, editMessage, saving,
  deleteDialog, deleteTarget,
  load, openCreate, openEdit, save, confirmDelete, remove,
} = useCrud('admin/news', {
  defaults: {
    title: '', content: '', type: 'info', active: true,
    kind: 'update', excerpt: '', cover_url: '', pinned: false,
    published_at: today(), meta: emptyMeta(),
  },
  normalise: (d) => ({
    items: Array.isArray(d) ? d : (d.items ?? d.data ?? []),
    total: Array.isArray(d) ? d.length : (d.total ?? 0),
  }),
  // Пустые поля не сохраняем: пустая строка в базе — это не «не задано», и
  // лента потом рисует пустой тег вместо того, чтобы обойтись без него.
  beforeSave: (form) => {
    const meta = mergeMeta(form.meta);
    const clean = {};

    const cover = prune(meta.cover);
    if (cover) clean.cover = cover;
    const cta = prune(meta.cta);
    if (cta?.url) clean.cta = cta;

    if (form.kind === 'promo') {
      const promo = prune({
        ...meta.promo,
        rule: prune({ left: prune(meta.promo.rule.left), right: prune(meta.promo.rule.right) }),
        example: prune({ left: prune(meta.promo.example.left), right: prune(meta.promo.example.right) }),
        steps: meta.promo.steps.filter((s) => s.text?.trim()),
      });
      if (promo) clean.promo = promo;
    }

    return {
      ...form,
      excerpt: form.excerpt?.trim() || null,
      cover_url: form.cover_url?.trim() || null,
      published_at: form.published_at || null,
      meta: Object.keys(clean).length ? clean : null,
    };
  },
  labels: {
    created: 'Новость создана',
    updated: 'Новость обновлена',
    deleted: 'Новость удалена',
    error: 'Ошибка',
  },
});

/** Убирает пустые значения; возвращает null, если не осталось ничего. */
function prune(obj) {
  if (! obj || typeof obj !== 'object') return null;
  const out = {};
  for (const [key, value] of Object.entries(obj)) {
    if (value === null || value === undefined || value === '') continue;
    if (Array.isArray(value)) {
      if (value.length) out[key] = value;
      continue;
    }
    if (typeof value === 'object') {
      const nested = prune(value);
      if (nested) out[key] = nested;
      continue;
    }
    out[key] = value;
  }
  return Object.keys(out).length ? out : null;
}

const tab = ref('main');
const isPromo = computed(() => editForm.value.kind === 'promo');

// Ссылки на ветки meta — чтобы в шаблоне не писать editForm.meta.promo.rule…
const metaCover = computed(() => editForm.value.meta.cover);
const metaCta = computed(() => editForm.value.meta.cta);
const metaPromo = computed(() => editForm.value.meta.promo);

function openCreateNews() {
  openCreate();
  editForm.value.meta = emptyMeta();
  tab.value = 'main';
}

function openEditNews(item) {
  openEdit(item);
  editForm.value.meta = mergeMeta(item.meta);
  editForm.value.kind = item.kind || 'update';
  editForm.value.excerpt = item.excerpt || '';
  editForm.value.cover_url = item.cover_url || '';
  editForm.value.pinned = !! item.pinned;
  // published_at приходит как «2026-09-21 10:00:00» — полю type=date нужна дата.
  editForm.value.published_at = String(item.published_at || item.created_at || '').slice(0, 10) || today();
  tab.value = 'main';
}

function addStep() {
  metaPromo.value.steps.push({ icon: 'users', text: '' });
}

const kindOptions = [
  { title: 'Обновление', value: 'update' },
  { title: 'Промо (акция)', value: 'promo' },
];

const typeOptions = [
  { title: 'Информация', value: 'info' },
  { title: 'Важное', value: 'warning' },
  { title: 'Успех', value: 'success' },
];

// Значки советов — те, что умеет рисовать страница новости.
const stepIconOptions = [
  { title: 'Клиенты', value: 'users' },
  { title: 'Документ', value: 'file' },
  { title: 'Разговор', value: 'chat' },
  { title: 'Календарь', value: 'calendar' },
];

const headers = [
  { title: 'Заголовок', key: 'title' },
  { title: 'Анонс', key: 'excerpt' },
  { title: 'Тег', key: 'kind', width: 120 },
  { title: 'Цвет', key: 'type', width: 100 },
  { title: 'Закреплена', key: 'pinned', width: 110 },
  { title: 'Активна', key: 'active', width: 90 },
  { title: 'Дата', key: 'published_at', width: 120 },
  { title: '', key: 'actions', sortable: false, width: 80 },
];

function preview(value) {
  const text = htmlToText(value);
  return text.length > 80 ? `${text.slice(0, 80)}...` : text;
}

const { canEdit, canFull } = usePermissions();

const columnVisible = ref({});
const visibleHeaders = computed(() => headers.filter(h => columnVisible.value[h.key] !== false));

onMounted(load);
</script>
