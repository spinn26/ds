/**
 * Cabinet permissions — единый source of truth.
 *
 * Структура: role → section → permission level.
 * Источник истины — yonote-спеки кабинетов в .claude/yonote/:
 *   ✅Кабинет-сотрудника-БЭК-офиса.md
 *   ✅Кабинет-Техподдержки.md
 *   ✅Кабинет-Руководителя.md
 *   ✅Кабинет-фин.менеджера-по-выплатам.md
 *   ✅Кабинет-Руководителя-по-расчетам-(Богданова-Е.).md
 *   ✅Сотрудник-отдела-обучения.md
 *
 * Уровни доступа:
 *   'view' — Read-Only (просмотр, фильтры, экспорт), без редактирования
 *   'edit' — view + добавление/редактирование записей
 *   'full' — edit + удаление / системные действия (публикация отчётов,
 *            заморозка периода, закрытие тикетов, верификация и т.п.)
 *
 * Использование:
 *   1) В меню (MainLayout.vue) — какие разделы видны (Object.keys).
 *   2) На страницах — через composable usePermissions:
 *        const { canEdit, canFull } = usePermissions();
 *        <v-btn v-if="canEdit('clients')">Добавить клиента</v-btn>
 *        <v-btn v-if="canFull('clients')">Удалить</v-btn>
 *   3) Серверная проверка (TODO) — Policy/Gate по тем же ключам.
 *
 * admin: НЕ описан явно — у него FULL на всё (см. getPermission).
 * Это убирает дублирование и облегчает добавление новых секций.
 */

export const VIEW = 'view';
export const EDIT = 'edit';
export const FULL = 'full';

const LEVEL_RANK = { view: 1, edit: 2, full: 3 };

/**
 * Карта прав по кабинетам. Ключи секций должны совпадать с
 * adminSection в menuItems (MainLayout.vue) и с тем, что используют
 * страницы при вызове usePermissions().
 */
