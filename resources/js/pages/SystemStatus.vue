<template>
  <div class="status">
    <header class="ph">
      <div class="ph-titles">
        <h1>Статус системы</h1>
        <p>Работа сервисов платформы и история инцидентов</p>
      </div>
      <router-link v-if="auth.isAdmin" class="ph-link" to="/manage/system-status">
        <Settings :size="16" :stroke-width="1.8" />
        Управление
      </router-link>
    </header>

    <!-- Сводка: единственный блок, который партнёр читает, не листая страницу.
         Пока всё хорошо — тёмно-зелёный герой; при проблеме фон становится
         обычной карточкой, иначе «всё штатно» и красная иконка спорят. -->
    <section :class="['sys', `tone-${tone(overall.status)}`]">
      <span class="sys-ic">
        <component :is="statusIcon(overall.status)" :size="28" :stroke-width="2.2" />
      </span>
      <div class="sys-body" aria-live="polite">
        <h2>{{ overall.label || 'Загрузка…' }}</h2>
        <p v-if="updatedAt">Обновлено сегодня в <span class="num">{{ updatedAt }}</span></p>
      </div>
      <button type="button" class="sys-btn" :disabled="refreshing" @click="load">
        <RefreshCw :size="16" :stroke-width="1.8" :class="{ spin: refreshing }" />
        Обновить
      </button>
    </section>

    <div v-if="loading" class="skeleton"></div>

    <template v-else>
      <section class="card">
        <h2 class="card-h">
          <LayoutGrid :size="18" :stroke-width="1.8" />
          Компоненты
          <span v-if="components.length" class="card-more">
            {{ okCount }} из {{ components.length }} работают
          </span>
        </h2>

        <p v-if="!components.length" class="empty">Компоненты не настроены.</p>

        <div v-else class="comps">
          <div v-for="c in components" :key="c.id" class="comp">
            <span class="comp-ic">
              <component :is="componentIcon(c.name)" :size="18" :stroke-width="1.8" />
            </span>
            <span class="comp-body">
              <span class="comp-name">{{ c.name }}</span>
              <span v-if="c.description" class="comp-desc">{{ c.description }}</span>
            </span>
            <span :class="['tag', `tone-${tone(c.status)}`]">
              <span class="dot"></span>{{ statusLabel(c.status) }}
            </span>
          </div>
        </div>
      </section>

      <!-- Активные инциденты идут выше истории и с раскрытой лентой апдейтов:
           именно за ними приходят на страницу во время сбоя. -->
      <section v-if="active.length" class="card">
        <h2 class="card-h">
          <TriangleAlert :size="18" :stroke-width="1.8" class="ic-warn" />
          Активные инциденты
        </h2>

        <ol class="tl">
          <li v-for="i in active" :key="i.id">
            <span :class="['tl-dot', `tone-${severityTone(i.severity)}`]">
              <component :is="severityIcon(i.severity)" :size="14" :stroke-width="2.2" />
            </span>
            <div class="tl-b">
              <div class="tl-h">
                <b>{{ i.title }}</b>
                <span :class="['tag', `tone-${severityTone(i.severity)}`]">{{ severityLabel(i.severity) }}</span>
                <span class="tag tone-neutral">{{ incidentStatusLabel(i.status) }}</span>
              </div>
              <p v-if="i.description">{{ i.description }}</p>
              <div class="tl-m">
                <span class="num">Начало: {{ fmtDateTime(i.started_at) }}</span>
                <span v-if="elapsed(i.started_at)">
                  <Clock :size="13" :stroke-width="1.8" />идёт {{ elapsed(i.started_at) }}
                </span>
              </div>

              <!-- Лента апдейтов: новые сверху, это «что сейчас делают». -->
              <ul v-if="i.updates?.length" class="upd">
                <li v-for="u in [...i.updates].reverse()" :key="u.id">
                  <div class="upd-h">
                    <span class="tag tone-neutral">{{ incidentStatusLabel(u.status) }}</span>
                    <span class="upd-time num">{{ fmtDateTime(u.created_at) }}</span>
                  </div>
                  <p>{{ u.message }}</p>
                </li>
              </ul>
            </div>
          </li>
        </ol>
      </section>

      <section class="card">
        <h2 class="card-h">
          <Clock :size="18" :stroke-width="1.8" />
          История инцидентов
        </h2>

        <p v-if="!history.length" class="empty">История пуста.</p>

        <ol v-else class="tl">
          <li v-for="h in history" :key="h.id">
            <span class="tl-dot tone-ok">
              <Check :size="14" :stroke-width="2.4" />
            </span>
            <div class="tl-b">
              <div class="tl-h">
                <b>{{ h.title }}</b>
                <span :class="['tag', `tone-${severityTone(h.severity)}`]">{{ severityLabel(h.severity) }}</span>
                <span class="tag tone-ok">{{ incidentStatusLabel(h.status) }}</span>
              </div>
              <p v-if="h.description">{{ h.description }}</p>
              <div class="tl-m">
                <span class="num">{{ fmtDateTime(h.started_at) }} → {{ fmtDateTime(h.resolved_at) }}</span>
                <span v-if="duration(h.started_at, h.resolved_at)">
                  <Clock :size="13" :stroke-width="1.8" />{{ duration(h.started_at, h.resolved_at) }}
                </span>
              </div>
            </div>
          </li>
        </ol>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import {
  Bell, Calculator, ChartColumn, Check, CircleAlert, CircleX, Clock, Database,
  FileText, GraduationCap, LayoutGrid, MessageCircle, Package, RefreshCw, Server,
  Settings, TrendingUp, TriangleAlert, Users, Wrench,
} from 'lucide-vue-next';
import api from '../api';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
// До первого ответа статус неизвестен: зелёный герой «всё штатно» на пустых
// данных врал бы ровно в тот момент, когда на страницу и приходят.
const overall = ref({ status: null, label: '' });
const components = ref([]);
const active = ref([]);
const history = ref([]);
const updatedAt = ref('');
const loading = ref(true);
const refreshing = ref(false);
// Момент последней загрузки: от него считается «идёт N» у активных инцидентов.
// Отдельная переменная, а не Date.now() в шаблоне, — иначе счётчик пересчитывался
// бы на каждый рендер и Vue уходил в бесконечное обновление.
const loadedAt = ref(Date.now());

