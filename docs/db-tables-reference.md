# Справочник таблиц БД (newds, PostgreSQL)

Составлено 14.08.2026 по живой схеме: **270 таблиц** в `public`.
Легенда статуса:

- **живая** — читается/пишется кодом платформы (`app/`, `resources/js`, `routes/`);
- **legacy** — наследие Directual/n8n, кодом платформы не используется, данные держим ради истории;
- **служебная** — инфраструктура Laravel.

> **С 14.08.2026 схема разделена.** 93 мёртвые таблицы Directual перенесены из
> `public` в схему **`legacy`** (миграция `2026_08_14_001100`). В `public`
> осталось 177 таблиц — только то, с чем работает платформа. Данные целы,
> внешние ключи между схемами работают, `down()` возвращает всё обратно.
> Читать legacy-таблицу можно, указав схему явно: `select * from legacy."bkc"`.
> Код, который ищет ссылающиеся таблицы через каталоги Postgres
> (`PartnerMergeService`, `AdminUserController`, `AdminContestController`),
> теперь фильтрует по `public` — иначе слияние партнёров падало бы на
> невидимой таблице.

Приблизительные размеры — из `pg_class.reltuples` (оценка планировщика), `-1` = статистика не собиралась (обычно таблица пустая).

---

## 1. Идентичность, доступ, оргструктура

| Таблица | Что хранит | Статус |
|---|---|---|
| `WebUser` | **Канон личности** (55 колонок): логин, e-mail, пароль, телефон, TG, роль, 2FA, аватар. Модель `App\Models\User`. | живая (~1 155) |
| `webUser` | Строчный legacy-двойник той же сущности из Directual. Кодом не используется — не путать с `WebUser` (Postgres регистрозависим). | legacy (~1 030) |
| `users` | Пустая дефолтная таблица Laravel — не используется. | служебная |
| `WebUserSession`, `sessions` | Сессии: первая — Directual, вторая — Laravel-драйвер сессий. | legacy / служебная |
| `personal_access_tokens` | Sanctum-токены API (веб-SPA, боты). | служебная (~520) |
| `password_reset_tokens`, `ResetPasswordRequest` | Токены восстановления пароля (Laravel / Directual). | служебная / legacy |
| `roles`, `usergroups` | Справочники ролей и групп пользователей. Реальный канон роли — колонка в `WebUser` + `User::isStaff()`. | живая / legacy |
| `permission_groups` | Группы прав `/admin/permissions`: JSON `permissions` → middleware `permission:section,level`. | живая (10) |
| `feature_flags` | Фича-флаги с привязкой к ролям. | живая |
| `user_segments` | Сегменты пользователей (JSON-критерии) для рассылок/выборок. | живая |
| `user_status_log` | Лог смены статуса пользователя. | legacy |
| `telegram_link_tokens` | Одноразовые токены привязки Telegram к аккаунту. | живая (48) |
| `departments`, `department_members`, `employee_positions` | Оргструктура сотрудников: отделы (parent_id, руководитель, зам), состав, должности. | живая |
| `user_notes` | Личные заметки сотрудника (одна запись на пользователя). | живая (11) |
| `SocialUser`, `WebFlowAccess` | Соц-логин и доступы Webflow из Directual. | legacy |

## 2. Партнёры (ФК)