export const cabinetPermissions = {
  // ===== БЭК-офис (Миняйлова, Джабиева, Сагина, Горемыкина, Бартенева) =====
  // Спека ✅Кабинет-сотрудника-БЭК-офиса.md
  backoffice: {
    calculator:                 FULL,   // полный режим расчётов
    structure:                  VIEW,
    contracts:                  EDIT,   // спека: ручное добавление + редактирование, без удаления
    upload:                     FULL,   // Загрузка контрактов (массовый импорт)
    clients:                    EDIT,   // спека: двухшаговое добавление + редактирование, без удаления
    partners:                   EDIT,   // редактирование карточек, без удаления
    statuses:                   VIEW,
    transfers:                  VIEW,   // История перестановок
    acceptance:                 VIEW,   // Read-Only по спеке
    commissions:                VIEW,   // Read-Only по спеке
    reports:                    EDIT,   // спека: формирование + выгрузка, публикация — calculations
    products:                   VIEW,
    contests:                   FULL,   // спека §13: добавление/архив/удаление + предпросмотр
    communication:              EDIT,   // спека: обработка тикетов своей ветки, без закрытия
    'chat-analytics':           VIEW,
    pool:                       VIEW,
    'partner-questionnaires':   VIEW,
    requisites:                 VIEW,
    instructions:               VIEW,
  },

  // ===== Техподдержка (Левенко, Саблина, Каприеловы) =====
  // Спека ✅Кабинет-Техподдержки.md
  support: {
    partners:                   VIEW,   // Read-Only
    structure:                  VIEW,
    statuses:                   VIEW,
    acceptance:                 VIEW,
    products:                   EDIT,   // добавление продукта на витрину
    clients:                    VIEW,
    contracts:                  VIEW,   // Менеджер контрактов
    communication:              EDIT,   // спека: ведение/закрытие своих тикетов
    'support-desk':             FULL,
    calculator:                 VIEW,   // вне спеки, дают «по факту» всем стафф
    'partner-questionnaires':   VIEW,
    instructions:               EDIT,   // спека: добавление/редактирование разделов БЗ
  },

  // ===== Руководитель (Медведева, Ламакин, Угарова, Архангельский) =====
  // Спека ✅Кабинет-Руководителя.md — почти всё Read-Only
  head: {
    calculator:                 VIEW,
    structure:                  VIEW,
    contracts:                  VIEW,
    clients:                    VIEW,
    partners:                   VIEW,
    statuses:                   VIEW,
    acceptance:                 VIEW,
    transfers:                  VIEW,
    products:                   VIEW,
    reports:                    FULL,   // полный (формирование, выгрузка)
    communication:              VIEW,   // супервизия — просмотр всех тикетов
    'support-desk':             VIEW,
    'chat-analytics':           VIEW,
    pool:                       VIEW,
    'partner-questionnaires':   VIEW,
    'owner-dashboard':          FULL,
    reconciliation:             FULL,
    anomalies:                  FULL,
    funnel:                     FULL,
    cohorts:                    FULL,
    instructions:               VIEW,
  },

  // ===== Фин. менеджер (Спирькова, Петряшина) =====
  // Спека ✅Кабинет-фин.менеджера-по-выплатам.md
  finance: {
    calculator:                 EDIT,   // спека: расчёты, без системных действий
    payments:                   FULL,   // Реестр выплат — основной раздел
    charges:                    FULL,   // Прочие начисления
    reports:                    EDIT,   // спека: формирование + выгрузка, публикация — calculations
    requisites:                 VIEW,   // вне спеки, для контекста
    pool:                       VIEW,
    communication:              EDIT,   // вне спеки — нужен для ответов партнёрам
  },

  // ===== Руководитель по расчётам — Богданова Е. =====
  // Спека ✅Кабинет-Руководителя-по-расчетам-(Богданова-Е.).md
  calculations: {
    calculator:                 FULL,
    structure:                  VIEW,
    import:                     FULL,   // Импорт транзакций
    transactions:               FULL,   // Manual TX
    commissions:                EDIT,   // спека: настройка интерфейса/скрытие колонок
    charges:                    FULL,   // Прочие начисления
    pool:                       FULL,   // запуск расчёта + ручная модерация
    qualifications:             VIEW,
    reports:                    EDIT,   // формирование/выгрузка; публикация — отдельная reports-access
    'reports-access':           FULL,   // публикация, закрытие периода, принудительный пересчёт
    partners:                   EDIT,   // редактирование карточек
    requisites:                 EDIT,   // изменение статуса верификации
    statuses:                   FULL,   // ручная смена статуса
    acceptance:                 VIEW,
    transfers:                  VIEW,
    currencies:                 FULL,   // Валюты и НДС
    payments:                   FULL,   // Реестр выплат + принудительный пересчёт
    products:                   FULL,   // создание + редактирование
    contracts:                  VIEW,   // Read-Only
    clients:                    VIEW,   // Read-Only
    communication:              EDIT,   // спека: переписка по реквизитам/финвопросам
  },

  // ===== Правки (corrections) — yonote-спеки нет, ставим VIEW по умолчанию =====
  corrections: {
    calculator:                 VIEW,
    clients:                    VIEW,
    contracts:                  VIEW,
    partners:                   VIEW,
  },

  // ===== Сотрудник отдела обучения (Жосан, Вдовина, Проваторова) =====
  // Спека ✅Сотрудник-отдела-обучения.md
  education: {
    education:                  FULL,   // конструктор LMS
    'education-analytics':      FULL,   // статистика обучения
    'partner-questionnaires':   FULL,   // работа с анкетами
    partners:                   VIEW,
    products:                   VIEW,
    communication:              EDIT,   // спец. ветка по обучению
    instructions:               VIEW,
  },
};

/**
 * Получить permission-уровень для пары (roles, section).
 * Если у пользователя несколько ролей — берём максимум.
 * Возвращает 'view' / 'edit' / 'full' либо null если доступа нет.
 *
 * admin → всегда 'full' (без явного описания).
 */
export function getPermission(userRoles, section) {
  if (!userRoles?.length || !section) return null;
  if (userRoles.includes('admin')) return FULL;

  let bestRank = 0;
  let bestLevel = null;
  for (const role of userRoles) {
    const cabinet = cabinetPermissions[role];
    if (!cabinet) continue;
    const level = cabinet[section];
    if (!level) continue;
    const rank = LEVEL_RANK[level] || 0;
    if (rank > bestRank) {
      bestRank = rank;
      bestLevel = level;
    }
  }
  return bestLevel;
}

export function canView(userRoles, section) {
  return getPermission(userRoles, section) !== null;
}
export function canEdit(userRoles, section) {
  const p = getPermission(userRoles, section);
  return p === EDIT || p === FULL;
}
export function canFull(userRoles, section) {
  return getPermission(userRoles, section) === FULL;
}

/**
 * Список всех секций, доступных хотя бы на view для набора ролей.
 * admin → возвращает sentinel '*' (имеет доступ ко всему — пусть caller
 * вызывает canView() для конкретной секции).
 */
export function availableSections(userRoles) {
  if (!userRoles?.length) return new Set();
  if (userRoles.includes('admin')) return new Set(['*']);
  const out = new Set();
  for (const role of userRoles) {
    const cabinet = cabinetPermissions[role];
    if (!cabinet) continue;
    for (const section of Object.keys(cabinet)) out.add(section);
  }
  return out;
}
