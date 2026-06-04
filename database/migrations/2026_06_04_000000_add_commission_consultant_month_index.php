<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Composite partial index for the hot commission filter
 *   WHERE "consultant" = ? AND "dateMonth" = ? AND "deletedAt" IS NULL
 * used by DashboardService (twice per dashboard load), FinanceReportService,
 * and CommissionCalculator::rebuildBalance on the ~551k-row commission table.
 *
 * Existing indexes cover only (consultant) and (dateYear,dateMonth) separately,
 * so the planner index-scans by consultant then filters dateMonth+deletedAt.
 *
 * CONCURRENTLY → no table lock online; must run outside a transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS commission_consultant_month_alive_idx ON commission ("consultant", "dateMonth") WHERE "deletedAt" IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS commission_consultant_month_alive_idx');
    }
};
