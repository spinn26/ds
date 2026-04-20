<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the integer FK columns on tables created by the 2026-04-14/15 batch.
 * These columns reference "WebUser".id, but were created as plain integers
 * (no foreignId), so Laravel never generated implicit indexes. Every JOIN
 * onto WebUser and every "my tickets / my accruals" filter was doing a
 * sequential scan.
 *
 * Uses CREATE INDEX CONCURRENTLY to avoid locking on production. Because
 * CONCURRENTLY cannot run inside a transaction, the migration opts out of
 * the default migration transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private array $indexes = [
        // tickets (legacy support system)
        ['tickets_created_by_idx',           'tickets',              '(created_by)'],
        ['tickets_assigned_to_idx',          'tickets',              '(assigned_to)'],
        ['tickets_consultant_id_idx',        'tickets',              '(consultant_id)'],
        ['ticket_messages_user_id_idx',      'ticket_messages',      '(user_id)'],
        ['ticket_participants_user_id_idx',  'ticket_participants',  '(user_id)'],

        // other_accruals
        ['other_accruals_consultant_idx',    'other_accruals',       '(consultant)'],
        ['other_accruals_created_by_idx',    'other_accruals',       '(created_by)'],

        // chat system — composite for the hot "open tickets by recency" query
        ['chat_tickets_status_last_msg_idx', 'chat_tickets',         '(status, last_message_at DESC)'],
        ['chat_messages_sender_id_idx',      'chat_messages',        '(sender_id)'],
        ['chat_internal_notes_author_id_idx', 'chat_internal_notes', '(author_id)'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$name, $table, $columns]) {
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$table} {$columns}");
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [$name]) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }
};
