/**
 * Заглушка `resources/js/api.js` для стенда редизайна.
 *
 * Данные ТОЛЬКО для вёрстки: та же форма, что отдаёт боевой API, значения
 * взяты из макета (ds-redesign/screenshots), чтобы стенд можно было класть
 * рядом со скриншотом и сравнивать. Проверять на них расчёты нельзя.
 */

const ME = {
  id: 101,
  email: 'lubava@example.com',
  firstName: 'Любава',
  lastName: 'Громова',
  patronymic: 'Сергеевна',
  phone: '+79160968148',
  role: 'consultant',
  avatarUrl: null,
  hasConsultant: true,
  questionnaireCompleted: true,
  offerAccepted: true,
  profileComplete: true,
  profileRequired: false,
  requisitesVerificationStatus: 'approved',
  paymentsSuspended: false,
  termination: null,
};

const NEWS = [
  {
    id: 1,
    kind: 'promo',
    tag: 'Промо',
    title: 'Промо «3000+»: +10% к ставке за личные продажи',
    excerpt: 'С 1 сентября по 31 декабря каждый месяц действует простое правило: сделал 3 000 баллов ЛП — получил дополнительно +10% от комиссии DS по своему ЛП.',
    coverKind: 'promo',
    coverUrl: null,
    publishedAt: '2026-09-16T09:00:00+03:00',
    readingMinutes: 2,
    isNew: true,
    pinned: true,
  },
  {
    id: 2,
    kind: 'update',
    tag: 'Обновление',
    title: 'Обновили тест на риск-профиль',
    excerpt: 'Изменения внесены с учётом новых разъяснений и рекомендаций Банка России. Обновлённая версия уже в таблице ЛФП.',
    coverKind: 'update',
    coverUrl: null,
    publishedAt: '2026-08-13T12:00:00+03:00',
    readingMinutes: 1,
    isNew: false,
    pinned: false,
  },
];

const PROMO = {
  active: true,
  newsId: 1,
  title: 'Промо «3000+»',
  target: 3000,
  current: 0,
  unit: 'ЛП',
  bonusLabel: '+10% к ставке',
  monthLabel: 'Сентябрь',
  daysLeft: 9,
  months: [
    { label: 'Сентябрь', short: 'Сен', state: 'now' },
    { label: 'Октябрь', short: 'Окт', state: 'next' },
    { label: 'Ноябрь', short: 'Ноя', state: 'next' },
    { label: 'Декабрь', short: 'Дек', state: 'next' },
  ],
};

const WORKSPACE = {
  partnerStats: {
    personalVolume: 0,
    groupVolume: 0,
    groupVolumeCumulative: 2001.82,
    qualification: '2 [Про]',
    qualificationLevel: 2,
    qualificationTitle: 'Про',
    nextLevelTitle: 'Эксперт',
    qualificationProgress: 20,
    ngpToNext: 7998.18,
    percent: 15,
    clientCount: 3,
    teamCount: 2,
  },
  promo: PROMO,
  news: NEWS,
  newsUnread: 1,
  isNetworkLeader: false,
  mentor: {
    id: 55,
    personName: 'Латыпов Руслан Вильсонович',
    qualification: '4 [ФК]',
    phone: '+79160968148',
    email: 'ruslanlatypovw@gmail.com',
    telegram: null,
  },
  networkLeader: {
    id: 7,
    personName: 'Рахманов Ленар Минибаевич',
    qualification: '9 [Платинум ДС]',
    phone: '+79153036388',
    email: 'assistant.lenar@gmail.com',
    telegram: 'LenarRakhmanov',
  },
  recentMessages: [],
  upcomingEvents: [],
  teamActivity: [],
  staffTasks: {},
};

const ARTICLE = {
  ...NEWS[0],
  content: '<p>С 1 сентября по 31 декабря каждый месяц действует простое правило.</p>',
  promo: PROMO,
  cta: {
    url: 'https://docs.google.com/presentation/d/1Lpf4u9eNg5j-2zCMO_49lCP-URjNOmNs3LFOZPu8G_U/edit',
    label: 'Открыть презентацию',
    note: 'Презентация конкурса · Google Slides',
  },
  others: [NEWS[1]],
};

const STATUS_INFO = {
  activityName: 'Активен',
  activityId: 2,
  yearPeriodEnd: '2027-07-10',
  daysRemaining: 292,
  canInvite: true,
  referralCode: 'LUBAVA',
  requiredPoints: 500,
  currentPoints: 500,
};

// URL → ответ. Порядок важен: сначала более длинные пути.
const ROUTES = [
  [/^\/workspace/, () => WORKSPACE],
  [/^\/news\/\d+$/, () => ARTICLE],
  [/^\/news/, () => ({ data: NEWS, total: NEWS.length, unread: 1 })],
  [/^\/auth\/me\/permissions/, () => ({ permissions: {} })],
  [/^\/auth\/me/, () => ME],
  [/^\/profile/, () => ({ user: ME, statusInfo: STATUS_INFO, consultant: { id: 2, personName: 'Громова Любава Сергеевна', participantCode: 'DS-2' }, referral: { code: 'LUBAVA' } })],
  [/^\/announcements\/active/, () => ({ data: [] })],
  [/^\/notifications\/unread-count/, () => ({ count: 27 })],
  [/^\/notifications/, () => ({ data: [
    { id: 1, type: 'system', title: 'Промо «3000+» стартовало', message: 'Условия — на странице новости', icon: 'mdi-bullhorn', color: 'warning', link: '/news/1', read: false, createdAt: '2026-09-16T09:00:00+03:00' },
    { id: 2, type: 'status', title: 'Обновили тест на риск-профиль', message: 'Новая версия в таблице ЛФП', icon: 'mdi-update', color: 'info', link: '/news/2', read: false, createdAt: '2026-08-13T12:00:00+03:00' },
  ] })],
  [/^\/chat\/unread-count/, () => ({ count: 1, tickets: [] })],
  [/^\/chat\/tickets/, () => ({ data: [], total: 0 })],
  [/^\/my-note/, () => ({ note: '' })],
  [/^\/menu\/published/, () => ({ items: [] })],
  [/^\/admin\/calc-state/, () => null],
  [/^\/status/, () => ({ state: 'ok', label: 'Все системы работают' })],
  [/^\/i18n\/overrides/, () => ({ overrides: {} })],
];

function respond(url) {
  const clean = String(url).split('?')[0];
  for (const [re, make] of ROUTES) {
    if (re.test(clean)) return Promise.resolve({ data: make() });
  }
  // Незакрытый эндпоинт виден в консоли стенда, а не падает молча.
  console.warn('[стенд] нет заглушки для', clean);
  return Promise.resolve({ data: {} });
}

const api = {
  get: (url) => respond(url),
  post: (url) => respond(url),
  put: (url) => respond(url),
  patch: (url) => respond(url),
  delete: (url) => respond(url),
  defaults: { headers: { common: {} } },
  interceptors: { request: { use() {} }, response: { use() {} } },
};

export default api;