| Таблица | Что хранит | Статус |
|---|---|---|
| `consultant` | **Карточка партнёра** (78 колонок): ФИО, `webUser`-якорь, наставник (`inviter`), активность, `activationDeadline`, уровень, статусы реквизитов/акцепта, счётчики терминаций. | живая (~2 068) |
| `consultantStructure`, `structure`, `team`, `test_connection_between_cons_and_team` | Структуры/команды и их лидеры — legacy-механика Directual, вытеснена деревом наставничества в `consultant`. | legacy |
| `status_levels` | **Матрица квалификаций**: уровень, ЛП, ГП, накопленный ГП, %ДС, доля пула, отрыв, обязательный ГП. Основа всех расчётов. | живая (10) |
| `statuses`, `status`, `contractStatus`, `status_requisites`, `consultantPaymentStatus`, `statusImportTransaction`, `status_contest` | Справочники статусов: партнёра, контракта, реквизитов (1 бэкофис / 2 консультант / 3 verified), выплат, импорта, конкурса. | живая (справочники) |
| `chageConsultanStatusLog` | Лог смены статуса партнёра (опечатка в имени — из Directual). | живая (211) |
| `changeConsultantInviterLog` | **История перестановок наставника** (модуль «Перестановки»). | живая (216) |
| `changeConsultantClientLog`, `changeConsultantContractLog` | История переноса клиентов и контрактов между партнёрами. | живая |
| `changeConsultantStructureTrigger`, `dataPermutationTrigger` | Directual-триггеры пересчёта структуры. | legacy |
| `partnerAcceptance`, `logAcceptance`, `agreementPartnersDocuments` | Акцепт оферты и партнёрских документов: факт, тип документа, дата, источник. | живая |
| `partner_comments` | Комментарии сотрудников к партнёру (в админке). | живая |
| `consultantsCounterHistory`, `consultantsWithMissingLogs`, `consultant_activation_backfill_20260602` | Служебные снимки/бэкфиллы. ⚠ `consultant_activation_backfill_20260602` не удалять — источник восстановления дат активации. | legacy (хранить) |
| `consultantStatusChangeMailing`, `typeMailConsultantStatus` | Directual-рассылки по смене статуса. | legacy |
| `cronPartnerCompressionDaily`, `cronMonthly`, `triggerCron` | Журналы Directual-кронов (сжатие структуры, месячные задачи). | legacy |
| `backofficeregistration` | Заявки на регистрацию из бэкофиса Directual. | legacy |
| `setup` | Персональные настройки партнёра из Directual (13 строк). | legacy |

## 3. Клиенты

| Таблица | Что хранит | Статус |
|---|---|---|
| `client` | **Карточка клиента** (38 колонок): ФИО, контакты, привязка к партнёру и `person`. Контакты берутся только отсюда. | живая (~8 714) |
| `person_legacy_map` | Мост legacy `person.id` → `client_id`/`consultant_id` (после консолидации Directual). | живая (~7 314) |
| `clientFamily` | Родственные связи клиентов (семьи — важно для разбора «дублей по телефону»). | живая (100) |
| `clientGoal`, `clientGoalsTrigger` | Финансовые цели клиента + Directual-триггер их пересчёта. | живая / legacy |
| `clientsCapital`, `assetsHistory` | Активы/пассивы клиента и история их оценки. | живая / legacy |
| `clientsIndicators`, `indicator`, `indicatorsHistory` | Финансовые показатели клиента по датам + справочник показателей. | живая (~111 652) |
| `client_duplicate_ignores` | Белый список групп «ложных дублей» клиентов (семьи, drift привязок). | живая |
| `clientsCounterHistory` | Снимки счётчиков клиентов. | legacy |
| `riskProfile`, `profitability` | Риск-профили и ожидаемая доходность по профилю/валюте. | справочники |
| `occupation`, `city`, `country`, `directory_of_activities` | Справочники: занятость, города, страны, виды деятельности. | живая |
| `meeting`, `meetingType` | Встречи с клиентом и их типы (Directual-CRM). | legacy |

## 4. Продукты, программы, контракты

