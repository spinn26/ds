<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reports how many WebUser rows still have legacy MD5 password hashes.
 *
 * MD5 detection: exactly 32 hex characters.
 * Bcrypt starts with $2y$ so easy to exclude.
 */
class Md5PasswordReport extends Command
{
    protected $signature = 'users:md5-report {--sample=10 : Show this many recent MD5 users}';

    protected $description = 'Count WebUser rows that still have MD5 password hashes (legacy).';

    public function handle(): int
    {
        $total = DB::table('WebUser')->whereNotNull('password')->count();

        $md5Count = DB::table('WebUser')
            ->whereNotNull('password')
            ->whereRaw('length(password) = 32')
            ->whereRaw("password ~ '^[a-f0-9]{32}$'")
            ->count();

        $bcryptCount = DB::table('WebUser')
            ->whereNotNull('password')
            ->where('password', 'like', '$2y$%')
            ->count();

        $otherCount = $total - $md5Count - $bcryptCount;

        $this->info('Password hash distribution in WebUser:');
        $this->table(['Kind', 'Count', '%'], [
            ['MD5 (legacy)', $md5Count, $this->pct($md5Count, $total)],
            ['bcrypt', $bcryptCount, $this->pct($bcryptCount, $total)],
            ['other/unknown', $otherCount, $this->pct($otherCount, $total)],
            ['total with password', $total, '100.00%'],
        ]);

        if ($md5Count === 0) {
            $this->info('✓ No MD5 passwords remaining.');
            return self::SUCCESS;
        }

        $sample = (int) $this->option('sample');
        if ($sample > 0) {
            $recent = DB::table('WebUser')
                ->select('id', 'email', 'dateCreated', 'dateChanged')
                ->whereNotNull('password')
                ->whereRaw('length(password) = 32')
                ->whereRaw("password ~ '^[a-f0-9]{32}$'")
                ->orderByDesc('id')
                ->limit($sample)
                ->get();

            $this->line('');
            $this->info("Most recent {$sample} users still on MD5:");
            $this->table(
                ['ID', 'Email', 'Created', 'Changed'],
                $recent->map(fn ($u) => [$u->id, $u->email, $u->dateCreated, $u->dateChanged])->toArray(),
            );
        }

        return self::SUCCESS;
    }

    private function pct(int $n, int $total): string
    {
        return $total > 0 ? number_format($n / $total * 100, 2) . '%' : '—';
    }
}
