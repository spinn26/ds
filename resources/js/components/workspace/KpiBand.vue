<template>
  <section class="kpi-wrap">
    <header class="kpi-head">
      <h2 class="kpi-h">
        <BarChart3 :size="18" :stroke-width="1.8" />
        Мои показатели
      </h2>
      <router-link class="kpi-more" to="/dashboard">
        Дашборд
        <ChevronRight :size="16" :stroke-width="1.8" />
      </router-link>
    </header>

    <div class="kpi">
      <div class="kpi-group">
        <div class="kpi-group-title">Этот месяц</div>
        <div class="kpi-cells">
          <router-link class="kpi-cell" to="/finance/report">
            <span class="kpi-label">ЛП</span>
            <span class="kpi-value info">{{ fmt(stats.personalVolume) }}</span>
            <span class="kpi-cap">личные баллы</span>
          </router-link>
          <router-link class="kpi-cell" to="/structure">
            <span class="kpi-label">ГП</span>
            <span class="kpi-value info">{{ fmt(stats.groupVolume) }}</span>
            <span class="kpi-cap">групповые баллы</span>
          </router-link>
        </div>
      </div>

      <div class="kpi-group">
        <div class="kpi-group-title">Статус</div>
        <div class="kpi-cells">
          <router-link class="kpi-cell" to="/dashboard">
            <span class="kpi-label">НГП</span>
            <span class="kpi-value accent">{{ fmt(stats.groupVolumeCumulative) }}</span>
            <span class="kpi-cap">накоплено</span>
          </router-link>
          <router-link class="kpi-cell" to="/dashboard">
            <span class="kpi-label">Квалификация</span>
            <span class="kpi-value">
              {{ stats.qualificationLevel ?? '—' }}
              <small v-if="stats.qualificationTitle">{{ stats.qualificationTitle }}</small>
            </span>
            <template v-if="progress != null">
              <span class="kpi-progress"><span :style="{ width: progress + '%' }"></span></span>
              <span class="kpi-cap">{{ progress }}% до следующей</span>
            </template>
            <span v-else class="kpi-cap">—</span>
          </router-link>
        </div>
      </div>

      <div class="kpi-group">
        <div class="kpi-group-title">База</div>
        <div class="kpi-cells">
          <router-link class="kpi-cell" to="/clients">
            <span class="kpi-label">Клиенты</span>
            <span class="kpi-value">{{ fmt(stats.clientCount) }}</span>
            <span class="kpi-cap">активных</span>
          </router-link>
          <router-link class="kpi-cell" to="/structure">
            <span class="kpi-label">Команда</span>
            <span class="kpi-value">{{ fmt(stats.teamCount) }}</span>
            <span class="kpi-cap">в структуре</span>
          </router-link>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import { BarChart3, ChevronRight } from 'lucide-vue-next';

/**
 * Полоса «Мои показатели» (per design/components/KpiTile.md): одна карточка,
 * три группы через разделители. Цвет несёт тип: info — баллы, accent — НГП,
 * счётчики без цвета. Каждая цифра ведёт в свой раздел.
 */
const props = defineProps({
  stats: { type: Object, default: () => ({}) },
});

// Процент считает бэкенд той же формулой, что и «Дашборд»: если считать
// здесь, две страницы разойдутся в цифрах на глазах у партнёра.
const progress = computed(() => {
  const p = props.stats.qualificationProgress;
  return p == null ? null : Math.max(0, Math.min(100, Math.round(p)));
});

function fmt(v) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(Number(v) || 0);
}
</script>

<style scoped>
.kpi-wrap { display: flex; flex-direction: column; gap: var(--space-3); }

.kpi-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
}
.kpi-h {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
  font: 600 17px/24px var(--font-sans);
  color: var(--ink);
}
.kpi-h svg { color: var(--brand); }
.kpi-more {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  font: 500 13px/18px var(--font-sans);
  color: var(--brand);
  text-decoration: none;
}

.kpi {
  display: grid;
  /* Средней группе шире: «2 001,82» и квалификация с прогрессом не влезают
     в треть и переносятся. */
  grid-template-columns: minmax(0, 1fr) minmax(0, 1.3fr) minmax(0, 1fr);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-card);
  overflow: hidden;
}
.kpi-group { padding: var(--space-5); }
.kpi-group + .kpi-group { border-left: 1px solid var(--border); }

.kpi-group-title {
  font: 600 11px/14px var(--font-sans);
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.kpi-cells {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--space-3);
  margin-top: var(--space-3);
}

.kpi-cell {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: var(--space-3);
  margin: calc(var(--space-3) * -1);
  border-radius: var(--radius-md);
  text-decoration: none;
  color: inherit;
}
.kpi-cell:hover { background: var(--surface-2); }
.kpi-cell:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }

.kpi-label { font: 400 12px/16px var(--font-sans); color: var(--ink-muted); }
.kpi-value {
  font: 700 24px/30px var(--font-sans);
  letter-spacing: -0.02em;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
  color: var(--ink);
}
.kpi-value small {
  font: 400 13px/18px var(--font-sans);
  color: var(--ink-muted);
  margin-left: 4px;
}
.kpi-value.info { color: var(--info); }
.kpi-value.accent { color: var(--accent); }
.kpi-cap { font: 400 12px/16px var(--font-sans); color: var(--ink-muted); }

.kpi-progress {
  display: block;
  height: 4px;
  margin: 6px 0 2px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  overflow: hidden;
}
.kpi-progress span {
  display: block;
  height: 100%;
  background: var(--brand);
  border-radius: var(--radius-pill);
}

/* < 1320px группы идут строками, подпись группы слева (per BRAND.md). */
@media (max-width: 1320px) {
  .kpi { grid-template-columns: minmax(0, 1fr); }
  .kpi-group + .kpi-group { border-left: 0; border-top: 1px solid var(--border); }
}
@media (max-width: 560px) {
  .kpi-cells { grid-template-columns: minmax(0, 1fr); gap: var(--space-4); }
}
</style>