let timer = null;

async function load() {
  refreshing.value = true;
  try {
    const { data } = await api.get('/system-status');
    overall.value = data.overall || { status: 'operational', label: '' };
    components.value = data.components || [];
    active.value = data.active || [];
    history.value = data.history || [];
    loadedAt.value = Date.now();
    updatedAt.value = new Date().toLocaleTimeString('ru-RU');
  } catch { /* сеть отвалилась — на экране остаются прошлые данные */ }
  loading.value = false;
  refreshing.value = false;
}

const okCount = computed(() => components.value.filter((c) => c.status === 'operational').length);

/** Тон = семантика статуса. Классы tone-* задают пару цветов и для плашки, и для иконки. */
const STATUS_TONE = {
  operational: 'ok',
  maintenance: 'info',
  degraded: 'warn',
  partial_outage: 'warn',
  major_outage: 'danger',
};
function tone(s) {
  return STATUS_TONE[s] || 'neutral';
}

const SEVERITY_TONE = { minor: 'warn', major: 'warn', critical: 'danger', maintenance: 'info' };
function severityTone(s) {
  return SEVERITY_TONE[s] || 'neutral';
}

const STATUS_ICON = {
  operational: Check,
  maintenance: Wrench,
  degraded: TriangleAlert,
  partial_outage: TriangleAlert,
  major_outage: CircleX,
};
function statusIcon(s) {
  return STATUS_ICON[s] || CircleAlert;
}

function severityIcon(s) {
  if (s === 'maintenance') return Wrench;
  return s === 'critical' ? CircleX : TriangleAlert;
}

