<template>
  <!-- Админу: меню со списком активных инцидентов и кнопкой «Решить» у каждого.
       Остальным: чип-ссылка на /status с rich-tooltip как раньше. -->
  <v-menu v-if="auth.isAdmin && incidents.length" v-model="menuOpen"
    location="bottom end" :close-on-content-click="false" offset="6">
    <template #activator="{ props: menuProps }">
      <a v-bind="menuProps" href="#" class="status-chip-link" :class="['status-' + status]"
        @click.prevent>
        <span class="status-dot" :class="['dot-' + status]">
          <span class="dot-ping" />
        </span>
        <span v-if="!compact" class="status-label">
          <span class="status-label-main">{{ label }}</span>
          <span v-if="detail" class="status-label-detail">· {{ detail }}</span>
        </span>
      </a>
    </template>
    <v-card min-width="380" max-width="460">
      <v-list density="compact" class="py-1">
        <v-list-subheader class="text-uppercase">
          Активные инциденты ({{ incidents.length }})
        </v-list-subheader>
        <v-list-item v-for="i in incidents" :key="i.id" :title="i.title"
          :subtitle="i.componentName || severityLabel(i.severity)">
          <template #prepend>
            <span class="row-bullet" :class="'sev-' + (i.severity || 'minor')" />
          </template>
          <template #append>
            <v-btn size="small" variant="tonal" color="success"
              prepend-icon="mdi-check-circle"
              :loading="resolvingId === i.id"
              @click="resolveIncident(i.id)">
              Решён
            </v-btn>
          </template>
        </v-list-item>
        <v-divider class="my-1" />
        <v-list-item to="/status" prepend-icon="mdi-page-next-outline" @click="menuOpen = false">
          <v-list-item-title>Открыть страницу статуса</v-list-item-title>
        </v-list-item>
      </v-list>
    </v-card>
  </v-menu>

  <v-tooltip v-else location="bottom" max-width="380">
    <template #activator="{ props: tip }">
      <router-link v-bind="tip" to="/status" class="status-chip-link" :class="['status-' + status]">
        <span class="status-dot" :class="['dot-' + status]">
          <span class="dot-ping" />
        </span>
        <span v-if="!compact" class="status-label">
          <span class="status-label-main">{{ label }}</span>
          <span v-if="detail" class="status-label-detail">· {{ detail }}</span>
        </span>
      </router-link>
    </template>
    <div class="status-tooltip">
      <div class="status-tooltip-head">{{ label }}</div>
      <template v-if="incidents.length">
        <div class="status-tooltip-sub">Активные инциденты ({{ incidents.length }})</div>
        <div v-for="i in incidents.slice(0, 5)" :key="i.id" class="status-tooltip-row">
          <span class="row-bullet" :class="'sev-' + (i.severity || 'minor')" />
          <div class="row-body">
            <div class="row-title">{{ i.title }}</div>
            <div v-if="i.componentName" class="row-meta">{{ i.componentName }}</div>
          </div>
        </div>
        <div v-if="incidents.length > 5" class="status-tooltip-more">
          и ещё {{ incidents.length - 5 }}…
        </div>
      </template>
      <template v-if="brokenComponents.length">
        <div class="status-tooltip-sub">Проблемные компоненты</div>
        <div v-for="c in brokenComponents.slice(0, 6)" :key="c.id" class="status-tooltip-row">
          <span class="row-bullet" :class="'comp-' + c.status" />
          <div class="row-body">
            <div class="row-title">{{ c.name }}</div>
            <div class="row-meta">{{ componentLabel(c.status) }}</div>
          </div>
        </div>
      </template>
      <div class="status-tooltip-foot">Открыть страницу статуса →</div>
    </div>
  </v-tooltip>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import api from '../api';
import { useAuthStore } from '../stores/auth';
import { useSnackbar } from '../composables/useSnackbar';

defineProps({
  compact: { type: Boolean, default: false },
});

const auth = useAuthStore();
const { showSuccess, showError } = useSnackbar();

const status = ref('operational');
const label = ref('Все работает в штатном режиме');
const incidents = ref([]);        // { id, title, severity, component_id, componentName }
const brokenComponents = ref([]); // { id, name, status } — только не-operational
const menuOpen = ref(false);
const resolvingId = ref(null);
let timer = null;

const SEVERITY_LABELS = {
  minor: 'Низкий',
  major: 'Серьёзный',
  critical: 'Критический',
  maintenance: 'Тех. работы',
};
function severityLabel(s) { return SEVERITY_LABELS[s] || s; }

/**
 * Быстрое закрытие инцидента из чипа в шапке (только admin).
 * Шлёт PUT /system-status/incidents/{id} status=resolved и обновляет
 * локальный список — без перезагрузки страницы.
 */
async function resolveIncident(id) {
  resolvingId.value = id;
  try {
    await api.put(`/system-status/incidents/${id}`, { status: 'resolved' });
    incidents.value = incidents.value.filter(i => i.id !== id);
    if (! incidents.value.length) menuOpen.value = false;
    showSuccess('Инцидент закрыт');
    // Перетянуть полный статус (overall может смениться, компоненты обновятся).
    await load();
  } catch (e) {
    showError(e?.response?.data?.message || 'Не удалось закрыть инцидент');
  } finally {
    resolvingId.value = null;
  }
}

const STATUS_LABELS = {
  operational: 'Все работает в штатном режиме',
  maintenance: 'Тех. работы',
  degraded: 'Снижение производительности',
  partial_outage: 'Частичный сбой',
  major_outage: 'Серьёзный сбой',
};