| Таблица | Что хранит | Статус |
|---|---|---|
| `products_catalog` | **Каталог продуктов UI** (25 колонок): название, тип, категория, витринные флаги, JSONB-тарифы. | живая (102) |
| `programs_catalog` | Программы внутри продуктов (37 колонок): сроки, валюты, годы КВ, флаги. | живая (690) |
| `legacy_products` | Остаток старой таблицы продуктов — FK-якорь для исторических строк. | legacy (28) |
| `productType`, `productCategory` | Типы и категории продуктов. | справочники |
| `contract` | **Контракт клиента** (50 колонок): номер, клиент, партнёр, продукт/программа, суммы, валюта, срок, статус, даты. | живая (~18 676) |
| `termContract` | Справочник сроков контрактов. | справочник (65) |
| `insmartProduct`, `insmartVender`, `insmart_type_map` | Маппинг типов/компаний InSmart на наши `product_id`/`program_id`. | живая |
| `motivationGroup`, `motivationGroupLevel`, `consultantMotivationGroupLevel` | Мотивационные группы продуктов и уровни в них по партнёрам. | legacy |

## 5. Деньги: транзакции, комиссии, балансы

| Таблица | Что хранит | Статус |
|---|---|---|
| `transaction` | **Платёж по контракту** (59 колонок): сумма, валюта, курс, дата, `commissionsAmountRUB` (доход ДС), %ДС, год КВ. Основа всех начислений. | живая (~57 642) |
| `transaction_draft` | Черновики ручных транзакций до проведения (с превью-расчётом). | живая |
| `transactionDeleting`, `transactionRecalculation`, `massTransactionRecalculationTrigger` | Directual-очереди удаления и массового пересчёта транзакций. | legacy |
| `commission` | **Начисления партнёрам** (38 колонок, ~605 тыс. строк): по транзакции × партнёру × уровню, тип, суммы, признаки удержания. | живая |
| `comissionByLevel` | Тариф комиссии по программе × уровню. | legacy |
| `dsCommission` | **Тарифы %ДС** (product × program × срок × окно дат) — источник расчёта, отдельно от JSONB-тарифов каталога. | живая (~4 451) |
| `commissionCalcProperty`, `hansardYearsCalcProperty` | Справочники схем расчёта (Стандарт/Апфронт/СФ) и годов Hansard. | справочники |
| `changeContractDsCommisionTrigger` | Directual-триггер смены %ДС у контракта. | legacy |
| `nocomission` | Исключения: транзакции, по которым комиссия не начисляется. | legacy (12) |
| `other_accruals` | Ручные прочие начисления партнёру (сумма/баллы, комментарий, автор). | живая (19) |
| `consultantBalance` | **Месячный баланс партнёра** (44 колонки): начислено транзакционное/нетранзакционное/пул, удержания за отрыв и ОП, к выплате, выплачено. | живая (~40 719) |
| `consultantPayment` | Факты выплат по балансу (сумма, дата, статус). | живая (~2 720) |
| `firstBalances`, `unactualBalances` | Стартовые и «неактуальные» балансы после пересчётов. | legacy |
| `networkGroupBonus`, `poolLog`, `poolTrigger` | Пул руководителей: расчёт по уровням 6–10, лог начислений, Directual-триггер. | живая (`poolLog`) / legacy |
| `pool_moderation` | Ручное включение/исключение партнёра из пула за период. | живая |
| `vat`, `vatChangesTrigger` | Ставка НДС по периодам + Directual-триггер её смены. | живая / legacy |
| `currency`, `currencyRate` | Валюты и **курсы по датам** (кнопка «Добавить курсы»; у таблицы нет сиквенса — id выдаёт `LegacyId`). | живая |
| `currencyRates`, `cbrResponse`, `currencyRatesChangesTrigger` | Legacy-курсы, сырые ответы ЦБ РФ, триггер изменений. | legacy |
| `management_currency_rate` | Управленческий курс (для отчётности), задаётся вручную. | живая (205) |
| `calculationsConstant` | Константы расчётов Directual — заменены `system_settings` (группа `business`). | legacy |
| `volumeCalculator`, `volumeCalculatorHistoryCleaner` | Сохранённые расчёты «Калькулятора объёмов» и чистка их истории. | живая / legacy |

## 6. Квалификации и периоды

