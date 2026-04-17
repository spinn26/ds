<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds indexes on the hot FK columns and date columns identified in the
 * 2026-04-17 audit. Legacy schema had no indexes beyond primary keys,
 * so MLM cascade, commission lookups and tree traversal all ran on
 * sequential scans over 500K+ row tables.
 *
 * Uses CREATE INDEX CONCURRENTLY so the migration does not lock the
 * large commission/transaction tables in production. Because
 * CONCURRENTLY cannot run inside a transaction, this migration opts out
 * of the default migration transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private array $indexes = [
        // commission — 533K rows
        ['commission_consultant_idx',      'commission',        '("consultant")'],
        ['commission_transaction_idx',     'commission',        '("transaction")'],
        ['commission_dateyear_datemonth_idx', 'commission',     '("dateYear", "dateMonth")'],
        ['commission_date_idx',            'commission',        '("date")'],

        // transaction — 50K rows
        ['transaction_contract_idx',       '"transaction"',     '("contract")'],
        ['transaction_dateyear_datemonth_idx', '"transaction"', '("dateYear", "dateMonth")'],
        ['transaction_date_idx',           '"transaction"',     '("date")'],

        // qualificationLog — 36K rows, always sorted DESC by date
        ['qualificationlog_consultant_idx', '"qualificationLog"', '("consultant")'],
        ['qualificationlog_date_idx',      '"qualificationLog"', '("date" DESC)'],

        // contract — 17K rows
        ['contract_consultant_idx',        'contract',          '("consultant")'],
        ['contract_client_idx',            'contract',          '("client")'],

        // client — 8K rows
        ['client_consultant_idx',          'client',            '("consultant")'],

        // consultant — 2K rows, tree traversal
        ['consultant_inviter_idx',         'consultant',        '("inviter")'],
        ['consultant_webuser_idx',         'consultant',        '("webUser")'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$name, $table, $columns]) {
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$table} {$columns}");
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [$name, $table, $columns]) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }
};