/**
 * Иконка компонента — чистое оформление: API отдаёт только имя, статус и
 * описание. Подбираем по ключевому слову в названии, незнакомое имя получает
 * нейтральный Server — придумывать компоненту смысл мы не вправе.
 */
const COMPONENT_ICONS = [
  [/чат|сообщ/i, MessageCircle],
  [/уведомл/i, Bell],
  [/продукт/i, Package],
  [/пул/i, TrendingUp],
  [/калькул|объ[её]м/i, ChartColumn],
  [/баз[аы]\s*данных|\bбд\b/i, Database],
  [/расч[её]т|комисс|начислен|выплат/i, Calculator],
  [/обучен|курс|урок/i, GraduationCap],
  [/отч[её]т|документ|реестр/i, FileText],
  [/структур|партн[её]р|клиент/i, Users],
];
function componentIcon(name) {
  const hit = COMPONENT_ICONS.find(([re]) => re.test(String(name || '')));
  return hit ? hit[1] : Server;
}

function statusLabel(s) {
  return {
    operational: 'Работает',
    maintenance: 'Тех. работы',
    degraded: 'Замедление',
    partial_outage: 'Частичный сбой',
    major_outage: 'Серьёзный сбой',
  }[s] || s;
}
function severityLabel(s) {
  return { minor: 'Незначительно', major: 'Серьёзно', critical: 'Критично', maintenance: 'Тех. работы' }[s] || s;
}
function incidentStatusLabel(s) {
  return {
    investigating: 'Расследуется', identified: 'Причина найдена',
    monitoring: 'Мониторинг', resolved: 'Решено',
    scheduled: 'Запланировано', in_progress: 'В процессе', completed: 'Завершено',
  }[s] || s;
}

// Даты приходят из Postgres строкой «2026-05-12 09:38:00» — без замены пробела
// на «T» такую строку разбирает не каждый движок.
function toDate(v) {
  if (!v) return null;
  const d = new Date(String(v).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? null : d;
}

const DT_FMT = new Intl.DateTimeFormat('ru-RU', {
  day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
});
function fmtDateTime(v) {
  const d = toDate(v);
  return d ? DT_FMT.format(d).replace(', ', ' ') : '—';
}

function plural(n, one, few, many) {
  const mod10 = n % 10;
  const mod100 = n % 100;
  if (mod10 === 1 && mod100 !== 11) return one;
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return few;
  return many;
}

/** Длительность в человеческом виде: «7 мин», «11 ч 57 мин», «5 дней 9 ч». */
function duration(from, to) {
  const a = toDate(from);
  const b = to instanceof Date ? to : toDate(to);
  if (!a || !b) return '';
  const minutes = Math.round((b - a) / 60000);
  if (minutes < 1) return 'меньше минуты';
  if (minutes < 60) return `${minutes} мин`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) {
    const rest = minutes % 60;
    return rest ? `${hours} ч ${rest} мин` : `${hours} ч`;
  }
  const days = Math.floor(hours / 24);
  const restHours = hours % 24;
  const daysLabel = `${days} ${plural(days, 'день', 'дня', 'дней')}`;
  return restHours ? `${daysLabel} ${restHours} ч` : daysLabel;
}

function elapsed(from) {
  return duration(from, new Date(loadedAt.value));
}

