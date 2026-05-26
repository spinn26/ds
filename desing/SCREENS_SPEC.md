# DS Consulting — спецификация экранов

Полный список экранов платформы с описанием каждого элемента и его функционала. Дизайнер использует этот документ как источник истины для отрисовки макетов в дополнение к артбордам в `desing/Дизайн-система.html`.

**Стек:** Laravel 11 + Vue 3 (Composition API) + Vuetify 3 + Pinia + Vite. Real-time через Socket.IO (порты 3001/3002). Auth — Sanctum Bearer-токены.

**Роли:**
- `consultant` — партнёр (полный кабинет, реф-ссылка, обучение, продукты).
- `registered` — только что зарегистрированный, доступно лишь `/education` и анкета онбординга.
- `admin` — полный доступ к `/admin/*` и `/manage/*`.
- `staff` (super-set из `backoffice`, `support`, `finance`, `head`, `calculations`, `corrections`, `education`) — `/manage/*` со своими permission-картами.
- `terminated` / `excluded` (activityStatus 3/5) — заблокирован кабинет; видит только `/terminated`, `/profile`, `/help`.

**Темизация:** primary `#2E7D32` (dark green), brand `#6EE87A` (мятный), `brand-ink #0A2B10`. AdminLayout всегда forced-dark.

**Глобальные паттерны:**
- Любая страница в MainLayout/AdminLayout начинается с `<PageHeader title icon [count] [#actions]>`.
- Списки → `DataTableWrapper` или `v-data-table-server` (density=compact). Skeleton-loader пока пусто. EmptyState без данных.
- CRUD-диалоги — `DialogShell` (v-dialog + v-card + title/text/actions); подтверждения — `ConfirmDialog` через `useConfirm()`.
- Notifications — `GlobalSnackbar` через `useSnackbar()` (showSuccess/showError/showNotification).
- Все деньги — `MoneyCell` (tabular-nums + suffix-валюта). Статусы — `StatusChip` (kind=status|priority|activity|contract|...). Boolean — `BooleanCell` (зелёная/серая иконка + tooltip).
- Колонки скрываются через `ColumnVisibilityMenu` (persist в localStorage по storage-key).
- Фильтры в плотном layout `density=compact` + `placeholder` (не label) + диапазоны за тогглом «Ещё».

---

