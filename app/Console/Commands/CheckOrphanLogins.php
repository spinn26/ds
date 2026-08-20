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
 * Типовые причины, и команда их различает:
 *   • «дубль почты» — есть ДРУГОЙ WebUser с той же почтой, и карточка привязана
 *     к нему (человек входит не в тот аккаунт);
 *   • «карточка удалена» — привязка на месте, но все карточки soft-deleted:
 *     партнёра убрали из структуры, а вход оставили (Тарасенко, WebUser 505);
 *   • «карточки нет вовсе» — привязку потеряли или партнёра не заводили.
 *
 *   php artisan partners:check-orphan-logins
 */
class CheckOrphanLogins extends Command
{
    protected $signature = 'partners:check-orphan-logins {--limit=50} {--email= : разобрать одну почту}';

    protected $description = 'Найти логины (WebUser) без карточки партнёра — «Консультант не найден» в кабинете';

    public function handle(): int
    {
        if ($email = $this->option('email')) {
            return $this->explainEmail((string) $email);
        }

        $rows = DB::select(<<<'SQL'
            SELECT w.id, w.email, w."lastName", w."firstName", w.role, w."dateDeleted",
                   (SELECT c.id FROM consultant c
                     WHERE lower(btrim(c.email)) = lower(btrim(w.email))
                       AND c."dateDeleted" IS NULL LIMIT 1) AS card_by_email,
                   -- Карточка по ФИО без логина — типичный импортированный ФК:
                   -- партнёр в системе есть, а поле webUser у него пустое.
                   (SELECT c.id FROM consultant c
                     WHERE c."webUser" IS NULL AND c."dateDeleted" IS NULL
                       AND lower(btrim(c."personName")) = lower(btrim(
                             concat_ws(' ', w."lastName", w."firstName", w.patronymic)))
                     LIMIT 1) AS card_by_name,
                   -- Карточка с тем же ФИО, но привязанная к ДРУГОМУ логину:
                   -- у человека два аккаунта на разные почты, партнёрский —
                   -- первый, а зашёл он во второй (кейс Виногорова 17.08.2026).
                   (SELECT c.id || ' → WebUser ' || c."webUser" FROM consultant c
                     WHERE c."webUser" IS NOT NULL AND c."webUser" <> w.id
                       AND c."dateDeleted" IS NULL
                       AND lower(btrim(c."personName")) = lower(btrim(
                             concat_ws(' ', w."lastName", w."firstName", w.patronymic)))
                     LIMIT 1) AS card_name_other_login,
                   -- Тот же человек, но без отчества в одном из аккаунтов —
                   -- сверка только по «Фамилия Имя». Ровно случай Виногорова:
                   -- во втором логине отчество не заполнено, полное ФИО не
                   -- совпало. Тёзки-однофамильцы тоже сюда попадут → «сверить».
                   (SELECT c.id || ' → WebUser ' || c."webUser" FROM consultant c
                     WHERE c."dateDeleted" IS NULL
                       AND (c."webUser" IS NULL OR c."webUser" <> w.id)
                       AND lower(btrim(split_part(c."personName", ' ', 1))) = lower(btrim(w."lastName"))
                       AND lower(btrim(split_part(c."personName", ' ', 2))) = lower(btrim(w."firstName"))
                     LIMIT 1) AS card_surname_name,
                   -- Карточки у логина есть, но ВСЕ удалены. Для кабинета это
                   -- то же самое, что карточки нет: Consultant-запросы идут с
                   -- фильтром dateDeleted. Партнёра убрали из структуры, а
                   -- вход не забрали (WebUser 505, Тарасенко: карточки
                   -- 1065 и 1519 обе soft-deleted).
                   (SELECT string_agg(c.id::text, ', ' ORDER BY c.id) FROM consultant c
                     WHERE c."webUser" = w.id AND c."dateDeleted" IS NOT NULL) AS deleted_cards,
                   (SELECT w2.id FROM "WebUser" w2
                     JOIN consultant c2 ON c2."webUser" = w2.id AND c2."dateDeleted" IS NULL
                    WHERE lower(btrim(w2.email)) = lower(btrim(w.email)) AND w2.id <> w.id
                    LIMIT 1) AS twin_web_user
              FROM "WebUser" w
             -- ⚠ ЖИВОЙ карточки нет. Фильтр dateDeleted здесь обязателен:
             -- без него логин, у которого все карточки удалены, считался
             -- «с карточкой» и в отчёт не попадал — а кабинет у него ровно
             -- такой же пустой (дыра найдена 2026-08-20 на Тарасенко).
             WHERE NOT EXISTS (
                     SELECT 1 FROM consultant c
                      WHERE c."webUser" = w.id AND c."dateDeleted" IS NULL
                   )
               -- ⚠ dateDeleted НЕ фильтруем: вход по нему не запрещён (это
               -- артефакт Directual-экспорта, по нему когда-то запирали живых
               -- людей). Под таким «удалённым» дублем человек спокойно входит и
               -- получает «Консультант не найден» — прежняя версия команды его
               -- не показывала и кейс выглядел необъяснимым.
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
            ['WebUser', 'Почта', 'ФИО', 'Роль', 'Логин', 'Диагноз'],
            array_map(fn ($r) => [
                $r->id,
                $r->email,
                trim(($r->lastName ?? '') . ' ' . ($r->firstName ?? '')),
                $r->role,
                $r->dateDeleted ? 'помечен удалённым' : 'живой',
                $this->verdict($r),
            ], array_slice($rows, 0, (int) $this->option('limit')))
        );

        $this->newLine();
        $this->comment('Привязка правится в карточке партнёра (поле webUser) — вручную, после сверки ФИО и почты.');
        $this->comment('«Карточки нет» у роли client/сотрудника — норма: партнёрская карточка им не нужна.');

        return self::SUCCESS;
    }

