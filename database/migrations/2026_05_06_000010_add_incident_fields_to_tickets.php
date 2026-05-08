<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Тикет может быть «зафиксирован как инцидент» — это пометка для
 * рабочего стола техподдержки (роли admin/support).
 *
 * Что добавляем:
 *   - is_incident (boolean) — флаг
 *   - incident_no (string) — короткий номер инцидента (генерим в коде:
 *     INC-YYYYMM-NNNN, чтобы не плодить отдельную sequence в legacy схеме)
 *   - incident_logged_at / incident_logged_by — кто и когда зафиксировал
 *   - incident_severity — приоритет инцидента (critical/high/medium/low)
 *   - incident_resolved_at — когда инцидент закрыт; status тикета свой
 *     (open/closed) — это разные состояния (чат можно завершить, но
 *     инцидент остаётся открытым в статистике)
 *
 * Все поля nullable — обратная совместимость со старыми тикетами.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_tickets', function (Blueprint $t) {
            if (! Schema::hasColumn('chat_tickets', 'is_incident')) {
                $t->boolean('is_incident')->default(false);
            }
            if (! Schema::hasColumn('chat_tickets', 'incident_no')) {
                $t->string('incident_no', 32)->nullable();
            }
            if (! Schema::hasColumn('chat_tickets', 'incident_logged_at')) {
                $t->timestamp('incident_logged_at')->nullable();
            }
            if (! Schema::hasColumn('chat_tickets', 'incident_logged_by')) {
                $t->unsignedInteger('incident_logged_by')->nullable();
            }
            if (! Schema::hasColumn('chat_tickets', 'incident_severity')) {
                $t->string('incident_severity', 16)->nullable();
            }
            if (! Schema::hasColumn('chat_tickets', 'incident_resolved_at')) {
                $t->timestamp('incident_resolved_at')->nullable();
            }
        });

        // Индекс для рабочего стола: быстро выбирать активные инциденты
        // (is_incident=true AND incident_resolved_at IS NULL).
        if (! $this->indexExists('chat_tickets', 'chat_tickets_is_incident_idx')) {
            Schema::table('chat_tickets', function (Blueprint $t) {
                $t->index(['is_incident', 'incident_resolved_at'], 'chat_tickets_is_incident_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('chat_tickets', function (Blueprint $t) {
            if ($this->indexExists('chat_tickets', 'chat_tickets_is_incident_idx')) {
                $t->dropIndex('chat_tickets_is_incident_idx');
            }
            foreach ([
                'is_incident', 'incident_no', 'incident_logged_at',
                'incident_logged_by', 'incident_severity', 'incident_resolved_at',
            ] as $col) {
                if (Schema::hasColumn('chat_tickets', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $rows = \DB::select(
            "SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?",
            [$table, $index]
        );
        return ! empty($rows);
    }
};
