<template>
  <div class="pay">
    <header class="ph">
      <div class="ph-t">
        <h1>Реестр выплат</h1>
        <p>Начисления, выплаты и остаток по месяцам</p>
      </div>

      <!-- Переключатель месяца живёт в шапке: он задаёт период сразу всем
           блокам страницы, отдельной карточкой его было легко проглядеть. -->
      <div class="month">
        <button type="button" class="month-nav" aria-label="Предыдущий месяц" @click="prevMonth">
          <ChevronLeft :size="18" :stroke-width="1.8" />
        </button>
        <span>{{ monthLabel }}</span>
        <button type="button" class="month-nav" aria-label="Следующий месяц"
          :disabled="isCurrentMonth" @click="nextMonth">
          <ChevronRight :size="18" :stroke-width="1.8" />
        </button>
      </div>
    </header>

    <div v-if="loading" class="bar" role="progressbar" aria-label="Загрузка"><span></span></div>

    <template v-if="summary">
      <section class="sum">
        <!-- Главная цифра месяца — на тёмном фирменном фоне: партнёр ищет
             на этой странице ровно её. -->
        <div class="due">
          <div class="due-main">
            <div class="due-eyebrow">К оплате за {{ monthLabelLower }}</div>
            <div class="due-big">{{ fmt(summary.totalPayable) }} ₽</div>
          </div>
          <div class="due-rest">
            <span>Оплачено <b>{{ fmt(summary.payed) }} ₽</b></span>
            <span>Остаток <b>{{ fmt(summary.remaining) }} ₽</b></span>
          </div>
        </div>

        <!-- Расшифровка суммы к оплате слагаемыми ровно в том порядке,
             в каком её собирает MyPaymentsController. -->
        <div class="eq">
          <div class="eq-h">Из чего складывается сумма к оплате</div>
          <div class="eq-row">
            <template v-for="(t, i) in terms" :key="t.label">
              <span v-if="i" class="eq-op" aria-hidden="true">+</span>
              <div class="term" :class="{ zero: !t.value }">
                <span>{{ t.label }}</span>
                <b>{{ fmt(t.value) }} ₽</b>
              </div>
            </template>
          </div>
          <p class="eq-note">{{ eqNote }}</p>
        </div>
      </section>

      <section class="card">
        <header class="card-head">
          <h2 class="card-h">
            <ReceiptText :size="18" :stroke-width="1.8" />
            Выплаты за {{ monthLabelLower }}
          </h2>
          <span v-if="summary.status" class="tag" :class="statusTone(summary.status)">
            {{ summary.status }}
          </span>
        </header>

        <div v-if="payments.length" class="tbl-wrap bleed">
          <table class="tbl">
            <thead>
              <tr>
                <th>Дата</th>
                <th class="r">Сумма</th>
                <th>Статус</th>
                <th>Комментарий</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in payments" :key="p.id">
                <td class="lead" data-th="Дата">{{ formatDate(p.paymentDate) }}</td>
                <td class="r num" data-th="Сумма">{{ fmt(p.amount) }} ₽</td>
                <td data-th="Статус">
                  <span class="tag" :class="paymentStatusTone(p.status)">{{ p.statusName ?? '—' }}</span>
                </td>
                <td class="wrap wide" data-th="Комментарий">{{ p.comment ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-else class="empty-lg">
          <span class="empty-ic"><Wallet :size="24" :stroke-width="1.8" /></span>
          <b>В этом месяце выплат ещё не было</b>
          <span>Остаток <span class="num">{{ fmt(summary.remaining) }} ₽</span> перейдёт в выплату, когда её проведут.</span>
        </div>
      </section>

      <section class="card flush">
        <div class="tbl-h">
          <h2 class="card-h">
            <CalendarDays :size="18" :stroke-width="1.8" />
            История по периодам
          </h2>
          <label class="switch">
            <input v-model="onlyMoved" type="checkbox">
            <i aria-hidden="true"></i>
            Только месяцы с движением
          </label>
          <button type="button" class="btn sm" :disabled="!history.length" @click="exportCsv">
            <Download :size="16" :stroke-width="1.8" />
            CSV
          </button>
        </div>

        <div v-if="visibleHistory.length" class="tbl-wrap">
          <table class="tbl">
            <thead>
              <tr>
                <th>Период</th>
                <th class="r">Начислено</th>
                <th class="r">Пул</th>
                <th class="r">Прочее</th>
                <th class="r">Оплачено</th>
                <th class="r">Остаток</th>
                <th>Статус</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="h in visibleHistory" :key="h.dateMonth" :class="{ cur: h.dateMonth === currentDm }">
                <td class="lead" data-th="Период">{{ fmtMonth(h.dateMonth) }}</td>
                <td class="r num" :class="{ zero: !h.accrued }" data-th="Начислено">{{ fmt(h.accrued) }} ₽</td>
                <td class="r num" :class="{ zero: !h.pool }" data-th="Пул">{{ fmt(h.pool) }} ₽</td>
                <td class="r num" :class="{ zero: !h.other }" data-th="Прочее">{{ fmt(h.other) }} ₽</td>
                <td class="r num" :class="{ zero: !h.payed }" data-th="Оплачено">{{ fmt(h.payed) }} ₽</td>
                <td class="r num wide" :class="h.remaining > 0 ? 'accent' : 'zero'" data-th="Остаток">{{ fmt(h.remaining) }} ₽</td>
                <td class="wide" data-th="Статус">
                  <span v-if="h.status" class="tag" :class="statusTone(h.status)">{{ h.status }}</span>
                  <span v-else class="muted">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-else class="empty-sm">
          <span class="empty-ic sm"><CalendarDays :size="20" :stroke-width="1.8" /></span>
          {{ history.length ? 'За последние месяцы движения не было' : 'История выплат пуста' }}
        </div>

        <div v-if="history.length" class="tbl-f">
          {{ history.length }} {{ plural(history.length, 'месяц', 'месяца', 'месяцев') }} · с движением: {{ movedCount }}
        </div>
      </section>
    </template>

    <div v-else-if="!loading" class="empty-lg card">
      <span class="empty-ic"><Inbox :size="24" :stroke-width="1.8" /></span>
      <b>Данные за выбранный период отсутствуют</b>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { ChevronLeft, ChevronRight, ReceiptText, CalendarDays, Download, Wallet, Inbox } from 'lucide-vue-next';
import api from '../api';
import { fmt } from '../composables/useDesign';

/**
 * Реестр выплат партнёра по дизайну v2 (эталон design/payouts).
 *
 * Страница про деньги: запрос, поля и форматирование сумм остались прежними,
 * изменилось только оформление. Ни одной цифры здесь не считаем — всё, что
 * показываем, приходит из /my-payments уже посчитанным.
 */
const loading = ref(false);
const summary = ref(null);
const payments = ref([]);
const history = ref([]);
const onlyMoved = ref(false);

const now = new Date();
const year = ref(now.getFullYear());
const month = ref(now.getMonth() + 1);

const currentDm = computed(() => `${year.value}-${String(month.value).padStart(2, '0')}`);

const isCurrentMonth = computed(() =>
  year.value === now.getFullYear() && month.value === now.getMonth() + 1
);

const monthNames = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
  'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

const monthLabel = computed(() => `${monthNames[month.value - 1]} ${year.value}`);
const monthLabelLower = computed(() => monthLabel.value.toLowerCase());

// Слагаемые суммы к оплате — те же поля summary, что и раньше, просто
// разложены по плиткам в порядке формулы контроллера.
const terms = computed(() => [
  { label: 'Сальдо на начало', value: summary.value?.balance },
  { label: 'Начислено', value: summary.value?.accrued },
  { label: 'Пул', value: summary.value?.pool },
  { label: 'Прочее', value: summary.value?.other },
]);

const eqNote = computed(() => (Number(summary.value?.accruedTotal) || 0) === 0
  ? 'В этом месяце новых начислений нет — к оплате переходит сальдо прошлых периодов.'
  : 'Сальдо прошлых периодов плюс начисления этого месяца.');

// «Движение» = в месяце было хоть одно начисление или выплата. Остаток сюда
// не входит: он тянется из прошлых периодов и есть почти всегда.
function hasMovement(h) {
  return Boolean(h.accrued || h.pool || h.other || h.payed);
}

const movedCount = computed(() => history.value.filter(hasMovement).length);
const visibleHistory = computed(() =>
  onlyMoved.value ? history.value.filter(hasMovement) : history.value
);

function prevMonth() {
  if (month.value === 1) { month.value = 12; year.value--; }
  else month.value--;
  loadData();
}

function nextMonth() {
  if (isCurrentMonth.value) return;
  if (month.value === 12) { month.value = 1; year.value++; }
  else month.value++;
  loadData();
}

async function loadData() {
  loading.value = true;
  summary.value = null;
  payments.value = [];
  history.value = [];
  try {
    const { data } = await api.get('/my-payments', { params: { year: year.value, month: month.value } });
    summary.value = data.summary;
    payments.value = data.payments ?? [];
    history.value = data.history ?? [];
  } catch {}
  loading.value = false;
}

function formatDate(val) {
  if (!val) return '—';
  const d = new Date(val);
  if (isNaN(d.getTime())) return val;
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function fmtMonth(dm) {
  if (!dm) return '—';
  const [y, m] = dm.split('-');
  return `${monthNames[parseInt(m, 10) - 1]} ${y}`;
}

function plural(n, one, few, many) {
  const a = Math.abs(n) % 100;
  const b = a % 10;
  if (a > 10 && a < 20) return many;
  if (b > 1 && b < 5) return few;
  if (b === 1) return one;
  return many;
}

// Тональности те же, что давали цвета чипам Vuetify: логика распознавания
// статуса не менялась, поменялась только палитра на токены.
function statusTone(status) {
  if (!status) return 'neutral';
  const s = status.toLowerCase();
  if (s.includes('полностью')) return 'ok';
  if (s.includes('частично')) return 'warn';
  if (s.includes('обработ')) return 'info';
  return 'neutral';
}

function paymentStatusTone(status) {
  if (status === 2) return 'ok';
  if (status === 1) return 'info';
  if (status === 3) return 'danger';
  return 'neutral';
}

/**
 * Выгрузка истории в CSV. Ничего не пересчитывает: берёт те же значения,
 * что стоят в таблице, только без пробелов-разделителей разрядов — иначе
 * Excel читает сумму как текст. Разделитель `;` и BOM — чтобы русская
 * локаль Excel открыла файл без «Мастера импорта».
 */
function exportCsv() {
  const head = ['Период', 'Начислено', 'Пул', 'Прочее', 'Оплачено', 'Остаток', 'Статус'];
  const num = (v) => String(Number(v) || 0).replace('.', ',');
  const rows = visibleHistory.value.map((h) => [
    fmtMonth(h.dateMonth), num(h.accrued), num(h.pool), num(h.other),
    num(h.payed), num(h.remaining), h.status ?? '',
  ]);
  const csv = [head, ...rows]
    .map((r) => r.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(';'))
    .join('\r\n');

  const url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' }));
  const a = document.createElement('a');
  a.href = url;
  a.download = `payouts-${currentDm.value}.csv`;
  // Ссылку кладём в документ: без этого часть браузеров не начинает
  // скачивание, а отзываем URL следующим тиком — иначе гонка с загрузкой.
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 0);
}

onMounted(loadData);
</script>

<style scoped>
.pay {
  display: flex;
  flex-direction: column;
  gap: var(--space-6);
  max-width: 1480px;
  font-family: var(--font-sans);
  color: var(--ink);
}

.num, .due-big, .term b, .tbl .num { font-variant-numeric: tabular-nums; }
.muted { color: var(--ink-muted); }

/* ---------- шапка ---------- */
.ph {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
}
.ph-t { min-width: 0; }
.ph h1 { margin: 0; font: 700 28px/34px var(--font-sans); letter-spacing: -0.015em; }
.ph p { margin: 4px 0 0; font: 400 14px/22px var(--font-sans); color: var(--ink-muted); }

.month {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  height: 40px;
  padding: 0 4px;
  border-radius: var(--radius-md);
  background: var(--surface);
  border: 1px solid var(--border);
  box-shadow: var(--shadow-card);
}
.month > span {
  min-width: 132px;
  text-align: center;
  font: 600 14px/1 var(--font-sans);
}
.month-nav {
  display: inline-grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border: 0;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--ink-muted);
  cursor: pointer;
}
.month-nav:hover:not(:disabled) { background: var(--surface-2); color: var(--ink); }
.month-nav:disabled { opacity: 0.35; cursor: default; }
.month-nav:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }

/* Индикатор загрузки: карточки при перелистывании месяца не прыгают,
   поэтому достаточно тонкой полосы вместо скелетона всей страницы. */
.bar {
  height: 3px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  overflow: hidden;
}
.bar span {
  display: block;
  width: 35%;
  height: 100%;
  border-radius: var(--radius-pill);
  background: var(--brand);
  animation: bar 1.1s ease-in-out infinite;
}
@keyframes bar {
  0% { transform: translateX(-100%); }
  100% { transform: translateX(300%); }
}
@media (prefers-reduced-motion: reduce) {
  .bar span { animation: none; width: 100%; opacity: 0.5; }
}

/* ---------- сумма к оплате ---------- */
.sum {
  display: grid;
  grid-template-columns: 340px minmax(0, 1fr);
  gap: var(--space-6);
  align-items: stretch;
}

.due {
  padding: var(--space-6);
  border-radius: var(--radius-xl);
  background: var(--brand-deep);
  color: var(--on-brand-deep);
  overflow: hidden;
}
.due-eyebrow {
  font: 600 11px/14px var(--font-sans);
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--brand-glow);
}
.due-big {
  margin-top: 10px;
  font: 800 44px/48px var(--font-sans);
  letter-spacing: -0.03em;
}
.due-rest {
  display: grid;
  gap: 6px;
  margin-top: var(--space-5);
  padding-top: var(--space-4);
  /* Разделитель на тёмном фоне — токеном, а не полупрозрачным белым:
     в тёмной теме фон другой, и rgba() давала бы грязную линию. */
  border-top: 1px solid var(--brand-deep-2);
  font: 400 14px/22px var(--font-sans);
  color: var(--on-brand-deep-muted);
}
.due-rest span { display: flex; justify-content: space-between; gap: var(--space-3); }
.due-rest b { color: var(--on-brand-deep); font-variant-numeric: tabular-nums; }

