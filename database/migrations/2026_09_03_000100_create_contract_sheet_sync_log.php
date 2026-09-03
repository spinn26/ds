<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал прогонов синхронизации контрактов с таблицей Парус/Акцент.
 *
 * Зачем: таблица — источник истины и молча перезаписывает платформу. Если в
 * лист попали неверные данные, нужен способ вернуть как было, не разбирая
 * руками десятки карточек. В `changes` лежит полный снимок правки по каждому
 * контракту — старое и новое значение каждого поля, — по нему и катится откат.
 *
 * Сделано по образцу contract_import_log: тот же принцип «прогон знает, что
 * именно он натворил», только там список созданных id, а тут — изменённых
 * полей, потому что синхронизация ничего не создаёт, а только обновляет.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contract_sheet_sync_log')) {
            return;
        }

        Schema::create('contract_sheet_sync_log', function (Blueprint $t) {
            $t->id();
            $t->string('status', 20)->default('success'); // success | rolled_back
            $t->integer('checked_count')->default(0);
            $t->integer('updated_count')->default(0);
            // [{contractId, number, fields: {поле: {old, value, label, from, to}}}]
            $t->jsonb('changes')->nullable();
            $t->unsignedInteger('created_by')->nullable();
            $t->timestamp('rolled_back_at')->nullable();
            $t->unsignedInteger('rolled_back_by')->nullable();
            $t->timestamps();

            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_sheet_sync_log');
    }
};
