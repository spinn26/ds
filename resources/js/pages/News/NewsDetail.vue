<template>
  <div class="read">
    <article class="article">
      <router-link class="back" to="/">
        <ArrowLeft :size="16" :stroke-width="1.8" />
        Все новости
      </router-link>

      <div v-if="loading" class="skeleton"></div>

      <template v-else-if="news">
        <div class="hero-art">
          <NewsCover :kind="news.kind" :url="news.coverUrl" :eyebrow="news.coverEyebrow"
            :numeral="news.coverNumeral" :caption="news.coverCaption" :alt="news.title" />
        </div>

        <div class="head">
          <div class="meta">
            <NewsMeta :item="{ ...news, isNew: false }" />
            <span v-if="news.readingMinutes" class="read-time">
              <Clock :size="14" :stroke-width="1.8" />{{ news.readingMinutes }} мин
            </span>
          </div>
          <h1>{{ news.title }}</h1>
          <p v-if="news.excerpt" class="lead">{{ news.excerpt }}</p>
        </div>

        <!-- Акция: блоки собираются из параметров новости, а не верстаются
             руками в редакторе (per решение «шаблон для акций»). -->
        <template v-if="promoMeta">
          <div v-if="promoMeta.rule" class="rule">
            <div>
              <div class="big num">{{ promoMeta.rule.left.value }}</div>
              <div class="cap">{{ promoMeta.rule.left.caption }}</div>
            </div>
            <ArrowRight class="arrow" :size="20" :stroke-width="1.8" />
            <div>
              <div class="big num brand">{{ promoMeta.rule.right.value }}</div>
              <div class="cap">{{ promoMeta.rule.right.caption }}</div>
            </div>
          </div>

          <template v-if="promoMeta.example">
            <h2>Пример</h2>
            <div class="example">
              <div class="col">
                <div class="pct num">{{ promoMeta.example.left.value }}</div>
                <div class="cap">{{ promoMeta.example.left.caption }}</div>
              </div>
              <ArrowRight class="arrow" :size="20" :stroke-width="1.8" />
              <div class="col">
                <div class="pct num brand">{{ promoMeta.example.right.value }}</div>
                <div class="cap">{{ promoMeta.example.right.caption }}</div>
              </div>
            </div>
          </template>

          <template v-if="news.promo?.months?.length">
            <h2>Когда действует</h2>
            <p v-if="promoMeta.monthsNote">{{ promoMeta.monthsNote }}</p>
            <div class="months">
              <div v-for="m in news.promo.months" :key="m.label" :class="['month', m.state]">
                <b>{{ m.label }}</b>
                <span>{{ m.state === 'now' ? 'идёт сейчас' : m.state === 'past' ? 'завершён' : promoMeta.monthHint || '' }}</span>
              </div>
            </div>
          </template>

          <div v-if="promoMeta.note" class="note">
            <Info :size="18" :stroke-width="1.8" />
            <div v-html="safeHtml(promoMeta.note)"></div>
          </div>

          <template v-if="promoMeta.steps?.length">
            <h2>{{ promoMeta.stepsTitle || 'Как добрать баллы' }}</h2>
            <ul class="steps">
              <li v-for="(s, i) in promoMeta.steps" :key="i">
                <component :is="stepIcon(s.icon)" :size="18" :stroke-width="1.8" />
                <span>{{ s.text }}</span>
              </li>
            </ul>
          </template>

          <p v-if="promoMeta.outro">{{ promoMeta.outro }}</p>
        </template>

        <!-- Обычная новость: текст из редактора. -->
        <div v-if="news.content" class="prose" v-html="safeHtml(news.content)"></div>

        <div v-if="news.cta?.url" class="cta">
          <div class="cta-text">
            <b>{{ ctaTitle }}</b>
            <span>{{ ctaNote }}</span>
          </div>
          <div class="cta-acts">
            <button type="button" class="btn" @click="copyLink">
              <Copy :size="16" :stroke-width="1.8" />
              {{ copied ? 'Ссылка скопирована' : 'Скопировать ссылку' }}
            </button>
            <a class="btn primary" :href="news.cta.url" target="_blank" rel="noopener">
              {{ news.cta.label || 'Открыть' }}
              <ExternalLink :size="16" :stroke-width="1.8" />
            </a>
          </div>
        </div>
      </template>

      <div v-else class="empty">Новость не найдена или снята с публикации.</div>
    </article>

    <aside v-if="news" class="aside">
      <section v-if="news.promo?.active" class="card">
        <h2 class="card-h"><Target :size="18" :stroke-width="1.8" />Ваш прогресс</h2>
        <PromoPanel :promo="news.promo" :with-link="false" flat />
      </section>

      <section v-if="news.others?.length" class="card">
        <h2 class="card-h"><CalendarDays :size="18" :stroke-width="1.8" />Другие новости</h2>
        <router-link v-for="o in news.others" :key="o.id" class="other" :to="`/news/${o.id}`">
          <span class="other-cover"><NewsCover :kind="o.kind" :url="o.coverUrl" :alt="o.title" /></span>
          <span class="other-body">
            <NewsMeta :item="o" />
            <span class="other-title">{{ o.title }}</span>
          </span>
        </router-link>
      </section>
    </aside>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, ArrowRight, Clock, Info, Copy, ExternalLink, Target, CalendarDays,
  Users, FileText, MessageCircle, Calendar } from 'lucide-vue-next';