.eq {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: var(--space-4);
  padding: var(--space-5) var(--space-6);
  border-radius: var(--radius-xl);
  background: var(--surface);
  border: 1px solid var(--border);
  box-shadow: var(--shadow-card);
  min-width: 0;
}
.eq-h { font: 600 15px/22px var(--font-sans); }
.eq-row { display: flex; align-items: center; gap: var(--space-3); flex-wrap: wrap; }
.eq-op { font: 600 20px/1 var(--font-sans); color: var(--ink-muted); }
.eq-note { margin: 0; font: 400 13px/18px var(--font-sans); color: var(--ink-muted); }

.term {
  flex: 1;
  min-width: 120px;
  padding: var(--space-4);
  border-radius: var(--radius-md);
  background: var(--surface-2);
}
.term span { display: block; font: 400 12px/16px var(--font-sans); color: var(--ink-muted); }
.term b { display: block; margin-top: 4px; font: 700 22px/28px var(--font-sans); }
/* Нулевое слагаемое гасим: взгляд должен цепляться за то, что не ноль. */
.term.zero b { color: var(--ink-muted); }

/* ---------- карточки ---------- */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-card);
  padding: var(--space-5);
  /* Таблица внутри тянется в края карточки — без обрезки её углы вылезали
     за скруглённую рамку. */
  overflow: hidden;
}
.card.flush { padding: 0; }

