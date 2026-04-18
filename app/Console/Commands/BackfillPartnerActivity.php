<?php

namespace App\Console\Commands;

use App\Enums\PartnerActivity;
use App\Models\Consultant;
use Illuminate\Console\Command;

class BackfillPartnerActivity extends Command
{
    protected $signature = 'partners:backfill-activity {--dry-run : Preview without writing}';

    protected $description = 'Set consultant.activity = Registered for rows where activity IS NULL';

    public function handle(): int
    {
        $query = Consultant::whereNull('activity');
        $count = $query->count();

        $this->info("Rows with activity IS NULL: {$count}");

        if ($count === 0) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no changes applied.');

            return self::SUCCESS;
        }

        $updated = $query->update(['activity' => PartnerActivity::Registered->value]);
        $this->info("Updated {$updated} rows → activity = Registered.");

        return self::SUCCESS;
    }
}
