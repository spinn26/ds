<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GrantConsultantRoleAfterQuestionnaire extends Command
{
    protected $signature = 'users:grant-consultant-after-questionnaire {--dry-run : Preview without writing}';

    protected $description = 'Append consultant role to users who completed the onboarding questionnaire but are still role=registered only';

    public function handle(): int
    {
        $users = User::where('role', 'registered')
            ->whereNotNull('questionnaireCompletedAt')
            ->get();

        $count = $users->count();
        $this->info("Users stuck on role=registered with questionnaire done: {$count}");

        if ($count === 0) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $users->each(fn ($u) => $this->line("  • {$u->id} — {$u->email}"));
            $this->warn('Dry run — no changes applied.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            $user->role = 'registered,consultant';
            $user->saveQuietly();
            $this->line("  ✓ {$user->id} — {$user->email} → registered,consultant");
        }

        $this->info("Updated {$count} users.");

        return self::SUCCESS;
    }
}
