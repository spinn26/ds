/**
 * Chat-module chart & chip palette.
 *
 * The chat UI uses a categorical palette distinct from Vuetify's
 * success/warning/error theme tokens — those are 3-5 colors, we need
 * 8+ for status/priority/category with good contrast in the dark
 * AdminLayout. Kept as static hex values here so both Analytics.vue
 * and StaffChat.vue share one copy instead of drifting.
 *
 * If we ever move Chat to theme-driven colors, swap these for
 * rgb(var(--v-theme-X)) strings and the consumers don't change.
 */

export const chatStatusColors = {
  new: '#60a5fa',
  open: '#fbbf24',
  pending: '#f97316',
  resolved: '#34d399',
  closed: '#6b7280',
};

export const chatStatusLabels = {
  new: 'Новый',
  open: 'В работе',
  pending: 'Ожидание',
  resolved: 'Решён',
  closed: 'Закрыт',
};

export const chatPriorityColors = {
  critical: '#ef4444',
  high: '#f97316',
  medium: '#fbbf24',
  low: '#34d399',
};

export const chatPriorityLabels = {
  critical: 'Критический',
  high: 'Высокий',
  medium: 'Средний',
  low: 'Низкий',
};

export const chatCategoryColors = {
  support: '#3b82f6',
  backoffice: '#f97316',
  billing: '#22c55e',
  legal: '#a855f7',
  general: '#6b7280',
  technical: '#3b82f6',
  sales: '#f97316',
};

export const chatCategoryLabels = {
  support: 'Техподдержка',
  backoffice: 'Бэк-офис',
  billing: 'Начисления',
  legal: 'Юридический',
  general: 'Общий',
  technical: 'Технический',
  sales: 'Продажи',
};

/** Two-series trend chart: new tickets vs resolved. */
export const chatTrendColors = {
  newSeries: '#60a5fa',
  resolvedSeries: '#34d399',
};

/** Activity-id → bold accent for partner context cards in StaffChat. */
export const chatActivityAccent = {
  1: '#059669', // Активен
  3: '#b45309', // Терминирован
  4: '#2563eb', // Зарегистрирован
  5: '#b91c1c', // Исключён
};

export function getChatStatusColor(s) { return chatStatusColors[s] || '#888'; }
export function getChatStatusLabel(s) { return chatStatusLabels[s] || s; }
export function getChatPriorityColor(p) { return chatPriorityColors[p] || '#888'; }
export function getChatPriorityLabel(p) { return chatPriorityLabels[p] || p; }
export function getChatCategoryColor(c) { return chatCategoryColors[c] || '#6b7280'; }
export function getChatCategoryLabel(c) { return chatCategoryLabels[c] || c; }
export function getChatActivityAccent(v) { return chatActivityAccent[v] || '#4b5563'; }
