<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Индикатор расчёта комиссий по импорту (правки 2026-05-23).
 *
 * Раньше после загрузки импорта оператор не понимал — рассчитаны ли
 * по нему commission. Чтобы узнать, надо было открывать /admin/commissions
 * и фильтровать по транзакциям, либо лезть в БД. Теперь храним:
 *   • calc_status: pending | running | done | partial | error
 *   • calc_total / calc_success / calc_errors — сводка прогресса
 *   • calc_done_at — когда завершился (для бейджа в истории)
 *
 * Заполняет CalculateImportCommissionsJob по ходу выполнения.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transaction_import_log', function (Blueprint $table) {
            if (! Schema::hasColumn('transaction_import_log', 'calc_status')) {
                $table->string('calc_status', 20)->nullable()->index();
            }
            if (! Schema::hasColumn('transaction_import_log', 'calc_total')) {
                $table->integer('calc_total')->nullable();
            }
            if (! Schema::hasColumn('transaction_import_log', 'calc_success')) {
                $table->integer('calc_success')->nullable();
            }
            if (! Schema::hasColumn('transaction_import_log', 'calc_errors')) {
                $table->integer('calc_errors')->nullable();
            }
            if (! Schema::hasColumn('transaction_import_log', 'calc_done_at')) {
                $table->timestamp('calc_done_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('transaction_import_log', function (Blueprint $table) {
            foreach (['calc_status', 'calc_total', 'calc_success', 'calc_errors', 'calc_done_at'] as $col) {
                if (Schema::hasColumn('transaction_import_log', $col)) {
                    if ($col === 'calc_status') $table->dropIndex(['calc_status']);
                    $table->dropColumn($col);
                }
            }
        });
    }
};