| Таблица | Что хранит | Статус |
|---|---|---|
| `qualificationLog` | **Месячный лог квалификации** (29 колонок): ЛП, ГП, накопленный ГП (НГП), уровень до/после, отрыв, ветка с отрывом, суммы к удержанию. | живая (~45 997) |
| `unactualQlogs` | Логи, помеченные неактуальными после пересчёта. | legacy |
| `qualificationCalculationsTrigger`, `qualificationSavingTrigger` | Directual-триггеры расчёта/сохранения квалификаций. | legacy |
| `calculationConsultantPoints` | Баллы партнёра к qLog (коэффициенты ЛП/ГП/НГП, ссылки на транзакции). | живая (~9 851) |
| `consultantProgramsData` | Агрегаты по партнёру × продукту/программе: суммы, счётчики, баллы, %-уровень. | живая (~37 024) |
| `consultantLevel` | Legacy-справочник уровней (канон — `status_levels`). | legacy |
| `period_closures` | **Закрытие периодов**: год/месяц, кем и когда закрыт, повторное открытие. Гейт для правок. | живая (25) |
| `period_visibility` | Видимость периода партнёру в кабинете. | живая |
| `calculationConsultantRaiting`, `contestrating`, `Contest`, `criterion`, `coefficientCriterion`, `type_criterion`, `type_contest`, `calculationContestTrigger` | Модуль конкурсов Directual: конкурсы, критерии и коэффициенты, рейтинг участников. Кодом платформы не используется. | legacy |

## 7. Реквизиты и документы

| Таблица | Что хранит | Статус |
|---|---|---|
| `requisites` | Реквизиты партнёра: ИНН, данные ЕГРИП/ЕГРЮЛ, `verified`, статус, `dateChange` (старт SLA). | живая (627) |
| `bankrequisites` | Банковские реквизиты к `requisites`: банк, БИК, счёт, бенефициар (проставляет бэк по ЕГРИП). | живая (584) |
| `bank_requisite_change_requests` | Заявки на смену банковских реквизитов после верификации (только через поддержку). | живая |
| `documentlogs` | Логи документов Directual (35 колонок). | legacy |
| `acts` | Акты (id, статус, имя) — заготовка Directual. | legacy (1) |

## 8. Импорт и интеграции

| Таблица | Что хранит | Статус |
|---|---|---|
| `counterparty` | **Контрагенты-провайдеры** (алиасы для распознавания листов импорта). | живая (104) |
| `pattern` | Шаблоны разбора файла контрагента: с какой строки, какие колонки. | legacy |
| `transaction_import_log` | Итог загрузки транзакций: строк/успехов/ошибок, JSON ошибок и предупреждений, статус расчёта. | живая (35) |
| `contract_import_log`, `contract_import_preview` | Загрузка контрактов: журнал и постраничное превью перед финализацией. | живая |
| `importTransactionLog`, `createImportTransaction`, `ImportInformation`, `importtransactionfromn8n` | Старый конвейер импорта (Directual + n8n). | legacy |
| `anderida`, `bkc`, `broker`, `gga`, `ibCounter`, `investorsTrust`, `privateEquity`, `roboadvisor`, `unilife`, `woodville` | **Staging-таблицы по провайдерам**: сырые строки выписок (дата платежа, номер контракта, суммы, флаги «пусто»), из которых Directual собирал транзакции. Сейчас импорт идёт напрямую в `transaction`. | legacy (история) |
| `getInsmartOrderWebHookData`, `webHookInsmartError` | Сырые вебхуки InSmart (оплаченные полисы) и ошибки их обработки. | живая (приём) |
| `getCourseOrderWebHookData`, `getCourseRegistrationWebHookData`, `getCourseLog`, `getcourseExportTransactionsData`, `getcourseCreateResidentPromocodeDebit`, `getCourseTransactionsFromGoogleSpreadsheetsWebHookData`, `getcourseTransactionExportDataFromGoogleSpreadsheet` | Интеграция с GetCourse (заказы, регистрации, промокоды, выгрузки в Google Sheets). | legacy |
| `lastN8nSyncTimestam`, `errorN8nlog`, `ZapierHook` | Синхронизация n8n/Zapier. | legacy |
| `integration_events` | **Журнал интеграций платформы**: сервис, направление, действие, статус, запрос/ответ, длительность. | живая (~2 048) |
| `api_settings` | Ключи и настройки внешних API (DaData, Sheets, Socket и т.д.), с пометкой `secret`. | живая (20) |
| `webhooks`, `webhook_deliveries` | Исходящие вебхуки платформы и попытки доставки. | живая |
| `exportLogClients`, `exportLogConsultant`, `exportLogContract`, `exportLogTransactions`, `exportLogQualificationLog`, `logExportClient`, `logExportConsultant`, `logExportContract`, `logExportTransaction` | Журналы выгрузок Directual по сущностям. | legacy |
| `ip_geo_cache` | Кэш геолокации IP (страна/город/провайдер) для аудита входов. | живая |