.card-head {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  flex-wrap: wrap;
  margin-bottom: var(--space-4);
}
.card-h {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0 auto 0 0;
  font: 600 17px/24px var(--font-sans);
}
.card-h svg { color: var(--brand); flex: 0 0 auto; }

/* ---------- таблицы ---------- */
.tbl-h {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  flex-wrap: wrap;
  padding: var(--space-5) var(--space-5) var(--space-4);
}
.tbl-h .card-h { margin: 0 auto 0 0; }

.tbl-wrap { overflow-x: auto; }
/* Таблица внутри карточки с падингом тянется в края — шапка строк должна
   доходить до границы карточки, как в «истории по периодам». */
.tbl-wrap.bleed { margin: 0 calc(var(--space-5) * -1) calc(var(--space-5) * -1); }

.tbl { width: 100%; border-collapse: collapse; font-size: 14px; }
.tbl th {
  padding: 10px var(--space-5);
  text-align: left;
  font: 600 11px/14px var(--font-sans);
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--ink-muted);
  background: var(--surface-2);
  white-space: nowrap;
}
.tbl td {
  padding: 12px var(--space-5);
  border-top: 1px solid var(--border);
  vertical-align: middle;
  white-space: nowrap;
}
.tbl td.wrap { white-space: normal; color: var(--ink-muted); }
.tbl .r { text-align: right; }
.tbl .lead { font-weight: 600; }
.tbl .zero { color: var(--ink-muted); }
.tbl .accent { color: var(--accent); font-weight: 600; }
.tbl tbody tr:hover td { background: var(--surface-2); }
/* Выбранный месяц подсвечен и при наведении — правило ниже hover'а. */
.tbl tbody tr.cur td { background: var(--brand-soft); }

