<?php

namespace App\Console\Commands;

use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Снять вход у логинов, за которыми нет НИЧЕГО (read-only без --apply).
 *
 * Кандидат — живой WebUser с паролем, у которого:
 *   • нет ни одной живой карточки ФК (все удалены или их не было);
 *   • на удалённых карточках нет ни контрактов, ни клиентов;
 *   • ни одного входа (last_seen_at пуст) и ни одного действия в activity_log.
 *
 * Такой аккаунт открывает кабинет, где всё отвечает «Консультант не найден».
 * Оставлять его живым незачем — это просто открытая дверь.
 *
 * ⚠ Сотрудников НЕ трогаем: партнёрская карточка им не нужна, отсутствие
 * карточки для них норма. Порог по активности отсекает и тех, кто реально
 * работает (Джабиева Лейла, backoffice: 82 действия, вход 04.08.2026).
 *
 * ⚠ Блокировка — это `isBlocked`, а НЕ `dateDeleted`: по dateDeleted вход не
 * запрещён (артефакт Directual-экспорта), AuthController проверяет только
 * isBlocked. Операция обратима — снять галочку в карточке пользователя.
 *
 *   php artisan partners:revoke-empty-logins           # показать кандидатов
 *   php artisan partners:revoke-empty-logins --apply   # снять вход
 */
class RevokeEmptyPartnerLogins extends Command
{
    protected $signature = 'partners:revoke-empty-logins
                            {--apply : снять вход (без флага — только показать)}';

    protected $description = 'Заблокировать вход у логинов без карточки ФК, без данных и без единого входа';

    /** Роли, для которых отсутствие карточки партнёра — норма. */
    private const STAFF_ROLES = ['admin', 'staff', 'backoffice', 'finance', 'calculations', 'manager', 'support'];

    public function handle(): int
    {
        $rows = DB::select(<<<'SQL'
            SELECT w.id, w.email, w."lastName", w."firstName", w.role,
                   (SELECT string_agg(c.id::text, ', ' ORDER BY c.id) FROM consultant c
                     WHERE c."webUser" = w.id) AS cards
              FROM "WebUser" w
             WHERE w."dateDeleted" IS NULL
               AND w.password IS NOT NULL
               AND w."isBlocked" IS NOT TRUE
               AND w.last_seen_at IS NULL
               -- ⚠ Человек БЫЛ партнёром: карточка привязана, но удалена.
               -- Без этого условия в выборку падают клиентские и служебные
               -- логины, которым карточка ФК не нужна вовсе, — а это уже
               -- совсем другие люди и другое решение.
               AND EXISTS (SELECT 1 FROM consultant c WHERE c."webUser" = w.id)
               AND NOT EXISTS (SELECT 1 FROM consultant c
                                WHERE c."webUser" = w.id AND c."dateDeleted" IS NULL)
               -- На удалённых карточках нет данных
               AND NOT EXISTS (SELECT 1 FROM consultant c JOIN contract k ON k.consultant = c.id
                                WHERE c."webUser" = w.id)
               AND NOT EXISTS (SELECT 1 FROM consultant c JOIN client cl ON cl.consultant = c.id
                                WHERE c."webUser" = w.id)
               -- Ни одного действия в журнале
               AND NOT EXISTS (SELECT 1 FROM activity_log a WHERE a.causer_id = w.id)
             ORDER BY w.id
        SQL);

        // Сотрудников отсекаем в PHP: роль — строка со списком через запятую,
        // «consultant,backoffice» в SQL-фильтр NOT IN не попадёт.
        $rows = array_values(array_filter($rows, fn ($r) => ! $this->isStaff($r->role)));

        if (! $rows) {
            $this->info('Кандидатов нет.');

            return self::SUCCESS;
        }

        $this->table(
            ['WebUser', 'Почта', 'ФИО', 'Роль', 'Удалённые карточки'],
            array_map(fn ($r) => [
                $r->id,
                $r->email ?: '—',
                trim(($r->lastName ?? '').' '.($r->firstName ?? '')),
                $r->role ?: '—',
                $r->cards ?: 'нет',
            ], $rows)
        );

        if (! $this->option('apply')) {
            $this->comment('Это предпросмотр. Снять вход: --apply');

            return self::SUCCESS;
        }

        $ids = array_map(fn ($r) => $r->id, $rows);
        DB::table('WebUser')->whereIn('id', $ids)->update(['isBlocked' => true]);

        foreach ($rows as $r) {
            Audit::log('login_revoked', 'WebUser', $r->id, [
                'reason' => 'нет живой карточки ФК, нет контрактов/клиентов, ни одного входа',
                'email' => $r->email,
                'cards' => $r->cards,
            ]);
        }

        $this->warn('Вход снят у логинов: '.count($ids).' ('.implode(', ', $ids).').');
        $this->comment('Обратимо: снять галочку «Заблокирован» в карточке пользователя.');

        return self::SUCCESS;
    }

    private function isStaff(?string $role): bool
    {
        foreach (explode(',', mb_strtolower((string) $role)) as $part) {
            if (in_array(trim($part), self::STAFF_ROLES, true)) {
                return true;
            }
        }

        return false;
    }
}
