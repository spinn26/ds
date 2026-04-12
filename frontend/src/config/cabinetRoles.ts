/**
 * Конфигурация кабинетов по ролям.
 * Каждый кабинет определяет набор разделов меню, доступных роли.
 *
 * Роли в системе (поле user.role, через запятую):
 * - consultant — партнёр
 * - admin — полный доступ
 * - backoffice — БЭК (бэк-офис)
 * - support — техподдержка
 * - finance — финансовый менеджер
 * - head — руководитель
 * - calculations — руководитель по расчётам
 * - corrections — сотрудник правки
 */

// Все возможные разделы admin-меню
export type AdminSection =
  | 'contractManager' | 'contractUpload'
  | 'partners' | 'partnerStatuses'
  | 'clients' | 'acceptance'
  | 'requisites' | 'transfers'
  | 'transactionImport' | 'transactions'
  | 'commissions' | 'pool' | 'qualifications'
  | 'charges' | 'payments'
  | 'reports' | 'reportAvailability' | 'currencies'
  | 'communication' | 'structure' | 'products' | 'contests'
  | 'calculator';

// Кабинет → доступные разделы
export const cabinetSections: Record<string, AdminSection[]> = {
  // Кабинет Техподдержки — только просмотр
  support: [
    'partners', 'partnerStatuses', 'structure', 'clients',
    'contractManager', 'acceptance', 'products', 'communication',
    'calculator',
  ],

  // Кабинет Руководителя — полный просмотр
  head: [
    'calculator', 'structure', 'contests', 'contractManager',
    'clients', 'partners', 'partnerStatuses', 'acceptance',
    'transfers', 'products', 'reports', 'communication',
  ],

  // Кабинет Фин.менеджера — калькулятор + выплаты + начисления
  finance: [
    'calculator', 'payments', 'charges', 'requisites',
    'reports', 'communication',
  ],

  // Кабинет Руководителя по расчетам — продукты + калькулятор + комиссии
  calculations: [
    'calculator', 'commissions', 'qualifications', 'pool',
    'transactions', 'transactionImport', 'products', 'reports',
    'currencies',
  ],

  // Кабинет БЭК — контракты, клиенты, партнёры, загрузка
  backoffice: [
    'calculator', 'structure', 'contests', 'contractManager',
    'contractUpload', 'clients', 'partners', 'partnerStatuses',
    'acceptance', 'requisites', 'transfers', 'products',
    'communication', 'reports',
  ],

  // Кабинет сотрудника Правки — клиенты + правки
  corrections: [
    'calculator', 'clients', 'contractManager', 'partners',
  ],

  // Админ — всё
  admin: [
    'contractManager', 'contractUpload',
    'partners', 'partnerStatuses',
    'clients', 'acceptance',
    'requisites', 'transfers',
    'transactionImport', 'transactions',
    'commissions', 'pool', 'qualifications',
    'charges', 'payments',
    'reports', 'reportAvailability', 'currencies',
    'communication', 'structure', 'products', 'contests',
    'calculator',
  ],
};

/**
 * Получить доступные admin-разделы по ролям пользователя.
 */
export function getAvailableSections(roles: string[]): Set<AdminSection> {
  const sections = new Set<AdminSection>();

  for (const role of roles) {
    const roleSections = cabinetSections[role.trim()];
    if (roleSections) {
      roleSections.forEach((s) => sections.add(s));
    }
  }

  return sections;
}

/**
 * Человекочитаемые названия кабинетов по роли.
 */
export const cabinetNames: Record<string, string> = {
  support: 'Кабинет Техподдержки',
  head: 'Кабинет Руководителя',
  finance: 'Кабинет Фин.менеджера',
  calculations: 'Кабинет Руководителя по расчётам',
  backoffice: 'Кабинет БЭК',
  corrections: 'Кабинет сотрудника Правки',
  admin: 'Администратор',
  consultant: 'Кабинет Партнёра',
};
