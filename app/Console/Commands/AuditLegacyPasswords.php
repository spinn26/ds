<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Аудит распределения хешей паролей.
 *
 * Auto-upgrade MD5 → bcrypt происходит при успешном логине
 * (User::validatePassword). Эта команда показывает сколько
 * аккаунтов ещё на MD5 — те, кто не логинился со времени
 * деплоя нового платформы.
 *
 * Mass-rehash MD5 → bcrypt технически невозможен (нужен plaintext),
 * единственный способ принудительно мигрировать неактивных —
 * пройтись по списку, force-reset пароль, отправить им email.
 * Эта команда даёт цифры для такого решения.
 */
class AuditLegacyPasswords extends Command
{
    protected $signature = 'users:legacy-password-audit
                            {--csv : Вывести список MD5-аккаунтов в CSV}';

    protected $description = 'Распределение хешей паролей (bcrypt vs MD5)';

    public function handle(): int
    {
        $stats = DB::selectOne('
            SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE password LIKE \'$2y$%\' OR password LIKE \'$2a$%\') AS bcrypt,
                COUNT(*) FILTER (WHERE password ~ \'^[a-f0-9]{32}$\' AND LENGTH(password)=32) AS md5,
                COUNT(*) FILTER (WHERE password IS NULL OR TRIM(password)=\'\') AS empty,
                COUNT(*) FILTER (WHERE
                    password IS NOT NULL
                    AND TRIM(password) <> \'\'
                    AND NOT (password LIKE \'$2y$%\' OR password LIKE \'$2a$%\')
                    AND NOT (password ~ \'^[a-f0-9]{32}$\' AND LENGTH(password)=32)
                ) AS other
            FROM "WebUser"
        ');

        $this->info('=== Password hash distribution ===');
        $this->line("Total accounts:        {$stats->total}");
        $this->line("bcrypt (\$2y\$/\$2a\$):    {$stats->bcrypt}");
        $this->line("MD5 (32 hex):          {$stats->md5}");
        $this->line("empty:                 {$stats->empty}");
        $this->line("other (broken/legacy): {$stats->other}");
        $this->newLine();

        if ($stats->total > 0) {
            $bcryptPct = round($stats->bcrypt / $stats->total * 100, 1);
            $md5Pct = round($stats->md5 / $stats->total * 100, 1);
            $this->comment("bcrypt: {$bcryptPct}%, MD5: {$md5Pct}%");
        }

        // Активность MD5-пользователей: когда последний раз логинились?
        $byActivity = DB::select('
            SELECT
                COUNT(*) FILTER (WHERE c.activity = 1) AS active,
                COUNT(*) FILTER (WHERE c.activity = 3) AS terminated,
                COUNT(*) FILTER (WHERE c.activity = 4) AS registered,
                COUNT(*) FILTER (WHERE c.activity = 5) AS excluded,
                COUNT(*) FILTER (WHERE c.activity IS NULL) AS no_consultant
            FROM "WebUser" w
            LEFT JOIN consultant c ON c."webUser" = w.id AND c."dateDeleted" IS NULL
            WHERE w.password ~ \'^[a-f0-9]{32}$\' AND LENGTH(w.password)=32
        ');

        if (! empty($byActivity)) {
            $a = $byActivity[0];
            $this->info('=== MD5 accounts by partner activity ===');
            $this->line("Active (1):     {$a->active}");
            $this->line("Terminated (3): {$a->terminated}");
            $this->line("Registered (4): {$a->registered}");
            $this->line("Excluded (5):   {$a->excluded}");
            $this->line("No consultant:  {$a->no_consultant}");
            $this->newLine();
            $this->comment('Active + Registered = реальные кандидаты на force-reset.');
        }

        if ($this->option('csv')) {
            $rows = DB::select('
                SELECT w.id, w.email, w.role, c.activity AS partner_activity
                FROM "WebUser" w
                LEFT JOIN consultant c ON c."webUser" = w.id AND c."dateDeleted" IS NULL
                WHERE w.password ~ \'^[a-f0-9]{32}$\' AND LENGTH(w.password)=32
                ORDER BY w.id
            ');
            $this->newLine();
            $this->line('id,email,role,partner_activity');
            foreach ($rows as $r) {
                $this->line(sprintf('%d,%s,%s,%s', $r->id, $r->email, $r->role ?? '', $r->partner_activity ?? ''));
            }
        }

        return self::SUCCESS;
    }
}
