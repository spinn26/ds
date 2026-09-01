# CLAUDE.md

Ориентир для работы с кодовой базой. Читать до первого изменения.

## Что это

**DS Consulting** — партнёрская (MLM) платформа для финансовых консультантов:
кабинет партнёра + бэкофис. Считает вознаграждения реальным людям, поэтому
цена ошибки в расчётах — деньги, а не битый UI.

Проект переехал с low-code Directual/n8n на собственный код. Отсюда две
особенности, которые видно везде: camelCase-имена таблиц (`consultant`,
`qualificationLog`) и legacy-слой, который нельзя трогать.

## Стек

- **Backend**: Laravel 12, PHP 8.2, PostgreSQL, Redis, Horizon, Sanctum (Bearer-токены, не сессии)
- **Frontend**: Vue 3 SPA + Vuetify 3 + Pinia + vue-router, Vite 7. Не Inertia — общение только через `/api/v1`
- **Realtime**: отдельный Node-процесс `socket-server/` на Socket.IO (порт 3001)
- **Mobile**: Capacitor 6 + Vue 3 + TS в `mobile/`, тот же API

## Команды

```bash
composer setup     # install + .env + key + migrate + npm build
composer dev       # serve + queue:listen + pail + vite одной командой
composer test      # config:clear + artisan test
vendor/bin/phpstan analyse            # level 5, гейт деплоя
vendor/bin/pint                       # стиль; в CI НЕ включён
```

## Структура

| Путь | Что там |
|---|---|
| `app/Services/` | Вся бизнес-логика — 69 сервисов. Контроллеры тонкие не везде, но логику писать сюда |
| `app/Http/Controllers/Api/` | 88 контроллеров |
| `routes/api.php` | Точка сборки: только группы и `require` |
| `routes/v1/{public,cabinet,admin,signed}.php` | Определения роутов по уровню доступа |
| `resources/js/pages/` | 174 Vue-страницы (84 — админка) |
| `database/migrations/` | 216 миграций |

**Порядок `require` в `routes/api.php` менять нельзя** — роуты матчатся в
порядке регистрации, статические сегменты должны идти раньше параметрических.

## Где документация

Она вся вне README и она хорошая — сначала читать её, потом код:

- **`.claude/specs/`** — 46 бизнес-спеков на русском. Это фактическое ТЗ:
  комиссии, пул, квалификации, статусы, импорт, Инсмарт, отчёты.
  Код на них ссылается прямо в докблоках (`per spec ✅Комиссии.md`).
- **`.claude/schema/`** — дампы `\d` ключевых таблиц.
- **`docs/db-tables-reference.md`** — 270 таблиц с пометками живая/legacy/служебная.
- **`docs/partner-cabinet-guide.md`** — как система выглядит для партнёра.
- **`DEPLOY.md`** — накат, откат, бэкапы, незакрытые хвосты.

## Домен

Партнёр (`consultant`) приглашает клиентов и других партнёров. Дерево строится
по полю `inviter`. Клиент заключает контракт, по контракту идут транзакции,
с транзакций каскадом вверх по дереву считаются комиссии.

| Термин | Смысл |
|---|---|
| **ЛП** | личные продажи (баллы за свои сделки) |
| **ГП** | групповые продажи: свои + вся команда |
| **НГП** | накопленный ГП — по нему растёт уровень |
| **Отрыв** | одна ветка даёт слишком много ГП: 70% — не в зачёт, 90% — нет пула |
| **Пул** | доп. выплата уровням 6+, 1% чистой выручки на уровень |
| **Квалификация** | уровень 1–10, фиксированный % комиссии |

`status_levels` — матрица квалификаций (ЛП, ГП, НГП, %ДС, доля пула, отрыв,
обязательный ГП). От неё зависят все расчёты; менять её данные — значит менять
деньги задним числом.

Ключевые сервисы: `CommissionCalculator` (каскад), `PoolRunner`/`PoolCalculator`
(пул), `PartnerStatusService` (статусы, активация, терминация),
`MonthlyFinaliser`/`MonthlyPenaltyRunner` (закрытие месяца и удержания).

## Инварианты — нарушать нельзя

1. **`CommissionCalculator::HISTORICAL_CUTOFF = '2026-06-01'`.** Всё раньше —
   выгруженная из Directual история, она неизменна. Любой движок пересчёта
   обязан пропускать такие периоды.
2. **`PeriodFreezeService::guard($year, $month)` перед любой мутацией**
   `transaction` / `commission` / `qualificationLog` / `poolLog`. Закрытый
   месяц правится только после явной разморозки админом (`period_closures.reopened_at`).
3. **`CommissionCalculator::UNKNOWN_CONSULTANT_ID = 536`** — плейсхолдер
   «Неизвестный консультант»: ставка 0%, цепочка не строится.
4. **`WebUser` ≠ `webUser`.** Postgres регистрозависим. Канон личности —
   `WebUser` (модель `App\Models\User`); строчный двойник — мёртвый legacy.
5. **Схема `legacy`.** 93 мёртвые таблицы Directual вынесены из `public`.
   Код, который ищет ссылающиеся таблицы через каталоги Postgres
   (`PartnerMergeService`, `AdminUserController`, `AdminContestController`),
   обязан фильтровать по `public` — иначе слияние партнёров падает на
   невидимой таблице.
6. **`consultant_activation_backfill_20260602` не удалять** — единственный
   источник восстановления дат активации.

## Доступ

Роли: `admin`, `backoffice`, `support`, `finance`, `head`, `calculations`,
`corrections`, `education`, `invest`; `staff` — собирательная.

Middleware-алиасы объявлены в `bootstrap/app.php`: `role`, `permission`,
`maintenance` и гарды `restrict.education` / `restrict.head` /
`restrict.support` / `restrict.corrections` / `restrict.invest` — они режут
запись внутри разрешённого раздела.

## Качество и деплой

- **PHPStan** level 5 + larastan, гейт перед деплоем. `phpstan-baseline.neon`
  замораживает 230 существующих ошибок в 192 блоках — новые блокируют деплой.
- **Pint** намеренно не в CI: код не приведён к стилю целиком.
- **Тесты** — отдельный job. Легаси-схема не создаётся миграциями, тестовая БД
  поднимается из дампа `database/schema/pgsql-schema.sql`.
- **`git push` в `main` = релиз на прод.** `.github/workflows/deploy.yml`
  катит на `dev.dsconsult.ru` (он же прод) автоматически: pull, composer,
  npm build, `migrate --force`, перезапуск воркеров и socket-server.
  Ручного гейта и staging нет.

## Подводные камни

- Локально нет `vendor/` и `.env` — без `composer install` не запустится
  ни phpstan, ни тесты, ни `artisan route:list`.
- `AdminDataController` (3073 строки) и `ChatController` (2978) — менять
  осторожно, покрытия тестами у них нет.
- Тестов 55 файлов на 619 PHP; расчёт денег покрыт `CommissionSpecTest`,
  `PoolRunnerTest`, `MonthlyFinalisationRunnerTest`. Меняешь расчёт — сначала
  тест, потом код.
- Комментарии в коде и спеки на русском. Держать язык единым.
