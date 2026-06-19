<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Запросы партнёров на смену БАНКОВСКИХ реквизитов с доп. проверкой.
 * Текущие (верифицированные) реквизиты при подаче запроса НЕ трогаются —
 * хранится снимок «было/стало», финменеджер (Катя, роль calculations)
 * принимает или отклоняет. Плюс флаг приостановки выплат на consultant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_requisite_change_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consultant')->index();
            $table->unsignedBigInteger('requisite_id')->nullable();

            // Снимок текущих (старых) банковских реквизитов на момент запроса.
            $table->string('old_bank_name')->nullable();
            $table->string('old_bank_bik', 20)->nullable();
            $table->string('old_account_number', 40)->nullable();
            $table->string('old_correspondent_account', 40)->nullable();

            // Новые банковские реквизиты, которые партнёр хочет применить.
            $table->string('new_bank_name')->nullable();
            $table->string('new_bank_bik', 20)->nullable();
            $table->string('new_account_number', 40)->nullable();
            $table->string('new_correspondent_account', 40)->nullable();

            // pending | accepted | rejected
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();
        });

        // Флаг приостановки выплат (смена реквизитов). Не влияет на доступ к
        // продуктам — только на баннер партнёру и подсветку у Кати.
        if (! Schema::hasColumn('consultant', 'payments_suspended')) {
            Schema::table('consultant', function (Blueprint $table) {
                $table->boolean('payments_suspended')->default(false);
                $table->timestamp('payments_suspended_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_requisite_change_requests');
        if (Schema::hasColumn('consultant', 'payments_suspended')) {
            Schema::table('consultant', function (Blueprint $table) {
                $table->dropColumn(['payments_suspended', 'payments_suspended_at']);
            });
        }
    }
};