## Содержание
- [Auth](#auth)
- [Partner cabinet](#partner-cabinet)
- [Manager / staff cabinet](#manager--staff-cabinet)
- [Admin cabinet](#admin-cabinet)
- [Chat](#chat)
- [Layouts & Shared](#layouts--shared)

---

## Auth

### Login — `/login`
**Файл:** `resources/js/pages/Auth/Login.vue`
**Доступ:** гость (meta.guest). Авторизованного редиректит на `/`.
**Layout:** собственный split-screen (без MainLayout).

**Десктоп (`grid-template-columns: 1fr 480px`):**

1. **Левая половина — Hero** (`linear-gradient(135deg, primary, secondary)`, белый текст):
   - Фон: `BrandWaves` SVG (width/height 900, rows 18, columns 22, amplitude 6, stroke `#ffffff`, opacity 0.35).
   - Верх: маркер «DS» (44×44, белая рамка, blur backdrop) + заголовок «DS Consulting» + подпись «Партнёрская платформа».
   - Центр: H1 «Партнёрский кабинет для финансовых консультантов» (38px) + lead «Клиенты, контракты, комиссии и обучение — в одном месте. Real-time чат с поддержкой и кураторами.»
   - Низ: «© DS Consulting · 2026 · 152-ФЗ».

2. **Правая половина — форма** (centered, max-width 380):
   - Eyebrow «вход в кабинет» (UPPERCASE, 12px, primary).
   - H2 «С возвращением» (либо «Подтверждение входа» в 2FA-шаге).
   - Lead «Войдите, чтобы продолжить работу с клиентами и контрактами.»
   - Поля (variant=outlined, rounded=md, density=comfortable):
     - `v-text-field` Email (mdi-email-outline, type=email, autocomplete=email, required).
     - `v-text-field` Пароль (mdi-lock-outline, append-inner — eye-toggle, autocomplete=current-password, required).
   - **Кнопка «Войти в кабинет»** (color=primary, size=large, block, type=submit, loading).
   - Разделитель «или» (с горизонтальными линиями).
   - **Кнопка «Войти через Telegram»** (variant=outlined, size=large, block, prepend-icon=mdi-send, **disabled** — плейсхолдер).
   - Подпись «Ещё не партнёр? [Подать заявку](/register)».

3. **2FA шаг** (если backend вернул `requires_2fa: true`):
   - Lead «Откройте Google Authenticator и введите 6-значный код.»
   - Поле TOTP (mdi-shield-key-outline, maxlength=6, inputmode=numeric, autofocus, rule `/^\d{6}$/`).
   - Кнопка «Подтвердить» (primary, large, block).
   - Кнопка «Назад» (variant=text, block) — сбрасывает challenge и пароль.

4. **Ошибка:** `v-alert type=error variant=tonal density=compact` (текст `Неверная почта или пароль` или сообщение от бэка).

**Мобила:** одна колонка, hero скрыт, сверху мини-маркер «DS» + бренд-имя.

**API:** `POST /auth/login → { token, user } | { requires_2fa, challenge }`. `POST /2fa/verify → { token, user }`.

---

### Register — `/register?ref=<code>`
**Файл:** `resources/js/pages/Auth/Register.vue`
**Доступ:** гость. Регистрация **только по реферальной ссылке** активного партнёра.
**Layout:** собственный анимированный (без MainLayout).

**Фон:** тёмно-зелёный gradient + 5 анимированных blob-радиал-градиентов с parallax (CSS-анимация `blob-drift` 16-24s) + декоративный `BrandWaves shape=circle` (420×420, mint background `#6EE87A`).

**Центрированная карточка** (`v-card pa-6 register-card`, elevation=16, rounded=xl, backdrop-blur 16px):

1. Бренд-заголовок: «DS» (text-h3, black, primary) + «КОНСАЛТИНГ ПЛАТФОРМА» (caption, letter-spacing 4px).
2. Заголовок «Регистрация» (text-h5, центр).
3. **Реф-баннер:**
   - Loading: `v-alert type=info` с спиннером «Проверяем реферальную ссылку…».
   - OK: `v-alert type=success` (mdi-account-check) — «Вас пригласил партнёр {name}, Код: {code}».
   - Error: `v-alert type=error` (mdi-lock-alert) — «Регистрация закрыта» + причина.
4. **Stepper** (`v-stepper`, 2 шага: «Ввод данных» / «Проверка»):

**Шаг 1 — форма** (`v-row dense`):
- Фамилия / Имя / Отчество (3 поля, `cyrillicRequiredRules`).
- Email (full width, mdi-email).
- Телефон (`<vue-tel-input>`, label сверху) + Telegram (mdi-send, placeholder `@username`).
- Дата рождения (type=date) + Город (mdi-city).
- Пароль (eye-toggle, rules: ≥8 символов, ≥1 буква, ≥1 цифра).
- Подтверждение пароля.
- 2 чекбокса (density=compact, required): «Согласен на обработку персональных данных» + «Согласен с правилами использования платформы».
- Кнопка «Далее →» (block, primary, size=large, disabled пока не валидно). Перед переходом — POST `/auth/check-duplicates` (email+phone).

**Шаг 2 — проверка:**
- `v-alert type=warning` «Проверьте данные. ФИО после регистрации можно изменить только через техподдержку».
- Карточка `v-card variant=tonal` с двумя колонками (Фамилия/Имя/Отчество/Почта/Телефон/Telegram/Дата рожд./Город).
- Бежевая карточка `color=amber-lighten-5` — «Стартовый период» (90 дней / 500 баллов ЛП).
- Действия: «Назад» (variant=outlined) + «Завершить регистрацию» (primary).

5. Внизу: «Уже есть аккаунт? [Войти](/login)».

**API:** `POST /auth/check-referral { code }`, `POST /auth/check-duplicates { email, phone }`, `POST /auth/register { ...form }`.

---

## Partner cabinet

> Все экраны внутри `MainLayout` (sidebar + topbar + мобильная нижняя навигация). Партнёрские пункты меню (group=`Обзор`/`Работа`/`Развитие`/`Связь`) видны при роли `consultant`.

### Workspace (Главная) — `/`
**Файл:** `resources/js/pages/Workspace.vue`
**Доступ:** все авторизованные (single page для партнёра и staff).
**Назначение:** рабочий стол — приветствие, ключевые показатели, виджеты.

**Шапка:** иконка mdi-hand-wave + «{Доброе утро/день/вечер}, {firstName}!» (text-h4, font-weight bold). Под ним «Рабочий стол DS Consulting». Справа — текущая дата по-русски.

**Двухколоночный layout (12/8 + 12/4):**

**Левая колонка:**
1. **«Мои показатели»** (для consultant'ов с `data.partnerStats`) — `v-card pa-4`, заголовок mdi-chart-line. 6 центрированных колонок: ЛП (success), ГП (info), НГП (warning), Квалификация (с под-чипом «Расчёт: …» если levelsDontMatch), Клиенты, Команда.
2. **Бейдж лидера сети** (если `data.isNetworkLeader`): `v-card variant=tonal color=secondary` с mdi-crown — «Вы — Лидер сети. Корень структуры, наставник не назначается».
3. **Наставник + Лидер сети** (две карточки рядом 6/6): аватар + ФИО + квалификация + чипы контактов (phone/email/telegram → копируют в буфер или открывают t.me/{nick}).
4. **«Новости и объявления»** (mdi-newspaper) — список новостей с цветной полосой слева (warning/success/info), заголовок + дата + текст pre-line.
5. **«Активность команды»** (для consultant'ов) — v-list, аватар mdi-cash (зелёный) + ФИО партнёра + «{сумма} ₽ · {лп} баллов» + дата.

**Правая колонка:**
1. **MyDayWidget** (только staff) — статистика сотрудника за сегодня.
2. **WhosOnlineWidget** (только staff) — кто из коллег онлайн (heartbeat WebUser.last_seen_at).
3. **MyTasksWidget** — личный TODO-чек-лист с inline-формой добавления.
4. **MyNoteWidget** — scratchpad с автосохранением.
5. **«Быстрые действия»** (mdi-lightning-bolt) — vertical стек tonal-кнопок:
   - (consultant) «Отчёт начислений» (mdi-bank), «Калькулятор», «Мои клиенты».
   - Все: «Обратная связь» (с бейджем unread), «Профиль».
6. **«Сообщения»** (mdi-chat) — последние сообщения с иконкой направления (down=входящее, up=исходящее), preview, бейдж «New».
7. **«Ближайшие события»** (mdi-calendar) — список конкурсов (router-link на /contests).
8. **«Задачи»** (только staff, mdi-clipboard-check) — список ссылок-задач: «Реквизиты на проверку: N», «Непрочитанных обращений: N», «Выплат в обработке: N»; если все 0 — «Всё выполнено ✓».

**Loading:** v-progress-linear indeterminate сверху страницы (z-index: 9, height 3px).

**API:** `GET /workspace`.

---

### Dashboard партнёра — `/dashboard`
**Файл:** `resources/js/pages/Dashboard.vue`
**Доступ:** партнёр (consultant) / staff (для просмотра).
**Назначение:** главный экран партнёра — квалификация, KPI, статус активации.

**Шапка:** PageHeader title=«Дашборд партнёра», icon=mdi-view-dashboard. Action — `MonthPicker` (с месячным меню).

**Секции:**
1. **Активационный период** (`v-alert closable`, виден если `data.statusInfo.daysRemaining != null`):
   - Type `warning` если ≤30 дней, иначе `info`.
   - «Осталось N дней. Требуется набрать N баллов. Текущий прогресс: N баллов.»
   - `v-progress-linear` (height 8, rounded), color синхронен с type.

2. **Карточка «Текущая квалификация»** (`v-card pa-4 mb-4`):
   - Eyebrow «ТЕКУЩАЯ КВАЛИФИКАЦИЯ» (caption, letter-spacing 1, medium-emphasis).
   - Чип уровня (color=secondary, size=default, font-weight bold): «5 [Senior]».
   - Чип статуса (success / grey по `active`).
   - Справа кнопка «Условия квалификаций» (variant=outlined, color=secondary, prepend-icon=mdi-table) → открывает диалог.
   - Полоса прогресса НГП: текущий / целевой, color=primary.
   - Подпись «Комиссия: 15%».
   - Полоса «ОП по ГП» (если `mandatoryPlan`): current/required, color=success|warning|error по `fulfillment%`.
   - Под `v-divider`: «До <название>: осталось N баллов НГП» либо чип «Максимальная квалификация» (color=amber, mdi-crown).

3. **Volume cards** (2 колонки, v-row): «Личные продажи (ЛП)» (mdi-bank, green), «НГП» (mdi-trending-up, orange).
   - Заголовок (caption), значение (text-h4, tabular-nums), иконка тренда (up/down/neutral) + процент к прошлому месяцу.

4. **Breakaway-карточка** (если `data.breakaway`):
   - Border-left 4px цветной (success/warning/error).
   - Чип-статус: «Отрыва нет» (success+mdi-check-decagram) / «Отрыв ≥ 70% — ветка не учитывается в ГП» (warning+mdi-alert-circle-outline) / «Отрыв ≥ 90% — пул не выплачивается» (error+mdi-alert-decagram).
   - 4 колонки: Топ ветка / ГП ветки / Доля от моего ГП (%) / Превышение.
   - Шкала с порогами «0% / 70% — удержание ГП / 90% — блокировка пула / 100%».

5. **«Показатели»** (h6) — 4 KPI-карточки (text-center): Партнёры 1 линии (mdi-account-outline, blue) / Всего партнёров (mdi-account-group, blue-darken-2) / Активных 1 линии (mdi-account-check, green) / Всего активных (mdi-account-multiple-check, green-darken-2).

6. **Клиенты** (2 карточки, router-link на `/clients`, hover): «Клиенты команды» (mdi-account-multiple, primary), «Мои клиенты» (mdi-account, secondary).

7. **«Партнёры по статусу»** (h6) — 4 кликабельные карточки (router-link на `/structure`): Всего (primary), Зарегистрировано (info), Активных (success), Терминированных (error). Под значением — diff к прошлому периоду (mdi-trending-up/down + цвет).

8. **Диалог «Условия квалификаций»** (max-width 1000):
   - Title: mdi-table (secondary) + «Полная таблица условий квалификаций».
   - `v-table density=compact`: # / Квалификация / % / НГП / ОП по ГП / Отрыв / Пул.
   - Текущий уровень — bg-green-lighten-5 + чип «Текущий» (success). Следующий — чип «Следующий» (info).

**Состояния:** loading → top progress-linear. Empty (нет statusInfo/breakaway/mandatoryPlan) — секции скрыты через v-if.

**API:** `GET /dashboard?month=YYYY-MM`, `GET /status-levels`.

---

### Structure — `/structure`
**Файл:** `resources/js/pages/Structure.vue`
**Доступ:** consultant / staff.
**Назначение:** иерархия команды партнёра (структура), раскрываемое дерево.

**Шапка:** PageHeader title=«Структура моей команды», icon=mdi-sitemap.

**Фильтр-карточка** (mb-3 pa-3):
- `v-text-field` search «ФИО партнёра...» (rounded, mdi-magnify, clearable, max-width 240, debounced).
- `v-select` Квалификация (multiple, clearable) — items из `GET /structure/qualification-levels` (value = `q.level`).
- `v-select` Статус (multiple, clearable): «Зарегистрирован-Партнёр» / «Активен» / «Терминирован» / «Исключён».
- Кнопка «Доп. фильтры» (variant=text, mdi-chevron-up/down).
- Чип-счётчик «{N} фильтр/фильтра/фильтров».
- Кнопка «Сбросить» (mdi-filter-remove, color=secondary).
- **Расширенные** (под v-expand-transition): `SmartRangeFilter` × 5 (Дата рождения, Дата смены статуса, ЛП, ГП, НГП) + `v-autocomplete` Город (с debounced search на `/structure/cities`).

**Таблица** (`v-table density=compact hover`, overflow-x auto):
- Колонки: `expand-toggle | Партнёр | Уровень | Квалификация | Статус | Дата смены статуса | ЛП | ГП | НГП | Клиенты | Контракты | Партнёры | export`.
- Раскрытие: лeading `v-btn icon mdi-chevron-right/down` загружает потомков через `GET /structure/{id}/children`. Отступы детей — `paddingLeft = depth*20+8`.
- Квалификация — чип secondary x-small.
- Статус — `<StatusChip kind=activityName>`.
- «Дата смены статуса»: для активного — `yearPeriodEnd` (или dateActivity+12мес fallback); для зарегистрированного — `activationDeadline`. Под датой каптион «ЛП с активации: N / 500» (warning <500, success ≥500).
- Кнопка export строки — mdi-download (x-small, variant=text), `GET /structure/{id}/export` (blob → XLSX).

**Pagination:** `v-pagination` (density=compact, по 25 items per page), показ если total>25.

**API:** `GET /structure?params...`, `GET /structure/{id}/children?params...`, `GET /structure/activity-statuses`, `GET /structure/qualification-levels`, `GET /structure/cities?q=...`, `GET /structure/{id}/export`.

---

### Profile — `/profile`
**Файл:** `resources/js/pages/Profile.vue`
**Доступ:** все авторизованные.

**Шапка:** PageHeader title=«Профиль», icon=mdi-account.

**Tabs** (color=primary, grow):
1. **«Информация о партнере»** (или «Информация о сотруднике» для staff).
2. **«Реквизиты и документы для выплат»** (только consultant).
3. **«Реферальные ссылки»** (только consultant + canInvite).
4. **«Безопасность»** (все).

**Tab 1 — Info:**
- Аватар (color=primary, size=72) + ФИО + email + чип статуса (`activityName`) + «до {yearPeriodEnd}» / «Активация до {activationDeadline}».
- Поля формы (v-row dense): Фамилия / Имя / Отчество (для партнёра — `disabled` + mdi-lock + hint «Изменение возможно только через техподдержку»; для сотрудника — редактируемые + поле «Должность»).
- Дата рождения (type=date), Пол (v-select male/female), Страна (v-autocomplete: Россия, Казахстан, Беларусь, ...), Город (v-combobox), Email, Телефон, Telegram.
- Кнопка «Сохранить» (mdi-content-save).
- (consultant) Карточка «Подписанные документы» — v-list-item с mdi-file-check, target=_blank.
- Карточка «Смена пароля» (3 поля: Текущий / Новый / Подтверждение, кнопка mdi-key).

**Tab 2 — Requisites:**
- **«Документы партнёра»** (mdi-file-document-multiple): 3 slot-карточки (Паспорт-фото, Паспорт-регистрация, Заявление на выплаты). Каждая — `v-file-input` + кнопка «Загрузить». `mdi-check-circle` если загружено.
- **«Реквизиты ИП»** (mdi-domain) + чип verificationStatus (verified/pending/rejected → success/warning/error). `v-alert warning` «Изменение сбросит статус верификации» если verified. Поля: Наименование ИП / ИНН / ОГРН / Юр.адрес / чекбокс «Адрес регистрации = фактическому» / Фактический адрес / Email / Телефон.
- **«Банковские реквизиты»** (mdi-bank) + чип status. Поля: Банк / БИК / Расчётный счёт / Корр. счёт / Получатель.

**Tab 3 — Referral:**
- Карточка с mdi-link-variant.
- Поля readonly: Реферальный код / Ссылка для приглашения. Append `v-btn icon mdi-content-copy` копирует в буфер.
- Alert «Скопировано в буфер обмена» если copied.
- Альтернатива (если `!canInvite`): info-алерт «Реферальные ссылки доступны только для партнёров со статусом Активен».

**Tab 4 — Security:**
- **2FA:**
  - Если включён: alert success «2FA включён с {date}», поле «Текущий пароль» + кнопка «Отключить 2FA» (color=error, mdi-shield-off).
  - Если выключен: lead «Защитите аккаунт одноразовыми кодами…», кнопка «Включить 2FA» (primary, mdi-shield-plus).
  - Setup-flow: инструкция в 3 шага, QR-код 180×180 + поле «Секрет» (mdi-key, click копирует) + поле TOTP-кода + «Подтвердить и включить».
- **Telegram-уведомления** (если `telegram.enabled`):
  - linked: alert success + «Отправить тест» (mdi-send-check) + «Отвязать» (color=error, mdi-link-off).
  - unlinked: lead с ссылкой на бота, кнопка «Привязать через бота» (mdi-send).
  - В процессе привязки: alert info + кнопка «Открыть в Telegram» (mdi-open-in-new, target=_blank) + «Проверить статус» + «Отменить». Внизу — индикатор «Ожидаем подтверждения в Telegram…».

**API:** `GET/POST /profile`, `POST /profile/avatar`, `GET /documents`, `POST /documents/upload`, `POST /password`, `POST /requisites`, `POST /bank-requisites`, `POST /2fa/setup`, `POST /2fa/confirm`, `POST /2fa/disable`, `POST /telegram/start-link`, `POST /telegram/test`, `POST /telegram/unlink`.

---

### Education (Обучение) — `/education`
**Файл:** `resources/js/pages/Education.vue`
**Доступ:** consultant + registered.
**Назначение:** домашняя страница обучения — список курсов с прогрессом + промо базы знаний.

**Layout:** pa-6 без PageHeader (свой заголовок).

**Шапка:** H1 «Обучение» (text-h4 bold) + sub-caption «N курсов · M в процессе · K ждут теста». Справа — search-поле «Поиск по курсам» (max-width 280, density=compact).

**Секции:**
1. **«Продолжите с того места»** (`v-card continue-card`, если есть курс с partial-progress):
   - Аватар mdi-play-circle (primary-soft).
   - Eyebrow «ПРОДОЛЖИТЕ С ТОГО МЕСТА» + название курса (text-truncate).
   - Прогресс-бар (height 6, rounded, max-width 320) + «N% · M/L».
   - Кнопка «Продолжить →» (primary, size=large, mdi-arrow-right).

2. **«База знаний» — кликабельная карточка** (`v-card kb-card`, link на `/education/kb`):
   - Аватар mdi-book-open-variant (primary, size=56).
   - Заголовок «База знаний» (h6, primary) + lead «Регламенты, инструкции, записи деловых игр и созвонов».
   - Справа: «{N} материалов» + «обновлено {relative-date}» + mdi-arrow-right.

3. **«Мои курсы»** (h2): «· N из M». Сетка из карточек курсов (12/6/4/3 responsive):
   - **Курс-карточка**: cover-блок с пронумерованным «01..N» большой цифрой + чип статуса (warning «нужен тест» с mdi-lock / success «открыто» с mdi-lock-open, если у курса есть `productId`).
   - Title + subtitle (тип: модули / уроки).
   - Progress-bar (height 6, color=success если 100% иначе primary).
   - Низ: «{N}%» (или «✓ изучен» success) + CTA-link («Начать» / «Продолжить» / «Изучен» — text-primary).

**Состояния:** Loading → `v-progress-circular` центр. Empty → `EmptyState` (mdi-school-outline, «Курсов пока нет», «Администратор скоро добавит учебные материалы»).

**API:** `GET /education/tree`, `GET /education/kb/stats`.

---

### EducationCourse — `/education/courses/:id`
**Файл:** `resources/js/pages/EducationCourse.vue`
**Назначение:** карточка курса/модуля — рекурсивное дерево, прогресс, CTA-перейти к следующему уроку.

**Layout:** двухколоночный (дерево + контент).

**Breadcrumbs** (density=compact): «Обучение → {course path}».

**Левая колонка — дерево** (aside.course-tree):
- Eyebrow «КУРС» + название корневого курса (text-subtitle-2 bold) + полоса прогресса + «N% · M из L».
- `<CourseTreeNode>` (рекурсивный) для каждого child — раскрывающиеся узлы с иконками modules/lessons + чек-иконка для viewed.

**Правая колонка — контент:**
1. **Hero** (с фоном по `heroStyle`, overlay + декоративный pattern): eyebrow + H1 course.title + description (opacity 0.9).
2. **Прогресс + CTA** (v-row): полоса «Прогресс по курсу» (height 10, primary) + карточка «следующий урок» (если есть `nextLesson`) с CTA → открывает урок.
3. **Структура курса / Уроки курса:**
   - Если есть `children` — сетка карточек-модулей (4/6/4 responsive). Каждый module-card: «M1»..«M{n}», чип статуса (mdi-check «Готово» / mdi-clock «В процессе» / mdi-lock «Закрыт»), название, прогресс-бар.
   - Иначе — список own-lessons.

**API:** `GET /education/tree`, `GET /education/courses/{id}`.

---

### EducationLesson — `/education/courses/:id/lessons/:lid`
**Файл:** `resources/js/pages/EducationLesson.vue`
**Назначение:** просмотр конкретного урока с видео / документами / блоками + кнопкой «Урок изучен».

**Layout:** двухколоночный (дерево слева, контент справа).

**Sticky-header (mdi-pin-style):**
- Eyebrow `{courseTitle}` + H1 `{lesson.title}`.
- Справа:
  - Если урок недоступен → чип warning + причина.
  - Если ещё не изучен → кнопка «Урок изучен» (primary, size=large, mdi-check, disabled если требует домашку и она не одобрена).
  - Если изучен → чип success «Изучено» (mdi-check-circle).

**Body-blocks:**
- Если `lesson.isTest` → большая CTA-карточка «Тест по курсу» (mdi-help-circle-outline, кнопка «Пройти тест»).
- `LessonBlockRenderer` (если есть `lesson.body[]`) — единый рендерер блоков (text, video, image, document).
- Legacy: videos (iframe 16:9 для YT/Vimeo), document_urls (mdi-file → открыть).

**API:** `GET /education/lessons/{lid}`, `POST /education/lessons/{lid}/viewed`.

---

### EducationTest — `/education/courses/:id/test`
**Файл:** `resources/js/pages/EducationTest.vue`
**Назначение:** итоговый тест курса (открытие доступа к продукту). Попыток без ограничения, проходной балл — 100%.

**Шапка** (sticky test-header): «Назад → {courseTitle}», справа caption «попыток: без ограничения · для допуска нужно 100%».

**Тело — 3 состояния:**

**A — идёт тест:**
- «Вопрос N из M» + индикатор `N / M` + полоса прогресса (height 8).
- Question-card (pa-6): eyebrow «Q{N}», текст вопроса (text-h6 bold), список радио-ответов (.answer-row с hover/selected стилями).
- Точки прогресса под карточкой (кликабельные).
- Действия: «← Назад» (outlined) + «Далее →» / «Завершить» (primary, disabled пока не выбран ответ).

**B — провал:**
- mdi-alert (error) + «Тест не пройден» + «Правильно: N из M. Для допуска нужно 100%».
- Кнопки: «Пройти ещё раз» (primary), «Вернуться к курсу» (outlined).

**C — успех:**
- mdi-check-circle (success) + «Тест сдан!» + «Доступ к продаже продукта {courseTitle} открыт».
- Кнопки: «К курсу» (primary), «К продукту» (outlined → `/products`).

**API:** `GET /education/courses/{id}/tests`, `POST /education/courses/{id}/test-submit`.

---

### EducationKb (База знаний) — `/education/kb`
**Файл:** `resources/js/pages/EducationKb.vue`
**Назначение:** портал по базе знаний — сетка разделов.

**Шапка:** breadcrumbs + H1 «База знаний» + sub «{N} материалов в {M} разделах». Search «Поиск по базе знаний» (max-width 320).

**Сетка** (12/6/4 responsive) — section-card:
- Аватар (mdi-folder-outline или произвольный, color=primary-soft).
- Title + caption «{N} материалов · {M} подразделов».
- Description.
- mdi-chevron-right.

**Empty:** `EmptyState mdi-bookshelf "База знаний пока пуста"`.

**API:** `GET /education/kb`, `GET /education/search?q=...`.

---

### EducationKbSection — `/education/kb/sections/:id`
**Файл:** `resources/js/pages/EducationKbSection.vue`
**Назначение:** список статей в разделе.

**Шапка:** breadcrumbs «Обучение → База знаний → Раздел» + H1 «Раздел базы знаний».

**v-list:** статьи с mdi-file-document-outline → переход на `/education/kb/articles/:id`.

**API:** `GET /education/kb/sections/{id}`.

---

### EducationKbArticle — `/education/kb/articles/:id`
**Файл:** `resources/js/pages/EducationKbArticle.vue`
**Назначение:** просмотр статьи базы знаний.

**Шапка:** breadcrumbs «Обучение → База знаний → {title}» + H1 title + description (text-body-1 medium-emphasis).

**Tags:** `v-chip x-small variant=tonal` — `#tag1 #tag2`.

**Body:** `<LessonBlockRenderer :blocks=article.body>` — рендер мульти-блочного контента. Empty → info-alert «Содержимое материала пока пустое».

**API:** `GET /education/kb/articles/{id}`.

---

### Clients (Мои клиенты) — `/clients`
**Файл:** `resources/js/pages/Clients/ClientList.vue`
**Доступ:** consultant.

**Шапка:** PageHeader title=«Мои клиенты», icon=mdi-account-group, count=total.

**Фильтр-карточка** (плотная):
- Поиск по ФИО (mdi-magnify, debounced).
- Город (mdi-city).
- Email (mdi-email).
- Тоггл «Ещё» (mdi-tune с под-счётчиком активных доп-фильтров).
- Чип-счётчик активных + кнопка «Сбросить».
- ColumnVisibilityMenu (storage-key=`client-list-cols`).
- **Расширенные:** диапазон даты рождения (с/по).

**Таблица** (`v-data-table-server`):
- Колонки: ФИО / birthDate (fmtDate) / Город / Email / Phone / Active (чип success/grey) / Products (чипы x-small).
- items-per-page 25/50/100/200.
- Empty → `<EmptyState>`.

**API:** `GET /clients?params...`.

---

### MyContracts (Контракты моих клиентов) — `/contracts`
**Файл:** `resources/js/pages/Contracts/MyContracts.vue`
**Доступ:** consultant.

**Шапка:** PageHeader title=«Контракты моих клиентов», icon=mdi-file-document, count=total.

**Фильтр-карточка** (плотная):
- № контракта (mdi-file-document).
- ФИО клиента (mdi-account).
- Продукт (v-autocomplete).
- Программа (зависит от продукта, disabled пока продукт не выбран).
- Статус (v-select).
- Тоггл «Ещё» + счётчик.
- ColumnVisibilityMenu (storage-key=`my-contracts-cols`).
- **Расширенные**: диапазоны Открыт / Заведён / Срок / Сумма.

**Таблица** (`v-data-table-server`):
- Колонки: № / Клиент / Продукт / Программа / Сумма (MoneyCell) / Срок / Статус / Открыт / Заведён / actions.

**API:** `GET /contracts/my?params`, `GET /products`, `GET /contract-statuses`.

---

### TeamContracts — `/contracts/team`
**Файл:** `resources/js/pages/Contracts/TeamContracts.vue`
**Доступ:** consultant.

**Шапка:** PageHeader title=«Контракты моей команды», icon=mdi-folder-account, count=total.

**Фильтры:** ФИО консультанта (mdi-account-tie) + поиск «ФИО клиента / № контракта / продукт» (mdi-magnify). Тоггл «Ещё» с диапазонами Открыт / Сумма / Срок.

**Таблица:** Консультант / № / Клиент / Продукт / Сумма (с валютным символом) / Открыт / Статус.

**API:** `GET /contracts/team?params`.

---

### Finance Report — `/finance/report`
**Файл:** `resources/js/pages/Finance/Report.vue`
**Доступ:** consultant.
**Назначение:** отчёт начислений и выплат партнёра.

**Шапка:** PageHeader (dynamic title `reportTitle`), icon=mdi-bank. Actions: «Скачать XLSX» (primary, mdi-download, disabled if locked) + MonthPicker.

**Locked-state** (если админ не открыл период): `v-alert type=info mdi-lock-clock` «Отчёт ещё не опубликован».

**Когда unlocked:**
1. **Row 1 — Квалификация + Объёмы** (4 колонки):
   - Квалификация (чип primary + чип процента + trending-up/down/neutral). Под: «Прошлый месяц: {title}».
   - ЛП (text-h6 success).
   - ОП по ГП (text-h6 info).
   - НГП (text-h6 warning).

2. **Row 2 — Sales totals** (4 карточки):
   - Личные продажи (mdi-account green): Баллы / Бонус в баллах ЛП / Бонус ₽.
   - Групповые продажи (mdi-account-group blue): Баллы (ОП по ГП) / Бонус баллы / Бонус ₽.
   - Итого продажи (mdi-sigma orange).
   - Пул (mdi-cash).

3. **Таблицы** комиссий, бонусов, удержаний — каждая section с заголовком + table.

**API:** `GET /finance/report?month=YYYY-MM`, `GET /finance/report/export?month=YYYY-MM`.

---

### Finance Calculator — `/finance/calculator`
**Файл:** `resources/js/pages/Finance/Calculator.vue`
**Доступ:** consultant / staff (некоторым read-only).

**Шапка:** PageHeader title=«Калькулятор объёмов», icon=mdi-calculator.

**Read-only banner** (если `isReadOnly('calculator')`): info-alert «Режим только для просмотра — доступна история расчётов».

**Форма** (v-row dense, поля показываются последовательно):
1. Квалификация (v-select).
2. Продукт (v-autocomplete, виден после квалификации).
3. Программа (если есть для продукта).
4. Свойство (calcProperty).
5. Срок контракта.
6. Год выплаты КВ (hint «Год выплаты комиссионного вознаграждения от провайдера»).
7. Сумма взноса (type=number).
8. Валюта (v-select, item-title=symbol).

**Действия:** «Рассчитать» (primary, mdi-calculator, loading) + «Сбросить» (variant=text, mdi-refresh).

**Info-alert** (mdi-information) «Расчёт комиссионных и объёмов для вновь открываемых контрактов с учётом НДС».

**Результат** (v-card mb-4 pa-4 после расчёта):
- Колонки с разбивкой: Комиссионные, Личный объём (баллы), Групповой объём, и т.д.

**API:** `GET /calculator/options`, `POST /calculator/compute`.

---

### Products — `/products`
**Файл:** `resources/js/pages/Products.vue`
**Доступ:** consultant.
**Назначение:** перечень доступных продуктов с фильтрами и блокировкой по обязательным курсам.

**Шапка:** PageHeader title=«Перечень продуктов», icon=mdi-package-variant.

**Фильтр-карточка:** Поиск (mdi-magnify) + Категория + Валюта (item-title=label).

**Сетка** карточек (4/6/4/3 responsive):
- **Hero**: v-img или плейсхолдер (mdi-package-variant + «DS Consulting»). Если битый URL — fallback.
- Чип категории (color, variant=outlined).
- Иконка lock/lock-open (success / grey).
- Title (subtitle-1 bold) + description (flex-grow).
- Валюты — чипы x-small primary outlined (символы).
- **Если locked + есть `requiredCourses`**: caption «Для доступа пройдите:» + чипы курсов (success/warning, с mdi-check/mdi-school).
- **Secondary actions** (если есть): «Обучение» (mdi-school info) / «Инструкция» (mdi-file-document secondary).
- **Primary CTA**: «К обучению» (mdi-school, tonal) / «Открыть продукт» (flat, mdi-open-in-new) / «Запросить» (если статус подачи).

**API:** `GET /products`, `GET /products/categories`, `GET /currencies`.

---

### InsmartWidget — `/insmart-widget`
**Файл:** `resources/js/pages/InsmartWidget.vue`
**Назначение:** встроенный виджет страховых продуктов InSmart (iframe).

**Шапка:** PageHeader title=«InSmart», icon=mdi-shield-car, subtitle=«Подбор и оформление страховых продуктов».

**Info-banner** (pa-3) — «Вся последующая обработка данных и начисления происходят автоматически…» + кнопка «← К продуктам».

**iframe-карточка** (min-height 70vh):
- Loading → progress-circular + «Загружаем виджет InSmart…».
- Error → warning-alert + кнопка «Повторить» (mdi-refresh).
- OK → `<iframe>` 80vh, `allow="payment; clipboard-read; clipboard-write"`.

**API:** `GET /insmart/widget-token`.

---

### Contests — `/contests`
**Файл:** `resources/js/pages/Contests.vue` (скрыт в меню по запросу 2026-05-05, путь работает).

**Шапка:** PageHeader title=«Конкурсы и события», icon=mdi-trophy.

**Фильтр:** v-select Тип конкурса (max-width 240).

**Loading skeleton:** 6 × `v-skeleton-loader type=article`.

**Сетка** (3 колонки) — contest-card:
- Чип «Активный» (success) + caption typeName справа.
- Title (subtitle-1 bold) + Description (flex-grow).
- «Период: {start} — {end}», «Победителей: {N}».
- Кнопка «Презентация» (outlined, mdi-presentation, target=_blank).

**Empty:** v-card text-center mdi-trophy-outline (64) + «Конкурсов и событий пока нет».

**API:** `GET /contests?type=...`.

---

### Communication (Обратная связь) — `/communication` (redirect → `/chat`)
**Файл:** `resources/js/pages/Communication.vue` (legacy)

**Шапка:** PageHeader title=«Обратная связь», icon=mdi-message-text. Badge unread + кнопка «Написать сообщение» (primary, mdi-pencil).

**Фильтр:** v-select «Категория» (max-width 260).

**Список сообщений** (`v-list lines=three`):
- Чип-prepend: «От DS» (blue) / «Вы» (green).
- Title: категория + чип «Новое» (error, x-small) если входящее и не прочитано + дата справа.
- Subtitle: текст pre-line.
- Append-actions: «Прочитано» (mdi-check, x-small outlined primary) / «Ответить» (mdi-reply).

**Empty:** `<EmptyState mdi-message-off-outline>`.

**Send/Reply dialog** (max-width 600, persistent): v-select Категория + v-textarea Сообщение (rows 5 auto-grow) + Отмена / «Отправить» (mdi-send).

**API:** `GET /communication`, `GET /communication/unread-count`, `GET /communication/categories`, `POST /communication/{id}/read`, `POST /communication` (с `reply_to`).

---

### Instructions — `/instructions`
**Файл:** `resources/js/pages/Instructions.vue`
**Назначение:** маркдаун-инструкции с поиском, категориями и видео.

**Шапка:** PageHeader title=«Инструкции», icon=mdi-book-open-variant.

**Search**: text-field «Поиск по тексту инструкций…» (mdi-magnify, debounced).

**Двухколоночный layout (4/8):**
- Левая: v-list категорий (v-list-subheader «Категории», items «{cat} — {N} статей»).
- Правая: v-list статей выбранной категории (mdi-file-document-outline). Append-icon mdi-play-circle-outline (info) если есть video.

**Drawer статьи** (location=right, width 800, temporary):
- Title + кнопка mdi-close.
- TOC (если `toc.length`): tonal info-card с iерархичным списком (depth по `level`).
- Embed-видео (iframe 16:9 для YouTube/Vimeo).
- v-html → отрендеренный markdown.
- FAB «back to top» (mdi-arrow-up, bottom-right).

**API:** `GET /instructions?search=...`, `GET /instructions/{slug}`.

---

### Help — `/help`
**Файл:** `resources/js/pages/Help.vue`
**Контент:** H5 «Инструкции» + plain `v-card pa-6 text-center text-medium-emphasis` «Раздел в разработке».

---

### Referrals — `/referrals`
**Файл:** `resources/js/pages/Referrals.vue`
**Контент:** Stub «Раздел в разработке» (реальные реф-ссылки — на `/profile` → Tab «Реферальные ссылки»).

---

### Terminated — `/terminated`
**Файл:** `resources/js/pages/Terminated.vue`
**Доступ:** consultant с activityStatus 3 (terminated) или 5 (excluded) — принудительный редирект из router-guard.

**Layout:** fill-height центрированный, без PageHeader.

**Карточка** (max-width 8/6, elevation=8, rounded=xl, pa-8 text-center):
- mdi-account-lock (size 80, color=error).
- H4 «Доступ ограничен» (error).
- Lead «Ваш аккаунт находится в статусе «Терминирован». Доступ к разделам платформы временно закрыт.»
- Divider.
- Блок «Что это значит:» — ul (условия активации не выполнены, баллы обнулены, повторная регистрация ≤3 раз).
- Блок «Для восстановления доступа:» — связаться с техподдержкой / наставником.
- Кнопки: «Обратная связь» (primary, mdi-chat → `/communication`), «Профиль» (outlined, mdi-account).

---

### SystemStatus (Статус системы) — `/status`
**Файл:** `resources/js/pages/SystemStatus.vue`
**Доступ:** все авторизованные (public-style read).

**Шапка:** PageHeader title=«Статус системы», icon=mdi-monitor-dashboard.

**Overall banner** (`v-card pa-4 mb-4 d-flex align-center ga-3`, ярко-цветной gradient по статусу):
- operational → зелёный (`#43a047 → #1b5e20`).
- maintenance → синий.
- degraded → оранжевый.
- partial_outage → насыщенный оранжевый.
- major_outage → красный.
- Иконка 32px (mdi-check-circle / mdi-tools / mdi-alert / mdi-alert-octagon / mdi-close-octagon).
- Заголовок (h6 white) + caption «Обновлено {HH:MM:SS}».
- (admin) Кнопка «Управление» (variant=outlined white, mdi-cog → `/manage/system-status`).

**Компоненты** (v-card pa-3, subtitle-1 + list):
- Каждый row: иконка статуса + name + description + чип статуса.
- Empty: «Компоненты не настроены».

**Активные инциденты** (v-card, mb-4):
- mdi-alert-circle (warning) + «Активные инциденты».
- Каждый incident (incident-row): чип severity (minor/major/critical → warning/orange/error) + title + description + caption «Начало: {dt} · Статус: {статус}».
- **Timeline апдейтов** (новые сверху): чип status + caption time + сообщение.

**История** (резолвленные инциденты): list с mdi-check-circle (success) + title + период «start → resolved_at».

**Авто-refresh:** setInterval 60 сек.

**API:** `GET /system-status`.

---

### Forbidden — `/forbidden`
**Файл:** `resources/js/pages/Forbidden.vue`
**Контент:** центрированная v-card variant=tonal max-width 440 — mdi-lock-outline (56 error) + H5 «Доступ запрещён» + lead «У вашей роли нет прав…» + кнопка «На главную» (primary, mdi-home).

### NotFound — `/not-found`
**Файл:** `resources/js/pages/NotFound.vue`
**Контент:** аналогичный Forbidden — mdi-compass-off (warning) + «Страница не найдена» + «Адрес «{path}» не существует или был удалён».

---

## Manager / staff cabinet

> Все экраны живут в `/manage/*` под MainLayout. Доступ ограничен `meta.staff: true`. Видимость пунктов меню — по effective permissions из БД (см. `auth.permissions`); fallback на `config/cabinetPermissions.js`. Для read-only ролей (например, `calculations` на менеджере контрактов) показывается info-banner «Режим только для просмотра» и скрываются write-кнопки.

### Manage Workspace (Рабочий стол) — `/manage/workspace`
**Файл:** `resources/js/pages/Admin/Workspace.vue`
**Доступ:** staff с правом `workspace`.
**Назначение:** агрегатор задач staff'а — что требует внимания сегодня.

**Шапка:** PageHeader title=«Рабочий стол», icon=mdi-view-dashboard-variant. Action: «Обновить» (mdi-refresh, text-variant, loading).

**Counter row (tiles 6/4/2 responsive):**
- 6 кликабельных tonal-карточек (color per metric): иконка + label + значение. Каждая ведёт на свой раздел.

**Task feed** (v-row dense — пары задач):
- **«Акцепт документов»** (`TaskCard` mdi-file-document-check, count) — список ожидающих с ФИО и email + дата → `/manage/acceptance`.
- **«Активные контракты без транзакций (30+ дней)»** (mdi-file-alert, ContractManager) — №контракта · consultantName + статус.
- **«Реквизиты на проверку»** (mdi-credit-card).
- **«Импорт транзакций — последние запуски»** (mdi-upload) — status, время, кол-во строк.
- **«Перестановки за неделю»** (mdi-history).

**API:** `GET /workspace/staff`.

---

### Periods — `/manage/periods`
**Файл:** `resources/js/pages/Admin/Periods.vue`
**Назначение:** управление видимостью отчётов партнёрам и заморозкой периодов.

**Шапка:** PageHeader title=«Закрытие отчётного месяца», icon=mdi-calendar-month, count=rows. Subtitle «Доступность отчётов на платформе для Партнёров». Action: ColumnVisibilityMenu.

**Info-alert** (info tonal, mdi-information): пояснение «Доступность управляет видимостью отчёта партнёрам. Закрытие периода — финальная заморозка».

**DataTableWrapper:**
- Колонки: Период (YYYY-MM) / visible (mdi-check-circle success / mdi-minus-circle error) / visibilityToggle (кнопка «Сделать доступным» success-tonal mdi-eye / «Сделать недоступным» error-tonal mdi-eye-off, если `canFull('reports-access')` и не frozen) / frozen-state (чип «Период закрыт» mdi-lock error / кнопка «Закрыть период» success-tonal mdi-lock) / actions (mdi-card-account-details-outline → `/manage/periods/:ym` + mdi-lock-open warning «Переоткрыть» если frozen).

**Close-period dialog** (max-width 480):
- v-alert warning mdi-shield-alert — предупреждение про заморозку.
- v-textarea «Комментарий» (rows 2).
- Actions: Отмена + «Закрыть» (error).

**API:** `GET /admin/periods`, `POST /admin/periods/{ym}/visibility`, `POST /admin/periods/{ym}/close`, `POST /admin/periods/{ym}/reopen`.

---

### PeriodCard — `/manage/periods/:ym`
**Файл:** `resources/js/pages/Admin/PeriodCard.vue`
**Назначение:** карточка одного периода с runner'ами штрафов, пула, выплат.

**Шапка:** PageHeader title=«Период {YYYY-MM}», icon=mdi-calendar-range. Actions: «К рабочему столу» + «Обновить».

**Status banner** (warning если frozen, иначе info): «Статус периода: открыт / закрыт · заморожен» + caption «Закрыт {date} · {note}». Справа кнопка «Закрыть период» (warning-tonal mdi-lock) / «Переоткрыть» (info-tonal mdi-lock-open).

**3 секции по половине ширины:**
1. **«Штрафы (§5): отрыв + ОП»** (mdi-alert-decagram error):
   - Кнопки «Preview» (info-tonal mdi-eye) + «Применить» (error flat mdi-check, disabled если нет preview или frozen).
   - 4 цифры: Партнёров / Затронуто комиссий / Отрыв ×0.5 / ОП ×0.8.
2. **«Пул (§6)»** (mdi-cash-multiple primary): Preview + Применить (primary). Выручка ДС / Фонд/уровень / Распределено / Партнёров.
3. **«Реестр выплат (§7)»** (mdi-bank): build + status, кол-во и сумма выплат.

**API:** `GET /admin/periods/{ym}`, `POST /admin/periods/{ym}/penalties/preview`, `POST /admin/periods/{ym}/penalties/apply`, `POST /admin/periods/{ym}/pool/preview`, `POST /admin/periods/{ym}/pool/apply`.

---

### ContractManager — `/manage/contracts`
**Файл:** `resources/js/pages/Admin/ContractManager.vue`
**Шапка:** PageHeader title=«Менеджер контрактов», icon=mdi-file-document-edit, count=total. Actions: кнопки экспорта/импорта/добавления (зависят от прав).
**Структура:** FilterBar (поиск, продукт, программа, статус, консультант) + DataTableWrapper с inline-editable полями. Диалоги создания/правки контракта (DialogShell), привязки клиента, удаления.

### ContractUpload — `/manage/contracts/upload`
**Файл:** `resources/js/pages/Admin/ContractUpload.vue`
**Шапка:** PageHeader title=«Загрузка контрактов», icon=mdi-upload.
**Структура:** двухколонный — слева форма загрузки CSV/XLSX (file-input + select продукта + кнопка «Загрузить»), справа — лог обработки (status, количество строк, ошибки построчно).

### Partners — `/manage/partners` (и `/admin/partners`)
**Файл:** `resources/js/pages/Admin/Partners.vue`
**Назначение:** реестр всех партнёров — глобальный (не команда staff'а).
**Шапка:** PageHeader title=«Партнёры», icon=mdi-account-search, count=total. Actions: добавить, экспорт.
**Структура:** Многоуровневый фильтр (FilterBar + расширенные тогглом «Ещё»): поиск по ФИО, уровень квалификации, статус, наставник, диапазоны ЛП/ГП/НГП, регистрация. DataTableWrapper server-side. Колонки: avatar+ФИО / participantCode / email / phone / Quality (StatusChip) / Status (StatusChip kind=activity) / dateActivity / dateRegister / actions (impersonate, edit, chat, delete).
**Action-icons на строке:** mdi-login «Войти как», mdi-pencil edit, `<StartChatButton :partnerId :partnerName :silent>` (mdi-chat-plus), mdi-delete.
**Edit dialog** (DialogShell, persistent, max-width 720): tabs «Идентичность / Контакты / Квалификация / Реквизиты / Документы».

### PartnerStatuses — `/manage/partners/statuses` (и `/admin/partners/statuses`)
**Файл:** `resources/js/pages/Admin/PartnerStatuses.vue`
**Шапка:** PageHeader title=«Статусы партнёров», icon=mdi-calendar-clock.
**Назначение:** массовая ручная смена статуса (активация / терминация). FilterBar + DataTable. Кнопка «Активировать» (success) / «Терминировать» (error) — с подтверждением и комментарием.

### Clients — `/manage/clients` (и `/admin/clients`)
**Файл:** `resources/js/pages/Admin/Clients.vue`
**Шапка:** PageHeader title=«Клиенты», icon=mdi-account-group, count=total. Action: добавить.
**Структура:** аналогично Partners — FilterBar + DataTableWrapper. Колонки: avatar+ФИО / birthDate / city / email / phone / Status (active/inactive) / products (chips) / consultantName / actions.

### Acceptance (Акцепт документов) — `/manage/acceptance`
**Файл:** `resources/js/pages/Admin/Acceptance.vue`
**Шапка:** PageHeader title=«Акцепт документов», icon=mdi-check-circle, count=total.
**Структура:** список заявок на верификацию документов (паспорт, реквизиты ИП, банк) с превью (ImageLightbox для image, ссылка для PDF). На каждой строке — кнопки «Одобрить» (success mdi-check) и «Отклонить» (error mdi-close) с диалогом причины.

### Requisites — `/manage/requisites` (и `/admin/requisites`)
**Файл:** `resources/js/pages/Admin/Requisites.vue`
**Шапка:** PageHeader title=«Реквизиты партнёров», icon=mdi-credit-card, count=total.
**Структура:** FilterBar (поиск, verification status, тип реквизитов) + DataTableWrapper. Колонки: партнёр / тип (ИП / Банк) / реквизиты (preview) / verificationStatus (chip) / actions (Verify, Reject с диалогом). Side-drawer/dialog с полной формой реквизитов и кнопками «Одобрить/Отклонить».

### Transfers (Перестановки) — `/manage/transfers`
**Файл:** `resources/js/pages/Admin/Transfers.vue`
**Шапка:** PageHeader title=«История перестановок», icon=mdi-history, count=total.
**Структура:** список логов перестановок партнёров (consultant перешёл от mentor A к mentor B). Фильтры: партнёр, период. Колонки: партнёр / from-mentor / to-mentor / выполнил staff / дата / комментарий.

### Permissions (Группы и права) — `/manage/permissions`
**Файл:** `resources/js/pages/Admin/Permissions.vue` (admin-only)
**Шапка:** PageHeader title=«Группы и права», icon=mdi-shield-account, count=groups.length. Action: «Добавить группу» (primary mdi-plus).

**Легенда + поиск** (legend-card):
- 3 чипа уровней: «Просмотр» (read-only) / «Правка» (+ добавление/редактирование) / «Полный» (+ удаление).
- Поле поиска «Поиск группы или раздела».
- Каунтер: «{N} групп · {M} разделов · {K} правил доступа».

**Матрица прав** (permissions-grid table):
- Колонки: «Группа» | section1 | section2 | ... | actions.
- Каждая строка — группа: аватар (secondary если system) + name + key (code) + чип «системная» (для defaults). Ячейки разделов: чип уровня (Просмотр/Правка/Полный) или пусто.
- Системная группа `admin` — особый бордер.

**CRUD-меню** на строке: «Редактировать» (mdi-pencil-outline) / «Удалить» (mdi-delete).

**Edit dialog:** name + key + description + matrix-checkbox по разделам с radio выбором уровня.

**API:** `GET/POST/PUT/DELETE /admin/permission-groups`, `GET /admin/permission-sections`.

### Instructions admin — `/manage/instructions`
**Файл:** `resources/js/pages/Admin/Instructions.vue`
**Шапка:** PageHeader title=«Управление инструкциями», icon=mdi-book-edit-outline.
**Структура:** список инструкций с категорией, slug, наличием видео. CRUD-диалог с полями title/slug/category/video_url/body_md + WYSIWYG (RichTextEditor).

### TransactionImport — `/manage/transactions/import`
**Файл:** `resources/js/pages/Admin/TransactionImport.vue`
**Шапка:** PageHeader title=«Импорт транзакций», icon=mdi-upload.
**Структура:** drag-zone для CSV/XLSX + mapping-полей + предпросмотр первых 50 строк + статус run-job с прогресс-баром (ImportProgressDialog). После завершения — список ошибок построчно с возможностью повторной обработки.

### Transactions — `/manage/transactions` (и `/admin/transactions`)
**Файл:** `resources/js/pages/Admin/Transactions.vue`
**Шапка:** PageHeader title=«Транзакции», icon=mdi-swap-horizontal.
**Структура:** мощная FilterBar (тип, партнёр, клиент, продукт, период, сумма, валюта, статус) + DataTableWrapper с inline editing. Колонки: дата / partner / client / контракт / продукт / сумма (MoneyCell с валютой) / тип / статус. Detail-dialog с полным разбором транзакции, attachments, history.

### Commissions — `/manage/commissions` (и `/admin/commissions`)
**Файл:** `resources/js/pages/Admin/Commissions.vue`
**Шапка:** PageHeader title=«Комиссии», icon=mdi-receipt, count=total.
**Структура:** FilterBar по периоду/партнёру/типу + DataTableWrapper. Колонки: партнёр / период / тип (ЛП/ГП/Бонус/Пул) / сумма / статус. Возможность ручного пересчёта.

### Pool (Комиссии пула) — `/manage/pool` (и `/admin/pool`)
**Файл:** `resources/js/pages/Admin/Pool.vue`
**Шапка:** PageHeader title=«Комиссии пула», icon=mdi-cash-multiple.
**Структура:** Распределение пула по уровням (матрёшка). Колонки: уровень / название / N партнёров / фонд / выплачено. Diagram (bars) распределения. ColumnVisibilityMenu (storage-key=`pool-cols`).

### Qualifications — `/manage/qualifications` (и `/admin/qualifications`)
**Файл:** `resources/js/pages/Admin/Qualifications.vue`
**Шапка:** PageHeader title=«Квалификации», icon=mdi-chart-bar, count=total.
**Назначение:** просмотр и ручная правка квалификаций партнёров за месяц.
**Структура:** FilterBar (период, уровень, партнёр) + DataTable. Колонки: партнёр / уровень / % / НГП / ОП по ГП / Отрыв / Пул / actions (правка с reason).

### Charges (Прочие начисления) — `/manage/charges`
**Файл:** `resources/js/pages/Admin/Charges.vue`
**Шапка:** PageHeader title=«Прочие начисления», icon=mdi-cash-plus, count=total. Action: добавить.
**Назначение:** ручные начисления и удержания вне основного расчёта.
**Структура:** список с фильтрами по периоду/партнёру/типу + Create dialog (партнёр, тип, сумма, валюта, период, комментарий, attachment).

### PaymentRegistry — `/manage/payments` (и `/admin/payments-legacy`)
**Файл:** `resources/js/pages/Admin/PaymentRegistry.vue`
**Шапка:** PageHeader title=«Реестр выплат», icon=mdi-cash-multiple. Actions: month picker, build, export, batch-status change.
**Структура:** Канбан-доска статусов выплат (К выплате → На выплате → Выплачено → Возврат) или table-view. Каждая строка — выплата партнёру с реквизитами и комментариями. Build-кнопка пересобирает реестр из commissions.

### Payments-legacy — `/admin/payments`
**Файл:** `resources/js/pages/Admin/Payments.vue` — простая таблица старого реестра выплат (резервная страница).

### Reports — `/manage/reports`
**Файл:** `resources/js/pages/Admin/Reports.vue`
**Шапка:** PageHeader title=«Отчёты», icon=mdi-file-chart.
**Структура:** грид с карточками отчётов (Сводный по комиссиям, ЛП/ГП по периодам, Активные/Терминированные, Контракты по продуктам и т.д.). Каждая карточка — title + description + дата генерации + кнопки «Сгенерировать» / «Скачать XLSX/PDF».

### Currencies (Справочники для расчёта) — `/manage/currencies` (и `/admin/currencies`)
**Файл:** `resources/js/pages/Admin/Currencies.vue`
**Шапка:** PageHeader title=«Справочники для расчёта транзакций», icon=mdi-currency-usd.
**Структура:** табы: «Валюты», «Курсы валют», «Коэффициенты НДС», «Срезы периодов». Базовый CRUD.

### Products admin — `/manage/products` (и `/admin/products`)
**Файл:** `resources/js/pages/Admin/Products.vue`
**Шапка:** PageHeader title=«Продукты и программы», icon=mdi-package-variant-closed.
**Структура:** табы: Продукты / Программы / Свойства / Сроки контрактов / Параметры расчёта. Каждый — DataTable + CRUD-dialog. Drag-and-drop сортировка.

### ProductsPreview — `/manage/products-preview`
**Файл:** `resources/js/pages/Admin/ProductsPreview.vue`
**Назначение:** превью партнёрской витрины продуктов с фильтром «From role» — увидеть какие продукты покажутся консультанту определённой квалификации.

### Contests admin — `/manage/contests` (и `/admin/contests`)
**Файл:** `resources/js/pages/Admin/Contests.vue`
**Шапка:** PageHeader title=«Конкурсы и события», icon=mdi-trophy, count=total. Action: добавить.
**Структура:** DataTable конкурсов (название, тип, период, победителей, статус) + CRUD-dialog (с RichTextEditor для description, file-input для презентации, multi-select победителей).

### EducationConstructor — `/manage/education`
**Файл:** `resources/js/pages/Admin/EducationConstructor.vue`
**Шапка:** PageHeader title=«Конструктор обучения», icon=mdi-school-outline.
**Назначение:** иерархический конструктор курсов и уроков.
**Структура:** двухпанельный — слева дерево курсов с drag-and-drop, справа редактор выбранного узла (поля: title / description / parent_id / lessons[] / tests[]). Lesson-editor содержит мульти-блочный редактор (LessonBlockRenderer с типами text/video/image/document/quiz). Test-editor: вопросы + варианты ответов + правильный.

### Education-legacy — `/manage/education-legacy`
**Файл:** `resources/js/pages/Admin/Education.vue`
**Шапка:** PageHeader title=«Обучение», icon=mdi-school.
**Назначение:** legacy-конструктор курсов (старый формат). Используется fallback.

### EducationCategories — `/manage/education/categories`
**Файл:** `resources/js/pages/Admin/EducationCategories.vue`
**Шапка:** PageHeader title=«Категории курсов», icon=mdi-folder-multiple, count=total.
**Структура:** Простой CRUD категорий: name, color, icon (mdi-icon-picker), order.

### EducationAnalytics — `/manage/education/analytics`
**Файл:** `resources/js/pages/Admin/EducationAnalytics.vue`
**Шапка:** PageHeader title=«Статистика обучения», icon=mdi-chart-line.
**Структура:** KPI tiles (Записано / В процессе / Завершено / Тестов сдано). Bar-chart по курсам. Table «Топ-N студентов».

### KbConstructor — `/manage/kb`
**Файл:** `resources/js/pages/Admin/KbConstructor.vue`
**Шапка:** PageHeader title=«База знаний — конструктор», icon=mdi-book-open-variant.
**Структура:** двухпанельный аналогично EducationConstructor — дерево разделов слева, редактор статьи справа (title, description, body=LessonBlockRenderer-блоки, tags).

### HomeworkQueue — `/manage/homework`
**Файл:** `resources/js/pages/Admin/HomeworkQueue.vue`
**Шапка:** PageHeader title=«Домашние задания», icon=mdi-clipboard-edit-outline.
**Структура:** очередь домашек на проверку. Каждая запись — partner / lesson / submitted_at / attachment + кнопки «Одобрить» / «Отклонить с комментарием». Drawer с превью решения и историей.

### PartnerQuestionnaires — `/manage/partner-questionnaires`
**Файл:** `resources/js/pages/Admin/PartnerQuestionnaires.vue`
**Шапка:** PageHeader title=«Анкеты партнёров», icon=mdi-clipboard-account, count=total.
**Структура:** просмотр заполненных анкет онбординга. FilterBar + Table. Drawer с полным набором ответов (Q3..QN).

### TechSupportDesk — `/manage/support`
**Файл:** `resources/js/pages/Manage/TechSupportDesk.vue` (admin-only)
**Шапка:** PageHeader title=«Тех. поддержка», icon=mdi-lifebuoy. Action: «Обновить» (mdi-refresh).

**KPI row (6/3 responsive):**
- 4 tonal-карточки: «Открыто» (info) / «Активных инцидентов» (error) / «Решено сегодня» (success) / «Закрыто сегодня».

**Filter pills:** Статус (Все/Открыт/В работе/Ожидание/Решён/Закрыт) + чип «Только инциденты» (mdi-alert-decagram error).

**Tickets table** (DataTable density=compact):
- Колонки: subject (с mdi-alert-decagram если incident) / incidentNo (chip error tonal) / severity (chip) / status (chip) / lastMessageAt / actions (mdi-message-text «Открыть чат», mdi-alert-decagram «Зафиксировать как инцидент», mdi-check-decagram «Закрыть инцидент» если canFull).

**Incident dialog** (max-width 480, persistent):
- Title: «Зафиксировать инцидент» / «Изменить приоритет инцидента».
- v-radio-group severity (minor/major/critical/maintenance, inline).
- v-textarea «Комментарий».
- Actions: Отмена / Подтвердить.

**API:** `GET /manage/support/desk`, `POST /manage/support/{id}/incident`, `POST /manage/support/{id}/resolve-incident`.

### Manage Chat — `/manage/chat`
См. секцию [Chat → StaffChat](#staff-chat-manage--manage--chat).

### Chat Analytics — `/manage/chat/analytics`
См. [Chat → Analytics](#chat-analytics--manage-chat-analytics).

### Manage аналитические клоны для head-роли
Все админские аналитические страницы (OwnerDashboard, Reconciliation, Anomalies, Cohorts, Contests) клонируются под путём `/manage/*` для роли `head`, чтобы не открывать `/admin/`. Компонент тот же. См. описание в Admin-разделе.

---

## Admin cabinet

> Все экраны под `AdminLayout` (forced dark theme, secondary-accent цвет вместо primary, узкий sidebar с группированным меню). Доступ — `meta.admin: true` (только роль `admin`).

**AdminLayout sidebar (`color=grey-darken-4 theme=dark`):**
- Брендмарк «DS УПРАВЛЕНИЕ» + «ПАНЕЛЬ УПРАВЛЕНИЯ» (caption letter-spacing).
- Кнопка «На сайт» (mdi-arrow-left, grey-lighten-1).
- Меню: Дашборд / Дашборд руководителя / Пользователи / Партнёры / Клиенты / Новости / Роадмап / Продукты / Конкурсы и события / Справочники (expandable: 11 каталогов) / Сверка балансов / Аномалии / Календарь операций / Массовые операции / Когорты / Почтовая рассылка / Триггеры уведомлений / Интеграции / API-ключи / Настройки / Мониторинг.
- Rail-toggle (свернуть до 72px, persist).
- Topbar: mdi-shield-crown + «DS Управление». Справа аватар (color=secondary) с меню «На сайт / Выйти».

### Admin Dashboard — `/admin/dashboard`
**Файл:** `resources/js/pages/Admin/Dashboard.vue`
**Шапка:** PageHeader title=«Панель управления», icon=mdi-chart-areaspline.

**KPI cards (6/4/3/2 responsive):** ~6 tonal-карточек: «Партнёров активных», «Контрактов открыто», «Выручка ₽», «Комиссии к выплате», «Новые регистрации», «Тикеты в работе». Каждая — icon + value (h5) + label (caption) + delta (mdi-arrow-up/-down + %).

**Charts row 1:**
- 8/12: «Выручка по месяцам (₽)» — Chart.js `<Line>`, height 280.
- 4/12: «Партнёры по статусам» — `<Doughnut>` 280.

**Charts row 2:**
- 6/12: «Новые партнёры по месяцам» — `<Bar>`.
- 6/12: «Выручка по продуктам (этот месяц)» — `<Bar>`.

**Charts row 3:**
- 7/12: «Воронка партнёра» (funnel) — список этапов с `v-progress-linear` (height 20, цвет funnelColor по индексу). Под названием — count + «{N}% от регистраций».
- 5/12: «Распределение по квалификациям» — чипы level + count + полосы.

**Дальше:** карточки «Топ-10 партнёров по выручке», «Активные тикеты», и т.д.

**API:** `GET /admin/dashboard`.

### OwnerDashboard — `/admin/owner-dashboard` (и `/manage/owner-dashboard`)
**Файл:** `resources/js/pages/Admin/OwnerDashboard.vue`
**Шапка:** PageHeader title=«Дашборд руководителя», icon=mdi-crown.

**KPI 6/4/3 responsive:**
- 4 tonal-cards: Активных партнёров (primary), Выручка тек.мес. (success), К выплате (warning), Пул (info). Значения через `<MoneyCell>`.

**Sections:**
- 7/12: «Выручка ДС по месяцам» — горизонтальные бары (height 22, color=success), для каждого месяца `formatMonth(m)` + progress + MoneyCell.
- 5/12: «Топ-10 партнёров по ГП» — v-list с аватаром-номером (color=primary) + ФИО + квалификация + MoneyCell ГП справа.
- Дополнительно: «Воронка партнёра» (стадии перехода: Зарегистрирован → Активен → Топ-5 уровней), «Аномалии этого месяца» (если есть).

**API:** `GET /admin/owner-dashboard`.

### Funnel — `/admin/funnel` (redirect → owner-dashboard)
**Файл:** `resources/js/pages/Admin/Funnel.vue` (legacy, объединён в OwnerDashboard).

### Reconciliation — `/admin/reconciliation` (и `/manage/reconciliation`)
**Файл:** `resources/js/pages/Admin/Reconciliation.vue`
**Шапка:** PageHeader title=«Сверка балансов», icon=mdi-scale-balance.
**Структура:** месяцевой селектор + таблица «Снапшот = Live SUM?» для каждой агрегатной метрики (commissions, pool, payments). Подсветка расхождений (`expected != actual`) — error.

### Anomalies — `/admin/anomalies` (и `/manage/anomalies`)
**Файл:** `resources/js/pages/Admin/Anomalies.vue`
**Шапка:** PageHeader title=«Аномалии и алерты», icon=mdi-alert-decagram. Action: refresh.
**Структура:** список аномалий с severity (info/warning/error), категорией, partner/контрактом, описанием. Кнопки «Дать прокомментировать» / «Закрыть».

### Cohorts — `/admin/cohorts` (и `/manage/cohorts`)
**Файл:** `resources/js/pages/Admin/Cohorts.vue`
**Шапка:** PageHeader title=«Когорты и retention», icon=mdi-chart-line.
**Структура:** heatmap-таблица retention'а по когортам регистрации × месяцам. Цветовая шкала от красного (0%) к зелёному (100%).

### OpsCalendar — `/admin/calendar`
**Файл:** `resources/js/pages/Admin/OpsCalendar.vue`
**Шапка:** PageHeader title=«Календарь операций», icon=mdi-calendar-check.
**Структура:** месячный календарь (`v-date-picker` / самодельная сетка) с маркерами на каждый день: «Закрытие периода», «Реестр выплат», «Пересчёт квалификаций», etc.

### BulkOps — `/admin/bulk-ops`
**Файл:** `resources/js/pages/Admin/BulkOps.vue`
**Шапка:** PageHeader title=«Массовые операции», icon=mdi-format-list-bulleted-square.
**Структура:** сетка карточек операций: «Терминировать просрочивших активацию», «Переоценить квалификации», «Перепосчитать пул», «Очистить кеш отчётов». Каждая — confirm-диалог + лог запуска.

### Users — `/admin/users`
**Файл:** `resources/js/pages/Admin/Users.vue`
**Шапка:** PageHeader title=«Пользователи», icon=mdi-account-multiple. Action: «Добавить» (mdi-plus).
**FilterBar:** поиск (4 cols), v-select Роль (3 cols), v-select Заблокирован (3 cols). Reset-кнопка.
**DataTableWrapper server-side:**
- Колонки: ФИО / email / role (StatusChip per role split by ','). / isBlocked (BooleanCell mdi-lock error / mdi-lock-open success) / actions (ActionsCell + custom «Войти как» mdi-login secondary).
- Edit dialog (DialogShell): Фамилия / Имя / Отчество / Email / Phone / Telegram / Роли (multi-select) / isBlocked / Password (если новый).

**API:** `GET /admin/users`, `POST /admin/users`, `PUT /admin/users/{id}`, `DELETE /admin/users/{id}`, `POST /admin/users/{id}/impersonate`.

### News — `/admin/news`
**Файл:** `resources/js/pages/Admin/News.vue`
**Шапка:** PageHeader title=«Новости и объявления», icon=mdi-newspaper. Action: «Добавить».
**DataTable (density=compact):** Заголовок / Содержание (clipped 80 chars) / Тип (StatusChip info/warning/success: «Инфо/Важно/Успех») / Активна (BooleanCell) / Дата / ActionsCell.
**Edit dialog (DialogShell 720):** title + RichTextEditor (min-height 260) + v-select type + checkbox active.

### Roadmap — `/admin/roadmap`
**Файл:** `resources/js/pages/Admin/Roadmap.vue`
**Шапка:** PageHeader title=«Роадмап продукта», icon=mdi-map-marker-path.
**Структура:** канбан-доска статусов: «Идея → В работе → Готовится релиз → Выпущено». На каждой карточке — title / description / labels / released_at. Drag-and-drop между колонками.

### Mail — `/admin/mail`
**Файл:** `resources/js/pages/Admin/Mail.vue`
**Шапка:** PageHeader title=«Почтовая рассылка», icon=mdi-email-fast.
**Структура:** двухпанельный — слева редактор кампании (subject, recipients filter, RichTextEditor для body, schedule), справа preview + история отправок с метриками (открытия, клики).

### Triggers — `/admin/triggers`
**Файл:** `resources/js/pages/Admin/Triggers.vue`
**Шапка:** PageHeader title=«Триггеры уведомлений», icon=mdi-robot.
**Структура:** список триггеров (event + condition + action), включение/выключение, кнопка тестовой отправки.

### Integrations — `/admin/integrations`
**Файл:** `resources/js/pages/Admin/Integrations.vue`
**Шапка:** PageHeader title=«Интеграции», icon=mdi-cloud-sync.
**Структура:** сетка карточек интеграций: InSmart, Telegram-bot, SMTP, AmoCRM, провайдеры комиссий. Каждая — status, ключи, кнопка «Тест соединения».

### ApiKeys — `/admin/api-keys`
**Файл:** `resources/js/pages/Admin/ApiKeys.vue`
**Шапка:** PageHeader title=«API-ключи и токены», icon=mdi-key-variant.
**Структура:** список выданных API-ключей (label, prefix, scopes, last_used_at, expires_at). Кнопки «Сгенерировать новый» / «Отозвать». Свежий ключ показывается ОДИН РАЗ в диалоге с «Скопировать в буфер».

### Settings — `/admin/settings`
**Файл:** `resources/js/pages/Admin/Settings.vue`
**Шапка:** PageHeader title=«Настройки системы», icon=mdi-cog.
**Структура (короткий файл — 46 строк, stub):** базовая страница системных настроек (формы для подмены SMTP, URL'ов, флагов). В разработке.

### Monitoring — `/admin/monitoring`
**Файл:** `resources/js/pages/Admin/Monitoring.vue`
**Шапка:** PageHeader title=«Мониторинг системы», icon=mdi-pulse.
**Структура:** real-time-карточки нагрузки: PHP-FPM, queue workers, DB pool, Redis hit-rate, socket-server. Графики `<Line>` за последний час. Список последних ошибок (Sentry-style).

### Admin SystemStatus — `/manage/system-status`
**Файл:** `resources/js/pages/Admin/SystemStatus.vue`
**Шапка:** PageHeader title=«Управление статусом системы», icon=mdi-monitor-dashboard. Actions: создать компонент / инцидент.
**Структура:** CRUD компонентов (component-row: name, description, status, order, кнопки edit/delete) + CRUD инцидентов (severity, title, description, components, status, timeline апдейтов). Кнопка «Добавить апдейт» к открытому инциденту.

### References + ReferenceDetail — `/admin/references` + `/admin/references/:catalog`
**Файлы:** `resources/js/pages/Admin/References.vue` (грид справочников) + `resources/js/pages/Admin/ReferenceDetail.vue` (CRUD конкретного справочника).
**Шапка справочников:** PageHeader.
**Структура References:** сетка карточек по каталогам (productCategory, currency, contractStatus, status, directory_of_activities, type_contest, status_contest, criterion, communicationCategory, title, occupation, meetingType). Каждая — caption «{N} записей» + кнопка «Открыть».
**Структура ReferenceDetail:** универсальный CRUD для записи справочника (fields: name/code/color/order). DataTable + ActionsCell + DialogShell.

### Education-legacy admin clones
`/admin/education`, `/admin/products`, `/admin/contests`, `/admin/contracts` и т.п. — те же компоненты что `/manage/*`. См. в Manage-секции.

### WorkspaceTaskCard — internal compound
**Файл:** `resources/js/pages/Admin/WorkspaceTaskCard.vue` (27 строк) — внутренний presentational wrapper для `Manage Workspace`. Карточка задачи: title, icon, count chip, кнопка «Открыть» с router-link.

---

## Chat

### Partner Chat — `/chat`
**Файл:** `resources/js/pages/Chat/PartnerChat.vue` (1456 строк)
**Доступ:** все авторизованные (партнёр со своими тикетами; staff видит свои переписки и общие категории).
**Layout:** full-bleed (без paddding-контейнера), `.chat-wrap` flex-fill.

**Connection banner** (виден при потере WS): `<div class="conn-banner">` warning-fill + mdi-wifi-off + «Соединение потеряно. Сообщения придут с задержкой ~15 сек».

**Двухпанельный (mobile — sliding):**

**Левая панель — список диалогов** (`.chat-sidebar`):
- **Sidebar head** (px-3 py-2): заголовок «Обращения» (body-1 bold), кнопка «Новый» (primary x-small mdi-plus), overflow-меню (mdi-dots-horizontal): «Написать основателю» (mdi-email-edit) / «Оставить кейс» (mdi-briefcase-plus).
- **Search** (text-field outlined density=compact, mdi-magnify clearable).
- **Filters:**
  - Status pills (chip x-small label, color=primary flat если активен, иначе tonal): «Все / Открыт / В работе / Ожидание / Решён / Закрыт».
  - Category pills: «Все / Поддержка / Финансы / Контракты / Развитие / Жалоба» (color от catColor).
- **Conversation list** (`.sidebar-list`):
  - Каждый `.chat-item` (active / has-unread / pinned):
    - Цветной аватар категории (`catColor`, mdi-icon).
    - Body: top-row subject (с mdi-pin если pinned) + ago(last_message_at). Preview-row: «Вы:» / «⚙» (system) / preview text. Bottom-row: chip-статус (tonal) + caption category.
    - Кнопка pin (mdi-pin / mdi-pin-outline на hover).
    - Бейдж unread (>0).
  - Empty: mdi-chat-outline (36 grey) + «Ничего не найдено».

**Правая панель — открытый тикет:**
- Header: avatar + subject + (recipient_name) + ago(last_message_at) + actions (close / mark resolved / pin).
- Сообщения (scroll-y, авто-скролл к низу при новых): каждое — bubble (own = right primary tonal / other = left surface). Системные — центральный grey-italic. Прикреплённые файлы — `ImageLightbox` (image) или ссылка mdi-file. Reactions / read-status / timestamp.
- Input: textarea + кнопки emoji / attach (mdi-paperclip) / send (mdi-send primary). Drag-and-drop для файлов. Превью attachments перед отправкой.

**New ticket dialog** (max-width 600):
- v-select Категория (Поддержка / Финансы / Контракты / Развитие / Жалоба / ...).
- v-text-field Тема.
- v-textarea Сообщение.
- File-input multiple.
- Actions: Отмена / «Создать».
- Поддержка query `?new=support` → предзаполненная категория.

**Real-time** (Socket.IO):
- `chat:new-message` → пуш в текущий список.
- `chat:ticket-status` → обновление статуса.
- `chat:user-typing` → индикатор «{N} печатает…».

**API:** `GET /chat/tickets`, `POST /chat/tickets`, `GET /chat/tickets/{id}/messages`, `POST /chat/tickets/{id}/messages`, `POST /chat/tickets/{id}/read`, `POST /chat/tickets/{id}/status`, `POST /chat/tickets/{id}/pin`, `GET /chat/unread-count`.

---

### Staff Chat — `/manage/chat`
**Файл:** `resources/js/pages/Chat/StaffChat.vue` (3150 строк)
**Доступ:** staff (admin/support/backoffice/finance/head/calculations/corrections/education).
**Layout:** full-bleed как PartnerChat, расширенные функции.

**View mode toggle** (`<v-btn-toggle>` в header): «Список» (mdi-format-list-bulleted) / «Канбан» (mdi-view-column-outline).

**Левая sidebar:**
- Header: «Обращения» + view-mode toggle + Bulk-mode toggle (mdi-checkbox-multiple-blank).
- Search (debounced).
- **Smart views**: чипы «Все · {N}», «Мои · {N}», «Без ответств. · {N}», «Просрочено · {N}» (warning).
- Status pills + Priority pills (Low/Medium/High/Critical).
- Conversation list: тот же chat-item + цветная priority-bar (если ≠ medium) + чипы customer→recipient + CSAT-бейдж «★ {rating}» если csat_rating заполнен.
- **Bulk-mode**: чекбоксы на строках. Внизу `.bulk-bar` (slide-up):
  - «Выбрано: N»
  - Menu «Статус» (v-menu с list статусов).
  - Menu «Приоритет».
  - Menu «Категория».
  - «Назначить ответственного».
  - «Удалить».

**Канбан-режим:**
- Колонки status (Open / In progress / Pending / Resolved / Closed) с count + total.
- Card в колонке — компакт-tile с subject / category / priority bar / due date / assigned.
- Drag-and-drop между колонками меняет status (POST).

**Правая панель (выбранный тикет):**
- Те же сообщения что в PartnerChat + расширенный header с:
  - Чипы category / priority / status (с edit).
  - Поле «Ответственный» (autocomplete по staff).
  - Поле «Приоритет», «Срок».
- **Context-панель справа** (выезжающая) — данные клиента/партнёра/контракта: ФИО, телефон, email, последние транзакции, связанные тикеты.
- Quick-replies (шаблоны ответов).
- Internal notes (видны только staff).

**API:** все из PartnerChat + `GET /chat/staff-views/{smart-view}`, `POST /chat/tickets/{id}/assign`, `POST /chat/tickets/bulk-update`, `GET /chat/quick-replies`, `POST /chat/quick-replies`, и т.д.

---

### Chat Analytics — `/manage/chat/analytics`
**Файл:** `resources/js/pages/Chat/Analytics.vue` (426 строк)
**Шапка** (.analytics-head): H2 «Аналитика чата» (mdi-chart-box-outline primary). Period-row: кнопки-чипы «Сегодня / Неделя / Месяц / Квартал», «Обновить» (mdi-refresh tonal), «Отчёт смены» (mdi-clipboard-text-outline secondary flat).

**Summary cards (cards-row):** 4 стат-карточки:
- «Всего тикетов» (за период).
- «Ср. время ответа» (success/warning по threshold, fmtMinutes).
- «Ср. время закрытия» (из открытия в закрытие).
- «SLA нарушено» (red если >0, «ожидание > 30 мин»).

**Status counters** (.status-counters) — 6 ячеек по статусам с цветной верхней border, label+icon+count.

**Grid (2 колонки):**
- «Категории» (mdi-shape-outline) — bar-list per category (label / track / fill / count).
- «Приоритет» (mdi-flag-outline) — то же per priority.

**«Динамика по дням»** — SVG-полилайн (resolved vs new), axis + точки с tooltip'ами.

**Топ операторов по тикетам / CSAT.**

**Handover dialog** («Отчёт смены»): форма с предзаполненными метриками + textarea, экспорт XLSX/PDF.

**API:** `GET /chat/analytics?period={today|week|month|quarter}`, `POST /chat/analytics/handover`.

---

## Layouts & Shared

### MainLayout — основной layout
**Файл:** `resources/js/layouts/MainLayout.vue` (957 строк)
**Используется:** все аутентифицированные роуты под `/`, кроме `/admin/*`.

**Структура:**

1. **Sidebar** (`v-navigation-drawer` 260px, rail 72px):
   - Header (sidebar-header pa-4): «DS» (h6 primary) + «ПЛАТФОРМА» + caption кабинета (Администратор / Кабинет БЭК / Техподдержка / Руководитель / Фин. менеджер / Расчёты / Правки / Куратор обучения).
   - Кнопка close (mobile) или rail-chevron (desktop).
   - **Меню** (v-list density=compact nav):
     - Группы (v-list-subheader, UPPERCASE): «Обзор», «Работа», «Развитие», «Связь», «Инструменты», «Данные», «Финансы», «Выплаты», «Обучение», «Прочее», «Помощь», «Аналитика».
     - Items: title + icon + active-state по pathname. Badge unread у `/chat` и `/manage/chat`.
   - Footer-список: «Свернуть меню» (mdi-chevron-left/right grey).

2. **Topbar** (`v-app-bar` flat border-b, backdrop-blur):
   - mobile: гамбургер.
   - SystemStatusChip (мигающий status-dot + label + clickable → menu/tooltip с инцидентами).
   - (consultant + canInvite) Кнопка «Реф. ссылка» (mdi-link-variant tonal primary, copies to clipboard, текст меняется на «Скопировано» на 2 сек).
   - Theme toggle (mdi-weather-sunny / mdi-weather-night).
   - **Notifications menu** (v-menu min-width 360):
     - Header: «Уведомления» + «Прочитать все» (если есть unread).
     - v-list с уведомлениями (аватар цветной по type, title, message, time-ago).
     - Footer: «Звук уведомлений» — switch (persist в localStorage).
   - **User menu** (avatar primary/secondary):
     - Avatar 56 + ФИО + email.
     - Чип статуса активности + «до {yearPeriodEnd}» + чип «N дн.» если ≤30 (mdi-timer-outline warning/error).
     - Links: «Профиль» (mdi-account-outline), «Панель управления» (mdi-shield-crown, только admin), «Выйти» (mdi-logout, base-color=error).
     - Камера-overlay на аватаре для загрузки avatar.

3. **Main content** (`v-main`, pa-4 / md-pa-6, fluid container):
   - `<router-view>`.
   - На route='/chat' или '/manage/chat' — `.content-main--full-bleed` (без отступов).

4. **Mobile bottom navigation** (если mobile):
   - Consultant: «Главная / Клиенты / Структура / Продукты / Профиль».
   - Staff: «Главная / Партнёры / Отчёты / Ещё (открывает drawer)».
   - Backdrop-blur, safe-area-inset.

5. **Глобальные компоненты:**
   - `<ConfirmDialog ref=confirmRef>` (provideConfirm).
   - `<GlobalSnackbar>`.
   - `<GlobalSearch>` (Ctrl+K).
   - `<ChatLauncher>` (floating bottom-right).
   - `<OnboardingQuestionnaire v-model=showQuestionnaire>` — обязательная анкета для consultant'ов.

6. **Quick-message dialog «Написать собственику»** (max-width 560, persistent на отправке):
   - Title: иконка + subject «Сообщение собственику».
   - Checkbox «Отправить анонимно» (mdi-checkbox, hint).
   - v-textarea «Ваше сообщение» (rows 6, auto-grow, counter 5000).
   - Actions: Отмена / «Отправить» (primary, disabled если message пуст). POST → `/founder-message`.

**Real-time** (Socket.IO на ws://localhost:3001 или same-origin):
- `notification` — push в список + sound + snackbar (для type='chat' — с кнопкой «Открыть»).
- `chat:new-message` / `chat:unread-changed` — refresh unread-count (debounced 200ms).
- Visibility-API: reconnect при возврате на вкладку после >60 сек idle.

**API:** `GET /profile` (для statusInfo), `POST /profile/avatar`, `GET /notifications`, `GET /notifications/unread-count`, `POST /notifications/{id}/read`, `POST /notifications/read-all`, `GET /chat/unread-count`, `PUT /me/heartbeat`, `POST /founder-message`.

---

### AdminLayout
**Файл:** `resources/js/layouts/AdminLayout.vue` (203 строки)
**Используется:** `/admin/*` (только админ).

**Особенности:**
- Forced dark theme на mount, восстановление при unmount.
- Sidebar (`color=grey-darken-4 theme=dark`), 280px, rail 72px.
- Кнопка «На сайт» (mdi-arrow-left) в верху меню.
- Группы (v-list-group expandable): «Справочники» с 11 каталогами.
- Topbar dark: «DS Управление» (mdi-shield-crown secondary).
- User menu (avatar color=secondary): «На сайт» / «Выйти».
- `<ConfirmDialog>` + `<GlobalSnackbar>` mounted.

---

### Shared components

#### PageHeader
**Файл:** `resources/js/components/PageHeader.vue`
**Props:** `title` (required), `icon`, `count` (опц.).
**Слот:** `actions`.
**Render:** flex row — иконка (size 28 primary) + h5-title + чип-count (primary tonal small) + spacer + actions.

#### DataTableWrapper
**Файл:** `resources/js/components/DataTableWrapper.vue`
**Props:** items, headers, loading, title, server-side (page, items-per-page, items-length), searchable (search prop), search-placeholder, density (default=compact), empty-icon, empty-message, empty-hint, row-props (fn), selectable, selected, item-value.
**Slots:** toolbar, filters, empty, любой `#item.{key}`.
**Render:**
- Toolbar (если title или searchable): title + search-input + slot `toolbar`.
- Filters-slot (chips).
- Body: skeleton-loader пока loading и нет items / EmptyState если пусто / v-data-table-server (или v-data-table) с hover + tabular-nums.

#### BrandWaves
**Файл:** `resources/js/components/BrandWaves.vue`
**Назначение:** параметрическая SVG-сетка из волн. Используется для hero-фонов, EmptyState bg, логотипа (shape=circle).
**Props:** width/height, shape (sheet|circle), bgColor, strokeColor, rows, columns, amplitude, frequency, strokeWidth, strokeOpacity.
**Render:** rect/circle background + clip-path + 2 семейства path'ов (horizontal + diagonal).

#### ChatLauncher
**Файл:** `resources/js/components/ChatLauncher.vue`
**Назначение:** floating-FAB чатов снизу-справа.
**Render:**
- Hidden on route `/chat` или `/manage/chat`.
- FAB v-btn icon large (color=primary, error если unread>0). Badge.
- Panel (transition cl-pop): header (mdi-chat-processing + «Мои чаты» + expand + close), body (loading / empty / v-list тикетов с status-icon + subject + preview + time-ago + badge unread), footer («Все чаты» / «Новый»).

#### GlobalSearch
**Файл:** `resources/js/components/GlobalSearch.vue`
**Назначение:** Ctrl+K глобальный поиск.
**Triggers:** keydown Ctrl/Cmd+K.
**Render:** v-dialog max-width 640 + input autofocus + результаты (Партнёры, Клиенты, Контракты, Тикеты, Продукты). Управление ↑↓ Enter Esc.

#### SystemStatusChip
**Файл:** `resources/js/components/SystemStatusChip.vue`
**Render:** для admin при наличии активных инцидентов — `<v-menu>` со списком инцидентов и кнопкой «Решён» (success tonal mdi-check-circle). Для остальных — `<router-link to=/status>` + tooltip. Сам chip — мигающий dot (анимация ping) + label («Работает / Замедление / Сбой») + caption detail.

#### OnboardingQuestionnaire
**Файл:** `resources/js/components/OnboardingQuestionnaire.vue` (340 строк)
**Назначение:** обязательная анкета для нового партнёра до открытия кабинета.
**Render:**
- v-dialog persistent max-width 720.
- Hero (pa-6): аватар mdi-clipboard-text-outline + «Добро пожаловать в DS Consulting» + lead + полоса прогресса.
- Body (scrollable, pa-6 max-height 65vh):
  - Identity-alert (mdi-account-check info-tonal): «{ФИО} · {Город}», «ФИО и город подставлены из вашего профиля».
  - Q3..QN: разные типы (text-field, btn-toggle, radio-group, checkboxes).
  - Q4 «Опыт в продажах» — v-btn-toggle (4 варианта).
  - Q5 «Цель в DS» — варианты с описаниями.
  - И т.д.
- Footer actions: «Сохранить и продолжить» (primary, disabled если не все required filled).

**API:** `POST /partner-questionnaire`.

#### EmptyState
**Файл:** `resources/js/components/EmptyState.vue`
**Props:** icon, message, hint, size (88), brand (true).
**Render:** центрированный круг с BrandWaves (mint) и иконкой поверх + body-1 message + caption hint + slot `action`.

#### MonthPicker
**Файл:** `resources/js/components/MonthPicker.vue`
**Props:** modelValue ('YYYY-MM').
**Render:** v-btn chevron-left + v-btn outlined small с label «{Месяц} {год}» (открывает menu) + v-btn chevron-right (disabled на текущем мес.).
**Menu:** year switcher (chevron + год disable если в будущем) + grid 3×4 кнопок месяцев (selected → primary flat, future → disabled).

#### ConfirmDialog
**Файл:** `resources/js/components/ConfirmDialog.vue`
**Назначение:** глобальный confirm через `useConfirm().ask({...})` или v-model.
**Render:** DialogShell title + message + actions (cancel/confirm). Поддерживает HTML-content, custom icon/colors.

#### GlobalSnackbar
**Файл:** `resources/js/components/GlobalSnackbar.vue` (27 строк)
**Назначение:** глобальные toast'ы через useSnackbar.
**Render:** v-snackbar с цветом по type (success/error/info/warning) + action-кнопка (например, «Открыть» с router push).

#### StartChatButton
**Файл:** `resources/js/components/StartChatButton.vue`
**Props:** partnerId, partnerName, contextType, contextId, contextLabel, silent (без авто-приветствия), customSubject, icon, tooltip.
**Render:** v-btn icon-only с tooltip. Click → создаёт тикет (subject строится автоматически: «Чат по клиенту: ...», «Контракт: ...», «Чат по общим вопросам ...») и редиректит в `/manage/chat?open={id}`.

#### MoneyCell
**Файл:** `resources/js/components/MoneyCell.vue`
**Props:** value, currency (suffix '₽'/'USD'/'%'), decimals, colored (red/green по знаку), signed (+ для positive), empty ('—').
**Render:** span с tabular-nums + formatted число + suffix (medium-emphasis).

#### StatusChip
**Файл:** `resources/js/components/StatusChip.vue`
**Props:** value, kind (status|priority|activity|activityName|contract|contest|payment|import|category), color/text override, size, variant (tonal), label, icon.
**Render:** v-chip с цветом+текстом из соответствующих карт `composables/useDesign` (statusColors, priorityColors, getActivityColor, activityLabels, и т.д.).

#### BooleanCell
**Файл:** `resources/js/components/BooleanCell.vue`
**Props:** value, true/false-icon (mdi-check-circle / mdi-minus-circle), true/false-color (success / grey), size (small), tooltip (string или {on, off}).
**Render:** v-icon с цветом+иконкой по значению, опц. в v-tooltip.

#### Прочие компоненты
- **FilterBar** (`components/FilterBar.vue`) — обёртка над v-row для фильтров (search-prop + slot для доп-полей + reset-кнопка).
- **DateRangePicker** — пара date-input «с / по».
- **SmartRangeFilter** — расширенный диапазон с типом (date/number/datetime).
- **ColumnVisibilityMenu** — меню со списком чекбоксов колонок (persist в localStorage по storage-key).
- **ActionsCell** — слот для row-actions (mdi-pencil edit + mdi-delete delete по default, + slot).
- **PersonCell** — компактное отображение ФИО + код участника.
- **DialogShell** — обёртка для v-dialog: title (с icon) + slot content + actions (Cancel + Confirm). Поддерживает loading, persistent, max-width, confirm-text/color, icon.
- **ImageLightbox** — клик по миниатюре открывает полноэкранную модалку с изображением (zoom, swipe).
- **FormErrors** — отображение ошибок validation от бэка (списком или общим message).
- **PhoneInput** — обёртка над vue-tel-input.
- **RichTextEditor** — TipTap или аналогичный WYSIWYG для News/Mail/Contests.
- **Breadcrumbs** — обёртка над v-breadcrumbs.
- **MyTasksWidget** — личный TODO-чек-лист (add/toggle/delete, persist через `GET/POST /me/tasks`).
- **MyNoteWidget** — авто-сохраняемая заметка (`GET/PUT /me/note`).
- **MyDayWidget** — статистика staff за сегодня.
- **WhosOnlineWidget** — список коллег онлайн (last_seen_at в пределах 5 мин).
- **ImportProgressDialog** — прогресс джобы импорта (с status, % completed, success/error counts, log).
- **SystemStatusBanner** — top-of-page banner при degradation (не везде, в основном на dashboard).