.tbl-f {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  flex-wrap: wrap;
  padding: var(--space-3) var(--space-5);
  border-top: 1px solid var(--border);
  font: 400 13px/18px var(--font-sans);
  color: var(--ink-muted);
}

/* ---------- контролы ---------- */
.btn {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  height: 40px;
  padding: 0 var(--space-4);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  background: var(--surface);
  color: var(--ink);
  font: 500 13px/18px var(--font-sans);
  cursor: pointer;
}
.btn.sm { height: 32px; padding: 0 var(--space-3); border-radius: var(--radius-sm); }
.btn:hover:not(:disabled) { background: var(--surface-2); }
.btn:disabled { opacity: 0.45; cursor: default; }
.btn:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }

.switch {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  font: 400 13px/18px var(--font-sans);
  color: var(--ink-muted);
  cursor: pointer;
  user-select: none;
}
.switch input { position: absolute; opacity: 0; pointer-events: none; }
.switch i {
  position: relative;
  width: 36px;
  height: 20px;
  flex: 0 0 auto;
  border-radius: var(--radius-pill);
  background: var(--border-strong);
  transition: background 0.2s;
}
.switch i::after {
  content: '';
  position: absolute;
  top: 2px;
  left: 2px;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background: var(--surface);
  transition: transform 0.2s;
}
.switch input:checked + i { background: var(--brand); }
.switch input:checked + i::after { transform: translateX(16px); }
.switch input:focus-visible + i { outline: 2px solid var(--focus); outline-offset: 2px; }

.tag {
  display: inline-flex;
  align-items: center;
  min-height: 24px;
  padding: 2px 10px;
  border-radius: var(--radius-pill);
  font: 600 12px/16px var(--font-sans);
  background: var(--surface-2);
  color: var(--ink-muted);
  white-space: normal;
}
.tag.ok { background: var(--brand-soft); color: var(--brand); }
.tag.warn { background: var(--accent-soft); color: var(--accent); }
.tag.info { background: var(--info-soft); color: var(--info); }
.tag.danger { background: var(--accent-soft); color: var(--danger); }

