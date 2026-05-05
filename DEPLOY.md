# Prod deployment runbook — 2026-04-20

Все изменения этой сессии, упакованные в пошаговый план для наката.

## Миграции к накату (10 штук)

Прогоняются одной командой. Все идемпотентны — можно повторно запускать
если процесс прервался.

```bash
ssh root@dev.dsconsult.ru
cd /var/www/newds
php artisan migrate --force
```

Ожидаемый порядок применения:

| # | Файл | Что делает | Риск |
|---|---|---|---|
| 1 | `2026_04_20_000001_add_fk_indexes_for_tickets_and_accruals.php` | Индексы на FK-колонки tickets/accruals | low |
| 2 | `2026_04_20_000002_backfill_deleted_consultants_from_webuser.php` | Чистка orphan-FK (5 шт из аудита) | medium — меняет данные, down() пустой |
| 3 | `2026_04_20_000010_create_period_closures_table.php` | Новая таблица `period_closures` | low |
| 4 | `2026_04_20_000020_create_pool_moderation_table.php` | Новая таблица `pool_moderation` | low |
| 5 | `2026_04_20_000030_add_soft_delete_partial_indexes.php` | Partial indexes на soft-delete | low — только скорость |
| 6 | `2026_04_20_000040_add_calc_fields_to_program.php` | `program.dsPercent / pointsMethod / fixedCost / kvPayoutYear` | low — nullable add |
| 7 | `2026_04_20_000050_add_identity_to_legacy_pk.php` | IDENTITY на `volumeCalculator.id`, `Contest.id` | medium — меняет PK-семантику |
| 8 | `2026_04_20_000060_add_identity_to_commission.php` | IDENTITY на `commission.id` | medium — без этого CommissionCalculator::createCommission падает |

## Что накат НЕ делает автоматически

- **Не пересчитывает комиссии за февраль-март 2026.** В БД сейчас
  `commission.amountRUB = NULL` для всех строк 2025-2026. Запуск пересчёта —
  отдельной командой **ПОСЛЕ** миграций:

  ```bash
  # dry-run (ничего не пишет, откатывает транзакцию)
  php scripts/commissions-recalc.php 2026-02
  php scripts/commissions-recalc.php 2026-03

  # реальный пересчёт — после подтверждения цифр
  php scripts/commissions-recalc.php 2026-02 --apply
  php scripts/commissions-recalc.php 2026-03 --apply
  ```

- **Не заполняет `program.dsPercent` / `pointsMethod`.** После миграции
  поля пустые. BackOffice заполняет вручную через диалог Programs в
  админке (Admin → Продукты → программа → Редактировать).

- **Не ротирует `GOOGLE_SHEETS_API_KEY`.** Ключ был в сессии, надо
  отключить старый и выдать новый через GCP Console.

## Поверка после наката

```bash
# Все миграции применены
php artisan migrate:status | tail -10

# Identity действительно встала
psql $DATABASE_URL -c "
  SELECT attname, attidentity FROM pg_attribute
  WHERE attrelid IN ('commission'::regclass,
                     'volumeCalculator'::regclass,
                     '\"Contest\"'::regclass)
    AND attname = 'id'"
# Ожидается attidentity = 'd' для всех трёх

# Новые таблицы существуют
psql $DATABASE_URL -c "\dt period_closures pool_moderation"

# PoolCalculator читает правильное поле
php artisan tinker --execute='
  $r = app(\App\Services\PoolRunner::class)->run(2026, 2);
  echo "Revenue: ", number_format($r["revenue"]), "\nFund/level: ",
       number_format($r["fund"]), "\n";
'
# Ожидается: revenue ~10.8 млн, fund/level ~108 тыс (у Богдановой было 216K —
# см. пояснение ниже).
```

## Что сломается, если накатить частично

- Без миграции 8 (`commission` identity) ни один `CommissionCalculator::calc`
  не отработает на проде — insert упадёт на NOT NULL id.
- Без миграции 2 (backfill orphan FK) не восстанавливаются 5 constraints,
  `\d+ WebUser` продолжит ругаться на сломанные FK.

## Откаты

Все миграции имеют `down()`. Откат по одной:
```bash
php artisan migrate:rollback --step=1
```

**Но:** `000050`/`000060` в `down()` делают `DROP IDENTITY` — после этого
все новые inserts снова падают на NULL id. Откатывать identity нет смысла
кроме тестового цикла, лучше вперёд доделать.

## Pool: следующий разговор с Богдановой

Мой PoolCalculator читает `transaction.netRevenueRUB` (не `amountRUB`).
Сумма чистой выручки ДС за февраль = 10.87M ₽, 1% = 108.7K ₽ на уровень.