import api from '../../api';
import NewsCover from '../../components/workspace/NewsCover.vue';
import NewsMeta from '../../components/workspace/NewsMeta.vue';
import PromoPanel from '../../components/workspace/PromoPanel.vue';
import { safeHtml } from '../../composables/useSafeHtml';

/**
 * Страница новости (per design/components/NewsDetail.md).
 *
 * Отдельный маршрут, а не модалка: ссылку на новость отправляют в чат, и
 * кнопка «назад» браузера обязана работать.
 */
const route = useRoute();
const news = ref(null);
const loading = ref(true);
const copied = ref(false);

const promoMeta = computed(() => news.value?.promoMeta || null);
const ctaTitle = computed(() => String(news.value?.cta?.note || '').split(' · ')[0] || 'Материал');
const ctaNote = computed(() => {
  const tail = String(news.value?.cta?.note || '').split(' · ')[1];
  return tail ? `${tail} · откроется в новой вкладке` : 'Откроется в новой вкладке';
});

const STEP_ICONS = { users: Users, file: FileText, chat: MessageCircle, calendar: Calendar };
function stepIcon(name) {
  return STEP_ICONS[name] || FileText;
}

async function load(id) {
  loading.value = true;
  try {
    const { data } = await api.get(`/news/${id}`);
    news.value = data;
    // Открыл — значит прочитал: метка «Новое» снимается сразу, иначе она
    // висит до перезагрузки страницы.
    api.post(`/news/${id}/read`).catch(() => {});
  } catch {
    news.value = null;
  }
  loading.value = false;
}

async function copyLink() {
  try {
    await navigator.clipboard.writeText(window.location.href);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
  } catch { /* буфер недоступен — молча, тост тут лишний */ }
}

onMounted(() => load(route.params.id));
watch(() => route.params.id, (id) => { if (id) load(id); });
</script>

<style scoped>
.read {
  display: grid;
  grid-template-columns: minmax(0, 760px) 320px;
  gap: var(--space-6);
  align-items: start;
  font-family: var(--font-sans);
  color: var(--ink);
}

.article { min-width: 0; }

.back {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  margin-bottom: var(--space-5);
  font: 500 13px/18px var(--font-sans);
  color: var(--ink-muted);
  text-decoration: none;
}
.back:hover { color: var(--brand); }

.hero-art {
  aspect-ratio: 21 / 8;
  border-radius: var(--radius-xl);
  overflow: hidden;
  background: var(--brand-deep);
}

.head { margin-top: var(--space-5); }
.meta { display: flex; align-items: center; gap: var(--space-3); flex-wrap: wrap; }
.read-time {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font: 400 12px/16px var(--font-sans);
  color: var(--ink-muted);
}
.head h1 {
  margin: var(--space-3) 0 0;
  font: 700 28px/34px var(--font-sans);
  letter-spacing: -0.015em;
}
.lead {
  margin: var(--space-3) 0 0;
  font: 400 16px/26px var(--font-sans);
  color: var(--ink-muted);
}

h2 {
  margin: var(--space-8) 0 var(--space-3);
  font: 600 17px/24px var(--font-sans);
}
.prose { font: 400 16px/26px var(--font-sans); }
.prose :deep(p) { margin: 0 0 var(--space-4); }
.prose :deep(a) { color: var(--brand); }

