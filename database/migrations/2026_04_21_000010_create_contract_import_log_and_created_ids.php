<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. Добавляем поле `created_ids` (jsonb) в transaction_import_log — чтобы
 *    rollback не ходил фильтром по comment="Импорт #N", а точечно удалял
 *    именно те транзакции, что создал конкретный прогон.
 * 2. Создаём contract_import_log по тому же принципу — вести историю
 *    прогонов импорта контрактов, со списком созданных id и возможностью
 *    атомарного отката.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transaction_import_log')
            && ! Schema::hasColumn('transaction_import_log', 'created_ids')) {
            Schema::table('transaction_import_log', function (Blueprint $t) {
                $t->jsonb('created_ids')->nullable();
            });
        }

        if (! Schema::hasTable('contract_import_log')) {
            Schema::create('contract_import_log', function (Blueprint $t) {
                $t->id();
                $t->string('source', 80)->nullable();   // 'sheets:Sheet1' или 'file:name.csv'
                $t->string('status', 20)->default('success'); // success | partial | error | rolled_back
                $t->integer('total_rows')->default(0);
                $t->integer('success_count')->default(0);
                $t->integer('error_count')->default(0);
                $t->jsonb('errors')->nullable();
                $t->jsonb('created_ids')->nullable();
                $t->foreignId('created_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_import_log');

        if (Schema::hasTable('transaction_import_log')
            && Schema::hasColumn('transaction_import_log', 'created_ids')) {
            Schema::table('transaction_import_log', function (Blueprint $t) {
                $t->dropColumn('created_ids');
            });
        }
    }
};