Богданова называла 216K ₽. Гипотеза: она считала суммарный фонд на
несколько уровней сразу (2 × 108K ≈ 216K) либо брала чуть другой
источник. **Подтвердить вручную на трёх месяцах до запуска `PoolRunner`
в автоматическом режиме.**

## Контрольный чеклист перед тем как сказать "релизим"

- [ ] Прогнал `php scripts/commissions-recalc.php 2026-02` (dry-run)
      и убедился, что суммы не нулевые и разложены адекватно по цепочкам
- [ ] Богданова подтвердила формулу пула на минимум трёх месяцах
- [ ] Сделан бэкап БД (`pg_dump -Fc newds > newds-pre-release.dump`)
- [ ] Tests: `php artisan test` даёт 0 failed (12 skipped норма — нужны
      фабрики, не критично)
- [ ] Smoke: `node scripts/ui-smoke.cjs` проходит по всем 61 странице

---

## Production checklist (релиз-блокеры — обновлено 2026-05-05)

### .env — обязательно проверить
```
APP_DEBUG=false
APP_ENV=production
LOG_LEVEL=warning            # не debug! debug сжирает диск
LOG_STACK=daily              # rotation 14 дней
QUEUE_CONNECTION=redis       # либо database — но миграция queue tables нужна
CACHE_STORE=redis
SESSION_DRIVER=redis
SANCTUM_TOKEN_EXPIRATION=43200   # 30 дней; null = вечно (опасно)
SOCKET_EMIT_SECRET=<openssl rand -hex 32>
INSMART_WEBHOOK_SECRET=<openssl rand -hex 32>
# DB_STATEMENT_TIMEOUT_MS=30000  — опционально, дефолт ОК
```

### Шаги наката (в строгом порядке)

```bash
ssh root@dev.dsconsult.ru
cd /var/www/newds

# 1) Бэкап ДО любых изменений (не пропускать!).
pg_dump -Fc newds > /var/backups/newds-$(date +%Y-%m-%d-%H%M).dump

# 2) Проверить что .env в актуальном состоянии (см. чеклист выше).
grep -E '^(APP_DEBUG|LOG_LEVEL|QUEUE_CONNECTION|CACHE_STORE|SANCTUM_TOKEN_EXPIRATION)' .env

# 3) Получить код.
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# 4) Storage symlink (нужен для public/storage и публичных файлов).
php artisan storage:link

# 5) Миграции. Перед накатом проверить status —
#    если есть конфликты по timestamp (старая версия уже применена),
#    разрезолвить вручную (см. секцию ниже).
php artisan migrate:status | tail -20
php artisan migrate --force

# 6) Кэши.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 7) Перезапуск сервисов.
systemctl restart php8.2-fpm
systemctl restart ds-queue-worker     # supervisor: queue:work --queue=default
systemctl restart ds-socket-server    # systemd unit для socket-server/

# 8) Smoke-тесты.
curl -f https://dev.dsconsult.ru/up
curl -f https://dev.dsconsult.ru/api/v1/auth/me -H "Authorization: Bearer $TEST_TOKEN"
```

### Конфликты таймстемпов миграций (resolved 2026-05-05)

Старые миграции имели одинаковые префиксы (`2026_04_28_000010` × 2 и т.п.).
В коммите 2026-05-05 переименованы → `_000011`, `_000021`, `_000031`.

**Если на проде уже применилась хотя бы одна из пары** до переименования —
после `git pull` Laravel увидит миграции как новые и попробует применить
заново. Проверить:
```sql
SELECT migration FROM migrations WHERE migration LIKE '2026_04_28_%';
```
Если в результате есть строки с **старыми** именами (без `_000011`/etc):
вручную обновить:
```sql
UPDATE migrations SET migration = '2026_04_28_000011_create_report_archive_table'
  WHERE migration = '2026_04_28_000010_create_report_archive_table';
-- аналогично для двух остальных пар
```

### Backup стратегия

Бэкапы делаются **ежедневно** через cron на хост-машине (не из Laravel
scheduler — пусть приложение и БД упадут раздельно):
```cron
0 3 * * * postgres pg_dump -Fc newds > /var/backups/newds/daily-$(date +\%F).dump
0 4 * * 0 find /var/backups/newds -name 'daily-*.dump' -mtime +30 -delete
```
Ретеншн: 30 дней daily, никогда не удаляем еженедельные. Проверять
ежемесячно что бэкапы реально восстанавливаются (drill).

### Откат при проблемах

```bash
# Откатить миграции (последние N).
php artisan migrate:rollback --step=N --force

# Восстановить из бэкапа.
dropdb newds && createdb newds
pg_restore -Fc -d newds /var/backups/newds-XXXX.dump

# Откатить код.
git reset --hard <previous-commit>
composer install --no-dev && npm ci && npm run build
systemctl restart php8.2-fpm ds-queue-worker ds-socket-server
```