## 9. Обучение и база знаний

| Таблица | Что хранит | Статус |
|---|---|---|
| `education_courses` | Курсы: название, категория, обложка, публикация, привязка к продукту (scalar `product_id` — именно он гейтит витрину). | живая (21) |
| `education_course_categories` | Категории курсов. | живая (13) |
| `education_lessons`, `education_lesson_views` | Уроки (текст/видео) и факты просмотра. | живая |
| `education_tests`, `education_test_attempts` | Тесты к урокам/курсам и попытки прохождения. | живая |
| `education_course_completions` | **Факт прохождения курса** (канон «курс пройден» = тест сдан). | живая (115) |
| `education_course_enrollments` | Записи на курс. | живая (752) |
| `education_course_certificates` | Выданные сертификаты. | живая |
| `education_course_product`, `education_course_program` | Pivot курс ↔ продукт/программа (M:N). | живая |
| `education_homework_submissions` | Сданные домашние задания. | живая |
| `education_kb_sections`, `education_kb_articles`, `education_tags` | База знаний: разделы, статьи, теги. | живая |

## 10. Коммуникации: чат, тикеты, почта, уведомления

| Таблица | Что хранит | Статус |
|---|---|---|
| `chat_tickets` | **Обращения платформы** (32 колонки): тема, отдел, статус, приоритет, исполнитель, контекст. Через них же идёт «Написать собственнику». | живая (~1 156) |
| `chat_messages`, `chat_message_reactions`, `chat_read_status` | Сообщения обращения, реакции, отметки прочтения. | живая |
| `chat_ticket_changes`, `chat_ticket_participants`, `chat_ticket_watchers`, `chat_internal_notes` | История изменений тикета, участники, наблюдатели, внутренние заметки (партнёру не видны). | живая |
| `chat_quick_replies`, `chat_knowledge_articles` | Быстрые ответы оператора и связанные статьи. | живая |
| `tickets`, `ticket_messages`, `ticket_participants` | Старый тикет-модуль; сейчас используется как **служебные заявки** (например, «Проверка реквизитов партнёра» с `context_type=requisites`). | живая (128) |
| `platformCommunication`, `communicationCategory` | Directual-переписка с партнёром и её категории. | живая (287) |
| `notifications` | Уведомления Laravel (колокольчик). | живая (~9 865) |
| `notification` | Legacy-справочник типов уведомлений Directual. | legacy (13) |
| `mail_log` | Журнал писем: получатель, тема, статус доставки, bounce, открытия/клики. | живая (425) |
| `mail_settings`, `mail_templates` | SMTP-профили (в т.ч. дефолтный) и шаблоны писем. | живая |
| `MailLog`, `SmsLog`, `SystemMessage`, `IncomingMessage` | Directual-журналы писем/SMS/сообщений. | legacy |
| `TChat`, `TUser`, `TMessageIn`, `TMessageOut`, `TKeyboard` | Telegram-бот Directual: чаты, пользователи, входящие/исходящие, клавиатуры. | legacy |
| `announcements`, `news` | Объявления и новости в кабинете. | живая |
| `monthlyReports`, `monthlyReportAvailabilityIndicator`, `commissionsReport`, `partnerMonthlyPaymentsReportMailing`, `partnerMonthlyPaymentsReportTrigger`, `reportGenerator`, `reportLogs` | Directual-генератор месячных отчётов партнёрам и его рассылка. Заменено отчётами платформы. | legacy |