const COMPONENT_STATUS_LABELS = {
  operational: 'Работает',
  maintenance: 'Тех. работы',
  degraded: 'Снижение производительности',
  partial_outage: 'Частичный сбой',
  major_outage: 'Серьёзный сбой',
};
function componentLabel(s) { return COMPONENT_STATUS_LABELS[s] || s; }

/**
 * Краткая причина для шапки. Логика:
 *  - 1 инцидент → его title (truncate в CSS);
 *  - >1 инцидентов → «N инцидентов»;
 *  - инцидентов нет, но компонент(ы) лежат → название первого/счёт.
 *  - всё ок → ничего.
 */
const detail = computed(() => {
  if (incidents.value.length === 1) return incidents.value[0].title;
  if (incidents.value.length > 1) return `${incidents.value.length} инцидентов`;
  if (brokenComponents.value.length === 1) return brokenComponents.value[0].name;
  if (brokenComponents.value.length > 1) return `${brokenComponents.value.length} компонентов с проблемами`;
  return '';
});

async function load() {
  try {
    const { data } = await api.get('/system-status');
    status.value = data.overall?.status || 'operational';
    label.value = STATUS_LABELS[status.value] || data.overall?.label || 'Статус системы';

    const components = data.components || [];
    const compById = Object.fromEntries(components.map(c => [c.id, c]));
    incidents.value = (data.active || []).map(i => ({
      id: i.id,
      title: i.title,
      severity: i.severity,
      component_id: i.component_id,
      componentName: i.component_id ? (compById[i.component_id]?.name || null) : null,
    }));
    brokenComponents.value = components
      .filter(c => c.status && c.status !== 'operational')
      .map(c => ({ id: c.id, name: c.name, status: c.status }));
  } catch {
    // silent — статус в шапке необязательный
  }
}

onMounted(() => {
  load();
  timer = setInterval(load, 60_000);
});
onUnmounted(() => {
  if (timer) clearInterval(timer);
});
</script>

<style scoped>
.status-chip-link {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 4px 10px;
  border-radius: 999px;
  text-decoration: none;
  color: rgba(var(--v-theme-on-surface), 0.85);
  font-size: 12px;
  font-weight: 500;
  background: rgba(var(--v-theme-surface-variant), 0.4);
  transition: background 0.15s;
  white-space: nowrap;
  max-width: 460px;
}
.status-chip-link:hover {
  background: rgba(var(--v-theme-surface-variant), 0.7);
}

.status-dot {
  position: relative;
  display: inline-block;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}
.dot-operational    { background: #43a047; }
.dot-maintenance    { background: #1e88e5; }
.dot-degraded       { background: #f5a623; }
.dot-partial_outage { background: #fb8c00; }
.dot-major_outage   { background: #e53935; }

.dot-ping {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  background: inherit;
  animation: status-ping 1.6s cubic-bezier(0, 0, 0.2, 1) infinite;
  opacity: 0.75;
}
.dot-operational .dot-ping { animation-duration: 3.2s; opacity: 0.5; }
.dot-major_outage .dot-ping,
.dot-partial_outage .dot-ping { animation-duration: 1.0s; }
@keyframes status-ping {
  0%   { transform: scale(1);    opacity: 0.75; }
  80%  { transform: scale(2.4);  opacity: 0;    }
  100% { transform: scale(2.4);  opacity: 0;    }
}

.status-operational     { background: rgba(67, 160, 71, 0.12); color: #66bb6a; }
.status-major_outage    { background: rgba(229, 57, 53, 0.15); color: #ef5350; }
.status-partial_outage  { background: rgba(251, 140, 0, 0.15); color: #ffa726; }
.status-degraded        { background: rgba(245, 166, 35, 0.12); color: #ffb74d; }
.status-maintenance     { background: rgba(30, 136, 229, 0.15); color: #64b5f6; }

.status-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
  overflow: hidden;
}
.status-label-main { font-weight: 600; flex-shrink: 0; }
.status-label-detail {
  font-weight: 400;
  opacity: 0.85;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 280px;
}

/* === Tooltip body === */
.status-tooltip {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 12px;
  line-height: 1.35;
  color: rgba(255, 255, 255, 0.95);
}
.status-tooltip-head { font-weight: 700; font-size: 13px; }
.status-tooltip-sub {
  margin-top: 4px;
  font-weight: 600;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  opacity: 0.7;
}
.status-tooltip-row {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}
.row-bullet {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  margin-top: 5px;
  flex-shrink: 0;
}
.sev-critical, .comp-major_outage { background: #e53935; }
.sev-major, .comp-partial_outage  { background: #fb8c00; }
.sev-minor, .comp-degraded        { background: #f5a623; }
.sev-maintenance, .comp-maintenance { background: #1e88e5; }
.row-body { flex: 1; min-width: 0; }
.row-title { font-weight: 500; word-break: break-word; }
.row-meta { font-size: 11px; opacity: 0.7; }
.status-tooltip-more { font-size: 11px; opacity: 0.7; padding-left: 16px; }
.status-tooltip-foot {
  margin-top: 6px;
  font-size: 11px;
  opacity: 0.7;
  border-top: 1px solid rgba(255,255,255,0.15);
  padding-top: 6px;
}

@media (max-width: 720px) {
  .status-label-detail { display: none; }
  .status-chip-link { padding: 4px; }
}
@media (max-width: 480px) {
  .status-label { display: none; }
}
</style>