/* ---------- пустые состояния ---------- */
.empty-lg, .empty-sm {
  display: grid;
  justify-items: center;
  gap: var(--space-2);
  text-align: center;
  font: 400 13px/18px var(--font-sans);
  color: var(--ink-muted);
}
.empty-lg { padding: var(--space-8) var(--space-4); }
.empty-sm { padding: var(--space-8) var(--space-4); }
.empty-lg b { font: 600 15px/22px var(--font-sans); color: var(--ink); }
.empty-ic {
  display: grid;
  place-items: center;
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: var(--brand-soft);
  color: var(--brand);
}
.empty-ic.sm { width: 44px; height: 44px; background: var(--surface-2); color: var(--ink-muted); }

/* ---------- адаптив ---------- */
/* < 1320px: расшифровка уходит под сумму, а сама сумма разворачивается в
   ленту — в колонке 300px «12 500 ₽» ломалось на две строки. */
@media (max-width: 1320px) {
  .sum { grid-template-columns: minmax(0, 1fr); gap: var(--space-4); }
  .due {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: var(--space-5);
    flex-wrap: wrap;
    padding: var(--space-5) var(--space-6);
  }
  .due-rest {
    min-width: 240px;
    margin-top: 0;
    padding: 0 0 0 var(--space-5);
    border-top: 0;
    border-left: 1px solid var(--brand-deep-2);
  }
  .eq { padding: var(--space-5); }
}

/* < 1180px: слагаемые в одну строку оставляем (формула читается целиком),
   но уплотняем — иначе на 1100 с раскрытым меню «3 200,5 ₽» переносится. */
@media (max-width: 1180px) {
  .term { padding: var(--space-3); min-width: 104px; }
  .term b { font-size: 20px; line-height: 26px; }
}

/* < 860px: таблицы превращаются в карточки строк — семь числовых колонок
   на телефоне дают горизонтальную прокрутку и нечитаемый шрифт. */
@media (max-width: 860px) {
  .ph { align-items: flex-start; }
  .ph h1 { font-size: 24px; line-height: 30px; }
  .month { width: 100%; justify-content: space-between; }
  .month > span { flex: 1; }

  .due { padding: var(--space-5); }
  .due-big { font-size: 36px; line-height: 42px; }
  .due-rest {
    min-width: 0;
    width: 100%;
    padding: var(--space-4) 0 0;
    border-left: 0;
    border-top: 1px solid var(--brand-deep-2);
  }

  /* Знаки «+» декоративные: на двух слагаемых в ряд они переносились на
     начало следующей строки и читались как мусор. */
  .eq-op { display: none; }
  .eq-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .term { min-width: 0; }

  .tbl-wrap, .tbl-wrap.bleed { overflow-x: visible; }
  .tbl-wrap.bleed { margin: 0 calc(var(--space-5) * -1) calc(var(--space-5) * -1); }
  .tbl thead { display: none; }
  .tbl tbody tr {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-2) var(--space-3);
    padding: var(--space-4) var(--space-5);
    border-top: 1px solid var(--border);
  }
  .tbl td {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-2);
    padding: 0;
    border: 0;
    white-space: normal;
  }
  /* Подпись колонки переезжает в саму ячейку — без шапки иначе не понять,
     что за цифра. */
  .tbl td::before {
    content: attr(data-th);
    font: 400 12px/16px var(--font-sans);
    color: var(--ink-muted);
  }
  .tbl td.lead {
    grid-column: 1 / -1;
    font: 600 15px/22px var(--font-sans);
  }
  .tbl td.lead::before { display: none; }
  .tbl td.wide { grid-column: 1 / -1; }
  .tbl .r { text-align: right; }
  .tbl tbody tr:hover td, .tbl tbody tr.cur td { background: none; }
  .tbl tbody tr.cur { background: var(--brand-soft); }
}
</style>
