<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Логины без карточки партнёра (read-only).
 *
 * У такого аккаунта все партнёрские эндпоинты отвечают «Консультант не найден»:
 * кабинет открывается, но отчёты/клиенты/структура пустые, а окно акцепта
 * документов до 17.08.2026 намертво блокировало вход (принять было нельзя).
 *
 * Две типовые причины, и команда их различает:
 *   • «дубль почты» — есть ДРУГОЙ WebUser с той же почтой, и карточка привязана
 *     к нему (человек входит не в тот аккаунт);
 *   • «карточки нет вовсе» — привязку потеряли или партнёра не заводили.
 *
 *   php artisan partners:check-orphan-logins
 */
class CheckOrphanLogins extends Command
{
    protected $signature = 'partners:check-orphan-logins {--limit=50}';

    protected $description = 'Найти логины (WebUser) без карточки партнёра — «Консультант не найден» в кабинете';

    public function handle(): int
    {
        $rows = DB::select(<<<'SQL'
            SELECT w.id, w.email, w."lastName", w."firstName", w.role, w."dateDeleted",
                   (SELECT c.id FROM consultant c
                     WHERE lower(btrim(c.email)) = lower(btrim(w.email))
                       AND c."dateDeleted" IS NULL LIMIT 1) AS card_by_email,
                   (SELECT w2.id FROM "WebUser" w2
                     JOIN consultant c2 ON c2."webUser" = w2.id AND c2."dateDeleted" IS NULL
                    WHERE lower(btrim(w2.email)) = lower(btrim(w.email)) AND w2.id <> w.id
                    LIMIT 1) AS twin_web_user
              FROM "WebUser" w
             WHERE NOT EXISTS (SELECT 1 FROM consultant c WHERE c."webUser" = w.id)
               AND w."dateDeleted" IS NULL
               AND w.password IS NOT NULL
               -- staff карточка партнёра не нужна: это норма, не инцидент
               AND lower(coalesce(w.role, '')) NOT IN ('admin','staff','backoffice','finance','calculations','manager','support')
             ORDER BY w.id DESC
        SQL);

        if (! $rows) {
            $this->info('Логинов без карточки партнёра не найдено.');

            return self::SUCCESS;
        }

        $this->warn('Логинов без карточки партнёра: ' . count($rows));
        $this->table(
            ['WebUser', 'Почта', 'ФИО', 'Роль', 'Диагноз'],
            array_map(fn ($r) => [
                $r->id,
                $r->email,
                trim(($r->lastName ?? '') . ' ' . ($r->firstName ?? '')),
                $r->role,
                $r->twin_web_user
                    ? "карточка на другом логине с той же почтой (WebUser {$r->twin_web_user})"
                    : ($r->card_by_email
                        ? "есть карточка {$r->card_by_email} с той же почтой, но без привязки"
                        : 'карточки партнёра нет'),
            ], array_slice($rows, 0, (int) $this->option('limit')))
        );

        $this->newLine();
        $this->comment('Привязка правится в карточке партнёра (поле webUser) — вручную, после сверки ФИО и почты.');

        return self::SUCCESS;
    }
}