onMounted(() => {
  load();
  // Автообновление раз в минуту — страницу держат открытой во время сбоя и
  // ждут, что она сама покажет новый апдейт.
  timer = setInterval(load, 60000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<style scoped>
.status {
  display: flex;
  flex-direction: column;
  gap: var(--space-6);
  max-width: 1480px;
  font-family: var(--font-sans);
  color: var(--ink);

  /* Пары «цвет / фон» под каждый тон. Мягкого красного в токенах нет, поэтому
     он собирается из --danger и текущей поверхности — так он остаётся верным
     и в тёмной теме. */
  --danger-soft: color-mix(in srgb, var(--danger) 14%, var(--surface));
}

.tone-ok { --tone: var(--brand); --tone-soft: var(--brand-soft); }
.tone-info { --tone: var(--info); --tone-soft: var(--info-soft); }
.tone-warn { --tone: var(--accent); --tone-soft: var(--accent-soft); }
.tone-danger { --tone: var(--danger); --tone-soft: var(--danger-soft); }
.tone-neutral { --tone: var(--ink-muted); --tone-soft: var(--surface-2); }

/* ---------- шапка страницы ---------- */
.ph {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
}
.ph h1 { margin: 0; font: 700 28px/34px var(--font-sans); letter-spacing: -0.015em; }
.ph p { margin: 4px 0 0; font: 400 14px/22px var(--font-sans); color: var(--ink-muted); }

.ph-link {
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
  text-decoration: none;
}
.ph-link:hover { border-color: var(--brand); color: var(--brand); }
.ph-link:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }

/* ---------- сводка ---------- */
.sys {
  display: flex;
  align-items: center;
  gap: var(--space-5);
  padding: var(--space-6);
  border-radius: var(--radius-xl);
}
.sys.tone-ok { background: var(--brand-deep); color: var(--on-brand-deep); }
.sys:not(.tone-ok) {
  background: var(--surface);
  border: 1px solid var(--border);
  /* Полоса слева цветом тона: статус виден боковым зрением, не только по иконке. */
  border-left: 4px solid var(--tone);
  box-shadow: var(--shadow-card);
}

.sys-body { min-width: 0; }
.sys h2 { margin: 0; font: 700 24px/30px var(--font-sans); letter-spacing: -0.01em; }
.sys p { margin: 4px 0 0; font: 400 14px/22px var(--font-sans); color: var(--ink-muted); }
.sys.tone-ok p { color: var(--on-brand-deep-muted); }

.sys-ic {
  flex: none;
  display: grid;
  place-items: center;
  width: 56px;
  height: 56px;
  border-radius: var(--radius-pill);
  background: var(--tone-soft);
  color: var(--tone);
}
.sys.tone-ok .sys-ic {
  background: var(--brand-glow);
  color: var(--on-brand-glow);
  box-shadow: 0 0 0 8px var(--brand-deep-2);
}

.sys-btn {
  margin-left: auto;
  flex: none;
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  height: 40px;
  padding: 0 var(--space-4);
  border: 1px solid transparent;
  border-radius: var(--radius-md);
  background: var(--brand-deep-2);
  color: var(--on-brand-deep);
  font: 600 13px/1 var(--font-sans);
  cursor: pointer;
}
.sys.tone-ok .sys-btn:hover { background: var(--on-brand-deep); color: var(--brand-deep); }
.sys:not(.tone-ok) .sys-btn {
  background: var(--surface);
  border-color: var(--border-strong);
  color: var(--ink);
}
.sys:not(.tone-ok) .sys-btn:hover { border-color: var(--brand); color: var(--brand); }
.sys-btn:disabled { opacity: 0.65; cursor: default; }
.sys-btn:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }

.spin { animation: spin 900ms linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .spin { animation: none; } }

/* ---------- карточки ---------- */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-card);
  padding: var(--space-5);
}
.card-h {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0 0 var(--space-4);
  font: 600 17px/24px var(--font-sans);
  color: var(--ink);
}
.card-h svg { color: var(--brand); flex: none; }
.card-h .ic-warn { color: var(--accent); }
.card-more {
  margin-left: auto;
  font: 500 13px/18px var(--font-sans);
  color: var(--ink-muted);
}

.empty { margin: 0; padding: var(--space-3) 0; font: 400 14px/22px var(--font-sans); color: var(--ink-muted); }
.skeleton { height: 220px; border-radius: var(--radius-lg); background: var(--surface-2); }