## 11. Контент, UI и эксплуатация

| Таблица | Что хранит | Статус |
|---|---|---|
| `instructions` | Инструкции для партнёров (markdown + видео, аудитория, публикация). Партнёр читает **именно их**. | живая |
| `doc_pages` | Admin-only markdown-доки (`/admin/partner-guide`) — партнёру не видны. | живая |
| `content_pages` | Статические страницы по slug. | живая |
| `menu_items` | Пункты бокового меню: область, группа, иконка, маршрут, роли, порядок. | живая (21) |
| `design_themes` | Темы оформления (JSON-конфиг токенов). | живая |
| `translation_overrides` | Переопределения строк локализации. | живая |
| `custom_fields`, `custom_field_values` | Пользовательские поля (тип, обязательность, роли) и их значения. | живая |
| `system_settings` | **Бизнес-константы и настройки** (`activation.window_days`, пороги пула, отрыв, `products.requisites_gate_cutoff` и т.д.). | живая (32) |
| `roadmap_entries` | Роадмап `/roadmap`: что выпущено, категория, дата релиза. | живая (98) |
| `system_components`, `system_incidents`, `system_incident_updates` | Статус-страница: компоненты, инциденты, апдейты по ним. | живая |
| `health_check_result_history_items` | История health-check'ов (самая «толстая» служебная таблица, ~199 тыс. строк). | служебная |
| `FileUpload` | Загруженные файлы Directual. | legacy (~2 625) |
| `GlobalVariables`, `objectForRequestScenario`, `SystemException`, `title`, `test`, `CryptoWallet`, `CryptoTransaction`, `NearTransaction` | Остатки движка Directual (переменные, сценарии, исключения, крипто-модуль). | legacy |

## 12. Служебные (Laravel)

| Таблица | Что хранит |
|---|---|
| `migrations` | Применённые миграции (209). |
| `cache`, `cache_locks` | Кэш и блокировки (драйвер database). |
| `jobs`, `job_batches`, `failed_jobs` | Очереди и упавшие задачи. |
| `activity_log` | Spatie activity log — кто что менял (~16 444). |
| `audit_log` | Собственный аудит действий: пользователь, действие, сущность, payload, IP, UA. |

---

## Что стоит знать отдельно

1. **Регистр имён.** Postgres регистрозависим: `WebUser` (живой) ≠ `webUser` (legacy), `transaction` ≠ `Transaction`. Legacy-таблицы Directual — camelCase без кавычек ломаются.
2. **Сиквенсы.** У части legacy-таблиц (`currencyRate`, часть импортных) сиквенса нет — id выдаёт `App\Support\LegacyId::next()`. Для таблиц **с** сиквенсом (`transaction`, `contract`, `commission`) `LegacyId` использовать нельзя — сиквенс отстаёт и bulk-импорт падает на `duplicate _pkey`.
3. **Два каталога продуктов.** UI пишет `products_catalog`/`programs_catalog` (JSONB-тарифы), а расчёт %ДС читает `dsCommission`. Они не синхронны автоматически — синк отдельной командой.
4. **Что нельзя чистить.** `consultant_activation_backfill_20260602`, `person_legacy_map`, провайдерские staging-таблицы (`bkc`, `investorsTrust`, `gga`, …) — источники восстановления истории до консолидации Directual.
5. **Порядок удаления.** FK `points → quallog`: строки `calculationConsultantPoints` удалять раньше `qualificationLog`.
