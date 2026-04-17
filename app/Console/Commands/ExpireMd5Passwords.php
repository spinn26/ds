<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Expires legacy MD5 passwords by setting password to NULL.
 * Affected users cannot log in until their password is reset
 * (admin action or password-reset flow — neither automated yet).
 *
 * Run users:md5-report first to see scope. Use --confirm to actually execute.
 *
 * WARNING: this blocks login for every MD5 user. Do not run until a
 * password-reset flow is deployed, or you are prepared to reset manually.
 */
class ExpireMd5Passwords extends Command
{
    protected $signature = 'users:expire-md5 {--confirm : Actually perform the update} {--since= : Only affect users with dateChanged older than this date (YYYY-MM-DD)}';

    protected $description = 'Nullify MD5 password hashes so users must reset their password.';

    public function handle(): int
    {
        $query = DB::table('WebUser')
            ->whereNotNull('password')
            ->whereRaw('length(password) = 32')
            ->whereRaw("password ~ '^[a-f0-9]{32}$'");

        if ($since = $this->option('since')) {
            $query->where(function ($q) use ($since) {
                $q->whereNull('dateChanged')->orWhere('dateChanged', '<', $since);
            });
            $this->info("Filter: only users with dateChanged < {$since} (or null).");
        }

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('Nothing to expire — no MD5 passwords match the filter.');
            return self::SUCCESS;
        }

        if (! $this->option('confirm')) {
            $this->warn("Would nullify the password for {$count} users. Re-run with --confirm to execute.");
            return self::SUCCESS;
        }

        if (! $this->confirm("This will set password=NULL for {$count} users. They will NOT be able to log in until reset. Proceed?", false)) {
            $this->info('Aborted.');
            return self::FAILURE;
        }

        $updated = $query->update(['password' => null]);
        $this->info("✓ Expired {$updated} MD5 passwords.");

        return self::SUCCESS;
    }
}