/* ---------- компоненты ---------- */
.comps {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: var(--space-2);
}
.comp {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  min-width: 0;
  padding: var(--space-3) var(--space-4);
  border-radius: var(--radius-md);
  background: var(--surface-2);
}
.comp-ic {
  flex: none;
  display: grid;
  place-items: center;
  width: 34px;
  height: 34px;
  border-radius: var(--radius-sm);
  background: var(--surface);
  color: var(--ink-muted);
}
.comp-body { display: flex; flex-direction: column; min-width: 0; }
.comp-name { font: 500 14px/20px var(--font-sans); color: var(--ink); overflow-wrap: break-word; }
.comp-desc { font: 400 12px/16px var(--font-sans); color: var(--ink-muted); overflow-wrap: break-word; }
.comp .tag { margin-left: auto; flex: none; }

/* ---------- плашки ---------- */
.tag {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 24px;
  padding: 0 10px;
  border-radius: var(--radius-pill);
  background: var(--tone-soft);
  color: var(--tone);
  font: 600 12px/16px var(--font-sans);
  white-space: nowrap;
}
.tag .dot { width: 7px; height: 7px; border-radius: var(--radius-pill); background: currentColor; }

/* ---------- лента инцидентов ---------- */
.tl { list-style: none; margin: 0; padding: 0; }
/* Только прямые потомки: внутри записи лежит вложенный список апдейтов, и без
   «>» его пункты получали сетку и нить основной ленты — текст наезжал на плашку. */
.tl > li {
  position: relative;
  display: grid;
  grid-template-columns: 28px minmax(0, 1fr);
  gap: var(--space-4);
  padding-bottom: var(--space-5);
}
.tl > li:last-child { padding-bottom: 0; }
/* Нить между точками: рисуем псевдоэлементом, чтобы у последней записи её не было. */
.tl > li::before {
  content: '';
  position: absolute;
  left: 13px;
  top: 30px;
  bottom: 2px;
  width: 2px;
  background: var(--border);
}
.tl > li:last-child::before { display: none; }

.tl-dot {
  display: grid;
  place-items: center;
  width: 28px;
  height: 28px;
  border-radius: var(--radius-pill);
  background: var(--tone-soft);
  color: var(--tone);
}
.tl-b { min-width: 0; }
.tl-h {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  flex-wrap: wrap;
  padding-top: 3px;
}
.tl-h b { font: 600 15px/22px var(--font-sans); margin-right: var(--space-1); overflow-wrap: break-word; }
.tl-h .tag { height: 22px; font-size: 11px; }
.tl-b > p {
  margin: 6px 0 0;
  max-width: 820px;
  font: 400 14px/21px var(--font-sans);
  color: var(--ink-muted);
  overflow-wrap: break-word;
}
.tl-m {
  display: flex;
  gap: var(--space-4);
  flex-wrap: wrap;
  margin-top: var(--space-2);
  font: 400 12px/16px var(--font-sans);
  color: var(--ink-muted);
}
.tl-m span { display: inline-flex; align-items: center; gap: 5px; }
.num { font-variant-numeric: tabular-nums; }

.upd {
  list-style: none;
  display: grid;
  gap: var(--space-3);
  margin: var(--space-3) 0 0;
  padding: 0 0 0 var(--space-4);
  border-left: 2px solid var(--border);
}
.upd-h { display: flex; align-items: center; gap: var(--space-2); flex-wrap: wrap; }
.upd-time { font: 400 12px/16px var(--font-sans); color: var(--ink-muted); }
.upd p { margin: 4px 0 0; font: 400 14px/22px var(--font-sans); color: var(--ink); overflow-wrap: break-word; }

/* Узкие экраны: кнопка обновления уходит под текст сводки, когда перестаёт
   помещаться в строку; пока место есть — остаётся прижатой вправо. */
@media (max-width: 860px) {
  .sys { flex-wrap: wrap; padding: var(--space-5); }
  .sys-body { flex: 1 1 220px; }
  .sys-btn { margin-left: 0; }
  .sys h2 { font-size: 20px; line-height: 26px; }
  .ph h1 { font-size: 24px; line-height: 30px; }
  .card { padding: var(--space-4); }
}
@media (max-width: 560px) {
  .sys-ic { width: 44px; height: 44px; }
  .comp { padding: var(--space-3); }
  .tl > li { gap: var(--space-3); }
}
</style>