.num { font-variant-numeric: tabular-nums; }
.brand { color: var(--brand); }

.rule, .example {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  gap: var(--space-5);
  margin-top: var(--space-6);
  padding: var(--space-6) var(--space-5);
  border-radius: var(--radius-lg);
  text-align: center;
}
.rule { background: var(--brand-soft); }
.example { background: var(--surface); border: 1px solid var(--border); }
.arrow { color: var(--ink-muted); }
.big { font: 700 32px/38px var(--font-sans); letter-spacing: -0.02em; }
.pct { font: 700 28px/34px var(--font-sans); letter-spacing: -0.02em; }
.cap { margin-top: 4px; font: 400 12px/16px var(--font-sans); color: var(--ink-muted); }

.months {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: var(--space-3);
  margin-top: var(--space-4);
}
.month {
  padding: var(--space-4);
  border-radius: var(--radius-md);
  background: var(--surface-2);
  text-align: center;
}
.month b { display: block; font: 600 15px/22px var(--font-sans); }
.month span { font: 400 12px/16px var(--font-sans); color: var(--ink-muted); }
.month.now { background: var(--brand); color: var(--on-brand); }
.month.now span { color: var(--on-brand); opacity: 0.85; }
.month.past { opacity: 0.6; }

.note {
  display: flex;
  gap: var(--space-3);
  margin-top: var(--space-6);
  padding: var(--space-5);
  border-radius: var(--radius-md);
  background: var(--accent-soft);
  color: var(--ink);
  font: 400 14px/22px var(--font-sans);
}
.note svg { color: var(--accent); flex: 0 0 auto; }

.steps {
  display: grid;
  /* Строго две колонки: auto-fit на широком экране раскладывал четыре совета
     как 3 + 1, и последний висел сиротой. */
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
  padding: 0;
  list-style: none;
}
.steps li {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-4);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  font: 400 14px/22px var(--font-sans);
}
.steps svg { color: var(--brand); flex: 0 0 auto; }

.cta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
  margin-top: var(--space-8);
  padding: var(--space-5);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
}
.cta-text { display: flex; flex-direction: column; }
.cta-text b { font: 600 15px/22px var(--font-sans); }
.cta-text span { font: 400 12px/16px var(--font-sans); color: var(--ink-muted); }
.cta-acts { display: flex; gap: var(--space-2); flex-wrap: wrap; }

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
  text-decoration: none;
  cursor: pointer;
}
.btn.primary { border-color: var(--brand); background: var(--brand); color: var(--on-brand); }
.btn.primary:hover { background: var(--brand-hover); }
.btn:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }

.aside { display: flex; flex-direction: column; gap: var(--space-6); position: sticky; top: 88px; }
.card {
  padding: var(--space-5);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
  box-shadow: var(--shadow-card);
}
.card-h {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0 0 var(--space-4);
  font: 600 17px/24px var(--font-sans);
}
.card-h svg { color: var(--brand); }

.other { display: grid; grid-template-columns: 96px minmax(0, 1fr); gap: var(--space-3); padding: var(--space-3) 0; text-decoration: none; color: inherit; }
.other + .other { border-top: 1px solid var(--border); }
.other-cover { display: block; aspect-ratio: 16 / 10; border-radius: var(--radius-md); overflow: hidden; }
.other-body { display: flex; flex-direction: column; gap: 4px; }
.other-title { font: 600 14px/20px var(--font-sans); }
.other:hover .other-title { color: var(--brand); }

.skeleton { height: 320px; border-radius: var(--radius-xl); background: var(--surface-2); }
.empty { padding: var(--space-8) 0; color: var(--ink-muted); font: 400 14px/22px var(--font-sans); }

/* < 1180px боковая колонка уходит под статью (per design/components/NewsDetail.md). */
@media (max-width: 1180px) {
  .read { grid-template-columns: minmax(0, 1fr); }
  .aside { position: static; }
}
@media (max-width: 720px) {
  .steps { grid-template-columns: minmax(0, 1fr); }
  .rule, .example { grid-template-columns: minmax(0, 1fr); gap: var(--space-4); }
  .arrow { transform: rotate(90deg); }
  .head h1 { font-size: 24px; line-height: 30px; }
}
</style>