    /**
     * Разбор одной почты: все логины с ней (регистр не важен) и их карточки.
     * Отвечает на вопрос «карточка есть, почему кабинет её не видит».
     */
    private function explainEmail(string $email): int
    {
        $needle = mb_strtolower(trim($email));

        $users = DB::select(<<<'SQL'
            SELECT w.id, w.email, w."lastName", w."firstName", w.patronymic, w.role,
                   w."dateDeleted", w."isBlocked", (w.password IS NOT NULL) AS has_password
              FROM "WebUser" w
             WHERE lower(btrim(w.email)) = ?
             ORDER BY w.id
        SQL, [$needle]);

        if (! $users) {
            $this->error("Логинов с почтой {$email} не найдено.");

            return self::SUCCESS;
        }

        $this->info('Логинов с этой почтой: ' . count($users)
            . (count($users) > 1 ? '  ⚠ дубль — вход зависит от того, к какой строке подошёл пароль' : ''));

        foreach ($users as $u) {
            $cards = DB::table('consultant')
                ->where('webUser', $u->id)
                ->get(['id', 'personName', 'activity', 'dateDeleted']);
            $this->line(sprintf(
                "\nWebUser %d  <%s>  %s %s %s\n  роль: %s | пароль: %s | %s%s",
                $u->id, $u->email, $u->lastName, $u->firstName, $u->patronymic,
                $u->role ?: '—',
                $u->has_password ? 'есть' : 'НЕТ (войти нельзя)',
                $u->dateDeleted ? 'помечен удалённым (вход всё равно разрешён)' : 'живой',
                $u->isBlocked ? ' | ЗАБЛОКИРОВАН' : '',
            ));
            if ($cards->isEmpty()) {
                $this->warn('  карточки партнёра НЕТ → кабинет ответит «Консультант не найден»');
                continue;
            }
            foreach ($cards as $c) {
                $this->line(sprintf('  карточка %d «%s» activity=%s%s',
                    $c->id, $c->personName, $c->activity,
                    $c->dateDeleted ? ' ⚠ УДАЛЕНА' : ''));
            }
        }

        // Карточки с этой же почтой, но привязанные к другому логину или ни к
        // какому — обычный источник расхождения.
        $cards = DB::table('consultant')
            ->whereRaw('lower(btrim(email)) = ?', [$needle])
            ->get(['id', 'personName', 'webUser', 'dateDeleted']);
        if ($cards->isNotEmpty()) {
            $this->newLine();
            $this->info('Карточки партнёра с этой почтой:');
            foreach ($cards as $c) {
                $this->line(sprintf('  карточка %d «%s» → логин %s%s',
                    $c->id, $c->personName, $c->webUser ?: 'НЕ ПРИВЯЗАН',
                    $c->dateDeleted ? ' ⚠ УДАЛЕНА' : ''));
            }
        }

        return self::SUCCESS;
    }

    /** Человекочитаемый диагноз по строке. */
    private function verdict(object $r): string
    {
        if ($r->twin_web_user) {
            return "карточка на другом логине с той же почтой (WebUser {$r->twin_web_user}) — человек входит не в тот аккаунт";
        }
        if ($r->card_by_name) {
            return "есть карточка {$r->card_by_name} с тем же ФИО и пустым логином — привязать webUser";
        }
        if ($r->card_name_other_login) {
            return "второй аккаунт того же человека: карточка {$r->card_name_other_login} (другая почта) — входить под ним либо перевесить привязку";
        }
        if ($r->card_by_email) {
            return "есть карточка {$r->card_by_email} с той же почтой, но привязка не та — сверить webUser";
        }

        if ($r->card_surname_name) {
            return "похоже на второй аккаунт: карточка {$r->card_surname_name} с той же фамилией и именем — сверить (отчество в логине не заполнено)";
        }

        if ($r->deleted_cards) {
            return "карточка {$r->deleted_cards} привязана, но УДАЛЕНА — вход остался, кабинет пустой: забрать логин или вернуть карточку";
        }

        return 'карточки партнёра нет вовсе';
    }
}
