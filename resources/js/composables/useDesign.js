/**
 * Design System — единый источник стилей и утилит.
 * Импортируй и используй во всех страницах для консистентности.
 */

// === COLORS ===
export const statusColors = {
  // Activity statuses
  active: 'success',
  terminated: 'error',
  registered: 'info',
  excluded: 'error',

  // Ticket statuses
  new: 'info',
  open: 'info',
  in_progress: 'warning',
  pending: 'warning',
  resolved: 'success',
  closed: 'grey',

  // Ticket categories
  support: 'blue',
  backoffice: 'orange',
  legal: 'purple',
  accounting: 'green',
  accruals: 'red',

  // Payment statuses
  paid: 'success',
  pending: 'warning',

  // Contract statuses
  'Активирован': 'success',
  'Сбор документов': 'warning',
  'Закрыто': 'error',
  'Закрыто нереализовано': 'error',
};

// === STATUS LABELS ===
export const statusLabels = {
  new: 'Новый',
  open: 'В работе',
  in_progress: 'В работе',
  pending: 'Ожидание',
  resolved: 'Решён',
  closed: 'Закрыт',
};

// === PRIORITIES ===
export const priorityColors = {
  critical: 'error',
  high: 'warning',
  medium: 'info',
  low: 'success',
};

export const priorityLabels = {
  critical: 'Критический',
  high: 'Высокий',
  medium: 'Средний',
  low: 'Низкий',
};

export function getPriorityColor(p) {
  return priorityColors[p] || 'default';
}

export const categoryLabels = {
  support: 'Техподдержка',
  backoffice: 'Бэк-офис',
  legal: 'Юрист',
  accounting: 'Бухгалтер',
  accruals: 'Начисления',
};

export const activityLabels = {
  1: 'Активен',
  3: 'Терминирован',
  4: 'Зарегистрирован',
  5: 'Исключён',
};

// === FORMATTERS ===
export function fmt(n) {
  return Number(n || 0).toLocaleString('ru-RU');
}

export function fmt2(n) {
  return Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function fmtDate(d) {
  if (!d) return '—';
  // Already formatted as dd.mm.yyyy by backend (Carbon format('d.m.Y'))
  if (typeof d === 'string' && /^\d{2}\.\d{2}\.\d{4}$/.test(d)) return d;
  try {
    const date = new Date(d);
    if (isNaN(date.getTime())) return '—';
    return date.toLocaleDateString('ru-RU');
  } catch {
    return '—';
  }
}

export function fmtDateTime(d) {
  if (!d) return '—';
  try {
    return new Date(d).toLocaleDateString('ru-RU') + ' ' +
      new Date(d).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  } catch {
    return d;
  }
}

export function timeAgo(d) {
  if (!d) return '';
  const now = Date.now();
  const then = new Date(d).getTime();
  const diff = Math.floor((now - then) / 1000);
  if (diff < 60) return 'только что';
  if (diff < 3600) return `${Math.floor(diff / 60)} мин назад`;
  if (diff < 86400) return `${Math.floor(diff / 3600)} ч назад`;
  if (diff < 604800) return `${Math.floor(diff / 86400)} дн назад`;
  return fmtDate(d);
}

// === HELPERS ===
export function getInitials(name) {
  if (!name) return '?';
  return name.split(' ').map(w => w[0]).filter(Boolean).slice(0, 2).join('').toUpperCase();
}

export function getStatusColor(status) {
  return statusColors[status] || 'grey';
}

export function getActivityColor(activityId) {
  const map = { 1: 'success', 3: 'error', 4: 'info', 5: 'error' };
  return map[activityId] || 'grey';
}

export function getCategoryColor(category) {
  return statusColors[category] || 'grey';
}

// === DESIGN TOKENS ===
export const tokens = {
  // Card elevation
  cardElevation: 2,
  cardElevationHover: 6,
  cardElevationDialog: 12,

  // Page header icon size
  headerIconSize: 28,

  // Table density
  tableDensity: 'compact',

  // Page title variant
  titleVariant: 'text-h5',

  // Spacing
  pageGap: 4,        // pa-4
  sectionGap: 3,     // mb-3
  cardPadding: 4,    // pa-4
};
