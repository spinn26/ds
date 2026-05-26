# DS Consulting — техническое задание для дизайна

> Полное ТЗ для дизайнера: один документ описывает все экраны платформы DS Consulting, дизайн-токены, компоненты, паттерны, состояния, тон голоса и адаптив. Дизайнер берёт этот документ и рисует всю платформу в Figma без необходимости лезть в код.

**Версия:** 2026-05-26
**Стек:** Laravel 11 + Vue 3 + Vuetify 3 + Pinia + Vite + Socket.IO
**Темизация:** light (по умолчанию) + dark; `AdminLayout` всегда forced-dark
**Бренд-цвет:** `#2E7D32` (primary), `#6EE87A` (secondary mint)

---

## Содержание

0. [Резюме (TL;DR)](#0-резюме-tldr)
1. [О платформе](#1-о-платформе)
2. [Брендинг](#2-брендинг)
3. [Дизайн-токены](#3-дизайн-токены)
4. [Темизация](#4-темизация)
5. [Иконографика](#5-иконографика)
6. [Layout-система](#6-layout-система)
7. [UI-компоненты](#7-ui-компоненты)
8. [Паттерны экранов](#8-паттерны-экранов)
9. [Состояния](#9-состояния)
10. [Все экраны (~95)](#10-все-экраны-95)
11. [Адаптив](#11-адаптив)
12. [Доступность](#12-доступность)
13. [Тон голоса и microcopy](#13-тон-голоса-и-microcopy)
14. [Что НЕ покрыто в этом ТЗ](#14-что-не-покрыто-в-этом-тз)

---

## 0. Резюме (TL;DR)

**DS Consulting** — это партнёрская платформа для финансовых консультантов: партнёр (consultant) ведёт клиентов, открывает контракты по продуктам, проходит обучение, видит свои комиссии и пул, общается с поддержкой; staff-роли (бэк-офис, поддержка, финансы, расчёты, корректировки, кураторы обучения, руководитель) обслуживают эти процессы из общего кабинета; админ управляет настройками, справочниками, контентом, пользователями и мониторингом.

**Платформа покрывает ~95 экранов:**

```
Auth (2)                  · Login · Register
Partner cabinet (~25)     · Workspace · Dashboard · Structure · Profile · Education (8 экранов) ·
                            Clients · MyContracts · TeamContracts · Finance Report · Calculator ·
                            Products · InsmartWidget · Contests · Communication · Instructions ·
                            Help · Referrals · Terminated · SystemStatus · Forbidden · NotFound
Manager/staff (~30)       · Manage Workspace · Periods (list + carddetail) · ContractManager ·
                            ContractUpload · Partners · PartnerStatuses · Clients · Acceptance ·
                            Requisites · Transfers · Permissions · Instructions admin ·
                            TransactionImport · Transactions · Commissions · Pool · Qualifications ·
                            Charges · PaymentRegistry · Payments-legacy · Reports · Currencies ·
                            Products admin · ProductsPreview · Contests admin ·
                            EducationConstructor + Education-legacy · EducationCategories ·
                            EducationAnalytics · KbConstructor · HomeworkQueue ·
                            PartnerQuestionnaires · TechSupportDesk
Admin cabinet (~30)       · Dashboard · OwnerDashboard · Funnel · Reconciliation · Anomalies ·
                            Cohorts · OpsCalendar · BulkOps · Users · News · Roadmap · Mail ·
                            Triggers · Integrations · ApiKeys · Settings · Monitoring ·
                            Admin SystemStatus · References + ReferenceDetail (11 каталогов)
Chat (3)                  · PartnerChat · StaffChat · Chat Analytics
Layouts & Shared          · MainLayout · AdminLayout · Auth-layout · 20+ shared компонентов
```

**Бренд-настроение:** «зелёный, аккуратный, профессиональный, без агрессии». Уверенный финансовый продукт с человеческим обращением «Вы».

**Источники-артборды (приложение):** 74 артборда в `desing/Дизайн-система.html` + JSX-копии в `desing/ds-*.jsx`.

---

## 1. О платформе

### 1.1 Назначение

DS Consulting — SaaS-кабинет для сети финансовых консультантов:
- **Партнёр (consultant)** ведёт клиентов, оформляет контракты, видит свои объёмы (ЛП — личные продажи, ГП — групповые, НГП — нараст. групповые), квалификацию (10 уровней), комиссии, пул, реферальные приглашения, обучение и базу знаний.
- **Staff** (бэк-офис, поддержка, финансы, расчёты, корректировки, кураторы) обслуживают партнёров: проверяют документы и реквизиты, импортируют транзакции, считают комиссии, ведут реестр выплат, отвечают на тикеты, проверяют домашки.
- **Админ** управляет ролями, справочниками, контентом, интеграциями, мониторингом, рассылками.
- **Terminated/excluded** — заблокированный партнёр, видит только страницу-заглушку с координатами техподдержки.

### 1.2 Аудитория и роли

| Роль | Кто | Где работает | Layout |
|---|---|---|---|
| `consultant` | Активный партнёр | `/` (полный кабинет) | MainLayout |
| `registered` | Только что зарегистрированный, не прошёл онбординг | `/education` + анкета | MainLayout (ограниченное меню) |
| `admin` | Полный доступ | `/admin/*` + `/manage/*` + `/` | AdminLayout + MainLayout |
| `staff` (super-set: `backoffice` / `support` / `finance` / `head` / `calculations` / `corrections` / `education`) | Сотрудники компании | `/manage/*` + `/` | MainLayout (с пунктами /manage) |
| `terminated` / `excluded` (activityStatus 3 / 5) | Заблокирован кабинет | `/terminated`, `/profile`, `/help` | MainLayout (минимум) |

Видимость пунктов меню — по effective permissions из БД; fallback на `config/cabinetPermissions.js`. Для read-only ролей выводится info-banner «Режим только для просмотра» и скрываются write-кнопки.

### 1.3 Стек и его влияние на дизайн

- **Vue 3 + Vuetify 3** — материальная дизайн-система Material 3 (MD3) под капотом. Все компоненты, описанные в этом ТЗ, существуют в Vuetify как `<v-btn>`, `<v-text-field>`, `<v-card>`, `<v-dialog>`, `<v-chip>`, `<v-data-table>` и т.д. — это значит, что дизайнер может опираться на MD3 как на референс, но цвета, типографика и формы — наши (см. §3).
- **Vite SPA** на single Laravel monolith. Партнёрский кабинет и стафф-кабинет — одно приложение, две layout-обёртки (Main / Admin).
- **Pinia store** для авторизации.
- **Sanctum Bearer-токены** (хранятся в localStorage).
- **Socket.IO (порты 3001/3002)** — real-time для чата и уведомлений.
- **PostgreSQL 16, 301 таблица**, 286 — legacy Directual.

Что это значит для дизайна:
- Все компоненты — из Vuetify. Кастомных «уникальных» виджетов почти нет, кроме `BrandWaves`, `MoneyCell`, `StatusChip`, `BooleanCell`, `DataTableWrapper`, `EmptyState`, `PageHeader`, `ChatLauncher`, `OnboardingQuestionnaire`, `SystemStatusChip`.
- Используем MDI-иконки (Material Design Icons), никаких других пакетов.
- Skeleton-loader, snackbar, dialog — стандартные Vuetify-паттерны.

---

## 2. Брендинг

### 2.1 Логотип (DSMark)

Логотип-марка — компактная подпись «DS», использующаяся в трёх вариантах:

| Вариант | Применение | Размер |
|---|---|---|
| `DSMark size={64}` | Hero / большие промо-блоки | 64 × 64 |
| `DSMark size={44}` | Topbar / в шапке Login | 44 × 44 |
| `DSMark size={28}` | Inline в тексте, рядом с заголовком sidebar | 28 × 28 |
| `DSMark size={18}` | Favicon-стиль, маленький бейдж | 18 × 18 |

**Конструкция:**
- Квадратный шейп с радиусом `radius-md` (8px на 44px). Можно использовать круглую версию (`shape="circle"`) — она применяется только в hero-сценах и BrandWaves-декоре.
- Буквы «DS» внутри — `font-weight: 700`, цвет `--ds-on-primary` (#FFFFFF на светлой теме; `--ds-on-primary` = #002106 в тёмной — это нормально, потому что в тёмной primary становится мятным).
- Фон — `--ds-primary` (зелёный в светлой, мятный в тёмной).

**Минимальный размер:** 18px (только favicon / inline в одной строке текста). Меньше — не использовать, теряется читаемость.

**Запасной вариант на тёмном брендовом фоне:** инверсный (белая марка на тёмно-зелёном `--ds-on-primary-container`), используется в Login-hero.

### 2.2 Название и подписи

| Где | Текст | Стиль |
|---|---|---|
| Topbar (компактно) | **DS** + «ПЛАТФОРМА» | `headline-s` + `label-m UPPERCASE` |
| Auth-hero | **DS Consulting** + «Партнёрская платформа» | `display-m` + `body-l muted` |
| AdminLayout | **DS Управление** + «ПАНЕЛЬ УПРАВЛЕНИЯ» | `headline-s` + `label-m UPPERCASE` |
| Sidebar header (Main) | **DS** + «ПЛАТФОРМА» + caption роли | `title-l primary` + `label-m` + `body-s muted` |
| Login eyebrow | «вход в кабинет» | `label-m UPPERCASE primary` |
| Register sub-header | «КОНСАЛТИНГ ПЛАТФОРМА» | `caption letter-spacing=4` |

**Правило:** «DS Consulting» — полное название (используется в маркетинге, hero, формальных коммуникациях). «DS» — короткая марка (в layout-шапках и узких местах). «Платформа партнёров» — сленговое название для рекламных текстов. Никаких других сокращений.

### 2.3 BrandWaves — параметрический фоновый паттерн

`BrandWaves` — фирменная SVG-сетка волн, заменяющая всякие decorative gradients/dots/static images.

**Параметры (рассказать дизайнеру для воспроизведения в Figma):**
| Prop | Тип | Назначение |
|---|---|---|
| `width` / `height` | number | Размер контейнера |
| `shape` | `'sheet' \| 'circle'` | Прямоугольник или круг (для логотипа-кружка) |
| `bgColor` | hex | Фоновый цвет (mint `#6EE87A` для бренд-сцен, прозрачный для overlay) |
| `strokeColor` | hex | Цвет линий (белый на бренд-фоне, primary на белом) |
| `rows` | number | Кол-во строк волн (обычно 12-22) |
| `columns` | number | Кол-во колонок (12-22) |
| `amplitude` | number | Высота волны (4-8) |
| `frequency` | number | Частота |
| `strokeWidth` | number | Толщина линии (1-2) |
| `strokeOpacity` | number | Прозрачность (0.3-0.6) |

**Где применяется:**
1. **Login hero** (левая половина) — фон 900×900, rows 18, columns 22, amplitude 6, stroke `#FFFFFF` opacity 0.35.
2. **EmptyState** — внутри кружка 88×88 (`shape="circle"`), mint background, как иллюстрация.
3. **Логотип-кружок** (если нужно декорировать большую марку) — circle 420×420 в Register.
4. **Hero-баннеры** в Education и success-страницах.
5. **Marketing-cards** (если будут).

**Запрет:** не использовать вместо BrandWaves градиенты «radial мятный→прозрачный», точки, статичные иллюстрации, фото-фоны. BrandWaves — единственный декоративный паттерн в DS.

### 2.4 Tone of voice (русский)

**Базовые правила:**
- Обращение **на «Вы»** (с заглавной — только в личной переписке / уведомлениях; в интерфейсе — со строчной).
- Без жаргона, без англицизмов («сайдбар», «нотификация»), без бизнес-сленга («экосистема», «синергия»).
- Глагол + объект в кнопках: «Сохранить», «Закрыть период», «Войти в кабинет».
- Подсказки — дружелюбные, но не игривые: «Загрузите фото первой страницы паспорта», а не «Кидайте паспорт сюда!».
- Ошибки — без обвинений: «Не удалось загрузить» вместо «Вы ввели неверные данные».
- Цифры — пробелом-разделителем (`1 234 567,89 ₽`), валюта-суффиксом, JetBrains Mono.

**Стопперы (никогда так не говорим):**
- «Ой!», «Упс!», «Что-то пошло не так» (слишком детский).
- «System error», «Error 500» в UI (только в логах).
- «Сорян», «Извините за неудобства» (избыточно, лучше предложить действие).

**Подробнее — см. §13.**

---

## 3. Дизайн-токены

Все токены — в файле `desing/ds-tokens.css`. CSS-переменные мапятся 1:1 в Vuetify theme config (см. `desing/README.md`).

### 3.1 Палитра — Light

**Brand · primary** (зелёный):

| Токен | HEX | Применение |
|---|---|---|
| `--ds-primary` | `#2E7D32` | Основной CTA, активные состояния, акценты |
| `--ds-on-primary` | `#FFFFFF` | Текст на primary-фоне |
| `--ds-primary-container` | `#C8E6C9` | Контейнер (например, primary tonal button) |
| `--ds-on-primary-container` | `#002106` | Текст в primary-container |
| `--ds-primary-soft` | `#E8F1E9` | Soft-фон выделенных блоков |
| `--ds-primary-tint` | `#F4F9F4` | Едва-зелёный фон (zebra-stripes, hover-overlay) |

**Brand · secondary** (mint, декоративный):

| Токен | HEX | Применение |
|---|---|---|
| `--ds-secondary` | `#6EE87A` | Hero-фон, бренд-акценты, BrandWaves |
| `--ds-on-secondary` | `#0A2B10` | Текст на mint (brand-ink) |
| `--ds-secondary-container` | `#C8FBCE` | Soft mint card |
| `--ds-on-secondary-container` | `#001505` | Текст в mint-container |

**Tertiary** (info-blue):

| Токен | HEX | Применение |
|---|---|---|
| `--ds-tertiary` | `#4361A8` | Вторичные акценты (link-like) |
| `--ds-on-tertiary` | `#FFFFFF` | |
| `--ds-tertiary-container` | `#DAE2FF` | |
| `--ds-on-tertiary-container` | `#001847` | |

**Status:**

| Токен | HEX | Применение |
|---|---|---|
| `--ds-success` | `#2E7D32` | Совпадает с primary — success в DS зелёный |
| `--ds-on-success` | `#FFFFFF` | |
| `--ds-success-container` | `#C8E6C9` | Success-alert tonal |
| `--ds-warning` | `#ED6C02` | Предупреждение |
| `--ds-on-warning` | `#FFFFFF` | |
| `--ds-warning-container` | `#FFE0B2` | |
| `--ds-error` | `#C62828` | Ошибка |
| `--ds-on-error` | `#FFFFFF` | |
| `--ds-error-container` | `#FFDAD6` | |
| `--ds-info` | `#0277BD` | Информация |
| `--ds-on-info` | `#FFFFFF` | |
| `--ds-info-container` | `#CDE5FF` | |

**Surface family** (5 ступеней):

| Токен | HEX | Применение |
|---|---|---|
| `--ds-background` | `#F8F9F8` | Фон страниц |
| `--ds-surface` | `#FFFFFF` | Карточки, поверхности |
| `--ds-surface-dim` | `#E6E8E6` | Затемнённая поверхность |
| `--ds-surface-bright` | `#FFFFFF` | Высветленная (фейк, такой же как surface в light) |
| `--ds-surface-container-lowest` | `#FFFFFF` | Самый низкий уровень |
| `--ds-surface-container-low` | `#FAFBFA` | Внутренние card-tones |
| `--ds-surface-container` | `#F1F2F1` | Средний серый |
| `--ds-surface-container-high` | `#E9EBE9` | Высокий контраст серого |
| `--ds-surface-container-highest` | `#E2E4E2` | Максимальный контраст серого |

**Text on surface:**

| Токен | HEX | Применение |
|---|---|---|
| `--ds-on-surface` | `#1A1F1B` | Основной текст |
| `--ds-on-surface-variant` | `#4A524C` | Вторичный текст |
| `--ds-on-surface-muted` | `#8A8F8B` | Подписи, мета |
| `--ds-on-surface-faint` | `#B8BCB8` | Disabled |

**Outlines:**

| Токен | HEX | Применение |
|---|---|---|
| `--ds-outline` | `#BDC4BE` | Разделители средние |
| `--ds-outline-variant` | `#DDE2DE` | Тонкие разделители (table-row, card-border) |
| `--ds-outline-soft` | `#EEF1EE` | Самые тонкие, едва-зелёные |

**Overlays и scrim:**

| Токен | Значение | Применение |
|---|---|---|
| `--ds-scrim` | `rgba(0,0,0,0.5)` | Подложка под модалкой |
| `--ds-overlay` | `rgba(0,0,0,0.04)` | Hover-state overlay |
| `--ds-overlay-strong` | `rgba(0,0,0,0.08)` | Pressed-state overlay |

### 3.2 Палитра — Dark

В тёмной теме primary становится мятным (`#6EE87A`), потому что зелёный `#2E7D32` плохо читается на тёмном фоне.

**Brand · primary (тёмная):**

| Токен | HEX | Применение |
|---|---|---|
| `--ds-primary` | `#6EE87A` | Мятный — primary в dark |
| `--ds-on-primary` | `#002106` | Текст на мятном |
| `--ds-primary-container` | `#1B5E20` | Тёмно-зелёный container |
| `--ds-on-primary-container` | `#C8E6C9` | Светло-зелёный текст |
| `--ds-primary-soft` | `rgba(110,232,122,0.10)` | Прозрачно-мятный |
| `--ds-primary-tint` | `rgba(110,232,122,0.06)` | Едва-мятный |

**Secondary:** `--ds-secondary: #A4E0AC` (pastel mint).

**Status (dark):**
- `--ds-success: #A4E0AC`
- `--ds-warning: #FFB77A`
- `--ds-error: #FFB4AB`
- `--ds-info: #93CCFF`

**Surface family (dark):**

| Токен | HEX |
|---|---|
| `--ds-background` | `#0F1311` |
| `--ds-surface` | `#161A17` |
| `--ds-surface-container-lowest` | `#0A0D0B` |
| `--ds-surface-container-low` | `#14181B` |
| `--ds-surface-container` | `#1A1E1B` |
| `--ds-surface-container-high` | `#24292A` |
| `--ds-surface-container-highest` | `#2E3431` |

**Text on surface (dark):**
- `--ds-on-surface: #E2E4E2`
- `--ds-on-surface-variant: #C2C8C3`
- `--ds-on-surface-muted: #8B928D`
- `--ds-on-surface-faint: #5A6058`

**Outlines (dark):**
- `--ds-outline: #3D4540`
- `--ds-outline-variant: #2A312C`
- `--ds-outline-soft: #1F2521`

### 3.3 Типографика

**Шрифт:**
- **Inter** 400 / 500 / 600 / 700 — основной (`--ds-font-sans`).
- **JetBrains Mono** 400 / 500 / 700 — числа, токены, таймкоды (`--ds-font-mono`).
- Fallback: `-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, system-ui, sans-serif`.

**Шкала (15 ступеней, MD3-aligned):**

| Класс | Размер / Высота | Применение | Пример |
|---|---|---|---|
| `ds-display-l` | 700 · 56/1.12 · letter-spacing -1px | Главные брендовые экраны | «DS Consulting» в Login |
| `ds-display-m` | 700 · 44/1.15 · -0.8px | Большие заголовки | «Платформа партнёров» |
| `ds-display-s` | 700 · 36/1.2 · -0.6px | Hero-заголовки внутри страниц | «Закрываем квартал» |
| `ds-headline-l` | 700 · 30/1.22 · -0.4px | H1 страниц | «Реестр выплат · март» |
| `ds-headline-m` | 700 · 24/1.25 · -0.3px | H2 секций | «Клиенты партнёра» |
| `ds-headline-s` | 700 · 20/1.3 · -0.2px | H3 / Profile-Hero ФИО | «Карточка контракта» |
| `ds-title-l` | 600 · 18/1.35 | Заголовок секции | «Реквизиты ИП» |
| `ds-title-m` | 600 · 15/1.4 | Подзаголовок карточки | «Информация о партнёре» |
| `ds-title-s` | 600 · 13/1.4 | Список / навигация | «Дашборд» |
| `ds-body-l` | 400 · 15/1.55 | Длинный контент, регламент | Описание курса |
| `ds-body-m` | 400 · 14/1.5 | Базовый текст в формах/таблицах | Содержимое строки |
| `ds-body-s` | 400 · 13/1.5 | Хинты, captions | «обновлено 2 часа назад» |
| `ds-label-l` | 600 · 13/1.3 | Кнопка / таб | «СОХРАНИТЬ» |
| `ds-label-m` | 600 · 12/1.3 · +0.6 · UPPERCASE | Eyebrow | «ТЕКУЩАЯ КВАЛИФИКАЦИЯ» |
| `ds-label-s` | 600 · 11/1.3 · +1 · UPPERCASE | Tag / status | «АКТИВЕН» |

**Modifier classes:**
- `.ds-mono` — переключает на JetBrains Mono + `font-variant-numeric: tabular-nums`. Применяется ко всем числам (суммы, проценты, баллы).
- `.ds-muted` — `color: var(--ds-on-surface-variant)`. Вторичный текст.
- `.ds-faint` — `color: var(--ds-on-surface-muted)`. Третичный.

### 3.4 Spacing (4px база)

| Токен | Значение | Применение |
|---|---|---|
| `--ds-space-0` | 0 | Нет отступа |
| `--ds-space-1` | 4px | Микро-отступ (chip-icon → chip-text) |
| `--ds-space-2` | 8px | Базовый gap (между чипами в row) |
| `--ds-space-3` | 12px | Inner card padding compact |
| `--ds-space-4` | 16px | Стандартный отступ (`pa-4`) |
| `--ds-space-5` | 20px | Между секциями карточки |
| `--ds-space-6` | 24px | Расширенный отступ |
| `--ds-space-7` | 32px | Hero-блоки |
| `--ds-space-8` | 40px | Большие промо-блоки |
| `--ds-space-9` | 56px | Header-фрагмент Auth-страниц |
| `--ds-space-10` | 72px | Самые большие промо-отступы |

**Правила использования (важно):**
- Дефолтный page-padding — **16px** (`pa-4`).
- Inner card padding — **12-16px** (`pa-3` / `pa-4`).
- Mobile — без изменений (16px).
- Tablet — допустимо `pa-md-6` (24px) для broader breath.
- `pa-6` (24px) — только marketing-style hero (Login, Welcome).
- Между секциями `mb-4` (16px) — стандарт. `mb-6` (24px) только перед большими заголовками.

### 3.5 Radius

| Токен | Значение | Применение |
|---|---|---|
| `--ds-radius-xs` | 4px | Маленькие маркеры, checkbox-box |
| `--ds-radius-sm` | 6px | Компактные тонкие компоненты |
| `--ds-radius-md` | 8px | **Default для кнопок и полей ввода** |
| `--ds-radius-lg` | 12px | **Default для карточек (`v-card rounded=lg`)** |
| `--ds-radius-xl` | 16px | Большие хедерные карточки, dialog-shell |
| `--ds-radius-2xl` | 24px | Hero-блоки, login-card |
| `--ds-radius-pill` | 999px | Чипы, badges, switch-track |

### 3.6 Shadow

**4 уровня elevation + focus:**

| Токен | Light | Применение |
|---|---|---|
| `--ds-shadow-1` | `0 1px 2px rgba(15,30,15,0.04), 0 1px 3px rgba(15,30,15,0.06)` | Hover на card |
| `--ds-shadow-2` | `0 2px 4px rgba(15,30,15,0.05), 0 4px 8px rgba(15,30,15,0.06)` | Default elevation для card |
| `--ds-shadow-3` | `0 4px 8px rgba(15,30,15,0.06), 0 8px 24px rgba(15,30,15,0.08)` | Dropdown, menu |
| `--ds-shadow-4` | `0 8px 16px rgba(15,30,15,0.08), 0 16px 40px rgba(15,30,15,0.10)` | Dialog, drawer |
| `--ds-shadow-focus` | `0 0 0 3px rgba(46,125,50,0.18)` | **Focus ring (3px primary 18%)** |

В тёмной теме все тени усиливаются (alpha 0.30-0.40 вместо 0.04-0.10).

### 3.7 Motion

**Durations:**

| Токен | Значение | Применение |
|---|---|---|
| `--ds-dur-fast` | 120ms | Микро-фидбек (hover, focus) |
| `--ds-dur-medium` | 200ms | Переключение состояний (chip filter active) |
| `--ds-dur-slow` | 320ms | Появление/исчезание панелей (sidebar collapse) |
| `--ds-dur-emphasized` | 480ms | Значимые переходы, success-confetti |

**Easings (MD3-style):**

| Токен | Значение | Применение |
|---|---|---|
| `--ds-ease-standard` | `cubic-bezier(.2, 0, 0, 1)` | Default для всего |
| `--ds-ease-emphasized` | `cubic-bezier(.3, 0, 0, 1)` | Значимые переходы |
| `--ds-ease-decelerate` | `cubic-bezier(0, 0, 0, 1)` | Появление |
| `--ds-ease-accelerate` | `cubic-bezier(.3, 0, 1, 1)` | Исчезновение |

**`prefers-reduced-motion`:**

При `prefers-reduced-motion: reduce` дизайнер должен предполагать, что отключаются:
- Конфетти / scale-in success-анимации
- Hover-translate (lift) на карточках
- Blob-drift на Register-фоне
- BrandWaves «дышащая» анимация (если бы была)
- Любые анимации длиннее 200ms

Все функциональные переходы (открытие меню, smooth scroll, fade) — остаются, но мгновенные.

### 3.8 Density / row heights

| Токен | Значение | Применение |
|---|---|---|
| `--ds-h-control` | 40px | Default-высота кнопки/поля |
| `--ds-h-control-sm` | 32px | Compact-кнопки/поля |
| `--ds-h-control-lg` | 48px | Large CTA |
| `--ds-h-row` | 48px | Default table-row |
| `--ds-h-row-compact` | 40px | Compact table-row (default для админских таблиц) |

**Правило:** глобальный density default — `comfortable` (40px). На таблицах с >25 строк коротким контентом — `compact` (32px). На primary CTA в формах — `large` (48px).

### 3.9 Z-layers

| Токен | Z-index | Применение |
|---|---|---|
| `--ds-z-sticky` | 10 | Sticky-headers, sticky-CTA |
| `--ds-z-overlay` | 100 | Toast, badge |
| `--ds-z-drawer` | 200 | Sidebar/drawer |
| `--ds-z-dialog` | 300 | Modal |
| `--ds-z-snackbar` | 400 | Snackbar поверх модалки |
| `--ds-z-tooltip` | 500 | Tooltip — самый верх |

---

## 4. Темизация

### 4.1 Light vs Dark — как переключаются

- **Default:** light.
- Switch в Topbar: `mdi-weather-sunny` / `mdi-weather-night` (user-menu или иконка-toggle).
- Состояние сохраняется в `localStorage` (key: `theme`).
- На уровне CSS работает атрибут `data-ds-theme="light|dark"` на `<html>` или родительском `.ds`.
- В Vuetify: `useTheme().global.name.value = 'light' | 'dark'`.

### 4.2 AdminLayout — всегда forced-dark

Это сознательное архитектурное решение: админ-кабинет визуально отделён от партнёрского, чтобы случайно не перепутать «вошёл-как-партнёр» с админкой.
- При входе на `/admin/*` → форсится `dark` независимо от user preference.
- При выходе из `/admin/*` → восстанавливается user preference.
- НЕЛЬЗЯ убирать `theme="dark"` с `AdminLayout`.
- Аватар admin'а — `color="secondary"` (мятный), а не primary.

### 4.3 Какие токены flip'аются между темами

| Токен | Light | Dark | Почему |
|---|---|---|---|
| `--ds-primary` | `#2E7D32` (тёмно-зелёный) | `#6EE87A` (мятный) | Зелёный плохо читается на тёмном фоне |
| `--ds-secondary` | `#6EE87A` | `#A4E0AC` | Mint становится пастельным |
| `--ds-success` | = primary | `#A4E0AC` | |
| `--ds-warning` | `#ED6C02` | `#FFB77A` (peachy) | |
| `--ds-error` | `#C62828` | `#FFB4AB` (pastel red) | |
| `--ds-info` | `#0277BD` | `#93CCFF` (sky) | |
| `--ds-background` | `#F8F9F8` | `#0F1311` | |
| `--ds-surface` | `#FFFFFF` | `#161A17` | |
| `--ds-shadow-*` | прозрачно-серые | насыщенные чёрные | |
| `--ds-scrim` | `rgba(0,0,0,0.5)` | `rgba(0,0,0,0.6)` | |
| `--ds-overlay` | `rgba(0,0,0,0.04)` | `rgba(255,255,255,0.04)` | Hover в dark — белым |

**Никогда не хардкодить hex.** Используем `rgb(var(--v-theme-primary))`, `rgba(var(--v-theme-surface), 0.9)`, `var(--ds-primary)`.

---

## 5. Иконографика

### 5.1 MDI only

Используем **только Material Design Icons** (`@mdi/font` или `mdi-` префикс в Vuetify):
- Единая визуальная система.
- ~7000 икон — закрывают любой кейс.
- Outline / filled / circular варианты под все случаи.

**Никаких других icon-set'ов:** не используем Font Awesome, Heroicons, Lucide, Tabler, Phosphor.

### 5.2 Конвенции использования

**Размеры:**

| Размер | Применение |
|---|---|
| 16px (`size="x-small"`) | В компактных рядах (status-chip dot) |
| 18px (`size="small"`) | В кнопках с текстом, в строках таблицы |
| 20px (default) | В лейблах форм, prefix-icony |
| 24px (`size="default"`) | В topbar и общем UI |
| 28px (`size="large"`) | В PageHeader |
| 32-40px | В KPI-карточках, hero-блоках |
| 56-80px | В empty-state, Profile-hero avatar |

**Цвета (правило):**
- В **нав-меню** (sidebar) — иконки в `on-surface-variant`, активный пункт — `primary`.
- В **action-кнопках** — наследует цвет кнопки (`mdi-content-save` в primary button — белый).
- В **status-чипах** — цвет статуса (mdi-check-circle → success, mdi-alert-circle → warning).
- В **table-row actions** — `outline` цвет (mdi-pencil, mdi-delete), при hover — primary / error.

**Outline vs filled:**
- **Outline** (`-outline`) — для навигации и не-критичных действий (mdi-account-outline, mdi-bell-outline, mdi-pencil-outline).
- **Filled** (без суффикса) — для активного состояния (mdi-bell, mdi-account, выбранный пункт в навигации).
- **Circle** (`-circle`) — для статусов (mdi-check-circle, mdi-alert-circle).
- **Decagram** — для критических statusов (mdi-alert-decagram, mdi-check-decagram).

### 5.3 Таблица замен эмодзи-плейсхолдеров на MDI

В JSX-артбордах использовались эмодзи как плейсхолдеры. В реальном UI они заменяются на MDI:

| Эмодзи | MDI | Применение |
|---|---|---|
| ⌂ | `mdi-home` | Главная |
| ▦ | `mdi-view-dashboard` | Дашборд |
| ⚇ | `mdi-account-group` | Клиенты/Партнёры |
| ▤ | `mdi-file-document` | Контракты |
| ¤ | `mdi-cash-multiple` | Финансы / Пул |
| ⏏ | `mdi-school` | Обучение |
| ❑ | `mdi-chat` | Чат |
| ⚙ | `mdi-cog` | Настройки |
| ⚐ | `mdi-bell` | Уведомления |
| ⌕ | `mdi-magnify` | Поиск |
| ⋮ | `mdi-dots-vertical` | Меню действий |
| ⤓ | `mdi-download` | Экспорт |
| ⤒ | `mdi-upload` | Импорт |
| ⎘ | `mdi-content-copy` | Копировать в буфер |
| ↻ | `mdi-refresh` | Обновить |
| ☷ | `mdi-sitemap` | Структура |
| ★ | `mdi-star` | Рейтинг |
| ✓ | `mdi-check-circle` | Подтверждение |
| ○ | `mdi-circle-outline` | Пустое состояние |
| ◐ | `mdi-circle-slice-4` | Прогресс ½ |
| 🔒 | `mdi-lock` | Заблокировано |

### 5.4 Основные иконки по группам (cheatsheet)

**Навигация:**
`mdi-home`, `mdi-view-dashboard`, `mdi-sitemap`, `mdi-account-group`, `mdi-handshake`, `mdi-file-document`, `mdi-cash-multiple`, `mdi-school`, `mdi-chat`, `mdi-cog`, `mdi-bell`, `mdi-monitor-dashboard`.

**Действия:**
`mdi-plus`, `mdi-pencil`, `mdi-delete`, `mdi-download`, `mdi-upload`, `mdi-content-copy`, `mdi-magnify`, `mdi-filter-variant`, `mdi-refresh`, `mdi-dots-vertical`, `mdi-send`, `mdi-content-save`, `mdi-open-in-new`, `mdi-eye`, `mdi-eye-off`.

**Статусы:**
`mdi-check-circle`, `mdi-alert-circle`, `mdi-close-circle`, `mdi-information`, `mdi-lock`, `mdi-lock-open`, `mdi-shield-check`, `mdi-shield-alert`, `mdi-circle-outline`, `mdi-circle-slice-4`, `mdi-clock-outline`.

**Контент:**
`mdi-file-pdf-box`, `mdi-image`, `mdi-video`, `mdi-headphones`, `mdi-link`, `mdi-link-variant`, `mdi-presentation`, `mdi-text-box`, `mdi-book-open-variant`, `mdi-folder-outline`.

**Финансы:**
`mdi-bank`, `mdi-cash`, `mdi-cash-multiple`, `mdi-credit-card`, `mdi-currency-usd`, `mdi-receipt`, `mdi-chart-bar`, `mdi-trending-up`, `mdi-trending-down`.

**Профиль:**
`mdi-account-outline`, `mdi-account`, `mdi-account-check`, `mdi-account-multiple`, `mdi-camera`, `mdi-shield-key`, `mdi-key`, `mdi-key-variant`, `mdi-logout`.

---

## 6. Layout-система

### 6.1 MainLayout

Используется для всех аутентифицированных роутов под `/`, кроме `/admin/*`.

**Структура (десктоп ≥1100px):**

```
┌───────────────┬───────────────────────────────────────────────────────┐
│               │  Topbar (64px)                                         │
│   Sidebar     │   [hamburger] [SystemStatusChip] ··· [ref-link]        │
│   (260px)     │   [theme-toggle] [notifications] [user-avatar]         │
│               ├───────────────────────────────────────────────────────┤
│   - DS лого   │                                                        │
│   - Меню      │   Content (pa-4 / pa-md-6)                             │
│   - Группы    │     ┌─────────────────────────────────────────┐        │
│     · Обзор   │     │ PageHeader                              │        │
│     · Работа  │     │  [icon] Title  [chip count]  ··· slot   │        │
│     · Развит. │     └─────────────────────────────────────────┘        │
│     · Связь   │                                                        │
│   - Footer    │   <router-view>                                        │
│     · свернуть│                                                        │
└───────────────┴───────────────────────────────────────────────────────┘
```

**Sidebar:**
- Ширина 260px (раскрыт), 72px (rail/свёрнут).
- `v-navigation-drawer`, `color="surface"`, граница `border-r` (1px outline-variant).
- Header (`sidebar-header pa-4`): «DS» (h6 primary) + «ПЛАТФОРМА» (`label-m`) + caption кабинета (Администратор / Кабинет БЭК / Техподдержка / Руководитель / Фин. менеджер / Расчёты / Правки / Куратор обучения).
- Меню (`v-list density=compact nav`):
  - Группы (`v-list-subheader` UPPERCASE): «Обзор», «Работа», «Развитие», «Связь», «Инструменты», «Данные», «Финансы», «Выплаты», «Обучение», «Прочее», «Помощь», «Аналитика».
  - Items: title + icon + active state (по pathname). Badge unread у `/chat` и `/manage/chat`.
- Footer-список: «Свернуть меню» (mdi-chevron-left/right grey).

**Topbar:**
- Высота 64px.
- `v-app-bar flat border-b` + `backdrop-blur` (полупрозрачный фон при scroll).
- Слева: гамбургер (на мобиле) или ничего.
- В центре справа:
  - `SystemStatusChip` (мигающий dot + label, клик → menu/tooltip с инцидентами).
  - (consultant + canInvite) Кнопка «Реф. ссылка» (`mdi-link-variant tonal primary`, копирует в буфер, текст меняется на «Скопировано» 2 сек).
  - `theme-toggle` (`mdi-weather-sunny` / `mdi-weather-night`).
  - `notifications` (v-menu, `mdi-bell` + badge unread).
  - User avatar (primary в Main, secondary в Admin).
- В menu пользователя:
  - Avatar 56 + ФИО + email.
  - Чип статуса активности + «до {yearPeriodEnd}» + чип «N дн.» если осталось ≤30 (`mdi-timer-outline` warning/error).
  - Links: «Профиль» (`mdi-account-outline`), «Панель управления» (`mdi-shield-crown`, только admin), «Выйти» (`mdi-logout`, base-color=error).
  - Камера-overlay на аватаре для загрузки avatar.

**Content area:**
- `v-main`, `pa-4` (mobile), `pa-md-6` (≥md). Fluid-container.
- На route='/chat' или '/manage/chat' — `.content-main--full-bleed` (без отступов, full-bleed).

**Mobile bottom navigation** (≤700px):
- Высота 56px + safe-area-inset-bottom.
- 5 пунктов:
  - **Consultant:** «Главная / Клиенты / Структура / Продукты / Профиль».
  - **Staff:** «Главная / Партнёры / Отчёты / Ещё (открывает drawer)».
- Backdrop-blur.

**Глобальные компоненты, mounted в layout:**
- `<ConfirmDialog ref=confirmRef>` (provideConfirm).
- `<GlobalSnackbar>`.
- `<GlobalSearch>` (Ctrl+K).
- `<ChatLauncher>` (floating FAB bottom-right).
- `<OnboardingQuestionnaire v-model=showQuestionnaire>` (для consultant'ов без заполненной анкеты).
- `Quick-message dialog «Написать собственику»` (max-width 560, persistent).

### 6.2 AdminLayout

Используется для `/admin/*` (только админ).

**Особенности:**
- **Forced dark theme** на mount, восстановление при unmount.
- Sidebar (`color="grey-darken-4" theme="dark"`), 280px (раскрыт), 72px (rail).
- Кнопка «На сайт» (`mdi-arrow-left grey-lighten-1`) в верху меню.
- Группы (`v-list-group` expandable): «Справочники» с 11 каталогами (productCategory, currency, contractStatus, status, directory_of_activities, type_contest, status_contest, criterion, communicationCategory, title, occupation, meetingType).
- Topbar dark: «DS Управление» (`mdi-shield-crown secondary`).
- User menu (avatar `color="secondary"`): «На сайт» / «Выйти».
- `<ConfirmDialog>` + `<GlobalSnackbar>` mounted.

**Меню sidebar (admin):**
- Дашборд (`mdi-chart-areaspline`)
- Дашборд руководителя (`mdi-crown`)
- Пользователи (`mdi-account-multiple`)
- Партнёры (`mdi-account-search`)
- Клиенты (`mdi-account-group`)
- Новости (`mdi-newspaper`)
- Роадмап (`mdi-map-marker-path`)
- Продукты (`mdi-package-variant-closed`)
- Конкурсы и события (`mdi-trophy`)
- Справочники (`mdi-folder-multiple`, expandable: 11 каталогов)
- Сверка балансов (`mdi-scale-balance`)
- Аномалии (`mdi-alert-decagram`)
- Календарь операций (`mdi-calendar-check`)
- Массовые операции (`mdi-format-list-bulleted-square`)
- Когорты (`mdi-chart-line`)
- Почтовая рассылка (`mdi-email-fast`)
- Триггеры уведомлений (`mdi-robot`)
- Интеграции (`mdi-cloud-sync`)
- API-ключи (`mdi-key-variant`)
- Настройки (`mdi-cog`)
- Мониторинг (`mdi-pulse`)

### 6.3 Breakpoints

| Breakpoint | Условие | Что меняется |
|---|---|---|
| Desktop | ≥1100px | Полная разметка по дизайну. Sidebar 260/280px раскрыт. |
| Tablet | 700-1099px | Rail-сайдбар (72px, только иконки + tooltip при hover). Content расширяется. |
| Mobile | ≤699px | Sidebar → drawer (overlay, slide-in). Bottom-tabbar (5 пунктов). Sticky-CTA в нижний фикс-бар. Таблицы → карточки (см. §11). |
| Mobile-app | Capacitor (iOS/Android wrap) | + safe-area-insets-top/bottom, нативный back-gesture, нативная шапка с bar-color. |

---

## 7. UI-компоненты (полный каталог)

### 7.1 Кнопки (DSButton / v-btn)

**Варианты:**

| Вариант | Класс / Vuetify | Применение |
|---|---|---|
| `filled` | `v-btn variant="flat"` (Vuetify default в DS) | Primary CTA — «Сохранить», «Войти», «Создать» |
| `tonal` | `v-btn variant="tonal"` | Secondary actions, soft CTA, status-chip-like |
| `outlined` | `v-btn variant="outlined"` | Tertiary actions, «Отмена», «Назад» |
| `text` | `v-btn variant="text"` | Inline links, «Сбросить фильтр» |
| `elevated` | `v-btn variant="elevated"` | Floating actions, реже |
| `icon` | `v-btn icon` | Только иконка (close, edit) |

**Размеры:**

| Размер | Высота | Применение |
|---|---|---|
| `x-small` | 24px | Inline-actions в таблице (mdi-download row export) |
| `small` | 32px | Secondary actions в форме |
| `default` | 40px | Default (контент-кнопки) |
| `large` | 48px | Primary CTA в форме («Войти в кабинет») |

**Цвета:** `primary`, `secondary`, `success`, `warning`, `error`, `info`, `surface`, `grey`. По умолчанию — `surface` (или цвет от темы).

**Состояния:**

| Состояние | Как выглядит |
|---|---|
| Idle | По default-стилю варианта |
| Hover | Background усиливается (filled — деpening; tonal/outlined — overlay 4%); cursor: pointer |
| Active | Background усиливается (overlay 8%); slight scale (0.98) |
| Loading | Спиннер 18px в центре кнопки; текст hide или append; pointer-events: none |
| Disabled | `--ds-surface-container-high` фон + `--ds-on-surface-faint` текст; cursor: not-allowed; opacity 0.7 |
| Focus-visible | Box-shadow `--ds-shadow-focus` (3px primary 18%) + offset 2px |

**Skeleton для loading-state кнопки:** `v-skeleton-loader type="button"` (rounded=md, width фикс).

### 7.2 Поля ввода (DSField / v-text-field)

**Базовый стиль:**
- `variant="outlined"` (border 1px outline-variant)
- `rounded="md"` (8px)
- `density="comfortable"` (default 40px высота; см. §3.8)
- Шрифт `body-m` (14/1.5)

**Состояния:**

| Состояние | Стиль |
|---|---|
| Empty | Border `outline-variant`, label сверху или внутри, placeholder muted |
| Filled | Border `outline-variant`, текст `on-surface` |
| Focus | Border `primary` (2px), box-shadow `shadow-focus` |
| Error | Border `error`, hint text `error`, message ниже |
| Disabled | Background `surface-container`, opacity 0.6, cursor not-allowed |
| Readonly | Border `outline-soft`, background неактивный |

**С элементами:**
- `label="Email"` — обычно сверху (form-style).
- `placeholder="example@dsconsult.ru"` — внутри (filter-style).
- `prepend-inner-icon="mdi-email"` — иконка слева внутри.
- `append-inner-icon="mdi-eye-off"` — иконка справа внутри (для toggle).
- `hint="Используется для входа"` + `persistent-hint` — подсказка внизу.
- `:rules` + `error-messages` — валидация (сообщение `body-s error`).

**Типы:**

| Тип | Особенности |
|---|---|
| `type="text"` | Default |
| `type="email"` | `autocomplete=email`, MDI prefix mdi-email |
| `type="tel"` | + `vue-tel-input` (международный формат) |
| `type="password"` | `append-inner` show-toggle (mdi-eye / mdi-eye-off), rules ≥8/буква/цифра |
| `type="date"` | + MDI prefix `mdi-calendar` или `mdi-cake` (для birthday) |
| `type="number"` | `inputmode="numeric"` |

**Specials:**
- **`<vue-tel-input>`** — для телефонов, флаг страны + код.
- **`v-combobox`** / **`v-autocomplete`** — для select-полей с поиском (debounce 200-300ms).
- **`v-textarea`** — `auto-grow`, `rows="3"` минимум, `counter="500"` для длинных полей.

**Validation rules (примеры):**

| Правило | Сообщение |
|---|---|
| Required | «Заполните поле» |
| Email | «Введите корректный email» |
| Phone | «Введите корректный телефон» |
| Cyrillic-required (ФИО) | «Только русские буквы» |
| Min length 8 | «Минимум 8 символов» |
| Password match | «Пароли не совпадают» |
| 6-digit numeric (2FA) | «6 цифр» |

### 7.3 Select / Autocomplete / Combobox

**`v-select`** — для выбора из фиксированного списка (статусы, типы):
- `variant="outlined" density="comfortable"`.
- Dropdown открывается под полем (`v-menu`).
- `multiple` — множественный выбор с чипами.
- `clearable` — `mdi-close` справа.
- `chips closable-chips` — выбранные показываются чипами.

**`v-autocomplete`** — для длинных списков с поиском:
- Debounce 200-300ms.
- Loading-spinner внутри при загрузке.
- `no-data-text="Ничего не найдено"`.

**`v-combobox`** — для свободного ввода + выбор из списка (например, Город):
- Можно ввести текст, которого нет в опциях (создаётся новый).

### 7.4 Chips (DSChip / v-chip)

**Варианты:**

| Variant | Применение |
|---|---|
| `flat` (default) | Без бордера, заливка по color |
| `tonal` | Soft-фон (container) + on-container текст |
| `outlined` | Border + transparent fill |
| `text` | Только текст |

**Размеры:**

| Размер | Высота |
|---|---|
| `x-small` | 20px |
| `small` | 24px |
| `default` | 32px |
| `large` | 40px |

**Спецификация:**
- `rounded="pill"` (999px) — default.
- `prepend-icon="mdi-check"` — иконка слева.
- `closable` + close-icon — для удаляемых чипов.
- `filter` variant: при `value=true` → primary-soft + primary text + primary border.

**Цветовые роли:**
- `color="primary"` — основной акцент (квалификация партнёра).
- `color="secondary"` — бренд-акцент (вы — лидер сети, бренд-теги).
- `color="success"` — Активен, Одобрено, Готово.
- `color="warning"` — На проверке, В процессе, Внимание.
- `color="error"` — Отклонено, Просрочено, Критический.
- `color="info"` — Зарегистрирован, Новый.

**Status-dot chip:** `<v-chip size="small">` с `prepend` — `<v-icon size="8">mdi-circle</v-icon>` цветной точкой.

### 7.5 Cards (DSCard / v-card)

**Варианты:**

| Variant | Применение |
|---|---|
| `flat` (default в DS) | Карточки в форме, в списке |
| `tonal` | Карточки выделенные soft-tone (primary-soft, warning-container) |
| `elevated` | Поднятые (shadow-2) при hover |
| `outlined` | Только border, без shadow (формы) |
| `filled` | Цветной фон (примечание, акцент) |
| `brand` | Mint-фон + brand-ink текст (Лидер сети) |

**Структура:**
```
v-card (rounded=lg, pa-4)
├── v-card-title (title-l, иконка слева, actions справа)
├── v-card-subtitle (body-s muted)
├── v-card-text (контент, body-m)
└── v-card-actions (justify-end, gap-2)
    ├── Cancel-btn (text/outlined)
    └── Confirm-btn (primary)
```

**Hover-lift паттерн (для кликабельных карточек):**
- Default: `shadow-1` (или none).
- Hover: `shadow-3` + `transform: translateY(-2px)` + `transition-duration: 200ms`.
- При `prefers-reduced-motion` — только shadow без translate.

**Border-left паттерн (для статусных карточек):**
- 4px цветной border-left (success/warning/error) сигнализирует о состоянии.
- Применяется в Dashboard partnera (Breakaway, активационный период).

### 7.6 Tables (v-data-table / DataTableWrapper)

**Заголовок таблицы (TH):**
- UPPERCASE
- `font-size: 12px`
- `letter-spacing: 0.4px`
- `color: var(--ds-on-surface-muted)`
- `font-weight: 600`

**Строка (TR):**
- Высота 40px (compact) или 48px (comfortable).
- Hover: background `--ds-overlay` (4%).
- Active (selected): primary-soft.
- Border-bottom 1px outline-variant.

**Ячейки (TD):**
- `body-m` (14/1.5).
- Числа — `.ds-mono` + `tabular-nums` + правое выравнивание.

**Pagination:**
- `v-pagination density="compact"`.
- По 25/50/100/200 items per page (select снизу).
- Показывать общее «N из M».

**Sort:**
- Иконка `mdi-arrow-up` / `mdi-arrow-down` рядом с header.
- Server-side через `v-data-table-server`.

**Filter (внутри таблицы):**
- Top-bar над таблицей: `FilterBar` (см. §7.20).
- Inline-filter в TH (опционально, для админских).

**Skeleton-loader на загрузке:**
- 8 строк с placeholder-block.
- При первой загрузке — full skeleton; при reload — top progress-linear + затемнение body 50%.

**Empty:** `EmptyState` (см. §7.18).

**DataTableWrapper-обёртка** (см. §7.20) — добавляет toolbar, filters-slot, skeleton/empty state.

### 7.7 Badge / Status indicators

**Типы:**

| Тип | Применение |
|---|---|
| **Numeric badge** | `v-badge content="5"` на иконке (notifications) |
| **Dot badge** | `v-badge dot` (минималистичный показатель активности) |
| **Pulse-animated** | Mark критических: `@keyframes pulse` 1s infinite (SystemStatusChip critical) |

**Цвета:** `error` (default для unread), `success` (для онлайн), `warning`, `info`.

**Положение:** `bottom-end`, `top-end`, `top-start`.

### 7.8 Alerts / Banners

**`v-alert type="info|success|warning|error" variant="tonal"`:**

**Структура:**
- Иконка слева (`mdi-information`, `mdi-check-circle`, `mdi-alert`, `mdi-alert-circle`).
- Title (опционально, body-l bold).
- Text (body-m).
- Closable (`closable` prop): `mdi-close` справа.

**Variants:**

| Variant | Стиль |
|---|---|
| `tonal` (default) | Soft-фон (container) + on-container текст |
| `outlined` | Border + transparent fill |
| `flat` | Цветной фон (для важных warnings/errors) |

**Density:** `compact` (Login-error) или default.

**Применение в Auth:**
- `<v-alert type="error" variant="tonal" density="compact">Неверная почта или пароль</v-alert>`.
- При 2FA-шаге — внутри той же формы.

**Применение в Dashboard:**
- Активационный период — type `warning` (≤30 дней) или `info`, `closable`.
- Изменение реквизитов — type `warning` (для verified).

### 7.9 Dialogs / Modals (DialogShell)

**`v-dialog` + `v-card` + структура:**

```
v-dialog (persistent если destructive; max-width: 480/600/720/1000)
├── v-card (rounded=xl)
│   ├── v-card-title (icon + title, sticky-header при scroll)
│   ├── v-card-text (body, max-height с scroll)
│   └── v-card-actions
│       ├── Spacer
│       ├── Cancel (variant=text/outlined)
│       └── Confirm (primary, prepend-icon)
└── scrim (--ds-scrim 0.5)
```

**Размеры:**

| max-width | Применение |
|---|---|
| `400` | Confirm-dialog с короткими действиями |
| `480` | Close-period dialog, простые формы |
| `600` | New-ticket dialog, mid-size form |
| `720` | Edit-user dialog с табами |
| `1000` | Quality-table dialog (полная таблица условий) |

**Persistent:** для destructive (delete, terminate, freeze). Не закрывается по клику вне.

**Loading state в dialog:**
- Кнопка confirm — `loading=true`, текст hide.
- Body — может быть `disabled` overlay.

**Specials:**
- **Setup-flow в табе** (2FA-setup внутри dialog) — 3 шага через `v-stepper`.
- **Image-lightbox** — полноэкранная модалка с zoom (см. ImageLightbox).
- **ConfirmDialog** — через `useConfirm().ask({...})`, единая обёртка над DialogShell.

### 7.10 Progress

**Linear:**
- `v-progress-linear height=8 rounded color=primary` — default.
- Indeterminate (loading-page) — height 3px, sticky top, z-9.
- Determined — value 0-100, color по состоянию (success/primary/warning/error).

**Circular:**
- `v-progress-circular size=24 color=primary indeterminate` — внутри inline (в alert, в кнопке).
- `size=64` — центр страницы при первой загрузке.

**Skeleton:**
- `v-skeleton-loader type="article"` (заглушка для card-content).
- `type="table"` для table.
- `type="card"` для карточки.
- `type="paragraph"` для текстовых блоков.

### 7.11 Avatar (DSAvatar / v-avatar)

**Размеры:**

| Размер | Применение |
|---|---|
| 24-32 (`size="small"`) | В списке (user-row) |
| 40 (`size="default"`) | В topbar |
| 56 (`size="large"`) | В Profile, в user-menu |
| 72 (`size="x-large"`) | В Profile-hero |
| 80+ | В welcome / empty-state |

**Стили:**
- `color="primary"` (default) — primary-фон + on-primary текст.
- `color="secondary"` (admin) — secondary-фон.
- С фото: `:image="user.avatar"`.
- С инициалами (без фото): первая буква имени + фамилии, font-weight 700, white text.
- Status-dot: `v-badge dot color=success location=bottom-end` — онлайн-индикатор.

**Glow** (когда primary): box-shadow 4px primary-soft (только в profile-hero, не везде).

### 7.12 Switch / Checkbox / Radio

**Switch (v-switch):**
- `color="primary"` default.
- Density: `comfortable` (default) / `compact`.
- Label справа.
- Hint снизу (`persistent-hint`).
- При on: track primary, thumb white, slight scale increase (14→18px).

**Checkbox (v-checkbox):**
- `color="primary"`.
- Density `compact` для compact-форм (например, чекбоксы согласий в Register).
- Иконка: `mdi-checkbox-blank-outline` / `mdi-checkbox-marked`.
- Поддержка `indeterminate`.

**Radio (v-radio-group + v-radio):**
- `color="primary"`.
- Inline (`inline=true`) — для коротких вариантов (severity-pick).
- Vertical (default) — для длинных вариантов.

### 7.13 Slider / Range

- `v-slider` color=primary, thumb-label.
- `v-range-slider` — для диапазонов (например, фильтр сумм).
- Применяется редко — основной фильтр-паттерн через `SmartRangeFilter` (два date/number поля «с/по»).

### 7.14 Tabs / Stepper

**Tabs (v-tabs):**
- `color="primary"` для индикатора активного.
- `grow` для full-width распределения.
- Иконка в табе (опционально): `prepend-icon`.
- Application:
  - Profile (4-7 tabs)
  - Currencies admin (Валюты / Курсы / НДС / Срезы)
  - Products admin (Продукты / Программы / Свойства / Срокм / Параметры)
  - Edit-user dialog (Идентичность / Контакты / Квалификация / Реквизиты / Документы)

**Stepper (v-stepper):**
- 2-3 шага для wizard'ов:
  - **Register:** «Ввод данных» / «Проверка» (2 шага).
  - **2FA setup:** «Установите приложение» / «QR + секрет» / «Подтвердите код» (3 шага).
- Иконки шагов: `mdi-check` для выполненных, цифра для текущих/будущих.
- Стиль активного: primary, выполненного — success.

### 7.15 Tooltip / Menu / Snackbar

**Tooltip (v-tooltip):**
- `location="top|bottom|left|right"`.
- Background `--ds-surface-container-highest`, текст `--ds-on-surface`.
- Появление с задержкой 500ms (default).
- На rail-sidebar — обязателен (показывает название пункта меню).

**Menu (v-menu):**
- `location="bottom end"`.
- Backdrop-blur, max-width 360px (для notifications), 220px (для user-menu).
- Содержит `v-list` с пунктами.

**Snackbar (v-snackbar / GlobalSnackbar):**
- `location="bottom right"` (или center на mobile).
- Color по type (success/error/info/warning).
- Timeout 4000ms (default), для error — 6000ms.
- Action-кнопка (опционально): «Открыть» (с router push).
- Z-index `--ds-z-snackbar` (поверх dialog).

### 7.16 BrandWaves

См. §2.3 — параметрический SVG-паттерн. Render: rect/circle background + clip-path + 2 семейства path'ов (horizontal + diagonal).

### 7.17 Специфические компоненты

#### MoneyCell

```
<MoneyCell value="1234567.89" currency="₽" decimals="2" colored signed empty="—" />
```

- **Props:** value, currency (suffix '₽'/'USD'/'%'), decimals, colored (red/green по знаку), signed (+ для positive), empty ('—').
- **Render:** span с `tabular-nums` + formatted число (1 234 567,89) + suffix `medium-emphasis`.
- **Применение:** во всех колонках с деньгами/процентами, KPI-карточках, dashboard'ах.

#### StatusChip

```
<StatusChip value="active" kind="activity" size="small" />
```

- **Props:** value, kind (status|priority|activity|activityName|contract|contest|payment|import|category), color/text override, size, variant (tonal), label, icon.
- **Render:** v-chip с цветом+текстом из карт в `composables/useDesign.js` (statusColors, priorityColors, getActivityColor, и т.д.).
- **Применение:** в табличных строках, в карточках, в фильтрах.

#### BooleanCell

```
<BooleanCell value=true tooltip="Активен / Неактивен" />
```

- **Props:** value, true/false-icon (default: mdi-check-circle / mdi-minus-circle), true/false-color (default: success / grey), size (small), tooltip.
- **Render:** v-icon с цветом+иконкой по value, опц. в v-tooltip.
- **Применение:** для boolean-колонок (isBlocked, active, visible).

### 7.18 EmptyState

```
<EmptyState
  icon="mdi-account-multiple-outline"
  message="Клиентов пока нет"
  hint="Добавьте первого клиента — он появится здесь"
  size=88
  brand
>
  <template #action>
    <v-btn color="primary" prepend-icon="mdi-plus">Добавить клиента</v-btn>
  </template>
</EmptyState>
```

**Структура:**
- Центрированный круг 88×88 с **BrandWaves** (mint background, shape=circle, primary stroke).
- Иконка поверх (44px, on-primary).
- `body-l` message.
- `body-s muted` hint (опционально).
- Slot `action` — CTA-кнопка (опционально).

**Размеры:**
- Default 88px круг.
- Может быть 64px (компакт) или 120px (full-page).

**Применение:**
- Все пустые таблицы.
- Empty filter results.
- Пустые секции (Контракты, Транзакции, Сообщения).

### 7.19 PageHeader

```
<PageHeader title="Партнёры" icon="mdi-account-search" :count="total">
  <template #actions>
    <v-btn color="primary" prepend-icon="mdi-plus">Добавить</v-btn>
  </template>
</PageHeader>
```

**Структура (flex row):**
- Иконка (size 28, color primary) +
- `text-h5` (или `headline-s`) title +
- Чип-count (primary tonal small, формат «· {N}») +
- Spacer +
- Subtitle (опционально, body-s muted) +
- Actions slot (right).

**Применение:** **обязательно** в начале каждой страницы под MainLayout/AdminLayout (кроме Auth и chat).

### 7.20 DataTableWrapper

Обёртка над `v-data-table-server` с встроенным toolbar, filters, skeleton, empty.

**Props:**
- `items`, `headers`, `loading`, `title`, `server-side` (page, items-per-page, items-length).
- `searchable` (search prop), `search-placeholder`.
- `density` (default=compact), `empty-icon`, `empty-message`, `empty-hint`.
- `row-props` (fn), `selectable`, `selected`, `item-value`.

**Slots:** `toolbar`, `filters`, `empty`, любой `#item.{key}`.

**Render:**
- Toolbar (если title или searchable): title + search-input + slot `toolbar`.
- Filters-slot (FilterBar с chips).
- Body: skeleton-loader пока loading и нет items / EmptyState если пусто / v-data-table-server (или v-data-table) с hover + tabular-nums.

### 7.21 FilterBar

Обёртка над v-row для фильтров:
- search-prop (text-field outlined `density=compact` mdi-magnify, debounced 200ms).
- slot для доп. полей (selectов, диапазонов).
- кнопка «Ещё» (mdi-tune, count активных доп. фильтров) — разворачивает v-expand-transition с SmartRangeFilter'ами.
- чип-счётчик активных + кнопка «Сбросить» (mdi-filter-remove secondary).

### 7.22 SystemStatusChip, ChatLauncher, GlobalSearch

**SystemStatusChip:**
- Мигающий status-dot (анимация ping) + label («Работает» / «Замедление» / «Сбой») + caption detail.
- Цвет: success (operational), warning (degraded/maintenance), error (outage).
- Click → menu (для admin) с списком инцидентов и кнопкой «Решён» / `<router-link to=/status>` для остальных.

**ChatLauncher (FAB):**
- Hidden на route `/chat` или `/manage/chat`.
- FAB v-btn icon large (color=primary, error если unread>0). Badge.
- Panel (transition cl-pop): header (mdi-chat-processing + «Мои чаты» + expand + close), body (loading / empty / v-list тикетов), footer («Все чаты» / «Новый»).

**GlobalSearch (Ctrl+K):**
- v-dialog max-width 640 + input autofocus + результаты разнесены по группам (Партнёры, Клиенты, Контракты, Тикеты, Продукты).
- Управление: ↑↓ — навигация, Enter — открыть, Esc — закрыть.

### 7.23 OnboardingQuestionnaire

- v-dialog `persistent` max-width 720.
- Hero (pa-6): аватар `mdi-clipboard-text-outline` + «Добро пожаловать в DS Consulting» + lead + полоса прогресса.
- Body (scrollable, pa-6, max-height 65vh): identity-alert (ФИО+город из профиля) + Q3..QN (разные типы: text, btn-toggle, radio-group, checkboxes).
- Footer: «Сохранить и продолжить» (primary, disabled если не все required).
- Появляется автоматически для consultant'ов без заполненной анкеты.

### 7.24 Дополнительные компоненты

| Компонент | Назначение |
|---|---|
| **DateRangePicker** | Пара date-input «с / по» |
| **SmartRangeFilter** | Расширенный диапазон с типом (date/number/datetime) |
| **ColumnVisibilityMenu** | Меню со списком чекбоксов колонок (persist в localStorage по storage-key) |
| **ActionsCell** | Slot для row-actions (mdi-pencil edit + mdi-delete delete по default + custom) |
| **PersonCell** | Компактное отображение ФИО + код участника + аватар |
| **DialogShell** | Обёртка для v-dialog: title + content + actions (Cancel + Confirm). loading, persistent, max-width, confirm-text/color, icon |
| **ConfirmDialog** | Глобальный confirm через `useConfirm().ask({...})` или v-model |
| **ImageLightbox** | Клик по миниатюре открывает полноэкранную модалку (zoom, swipe) |
| **FormErrors** | Отображение ошибок validation от бэка (списком или общим message) |
| **PhoneInput** | Обёртка над vue-tel-input (флаг страны + код) |
| **RichTextEditor** | TipTap или аналогичный WYSIWYG (News, Mail, Contests, Lessons) |
| **Breadcrumbs** | Обёртка над v-breadcrumbs |
| **MyTasksWidget** | Личный TODO-чек-лист (add/toggle/delete) |
| **MyNoteWidget** | Авто-сохраняемая заметка |
| **MyDayWidget** | Статистика staff за сегодня |
| **WhosOnlineWidget** | Список коллег онлайн (last_seen_at в пределах 5 мин) |
| **ImportProgressDialog** | Прогресс джобы импорта |
| **SystemStatusBanner** | Top-of-page banner при degradation |
| **StartChatButton** | Icon-btn с tooltip → создаёт тикет и редиректит в чат |
| **MonthPicker** | Picker месяца (chevron-left + label + chevron-right + popover-grid 3×4) |

---

## 8. Паттерны экранов

### 8.1 Список-страница (паттерн «реестр»)

**Структура (сверху вниз):**

```
PageHeader (title + icon + count + #actions)
  └─ Actions: «Добавить» (primary mdi-plus), «Экспорт» (outlined mdi-download), «Импорт» (outlined mdi-upload)

FilterBar (mb-3 pa-3 card)
  ├─ Search (mdi-magnify, debounced, placeholder)
  ├─ Select-фильтры (multiple, clearable)
  ├─ «Ещё» toggle (mdi-tune + count) → SmartRangeFilter'ы
  ├─ Чип-счётчик «{N} фильтр(ов)»
  ├─ «Сбросить» (text mdi-filter-remove secondary)
  └─ ColumnVisibilityMenu (mdi-eye-settings)

DataTableWrapper (server-side)
  ├─ Skeleton при первой загрузке
  ├─ Body — v-data-table-server density=compact hover
  ├─ Empty → EmptyState
  └─ Pagination (25/50/100/200)
```

**Применяется на:** Clients, MyContracts, TeamContracts, ContractManager, Partners, PartnerStatuses, Acceptance, Requisites, Transactions, Commissions, Pool, Qualifications, Charges, Users, Permissions, Reports, HomeworkQueue, PartnerQuestionnaires, TechSupportDesk.

### 8.2 Форма-страница (паттерн «карточка с полями»)

**Структура:**

```
PageHeader (title + icon)

v-card (pa-4)
  ├─ Section title (title-l)
  ├─ v-row dense (4-column grid на desktop, 12 на mobile)
  │   ├─ v-text-field (Фамилия)
  │   ├─ v-text-field (Имя)
  │   └─ ...
  ├─ v-divider (mb-4)
  ├─ Section 2 — Дополнительные блоки
  └─ Actions (bottom, justify-end)
      ├─ «Отмена» (outlined)
      └─ «Сохранить» (primary mdi-content-save)
```

**Применяется на:** Profile (info-tab), Edit-User dialog, Edit-Partner dialog, EducationConstructor (lesson-editor).

### 8.3 Дашборд (паттерн «KPI + графики»)

**Структура:**

```
PageHeader (title + icon + #actions с MonthPicker)

Active-period alert (если есть)

KPI grid (.ds-kpi, 6/4/3/2 responsive)
  └─ Tonal-карточки: icon + value (h5 mono) + label (caption) + delta (mdi-trending-up + %)

Hero-card (если есть) — крупный блок с акцентом

Volume cards (2 col v-row) — ЛП / НГП с trending-индикаторами

Breakaway/Special-card (border-left 4px, цветной)

Charts row (Line + Doughnut + Bar) — Chart.js, height 280

Tables (списки топ-N)
```

**Применяется на:** Workspace, Dashboard, Admin Dashboard, OwnerDashboard, EducationAnalytics, Chat Analytics.

### 8.4 Wizard / Stepper

**Структура:**

```
Stepper (2-3 steps)
  ├─ Step 1: «Название шага»
  ├─ Step 2: ...
  └─ Step 3: Final

Step content (v-card pa-6)
  └─ Поля шага

Actions (justify-end + justify-between для последнего шага)
  ├─ «Назад» (outlined, disabled на step 1)
  └─ «Далее →» / «Завершить» (primary block, disabled пока invalid)
```

**Применяется на:** Register (2 шага), 2FA setup (3 шага), EducationTest (Q1..QN с прогрессом).

### 8.5 Settings-страница

**Структура:**

```
Sidebar nav (12/3) — vertical list с разделами
  └─ Active item: primary-soft фон + primary текст

Content (12/9)
  └─ Карточки разделов (Личные данные / Документы / Реквизиты / Безопасность / Уведомления / Telegram / Реферал)
```

**Применяется на:** Profile, Admin Settings.

### 8.6 Chat (3 столбца)

**Структура (full-bleed):**

```
┌─────────────┬───────────────────────────┬─────────────┐
│             │ Topbar (ticket header)    │             │
│  Sidebar    ├───────────────────────────┤   Context   │
│  (320-380)  │                           │   panel     │
│             │   Messages (scroll)       │   (310)     │
│  - search   │   ├─ own (right primary)  │   - client  │
│  - filters  │   └─ other (left surface) │   - related │
│  - list     │                           │   - quick   │
│             ├───────────────────────────┤             │
│             │  Input (textarea + send)  │             │
└─────────────┴───────────────────────────┴─────────────┘
```

**Особенности:**
- Mobile — sliding panels (только одна активна).
- Connection banner при потере WS.
- Conn-banner: warning-fill + mdi-wifi-off + текст.

---

## 9. Состояния

### 9.1 Loading

**Виды:**

| Тип | Применение |
|---|---|
| **Top progress-linear** | Page-level reload (z=9, height 3px, sticky top, primary indeterminate) |
| **Skeleton-loader** | Для контента с известной структурой (table-rows, card-grid, paragraphs) |
| **Spinner внутри button** | Для action-loading (показать прогресс отправки) |
| **Spinner-circular** | Центр страницы при первой загрузке без skeleton |
| **Inline progress** | В alert при процессе (linking telegram, polling status) |

**Правило:** не использовать full-page overlay + spinner (раздражает). Только skeleton + top-progress.

### 9.2 Empty state

См. §7.18.

**Универсальный паттерн:**
- BrandWaves circle 88px + иконка (mdi-{contextual}-outline) поверх.
- Заголовок (`body-l`, главная мысль) — «Партнёров пока нет», «Ничего не найдено».
- Hint (`body-s muted`) — что сделать, чтобы заполнить.
- CTA (опционально) — «Добавить первого партнёра».

**Контексты:**
- Empty filter results — «Ничего не найдено», hint «Попробуйте изменить параметры поиска».
- Empty table (no data) — «Записей пока нет», hint + CTA «Добавить».
- Empty search — «По запросу «{query}» ничего не найдено».
- Empty section (на dashboard) — `v-card-text text-center pa-4 medium-emphasis` (без иллюстрации).

### 9.3 Error

**Виды:**

| Тип | Применение |
|---|---|
| **Inline v-alert error tonal** | Validation-error в форме (под полем или над actions) |
| **Page-level 403/404** | Полноэкранная карточка с status-кодом, message, action |
| **Snackbar error** | Transient (после неудачной отправки формы) |
| **Field-level error** | Под полем формы (red text body-s) |

**Page-level 404 (NotFound):**
- Центрированная v-card variant=tonal max-width 440.
- mdi-compass-off (size 56, warning).
- H5 «Страница не найдена».
- Lead «Адрес «{path}» не существует или был удалён.»
- Кнопка «На главную» (primary mdi-home).

**Page-level 403 (Forbidden):**
- Аналогично, но mdi-lock-outline (size 56, error).
- H5 «Доступ запрещён».
- Lead «У вашей роли нет прав на просмотр этого раздела.»
- Кнопка «На главную» (primary mdi-home).

**Connection-error в чате:**
- conn-banner warning-fill (sticky top): mdi-wifi-off + «Соединение потеряно. Сообщения придут с задержкой ~15 сек».

### 9.4 Success

**Виды:**

| Тип | Применение |
|---|---|
| **Snackbar success** | «Сохранено», «Скопировано» (короткие) |
| **Confetti / scale-in** | После прохождения теста (опц., respect prefers-reduced-motion) |
| **Inline confirmation** | После save — alert success «Данные сохранены» |
| **Page-level success** | После завершения регистрации, прохождения теста |

**EducationTest — success:**
- mdi-check-circle (size 64, success).
- H5 «Тест сдан!».
- Lead «Доступ к продаже продукта {courseTitle} открыт».
- Buttons: «К курсу» (primary), «К продукту» (outlined).

**Анимация (только если NO `prefers-reduced-motion`):**
- Scale-in (scale 0.9 → 1, opacity 0 → 1, duration 320ms).
- Конфетти — лёгкий particle-burst.

### 9.5 Permission denied

**Page-level 403** — см. §9.3.

**Inline (для секций внутри страницы):**
- `v-card variant="outlined"` + lock-overlay (центр):
  - mdi-lock (size 40, on-surface-muted).
  - «Раздел доступен только {роль}».
  - Опц. кнопка «Запросить доступ».

---

## 10. Все экраны (~95)

> Полные описания каждого экрана — в `desing/SCREENS_SPEC.md`. Ниже — сжатый список с URL, файлом, layout и кратким описанием.

### 10.1 Auth (2 экрана)

| URL | Файл | Layout | Краткое описание |
|---|---|---|---|
| `/login` | `Auth/Login.vue` | Split-screen (свой) | Hero (gradient + BrandWaves) + форма (Email, Password, Войти, 2FA-шаг, Telegram-disabled) |
| `/register?ref=<code>` | `Auth/Register.vue` | Centered animated (свой) | Дбобольно фон + центрированная карточка + 2-step stepper (форма + проверка) |

**Артборд:** `ds-screens-auth-partner.jsx`.

### 10.2 Partner cabinet (~22 экрана)

| URL | Файл | Описание |
|---|---|---|
| `/` | `Workspace.vue` | Рабочий стол: приветствие + KPI «Мои показатели» + наставник + новости + активность команды + правая колонка с виджетами |
| `/dashboard` | `Dashboard.vue` | Активационный период + Квалификация (с условиями) + ЛП/НГП volume cards + Breakaway + 4 KPI partner-counts + 2 client-cards + 4 status-counts + диалог квалификаций |
| `/structure` | `Structure.vue` | Иерархия команды (раскрываемое дерево с children-загрузкой) + фильтры + расширенные диапазоны (5×SmartRangeFilter) + export строки |
| `/profile` | `Profile.vue` | 4-7 табов (Личные данные / Документы / Реквизиты / Безопасность / Уведомления / Telegram / Реферал). Hero с avatar+name+status-chips |
| `/education` | `Education.vue` | «Продолжите...» card + промо БЗ + сетка курсов (с lock-state по requiredCourses) |
| `/education/courses/:id` | `EducationCourse.vue` | Дерево курса слева + контент справа (hero + прогресс + структура модулей / уроков) |
| `/education/courses/:id/lessons/:lid` | `EducationLesson.vue` | Sticky-header (название урока + кнопка «Урок изучен») + блоки контента (LessonBlockRenderer) |
| `/education/courses/:id/test` | `EducationTest.vue` | 3 состояния: идёт (вопрос+радио+прогресс) / провал / успех |
| `/education/kb` | `EducationKb.vue` | Сетка карточек разделов БЗ + search |
| `/education/kb/sections/:id` | `EducationKbSection.vue` | Список статей в разделе |
| `/education/kb/articles/:id` | `EducationKbArticle.vue` | Просмотр статьи (LessonBlockRenderer) |
| `/clients` | `Clients/ClientList.vue` | Реестр клиентов (FilterBar + DataTable) |
| `/contracts` | `Contracts/MyContracts.vue` | Контракты партнёра (FilterBar + DataTable) |
| `/contracts/team` | `Contracts/TeamContracts.vue` | Контракты команды (FilterBar + DataTable) |
| `/finance/report` | `Finance/Report.vue` | Locked-state alert или: Квалификация+Объёмы row + Sales totals + комиссии/бонусы/удержания |
| `/finance/calculator` | `Finance/Calculator.vue` | Форма (квалификация → продукт → программа → ...) + результат |
| `/products` | `Products.vue` | Сетка карточек продуктов с lock-state по requiredCourses |
| `/insmart-widget` | `InsmartWidget.vue` | Встроенный iframe-виджет страховых продуктов |
| `/contests` | `Contests.vue` | Сетка конкурсов (скрыт в меню, путь работает) |
| `/communication` | `Communication.vue` | Legacy (redirect → /chat). Сообщения от DS, dialog «Написать» |
| `/instructions` | `Instructions.vue` | Двухколонный (категории + список) + drawer статьи с TOC и embed-видео |
| `/help` | `Help.vue` | Stub «Раздел в разработке» |
| `/referrals` | `Referrals.vue` | Stub (реальное — в Profile-tab) |
| `/terminated` | `Terminated.vue` | Полноэкранная error-card с инструкциями и контактами |
| `/status` | `SystemStatus.vue` | Overall banner + список компонентов + активные инциденты + история |
| `/forbidden` | `Forbidden.vue` | 403 page-level |
| `/not-found` | `NotFound.vue` | 404 page-level |

**Артборды:** `ds-screens-auth-partner.jsx` (Dashboard + Clients + Contracts + Products), `ds-extra-partner.jsx` (8 остальных), `ds-missing-partner.jsx` (доп.).

### 10.3 Manager / staff cabinet (~30 экранов)

Все экраны живут в `/manage/*` под MainLayout. Доступ — `meta.staff: true`.

| URL | Файл | Описание |
|---|---|---|
| `/manage/workspace` | `Admin/Workspace.vue` | Counter row 6 tiles + task feed (TaskCard пары) |
| `/manage/periods` | `Admin/Periods.vue` | DataTable периодов + кнопки доступности/закрытия + диалог закрытия |
| `/manage/periods/:ym` | `Admin/PeriodCard.vue` | Status banner + 3 секции (Штрафы / Пул / Реестр выплат) с Preview/Apply кнопками |
| `/manage/contracts` | `Admin/ContractManager.vue` | FilterBar + DataTable с inline-edit + CRUD-диалоги |
| `/manage/contracts/upload` | `Admin/ContractUpload.vue` | Форма загрузки CSV/XLSX + лог обработки |
| `/manage/partners` | `Admin/Partners.vue` | Реестр (FilterBar + Table) + Edit dialog с табами + actions impersonate/edit/chat/delete |
| `/manage/partners/statuses` | `Admin/PartnerStatuses.vue` | Массовая смена статуса + diалог комментария |
| `/manage/clients` | `Admin/Clients.vue` | Аналог Partners для клиентов |
| `/manage/acceptance` | `Admin/Acceptance.vue` | Список заявок на верификацию документов + кнопки Одобрить/Отклонить |
| `/manage/requisites` | `Admin/Requisites.vue` | Реквизиты партнёров + side-drawer/dialog с полной формой |
| `/manage/transfers` | `Admin/Transfers.vue` | История перестановок (from-mentor → to-mentor) |
| `/manage/permissions` | `Admin/Permissions.vue` (admin-only) | Матрица групп × разделов × уровней |
| `/manage/instructions` | `Admin/Instructions.vue` | CRUD инструкций с RichTextEditor |
| `/manage/transactions/import` | `Admin/TransactionImport.vue` | Drag-zone CSV + mapping + preview + status job |
| `/manage/transactions` | `Admin/Transactions.vue` | Мощная FilterBar + Table + detail-dialog |
| `/manage/commissions` | `Admin/Commissions.vue` | FilterBar + Table комиссий |
| `/manage/pool` | `Admin/Pool.vue` | Распределение пула по уровням + diagram |
| `/manage/qualifications` | `Admin/Qualifications.vue` | FilterBar + Table квалификаций + правка с reason |
| `/manage/charges` | `Admin/Charges.vue` | Прочие начисления + Create dialog |
| `/manage/payments` | `Admin/PaymentRegistry.vue` | Канбан выплат (К выплате → На выплате → Выплачено → Возврат) |
| `/manage/reports` | `Admin/Reports.vue` | Грид карточек отчётов с кнопками генерации |
| `/manage/currencies` | `Admin/Currencies.vue` | Табы: Валюты / Курсы / НДС / Срезы |
| `/manage/products` | `Admin/Products.vue` | Табы: Продукты / Программы / Свойства / Сроки / Параметры |
| `/manage/products-preview` | `Admin/ProductsPreview.vue` | Превью партнёрской витрины с фильтром «From role» |
| `/manage/contests` | `Admin/Contests.vue` | DataTable конкурсов + CRUD-dialog с RichTextEditor и multi-select победителей |
| `/manage/education` | `Admin/EducationConstructor.vue` | Двухпанельный конструктор курсов (дерево DnD + редактор lesson/test) |
| `/manage/education-legacy` | `Admin/Education.vue` | Legacy fallback |
| `/manage/education/categories` | `Admin/EducationCategories.vue` | CRUD категорий с icon-picker |
| `/manage/education/analytics` | `Admin/EducationAnalytics.vue` | KPI tiles + bar-chart + top-N студентов |
| `/manage/kb` | `Admin/KbConstructor.vue` | Двухпанельный конструктор БЗ |
| `/manage/homework` | `Admin/HomeworkQueue.vue` | Очередь домашек с Одобрить/Отклонить и drawer-preview |
| `/manage/partner-questionnaires` | `Admin/PartnerQuestionnaires.vue` | Просмотр заполненных анкет + drawer с ответами |
| `/manage/support` | `Manage/TechSupportDesk.vue` (admin) | KPI 4 tiles + filter pills + tickets table + incident dialog |

**Артборды:** `ds-screens-manager-admin.jsx` (Manager Workspace), `ds-extra-manager.jsx` (17 экранов), `ds-missing-manager.jsx` (доп.).

### 10.4 Admin cabinet (~25 экранов)

Все экраны под `AdminLayout` (forced dark theme, secondary-accent). Доступ — `meta.admin: true`.

| URL | Файл | Описание |
|---|---|---|
| `/admin/dashboard` | `Admin/Dashboard.vue` | KPI cards 6×2 + 3 charts row (Line/Doughnut/Bar/Funnel/Quality distribution) + Топ-10 + тикеты |
| `/admin/owner-dashboard` | `Admin/OwnerDashboard.vue` | 4 KPI tonal + горизонтальные бары Выручка по месяцам + Топ-10 ГП + Воронка + Аномалии |
| `/admin/funnel` | `Admin/Funnel.vue` | Legacy (redirect → OwnerDashboard) |
| `/admin/reconciliation` | `Admin/Reconciliation.vue` | Месячный селектор + таблица расхождений Snapshot vs Live |
| `/admin/anomalies` | `Admin/Anomalies.vue` | Список аномалий с severity + кнопки Комментировать/Закрыть |
| `/admin/cohorts` | `Admin/Cohorts.vue` | Heatmap-таблица retention'а по когортам |
| `/admin/calendar` | `Admin/OpsCalendar.vue` | Месячный календарь с маркерами операций |
| `/admin/bulk-ops` | `Admin/BulkOps.vue` | Сетка карточек операций + confirm-диалог + лог |
| `/admin/users` | `Admin/Users.vue` | FilterBar + Table пользователей + Edit dialog |
| `/admin/news` | `Admin/News.vue` | DataTable новостей + Edit dialog (RichTextEditor) |
| `/admin/roadmap` | `Admin/Roadmap.vue` | Канбан-доска (Идея → В работе → Готовится → Выпущено) с DnD |
| `/admin/mail` | `Admin/Mail.vue` | Двухпанельный (редактор кампании + preview/история) |
| `/admin/triggers` | `Admin/Triggers.vue` | Список триггеров event+condition+action + toggle + test |
| `/admin/integrations` | `Admin/Integrations.vue` | Сетка карточек интеграций (InSmart, Telegram, SMTP, AmoCRM) |
| `/admin/api-keys` | `Admin/ApiKeys.vue` | Список API-ключей + кнопки Сгенерировать/Отозвать + single-show-dialog |
| `/admin/settings` | `Admin/Settings.vue` | Stub базовых системных настроек |
| `/admin/monitoring` | `Admin/Monitoring.vue` | Real-time-карточки нагрузки (PHP-FPM, queue, DB, Redis, socket) + графики ошибок |
| `/manage/system-status` | `Admin/SystemStatus.vue` | CRUD компонентов + инцидентов + апдейтов |
| `/admin/references` | `Admin/References.vue` | Грид справочников (11 каталогов) |
| `/admin/references/:catalog` | `Admin/ReferenceDetail.vue` | Универсальный CRUD записи (name/code/color/order) |

**Артборды:** `ds-screens-manager-admin.jsx` (Admin Home), `ds-extra-admin.jsx` (18 экранов).

### 10.5 Chat (3)

| URL | Файл | Описание |
|---|---|---|
| `/chat` | `Chat/PartnerChat.vue` (1456 строк) | Двухпанельный, partners видят свои тикеты + категории + статусы + new-ticket dialog |
| `/manage/chat` | `Chat/StaffChat.vue` (3150 строк) | PartnerChat + view-toggle (List/Kanban) + bulk-mode + context-panel + smart views + assign |
| `/manage/chat/analytics` | `Chat/Analytics.vue` (426 строк) | Period chips + 4 summary cards + status counters + grids (categories/priority) + динамика + топ операторов + handover dialog |

**Артборды:** в общем JSX.

### 10.6 Layouts & Shared

| Компонент | Файл | Назначение |
|---|---|---|
| MainLayout | `layouts/MainLayout.vue` (957 строк) | Sidebar + Topbar + Content + Mobile bottom nav |
| AdminLayout | `layouts/AdminLayout.vue` (203 строки) | Forced dark + 280px sidebar с группами |
| 20+ shared components | `components/*.vue` | См. §7.17-7.24 |

---

## 11. Адаптив

### 11.1 Breakpoints (Vuetify-стандарт)

| Breakpoint | Условие | Цель |
|---|---|---|
| xs | <600px | Mobile portrait |
| sm | 600-959 | Mobile landscape, маленький tablet |
| md | 960-1263 | Tablet, маленький desktop |
| lg | 1264-1903 | Desktop |
| xl | ≥1904 | Big desktop |

### 11.2 Mobile (≤700px) — что меняется

**Layout:**
- Sidebar → `v-navigation-drawer temporary` (overlay slide-in).
- Гамбургер слева в topbar.
- Bottom-nav: 5 пунктов с safe-area-inset.
- Sticky CTA в нижний фикс-бар (для основных action'ов на странице).

**Таблицы:**
- Превращаются в карточки (card-per-row).
- Каждая карточка — vertical layout: иконка + ФИО (title-l) + детали (body-s) + actions (icon-row).
- Скрываются второстепенные колонки (показываем только key fields).

**Фильтры:**
- Search — на всю ширину.
- Селекты — full-width, по одной колонке.
- «Ещё» — bottom-sheet (full-screen drawer) с фильтрами и кнопками «Применить» / «Сбросить».

**Формы:**
- Все поля — 12/12 (1 колонка).
- Кнопки — `block=true` (full-width).
- Stepper — без backgrounds, минималистично.

**Chat:**
- Sliding panels: открыт чат — sidebar схлопывается.
- Кнопка «Назад к списку» в header'е сообщения.

### 11.3 Tablet (700-1100px) — что меняется

- **Rail-sidebar** (72px, только иконки). Tooltip при hover на пункт меню.
- **Content** расширяется.
- **Bottom-nav** — нет (хватает rail-sidebar).
- Таблицы — comfortable density (40px row).
- Фильтры — `v-row` с 2-3 колонками.

### 11.4 Desktop (≥1100px) — full layout

- **Раскрытый sidebar** 260/280px.
- Полная разметка по дизайну (см. §6).

### 11.5 Mobile-app (Capacitor)

- + safe-area-insets-top/bottom.
- Нативный back-gesture (iOS swipe-back).
- Нативная шапка с bar-color (matches theme).
- Push-уведомления (notification permission на старте).

### 11.6 Универсальные правила

- Минимальная ширина touch-target — 44×44 (recommended).
- Минимальная ширина text-input — 44px высота.
- Spacing на mobile — `pa-4` (16px), на desktop — `pa-md-6` (24px).
- Inputs на mobile — большее `density="default"`, чтобы не были крошечными.

---

## 12. Доступность

### 12.1 Контраст (WCAG AA)

**Что проверять:**
- Текст on-surface на surface — ≥4.5:1 (`#1A1F1B` на `#FFFFFF` — OK).
- Primary `#2E7D32` на белом — `5.92:1` — OK (WCAG AA для text).
- Secondary `#6EE87A` на белом — `1.69:1` — **fail для текста**. Используем только как **декор** или background с brand-ink `#0A2B10` text.
- Status-цвета:
  - Error `#C62828` на `#FFFFFF` — `6.78:1` — OK.
  - Warning `#ED6C02` на `#FFFFFF` — `3.97:1` — **OK только для large text** (≥18px).
  - Info `#0277BD` на `#FFFFFF` — `5.61:1` — OK.
  - Success = primary — OK.
- На dark:
  - Primary `#6EE87A` на `#0F1311` — `12.04:1` — OK.
  - On-surface `#E2E4E2` на `#161A17` — `11.27:1` — OK.

**Чипы и кнопки:** всегда используем container/on-container пары, они дизайнерски сбалансированы.

### 12.2 Focus-visible

- Все интерактивные элементы (button, input, link, chip-filter) при keyboard-focus получают `box-shadow: var(--ds-shadow-focus)` (3px primary 18% + offset 2px).
- На primary-кнопке (тёмно-зелёный фон) — focus-ring сделан 3px secondary (mint) для контраста.
- В Vuetify это работает автоматически через `:focus-visible`.

### 12.3 `prefers-reduced-motion`

Отключается при `prefers-reduced-motion: reduce`:
- Confetti / particle-burst (success).
- Scale-in / scale-out анимации.
- Hover-translate (lift) на карточках.
- Blob-drift на Register-фоне.
- BrandWaves «дышащая» анимация (если есть).
- Любые анимации длиннее 200ms.

Остаются:
- Мгновенные fade in/out.
- Открытие меню/dialog (smooth, но <200ms).
- Smooth scroll (если включено).

### 12.4 Семантика

**Кнопка vs ссылка:**
- `<v-btn>` для actions (submit, save, delete, navigate-to-action).
- `<a>` или `<router-link>` для navigation (внутренние ссылки, breadcrumbs).
- Никогда `<a>` с `onclick="action"` — это ломает keyboard.

**Aria-labels:**
- Icon-only buttons → `aria-label="Удалить"`.
- Icon-индикаторы статусов → `aria-label="Активен"`.
- Декоративные иконки → `aria-hidden="true"`.

**Required indicators:**
- В label-форме: `*` (астериск) рядом с label, цвет `--ds-error`, `aria-required="true"`.

**Состояния:**
- `aria-invalid="true"` для error-полей.
- `aria-busy="true"` для loading-состояний.
- `aria-pressed` для toggle-кнопок.

**Heading hierarchy:** не пропускать уровни. H1 → H2 → H3 (а не H1 → H4).

**Skip-link:** «Перейти к контенту» (для keyboard-users, в начале страницы).

### 12.5 Keyboard navigation

- **Tab** — следующее интерактивное.
- **Shift+Tab** — предыдущее.
- **Enter / Space** — активировать кнопку, выбрать.
- **Esc** — закрыть dialog / menu / clear input.
- **Ctrl+K** — глобальный поиск.
- **Arrow ↑↓** — навигация в выпадающих списках.
- **Arrow ←→** — навигация в табах.

---

## 13. Тон голоса и microcopy

### 13.1 Обращение

**На «Вы»** (всегда со строчной в UI, с прописной — только в личной переписке с клиентом).

Примеры:
- «Заполните обязательные поля» (а не «Ты должен заполнить»).
- «Вы вошли как админ» (в profile-menu).
- «Вас пригласил партнёр Иван Иванов» (в реф-баннере).

### 13.2 Кнопки

**Глагол + объект (повелительное наклонение):**
- «Сохранить» / «Сохранить реквизиты» / «Сохранить и продолжить».
- «Отменить» (НЕ «Отмена» — глагол сильнее).
- «Закрыть» / «Закрыть период».
- «Удалить» / «Удалить контракт».
- «Войти в кабинет» / «Войти через Telegram».
- «Создать тикет» / «Создать партнёра».
- «Подтвердить» / «Подтвердить и включить».
- «Применить» (penalties) / «Сделать доступным».

**Ссылки (текстовые actions):**
- «Подробнее» (НЕ «читать дальше»).
- «Ещё» (для filter-toggle).
- «Сбросить» (НЕ «очистить»).

### 13.3 Ошибки (дружелюбно, без обвинений)

**Шаблон:** «Что произошло» + «Что делать (опционально)».

- **Network:** «Не удалось загрузить. Проверьте подключение и попробуйте ещё раз.»
- **Auth:** «Неверная почта или пароль» (НЕ «Ошибка входа 401»).
- **Validation:** «Заполните обязательные поля» (без указания, что именно — поля сами выделены).
- **Conflict:** «Этот email уже зарегистрирован. Войдите или восстановите пароль.»
- **Forbidden:** «У вашей роли нет прав на это действие.»
- **Server:** «Что-то пошло не так на нашей стороне. Мы уже разбираемся.» (general 500).
- **Validation per field:**
  - Email: «Введите корректный email» (не «invalid format»).
  - Phone: «Введите корректный телефон».
  - Password: «Минимум 8 символов, хотя бы одна буква и цифра».
  - Passwords match: «Пароли не совпадают».

### 13.4 Пустые состояния

**Шаблон:** «Чего нет» + «Что сделать».

- «Клиентов пока нет. Добавьте первого — он появится здесь.»
- «По запросу «{query}» ничего не найдено. Попробуйте изменить параметры.»
- «Новостей пока нет. Здесь будут появляться важные объявления.»
- «Конкурсов и событий пока нет.»
- «База знаний пока пуста. Скоро здесь появятся регламенты и инструкции.»

### 13.5 Confirmations

**Шаблон:** «Точно сделать X?» + «Что произойдёт после».

- «Точно удалить контракт #DS-12345? Это действие нельзя отменить.»
- «Закрыть период? После закрытия отчёты не смогут быть изменены.»
- «Терминировать партнёра? Доступ к кабинету будет закрыт.»
- «Изменение реквизитов сбросит статус верификации. Продолжить?» (warning, не destructive).
- «Отвязать Telegram? Уведомления перестанут приходить.»

**Destructive actions** — всегда `persistent=true` на dialog, кнопка confirm — `color="error"`.

### 13.6 Микро-копии (eyebrow / caption)

**Eyebrow (uppercase label-m):**
- «ТЕКУЩАЯ КВАЛИФИКАЦИЯ»
- «ПРОДОЛЖИТЕ С ТОГО МЕСТА»
- «ВХОД В КАБИНЕТ»
- «КОНСАЛТИНГ ПЛАТФОРМА»

**Caption (body-s muted):**
- «Скопировано в буфер обмена» (2 сек после copy)
- «Отправлено на проверку»
- «Обновлено 2 часа назад»
- «осталось 14 дней»

**Hints под полями:**
- «Используется для входа в кабинет.»
- «Изменение возможно только через техподдержку.»
- «Минимум 8 символов, хотя бы одна буква и цифра.»
- «Ссылка действует 15 минут.»

### 13.7 Lead text (под H1)

- Auth Login: «Войдите, чтобы продолжить работу с клиентами и контрактами.»
- Auth Register: «Регистрация открыта только по реферальной ссылке активного партнёра.»
- Education: «Курсы DS Consulting — изучите продукты и получите доступ к продаже.»
- Profile: «Личные данные, документы и безопасность.»

### 13.8 Loading-копии

- «Загружаем профиль…»
- «Сохраняем…»
- «Проверяем реферальную ссылку…»
- «Создаём тикет…»
- «Ожидаем подтверждения в Telegram…»

### 13.9 Success-копии

**Snackbar:**
- «Сохранено»
- «Скопировано»
- «Отправлено»
- «Удалено»
- «Применено»

**Inline alert (длиннее):**
- «Данные обновлены. Изменения вступят в силу немедленно.»
- «Документ загружен. Он отправлен на проверку.»
- «Реквизиты сохранены. Они доступны бэк-офису для верификации.»

### 13.10 Status-лейблы

**Активность партнёра:**
- «Зарегистрирован» (info)
- «Активен» (success)
- «Терминирован» (error)
- «Исключён» (error)

**Контракт:**
- «Открыт» (info)
- «Заведён» (warning)
- «На отзыве» (warning)
- «Закрыт» (success)

**Тикет:**
- «Открыт» / «В работе» / «Ожидание» / «Решён» / «Закрыт»

**Документ / реквизиты:**
- «На проверке» (warning)
- «Одобрено» (success)
- «Отклонено» (error)

**Период:**
- «Период открыт» / «Период закрыт» / «Заморожен»

### 13.11 Денежные форматы

- **Рубли:** `1 234 567,89 ₽` (пробел-разделитель, запятая-десятичный, суффикс `₽`).
- **USD/EUR:** `1,234.56 USD` (англо-формат, suffix буквенный).
- **Проценты:** `15,5 %` (запятая-десятичный, пробел + `%`).
- **Знак:** + для положительных только в дельта-показателях (delta в KPI), для otherwise — без знака.
- **Цвет:** зелёный для +, красный для −, base для neutral.

---

## 14. Что НЕ покрыто в этом ТЗ

**LMS-модуль (внутри Education):**
- Конкретные блоки lesson-content (text/video/image/document/quiz) — нужны отдельные макеты внутри блочного редактора.
- A4-сертификат об окончании курса — отдельный PDF-шаблон, не в этом ТЗ.

**Микро-флоу в Chat:**
- CSAT-форма (после resolved-ticket) — модалка с 5-звёздочной оценкой и textarea «Что улучшить?».
- Quick-replies editor — `/manage/chat/quick-replies` (CRUD-табличка).
- Internal-notes UI внутри сообщения — не описано отдельно.

**Иллюстрации:**
- В DS используем только BrandWaves + MDI-иконки. Если потребуются полноценные иллюстрации (Empty-state с человечком, Welcome-screen с landscape) — отдельный пакет.

**Микро-анимации:**
- Только спецификация motion-токенов (§3.7). Конкретные параметры animations (где, как, какие свойства) — на усмотрение разработчика, в рамках токенов.

**Прототипы взаимодействия:**
- Этот документ статичен. Прототипы (Figma click-throughs) — отдельный артефакт.

**Mobile-варианты для всех 95 экранов:**
- В JSX-артбордах mobile-варианты есть только для главной партнёра. Для остальных дизайнер опирается на §11.2 (правила mobile).

**Печатные формы:**
- A4-сертификат, договоры, счёт-фактуры — отдельный пакет (не входит в SPA).

**Email-шаблоны:**
- Welcome, password-reset, notification — отдельный пакет (HTML-templates), не SPA.

**Telegram-бот UI:**
- Привязка и интерфейс бота — отдельный продукт. В SPA отображается только статус и кнопка «Привязать».

---

## Приложения

### Приложение А: Источники и артборды

| Файл | Описание |
|---|---|
| `desing/Дизайн-система.html` | Канвас 74 артборда. Открыть в браузере для просмотра |
| `desing/ds-tokens.css` | CSS-переменные (палитра, типографика, spacing, radius, shadow, motion) |
| `desing/ds-foundations.jsx` | Артборды фундаментальных токенов |
| `desing/ds-primitives.jsx` | React-копии всех компонентов (источник для каталога компонентов) |
| `desing/ds-components.jsx` | Каталог в обеих темах |
| `desing/ds-patterns.jsx` | Состояния (loading/empty/error/success/permission) + nav |
| `desing/ds-layouts.jsx` | MainLayout / AdminLayout / Tablet / Mobile |
| `desing/ds-screens-auth-partner.jsx` | Login + Dashboard + Clients + Contracts + Products |
| `desing/ds-screens-manager-admin.jsx` | Manager Workspace + Admin Home |
| `desing/ds-extra-partner.jsx` | 8 партнёрских экранов |
| `desing/ds-extra-manager.jsx` | 17 менеджерских |
| `desing/ds-extra-admin.jsx` | 18 админских |
| `desing/ds-missing-partner.jsx` + `ds-missing-manager.jsx` | 21 доп. экран |
| `desing/SCREENS_SPEC.md` | Детальный спек каждого экрана (1435 строк) |
| `desing/PROFILE_SPEC.md` | Детальный спек профиля (377 строк) |
| `desing/README.md` | Обзор дизайн-системы + Vuetify config |

### Приложение Б: Готовый Vuetify theme config

```ts
// vuetify.ts
export default createVuetify({
  theme: {
    defaultTheme: 'light',
    themes: {
      light: {
        dark: false,
        colors: {
          primary:    '#2E7D32',
          secondary:  '#6EE87A',
          tertiary:   '#4361A8',
          success:    '#2E7D32',
          warning:    '#ED6C02',
          error:      '#C62828',
          info:       '#0277BD',
          background: '#F8F9F8',
          surface:    '#FFFFFF',
          'on-surface': '#1A1F1B',
          'on-surface-variant': '#4A524C',
          outline:    '#BDC4BE',
          'outline-variant': '#DDE2DE',
        },
      },
      dark: {
        dark: true,
        colors: {
          primary:    '#6EE87A',
          secondary:  '#A4E0AC',
          tertiary:   '#B3C5FF',
          success:    '#A4E0AC',
          warning:    '#FFB77A',
          error:      '#FFB4AB',
          info:       '#93CCFF',
          background: '#0F1311',
          surface:    '#161A17',
          'on-surface': '#E2E4E2',
          'on-surface-variant': '#C2C8C3',
          outline:    '#3D4540',
          'outline-variant': '#2A312C',
        },
      },
    },
  },
  defaults: {
    VBtn:   { variant: 'flat', class: 'text-none', rounded: 'md' },
    VCard:  { rounded: 'lg' },
    VTextField:    { variant: 'outlined', density: 'comfortable' },
    VSelect:       { variant: 'outlined', density: 'comfortable' },
    VAutocomplete: { variant: 'outlined', density: 'comfortable' },
    VChip:  { rounded: 'pill' },
    VProgressLinear: { rounded: true, color: 'primary' },
  },
});
```

### Приложение В: Маппинг DS-компонентов на Vuetify

| DS-компонент | Vuetify-эквивалент |
|---|---|
| `DSButton variant="filled\|tonal\|outlined\|text\|elevated"` | `v-btn variant="…"` |
| `DSField label hint error` | `v-text-field variant="outlined"` |
| `DSField textarea` | `v-textarea` |
| `DSChip onClick active` | `v-chip filter` |
| `DSChip variant="success\|warning\|error\|info\|brand"` | `v-chip color="…"` |
| `DSStatus variant` | `v-chip size="small"` с точкой (custom) |
| `DSBadge` / `DSBadge dot` | `v-badge` |
| `DSCard variant="default\|elevated\|filled\|brand"` | `v-card variant="…"` |
| `DSAlert variant title` | `v-alert type="…"` |
| `DSProgress value variant height` | `v-progress-linear rounded color="primary"` |
| `DSTabs items active` | `v-tabs` + `v-tab` |
| `DSAvatar initials size status` | `v-avatar` |
| `DSSwitch on label` | `v-switch` |
| `DSCheckbox checked label` | `v-checkbox` |
| `DSRadio checked label` | `v-radio` |
| `DSSlider value` | `v-slider` |
| `DSSkel` | `v-skeleton-loader` |
| `DSDivider` | `v-divider` |
| `FullShell` + `*Sidebar` + `AppBar` | `v-app` + `v-navigation-drawer` + `v-app-bar` |

### Приложение Г: Чек-лист дизайнера

Перед сдачей макета убедитесь:
- [ ] Все цвета — из палитры (§3.1-3.2), нет хардкода вне токенов
- [ ] Все шрифты — Inter / JetBrains Mono (для чисел)
- [ ] Все радиусы — из шкалы (§3.5): кнопки `md`, карточки `lg`, чипы `pill`
- [ ] Spacing кратен 4px (§3.4)
- [ ] PageHeader на каждой странице (кроме Auth и Chat)
- [ ] Иконки только MDI (§5)
- [ ] Light + Dark вариант для каждого экрана (минимум для flagship-экранов)
- [ ] EmptyState для пустых таблиц/секций
- [ ] Skeleton-loader для loading-состояний
- [ ] 404/403 page-level (см. §9.3)
- [ ] Mobile-варианты для flagship-экранов (Login, Workspace, Dashboard, Chat)
- [ ] Tone of voice по §13
- [ ] Контраст WCAG AA (§12.1)
- [ ] Focus-rings (§12.2)
- [ ] BrandWaves используется как декор (НЕ заменён градиентами)

---

**Конец документа.**

Если по ходу работы возникают вопросы — обращайтесь:
- Полные спеки экранов: `desing/SCREENS_SPEC.md`
- Детали профиля: `desing/PROFILE_SPEC.md`
- Артборды (visual reference): `desing/Дизайн-система.html`
- Текущая реализация: `resources/js/pages/` (для проверки текущего состояния)
